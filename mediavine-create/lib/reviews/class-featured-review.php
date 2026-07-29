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
		$last_initial = mb_strtoupper( mb_substr( $last_part, 0, 1 ) );

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
			'slug'  => 'mv_create_featured_review_show_rating',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 60,
			'data'  => [
				'type'         => 'checkbox',
				'label'        => __( 'Featured Reviews: Show Rating', 'mediavine-create' ),
				'instructions' => __( 'Display the star rating in featured review blocks.', 'mediavine-create' ),
				'default'      => 'Enabled',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_text',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 62,
			'data'  => [
				'type'         => 'checkbox',
				'label'        => __( 'Featured Reviews: Show Review Text', 'mediavine-create' ),
				'instructions' => __( 'Display the review content in featured review blocks.', 'mediavine-create' ),
				'default'      => 'Enabled',
			],
		];

		$settings[] = [
			'slug'  => 'mv_create_featured_review_show_name',
			'value' => 'true',
			'group' => Plugin::$settings_group . '_reader_experience',
			'order' => 64,
			'data'  => [
				'type'         => 'checkbox',
				'label'        => __( 'Featured Reviews: Show Reviewer Name', 'mediavine-create' ),
				'instructions' => __( 'Display the reviewer\'s name in featured review blocks.', 'mediavine-create' ),
				'default'      => 'Enabled',
			],
		];

		return $settings;
	}
}
