<?php
namespace Mediavine\Create;

/**
 * Tools to help manage admin notices.
 */
class Admin_Notices {

	/** @var Admin_Notices  */
	private static $instance = null;

	/** @var string[] Names of notices that can be dismissed per user */
	private const PER_USER_NOTICES = [ 'password_reset_required' ];

	/**
	 * @return Admin_Notices
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 *
	 */
	public function init() {
		// setup per-user dismissals
		add_action( 'admin_init', [ $this, 'plugin_notice_dismiss' ] );

		// Create notices
		add_action( 'admin_notices', [ $this, 'password_reset_notice' ] );
	}

	/**
	 *
	 * Builds and displays our admin notices
	 *
	 * @param string  $name the name of the notice being built
	 * @param string  $message the message content for the notice being built
	 * @param string  $level the notice level
	 * @param boolean $dismissible if we want this notice to be dismissible on a per-user basis
	 */
	private function admin_error_notice( $name, $message, $level = 'error' ) {
		global $current_user;

		$user_id = $current_user->ID;

		// early return if the notice has already been dismissed
		$val = (int) get_user_meta($user_id, $name, true);
		if ( $val ) {
			return;
		}

		// print the notice
		printf(
			'<div class="notice notice-' . esc_attr($level) . '"><p>%1$s</p></div>',
			wp_kses(
				$message,
				[
					'strong' => [],
					'code'   => [],
					'br'     => [],
					'div'    => [
						'class' => true,
						'style' => true,
					],
					'span'   => [
						'class' => true,
						'style' => true,
					],
					'a'      => [
						'href'   => true,
						'target' => true,
						'class'  => true,
						'style'  => true,
					],
					'p'      => [],
					'button' => [
						'type'      => true,
						'class'     => true,
						'data-email' => true,
						'style'     => true,
					],
				]
			)
		);
	}

	/**
	 *
	 * Checks for URL param from clicked link
	 * Adds user meta telling us if this user has dismissed the given notice
	 *
	 * @param string $name the name of the notice
	 */
	private function plugin_notice_dismiss_per_user( $name ) {

		global $current_user;

		$user_id = $current_user->ID;

		$filtered_name = filter_input(INPUT_GET, $name . '-dismiss-notice');

		if ( ! empty( $name ) && in_array($name, self::PER_USER_NOTICES) && isset( $filtered_name ) ) {

			add_user_meta( $user_id, $name, 1, true );

		}

	}

	/**
	 * Hook in admin notices dismissals by looping through allowed list.
	 */
	public function plugin_notice_dismiss() {
		array_map( [ $this, 'plugin_notice_dismiss_per_user' ], self::PER_USER_NOTICES );
	}

