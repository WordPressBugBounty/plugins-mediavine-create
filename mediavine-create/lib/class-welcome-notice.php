<?php
namespace Mediavine\Create;

/**
 * Handles the Welcome page display and admin notice for Create 2.0 upgrade.
 */
class Welcome_Notice {

	/** @var Welcome_Notice */
	private static $instance = null;

	/** @var string User meta key for tracking if user has seen welcome page */
	const USER_META_WELCOME_SEEN = 'mv_create_welcome_seen';

	/** @var string User meta key for tracking if user dismissed the banner */
	const USER_META_BANNER_DISMISSED = 'mv_create_welcome_banner_dismissed';

	/** @var string Option key for tracking if 2.0 upgrade has occurred */
	const OPTION_SHOW_WELCOME = 'mv_create_show_welcome';

	/**
	 * @return Welcome_Notice
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		// Hook into plugin update to detect 2.0 upgrade
		add_action( Plugin::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'maybe_set_welcome_flag' ], 5 );

		// Also check on admin_init for existing 2.0 installs that haven't seen welcome yet
		add_action( 'admin_init', [ $this, 'maybe_set_welcome_flag_for_existing' ], 1 );

		// Redirect to welcome page on first visit after upgrade
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_welcome' ] );

		// Handle banner dismissal
		add_action( 'admin_init', [ $this, 'handle_banner_dismiss' ] );

		// Show admin notice banner
		add_action( 'admin_notices', [ $this, 'welcome_banner_notice' ] );
	}

	/**
	 * Check if this is a 2.0 upgrade and set the welcome flag
	 *
	 * @param string $last_version The previous plugin version
	 */
	public function maybe_set_welcome_flag( $last_version ) {
		// Only show welcome for upgrades from pre-2.0 versions
		if ( version_compare( $last_version, '2.0.0', '<' ) ) {
			update_option( self::OPTION_SHOW_WELCOME, true );
		}
	}

	/**
	 * For existing 2.0 installs, check if we should show the welcome page
	 *
	 * This handles the case where someone is already on 2.0.0 but hasn't
	 * been prompted to see the welcome page yet (e.g., the welcome feature
	 * was added after they upgraded to 2.0).
	 */
	public function maybe_set_welcome_flag_for_existing() {
		// Only run this check once
		if ( get_option( 'mv_create_welcome_check_done' ) ) {
			return;
		}

		// Mark that we've done this check
		update_option( 'mv_create_welcome_check_done', true );

		// If the welcome option already exists (either true or false), don't override
		if ( false !== get_option( self::OPTION_SHOW_WELCOME, false ) ) {
			return;
		}

		// If we're on 2.0.0+, show the welcome page
		$current_version = get_option( 'mv_create_version', '0.0.0' );
		if ( version_compare( $current_version, '2.0.0', '>=' ) ) {
			update_option( self::OPTION_SHOW_WELCOME, true );
		}
	}

	/**
	 * Check if current user has seen the welcome page
	 *
	 * @return bool
	 */
	private function user_has_seen_welcome() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true; // No user logged in, don't redirect
		}

		return (bool) get_user_meta( $user_id, self::USER_META_WELCOME_SEEN, true );
	}

	/**
	 * Mark current user as having seen the welcome page
	 */
	private function mark_user_seen_welcome() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META_WELCOME_SEEN, '1' );
		}
	}

	/**
	 * Check if current user has dismissed the welcome banner
	 *
	 * @return bool
	 */
	private function user_has_dismissed_banner() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}

		return (bool) get_user_meta( $user_id, self::USER_META_BANNER_DISMISSED, true );
	}

	/**
	 * Redirect to welcome page if user hasn't seen it yet
	 */
	public function maybe_redirect_to_welcome() {
		// Only redirect for users who can manage options
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if welcome should be shown
		if ( ! get_option( self::OPTION_SHOW_WELCOME ) ) {
			return;
		}

		// Check if user has already seen welcome
		if ( $this->user_has_seen_welcome() ) {
			return;
		}

		// Check if we're on a Create admin page (but not already on welcome)
		$current_url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Don't redirect if already on welcome page
		if ( strpos( $current_url, 'page=mv_create_welcome' ) !== false ) {
			// Mark as seen when they reach the welcome page
			$this->mark_user_seen_welcome();
			return;
		}

		// Only redirect from Create card/settings pages (welcome handled above).
		$is_create_page = (
			false !== strpos( $current_url, 'post_type=mv_create' ) ||
			false !== strpos( $current_url, 'page=mv_settings' )
		);

		if ( ! $is_create_page ) {
			return;
		}

		// Perform redirect
		wp_safe_redirect( admin_url( 'admin.php?page=mv_create_welcome' ) );
		exit;
	}

	/**
	 * Handle banner dismiss action
	 */
	public function handle_banner_dismiss() {
		$action = 'mv_create_dismiss_welcome_banner';
		$value  = Admin_Notice_Helper::get_verified_dismiss_value( $action );

		if ( null === $value ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META_BANNER_DISMISSED, '1' );
		}

		Admin_Notice_Helper::redirect_after_dismiss( [ $action ] );
	}

	/**
	 * Display welcome banner notice on non-Create admin pages
	 */
	public function welcome_banner_notice() {
		// Only show for users who can manage options
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if welcome should be shown
		if ( ! get_option( self::OPTION_SHOW_WELCOME ) ) {
			return;
		}

		// Check if user has dismissed banner
		if ( $this->user_has_dismissed_banner() ) {
			return;
		}

		// Don't show on Create admin pages (React UI takes over).
		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->base ) ) {
			return;
		}

		if ( Admin_Notice_Helper::is_create_page() ) {
			return;
		}

		// Build the banner
		$welcome_url = admin_url( 'admin.php?page=mv_create_welcome' );
		$dismiss_url = Admin_Notice_Helper::get_dismiss_url( 'mv_create_dismiss_welcome_banner' );

		$message = sprintf(
			'<div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
				<div style="display: flex; align-items: center; gap: 12px;">
					<span style="font-size: 20px;">✨</span>
					<span><strong>%s</strong> %s</span>
				</div>
				<div style="display: flex; align-items: center; gap: 12px;">
					<a href="%s" class="button button-primary" style="margin: 0;">%s</a>
					<a href="%s" style="color: #666; text-decoration: none; font-size: 13px;">%s</a>
				</div>
			</div>',
			esc_html__( 'Create 2.0 is here!', 'mediavine-create' ),
			esc_html__( 'Discover new themes, interactive mode, and more.', 'mediavine-create' ),
			esc_url( $welcome_url ),
			esc_html__( 'See What\'s New', 'mediavine-create' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'mediavine-create' )
		);

		printf(
			'<div class="notice notice-info" style="padding: 12px 16px;">%s</div>',
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above
		);
	}
}
