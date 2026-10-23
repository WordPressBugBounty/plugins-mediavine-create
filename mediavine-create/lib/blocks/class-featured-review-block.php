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
		// Featured review block is gated to the guides band (Pro Guides / Pro Pass).
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_FEATURED_REVIEW_BLOCK ) ) {
			return '';
		}

		$card_id = isset( $attributes['cardId'] ) ? absint( $attributes['cardId'] ) : 0;

		// Validate card ID
		if ( ! $card_id ) {
			return '';
		}

		// Fetch the card once — used both to confirm it exists and to pick the
		// jump label based on its type (recipe vs how-to).
		$card = self::$models_v2->mv_creations->select_one( $card_id );
		if ( ! $card ) {
			return '';
		}

		// Get featured review
		$review = Featured_Review::get_featured_for_card( $card_id );
		if ( ! $review ) {
			return '';
		}

		$card_type = ! empty( $card->type ) ? $card->type : 'recipe';

		// Get display settings
		$show_rating = self::get_display_setting( 'mv_create_featured_review_show_rating', true );
		$show_text   = self::get_display_setting( 'mv_create_featured_review_show_text', true );
		$show_name   = self::get_display_setting( 'mv_create_featured_review_show_name', true );

		// Optional "jump to card" control. Independent of the display toggles
		// above, so it must be considered before bailing on an empty block.
		$jump = self::render_jump_to_card( $card_id, $card_type );

		// If nothing at all is enabled, don't render the block.
		if ( ! $show_rating && ! $show_text && ! $show_name && '' === $jump ) {
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

		$output .= $jump;

		$output .= '</div>';

		return $output;
	}

	/**
	 * Whether the jump link may show for a card type, following the Jump to
	 * Card "Show Jump Button On" setting.
	 *
	 * That setting is hidden in Settings while Jump to Card is off, so a value
	 * left behind there must not keep limiting the featured review.
	 *
	 * @param string $card_type The card type (recipe|diy|list).
	 * @return bool
	 */
	private static function jump_allowed_for_type( $card_type ) {
		if ( ! self::get_display_setting( 'mv_create_enable_jump_to_recipe', false ) ) {
			return true;
		}

		return Creations_Jump_To_Recipe::is_jtr_type_allowed( $card_type );
	}

	/**
	 * Whether the jump link may show for a card, by ID.
	 *
	 * @param int $card_id The Create card ID.
	 * @return bool
	 */
	private static function jump_allowed_for_card( $card_id ) {
		// Skip the card lookup when every card type gets a jump link.
		if ( '' === Creations_Jump_To_Recipe::get_jtr_limited_type() ) {
			return true;
		}

		$card = self::$models_v2->mv_creations->select_one( $card_id );

		return self::jump_allowed_for_type( ! empty( $card->type ) ? $card->type : 'recipe' );
	}

	/**
	 * Render the optional "jump to card" link/button.
	 *
	 * Targets the card's #mv-creation-{id} anchor, which every Create card
	 * renders on its outer <section> element.
	 *
	 * @param int    $card_id   The Create card ID the review belongs to.
	 * @param string $card_type The card type (recipe|diy|list) for label choice.
	 * @return string The jump link HTML, or empty string when disabled.
	 */
	private static function render_jump_to_card( $card_id, $card_type = 'recipe' ) {
		if ( ! self::get_display_setting( 'mv_create_featured_review_show_jump', false ) ) {
			return '';
		}

		if ( ! self::jump_allowed_for_type( $card_type ) ) {
			return '';
		}

		$style = \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_style', 'link' );

		// Label depends on the card type, mirroring the Jump to Card feature.
		if ( 'diy' === $card_type ) {
			$label   = \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_howto_label' );
			$default = __( 'Jump to How-To', 'mediavine-create' );
		} else {
			$label   = \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_label' );
			$default = __( 'Jump to Recipe', 'mediavine-create' );
		}
		if ( '' === $label || null === $label ) {
			$label = $default;
		}

		$style_class = 'button' === $style
			? 'create-featured-review-jump-button'
			: 'create-featured-review-jump-link';

		// Resolve the chosen color to concrete values so it works even when the
		// block is relocated outside a card (where card CSS vars aren't inherited).
		$color = \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_color', 'secondary' );

		if ( 'custom' === $color ) {
			$custom_bg = self::sanitize_color( \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_custom_bg' ) );
			$custom_fg = self::sanitize_color( \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_custom_fg' ) );
			if ( 'button' === $style ) {
				// Button: background fill + explicit text color.
				$jump_color = $custom_bg ? $custom_bg : '#c8a951';
				$jump_text  = $custom_fg ? $custom_fg : self::contrast_text_color( $jump_color );
			} else {
				// Link: no fill — the foreground/text color is the link color.
				$jump_color = $custom_fg ? $custom_fg : '#c8a951';
				$jump_text  = $jump_color;
			}
		} else {
			$jump_color = self::resolve_jump_color( $color );
			$jump_text  = self::contrast_text_color( $jump_color );
		}

		$jump_style = 'style="--fr-jump-color:' . esc_attr( $jump_color ) . ';--fr-jump-text:' . esc_attr( $jump_text ) . ';"';

		$output  = '<div class="create-featured-review-jump">';
		$output .= '<a class="' . esc_attr( $style_class ) . '" ' . $jump_style . ' href="#mv-creation-' . esc_attr( $card_id ) . '">';
		$output .= esc_html( $label );
		$output .= '</a>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Resolve a jump-color choice to a concrete background/accent hex color.
	 *
	 * - "primary"   -> the publisher's primary theme color.
	 * - "secondary" -> the publisher's secondary theme color (which also drives
	 *                  the star ratings). "ratings" is a legacy alias for this.
	 * - "custom"    -> the publisher's custom background color.
	 *
	 * Falls back to the default star gold when the chosen color is unset.
	 *
	 * @param string $choice One of primary|secondary|custom (or legacy "ratings").
	 * @return string A sanitized #hex color.
	 */
	private static function resolve_jump_color( $choice ) {
		$default = '#c8a951'; // Default star/ratings gold.

		if ( 'primary' === $choice ) {
			$primary = self::sanitize_color( \Mediavine\Settings::get_setting( 'mv_create_color' ) );
			return $primary ? $primary : $default;
		}

		if ( 'custom' === $choice ) {
			$bg = self::sanitize_color( \Mediavine\Settings::get_setting( 'mv_create_featured_review_jump_custom_bg' ) );
			return $bg ? $bg : $default;
		}

		// "secondary" (and legacy "ratings") -> the secondary theme color.
		$secondary = self::sanitize_color( \Mediavine\Settings::get_setting( 'mv_create_secondary_color' ) );
		return $secondary ? $secondary : $default;
	}

	/**
	 * Normalize a stored color value to a valid #hex, or '' if invalid/empty.
	 *
	 * @param string $color The raw stored color.
	 * @return string Sanitized #hex color or empty string.
	 */
	private static function sanitize_color( $color ) {
		if ( empty( $color ) ) {
			return '';
		}
		$hex = sanitize_hex_color( '#' . ltrim( (string) $color, '#' ) );
		return $hex ? $hex : '';
	}

	/**
	 * Pick a readable text color (near-black or white) for a given background.
	 *
	 * Delegates to the shared WCAG relative-luminance contrast helper used
	 * across the card color system.
	 *
	 * @param string $hex A #hex color.
	 * @return string '#1a1a1a' for light backgrounds, '#ffffff' for dark.
	 */
	private static function contrast_text_color( $hex ) {
		return Creations_Views_Colors::contrast_text( $hex, '#ffffff', '#1a1a1a' );
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
		/* translators: %s: star rating value out of 5 */
		$output .= '<span class="create-featured-review-stars" role="img" aria-label="' . esc_attr( sprintf( __( '%s out of 5 stars', 'mediavine-create' ), $rating ) ) . '">';

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
			return self::render_editor_warning( __( 'Please select a Create card.', 'mediavine-create' ) );
		}

		if ( ! Featured_Review::card_exists( $card_id ) ) {
			return self::render_editor_warning( __( 'Card no longer exists.', 'mediavine-create' ) );
		}

		$review = Featured_Review::get_featured_for_card( $card_id );
		if ( ! $review ) {
			return self::render_editor_warning( __( 'No featured review selected for this card.', 'mediavine-create' ) );
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
			'editor_script'   => Admin_Init::blocks_script_handle(),
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
		add_filter( 'the_content', [ __CLASS__, 'maybe_auto_insert' ], 20 );
		add_filter( 'mv_create_auto_output_jtr_shortcode', [ __CLASS__, 'maybe_suppress_jtr' ] );
	}

	/**
	 * Optionally suppress the standalone Jump to Card button when the featured
	 * review — which already carries a jump link — is shown on the same post.
	 * Hooked to the JTR "mv_create_auto_output_jtr_shortcode" filter.
	 *
	 * @param bool $display Whether the JTR button would be output.
	 * @return bool
	 */
	public static function maybe_suppress_jtr( $display ) {
		if ( ! $display ) {
			return $display;
		}
		if ( ! self::get_display_setting( 'mv_create_featured_review_replace_jtr', false ) ) {
			return $display;
		}
		if ( ! self::get_display_setting( 'mv_create_featured_review_show_jump', false ) ) {
			return $display;
		}

		return self::featured_jump_will_show() ? false : $display;
	}

	/**
	 * Whether the featured review's jump link will be shown on the current post,
	 * via a manually-placed block or auto-placement of a card that has a
	 * featured review.
	 *
	 * @return bool
	 */
	private static function featured_jump_will_show() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return false;
		}

		// A manually-placed block always shows (its render is not gated), but
		// its jump link is dropped for card types the Jump button is limited away from.
		if ( function_exists( 'has_block' ) && has_block( 'mv/featured-review', $post_id ) ) {
			$block_content = get_post_field( 'post_content', $post_id );
			if ( ! preg_match_all( '/<!-- wp:mv\/featured-review [^>]*"cardId":"?(\d+)/', $block_content, $block_matches ) ) {
				return true;
			}
			foreach ( array_unique( $block_matches[1] ) as $card_id ) {
				if ( self::jump_allowed_for_card( absint( $card_id ) ) ) {
					return true;
				}
			}

			return false;
		}

		// Auto-placement respects the guides-band gate, so if the review would be
		// gated out we must not suppress the JTR button (or the reader loses both).
		if (
			class_exists( '\Mediavine\Create\GateKeeper' )
			&& ! GateKeeper::can_access( GateKeeper::FEATURE_FEATURED_REVIEW_BLOCK )
		) {
			return false;
		}

		// Otherwise auto-placement must be active.
		$placement = \Mediavine\Settings::get_setting( 'mv_create_featured_review_placement', 'manual' );
		$selector  = \Mediavine\Settings::get_setting( 'mv_create_featured_review_selector' );
		$selector  = is_string( $selector ) ? trim( $selector ) : '';
		if ( ! in_array( $placement, [ 'top', 'middle', 'above-card' ], true ) && '' === $selector ) {
			return false;
		}

		// ...and a card in the post must have a featured review.
		$content = get_post_field( 'post_content', $post_id );
		if ( empty( $content ) || ! preg_match_all( '/\[mv_create[^\]]+key="(\d+)"/', $content, $matches ) ) {
			return false;
		}
		// Auto-placement renders the first card with a featured review.
		foreach ( array_unique( $matches[1] ) as $key ) {
			if ( Featured_Review::get_featured_for_card( absint( $key ) ) ) {
				return self::jump_allowed_for_card( absint( $key ) );
			}
		}

		return false;
	}

	/**
	 * Auto-insert the featured review into post content based on the
	 * "placement" setting. Mirrors the Jump-To-Recipe the_content pattern.
	 *
	 * Runs at priority 20 — after the [mv_create] shortcode has rendered to its
	 * <section id="mv-creation-{id}"> markup — so the card can be located in the
	 * already-rendered content.
	 *
	 * @param string $content The post content.
	 * @return string The (possibly modified) content.
	 */
	public static function maybe_auto_insert( $content ) {
		// Only run on the main, singular, front-end loop. Skip feeds/REST/admin.
		if ( is_admin() || is_feed() ) {
			return $content;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $content;
		}
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Precedence: (1) a manually-placed block always wins, so if one is
		// already in the content, do nothing.
		if ( false !== strpos( $content, 'create-featured-review' ) ) {
			return $content;
		}

		$placement = \Mediavine\Settings::get_setting( 'mv_create_featured_review_placement', 'manual' );
		$selector  = \Mediavine\Settings::get_setting( 'mv_create_featured_review_selector' );
		$selector  = is_string( $selector ) ? trim( $selector ) : '';

		$has_auto     = in_array( $placement, [ 'top', 'middle', 'above-card' ], true );
		$has_selector = ( '' !== $selector );

		// Nothing configured (no automatic location and no selector): nothing to do.
		if ( ! $has_auto && ! $has_selector ) {
			return $content;
		}

		/**
		 * Filter whether to auto-insert the featured review for this request.
		 *
		 * @param bool   $enabled   Whether to auto-insert. Default true.
		 * @param string $placement The configured automatic placement value.
		 */
		if ( ! apply_filters( 'mv_create_auto_output_featured_review', true, $placement ) ) {
			return $content;
		}

		// Respect the guides-band gate for the featured review feature.
		if (
			class_exists( '\Mediavine\Create\GateKeeper' )
			&& ! GateKeeper::can_access( GateKeeper::FEATURE_FEATURED_REVIEW_BLOCK )
		) {
			return $content;
		}

		// Only place when a card in the post actually has a featured review.
		$card_id = self::find_featured_card_in_content( $content );
		if ( ! $card_id ) {
			return $content;
		}

		$block = self::render( [ 'cardId' => $card_id ] );
		if ( empty( $block ) ) {
			return $content;
		}

		// (2) Selector (preferred) cascading to (3) the automatic location.
		if ( $has_selector ) {
			return self::place_with_selector( $content, $block, $card_id, $placement, $selector, $has_auto );
		}

		// No selector: pure automatic placement.
		return self::insert_block_at_placement( $content, $block, $placement, $card_id );
	}

	/**
	 * Find the first Create card in rendered content that has a featured review.
	 *
	 * @param string $content Rendered post content.
	 * @return int The card ID, or 0 if none found.
	 */
	private static function find_featured_card_in_content( $content ) {
		// Matches the card's <section id="mv-creation-{id}"> anchor. The trailing
		// quote in the pattern excludes JTR's "mv-creation-{id}-jtr" helper anchors.
		if ( ! preg_match_all( '/id="mv-creation-(\d+)"/', $content, $matches ) ) {
			return 0;
		}

		foreach ( array_unique( $matches[1] ) as $match ) {
			$card_id = absint( $match );
			if ( $card_id && Featured_Review::get_featured_for_card( $card_id ) ) {
				return $card_id;
			}
		}

		return 0;
	}

	/**
	 * Splice the rendered featured-review block into content at the
	 * configured placement.
	 *
	 * @param string $content   Rendered post content.
	 * @param string $block     Rendered featured-review HTML.
	 * @param string $placement One of top|middle|above-card.
	 * @param int    $card_id   The card the review belongs to.
	 * @return string
	 */
	private static function insert_block_at_placement( $content, $block, $placement, $card_id ) {
		switch ( $placement ) {
			case 'top':
				return self::insert_at_top( $content, $block );

			case 'above-card':
				return self::insert_above_card( $content, $block, $card_id );

			case 'middle':
				return self::insert_in_middle( $content, $block, $card_id );

			default:
				return $content;
		}
	}

	/**
	 * Place the block honoring the selector -> automatic-location cascade.
	 *
	 * A CSS selector can point at elements outside the post body (template
	 * parts, sidebars), which server-side string splicing cannot reach, so the
	 * block is wrapped with an id and an inline script moves it into the target
	 * element once the full page DOM exists (mirroring the public-reviews
	 * selector pattern). If an automatic location is also set, the block is
	 * rendered there as a visible base; the script relocates it to the selector
	 * only when that element exists, otherwise it stays at the automatic
	 * location. With no automatic location, the block is hidden and shown only
	 * if the selector matches.
	 *
	 * @param string $content   Rendered post content.
	 * @param string $block     Rendered featured-review HTML.
	 * @param int    $card_id   The card ID (used for a unique wrapper id).
	 * @param string $placement The automatic placement value.
	 * @param string $selector  The CSS selector.
	 * @param bool   $has_auto  Whether an automatic fallback location is set.
	 * @return string
	 */
	private static function place_with_selector( $content, $block, $card_id, $placement, $selector, $has_auto ) {
		$id = 'create-featured-review-relocate-' . absint( $card_id );

		// Hidden when there is no automatic fallback (selector-only).
		$hidden  = $has_auto ? '' : ' hidden';
		$wrapped = '<div id="' . esc_attr( $id ) . '" class="create-featured-review-portal"' . $hidden . '>' . $block . '</div>';

		if ( $has_auto ) {
			// Visible base at the automatic location; the script moves it to the
			// selector when present, otherwise it stays here.
			$content = self::insert_block_at_placement( $content, $wrapped, $placement, $card_id );
		} else {
			// Selector-only: hidden at the end, shown only if the selector matches.
			$content .= $wrapped;
		}

		return $content . self::relocate_script( $id, $selector );
	}

	/**
	 * Build the inline script that relocates the wrapped block to a selector.
	 *
	 * On a match the wrapper is moved into the target and revealed. On no match
	 * or an invalid selector the wrapper is left in place: visible if it has an
	 * automatic-location base, hidden (i.e. not shown) if it does not.
	 * wp_json_encode safely embeds the selector; data-noptimize keeps JS
	 * optimizers from deferring/combining the script.
	 *
	 * @param string $id       The wrapper element id.
	 * @param string $selector The CSS selector.
	 * @return string
	 */
	private static function relocate_script( $id, $selector ) {
		$script  = '<script data-noptimize="1">(function(){';
		$script .= 'var w=document.getElementById(' . wp_json_encode( $id ) . ');';
		$script .= 'if(!w){return;}';
		$script .= 'var t;';
		$script .= 'try{t=document.querySelector(' . wp_json_encode( $selector ) . ');}catch(e){return;}';
		$script .= 'if(t){t.appendChild(w);w.removeAttribute("hidden");}';
		$script .= '})();</script>';

		return $script;
	}

	/**
	 * Insert the block at the top of the content, but below the Jump to Card
	 * button when one has been prepended (so the two don't stack).
	 *
	 * @param string $content Rendered post content.
	 * @param string $block   Markup to insert.
	 * @return string
	 */
	private static function insert_at_top( $content, $block ) {
		// The JTR button renders as <div class="mv-create-jtr-wrapper">...</div>
		// prepended to the content. Place the review just after it.
		$pos = strpos( $content, 'mv-create-jtr-wrapper' );
		if ( false !== $pos ) {
			$close = strpos( $content, '</div>', $pos );
			if ( false !== $close ) {
				$after = $close + strlen( '</div>' );
				return substr( $content, 0, $after ) . $block . substr( $content, $after );
			}
		}

		return $block . $content;
	}

	/**
	 * Insert the block immediately above the card.
	 *
	 * When Jump-To-Recipe is enabled the card is wrapped in a hint wrapper
	 * (#mv-creation-{id}-jtr-hint-wrapper); prefer to sit above that wrapper so
	 * the review is a clean sibling above the whole card. Otherwise sit directly
	 * above the card's <section>.
	 *
	 * @param string $content Rendered post content.
	 * @param string $block   Markup to insert.
	 * @param int    $card_id The card ID.
	 * @return string
	 */
	private static function insert_above_card( $content, $block, $card_id ) {
		$targets = [
			'id="mv-creation-' . $card_id . '-jtr-hint-wrapper"',
			'id="mv-creation-' . $card_id . '"',
		];

		foreach ( $targets as $needle ) {
			$pos = strpos( $content, $needle );
			if ( false === $pos ) {
				continue;
			}
			// Back up to the start of the tag that carries the id.
			$tag_start = strrpos( substr( $content, 0, $pos ), '<' );
			if ( false === $tag_start ) {
				continue;
			}
			return substr( $content, 0, $tag_start ) . $block . substr( $content, $tag_start );
		}

		// Card not located; fall back to the top of the content.
		return $block . $content;
	}

	/**
	 * Insert markup near the middle of the surrounding prose, on a paragraph
	 * boundary, without ever splicing inside the card's <section> markup.
	 *
	 * @param string $content Rendered post content.
	 * @param string $block   Markup to insert.
	 * @param int    $card_id The card ID (used to locate and avoid the card).
	 * @return string
	 */
	private static function insert_in_middle( $content, $block, $card_id ) {
		// Locate the card section so we never land inside it.
		$section_start = false;
		$section_end   = false;
		$id_pos        = strpos( $content, 'id="mv-creation-' . $card_id . '"' );
		if ( false !== $id_pos ) {
			$section_start = strrpos( substr( $content, 0, $id_pos ), '<section' );
			$close         = strpos( $content, '</section>', $id_pos );
			if ( false !== $close ) {
				$section_end = $close + strlen( '</section>' );
			}
		}

		// Collect candidate insertion points just after each closing </p> that
		// fall outside the card's <section> (so we never splice into the card).
		$candidates = [];
		$offset     = 0;
		while ( false !== ( $p = strpos( $content, '</p>', $offset ) ) ) {
			$point  = $p + strlen( '</p>' );
			$offset = $point;
			if ( false !== $section_start && false !== $section_end && $point > $section_start && $point < $section_end ) {
				continue;
			}
			$candidates[] = $point;
		}

		// Insert at the middle *paragraph boundary* of the surrounding prose.
		// Counting paragraphs — rather than using the byte midpoint of $content —
		// keeps the card's large, non-prose markup (images, ingredient and
		// instruction lists, JSON-LD schema) from dragging the target down next
		// to the card, which would otherwise make "middle" behave like
		// "above-card" for a typical recipe post.
		if ( ! empty( $candidates ) ) {
			$mid  = (int) floor( ( count( $candidates ) - 1 ) / 2 );
			$best = $candidates[ $mid ];
			return substr( $content, 0, $best ) . $block . substr( $content, $best );
		}

		// No usable paragraph boundary outside the card: place above the card,
		// or fall back to the top of the content.
		if ( false !== $section_start ) {
			return substr( $content, 0, $section_start ) . $block . substr( $content, $section_start );
		}

		return $block . $content;
	}
}