	/**
	 * Display password reset notice for v1 JWT users who need to migrate to v2
	 */
	public function password_reset_notice() {
		// Check if password reset is needed first (transient set by plugin update check)
		$needs_password = get_transient( 'mv_create_needs_password_reset' );

		if ( ! $needs_password ) {
			return;
		}

		// Show on dashboard and plugins page (not on Create pages since React UI takes over)
		$screen = get_current_screen();

		// Only show on dashboard or plugins page, not on Create admin pages
		if ( ! $screen || ! isset( $screen->base ) || ( 'dashboard' !== $screen->base && 'plugins' !== $screen->base ) ) {
			return;
		}

		// Check if password reset email was already sent (suppress for 5 minutes)
		$pending_transient = get_transient( 'mv_create_password_reset_pending_' . get_current_user_id() );

		// Get email from API token (same way admin UI does it)
		$api_token = \Mediavine\Settings::get_setting( 'mv_create_api_token' );
		if ( empty( $api_token ) ) {
			return;
		}

		// Decode JWT to get email
		$token_parts = explode( '.', $api_token );
		if ( count( $token_parts ) !== 3 ) {
			return;
		}

		// Decode the payload (second part)
		$payload = json_decode( base64_decode( strtr( $token_parts[1], '-_', '+/' ) ), true );
		if ( ! isset( $payload['email'] ) || ! is_email( $payload['email'] ) ) {
			return;
		}

		$api_email = $payload['email'];

		// Build the notice HTML with inline JavaScript
		$notice_name    = 'password_reset_required';
		$services_url   = esc_js( \Mediavine\Create\Plugin::$services_api_url );
		$api_token      = esc_js( \Mediavine\Settings::get_setting( 'mv_create_api_token' ) );
		$user_id        = esc_js( \Mediavine\Settings::get_setting( 'mv_create_api_user_id' ) );

		if ( $pending_transient ) {
			// Show "waiting for password" message with resend button after 5 minutes
			$resend_button = sprintf(
				'<button type="button" class="button button-secondary mv-create-resend-password-reset" data-email="%s" style="margin-top: 12px; display: none;">%s</button>',
				esc_attr( $api_email ),
				__( 'Didn\'t get an email? Resend', 'mediavine' )
			);
			$message = sprintf(
				'<div style="margin: 8px 0;"><strong>%s</strong></div><div style="margin: 12px 0; line-height: 1.6;">%s</div><div style="margin: 12px 0; padding: 10px; background-color: #f5f5f5; border-left: 3px solid #ffc107; font-size: 13px; color: #666;">%s</div><div style="margin: 16px 0;"><a href="#" class="mv-create-check-password" style="text-decoration: none;">%s</a></div>%s<div class="mv-resend-timer" style="font-size: 13px; color: #999; margin-top: 12px; min-height: 20px;"></div>',
				__( 'Password Reset Email Sent', 'mediavine' ),
				__( 'We\'ve sent you an email with instructions to set your password for Create Studio. Once you\'ve set your password, click below to continue.', 'mediavine' ),
				__( '📧 Check your spam folder if you don\'t see it in your inbox.', 'mediavine' ),
				__( 'I\'ve set my password, check again', 'mediavine' ),
				$resend_button
			);
			$this->admin_error_notice( $notice_name, $message, 'info', false );
		} else {
			// Show initial password reset request message
			$dismiss_link = sprintf(
				'<a href="?%s-dismiss-notice" style="margin-left: 16px; text-decoration: none; color: #666; font-size: 13px; align-self: center;">%s</a>',
				$notice_name,
				__( 'Dismiss', 'mediavine' )
			);
			$message = sprintf(
				'<div style="margin: 8px 0;"><strong>%s</strong></div><div style="margin: 12px 0; line-height: 1.6;">%s</div><div style="margin: 16px 0; display: flex; align-items: center;"><button type="button" class="button button-primary mv-create-request-password-reset" data-email="%s">%s</button>%s</div>',
				__( 'Action Required: Set a Password for Create Studio', 'mediavine' ),
				__( 'To continue using Create\'s external services like Nutrition Calculation and Web Scraping, you need to create a password. Click below to receive an email with instructions.', 'mediavine' ),
				esc_attr( $api_email ),
				__( 'Send Password Reset Email', 'mediavine' ),
				$dismiss_link
			);
			$this->admin_error_notice( $notice_name, $message, 'warning', false );
		}

		// Add inline JavaScript for calling Services API directly
		// Get nonce from WordPress
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var servicesUrl = '<?php echo $services_url; ?>';
			var apiToken = '<?php echo $api_token; ?>';
			var userId = '<?php echo $user_id; ?>';
			var nonce = '<?php echo esc_js( $nonce ); ?>';

			// Handle password reset request via WordPress REST API
			$('.mv-create-request-password-reset').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var email = $btn.data('email');

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sending...', 'mediavine' ) ); ?>');

