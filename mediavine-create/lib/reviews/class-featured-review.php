<?php
namespace Mediavine\Create;

/**
 * Featured Review functionality
 *
 * Handles marking reviews as featured, retrieving featured reviews,
 * and formatting reviewer names for display.
 */
class Featured_Review extends Plugin {

	/**
	 * Mark a review as featured.
	 *
	 * Only one review per card can be featured at a time.
	 * Marking a new review as featured will automatically unfeature
	 * any previously featured review for the same card.
	 *
	 * @param int $review_id The ID of the review to feature.
	 * @return bool True on success, false on failure.
	 */
	public static function mark_as_featured( $review_id ) {
		if ( ! $review_id || ! is_numeric( $review_id ) ) {
			return false;
		}

		$review = self::$models_v2->mv_reviews->select_one( (int) $review_id );
		if ( ! $review ) {
			return false;
		}

		// Unfeature any currently featured review for this card
		self::unfeature_all_for_card( $review->creation );

		// Mark this review as featured
		$updated = self::$models_v2->mv_reviews->update( [
			'id'          => $review_id,
			'is_featured' => 1,
		] );

		return ! empty( $updated );
	}

	/**
	 * Unfeature a specific review.
	 *
	 * @param int $review_id The ID of the review to unfeature.
	 * @return bool True on success, false on failure.
	 */
	public static function unfeature_review( $review_id ) {
		if ( ! $review_id || ! is_numeric( $review_id ) ) {
			return false;
		}

		$updated = self::$models_v2->mv_reviews->update( [
			'id'          => $review_id,
			'is_featured' => 0,
		] );

		return ! empty( $updated );
	}

