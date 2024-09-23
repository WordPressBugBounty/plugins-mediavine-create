<?php
namespace Mediavine\Create;

/**
 * Class for our custom content blocks
 */
final class Custom_Content {
	/**
	 * Construct the object with necessary properties and run hooks
	 *
	 * @return void
	 */
	public function __construct( $namespace, $label ) {
		global $wp_version;

		$this->namespace = $namespace;
		$this->label     = $label;
		$this->nonce_key = $namespace . '_nonce';

		// version-check for filter compatibility
		$block_categories_filter = 'block_categories';
		if ( version_compare( $wp_version, '5.8', '>=' ) ) {
			$block_categories_filter = 'block_categories_all';
		}

		add_filter( $block_categories_filter, [ $this, 'block_categories' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'init', [ $this, 'init' ] );
		add_action( 'media_buttons', [ $this, 'media_buttons' ] );
		add_action( 'save_post', [ $this, 'save_post' ] );
	}

	/**
	 * Creates a new static object
	 *
	 * @param string $namespace Namespace of the block
	 * @param string $label Label of the block
	 * @return void
	 */
	public static function make( $namespace, $label ) {
		return new static( $namespace, $label );
	}

	/**
	 * Adds meta field blocks to TinyMCE and Gutenberg editors
	 *
	 * @return array List of meta field blocks to be added
	 */
	private function get_meta_fields() {
		return apply_filters( $this->namespace . '_meta_fields', [] );
	}

	/**
	 * Adds content blocks to Gutenberg editor
	 *
	 * @return array List of content blocks to be added
	 */
	private function get_content_blocks() {
		return apply_filters( $this->namespace . '_content_blocks', [] );
	}

	/**
	 * Add meta box to TinyMCE.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			$this->namespace,
			$this->label,
			[ $this, 'render_meta_box' ],
			'post',
			'normal',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Render a meta box in a post.
	 *
	 * @param \WP_Post $post
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ) {
		$fields = $this->get_meta_fields();

		// Loop over registered fields, creating a container for each
		?>
			<div id="<?php echo esc_attr( $this->namespace ); ?>-meta-root">
				<?php wp_nonce_field( 'save_post', $this->nonce_key ); ?>
				<?php foreach ( $fields as $field ) { ?>
					<div
						id="<?php echo esc_attr( $field['slug'] ); ?>"
						data-type="<?php echo esc_attr( $field['type'] ); ?>"
						<?php if ( metadata_exists( 'post', $post->ID, $field['slug'] ) ) { ?>
							data-value="<?php echo esc_attr( get_post_meta( $post->ID, $field['slug'], true ) ); ?>"
						<?php }; ?>
					></div>
				<?php } ?>
			</div>
		<?php
	}

	/**
	 * Register the block categories.
	 *
	 * @param array $categories An array of categories available to the editors
	 * @return array $categories
	 */
	public function block_categories( $categories = [] ) {
		// TODO: the following page check should no longer be needed once a fix for the following issue is released
		// https://github.com/WordPress/gutenberg/issues/28517
		global $pagenow;
		if ( 'widgets.php' === $pagenow || 'customize.php' === $pagenow ) {
			// This is a widgets block editor.  We only want our blocks registered for post/page editors.
			return $categories;
		} else {
			return array_merge(
				$categories,
				[
					[
						'slug'  => $this->namespace . '-blocks',
						'title' => $this->label . ' ' . __( 'Content Blocks', 'mediavine' ),
					],
					[
						'slug'  => $this->namespace . '-meta',
						'title' => $this->label . __( 'Meta Boxes', 'mediavine' ),
					],
				]
			);
		}
	}

	/**
	 * Register the media buttons for Gutenberg content blocks;
	 *
	 * @return void
	 */
	public function media_buttons() {
		$blocks = $this->get_content_blocks();
		if ( [] === $blocks ) {
			return;
		}

		// Loop over registered blocks, creating a container for each
		?>
			<div data-scope="<?php echo esc_attr( $this->namespace ); ?>">
				<?php foreach ( $blocks as $block ) { ?>
					<div data-shortcode="<?php echo esc_attr( $block['slug'] ); ?>"></div>
				<?php } ?>
			</div>
		<?php
	}

	/**
	 * Adds given fields to the post meta on save.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function save_post( $post_id ) {
		$verified = $this->verify_nonce();
		if ( ! $verified ) {
			return;
		}

		// Loop over registered fields
		foreach ( $this->get_meta_fields() as $field ) {
			$value = $this->field_value( $field );
			if ( is_null( $value ) ) {
				delete_post_meta( $post_id, $this->field_slug( $field ) );
				continue;
			}

			// Sanitize values
			if ( 'boolean' === $this->field_type( $field ) ) {
				$value = 'true' === $value;
			}

			// Store data
			update_post_meta( $post_id, $this->field_slug( $field ), $value );
		}
	}

	/**
	 * Register the meta fields.
	 *
	 * @return void
	 */
	public function init() {
		$fields = $this->get_meta_fields();

		// Register REST field
		foreach ( $fields as $field ) {
			register_meta(
				'post',
				$this->field_slug( $field ),
				[
					'type'         => $this->field_type( $field ),
					'single'       => true,
					'show_in_rest' => true,
				]
			);
		}
	}

	/**
	 * Returns the type of a field.
	 *
	 * @param array $field
	 * @return string|null $type
	 */
	private function field_type( $field ) {
		if ( ! array_key_exists( 'type', $field ) ) {
			return null;
		}

		return $field['type'];
	}

	/**
	 * Gets the slug of a field.
	 *
	 * @param array $field
	 * @return string|null $slug
	 */
	private function field_slug( $field ) {
		if ( ! array_key_exists( 'slug', $field ) ) {
			return null;
		}

		return $field['slug'];
	}

	/**
	 * Gets the value of a field
	 * phpcs:disable Nonce verified before function is called
	 *
	 * @param array $field
	 * @return mixed$value
	 */
	// phpcs:disable
	private function field_value( $field ) {
		$value = isset( $_POST[ $this->field_slug( $field ) ] ) ? $_POST[ $this->field_slug( $field ) ] : null;

		if ( is_null( $value ) || empty( $value ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}
	// phpcs:enable

	/**
	 * Gets the nonce from `$_POST`.
	 * phpcs:disable Nonce verified before function is called
	 *
	 * @return string|bool $nonce
	 */
	// phpcs:disable
	private function get_nonce() {
		return isset( $_POST[ $this->nonce_key ] ) ? $_POST[ $this->nonce_key ] : false;
	}
	// phpcs:enable

	/**
	 * Checks there is a nonce set and that it is a verified nonce.
	 *
	 * @return bool verified nonce
	 */
	private function verify_nonce() {
		$nonce = $this->get_nonce();

		if ( ! $nonce ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'save_post' ) ) {
			die;
		}

		return true;
	}
}
