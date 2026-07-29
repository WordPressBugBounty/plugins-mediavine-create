<?php
namespace Mediavine\Create;

/**
 * Add Create card edit links to the WordPress admin bar
 */
class Admin_Bar {

	/** @var Admin_Bar */
	private static $instance = null;

	/**
	 * @return Admin_Bar
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize admin bar hooks
	 */
	public function init() {
		add_action( 'admin_bar_menu', [ $this, 'add_create_card_links' ], 100 );
		add_action( 'wp_head', [ $this, 'add_admin_bar_styles' ] );
		add_action( 'admin_head', [ $this, 'add_admin_bar_styles' ] );
	}

	/**
	 * Add Create card edit links to admin bar
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function add_create_card_links( $wp_admin_bar ) {
		// Only show on frontend single posts/pages
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		// Check if user has permission to edit Create cards
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Get Create cards embedded in this post
		$creation_ids = Creations::get_creation_ids_by_post( $post_id );

		if ( empty( $creation_ids ) || is_wp_error( $creation_ids ) ) {
			return;
		}

		// Get card details for each creation
		$cards = [];
		foreach ( $creation_ids as $creation_id ) {
			$creation = Plugin::$models_v2->mv_creations->find_one( $creation_id );
			if ( $creation ) {
				$type_label = $this->get_type_label( $creation->type );
				$cards[]    = [
					'id'        => $creation->id,
					'object_id' => $creation->object_id,
					'type'      => $creation->type,
					/* translators: %s: creation type (e.g. Recipe, How-To) */
					'title'     => $creation->title ?: sprintf( __( 'Untitled %s', 'mediavine-create' ), $type_label ),
				];
			}
		}

		if ( empty( $cards ) ) {
			return;
		}

		$icon_svg   = $this->get_create_icon_svg();
		$card_count = count( $cards );

		// Single card: direct link with singular label
		if ( 1 === $card_count ) {
			$card     = $cards[0];
			$edit_url = admin_url(
				sprintf(
					'admin.php?page=create_editor&id=%d&type=%s',
					$card['id'],
					$card['type']
				)
			);

			$wp_admin_bar->add_node(
				[
					'id'    => 'mv-create-cards',
					'title' => '<span class="mv-create-admin-bar-icon">' . $icon_svg . '</span> ' . __( 'Edit Create Card', 'mediavine-create' ),
					'href'  => $edit_url,
				]
			);
		} else {
			// Multiple cards: dropdown menu with plural label
			$wp_admin_bar->add_node(
				[
					'id'    => 'mv-create-cards',
					'title' => '<span class="mv-create-admin-bar-icon">' . $icon_svg . '</span> ' . __( 'Edit Create Cards', 'mediavine-create' ),
					'href'  => false,
				]
			);

			// Add submenu item for each card
			foreach ( $cards as $card ) {
				$edit_url = admin_url(
					sprintf(
						'admin.php?page=create_editor&id=%d&type=%s',
						$card['id'],
						$card['type']
					)
				);

				$wp_admin_bar->add_node(
					[
						'id'     => 'mv-create-card-' . $card['id'],
						'parent' => 'mv-create-cards',
						'title'  => esc_html( $card['title'] ),
						'href'   => $edit_url,
					]
				);
			}
		}
	}

	/**
	 * Get display label for card type
	 *
	 * @param string $type Card type (recipe, diy, list)
	 * @return string Display label
	 */
	private function get_type_label( $type ) {
		$labels = [
			'recipe' => __( 'Recipe', 'mediavine-create' ),
			'diy'    => __( 'How-To', 'mediavine-create' ),
			'list'   => __( 'List', 'mediavine-create' ),
		];

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
	}

	/**
	 * Get Create icon SVG
	 *
	 * @return string SVG markup
	 */
	private function get_create_icon_svg() {
		return '<svg width="20" height="20" viewBox="0 0 25 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8.084 11.645L8.349 11.981L8.087 12.32C8.017 12.41 6.356 14.526 4.187 14.535C2.017 14.545 0.336 12.444 0.265 12.355L0 12.017L0.262 11.679C0.332 11.589 1.992 9.473 4.162 9.464C6.331 9.454 8.013 11.554 8.084 11.644V11.645ZM24.734 11.645L25 11.981L24.738 12.32C24.668 12.41 23.008 14.526 20.838 14.535C18.668 14.545 16.987 12.444 16.916 12.355L16.651 12.018L16.913 11.68C16.983 11.59 18.644 9.474 20.814 9.465C22.982 9.455 24.664 11.555 24.734 11.645ZM12.835 0.251C12.928 0.318 15.132 1.913 15.141 3.996C15.151 6.078 12.964 7.693 12.871 7.76L12.521 8.015L12.168 7.764C12.074 7.696 9.87 6.102 9.86 4.019C9.85 1.936 12.038 0.322 12.131 0.254L12.48 0L12.833 0.251H12.835ZM12.835 16.236C12.928 16.303 15.132 17.898 15.141 19.981C15.151 22.063 12.964 23.678 12.871 23.745L12.521 24L12.168 23.749C12.074 23.682 9.87 22.087 9.86 20.004C9.85 17.921 12.039 16.307 12.132 16.24L12.482 15.985L12.835 16.236ZM9.616 15.222C9.633 15.332 10.017 17.957 8.489 19.436C6.962 20.916 4.226 20.571 4.111 20.556L3.675 20.498L3.611 20.081C3.594 19.971 3.211 17.346 4.739 15.867C6.265 14.388 9.001 14.732 9.117 14.747L9.552 14.805L9.616 15.222ZM15.45 9.195L15.386 8.778C15.368 8.667 14.985 6.043 16.513 4.564C18.039 3.084 20.775 3.428 20.891 3.444L21.326 3.502L21.39 3.919C21.407 4.029 21.791 6.654 20.264 8.133C18.737 9.613 16.001 9.268 15.884 9.253L15.45 9.195ZM8.472 4.548C10.012 6.013 9.654 8.64 9.638 8.751L9.578 9.169L9.144 9.231C9.028 9.247 6.294 9.616 4.754 8.149C3.214 6.683 3.572 4.056 3.588 3.945L3.648 3.528L4.082 3.466C4.198 3.449 6.932 3.081 8.472 4.548ZM20.246 15.851C21.786 17.316 21.428 19.943 21.413 20.055L21.353 20.472L20.918 20.534C20.802 20.55 18.069 20.919 16.528 19.452C14.988 17.986 15.346 15.359 15.362 15.248L15.422 14.831L15.856 14.769C15.972 14.753 18.706 14.384 20.246 15.851Z" /></svg>';
	}

	/**
	 * Add admin bar icon styles
	 */
	public function add_admin_bar_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<style>
			.mv-create-admin-bar-icon {
				display: inline-block;
				vertical-align: middle;
			}
			.mv-create-admin-bar-icon svg {
				display: block;
				width: 18px;
				height: 18px;
				fill: currentColor;
			}
			#wpadminbar #wp-admin-bar-mv-create-cards > .ab-item {
				display: flex;
				align-items: center;
				gap: 6px;
			}
		</style>
		<?php
	}
}
