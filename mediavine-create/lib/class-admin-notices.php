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
		add_action( 'admin_notices', [ $this, 'payment_failed_notice' ] );
		add_action( 'admin_notices', [ $this, 'cancel_pending_notice' ] );
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
			Plugin_Context::assets_url() . 'admin/ui/static/password-reset-notice.js',
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

		$payload = \Mediavine\Create\Helpers\Jwt::decode_payload( $api_token );
		if ( ! is_array( $payload ) || ! isset( $payload['email'] ) || ! is_email( $payload['email'] ) ) {
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

	/**
	 * Inline full-colour Create logo mark for admin notices.
	 *
	 * Ported from Create Studio's `img/logo/logo-solo.svg` — the current
	 * eight-petal mark. (Its older `img/icon.svg` is a stale palette: a duller
	 * green and a teal petal where the mark now has deep navy.)
	 *
	 * Flattened on the way in for two reasons, both about living inside someone
	 * else's page:
	 *  - The source's Inkscape clipPaths are no-ops (each is a full-canvas rect)
	 *    but would put eight globally-scoped ids into wp-admin, where an id
	 *    collision would silently blank out petals. Dropped; renders identically.
	 *  - `style="fill:#..."` becomes a plain `fill` attribute so a stray
	 *    `svg { style }` rule from another plugin can't override it.
	 *
	 * Inlined rather than enqueued because the notice can render on ANY admin
	 * screen, including ones Create's assets never reach, and a broken image
	 * would undercut the one notice that has to look trustworthy.
	 *
	 * @return string SVG markup.
	 */
	private static function brand_mark() {
		return '<svg viewBox="0 0 267.01025 267.00867" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" width="28" height="28">'
			. '<g transform="translate(-287.99996,-548.99999)">'
			. '<path fill="#f8b0aa" d="M 0,0 C -0.566,0.747 -14.039,18.276 -31.408,18.192 -48.79,18.122 -62.098,0.466 -62.656,-0.286 l -2.097,-2.824 2.123,-2.804 c 0.565,-0.746 14.034,-18.275 31.411,-18.194 17.38,0.072 30.687,17.729 31.245,18.481 l 2.097,2.822 z" transform="matrix(1.3333333,0,0,-1.3333333,374.33733,678.55986)"/>'
			. '<path fill="#243c4a" d="M 0,0 C -0.566,0.747 -14.039,18.276 -31.408,18.192 -48.79,18.122 -62.098,0.466 -62.656,-0.286 l -2.097,-2.824 2.123,-2.804 c 0.565,-0.746 14.034,-18.275 31.411,-18.194 17.38,0.072 30.688,17.729 31.245,18.481 l 2.098,2.822 z" transform="matrix(1.3333333,0,0,-1.3333333,552.17827,678.55986)"/>'
			. '<path fill="#00bd7e" d="m 0,0 -2.824,2.097 -2.804,-2.121 c -0.747,-0.567 -18.275,-14.035 -18.195,-31.413 0.072,-17.379 17.729,-30.686 18.48,-31.245 l 2.824,-2.097 2.805,2.124 c 0.746,0.564 18.275,14.039 18.192,31.406 C 18.406,-13.865 0.751,-0.557 0,0" transform="matrix(1.3333333,0,0,-1.3333333,425.06827,551.796)"/>'
			. '<path fill="#5870ac" d="m 0,0 -2.824,2.097 -2.804,-2.121 c -0.747,-0.567 -18.275,-14.035 -18.195,-31.413 0.072,-17.379 17.729,-30.686 18.48,-31.245 l 2.824,-2.097 2.805,2.124 c 0.746,0.564 18.275,14.039 18.192,31.406 C 18.406,-13.865 0.751,-0.557 0,0" transform="matrix(0.94276705,0.94285098,0.94285098,-0.94276705,516.44603,592.59868)"/>'
			. '<path fill="#5f3553" d="m 0,0 -2.824,2.095 -2.804,-2.124 c -0.747,-0.564 -18.275,-14.032 -18.195,-31.411 0.072,-17.379 17.729,-30.686 18.48,-31.244 l 2.824,-2.097 2.805,2.124 c 0.746,0.566 18.275,14.038 18.192,31.406 C 18.406,-13.867 0.751,-0.559 0,0" transform="matrix(1.3333333,0,0,-1.3333333,425.06827,729.634)"/>'
			. '<path fill="#dc4f3f" d="m 0,0 -0.514,3.48 -3.484,0.482 c -0.928,0.129 -22.85,2.995 -35.073,-9.345 -12.241,-12.343 -9.167,-34.237 -9.03,-35.161 l 0.514,-3.48 3.485,-0.482 c 0.927,-0.129 22.845,-2.999 35.077,9.344 C 3.212,-22.821 0.137,-0.927 0,0" transform="matrix(1.3333333,0,0,-1.3333333,390.69467,718.34893)"/>'
			. '<path fill="#ffd267" d="m 0,0 c -12.342,12.241 -34.236,9.168 -35.162,9.031 l -3.479,-0.514 -0.483,-3.484 c -0.128,-0.928 -2.998,-22.845 9.346,-35.078 12.34,-12.236 34.235,-9.162 35.161,-9.026 l 3.48,0.514 0.481,3.486 C 9.473,-34.143 12.34,-12.222 0,0" transform="matrix(1.3333333,0,0,-1.3333333,378.4824,599.6004)"/>'
			. '<path fill="#827a9f" d="M 0,0 C -12.342,12.241 -34.236,9.167 -35.162,9.03 L -38.641,8.516 -39.124,5.03 c -0.128,-0.926 -2.998,-22.844 9.346,-35.075 12.34,-12.239 34.235,-9.164 35.161,-9.027 l 3.48,0.514 0.482,3.484 C 9.473,-34.146 12.34,-12.223 0,0" transform="matrix(1.3333333,0,0,-1.3333333,504.23493,725.3508)"/>'
			. '</g>'
			. '</svg>';
	}

	/**
	 * Scoped styles for the branded payment notice.
	 *
	 * Printed with the notice instead of enqueued for the same reason the mark
	 * is inlined: this notice renders on screens Create's stylesheet never
	 * reaches.
	 *
	 * @return void
	 */
	private static function print_payment_notice_styles() {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;

		echo '<style id="mv-create-payment-notice-css">
			.mv-create-payment-notice { border-left-color: #dc4f3f; padding: 12px 16px; }
			.mv-create-payment-notice__row { display: flex; align-items: flex-start; gap: 11px; }
			.mv-create-payment-notice__mark { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; margin-top: -1px; }
			.mv-create-payment-notice__body { flex: 1; min-width: 0; }
			.mv-create-payment-notice__headline { margin: 0 0 3px; font-size: 14px; color: #1d2327; }
			.mv-create-payment-notice__brand { font-weight: 600; }
			.mv-create-payment-notice__sep { color: #787c82; font-weight: 400; }
			.mv-create-payment-notice__text { margin: 0 0 11px; line-height: 1.5; color: #50575e; }
			.mv-create-payment-notice__actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0; }
			.mv-create-payment-notice__countdown { color: #a33526; font-size: 12px; font-weight: 600; }
		</style>';
	}

	/**
	 * Display the dunning "update your card" notice (CRE-295).
	 *
	 * A failed renewal no longer strips entitlements on the spot — Create Studio
	 * holds the paid tier while Stripe retries the card (create-studio CS-27).
	 * This notice IS the other half of that bargain: the only reason it is safe
	 * not to degrade the site is that the publisher is told, loudly, that their
	 * card needs fixing before the grace window closes.
	 *
	 * Deliberately NOT dismissible and not screen-scoped: it is time-boxed, it
	 * clears itself the moment Studio reports a recovered payment (or the grace
	 * window lapses), and a dismissed notice would let a site slide into a real
	 * downgrade unwarned.
	 */
	public function payment_failed_notice() {
		// Billing is an administrator concern; don't nag editors who can't fix it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! GateKeeper::is_payment_failed() ) {
			return;
		}

		self::print_payment_notice_styles();

		$days_left  = GateKeeper::get_grace_days_remaining();
		$plan_label = GateKeeper::tier_display_name( GateKeeper::get_effective_tier() );

		$countdown = $days_left > 0
			? sprintf(
				/* translators: %s: number of days left to fix the payment method. */
				_n( '%s day left', '%s days left', $days_left, 'mediavine-create' ),
				number_format_i18n( $days_left )
			)
			: '';

		printf(
			'<div class="notice notice-error mv-create-payment-notice">'
				. '<div class="mv-create-payment-notice__row">'
					. '<span class="mv-create-payment-notice__mark">%1$s</span>'
					. '<div class="mv-create-payment-notice__body">'
						. '<p class="mv-create-payment-notice__headline"><span class="mv-create-payment-notice__brand">%2$s</span> <span class="mv-create-payment-notice__sep">&middot;</span> <strong>%3$s</strong></p>'
						. '<p class="mv-create-payment-notice__text">%4$s</p>'
						. '<p class="mv-create-payment-notice__actions"><a class="button button-primary" href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a>%7$s</p>'
					. '</div>'
				. '</div>'
			. '</div>',
			self::brand_mark(),
			esc_html__( 'Create', 'mediavine-create' ),
			esc_html__( "Whoops! We couldn't process your payment", 'mediavine-create' ),
			esc_html(
				sprintf(
					/* translators: %s: subscription plan name, e.g. "Pro Pass". */
					__( 'Update your billing details to keep your %s features.', 'mediavine-create' ),
					$plan_label
				)
			),
			esc_url( User_Verification::open_billing_url() ),
			esc_html__( 'Update payment method', 'mediavine-create' ),
			$countdown ? '<span class="mv-create-payment-notice__countdown">' . esc_html( $countdown ) . '</span>' : ''
		);
	}

	/**
	 * Display the "your plan cancels on <date>" heads-up (create-studio CS-26).
	 *
	 * A subscriber who cancels in Stripe keeps their plan until the period they
	 * paid for ends. Nothing is degraded in the meantime, so this is an
	 * informational warning, not the error-level nag the failed-payment notice
	 * uses: it tells the publisher when the premium features switch off and
	 * where to undo the cancellation.
	 *
	 * Shown to the same audience as payment_failed_notice(), and for the same
	 * reasons not dismissible or screen-scoped. It clears itself when Studio
	 * reports the subscription resumed, or the cancel date passes.
	 */
	public function cancel_pending_notice() {
		// Billing is an administrator concern; don't nag editors who can't act on it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! GateKeeper::is_cancel_pending() ) {
			return;
		}

		// A failed payment is the more urgent message and already links to
		// billing; don't stack two notices about the same subscription.
		if ( GateKeeper::is_payment_failed() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning cre-cancel-notice"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: subscription plan name, e.g. "Pro Pass". 2: date the plan cancels, in the site's date format. */
					__( 'Your %1$s plan is set to cancel on %2$s. You\'ll keep your premium features until then.', 'mediavine-create' ),
					GateKeeper::tier_display_name( GateKeeper::get_effective_tier() ),
					// Shared with the React admin's localized payload so both notices
					// print the same string.
					GateKeeper::get_cancel_date_display()
				)
			),
			esc_url( User_Verification::open_billing_url() ),
			esc_html__( 'Manage billing', 'mediavine-create' )
		);
	}
}