				// Call WordPress REST endpoint instead of external API
				$.ajax({
					url: '<?php echo esc_js( rest_url( 'mv-settings/v1/request-password-reset' ) ); ?>',
					method: 'POST',
					contentType: 'application/json',
					headers: {
						'X-WP-Nonce': nonce
					},
					data: JSON.stringify({ email: email }),
					success: function(response) {
						// Set the pending transient via WordPress REST API
						$.post('<?php echo esc_js( rest_url( 'mv-settings/v1/settings' ) ); ?>',
							JSON.stringify([{slug: 'mv_create_password_reset_pending_transient', value: 'set'}]),
							function() {
								$btn.closest('.notice').html('<p><strong><?php echo esc_js( __( 'Success!', 'mediavine' ) ); ?></strong><br><?php echo esc_js( __( 'Check your email for instructions. Reloading...', 'mediavine' ) ); ?></p>');
								setTimeout(function() {
									location.reload();
								}, 2000);
							}
						).fail(function() {
							// Even if setting the transient fails, show success and reload
							$btn.closest('.notice').html('<p><strong><?php echo esc_js( __( 'Success!', 'mediavine' ) ); ?></strong><br><?php echo esc_js( __( 'Check your email for instructions. Reloading...', 'mediavine' ) ); ?></p>');
							setTimeout(function() {
								location.reload();
							}, 2000);
						});
					},
					error: function(xhr) {
						var errorMsg = xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: '<?php echo esc_js( __( 'Failed to send email. Please try again.', 'mediavine' ) ); ?>';
						alert(errorMsg);
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Send Password Reset Email', 'mediavine' ) ); ?>');
					}
				});
			});

			// Handle password status check
			$('.mv-create-check-password').on('click', function(e) {
				e.preventDefault();
				var $link = $(this);
				var originalText = $link.text();

				$link.text('<?php echo esc_js( __( 'Checking...', 'mediavine' ) ); ?>');

				// Call Services API to check if password is set
				$.ajax({
					url: servicesUrl + '/users/' + userId,
					method: 'GET',
					headers: {
						'Authorization': 'Bearer ' + apiToken
					},
					success: function(response) {
						// Password is set, reload to clear notice
						$link.closest('.notice').html('<p><strong><?php echo esc_js( __( 'Success!', 'mediavine' ) ); ?></strong><br><?php echo esc_js( __( 'Your password is set. Reloading...', 'mediavine' ) ); ?></p>');
						setTimeout(function() {
							location.reload();
						}, 1000);
					},
					error: function(xhr) {
						if (xhr.status === 401 || xhr.status === 403) {
							alert('<?php echo esc_js( __( 'Password not yet set. Please check your email and follow the instructions.', 'mediavine' ) ); ?>');
						} else {
							alert('<?php echo esc_js( __( 'Unable to verify password status. Please try again.', 'mediavine' ) ); ?>');
						}
						$link.text(originalText);
					}
				});
			});

			// Handle resend password reset email button via WordPress REST API
			$('.mv-create-resend-password-reset').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var email = $btn.data('email');

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sending...', 'mediavine' ) ); ?>');

				// Call WordPress REST endpoint instead of external API
				$.ajax({
					url: '<?php echo esc_js( rest_url( 'mv-settings/v1/request-password-reset' ) ); ?>',
					method: 'POST',
					contentType: 'application/json',
					headers: {
						'X-WP-Nonce': nonce
					},
					data: JSON.stringify({ email: email }),
					success: function(response) {
						// Reset the pending transient via WordPress REST API
						$.post('<?php echo esc_js( rest_url( 'mv-settings/v1/settings' ) ); ?>',
							JSON.stringify([{slug: 'mv_create_password_reset_pending_transient', value: 'set'}]),
							function() {
								$btn.closest('.notice').html('<p><strong><?php echo esc_js( __( 'Success!', 'mediavine' ) ); ?></strong><br><?php echo esc_js( __( 'Email resent! Check your inbox. Reloading...', 'mediavine' ) ); ?></p>');
								setTimeout(function() {
									location.reload();
								}, 2000);
							}
						).fail(function() {
							$btn.closest('.notice').html('<p><strong><?php echo esc_js( __( 'Success!', 'mediavine' ) ); ?></strong><br><?php echo esc_js( __( 'Email resent! Check your inbox. Reloading...', 'mediavine' ) ); ?></p>');
							setTimeout(function() {
								location.reload();
							}, 2000);
						});
					},
					error: function(xhr) {
						var errorMsg = xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: '<?php echo esc_js( __( 'Failed to send email. Please try again.', 'mediavine' ) ); ?>';
						alert(errorMsg);
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Didn\'t get an email? Resend', 'mediavine' ) ); ?>');
					}
				});
			});

			// Timer countdown for resend button (show after 5 minutes)
			var $resendBtn = $('.mv-create-resend-password-reset');
			var $timer = $('.mv-resend-timer');
			if ($resendBtn.length) {
				var timeRemaining = 300; // 5 minutes in seconds
				var timerInterval = setInterval(function() {
					timeRemaining--;

					if (timeRemaining <= 0) {
						clearInterval(timerInterval);
						$resendBtn.show();
						$timer.html('');
					} else {
						var minutes = Math.floor(timeRemaining / 60);
						var seconds = timeRemaining % 60;
						seconds = seconds < 10 ? '0' + seconds : seconds;
						$timer.html('<?php echo esc_js( __( 'Resend available in:', 'mediavine' ) ); ?> ' + minutes + ':' + seconds);
					}
				}, 1000);
			}
		});
		</script>
		<?php
	}
}
