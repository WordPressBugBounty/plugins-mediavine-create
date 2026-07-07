<?php
namespace Mediavine\Create;

/**
 * Featured Review Block
 *
 * Server-side rendering for the mv/featured-review Gutenberg block.
 * Displays a highlighted review from a Create card.
 */
class Featured_Review_Block extends Plugin {

	/**
	 * Render the featured review block.
	 *
	 * @param array $attributes Block attributes containing cardId.
	 * @return string The rendered HTML, or empty string if no featured review.
	 */
	public static function render( $attributes ) {
		$card_id = isset( $attributes['cardId'] ) ? absint( $attributes['cardId'] ) : 0;

		// Validate card ID
		if ( ! $card_id ) {
			return '';
		}

		// Check if card exists
		if ( ! Featured_Review::card_exists( $card_id ) ) {
			return '';
		}

		// Get featured review
		$review = Featured_Review::get_featured_for_card( $card_id );
		if ( ! $review ) {
			return '';
		}

		// Get display settings
		$show_rating = self::get_display_setting( 'mv_create_featured_review_show_rating', true );
		$show_text   = self::get_display_setting( 'mv_create_featured_review_show_text', true );
		$show_name   = self::get_display_setting( 'mv_create_featured_review_show_name', true );

		// If all display settings are disabled, don't render the block
		if ( ! $show_rating && ! $show_text && ! $show_name ) {
			return '';
		}

		// Format reviewer name
		$formatted_name = Featured_Review::format_reviewer_name( $review->author_name );

		// Sanitize review content
		$review_content = wp_kses( $review->review_content, [] );

		// Determine if we have text content to display
		$has_text = $show_text && ! empty( $review_content );

		// Build wrapper classes - center content when no text is shown
		$wrapper_classes = [ 'create-featured-review' ];
		if ( ! $has_text ) {
			$wrapper_classes[] = 'create-featured-review-centered';
		}

		// Build output
		$output = '<div class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '">';

		if ( $show_rating ) {
			$output .= self::render_star_rating( $review->rating );
		}

		if ( $has_text ) {
			$output .= '<div class="create-featured-review-text">';
			$output .= '<p>"' . esc_html( $review_content ) . '"</p>';
			$output .= '</div>';
		}

		if ( $show_name && ! empty( $formatted_name ) ) {
			$output .= '<div class="create-featured-review-author">';
			$output .= '- ' . esc_html( $formatted_name );
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render the star rating HTML.
	 *
	 * @param float $rating The rating value (0-5).
	 * @return string The star rating HTML.
	 */
	private static function render_star_rating( $rating ) {
		$rating = floatval( $rating );
		$rating = max( 0, min( 5, $rating ) ); // Clamp to 0-5

		$output  = '<div class="create-featured-review-rating" data-rating="' . esc_attr( $rating ) . '">';
		$output .= '<span class="create-featured-review-stars" role="img" aria-label="' . esc_attr( sprintf( __( '%s out of 5 stars', 'mediavine' ), $rating ) ) . '">';

		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $rating >= $i ) {
				// Full star
				$output .= '<span class="mv-star mv-star-full">★</span>';
			} elseif ( $rating >= ( $i - 0.5 ) ) {
				// Half star
				$output .= '<span class="mv-star mv-star-half">★</span>';
			} else {
				// Empty star
				$output .= '<span class="mv-star mv-star-empty">☆</span>';
			}
		}

		$output .= '</span>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Get a display setting value.
	 *
	 * @param string $setting_slug The setting slug.
	 * @param bool   $default      Default value if setting not found.
	 * @return bool The setting value.
	 */
	private static function get_display_setting( $setting_slug, $default = true ) {
		$value = \Mediavine\Settings::get_setting( $setting_slug );

		// Only use default if setting doesn't exist at all
		if ( null === $value ) {
			return $default;
		}

		// Check for truthy values - empty string '' (from unchecked checkbox) is false
		return 'true' === $value || true === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Render the block for editor preview (with additional context).
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $is_editor  Whether this is an editor preview.
	 * @return string The rendered HTML.
	 */
	public static function render_editor_preview( $attributes, $is_editor = true ) {
		$card_id = isset( $attributes['cardId'] ) ? absint( $attributes['cardId'] ) : 0;

		if ( ! $is_editor ) {
			return self::render( $attributes );
		}

		// For editor, we show warnings for edge cases
		if ( ! $card_id ) {
			return self::render_editor_warning( __( 'Please select a Create card.', 'mediavine' ) );
		}

		if ( ! Featured_Review::card_exists( $card_id ) ) {
			return self::render_editor_warning( __( 'Card no longer exists.', 'mediavine' ) );
		}

		$review = Featured_Review::get_featured_for_card( $card_id );
		if ( ! $review ) {
			return self::render_editor_warning( __( 'No featured review selected for this card.', 'mediavine' ) );
		}

		return self::render( $attributes );
	}

	/**
	 * Render an editor warning message.
	 *
	 * @param string $message The warning message.
	 * @return string The warning HTML.
	 */
	private static function render_editor_warning( $message ) {
		return '<div class="create-featured-review create-featured-review-warning">' .
			'<span class="create-featured-review-warning-icon">⚠️</span> ' .
			esc_html( $message ) .
			'</div>';
	}

	/**
	 * Register the Gutenberg block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$block_name = 'mv/featured-review';

		// Skip if already registered
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return;
		}

		register_block_type( $block_name, [
			'api_version'     => 3,
			'editor_script'   => Plugin::PLUGIN_DOMAIN . '-script',
			'render_callback' => [ __CLASS__, 'render' ],
			'attributes'      => [
				'cardId' => [
					'type' => 'number',
				],
			],
			'supports'        => [
				'reusable' => false,
				'html'     => false,
			],
		] );
	}

	/**
	 * Initialize block registration.
	 */
	public function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}
}
