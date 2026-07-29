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
		$action       = 'mv_create_dismiss_broadcast';
		$broadcast_id = Admin_Notice_Helper::get_verified_dismiss_value( $action );

		if ( null === $broadcast_id || '' === $broadcast_id ) {
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

		Admin_Notice_Helper::redirect_after_dismiss( [ $action ] );
	}

	/**
	 * Display broadcast banner notice on non-Create admin pages.
	 */
	public function broadcast_banner_notice() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Connecting to Create Studio is the consent to contact create.studio.
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		// Don't show on Create admin pages.
		if ( Admin_Notice_Helper::is_create_page() ) {
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
			$cta_text = $cta_text ? $cta_text : __( 'Update now', 'mediavine-create' );
		}

		$colors = $this->get_type_colors( $type );
		$icon   = $this->get_type_icon( $type );

		$dismiss_url = Admin_Notice_Helper::get_dismiss_url( 'mv_create_dismiss_broadcast', $broadcast['id'] );

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
			esc_html__( 'Dismiss', 'mediavine-create' )
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
	 * @param string $type Broadcast type slug.
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
	 * @param string $type Broadcast type slug.
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