	/**
	 * Unfeature all reviews for a specific card.
	 *
	 * @param int $creation_id The ID of the Create card.
	 * @return bool True on success, false on failure.
	 */
	public static function unfeature_all_for_card( $creation_id ) {
		global $wpdb;

		if ( ! $creation_id || ! is_numeric( $creation_id ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'mv_reviews';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `$table` SET is_featured = 0 WHERE creation = %d AND is_featured = 1",
				$creation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result !== false;
	}

	/**
	 * Get the featured review for a specific card.
	 *
	 * @param int $creation_id The ID of the Create card.
	 * @return object|null The featured review object, or null if none exists.
	 */
	public static function get_featured_for_card( $creation_id ) {
		if ( ! $creation_id || ! is_numeric( $creation_id ) ) {
			return null;
		}

		$reviews = self::$models_v2->mv_reviews->find( [
			'where' => [
				'creation'    => (int) $creation_id,
				'is_featured' => 1,
			],
			'limit' => 1,
		] );

		if ( empty( $reviews ) ) {
			return null;
		}

		return $reviews[0];
	}

	/**
	 * Get the previously featured review ID for a card (if any).
	 *
	 * Used to return previous_featured_id in API responses.
	 *
	 * @param int $creation_id The ID of the Create card.
	 * @return int|null The ID of the previously featured review, or null.
	 */
	public static function get_current_featured_id( $creation_id ) {
		$featured = self::get_featured_for_card( $creation_id );
		return $featured ? $featured->id : null;
	}

	/**
	 * Format reviewer name as "First L." format.
	 *
	 * Examples:
	 * - "Sarah Miller" -> "Sarah M."
	 * - "John" -> "John"
	 * - "Mary Jane Watson" -> "Mary Jane W."
	 *
	 * @param string $name The full reviewer name.
	 * @return string The formatted name.
	 */
	public static function format_reviewer_name( $name ) {
		if ( empty( $name ) ) {
			return '';
		}

		$name  = trim( wp_kses( $name, [] ) );
		$parts = preg_split( '/\s+/', $name );

		if ( count( $parts ) < 2 ) {
			return $name;
		}

		// Get the last name initial
		$last_part    = array_pop( $parts );
		$last_initial = mb_substr( $last_part, 0, 1 );
		$last_initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $last_initial ) : strtoupper( $last_initial );

		// Join remaining parts (first name, middle names) with the last initial
		$first_parts = implode( ' ', $parts );

		return $first_parts . ' ' . $last_initial . '.';
	}

	/**
	 * Check if a review exists.
	 *
	 * @param int $review_id The ID of the review.
	 * @return bool True if exists, false otherwise.
	 */
	public static function review_exists( $review_id ) {
		if ( ! $review_id || ! is_numeric( $review_id ) ) {
			return false;
		}

		$review = self::$models_v2->mv_reviews->select_one( (int) $review_id );
		return ! empty( $review );
	}

	/**
	 * Check if a card (creation) exists.
	 *
	 * @param int $creation_id The ID of the Create card.
	 * @return bool True if exists, false otherwise.
	 */
	public static function card_exists( $creation_id ) {
		if ( ! $creation_id || ! is_numeric( $creation_id ) ) {
			return false;
		}

		$card = self::$models_v2->mv_creations->select_one( (int) $creation_id );
		return ! empty( $card );
	}

	/**
	 * Initialize the Featured Review functionality.
	 */
	public function init() {
		// Register settings for featured review display options
		add_filter( 'mv_create_settings', [ $this, 'register_settings' ] );
	}

	/**
	 * Register featured review display settings.
	 *
	 * @param array $settings Current settings array.
	 * @return array Modified settings array.
	 */
	public function register_settings( $settings ) {
		$settings[] = [
			'slug'  => 'mv_create_featured_review_placement',
			'value' => 'manual',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 58,
			'data'  => [
				'type'          => 'select',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Automatic Placement', 'mediavine-create' ),
				'instructions'  => __( 'Where to automatically place a featured review when a card in the post has one chosen (nothing is added otherwise). Order of precedence: a Featured Review block you place yourself always wins; then the Target Element below, if it is on the page; then this automatic location. Choose "None" to rely only on a manual block or the Target Element.', 'mediavine-create' ),
				'default_label' => __( 'None', 'mediavine-create' ),
				'options'       => [
					[
						'label' => __( 'None (manual block or target element only)', 'mediavine-create' ),
						'value' => 'manual',
					],
					[
						'label' => __( 'Top of post', 'mediavine-create' ),
						'value' => 'top',
					],
					[
						'label' => __( 'Middle of post', 'mediavine-create' ),
						'value' => 'middle',
					],
					[
						'label' => __( 'Right above the card', 'mediavine-create' ),
						'value' => 'above-card',
					],
				],
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_selector',
			'value' => '',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 59,
			'data'  => [
				'type'         => 'text',
				'subgroup'     => 'featured_reviews',
				'gated'        => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'        => __( 'Target Element', 'mediavine-create' ),
				'instructions' => __( 'Optional. A CSS selector for a preferred location — an element you have added to your post or template (for example, #featured-review or .featured-review-slot). When that element is on the page, the featured review is placed inside it, overriding the automatic placement above. A Featured Review block you place yourself still takes precedence.', 'mediavine-create' ),
				'placeholder'  => __( 'e.g. #featured-review', 'mediavine-create' ),
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_rating',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 60,
			'data'  => [
				'type'          => 'checkbox',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Featured Reviews: Show Rating', 'mediavine-create' ),
				'instructions'  => __( 'Display the star rating in featured review blocks.', 'mediavine-create' ),
				'default_label' => __( 'Enabled', 'mediavine-create' ),
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_text',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 62,
			'data'  => [
				'type'          => 'checkbox',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Featured Reviews: Show Review Text', 'mediavine-create' ),
				'instructions'  => __( 'Display the review content in featured review blocks.', 'mediavine-create' ),
				'default_label' => __( 'Enabled', 'mediavine-create' ),
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_name',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 64,
			'data'  => [
				'type'          => 'checkbox',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Featured Reviews: Show Reviewer Name', 'mediavine-create' ),
				'instructions'  => __( 'Display the reviewer\'s name in featured review blocks.', 'mediavine-create' ),
				'default_label' => __( 'Enabled', 'mediavine-create' ),
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_jump',
			'value' => false,
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 66,
			'data'  => [
				'type'          => 'checkbox',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Show "Jump to Card" Link', 'mediavine-create' ),
				'instructions'  => __( 'Add a link or button to the featured review that jumps the reader down to the recipe card.', 'mediavine-create' ),
				'default_label' => __( 'Disabled', 'mediavine-create' ),
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_style',
			'value' => 'link',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 68,
			'data'  => [
				'type'          => 'select',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Jump Style', 'mediavine-create' ),
				'instructions'  => __( 'Display the "jump to card" control as a text link or a button.', 'mediavine-create' ),
				'default_label' => __( 'Link', 'mediavine-create' ),
				'dependent_on'  => 'mv_create_featured_review_show_jump',
				'options'       => [
					[
						'label' => __( 'Link', 'mediavine-create' ),
						'value' => 'link',
					],
					[
						'label' => __( 'Button', 'mediavine-create' ),
						'value' => 'button',
					],
				],
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_color',
			'value' => 'secondary',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 69,
			'data'  => [
				'type'          => 'select',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Jump Color', 'mediavine-create' ),
				'instructions'  => __( 'Color for the "jump to card" link or button.', 'mediavine-create' ),
				'default_label' => __( 'Secondary (matches ratings)', 'mediavine-create' ),
				'dependent_on'  => 'mv_create_featured_review_show_jump',
				'options'       => [
					[
						'label' => __( 'Theme primary color', 'mediavine-create' ),
						'value' => 'primary',
					],
					[
						'label' => __( 'Theme secondary color (matches ratings)', 'mediavine-create' ),
						'value' => 'secondary',
					],
					[
						'label' => __( 'Custom colors', 'mediavine-create' ),
						'value' => 'custom',
					],
				],
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_custom_bg',
			'value' => '#c8a951',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 70,
			'data'  => [
				'type'          => 'text',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Custom Jump Background', 'mediavine-create' ),
				'instructions'  => __( 'Background color for the jump button when "Custom colors" is selected. (Buttons only — links have no fill.)', 'mediavine-create' ),
				'default_label' => '#c8a951',
				'dependent_on'  => 'mv_create_featured_review_show_jump',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_custom_fg',
			'value' => '#ffffff',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 71,
			'data'  => [
				'type'          => 'text',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Custom Jump Text', 'mediavine-create' ),
				'instructions'  => __( 'Text color for the jump link or button when "Custom colors" is selected.', 'mediavine-create' ),
				'default_label' => '#ffffff',
				'dependent_on'  => 'mv_create_featured_review_show_jump',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_label',
			'value' => __( 'Jump to Recipe', 'mediavine-create' ),
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 72,
			'data'  => [
				'type'          => 'text',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Jump to Recipe Label', 'mediavine-create' ),
				'instructions'  => __( 'The text shown on the "jump to card" control for recipe cards.', 'mediavine-create' ),
				'default_label' => __( 'Jump to Recipe', 'mediavine-create' ),
				'dependent_on'  => 'mv_create_featured_review_show_jump',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_jump_howto_label',
			'value' => __( 'Jump to How-To', 'mediavine-create' ),
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 73,
			'data'  => [
				'type'          => 'text',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Jump to How-To Label', 'mediavine-create' ),
				'instructions'  => __( 'The text shown on the "jump to card" control for how-to cards.', 'mediavine-create' ),
				'default_label' => __( 'Jump to How-To', 'mediavine-create' ),
				'dependent_on'  => 'mv_create_featured_review_show_jump',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_replace_jtr',
			'value' => false,
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 74,
			'data'  => [
				'type'          => 'checkbox',
				'subgroup'      => 'featured_reviews',
				'gated'         => GateKeeper::FEATURE_REVIEW_FEATURED,
				'label'         => __( 'Replace Jump to Recipe Button', 'mediavine-create' ),
				'instructions'  => __( 'When enabled, hide the standalone Jump to Recipe button on posts where the featured review is shown with its jump link — the featured review already links to the card, so the separate button is redundant.', 'mediavine-create' ),
				'default_label' => __( 'Disabled', 'mediavine-create' ),
				'dependent_on'  => 'mv_create_featured_review_show_jump',
			],
		];

		return $settings;
	}
}
