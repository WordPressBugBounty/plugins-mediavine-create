<?php
namespace Mediavine\Create;

/**
 * Handles broadcast banner notices in the WordPress admin.
 */
class Broadcast_Notice {

	/** @var Broadcast_Notice */
	private static $instance = null;

	/**
	 * @return Broadcast_Notice
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_notices', [ $this, 'broadcast_banner_notice' ] );
		add_action( 'admin_init', [ $this, 'handle_banner_dismiss' ] );
	}

	/**
	 * Handle banner dismiss action.
	 */
	public function handle_banner_dismiss() {
		if ( ! isset( $_GET['mv_create_dismiss_broadcast'] ) ) {
			return;
		}

		// Verify nonce to prevent CSRF.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mv_create_dismiss_broadcast' ) ) {
			return;
		}

		$broadcast_id = sanitize_text_field( wp_unslash( $_GET['mv_create_dismiss_broadcast'] ) );
		if ( empty( $broadcast_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$dismissed = get_user_meta( $user_id, 'mv_create_dismissed_broadcasts', true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}

		if ( ! in_array( $broadcast_id, $dismissed, true ) ) {
			$dismissed[] = $broadcast_id;
			update_user_meta( $user_id, 'mv_create_dismissed_broadcasts', $dismissed );
		}

		$redirect_url = remove_query_arg( [ 'mv_create_dismiss_broadcast', '_wpnonce' ] );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Display broadcast banner notice on non-Create admin pages.
	 */
	public function broadcast_banner_notice() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Don't show on Create admin pages.
		$current_url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$is_create_page = (
			strpos( $current_url, 'post_type=mv_create' ) !== false ||
			strpos( $current_url, 'page=mv_settings' ) !== false ||
			strpos( $current_url, 'page=mv_create_welcome' ) !== false
		);

		if ( $is_create_page ) {
			return;
		}

		$broadcast = Dashboard_API::get_active_broadcast();
		if ( ! $broadcast ) {
			return;
		}

		$type     = isset( $broadcast['type'] ) ? $broadcast['type'] : 'announcement';
		$title    = isset( $broadcast['title'] ) ? $broadcast['title'] : '';
		$body     = isset( $broadcast['body'] ) ? $broadcast['body'] : '';
		$cta_text = isset( $broadcast['cta_text'] ) ? $broadcast['cta_text'] : '';
		$cta_url  = '';
		if ( ! empty( $broadcast['url'] ) ) {
			$cta_url = $broadcast['url'];
		} elseif ( 'urgent' === $type ) {
			$cta_url  = admin_url( 'update-core.php' );
			$cta_text = $cta_text ? $cta_text : __( 'Update now', 'mediavine' );
		}

		$colors = $this->get_type_colors( $type );
		$icon   = $this->get_type_icon( $type );

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'mv_create_dismiss_broadcast', $broadcast['id'] ),
			'mv_create_dismiss_broadcast'
		);

		$f = "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";

		// Build the notice — matches Welcome_Notice style but with type-based theming.
		$message = sprintf(
			'<div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; font-family: %s;">
				<div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
					<span style="font-size: 18px; flex-shrink: 0; line-height: 1;">%s</span>
					<div>
						<strong style="font-size: 13px; color: %s;">%s</strong>
						<span style="font-size: 13px; color: #57534e; margin-left: 4px;">%s</span>
					</div>
				</div>
				<div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;">
					%s
					<a href="%s" style="color: #a8a29e; text-decoration: none; font-size: 13px;">%s</a>
				</div>
			</div>',
			$f,
			$icon,
			esc_attr( $colors['accent'] ),
			esc_html( $title ),
			esc_html( $body ),
			( $cta_text && $cta_url ) ? sprintf(
				'<a href="%s" class="button button-primary" style="margin: 0; background: %s; border-color: %s; font-family: %s;">%s</a>',
				esc_url( $cta_url ),
				esc_attr( $colors['accent'] ),
				esc_attr( $colors['accent'] ),
				$f,
				esc_html( $cta_text )
			) : '',
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'mediavine' )
		);

		printf(
			'<div class="notice" style="border-left-color: %s; padding: 12px 16px;">%s</div>',
			esc_attr( $colors['accent'] ),
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above
		);
	}

	/**
	 * Get color scheme for a broadcast type.
	 *
	 * Uses Create's own palette rather than generic blues/purples.
	 *
	 * @param string $type
	 * @return array
	 */
	private function get_type_colors( $type ) {
		$map = [
			'announcement' => [ 'accent' => '#3d5b6d' ], // Create primary teal
			'feature'      => [ 'accent' => '#6d5b8a' ], // Create list-purple
			'promotion'    => [ 'accent' => '#c17f59' ], // Create recipe-copper
			'beta'         => [ 'accent' => '#5b8a6d' ], // Create howto-green
			'urgent'       => [ 'accent' => '#c75450' ], // warm red for urgent upgrades
			'bug'          => [ 'accent' => '#a8872d' ], // amber for known issues
		];

		return isset( $map[ $type ] ) ? $map[ $type ] : $map['announcement'];
	}

	/**
	 * Get emoji icon for a broadcast type.
	 *
	 * @param string $type
	 * @return string
	 */
	private function get_type_icon( $type ) {
		$icons = [
			'announcement' => '&#x1F4E2;',
			'feature'      => '&#x2728;',
			'promotion'    => '&#x1F381;',
			'beta'         => '&#x1F9EA;',
			'urgent'       => '&#x1F6A8;',
			'bug'          => '&#x1F41B;',
		];

		return isset( $icons[ $type ] ) ? $icons[ $type ] : $icons['announcement'];
	}
}
