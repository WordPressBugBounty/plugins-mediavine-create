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
	 * Subscription tier constant for trial users (same features as free-plus).
	 */
	const TIER_TRIAL = 'trial';

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
	 * Feature identifier for sync scroll in the list editor.
	 */
	const FEATURE_SYNC_SCROLL = 'sync_scroll';

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
	 * Setting slug for total site count (all canonical sites for the user).
	 */
	const SETTING_TOTAL_SITE_COUNT = 'mv_create_total_site_count';

	/**
	 * Setting slug for trial status.
	 */
	const SETTING_IS_TRIALING = 'mv_create_is_trialing';

	/**
	 * Setting slug for trial days remaining.
	 */
	const SETTING_TRIAL_DAYS_REMAINING = 'mv_create_trial_days_remaining';

	/**
	 * Setting slug for trial end date.
	 */
	const SETTING_TRIAL_END = 'mv_create_trial_end';

	/**
	 * Setting slug for trial eligibility.
	 */
	const SETTING_TRIAL_ELIGIBLE = 'mv_create_trial_eligible';

	/**
	 * Setting slug for trial extensions (JSON object of redeemed steps).
	 */
	const SETTING_TRIAL_EXTENSIONS = 'mv_create_trial_extensions';

	/**
	 * Map of setting slugs to trial extension step keys.
	 *
	 * @var array
	 */
	private static $setting_to_trial_step = [
		'mv_create_enable_servings_adjustment'    => 'servings_adjustment',
		'mv_create_enable_unit_conversion'        => 'unit_conversion',
		'mv_create_enable_checklists'             => 'checklists',
		'mv_create_widget_toolbar_layout'         => 'toolbar_layout',
		'mv_create_card_style'                    => 'premium_theme',
	];

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
		self::FEATURE_SYNC_SCROLL,
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
	 * Theme feature fallbacks must stay aligned with
	 * Creations_Views_Themes::get_fallback_style() for the matching style slug.
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
		// Themes: gated card_style values are reset via Creations_Views_Themes in enforce_feature_fallbacks().
		// Products
		'products_display_mode'  => 'gallery',
		'products_section_title' => 'Recommended Products',
		'products_position'      => 'after_video',
		// Toggle features — disable on downgrade
		'enable_checklists'              => '',
		'enable_sync_scroll'             => '',
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
		add_action( 'mv_create_sync_subscription', [ __CLASS__, 'sync_subscription' ] );

		foreach ( self::interactive_mode_studio_keys() as $setting_slug => $studio_key ) {
			add_action( 'mv_create_setting_updated_' . $setting_slug, [ __CLASS__, 'sync_setting_to_studio' ], 10, 1 );
		}

		// Auto-detect trial extension steps when features are enabled.
		foreach ( self::$setting_to_trial_step as $setting_slug => $step ) {
			add_action( 'mv_create_setting_updated_' . $setting_slug, [ __CLASS__, 'maybe_extend_trial_on_setting' ] );
		}

		// Auto-detect trial extension steps for actions (not settings).
		add_action( 'mv_create_bulk_import_completed', [ __CLASS__, 'maybe_extend_trial_bulk_import' ] );
		add_action( 'mv_create_review_managed', [ __CLASS__, 'maybe_extend_trial_review' ] );
		add_action( 'mv_review_response_created', [ __CLASS__, 'maybe_extend_trial_review' ] );
	}

	/**
	 * Map of Create setting slugs to Create Studio payload keys for interactive mode.
	 *
	 * @return array<string, string>
	 */
	private static function interactive_mode_studio_keys() {
		return [
			'mv_create_enable_interactive_mode'       => 'interactive_mode_enabled',
			'mv_create_interactive_mode_button_text'  => 'interactive_mode_button_text',
			'mv_create_interactive_mode_cta_variant'  => 'interactive_mode_cta_variant',
			'mv_create_interactive_mode_cta_title'    => 'interactive_mode_cta_title',
			'mv_create_interactive_mode_cta_subtitle' => 'interactive_mode_cta_subtitle',
		];
	}

	/**
	 * Sync an interactive-mode setting to Create Studio.
	 *
	 * Hooked for each slug in interactive_mode_studio_keys(); the Studio payload
	 * key is resolved from the setting slug.
	 *
	 * @param object $setting The setting object with slug and value.
	 * @return void
	 */
	public static function sync_setting_to_studio( $setting ) {
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

		$keys = self::interactive_mode_studio_keys();
		if ( empty( $setting->slug ) || empty( $keys[ $setting->slug ] ) ) {
			return;
		}

		$studio_key = $keys[ $setting->slug ];
		$value      = ( 'interactive_mode_enabled' === $studio_key )
			? ! empty( $setting->value )
			: sanitize_text_field( $setting->value );

		Create_Studio_Client::request(
			'POST',
			'/sites/' . $site_id,
			[ $studio_key => $value ]
		);
	}

	/**
	 * Whether interactive mode should actually render for readers.
	 *
	 * The setting seeds to enabled, so a free site carries a truthy value it never
	 * chose — check access, never the setting alone.
	 *
	 * Free+ must not be able to switch interactive mode off, but that rule lives in
	 * enforce_free_plus_interactive_mode() on release/new-tiers; don't add a second
	 * copy here.
	 *
	 * @return bool
	 */
	public static function is_interactive_mode_enabled() {
		if ( ! self::can_access( self::FEATURE_INTERACTIVE_MODE ) ) {
			return false;
		}

		return (bool) Settings::get_setting( 'mv_create_enable_interactive_mode', false );
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

		// Defer the sync to avoid blocking admin page render with an
		// outbound HTTP request. wp_schedule_single_event fires on the
		// next page load via WP-Cron (or immediately with alternate cron).
		if ( ! wp_next_scheduled( 'mv_create_sync_subscription' ) ) {
			wp_schedule_single_event( time(), 'mv_create_sync_subscription' );
		}
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
		$valid_tiers = [ self::TIER_FREE, self::TIER_PRO, self::TIER_FREE_PLUS, self::TIER_TRIAL ];
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
		$valid_tiers = [ self::TIER_FREE, self::TIER_PRO, self::TIER_FREE_PLUS, self::TIER_TRIAL ];
		if ( ! in_array( $subscription_tier, $valid_tiers, true ) ) {
			$subscription_tier = self::TIER_FREE;
		}

		// Store the subscription tier.
		self::update_subscription_setting( self::SETTING_SUBSCRIPTION_TIER, $subscription_tier );

		// Store active paid subscription count and total site count (for multi-site discount messaging).
		$active_paid_count = isset( $status['active_paid_count'] ) ? (int) $status['active_paid_count'] : 0;
		self::update_subscription_setting( self::SETTING_ACTIVE_PAID_COUNT, $active_paid_count );
		$total_site_count = isset( $status['total_site_count'] ) ? (int) $status['total_site_count'] : 1;
		self::update_subscription_setting( self::SETTING_TOTAL_SITE_COUNT, $total_site_count );

		// Store trial status fields.
		$is_trialing = ! empty( $status['is_trialing'] );
		self::update_subscription_setting( self::SETTING_IS_TRIALING, $is_trialing ? '1' : '' );
		self::update_subscription_setting( self::SETTING_TRIAL_DAYS_REMAINING, isset( $status['trial_days_remaining'] ) ? (int) $status['trial_days_remaining'] : 0 );
		self::update_subscription_setting( self::SETTING_TRIAL_END, isset( $status['trial_end'] ) ? $status['trial_end'] : '' );

		// Store trial extensions (redeemed steps).
		if ( isset( $status['trial_extensions'] ) && is_array( $status['trial_extensions'] ) ) {
			self::update_subscription_setting( self::SETTING_TRIAL_EXTENSIONS, wp_json_encode( $status['trial_extensions'] ) );
		}

		// Store trial eligibility.
		$trial_eligible = ! empty( $status['trial_eligible'] );
		self::update_subscription_setting( self::SETTING_TRIAL_ELIGIBLE, $trial_eligible ? '1' : '' );

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
	 * @return bool True if tier is 'pro', 'free-plus', or 'trial'.
	 */
	public static function is_pro_or_higher() {
		$tier = self::get_subscription_tier();

		return in_array( $tier, [ self::TIER_PRO, self::TIER_FREE_PLUS, self::TIER_TRIAL ], true );
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

		// Reset gated theme to fallback (slugs/fallbacks from Creations_Views_Themes).
		$card_style = Settings::get_setting( $prefix . 'card_style' );
		if ( ! empty( $card_style ) && Creations_Views_Themes::is_gated( $card_style ) ) {
			Settings::update_setting(
				$prefix . 'card_style',
				Creations_Views_Themes::get_fallback_style( $card_style )
			);
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

	/**
	 * Check if the site is currently in a Pro trial.
	 *
	 * @return bool True if the site is trialing.
	 */
	public static function is_trialing() {
		return ! empty( Settings::get_setting( self::SETTING_IS_TRIALING ) );
	}

	/**
	 * Get the number of trial days remaining.
	 *
	 * @return int Days remaining in the trial, or 0 if not trialing.
	 */
	public static function get_trial_days_remaining() {
		return (int) Settings::get_setting( self::SETTING_TRIAL_DAYS_REMAINING );
	}

	/**
	 * Get the trial end date.
	 *
	 * @return string ISO 8601 trial end date, or empty string if not trialing.
	 */
	public static function get_trial_end() {
		return (string) Settings::get_setting( self::SETTING_TRIAL_END );
	}

	/**
	 * Get the trial extensions (redeemed steps).
	 *
	 * @return array Associative array of step keys to redemption timestamps.
	 */
	public static function get_trial_extensions() {
		$raw = Settings::get_setting( self::SETTING_TRIAL_EXTENSIONS );
		if ( empty( $raw ) ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Auto-extend trial when a mapped setting is enabled.
	 *
	 * Fires on `mv_create_setting_updated_{slug}` for settings in $setting_to_trial_step.
	 *
	 * @param object $setting The setting object with slug and value.
	 */
	public static function maybe_extend_trial_on_setting( $setting ) {
		// Don't trigger during Studio syncs.
		if ( self::$syncing_from_studio ) {
			return;
		}

		// Only act when the setting is being enabled (truthy value).
		if ( empty( $setting->value ) ) {
			return;
		}

		// Only extend if currently trialing.
		if ( ! self::is_trialing() ) {
			return;
		}

		$step = isset( self::$setting_to_trial_step[ $setting->slug ] ) ? self::$setting_to_trial_step[ $setting->slug ] : null;
		if ( ! $step ) {
			return;
		}

		// For premium_theme step, only trigger on actual premium themes.
		if ( 'premium_theme' === $step && ! Creations_Views_Themes::is_gated( $setting->value ) ) {
			return;
		}

		// For toolbar_layout step, skip the default value.
		if ( 'toolbar_layout' === $step && 'toolbar' === $setting->value ) {
			return;
		}

		// Check if step already redeemed locally to avoid unnecessary API call.
		$extensions = self::get_trial_extensions();
		if ( isset( $extensions[ $step ] ) ) {
			return;
		}

		// Fire-and-forget: call the trial extension API.
		Trial_API::handle_extend_step( $step );
	}

	/**
	 * Extend trial when a bulk import is completed.
	 *
	 * Hooked to: mv_create_bulk_import_completed
	 *
	 * @param mixed $imported The imported data (unused).
	 */
	public static function maybe_extend_trial_bulk_import( $imported = null ) {
		if ( ! self::is_trialing() ) {
			return;
		}

		$extensions = self::get_trial_extensions();
		if ( isset( $extensions['bulk_import'] ) ) {
			return;
		}

		Trial_API::handle_extend_step( 'bulk_import' );
	}

	/**
	 * Extend trial when a review is managed or responded to.
	 *
	 * Hooked to: mv_create_review_managed, mv_review_response_created
	 *
	 * @param mixed $data The review data (unused).
	 */
	public static function maybe_extend_trial_review( $data = null ) {
		if ( ! self::is_trialing() ) {
			return;
		}

		$extensions = self::get_trial_extensions();
		if ( isset( $extensions['review_management'] ) ) {
			return;
		}

		Trial_API::handle_extend_step( 'review_management' );
	}
}
