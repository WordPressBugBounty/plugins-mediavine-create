<?php
namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * The single source of truth for what happens when a reader rates a card.
 *
 * Every consumer asks this class instead of doing its own threshold math: the
 * REST handler (does this rating save without a review?), the card script
 * localization (when should the form open?), and the admin preview. Phase 2
 * (CRE-326 follow-up) adds a WordPress-comments mode that plugs in here too.
 */
class Rating_Policy {

	/** Every rating saves on click; no review form. */
	const MODE_RATINGS = 'ratings';

	/** A review form follows the rating; low ratings need a written review. */
	const MODE_REVIEWS = 'reviews';

	/** Reserved for the WordPress-comments mode (not selectable yet). */
	const MODE_COMMENTS = 'comments';

	const SETTING_MODE     = 'mv_create_review_mode';
	const SETTING_REQUIRED = 'mv_create_review_required_at_or_below';

	/** Ratings of 3★ or lower need a review (the long-standing "below 4★" rule). */
	const DEFAULT_REQUIRED = 3;

	/** Retired: absorbed by SETTING_REQUIRED (see migrate_legacy_settings()). */
	const LEGACY_ANONYMOUS_RATINGS = 'mv_create_enable_anonymous_ratings';

	/**
	 * Modes a publisher can currently pick.
	 *
	 * @return string[]
	 */
	public static function selectable_modes() {
		return [ self::MODE_REVIEWS, self::MODE_RATINGS ];
	}

	/**
	 * The active feedback mode.
	 *
	 * Anything that isn't selectable yet (including 'comments') falls back to
	 * reviews, the long-standing behavior.
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = (string) Settings::get_setting( self::SETTING_MODE, self::MODE_REVIEWS );
		$mode = apply_filters( 'mv_create_review_mode', $mode );

		return in_array( $mode, self::selectable_modes(), true ) ? $mode : self::MODE_REVIEWS;
	}

	/**
	 * Whether a review form follows a rating.
	 *
	 * @return bool
	 */
	public static function form_enabled() {
		return self::MODE_REVIEWS === self::mode();
	}

	/**
	 * Highest star rating that still needs a written review (1–5; 5 = every rating).
	 *
	 * @return int
	 */
	public static function required_at_or_below() {
		return self::sanitize_required( Settings::get_setting( self::SETTING_REQUIRED, self::DEFAULT_REQUIRED ) );
	}

	/**
	 * Clamp a stored threshold to 1–5, falling back to the default.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_required( $value ) {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_REQUIRED;
		}

		return max( 1, min( 5, (int) $value ) );
	}

	/**
	 * Lowest rating that saves without a review (the legacy "submit threshold").
	 *
	 * Ratings-only mode accepts every rating. Otherwise anything above the
	 * required level saves, e.g. required 3 → 3.5 (half-star ratings above 3 save).
	 * Still filterable through `mv_create_ratings_submit_threshold` so existing
	 * snippets (the published support guide sets it to 1) keep working.
	 *
	 * @return float
	 */
	public static function submit_threshold() {
		$default = self::form_enabled() ? self::required_at_or_below() + 0.5 : 0.5;

		return (float) apply_filters( 'mv_create_ratings_submit_threshold', $default );
	}

	/**
	 * Lowest rating that skips the review form entirely (the legacy "prompt threshold").
	 *
	 * In reviews mode the form always opens (optional above the threshold), so
	 * the default is above 5★. Filterable through `mv_create_ratings_prompt_threshold`.
	 *
	 * @return float
	 */
	public static function prompt_threshold() {
		$default = self::form_enabled() ? 5.5 : 0.5;

		return (float) apply_filters( 'mv_create_ratings_prompt_threshold', $default );
	}

	/**
	 * Whether a rating needs a written review before it counts.
	 *
	 * @param float $rating Star rating (0.5–5).
	 * @return bool
	 */
	public static function requires_review( $rating ) {
		// A prompt threshold below the submit threshold wins, as it always has:
		// a rating that never sees the form can't be made to wait for one.
		$threshold = min( self::submit_threshold(), self::prompt_threshold() );

		return $rating < $threshold;
	}

	/**
	 * The policy as the card script consumes it.
	 *
	 * @return array
	 */
	public static function client_settings() {
		return [
			'mode'              => self::mode(),
			'requiredAtOrBelow' => self::required_at_or_below(),
		];
	}

	/**
	 * The policy as a JSON attribute for the Create Studio embed script.
	 *
	 * Interactive Mode can run on create.studio (Free+), where the page's
	 * MV_CREATE_SETTINGS isn't available, so the policy travels with the embed.
	 *
	 * @return string
	 */
	public static function embed_attribute() {
		return (string) wp_json_encode( self::client_settings() );
	}

	/**
	 * Seed the threshold from the retired "Allow Anonymous Ratings" toggle.
	 *
	 * Runs before settings are upserted (Plugin::update_settings), and before
	 * Settings drops the retired row. Only acts the first time: once the new
	 * setting exists, the stored value always wins.
	 * - anonymous ratings on  → 3 (4–5★ saved on click, as before)
	 * - anonymous ratings off → 5 (every rating waits for a review, as the card did)
	 * - no legacy row (fresh install) → the registered default (3)
	 *
	 * Mapping "off" to 5 relies on Create Studio's Interactive Mode following
	 * the site's policy (CS-78) rather than posting bare 4–5★ ratings.
	 *
	 * @param array $settings Registered settings about to be upserted.
	 * @return array
	 */
	public static function migrate_legacy_settings( array $settings ) {
		if ( null !== Settings::get_settings( self::SETTING_REQUIRED ) ) {
			return $settings;
		}

		$legacy = Settings::get_settings( self::LEGACY_ANONYMOUS_RATINGS );
		if ( ! is_object( $legacy ) ) {
			return $settings;
		}

		$required = wp_validate_boolean( $legacy->value ) ? self::DEFAULT_REQUIRED : 5;

		foreach ( $settings as $index => $setting ) {
			if ( isset( $setting['slug'] ) && self::SETTING_REQUIRED === $setting['slug'] ) {
				$settings[ $index ]['value']              = (string) $required;
				$settings[ $index ]['force_update_value'] = true;
			}
		}

		return $settings;
	}
}
