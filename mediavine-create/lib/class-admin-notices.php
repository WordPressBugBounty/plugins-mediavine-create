<?php
namespace Mediavine\Create;

/**
 * Tools to help manage admin notices.
 */
class Admin_Notices {

	/** @var Admin_Notices */
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
	 * Hook notice rendering, dismissal, assets, and REST routes.
	 */
	public function init() {
		add_action( 'admin_init', [ $this, 'plugin_notice_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'password_reset_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_password_reset_script' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes used by the password-reset notice.
	 */
	public function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/password-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'check_password_status' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);
	}

	/**
	 * Proxy password-status check to Create Studio so the JWT never reaches the browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_password_status( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = \Mediavine\Settings::get_setting( 'mv_create_api_user_id' );
		if ( empty( $user_id ) ) {
			return new \WP_Error(
				'no_user_id',
				__( 'Create Studio user id is not configured.', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$response = Create_Studio_Client::request( 'GET', '/users/' . rawurlencode( (string) $user_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['success'] ) ) {
			delete_transient( 'mv_create_needs_password_reset' );
			delete_transient( 'mv_create_password_reset_pending_' . get_current_user_id() );

			return rest_ensure_response(
				[
					'password_set' => true,
				]
			);
		}

		$status_code = isset( $response['status_code'] ) ? (int) $response['status_code'] : 500;

		if ( in_array( $status_code, [ 401, 403 ], true ) ) {
			return rest_ensure_response(
				[
					'password_set' => false,
				]
			);
		}

		return new \WP_Error(
			'password_status_check_failed',
			__( 'Unable to verify password status. Please try again.', 'mediavine-create' ),
			[ 'status' => $status_code ]
		);
	}

	/**
	 * Enqueue password-reset notice script on dashboard/plugins when the notice may show.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_password_reset_script( $hook_suffix ) {
		// Match REST permission_callback / request-password-reset (manage_options).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! in_array( $hook_suffix, [ 'index.php', 'plugins.php' ], true ) ) {
			return;
		}

		if ( ! get_transient( 'mv_create_needs_password_reset' ) ) {
			return;
		}

		if ( empty( \Mediavine\Settings::get_setting( 'mv_create_api_token' ) ) ) {
			return;
		}

		$handle = 'mv-create-password-reset-notice';

		wp_enqueue_script(
			$handle,
			Plugin::assets_url() . 'admin/ui/static/password-reset-notice.js',
			[ 'jquery' ],
			Plugin::VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'mvCreatePasswordResetNotice',
			[
				'nonce'                   => wp_create_nonce( 'wp_rest' ),
				'passwordStatusUrl'       => esc_url_raw( rest_url( 'mv-create/v1/password-status' ) ),
				'requestPasswordResetUrl' => esc_url_raw( rest_url( 'mv-settings/v1/request-password-reset' ) ),
				'i18n'                    => [
					'sending'           => __( 'Sending...', 'mediavine-create' ),
					'success'           => __( 'Success!', 'mediavine-create' ),
					'emailSentReload'   => __( 'Check your email for instructions. Reloading...', 'mediavine-create' ),
					'emailResentReload' => __( 'Email resent! Check your inbox. Reloading...', 'mediavine-create' ),
					'sendFailed'        => __( 'Failed to send email. Please try again.', 'mediavine-create' ),
					'sendEmail'         => __( 'Send Password Reset Email', 'mediavine-create' ),
					'resend'            => __( 'Didn\'t get an email? Resend', 'mediavine-create' ),
					'checking'          => __( 'Checking...', 'mediavine-create' ),
					'passwordSetReload' => __( 'Your password is set. Reloading...', 'mediavine-create' ),
					'passwordNotSet'    => __( 'Password not yet set. Please check your email and follow the instructions.', 'mediavine-create' ),
					'verifyFailed'      => __( 'Unable to verify password status. Please try again.', 'mediavine-create' ),
					'resendAvailableIn' => __( 'Resend available in:', 'mediavine-create' ),
				],
			]
		);
	}

	/**
	 * Builds and displays our admin notices.
	 *
	 * @param string $name    The name of the notice being built.
	 * @param string $message The message content for the notice being built.
	 * @param string $level   The notice level.
	 */
	private function admin_error_notice( $name, $message, $level = 'error' ) {
		$user_id = get_current_user_id();

		// Early return if the notice has already been dismissed.
		$val = (int) get_user_meta( $user_id, $name, true );
		if ( $val ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $level ),
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
						'type'       => true,
						'class'      => true,
						'data-email' => true,
						'style'      => true,
					],
				]
			)
		);
	}

	/**
	 * Checks for a nonce-protected dismiss request and persists per-user meta.
	 *
	 * @param string $name The name of the notice.
	 */
	private function plugin_notice_dismiss_per_user( $name ) {
		if ( empty( $name ) || ! in_array( $name, self::PER_USER_NOTICES, true ) ) {
			return;
		}

		// Password-reset notice is admin-only; keep dismiss gated the same way.
		if ( 'password_reset_required' === $name && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = $name . '-dismiss-notice';
		$value  = Admin_Notice_Helper::get_verified_dismiss_value( $action );

		if ( null === $value ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		add_user_meta( $user_id, $name, 1, true );
		Admin_Notice_Helper::redirect_after_dismiss( [ $action ] );
	}

	/**
	 * Hook in admin notices dismissals by looping through allowed list.
	 */
	public function plugin_notice_dismiss() {
		array_map( [ $this, 'plugin_notice_dismiss_per_user' ], self::PER_USER_NOTICES );
	}

	/**
	 * Display password reset notice for v1 JWT users who need to migrate to v2.
	 */
	public function password_reset_notice() {
		// Studio password migration is an admin action; REST endpoints require manage_options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$needs_password = get_transient( 'mv_create_needs_password_reset' );

		if ( ! $needs_password ) {
			return;
		}

		$screen = get_current_screen();

		// Only show on dashboard or plugins page, not on Create admin pages.
		if ( ! $screen || ! isset( $screen->base ) || ( 'dashboard' !== $screen->base && 'plugins' !== $screen->base ) ) {
			return;
		}

		$pending_transient = get_transient( 'mv_create_password_reset_pending_' . get_current_user_id() );

		$api_token = \Mediavine\Settings::get_setting( 'mv_create_api_token' );
		if ( empty( $api_token ) ) {
			return;
		}

		$token_parts = explode( '.', $api_token );
		if ( count( $token_parts ) !== 3 ) {
			return;
		}

		$payload = json_decode( base64_decode( strtr( $token_parts[1], '-_', '+/' ) ), true );
		if ( ! isset( $payload['email'] ) || ! is_email( $payload['email'] ) ) {
			return;
		}

		$api_email   = $payload['email'];
		$notice_name = 'password_reset_required';

		if ( $pending_transient ) {
			$resend_button = sprintf(
				'<button type="button" class="button button-secondary mv-create-resend-password-reset" data-email="%s" style="margin-top: 12px; display: none;">%s</button>',
				esc_attr( $api_email ),
				esc_html__( 'Didn\'t get an email? Resend', 'mediavine-create' )
			);
			$message       = sprintf(
				'<div style="margin: 8px 0;"><strong>%1$s</strong></div><div style="margin: 12px 0; line-height: 1.6;">%2$s</div><div style="margin: 12px 0; padding: 10px; background-color: #f5f5f5; border-left: 3px solid #ffc107; font-size: 13px; color: #666;">%3$s</div><div style="margin: 16px 0;"><a href="#" class="mv-create-check-password" style="text-decoration: none;">%4$s</a></div>%5$s<div class="mv-resend-timer" style="font-size: 13px; color: #999; margin-top: 12px; min-height: 20px;"></div>',
				esc_html__( 'Password Reset Email Sent', 'mediavine-create' ),
				esc_html__( 'We\'ve sent you an email with instructions to set your password for Create Studio. Once you\'ve set your password, click below to continue.', 'mediavine-create' ),
				esc_html__( '📧 Check your spam folder if you don\'t see it in your inbox.', 'mediavine-create' ),
				esc_html__( 'I\'ve set my password, check again', 'mediavine-create' ),
				$resend_button
			);
			$this->admin_error_notice( $notice_name, $message, 'info' );
		} else {
			$dismiss_link = sprintf(
				'<a href="%1$s" style="margin-left: 16px; text-decoration: none; color: #666; font-size: 13px; align-self: center;">%2$s</a>',
				esc_url( Admin_Notice_Helper::get_dismiss_url( $notice_name . '-dismiss-notice' ) ),
				esc_html__( 'Dismiss', 'mediavine-create' )
			);
			$message      = sprintf(
				'<div style="margin: 8px 0;"><strong>%1$s</strong></div><div style="margin: 12px 0; line-height: 1.6;">%2$s</div><div style="margin: 16px 0; display: flex; align-items: center;"><button type="button" class="button button-primary mv-create-request-password-reset" data-email="%3$s">%4$s</button>%5$s</div>',
				esc_html__( 'Action Required: Set a Password for Create Studio', 'mediavine-create' ),
				esc_html__( 'To continue using Create\'s external services like Nutrition Calculation and Web Scraping, you need to create a password. Click below to receive an email with instructions.', 'mediavine-create' ),
				esc_attr( $api_email ),
				esc_html__( 'Send Password Reset Email', 'mediavine-create' ),
				$dismiss_link
			);
			$this->admin_error_notice( $notice_name, $message, 'warning' );
		}
	}
}
