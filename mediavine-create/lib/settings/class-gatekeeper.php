<?php
/**
 * GateKeeper - Feature Access Control
 *
 * Manages feature gating based on subscription tiers. Controls access to premium
 * features for users without an active Pro or Ad-Supported subscription.
 *
 * @package Mediavine\Create
 * @since 2.0.0
 */

namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * GateKeeper class for managing feature access based on subscription tiers.
 *
 * This class handles:
 * - Subscription tier management and caching
 * - Feature gating logic
 * - Subscription sync with Create Studio
 * - Upgrade URL generation
 */
class GateKeeper {

	/**
	 * Subscription tier constant for free users.
	 */
	const TIER_FREE = 'free';

	/**
	 * Subscription tier constant for pro users.
	 */
	const TIER_PRO = 'pro';

	/**
	 * Subscription tier constant for free-plus (ad-supported) users.
	 */
	const TIER_FREE_PLUS = 'free-plus';

	/**
	 * Feature identifier for Editorial theme.
	 */
	const FEATURE_THEME_EDITORIAL = 'theme_editorial';

	/**
	 * Feature identifier for Modern Elegant theme.
	 */
	const FEATURE_THEME_MODERN = 'theme_modern';

	/**
	 * Feature identifier for editing user reviews.
	 */
	const FEATURE_REVIEW_EDIT = 'review_edit';

	/**
	 * Feature identifier for responding to reviews.
	 */
	const FEATURE_REVIEW_RESPOND = 'review_respond';

	/**
	 * Feature identifier for marking reviews as featured.
	 */
	const FEATURE_REVIEW_FEATURED = 'review_featured';

	/**
	 * Feature identifier for featured review Gutenberg block.
	 */
	const FEATURE_FEATURED_REVIEW_BLOCK = 'featured_review_block';

	/**
	 * Feature identifier for interactive mode.
	 */
	const FEATURE_INTERACTIVE_MODE = 'interactive_mode';

	/**
	 * Feature identifier for servings adjustment.
	 */
	const FEATURE_SERVINGS_ADJUSTMENT = 'servings_adjustment';

	/**
	 * Feature identifier for checklists.
	 */
	const FEATURE_CHECKLISTS = 'checklists';

	/**
	 * Feature identifier for unit conversion.
	 */
	const FEATURE_UNIT_CONVERSION = 'unit_conversion';

	/**
	 * Feature identifier for bulk importing list items.
	 */
	const FEATURE_LIST_BULK_IMPORT = 'list_bulk_import';

	/**
	 * Feature identifier for custom CSS.
	 */
	const FEATURE_CUSTOM_CSS = 'custom_css';

	/**
	 * TTL for subscription cache in seconds (1 hour).
	 */
	const SUBSCRIPTION_TTL = 3600;

	/**
	 * Setting slug for subscription tier.
	 */
	const SETTING_SUBSCRIPTION_TIER = 'mv_create_subscription_tier';

	/**
	 * Setting slug for subscription synced timestamp.
	 */
	const SETTING_SUBSCRIPTION_SYNCED_AT = 'mv_create_subscription_synced_at';

	/**
	 * Setting slug for active paid subscription count (multi-site discount).
	 */
	const SETTING_ACTIVE_PAID_COUNT = 'mv_create_active_paid_count';

	/**
	 * List of all gated features that require Pro or higher tier.
	 *
	 * @var array
	 */
	private static $gated_features = [
		self::FEATURE_THEME_EDITORIAL,
		self::FEATURE_THEME_MODERN,
		self::FEATURE_REVIEW_EDIT,
		self::FEATURE_REVIEW_RESPOND,
		self::FEATURE_REVIEW_FEATURED,
		self::FEATURE_FEATURED_REVIEW_BLOCK,
		self::FEATURE_INTERACTIVE_MODE,
		self::FEATURE_SERVINGS_ADJUSTMENT,
		self::FEATURE_CHECKLISTS,
		self::FEATURE_UNIT_CONVERSION,
		self::FEATURE_LIST_BULK_IMPORT,
		self::FEATURE_CUSTOM_CSS,
	];

	/**
	 * Features that require Pro tier specifically (not available on Free+).
	 *
	 * @var array
	 */
	private static $pro_only_features = [];

	/**
	 * Feature fallback values when feature is gated.
	 *
	 * @var array
	 */
	private static $feature_fallbacks = [
		self::FEATURE_THEME_EDITORIAL => 'big-image',
		self::FEATURE_THEME_MODERN    => 'big-image',
	];

	/**
	 * Settings that must be reset to their defaults on downgrade.
	 *
	 * Maps setting slug (without the settings_group prefix) to the default value.
	 *
	 * @var array
	 */
	private static $gated_setting_defaults = [
		// Themes — gated values are 'editorial' and 'modern'; fallback handled separately via card_style check.
		// Products
		'products_display_mode'  => 'gallery',
		'products_section_title' => 'Recommended Products',
		'products_position'      => 'after_video',
		// Toggle features — disable on downgrade
		'enable_checklists'              => '',
		'enable_interactive_mode'        => '',
		'interactive_mode_button_text'   => '',
		'enable_unit_conversion'         => '',
		'enable_servings_adjustment'     => '',
		'custom_css'                     => '',
	];

	/**
	 * Initialize the GateKeeper hooks.
	 *
	 * @return void
	 */
	/**
	 * Flag to prevent re-syncing settings that were just received from Studio.
	 *
	 * @var bool
	 */
	public static $syncing_from_studio = false;

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_sync_subscription' ] );
		add_action( 'mv_create_setting_updated_mv_create_enable_interactive_mode', [ __CLASS__, 'sync_interactive_mode' ] );
		add_action( 'mv_create_setting_updated_mv_create_enable_interactive_mode', [ __CLASS__, 'maybe_disable_hands_free_mode' ] );
		add_action( 'mv_create_setting_updated_mv_create_interactive_mode_button_text', [ __CLASS__, 'sync_interactive_mode_button_text' ] );
		add_action( 'mv_create_setting_updated_mv_create_interactive_mode_cta_variant', [ __CLASS__, 'sync_interactive_mode_cta_variant' ] );
		add_action( 'mv_create_setting_updated_mv_create_interactive_mode_cta_title', [ __CLASS__, 'sync_interactive_mode_cta_title' ] );
		add_action( 'mv_create_setting_updated_mv_create_interactive_mode_cta_subtitle', [ __CLASS__, 'sync_interactive_mode_cta_subtitle' ] );
	}

	/**
	 * Sync the interactive mode setting to Create Studio.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_interactive_mode( $setting ) {
		if ( self::$syncing_from_studio ) {
			return;
		}

		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		$site_id = Create_Studio_Client::get_site_id();
		if ( empty( $site_id ) ) {
			return;
		}

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ 'interactive_mode_enabled' => ! empty( $setting->value ) ]
		);
	}

	/**
	 * Disable hands-free mode when interactive mode is enabled, since they conflict.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function maybe_disable_hands_free_mode( $setting ) {
		if ( ! empty( $setting->value ) ) {
			Settings::update_setting( 'mv_create_enable_hands_free_mode', false );
		}
	}

	/**
	 * Sync the interactive mode button text to Create Studio.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_interactive_mode_button_text( $setting ) {
		if ( self::$syncing_from_studio ) {
			return;
		}

		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		$site_id = Create_Studio_Client::get_site_id();
		if ( empty( $site_id ) ) {
			return;
		}

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ 'interactive_mode_button_text' => sanitize_text_field( $setting->value ) ]
		);
	}

	/**
	 * Sync the interactive mode CTA variant to Create Studio.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_interactive_mode_cta_variant( $setting ) {
		if ( self::$syncing_from_studio ) {
			return;
		}

		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		$site_id = Create_Studio_Client::get_site_id();
		if ( empty( $site_id ) ) {
			return;
		}

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ 'interactive_mode_cta_variant' => sanitize_text_field( $setting->value ) ]
		);
	}

	/**
	 * Sync the interactive mode CTA title to Create Studio.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_interactive_mode_cta_title( $setting ) {
		if ( self::$syncing_from_studio ) {
			return;
		}

		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		$site_id = Create_Studio_Client::get_site_id();
		if ( empty( $site_id ) ) {
			return;
		}

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ 'interactive_mode_cta_title' => sanitize_text_field( $setting->value ) ]
		);
	}

	/**
	 * Sync the interactive mode CTA subtitle to Create Studio.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_interactive_mode_cta_subtitle( $setting ) {
		if ( self::$syncing_from_studio ) {
			return;
		}

		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		$site_id = Create_Studio_Client::get_site_id();
		if ( empty( $site_id ) ) {
			return;
		}

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ 'interactive_mode_cta_subtitle' => sanitize_text_field( $setting->value ) ]
		);
	}

	/**
	 * Check if subscription needs refresh and sync if necessary.
	 *
	 * This is called on admin_init to ensure subscription data stays fresh.
	 *
	 * @return void
	 */
	public static function maybe_sync_subscription() {
		// Only sync if site is connected to Create Studio.
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return;
		}

		// Only sync if TTL has expired.
		if ( ! self::needs_subscription_refresh() ) {
			return;
		}

		// Perform the sync.
		self::sync_subscription();
	}

	/**
	 * Get the current subscription tier.
	 *
	 * @return string The subscription tier ('free', 'pro', or 'free-plus').
	 */
	public static function get_subscription_tier() {
		$tier = Settings::get_setting( self::SETTING_SUBSCRIPTION_TIER );

		if ( empty( $tier ) ) {
			return self::TIER_FREE;
		}

		// Backward compatibility: treat legacy 'ad_supported' as 'free-plus'.
		if ( 'ad_supported' === $tier ) {
			return self::TIER_FREE_PLUS;
		}

		// Validate tier is a known value.
		$valid_tiers = [ self::TIER_FREE, self::TIER_PRO, self::TIER_FREE_PLUS ];
		if ( ! in_array( $tier, $valid_tiers, true ) ) {
			return self::TIER_FREE;
		}

		return $tier;
	}

	/**
	 * Check if subscription cache needs refresh.
	 *
	 * Returns true if the subscription data has expired based on TTL.
	 *
	 * @return bool True if refresh is needed.
	 */
	public static function needs_subscription_refresh() {
		$synced_at = Settings::get_setting( self::SETTING_SUBSCRIPTION_SYNCED_AT );

		// If never synced, refresh is needed.
		if ( empty( $synced_at ) ) {
			return true;
		}

		$synced_timestamp = strtotime( $synced_at );

		// If invalid timestamp, refresh is needed.
		if ( false === $synced_timestamp ) {
			return true;
		}

		$expiration_time = $synced_timestamp + self::SUBSCRIPTION_TTL;

		return time() >= $expiration_time;
	}

	/**
	 * Sync subscription status from Create Studio.
	 *
	 * Calls the Create Studio API to get the current subscription tier
	 * and stores it locally with a timestamp.
	 *
	 * @return bool True on successful sync, false on failure.
	 */
	public static function sync_subscription() {
		// Use site-based status check instead of user-based endpoint.
		$status = Create_Studio_Client::check_site_status();

		// Handle errors - fail open by keeping cached tier.
		if ( false === $status || empty( $status['connected'] ) ) {
			return false;
		}

		// Extract subscription tier from response.
		$subscription_tier = isset( $status['subscription_tier'] ) ? $status['subscription_tier'] : self::TIER_FREE;

		// Backward compatibility: treat legacy 'ad_supported' as 'free-plus'.
		if ( 'ad_supported' === $subscription_tier ) {
			$subscription_tier = self::TIER_FREE_PLUS;
		}

		// Validate the tier value.
		$valid_tiers = [ self::TIER_FREE, self::TIER_PRO, self::TIER_FREE_PLUS ];
		if ( ! in_array( $subscription_tier, $valid_tiers, true ) ) {
			$subscription_tier = self::TIER_FREE;
		}

		// Store the subscription tier.
		self::update_subscription_setting( self::SETTING_SUBSCRIPTION_TIER, $subscription_tier );

		// Store active paid subscription count (for multi-site discount messaging).
		$active_paid_count = isset( $status['active_paid_count'] ) ? (int) $status['active_paid_count'] : 0;
		self::update_subscription_setting( self::SETTING_ACTIVE_PAID_COUNT, $active_paid_count );

		// Store the sync timestamp in ISO 8601 format.
		$synced_at = gmdate( 'c' );
		self::update_subscription_setting( self::SETTING_SUBSCRIPTION_SYNCED_AT, $synced_at );

		// If downgrading, switch gated themes to the fallback.
		self::enforce_feature_fallbacks();

		return true;
	}

	/**
	 * Update a subscription-related setting.
	 *
	 * @param string $slug  The setting slug.
	 * @param mixed  $value The setting value.
	 * @return void
	 */
	private static function update_subscription_setting( $slug, $value ) {
		Settings::create_settings(
			[
				'slug'  => $slug,
				'value' => $value,
				'group' => 'mv_create_subscription',
			]
		);

		// Reset the cached settings to ensure fresh read on next access.
		Settings::reset_settings();
	}

	/**
	 * Check if a feature is gated (requires Pro or Ad-Supported tier).
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if the feature is gated.
	 */
	public static function is_feature_gated( $feature ) {
		return in_array( $feature, self::$gated_features, true );
	}

	/**
	 * Check if user has access to a specific feature.
	 *
	 * Returns true if:
	 * - The feature is not gated (available to all), OR
	 * - The user has Pro or Ad-Supported tier
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if user can access the feature.
	 */
	public static function can_access( $feature ) {
		// If feature is not gated, everyone has access.
		if ( ! self::is_feature_gated( $feature ) ) {
			return true;
		}

		// Pro-only features require exactly the Pro tier.
		if ( self::is_pro_only( $feature ) ) {
			return self::TIER_PRO === self::get_subscription_tier();
		}

		// Other gated features require Pro or Free+.
		return self::is_pro_or_higher();
	}

	/**
	 * Check if a feature is restricted to Pro tier only (not Free+).
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if the feature requires Pro specifically.
	 */
	public static function is_pro_only( $feature ) {
		return in_array( $feature, self::$pro_only_features, true );
	}

	/**
	 * Check if the current tier is Pro or higher.
	 *
	 * Pro and Free+ tiers both have full feature access.
	 *
	 * @return bool True if tier is 'pro' or 'free-plus'.
	 */
	public static function is_pro_or_higher() {
		$tier = self::get_subscription_tier();

		return in_array( $tier, [ self::TIER_PRO, self::TIER_FREE_PLUS ], true );
	}

	/**
	 * Get the upgrade URL for Create Studio subscription page.
	 *
	 * @return string The full URL to the Create Studio subscription settings page.
	 */
	public static function get_upgrade_url() {
		$site_url = rawurlencode( home_url() );

		return Plugin::$create_studio_base_url . '/admin/upgrade?site_url=' . $site_url;
	}

	/**
	 * Enforce feature fallbacks when downgrading from a premium tier.
	 *
	 * Resets all gated settings to their defaults and switches gated themes
	 * to the big-image fallback.
	 *
	 * @return void
	 */
	public static function enforce_feature_fallbacks() {
		if ( self::is_pro_or_higher() ) {
			return;
		}

		$prefix = Plugin::$settings_group . '_';

		// Reset gated theme to fallback.
		$card_style   = Settings::get_setting( $prefix . 'card_style' );
		$gated_themes = [
			'editorial' => self::FEATURE_THEME_EDITORIAL,
			'modern'    => self::FEATURE_THEME_MODERN,
		];

		if ( ! empty( $card_style ) && isset( $gated_themes[ $card_style ] ) ) {
			$fallback = self::get_fallback( $gated_themes[ $card_style ] );
			if ( $fallback ) {
				Settings::update_setting( $prefix . 'card_style', $fallback );
			}
		}

		// Reset all other gated settings to their defaults.
		foreach ( self::$gated_setting_defaults as $slug_suffix => $default_value ) {
			Settings::update_setting( $prefix . $slug_suffix, $default_value );
		}
	}

	/**
	 * Get list of all gated features.
	 *
	 * @return array Array of feature identifier constants.
	 */
	public static function get_gated_features() {
		return self::$gated_features;
	}

	/**
	 * Get fallback value for a gated feature.
	 *
	 * For example, gated themes fall back to 'big-image' theme.
	 *
	 * @param string $feature The feature identifier.
	 * @return mixed|null The fallback value, or null if no fallback defined.
	 */
	public static function get_fallback( $feature ) {
		if ( isset( self::$feature_fallbacks[ $feature ] ) ) {
			return self::$feature_fallbacks[ $feature ];
		}

		return null;
	}
}
