<?php
/**
 * GateKeeper - Feature Access Control
 *
 * Manages feature gating based on subscription tiers. Controls access to premium
 * features via the capability matrix keyed by the site's effective tier.
 *
 * @package Create
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
	 * Subscription tier constant for the free floor / downgrade tier.
	 *
	 * Display name "Free". Keeps only ungated features. Not to be confused with
	 * the "Disconnected" column of the pricing matrix, which is a connection
	 * state, not a tier.
	 */
	const TIER_FREE = 'free';

	/**
	 * Subscription tier constant for free-plus (ad-supported) users.
	 *
	 * Display name "Free+". Chosen explicitly by the publisher — see
	 * set_free_plan_choice() and Plan_Choice — or set as a real Studio
	 * subscription tier; the recipe band unlocks outright either way (the old
	 * >=10-recipe eligibility floor was removed).
	 */
	const TIER_FREE_PLUS = 'free-plus';

	/**
	 * Subscription tier constant for the guide-focused paid tier.
	 *
	 * Display name "Pro Guides".
	 */
	const TIER_PRO_GUIDES = 'pro-guides';

	/**
	 * Subscription tier constant for the list-focused paid tier.
	 *
	 * Display name "Pro Lists".
	 */
	const TIER_PRO_LISTS = 'pro-lists';

	/**
	 * Subscription tier constant for the top paid tier (union of all).
	 *
	 * Display name "Pro Pass". Keeps the shipped legacy `pro` slug, whose
	 * meaning (full access) is unchanged, so stored `pro` records stay correct.
	 */
	const TIER_PRO = 'pro';

	/**
	 * Canonical list of all real (storable) tiers.
	 *
	 * @var array
	 */
	private static $all_tiers = [
		self::TIER_FREE,
		self::TIER_FREE_PLUS,
		self::TIER_PRO_GUIDES,
		self::TIER_PRO_LISTS,
		self::TIER_PRO,
	];

	/**
	 * Tier set: every tier EXCEPT free.
	 *
	 * @var array
	 */
	const EVERYONE_BUT_FREE = [
		self::TIER_FREE_PLUS,
		self::TIER_PRO_GUIDES,
		self::TIER_PRO_LISTS,
		self::TIER_PRO,
	];

	/**
	 * Tier set: recipe-engine tiers (NOT pro-lists, NOT free).
	 *
	 * @var array
	 */
	const RECIPE_TIERS = [
		self::TIER_FREE_PLUS,
		self::TIER_PRO_GUIDES,
		self::TIER_PRO,
	];

	/**
	 * Tier set: the guide-focused premium band.
	 *
	 * @var array
	 */
	const GUIDES_TIERS = [
		self::TIER_PRO_GUIDES,
		self::TIER_PRO,
	];

	/**
	 * Tier set: the list-focused premium band.
	 *
	 * @var array
	 */
	const LIST_TIERS = [
		self::TIER_PRO_LISTS,
		self::TIER_PRO,
	];

	/**
	 * Tier set: every PAID tier (excludes free and the ad-supported free-plus).
	 *
	 * @var array
	 */
	const PAID_TIERS = [
		self::TIER_PRO_GUIDES,
		self::TIER_PRO_LISTS,
		self::TIER_PRO,
	];

	/**
	 * Feature identifier for Editorial theme.
	 */
	const FEATURE_THEME_EDITORIAL = 'theme_editorial';

	/**
	 * Feature identifier for Modern Elegant theme.
	 */
	const FEATURE_THEME_MODERN = 'theme_modern';

	/**
	 * Feature identifier for COLLECTING new user reviews and ratings.
	 *
	 * Gates the *intake* path only — the front-end rating/review affordances and
	 * the public POST /reviews endpoint. It deliberately does NOT gate display:
	 * existing reviews, the aggregate star rating, the public reviews tab, and
	 * the Recipe/HowTo `aggregateRating` + `review` JSON-LD all keep rendering at
	 * every tier, including free. Never use this feature to hide review output.
	 */
	const FEATURE_REVIEW_COLLECTION = 'review_collection';

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
	 * Feature identifier for the premium Editorial list layout.
	 */
	const FEATURE_LIST_LAYOUT_EDITORIAL = 'list_layout_editorial';

	/**
	 * Feature identifier for the premium Leaderboard list layout.
	 */
	const FEATURE_LIST_LAYOUT_LEADERBOARD = 'list_layout_leaderboard';

	/**
	 * Feature identifier for the premium Ranked list layout (counts down to a
	 * featured #1).
	 */
	const FEATURE_LIST_LAYOUT_RANKED = 'list_layout_ranked';

	/**
	 * Feature identifier for the premium top-first Ranked list layout (leads
	 * with the featured #1, then 2, 3, …).
	 */
	const FEATURE_LIST_LAYOUT_RANKED_TOP = 'list_layout_ranked_top';

	/**
	 * Feature identifier for auto-syncing list items with their source post.
	 */
	const FEATURE_LIST_ITEM_SYNC = 'list_item_sync';

	/**
	 * Feature identifier for product scraping (incl. Amazon).
	 */
	const FEATURE_PRODUCT_SCRAPE = 'product_scrape';

	/**
	 * Feature identifier for list-item scraping (RESERVED pending code trace).
	 */
	const FEATURE_LIST_ITEM_SCRAPE = 'list_item_scrape';

	/**
	 * Feature identifier for nutrition auto-calculation.
	 */
	const FEATURE_NUTRITION_CALC = 'nutrition_calculation';

	/**
	 * Feature identifier for the BASE (free) per-card nutrition editor.
	 *
	 * CONNECTION-gated, NOT tier-gated: locked only while the site is disconnected
	 * from Create Studio; free for everyone once connected. Distinct from
	 * FEATURE_NUTRITION_CALC (automatic calculation), which stays tier-gated. Do not
	 * merge or confuse the two.
	 */
	const FEATURE_NUTRITION = 'nutrition';

	/**
	 * Feature identifier for the BASE (free) per-card Recommended Products editor.
	 *
	 * CONNECTION-gated, NOT tier-gated: locked only while the site is disconnected
	 * from Create Studio; free for everyone once connected. Distinct from
	 * FEATURE_RECOMMENDED_PRODUCTS_DISPLAY (premium display modes), which stays
	 * tier-gated. Do not merge or confuse the two.
	 */
	const FEATURE_RECOMMENDED_PRODUCTS = 'recommended_products';

	/**
	 * Feature identifier for Amazon PA-API product lookup.
	 *
	 * CONNECTION-gated, NOT tier-gated. Amazon lookups use the publisher's OWN
	 * Amazon Product Advertising API keys and never touch Create Studio, so they
	 * are free at every tier (including free) once the site is connected —
	 * unlike FEATURE_PRODUCT_SCRAPE (the Create Studio URL scraper), which stays a
	 * premium EVERYONE_BUT_FREE feature. Do not confuse the two.
	 */
	const FEATURE_AMAZON_LOOKUP = 'amazon_lookup';

	/**
	 * Feature identifier for premium list layouts (Editorial + Ranked only).
	 */
	const FEATURE_PREMIUM_LIST_LAYOUTS = 'premium_list_layouts';

	/**
	 * Feature identifier for the recommended-products premium display
	 * (products_display_mode / products_section_title / products_position).
	 *
	 * This is a recipe/howto "Recommended Products" feature, NOT a list layout.
	 */
	const FEATURE_RECOMMENDED_PRODUCTS_DISPLAY = 'recommended_products_display';

	/**
	 * Feature identifier for advanced steps (coming soon).
	 */
	const FEATURE_ADVANCED_STEPS = 'advanced_steps';

	/**
	 * TTL for subscription cache in seconds (1 hour).
	 */
	const SUBSCRIPTION_TTL = 3600;

	/**
	 * Request timeout in seconds for the inline (page-blocking) subscription sync.
	 *
	 * Deliberately far below Create_Studio_Client::DEFAULT_TIMEOUT: this call
	 * runs ahead of a Create admin page render, so a slow Studio must cost the
	 * publisher a few seconds at most before the cron fallback takes over.
	 */
	const INLINE_SYNC_TIMEOUT = 5;

	/**
	 * How long (seconds) a failed inline sync suppresses further inline attempts.
	 */
	const INLINE_SYNC_BACKOFF = 300;

	/**
	 * Transient flagging that a recent inline sync failed (see INLINE_SYNC_BACKOFF).
	 */
	const TRANSIENT_INLINE_SYNC_BACKOFF = 'mv_create_subscription_sync_backoff';

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
	 * Setting slug for the multi-site discount flag: '1' when another of the
	 * owner's sites has an active Pro Pass subscription (and the owner has more
	 * than one site), i.e. THIS site would get 50% off Pro Pass. Studio decides;
	 * the plugin only stores and displays it.
	 */
	const SETTING_MULTI_SITE_DISCOUNT_ELIGIBLE = 'mv_create_multi_site_discount_eligible';

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
	 * Setting slugs for the plan the subscription is billed on. During a
	 * trial the tier is the Pro Pass-equivalent, so this is the only record
	 * of what the trial converts to. Tier slug and 'monthly' | 'yearly'.
	 */
	const SETTING_PLAN_TIER     = 'mv_create_plan_tier';
	const SETTING_PLAN_INTERVAL = 'mv_create_plan_interval';

	/**
	 * Setting slug for trial eligibility.
	 */
	const SETTING_TRIAL_ELIGIBLE = 'mv_create_trial_eligible';

	/**
	 * Setting slug for trial extensions (JSON object of redeemed steps).
	 */
	const SETTING_TRIAL_EXTENSIONS = 'mv_create_trial_extensions';

	/**
	 * Setting slug for the dunning "renewal payment failed" flag (CRE-295).
	 */
	const SETTING_PAYMENT_FAILED = 'mv_create_payment_failed';

	/**
	 * Setting slug for the dunning grace deadline (ISO 8601).
	 */
	const SETTING_GRACE_UNTIL = 'mv_create_grace_until';

	/**
	 * Setting slug for the "cancels at period end" flag (create-studio CS-26).
	 *
	 * Set while the subscriber has cancelled in Stripe but the paid period they
	 * already paid for is still running.
	 */
	const SETTING_CANCEL_AT_PERIOD_END = 'mv_create_cancel_at_period_end';

	/**
	 * Setting slug for the end of the current billing period (ISO 8601) — the
	 * date a pending cancellation takes effect.
	 */
	const SETTING_CURRENT_PERIOD_END = 'mv_create_current_period_end';

	/**
	 * Setting slug for the pre-downgrade settings snapshot.
	 *
	 * JSON object of setting suffix => stored value, captured by the free-floor
	 * reset just before it overwrites a publisher value, so a later upgrade can
	 * restore each value once the new tier allows its bound feature again.
	 */
	const SETTING_PRE_DOWNGRADE_SETTINGS = 'mv_create_pre_downgrade_settings';

	/**
	 * Setting slug for the publisher's explicit choice between the two free-band
	 * plans, made once via the Create 3.0 plan-choice gate (Plan_Choice).
	 *
	 * '' (no choice yet) | 'free' | 'free-plus'. Deliberately NOT cleared by
	 * reset_subscription_state(): the choice is the publisher's, not Studio's,
	 * so it has to survive a disconnect and a re-sync — see default_tier(),
	 * which resolves an unset stored tier from this choice rather than from an
	 * automatic entitlement.
	 */
	const SETTING_FREE_PLAN_CHOICE = 'mv_create_free_plan_choice';

	/**
	 * Per-request memo of get_effective_tier().
	 *
	 * Null means unresolved. Cleared by reset_request_cache() whenever
	 * subscription settings are written (and by tests that poke settings
	 * directly).
	 *
	 * @var string|null
	 */
	private static $effective_tier = null;

	/**
	 * Subscription/trial setting slugs written at sync time, not registered.
	 *
	 * These rows are created by update_subscription_setting() rather than by a
	 * settings-group class, so anything enumerating "every setting the plugin
	 * can hold" (e.g. the generated admin fixture) has to ask for them here.
	 *
	 * @return string[]
	 */
	/**
	 * A plan tier slug as Create Studio reports it, or '' for anything else.
	 *
	 * @param mixed $tier Reported tier.
	 * @return string
	 */
	public static function sanitize_plan_tier( $tier ) {
		return is_string( $tier ) && self::is_known_tier_slug( $tier ) ? $tier : '';
	}

	/**
	 * 'monthly' or 'yearly', or '' for anything else.
	 *
	 * @param mixed $interval Reported billing interval.
	 * @return string
	 */
	public static function sanitize_plan_interval( $interval ) {
		return in_array( $interval, [ 'monthly', 'yearly' ], true ) ? $interval : '';
	}

	public static function get_subscription_setting_slugs() {
		return [
			self::SETTING_SUBSCRIPTION_TIER,
			self::SETTING_SUBSCRIPTION_SYNCED_AT,
			self::SETTING_ACTIVE_PAID_COUNT,
			self::SETTING_TOTAL_SITE_COUNT,
			self::SETTING_IS_TRIALING,
			self::SETTING_TRIAL_DAYS_REMAINING,
			self::SETTING_TRIAL_END,
			self::SETTING_PLAN_TIER,
			self::SETTING_PLAN_INTERVAL,
			self::SETTING_TRIAL_ELIGIBLE,
			self::SETTING_TRIAL_EXTENSIONS,
			self::SETTING_PRE_DOWNGRADE_SETTINGS,
			self::SETTING_FREE_PLAN_CHOICE,
			self::SETTING_MULTI_SITE_DISCOUNT_ELIGIBLE,
		];
	}

	/**
	 * Map of setting slugs to trial extension step keys.
	 *
	 * @var array
	 */
	private static $setting_to_trial_step = [
		'mv_create_enable_servings_adjustment' => 'servings_adjustment',
		'mv_create_enable_unit_conversion'     => 'unit_conversion',
		'mv_create_enable_checklists'          => 'checklists',
		'mv_create_widget_toolbar_layout'      => 'toolbar_layout',
		'mv_create_card_style'                 => 'premium_theme',
	];

	/**
	 * The capability matrix.
	 *
	 * Each feature maps to the explicit set of tiers that include it. Features NOT
	 * listed here are UNGATED (available to all tiers including free). This is the
	 * single source of truth; do not add hierarchy assumptions on top of it.
	 *
	 * NOTE: declared as a method (matrix()) because class constants cannot reference
	 * other class constant arrays as elements before PHP 8.2 in all paths, and to
	 * keep the tier-set constants (EVERYONE_BUT_FREE / RECIPE_TIERS) authoritative.
	 * Memoized in a static so hot-path callers (can_access / tier_allows) do not
	 * rebuild the ~25-entry array on every check.
	 *
	 * LOCKSTEP: three surfaces encode this table and three tests pin them to the
	 * same CANON block. Change one, change all three:
	 *   - tests/Integration/test-gatekeeper-parity.php               (this matrix)
	 *   - admin/ui/src/__tests__/featureMatrixParity.test.ts         (JS fallback)
	 *   - create-studio: packages/app/tests/unit/tier-parity.test.ts (Studio)
	 *
	 * @return array<string,string[]>
	 */
	private static function feature_matrix() {
		static $matrix = null;

		if ( null !== $matrix ) {
			return $matrix;
		}

		$matrix = [
			// Scraping + automatic nutrition — everyone EXCEPT free.
			// Automatic nutrition calc is an all-premium-plans feature: every
			// connected paid/ad tier gets it (incl. Pro Lists); only the free
			// floor (and the disconnected state) lack it.
			self::FEATURE_PRODUCT_SCRAPE               => self::EVERYONE_BUT_FREE,
			self::FEATURE_LIST_ITEM_SCRAPE             => self::EVERYONE_BUT_FREE,
			self::FEATURE_NUTRITION_CALC               => self::EVERYONE_BUT_FREE,

			// Collecting NEW reviews/ratings — everyone EXCEPT free. Display of
			// already-collected reviews, the aggregate rating and the review
			// JSON-LD stay ungated at every tier; only intake is gated.
			self::FEATURE_REVIEW_COLLECTION            => self::EVERYONE_BUT_FREE,

			// Custom CSS — PAID plans only (Free+ does not include it).
			self::FEATURE_CUSTOM_CSS                   => self::PAID_TIERS,

			// Recipe-engine features — recipe tiers (NOT pro-lists, NOT free).
			self::FEATURE_UNIT_CONVERSION              => self::RECIPE_TIERS,
			self::FEATURE_SERVINGS_ADJUSTMENT          => self::RECIPE_TIERS,
			self::FEATURE_INTERACTIVE_MODE             => self::RECIPE_TIERS,

			// Guides-only premium — Pro Guides / Pro Pass.
			// Review management (edit / respond / feature / featured block) sits
			// above the recipe band per the pricing matrix: Pro Guides and Pro
			// Pass only. Pro Lists stays lists-focused; free-plus lacks it.
			self::FEATURE_REVIEW_EDIT                  => self::GUIDES_TIERS,
			self::FEATURE_REVIEW_RESPOND               => self::GUIDES_TIERS,
			self::FEATURE_REVIEW_FEATURED              => self::GUIDES_TIERS,
			self::FEATURE_FEATURED_REVIEW_BLOCK        => self::GUIDES_TIERS,
			self::FEATURE_CHECKLISTS                   => self::GUIDES_TIERS,
			self::FEATURE_ADVANCED_STEPS               => self::GUIDES_TIERS,
			self::FEATURE_THEME_EDITORIAL              => self::GUIDES_TIERS,
			self::FEATURE_THEME_MODERN                 => self::GUIDES_TIERS,
			self::FEATURE_RECOMMENDED_PRODUCTS_DISPLAY => self::GUIDES_TIERS,

			// Lists-only premium — Pro Lists / Pro Pass.
			self::FEATURE_PREMIUM_LIST_LAYOUTS         => self::LIST_TIERS,
			self::FEATURE_LIST_BULK_IMPORT             => self::LIST_TIERS,
			self::FEATURE_SYNC_SCROLL                  => self::LIST_TIERS,
			// Per-layout gating for the premium list layouts (from #5315). Each
			// premium layout resolves through can_access() via $gated_list_layouts.
			self::FEATURE_LIST_LAYOUT_EDITORIAL        => self::LIST_TIERS,
			self::FEATURE_LIST_LAYOUT_LEADERBOARD      => self::LIST_TIERS,
			self::FEATURE_LIST_LAYOUT_RANKED           => self::LIST_TIERS,
			self::FEATURE_LIST_LAYOUT_RANKED_TOP       => self::LIST_TIERS,
			// List-item sync (distinct from sync_scroll) — Pro Lists / Pro Pass.
			self::FEATURE_LIST_ITEM_SYNC               => self::LIST_TIERS,
		];

		return $matrix;
	}

	/**
	 * Map of premium list layout slugs to their gating feature identifier (from
	 * #5315). Used to gate layout selection in the editor and to fall back to a
	 * free layout at render time when the site can't access the premium layout.
	 *
	 * @var array
	 */
	private static $gated_list_layouts = [
		'editorial'   => self::FEATURE_LIST_LAYOUT_EDITORIAL,
		'leaderboard' => self::FEATURE_LIST_LAYOUT_LEADERBOARD,
		'ranked'      => self::FEATURE_LIST_LAYOUT_RANKED,
		'ranked-top'  => self::FEATURE_LIST_LAYOUT_RANKED_TOP,
	];

	/**
	 * Map of premium card theme slugs to their gating feature identifier.
	 *
	 * The single source of truth for "which theme needs which entitlement",
	 * mirroring $gated_list_layouts. Every theme gate — shortcode render, the
	 * apply-theme admin-post handler, the preview banner, the card_style setting
	 * option list, and the free-floor downgrade — resolves through
	 * theme_feature() / can_access_theme() / get_accessible_theme(), so a new
	 * premium theme is a one-line change here.
	 *
	 * Which themes are gated is declared once in Creations_Views_Themes; the
	 * keys of this map must stay aligned with
	 * Creations_Views_Themes::get_gated_style_slugs() (asserted by
	 * tests/Integration/test-gatekeeper-theme-gating.php).
	 *
	 * @var array
	 */
	private static $gated_themes = [
		'editorial' => self::FEATURE_THEME_EDITORIAL,
		'modern'    => self::FEATURE_THEME_MODERN,
	];

	/**
	 * Per-feature render fallbacks (from #5315). Premium list layouts fall back
	 * to a free single-column layout when the site can't access them. Consulted
	 * by get_fallback() ahead of the $setting_to_feature map.
	 *
	 * Theme feature fallbacks must stay aligned with
	 * Creations_Views_Themes::get_fallback_style() for the matching style slug.
	 *
	 * @var array
	 */
	private static $feature_fallbacks = [
		self::FEATURE_LIST_LAYOUT_EDITORIAL   => 'hero',
		self::FEATURE_LIST_LAYOUT_LEADERBOARD => 'numbered',
		self::FEATURE_LIST_LAYOUT_RANKED      => 'numbered',
		self::FEATURE_LIST_LAYOUT_RANKED_TOP  => 'numbered',
	];

	/**
	 * Features locked behind a Create Studio CONNECTION (not a tier).
	 *
	 * These are free tier-wise (intentionally NOT in feature_matrix()), so once the
	 * site is connected can_access() resolves them true for every tier including
	 * free. While disconnected the EDITOR is locked to encourage connecting.
	 *
	 * Grandfathering: this gate covers the admin editor + save/create REST paths
	 * only. Existing card content still RENDERS on the frontend regardless of
	 * connection — do not use this set to hide front-end output.
	 *
	 * @var array
	 */
	private static $connection_gated_features = [
		self::FEATURE_NUTRITION,
		self::FEATURE_RECOMMENDED_PRODUCTS,
		self::FEATURE_AMAZON_LOOKUP,
	];

	/**
	 * Map of setting slug suffix => [ governing feature, default-on-loss ].
	 *
	 * Used by the free-floor-only settings reset in enforce_feature_fallbacks().
	 * Settings that hold a MIX of free and premium values (card_style,
	 * list_layout) downgrade value-aware via setting_value_downgrades() instead
	 * of the blanket default here.
	 *
	 * @var array
	 */
	private static $setting_to_feature = [
		// card_style downgrades value-aware via setting_value_downgrades(), which
		// derives its map from $gated_themes — the feature here is only the
		// representative gate for the tier_allows() guard, and the floor value is
		// never used because every gated theme has a registry fallback.
		'card_style'                   => [ self::FEATURE_THEME_EDITORIAL, Creations_Views_Themes::DEFAULT_FALLBACK ],
		'list_layout'                  => [ self::FEATURE_PREMIUM_LIST_LAYOUTS, 'numbered' ],
		'products_display_mode'        => [ self::FEATURE_RECOMMENDED_PRODUCTS_DISPLAY, 'gallery' ],
		'products_section_title'       => [ self::FEATURE_RECOMMENDED_PRODUCTS_DISPLAY, 'Recommended Products' ],
		'products_position'            => [ self::FEATURE_RECOMMENDED_PRODUCTS_DISPLAY, 'after_video' ],
		'enable_checklists'            => [ self::FEATURE_CHECKLISTS, '' ],
		'enable_sync_scroll'           => [ self::FEATURE_SYNC_SCROLL, '' ],
		'enable_interactive_mode'      => [ self::FEATURE_INTERACTIVE_MODE, '' ],
		// Card-type choice and CTA customization are paid-only (see
		// Reader_Experience), so these bind to custom_css: a free-floored site
		// loses its custom copy/variant, and the snapshot only restores once the
		// new tier actually allows the branding.
		'interactive_mode_card_types'  => [ self::FEATURE_CUSTOM_CSS, 'both' ],
		'interactive_mode_button_text' => [ self::FEATURE_CUSTOM_CSS, '' ],
		'interactive_mode_cta_variant' => [ self::FEATURE_CUSTOM_CSS, 'inline-banner' ],
		'interactive_mode_cta_title'   => [ self::FEATURE_CUSTOM_CSS, '' ],
		'interactive_mode_cta_subtitle' => [ self::FEATURE_CUSTOM_CSS, '' ],
		'enable_unit_conversion'       => [ self::FEATURE_UNIT_CONVERSION, '' ],
		'enable_servings_adjustment'   => [ self::FEATURE_SERVINGS_ADJUSTMENT, '' ],
		'custom_css'                   => [ self::FEATURE_CUSTOM_CSS, '' ],
		// Deliberately absent: 'allow_reviews' / 'reviews_ctas' /
		// 'enable_anonymous_ratings'. FEATURE_REVIEW_COLLECTION gates review
		// INTAKE at render/submit time only; flipping allow_reviews off would
		// also hide the aggregate star rating on existing cards, which the free
		// floor must never do.
	];

	/**
	 * Value-aware free-floor downgrade maps: setting suffix => [ premium value => free value ].
	 *
	 * Settings listed here hold a mix of free and premium values, so the
	 * free-floor reset must not blindly overwrite them: a stored FREE value is
	 * left untouched, and each premium value maps to its closest free
	 * equivalent rather than one hard default. The list-layout map is derived
	 * from $gated_list_layouts + the per-feature render fallbacks so a newly
	 * gated layout picks up its downgrade mapping automatically, and the
	 * card-style map from $gated_themes + the theme registry's declared
	 * fallbacks.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function setting_value_downgrades() {
		$list_layouts = [];
		foreach ( self::$gated_list_layouts as $layout => $feature ) {
			$fallback                = self::get_fallback( $feature );
			$list_layouts[ $layout ] = $fallback ? $fallback : 'numbered';
		}

		$card_styles = [];
		foreach ( array_keys( self::$gated_themes ) as $theme ) {
			$fallback              = Creations_Views_Themes::get_fallback_style( $theme );
			$card_styles[ $theme ] = ( $fallback && $fallback !== $theme )
				? $fallback
				: Creations_Views_Themes::DEFAULT_FALLBACK;
		}

		return [
			'card_style'  => $card_styles,
			'list_layout' => $list_layouts,
		];
	}

	/**
	 * Flag to prevent re-syncing settings that were just received from Studio.
	 *
	 * @var bool
	 */
	public static $syncing_from_studio = false;

	/**
	 * Initialize the GateKeeper hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_sync_subscription' ] );
		add_action( 'mv_create_sync_subscription', [ __CLASS__, 'sync_subscription' ] );
		add_action( 'mv_create_settings_cache_reset', [ __CLASS__, 'reset_request_cache' ] );

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
			'mv_create_interactive_mode_card_types'   => 'interactive_mode_card_types',
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
	 * Setting value => creation types Interactive Mode may render on.
	 *
	 * Keys mirror the `mv_create_interactive_mode_card_types` select; values are
	 * creation `type` slugs ('diy' is the how-to card).
	 *
	 * @return array<string,string[]>
	 */
	private static function interactive_mode_card_type_map() {
		return [
			'both'    => [ 'recipe', 'diy' ],
			'recipes' => [ 'recipe' ],
			'howtos'  => [ 'diy' ],
		];
	}

	/**
	 * Creation types Interactive Mode should render on.
	 *
	 * Empty when the feature is off or out of tier. Free+ is a free plan paid
	 * for by Create's ads served through hosted Interactive Mode, so it is
	 * always both card types regardless of the stored value — only paid tiers
	 * may narrow it (mirrors enforce_free_plus_interactive_mode()).
	 *
	 * @return string[] Creation type slugs.
	 */
	public static function interactive_mode_card_types() {
		if ( ! self::is_interactive_mode_enabled() ) {
			return [];
		}

		$map = self::interactive_mode_card_type_map();

		if ( self::TIER_FREE_PLUS === self::get_effective_tier() ) {
			return $map['both'];
		}

		$value = Settings::get_setting( 'mv_create_interactive_mode_card_types', 'both' );

		return isset( $map[ $value ] ) ? $map[ $value ] : $map['both'];
	}

	/**
	 * Whether Interactive Mode should render on a given creation type.
	 *
	 * @param string $type Creation type slug ('recipe', 'diy', 'list'…).
	 * @return bool
	 */
	public static function is_interactive_mode_enabled_for_type( $type ) {
		return in_array( $type, self::interactive_mode_card_types(), true );
	}

	/**
	 * Check if subscription needs refresh and sync if necessary.
	 *
	 * This is called on admin_init to ensure subscription data stays fresh:
	 * inline on Create's own screens, deferred to WP-Cron everywhere else.
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

		// Create's own screens localize the gate snapshot (tier + feature_access)
		// later in this same request, so a deferred sync would land AFTER the
		// page had already rendered stale gating — it took a second reload to
		// see a tier change (CRE-298). Sync inline there instead, with a short
		// timeout; a failure backs off to the deferred path below so a slow or
		// unreachable Studio never blocks every Create page load.
		if ( self::should_sync_inline() ) {
			if ( self::sync_subscription( self::INLINE_SYNC_TIMEOUT ) ) {
				wp_clear_scheduled_hook( 'mv_create_sync_subscription' );
				return;
			}

			set_transient( self::TRANSIENT_INLINE_SYNC_BACKOFF, 1, self::INLINE_SYNC_BACKOFF );
		}

		// Everywhere else, defer the sync to avoid blocking admin page render
		// with an outbound HTTP request. wp_schedule_single_event fires on the
		// next page load via WP-Cron (or immediately with alternate cron).
		if ( ! wp_next_scheduled( 'mv_create_sync_subscription' ) ) {
			wp_schedule_single_event( time(), 'mv_create_sync_subscription' );
		}
	}

	/**
	 * Whether a stale subscription should be synced inline for this request.
	 *
	 * True only on Create's own admin screens, and only while no recent inline
	 * attempt has failed.
	 *
	 * @return bool
	 */
	private static function should_sync_inline() {
		if ( ! Admin_Notice_Helper::is_create_page() ) {
			return false;
		}

		return ! get_transient( self::TRANSIENT_INLINE_SYNC_BACKOFF );
	}

	/**
	 * Normalize any inbound or stored tier slug to a canonical tier.
	 *
	 * Single point that maps inbound slugs onto the 5 canonical tiers:
	 *  - empty / unknown -> free (fail-closed)
	 *
	 * NOTE: this is the fail-closed normalizer for an inbound VALUE. The
	 * "site has never been told anything" case is handled one level up in
	 * get_subscription_tier(), which floors an unset tier via default_tier()
	 * before it gets here — do not confuse the two.
	 *  - trial           -> free (fail-closed; unlock comes from is_trialing())
	 *  - free / free-plus / pro-guides / pro-lists / pro -> identity
	 *
	 * There are NO aliases: every slug the plugin has ever stored is in the
	 * canonical list above (`pro` is Pro Pass — the same "full access" meaning
	 * it always had), so anything else is genuinely unknown and fail-closes to
	 * free. `trial` is a defensive fail-closed pseudo-slug (real trials come
	 * from is_trialing()).
	 *
	 * @param mixed $raw The raw tier slug.
	 * @return string A canonical tier slug (always a member of $all_tiers).
	 */
	public static function normalize_tier( $raw ) {
		$tier = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $tier ) {
			return self::TIER_FREE;
		}

		if ( 'trial' === $tier ) {
			return self::TIER_FREE;
		}

		if ( in_array( $tier, self::$all_tiers, true ) ) {
			return $tier;
		}

		return self::TIER_FREE;
	}

	/**
	 * Whether a raw slug is a tier the plugin knows how to interpret.
	 *
	 * Known = the 5 canonical tiers plus the accepted inbound alias 'trial'.
	 * Callers validating untrusted input (e.g. webhooks) should check
	 * this BEFORE normalize_tier(), which fail-closes anything unknown to free —
	 * a silent downgrade an invalid slug must never be able to trigger.
	 *
	 * @param mixed $raw The raw tier slug.
	 * @return bool True if the slug is canonical or a known alias.
	 */
	public static function is_known_tier_slug( $raw ) {
		if ( ! is_string( $raw ) ) {
			return false;
		}

		$tier = trim( $raw );

		if ( in_array( $tier, self::$all_tiers, true ) ) {
			return true;
		}

		return 'trial' === $tier;
	}

	/**
	 * Get the stored subscription tier, normalized to a canonical slug.
	 *
	 * Always returns one of the 5 canonical tiers (never trial, never an unknown slug).
	 *
	 * @return string A canonical tier slug.
	 */
	public static function get_subscription_tier() {
		$stored = Settings::get_setting( self::SETTING_SUBSCRIPTION_TIER );

		// UNSET (never synced, or cleared on disconnect) is not the same as a
		// stored 'free': it means nobody has ever told us what this site is
		// entitled to, so the floor applies. See default_tier().
		if ( '' === trim( (string) $stored ) ) {
			return self::default_tier();
		}

		$tier = self::normalize_tier( $stored );

		// Free+ is off while the site is not connected to Create Studio, even if
		// a stale stored 'free-plus' outlived the connection.
		if ( self::TIER_FREE_PLUS === $tier && ! self::is_connected() ) {
			return self::TIER_FREE;
		}

		return $tier;
	}

	/**
	 * The tier a site sits at when Create Studio has never told us otherwise.
	 *
	 * Create 3.0 replaced the old CRE-296 automatic free-plus floor with an
	 * explicit publisher choice (Plan_Choice): a site with no stored tier now
	 * resolves to whichever of Free / Free+ the publisher picked at
	 * `GateKeeper::set_free_plan_choice()`, defaulting to plain Free until a
	 * choice is made. Installation is still the resolution boundary — sites
	 * that never connected have no `Sites` row and no site JWT, so
	 * sync_subscription() never runs and the stored tier stays empty — but
	 * there is no more automatic entitlement.
	 *
	 * Free+ is only in effect while the site is connected to Create Studio:
	 * an unconnected site (never connected, or disconnected) floors to plain
	 * Free regardless of the saved choice. The choice itself is kept, so Free+
	 * resumes on (re)connect. The `mv_create_tier_floor` filter still runs on
	 * the connection-adjusted tier.
	 *
	 * The choice never overrides a real answer: any stored tier — including a
	 * genuine Studio downgrade to `free` — short-circuits it in
	 * get_subscription_tier() before this is ever called.
	 *
	 * @return string A canonical tier slug.
	 */
	public static function default_tier() {
		$choice = self::get_free_plan_choice();
		$tier   = self::TIER_FREE_PLUS === $choice ? self::TIER_FREE_PLUS : self::TIER_FREE;

		// Product decision: Free+ features are off whenever the site is not
		// connected to Create Studio. The stored choice is kept, so Free+
		// resumes automatically on (re)connect.
		if ( ! self::is_connected() ) {
			$tier = self::TIER_FREE;
		}

		/**
		 * Filters the tier an unconnected / never-synced site falls back to.
		 *
		 * @since 3.0.0
		 *
		 * @param string $tier   The resolved floor tier.
		 * @param string $choice The publisher's recorded free-plan choice
		 *                       ('' | 'free' | 'free-plus').
		 */
		return self::normalize_tier( apply_filters( 'mv_create_tier_floor', $tier, $choice ) );
	}

	/**
	 * The publisher's recorded choice between the two free-band plans.
	 *
	 * Set once via the Create 3.0 plan-choice gate (or mirrored back by a
	 * successful sync — see sync_subscription()). Normalizes anything other
	 * than the two free-band tiers to '' (no choice made).
	 *
	 * @return string '' | 'free' | 'free-plus'.
	 */
	public static function get_free_plan_choice() {
		$choice = trim( (string) Settings::get_setting( self::SETTING_FREE_PLAN_CHOICE ) );

		if ( in_array( $choice, [ self::TIER_FREE, self::TIER_FREE_PLUS ], true ) ) {
			return $choice;
		}

		return '';
	}

	/**
	 * Whether the current tier comes from the floor rather than a real subscription.
	 *
	 * Use to keep "you actually signed up for something" surfaces (achievements,
	 * checklist steps) honest: a floored site is entitled to free-plus features
	 * but has not subscribed to anything.
	 *
	 * @return bool True if the stored tier is unset and the floor is supplying it.
	 */
	public static function is_tier_floored() {
		return '' === trim( (string) Settings::get_setting( self::SETTING_SUBSCRIPTION_TIER ) );
	}

	/**
	 * Record the publisher's explicit choice between Free and Free+.
	 *
	 * Backs both the Create 3.0 plan-choice gate (Plan_Choice) and the
	 * unconnected self-serve switch in Subscription_API::handle_set_tier(). An
	 * unconnected site has the choice written straight to the stored tier's
	 * resolver — the stored tier itself is cleared so default_tier() (which now
	 * reads this choice) is back in charge. A connected site's stored tier
	 * stays as-is here; Subscription_API round-trips it through Create Studio
	 * separately and sync_subscription() mirrors the result back into this
	 * setting.
	 *
	 * Always marks the plan-choice gate satisfied (Plan_Choice::mark_choice_made()),
	 * so an admin who picks a free-band plan is never redirected back to the
	 * gate on their next screen.
	 *
	 * @param string $tier The chosen tier: GateKeeper::TIER_FREE or TIER_FREE_PLUS.
	 * @return string|false The resulting effective tier, or false if $tier is invalid.
	 */
	public static function set_free_plan_choice( $tier ) {
		if ( ! in_array( $tier, [ self::TIER_FREE, self::TIER_FREE_PLUS ], true ) ) {
			return false;
		}

		$writes = [ self::SETTING_FREE_PLAN_CHOICE => $tier ];

		if ( ! self::is_connected() ) {
			// Hand the tier back to the choice-driven floor resolver.
			$writes[ self::SETTING_SUBSCRIPTION_TIER ] = '';
		}

		self::update_subscription_settings( $writes );

		self::enforce_feature_fallbacks();

		Plan_Choice::mark_choice_made();

		return self::get_effective_tier();
	}

	/**
	 * Get the effective tier: trial-aware and normalized.
	 *
	 * During a trial the effective tier is resolve_trial_tier() (pro), so a
	 * stale 'trial' string after the trial ends can never grant access. Otherwise it
	 * is the normalized stored tier.
	 *
	 * Memoized per request: can_access() hits this on every gated check, and the
	 * underlying is_trialing + subscription_tier reads each miss the Settings
	 * cache on never-synced free sites. Cleared by reset_request_cache().
	 *
	 * @return string A canonical tier slug.
	 */
	public static function get_effective_tier() {
		if ( null !== self::$effective_tier ) {
			return self::$effective_tier;
		}

		if ( self::is_trialing() ) {
			self::$effective_tier = self::resolve_trial_tier();
		} else {
			self::$effective_tier = self::get_subscription_tier();
		}

		return self::$effective_tier;
	}

	/**
	 * Clear per-request GateKeeper memos.
	 *
	 * Call after writing subscription/trial settings outside
	 * update_subscription_setting() (tests, webhook handler), or whenever a
	 * same-request re-read must see freshly written values.
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$effective_tier = null;
	}

	/**
	 * Resolve the tier granted during an active trial.
	 *
	 * Trials are Pro Pass-equivalent and are NEVER recipe-gated; this must never
	 * return free-plus.
	 *
	 * @return string A canonical tier slug (pro).
	 */
	public static function resolve_trial_tier() {
		return self::TIER_PRO;
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
	 * Also reconciles Create 3.0's free-plan choice (Plan_Choice) with Studio:
	 * see the reconciliation and mirror steps below.
	 *
	 * @param int|null $timeout Optional status request timeout in seconds. Only
	 *                          the inline admin sync passes one; anything else
	 *                          (including the cron hook's argument) falls back
	 *                          to the client default.
	 * @return bool True on successful sync, false on failure.
	 */
	public static function sync_subscription( $timeout = null ) {
		// Use site-based status check instead of user-based endpoint.
		$status = Create_Studio_Client::check_site_status( is_int( $timeout ) ? $timeout : null );

		// Handle errors - fail open by keeping cached tier.
		if ( false === $status || empty( $status['connected'] ) ) {
			return false;
		}

		// Capture the previous tier before we overwrite it, so we can detect a
		// genuine drop to free for the settings reset. Also capture whether the
		// site was sitting on the choice-driven floor (no stored tier yet) and
		// what the publisher had locally chosen, both BEFORE this sync writes
		// anything.
		$previous_tier = self::get_subscription_tier();
		$was_paying    = self::is_trialing() || in_array( $previous_tier, self::PAID_TIERS, true );
		$was_floored   = self::is_tier_floored();
		$local_choice  = self::get_free_plan_choice();

		// Normalize the incoming tier onto a canonical slug.
		$subscription_tier = self::normalize_tier( isset( $status['subscription_tier'] ) ? $status['subscription_tier'] : '' );

		$is_trialing    = ! empty( $status['is_trialing'] );
		$trial_eligible = ! empty( $status['trial_eligible'] );

		$free_band = [ self::TIER_FREE, self::TIER_FREE_PLUS ];

		/*
		 * Connect-time reconciliation: a site that made its free-plan choice
		 * while unconnected (or a previously-connected site reconnecting) may
		 * be talking to a Studio record that disagrees with that choice -- it
		 * never heard about it, or remembers a stale one. Only reachable while
		 * floored: once a real tier is stored the choice is history, not a
		 * standing preference to keep pushing. The publisher's choice wins:
		 * push it to Studio and adopt it as this sync's incoming tier so the
		 * writes below (and the mirror) settle on it directly rather than
		 * requiring a second sync.
		 *
		 * If the push fails, nothing about the tier is settled this round: the
		 * stored tier is left unset (so the site stays floored on the
		 * publisher's choice) and the mirror below is skipped. Storing Studio's
		 * answer instead would end the floored state — no later sync would
		 * retry — and the mirror would then overwrite the choice with a plan
		 * the publisher never picked, losing it for good over a transient error.
		 */
		$push_failed = false;

		if ( $was_floored && '' !== $local_choice && in_array( $subscription_tier, $free_band, true ) && $subscription_tier !== $local_choice ) {
			$pushed = Create_Studio_Client::set_subscription_tier( $local_choice );

			if ( ! is_wp_error( $pushed ) && ! empty( $pushed['success'] ) ) {
				$subscription_tier = $local_choice;
			} else {
				$push_failed = true;
				Help::log( 'GateKeeper::sync_subscription: failed to push local free-plan choice to Create Studio; will retry on the next sync.' );
			}
		}

		// Batch every sync write into one Settings::create_settings() call so the
		// settings cache is flushed once instead of once per row.
		$writes = [
			self::SETTING_SUBSCRIPTION_TIER      => $subscription_tier,
			self::SETTING_ACTIVE_PAID_COUNT      => isset( $status['active_paid_count'] ) ? (int) $status['active_paid_count'] : 0,
			self::SETTING_TOTAL_SITE_COUNT       => isset( $status['total_site_count'] ) ? (int) $status['total_site_count'] : 1,
			self::SETTING_IS_TRIALING            => $is_trialing ? '1' : '',
			self::SETTING_TRIAL_DAYS_REMAINING   => isset( $status['trial_days_remaining'] ) ? (int) $status['trial_days_remaining'] : 0,
			self::SETTING_TRIAL_END              => isset( $status['trial_end'] ) ? $status['trial_end'] : '',
			self::SETTING_PLAN_TIER              => self::sanitize_plan_tier( isset( $status['plan_tier'] ) ? $status['plan_tier'] : '' ),
			self::SETTING_PLAN_INTERVAL          => self::sanitize_plan_interval( isset( $status['plan_interval'] ) ? $status['plan_interval'] : '' ),
			self::SETTING_TRIAL_ELIGIBLE         => $trial_eligible ? '1' : '',
			// Dunning grace (CRE-295). Studio keeps the paid tier through the
			// window, so these only drive the "update your card" nag.
			self::SETTING_PAYMENT_FAILED         => ! empty( $status['payment_failed'] ) ? '1' : '',
			self::SETTING_GRACE_UNTIL            => ! empty( $status['grace_until'] ) ? (string) $status['grace_until'] : '',
			// Pending cancellation (CS-26). The paid tier stays in force until the
			// period ends, so these only drive the "plan cancels on" notice.
			self::SETTING_CANCEL_AT_PERIOD_END   => ! empty( $status['cancel_at_period_end'] ) ? '1' : '',
			self::SETTING_CURRENT_PERIOD_END     => ! empty( $status['current_period_end'] ) ? (string) $status['current_period_end'] : '',
			self::SETTING_SUBSCRIPTION_SYNCED_AT => gmdate( 'c' ),
		];

		// An older Studio omits the flag: not eligible.
		$writes[ self::SETTING_MULTI_SITE_DISCOUNT_ELIGIBLE ] = ! empty( $status['multi_site_discount_eligible'] ) ? '1' : '';

		if ( isset( $status['trial_extensions'] ) && is_array( $status['trial_extensions'] ) ) {
			$writes[ self::SETTING_TRIAL_EXTENSIONS ] = wp_json_encode( $status['trial_extensions'] );
		}

		// Stay floored on the publisher's choice so the next sync retries the push.
		if ( $push_failed ) {
			unset( $writes[ self::SETTING_SUBSCRIPTION_TIER ] );
		}

		self::update_subscription_settings( $writes );

		// Mirror: see mirror_free_plan_choice(). Skipped when the push failed --
		// nothing about the tier is settled this round, and mirroring Studio's
		// answer would overwrite the very choice we are going to retry pushing.
		if ( ! $push_failed ) {
			self::mirror_free_plan_choice( $subscription_tier, $is_trialing, $local_choice );
		}

		self::settle_plan_choice_gate( $subscription_tier, $is_trialing, $was_paying );

		// On a genuine downgrade into free, reset gated settings; otherwise preserve.
		self::enforce_feature_fallbacks( $previous_tier );

		return true;
	}

	/**
	 * Settle the plan-choice gate after a tier change from Create Studio.
	 *
	 * Shared by sync_subscription() and the subscription_change webhook so the
	 * two paths cannot drift. Run it after mirror_free_plan_choice(): ending a
	 * plan forgets the free-plan choice that mirror just recorded.
	 *
	 * @param string $tier        Canonical incoming tier.
	 * @param bool   $is_trialing Whether the incoming state is a trial.
	 * @param bool   $was_paying  Whether the site was paying or trialing before this change.
	 * @return void
	 */
	public static function settle_plan_choice_gate( $tier, $is_trialing, $was_paying ) {
		// A paid or trialing result is itself a choice: the plan-choice gate
		// must never block (or re-block) an admin who is already paying, and a
		// stale "plan ended" flag must not outlive the plan coming back.
		if ( $is_trialing || in_array( $tier, self::PAID_TIERS, true ) ) {
			Plan_Choice::mark_choice_made();
		}

		// A paid plan or trial that has just ended: ask Free or Free+.
		if ( $was_paying && ! $is_trialing && in_array( $tier, [ self::TIER_FREE, self::TIER_FREE_PLUS ], true ) ) {
			Plan_Choice::require_choice_after_plan_ended();
		}
	}

	/**
	 * Keep the publisher's recorded free-plan choice in step with the plan
	 * Create Studio says the site is on.
	 *
	 * Shared by sync_subscription() and the `subscription_change` webhook so the
	 * two paths cannot drift: a switch made from Create Studio (Free <-> Free+)
	 * has to move the local choice whichever way it arrives, or a disconnect
	 * before the next sync floors the site on a stale choice and a reconnect
	 * pushes that stale choice back over what the publisher picked.
	 *
	 * Rules:
	 *  - Only a free-band tier (free / free-plus) is mirrored; a paid or
	 *    trialing result never touches the choice. Trialing is checked
	 *    explicitly because normalize_tier() folds the legacy 'trial' slug onto
	 *    free, which would otherwise look like a free-band result.
	 *  - Never CREATES a choice: with none recorded this is a no-op, so a
	 *    routine sync (or webhook) on a site still pending the plan-choice gate
	 *    must not record Studio's default as if the publisher had picked it.
	 *  - It does not call Plan_Choice itself. A choice change needs no gate
	 *    handling here; a plan that has just ended clears the choice afterwards
	 *    via Plan_Choice::require_choice_after_plan_ended(), so callers must
	 *    mirror BEFORE that runs, as both do.
	 *
	 * The caller owns the "skip" decisions that are not about the tier itself
	 * (e.g. sync_subscription() skips this when its push of the local choice to
	 * Studio failed).
	 *
	 * @param string      $tier         The canonical tier Studio reported.
	 * @param bool        $is_trialing  Whether Studio reported an active trial.
	 * @param string|null $local_choice The choice recorded before this update, or
	 *                                  null to read the current one.
	 * @return bool True if the recorded choice was changed.
	 */
	public static function mirror_free_plan_choice( $tier, $is_trialing = false, $local_choice = null ) {
		if ( null === $local_choice ) {
			$local_choice = self::get_free_plan_choice();
		}

		if ( $is_trialing || '' === $local_choice || ! in_array( $tier, [ self::TIER_FREE, self::TIER_FREE_PLUS ], true ) ) {
			return false;
		}

		self::update_subscription_setting( self::SETTING_FREE_PLAN_CHOICE, $tier );

		return true;
	}

	/**
	 * Reset ALL locally stored subscription + trial state to the disconnected
	 * floor (default_tier()).
	 *
	 * Called on disconnect: with the API token gone no future sync can correct
	 * stale state, so every setting sync_subscription() writes must be cleared
	 * here — a stale is_trialing flag in particular would keep the effective
	 * tier at Pro Pass forever. Callers should follow with
	 * enforce_feature_fallbacks() so the free-floor settings reset runs against
	 * the freshly cleared state.
	 *
	 * @return void
	 */
	public static function reset_subscription_state() {
		$writes = [
			// Cleared, NOT pinned to free: an unset tier hands the site back
			// to default_tier(), which resolves it from the publisher's
			// recorded free-plan choice — a disconnected site lands on
			// whichever free-band plan it chose, same as one that never
			// connected. The choice itself is deliberately left intact.
			self::SETTING_SUBSCRIPTION_TIER      => '',
			self::SETTING_ACTIVE_PAID_COUNT      => '0',
			self::SETTING_TOTAL_SITE_COUNT       => '1',
			self::SETTING_IS_TRIALING            => '',
			self::SETTING_TRIAL_DAYS_REMAINING   => '0',
			self::SETTING_TRIAL_END              => '',
			self::SETTING_PLAN_TIER              => '',
			self::SETTING_PLAN_INTERVAL          => '',
			self::SETTING_TRIAL_EXTENSIONS       => '',
			self::SETTING_TRIAL_ELIGIBLE         => '',
			self::SETTING_PAYMENT_FAILED         => '',
			self::SETTING_GRACE_UNTIL            => '',
			self::SETTING_CANCEL_AT_PERIOD_END   => '',
			self::SETTING_CURRENT_PERIOD_END     => '',
			self::SETTING_SUBSCRIPTION_SYNCED_AT => '',
		];

		$writes[ self::SETTING_MULTI_SITE_DISCOUNT_ELIGIBLE ] = '';

		self::update_subscription_settings( $writes );
	}

	/**
	 * Build the server-computed feature access map.
	 *
	 * Each feature maps to its resolved access + a reason so the admin can pick the
	 * right CTA (upgrade vs. connect) without re-deriving the logic in JS. The map
	 * covers the UNION of the tier matrix AND the connection-gated features, so the
	 * two base features (nutrition / recommended_products) always appear with a
	 * reason of 'connection' (disconnected) or 'ok' (connected).
	 *
	 * Reasons: 'ok' (accessible), 'connection' (connection-gated + disconnected),
	 * 'tier' (plain tier lock).
	 *
	 * Resolves the effective tier and connection state once, then loops with
	 * tier_allows() / the connection check directly — equivalent to can_access()
	 * per feature without re-resolving settings on every iteration.
	 *
	 * @return array<string,array{access:bool,reason:string}>
	 */
	public static function get_feature_access_map() {
		$map = [];

		$tier      = self::get_effective_tier();
		$connected = self::is_connected();

		$features = array_merge(
			array_keys( self::feature_matrix() ),
			self::$connection_gated_features
		);

		foreach ( $features as $feature ) {
			if ( self::is_connection_gated( $feature ) ) {
				// Connection-gated features are absent from the tier matrix, so
				// once connected they resolve true for every tier.
				$access = $connected;
				$reason = $access ? 'ok' : 'connection';
			} else {
				$access = self::tier_allows( $tier, $feature );
				$reason = $access ? 'ok' : 'tier';
			}

			$map[ $feature ] = [
				'access' => $access,
				'reason' => $reason,
			];
		}

		return $map;
	}

	/**
	 * Public copy of the capability matrix for the admin localization.
	 *
	 * Feature identifier => list of tier slugs that include it. Localized as
	 * `feature_matrix` in the __SUBSCRIPTION__ payload so the admin UI derives
	 * its upgrade CTAs (which tiers unlock a feature) from the same matrix PHP
	 * enforces, instead of a hand-copied JS table that can drift.
	 *
	 * @return array<string,string[]> Feature => allowed tier slugs.
	 */
	public static function get_feature_matrix_map() {
		return self::feature_matrix();
	}

	/**
	 * Forget the recorded Free / Free+ choice, so it is asked for again.
	 *
	 * @return void
	 */
	public static function clear_free_plan_choice() {
		self::update_subscription_setting( self::SETTING_FREE_PLAN_CHOICE, '' );
	}

	/**
	 * Update a single subscription-related setting.
	 *
	 * @param string $slug  Setting slug.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	private static function update_subscription_setting( $slug, $value ) {
		self::update_subscription_settings( [ $slug => $value ] );
	}

	/**
	 * Update one or more subscription-related settings with a single cache flush.
	 *
	 * @param array<string,mixed> $values Slug => value map.
	 * @return void
	 */
	private static function update_subscription_settings( array $values ) {
		$settings = [];

		foreach ( $values as $slug => $value ) {
			$settings[] = [
				'slug'  => $slug,
				'value' => $value,
				'group' => 'mv_create_subscription',
			];
		}

		Settings::create_settings( $settings );
		self::reset_request_cache();
	}

	/**
	 * Check if a feature is gated (present in the capability matrix).
	 *
	 * Features not in the matrix are ungated (available to all tiers incl. free).
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if the feature is gated.
	 */
	public static function is_feature_gated( $feature ) {
		return isset( self::feature_matrix()[ $feature ] );
	}

	/**
	 * Pure matrix lookup: does an explicit tier include a feature?
	 *
	 * No eligibility floor and no trial awareness — just the static matrix.
	 *
	 * @param string $tier    A canonical tier slug.
	 * @param string $feature The feature identifier.
	 * @return bool True if the tier includes the feature (ungated features = true).
	 */
	public static function tier_allows( $tier, $feature ) {
		if ( ! self::is_feature_gated( $feature ) ) {
			return true;
		}

		return in_array( $tier, self::feature_matrix()[ $feature ], true );
	}

	/**
	 * Whether the site is connected to Create Studio.
	 *
	 * Thin wrapper over the canonical connection check (the mv_create_api_token
	 * setting) so connection-gating has a single, intention-revealing entry point.
	 *
	 * @return bool True if the site is connected.
	 */
	public static function is_connected() {
		return Create_Studio_Client::is_site_connected();
	}

	/**
	 * Whether a feature is locked behind a Create Studio connection (not a tier).
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if the feature is connection-gated.
	 */
	public static function is_connection_gated( $feature ) {
		return in_array( $feature, self::$connection_gated_features, true );
	}

	/**
	 * Canonical "site is not connected to Create Studio" WP_Error.
	 *
	 * Single source of truth for the connection-required rejection returned by
	 * connection-gated REST endpoints (e.g. the per-card nutrition and recommended
	 * products editors and the Amazon lookup). Clients key off the response shape
	 * — status 403 and `data.error_code === 'not_connected'` — to show the Connect
	 * prompt instead of an upgrade CTA, so every gate must emit the identical
	 * shape. Pass an endpoint-specific detail via $message to explain what the
	 * connection unlocks; the top-level error message stays constant.
	 *
	 * @param string|null $message Optional endpoint-specific detail for `data.message`.
	 * @return \WP_Error 403 not_connected error with `error_code` in the data array.
	 */
	public static function connection_required_error( $message = null ) {
		$data = [ 'status' => 403 ];

		if ( null !== $message ) {
			$data['message'] = $message;
		}

		$data['error_code'] = 'not_connected';

		return new \WP_Error(
			'not_connected',
			__( 'Site is not connected to Create Studio', 'mediavine-create' ),
			$data
		);
	}

	/**
	 * Check if the current effective tier can access a feature.
	 *
	 * Connection-gated features (the base/free nutrition + recommended-products
	 * editors) are checked FIRST: locked while disconnected, then free for every
	 * tier once connected (they are intentionally absent from the tier matrix).
	 *
	 * STRICT single-column model: a feature is accessible iff the effective tier is
	 * listed for it in the matrix (connection-gated base features excepted above).
	 *
	 * @param string $feature The feature identifier.
	 * @return bool True if the feature is accessible.
	 */
	public static function can_access( $feature ) {
		// Connection gate (not a tier gate): lock the base editor while
		// disconnected; once connected these resolve true for every tier because
		// they are not in the matrix.
		if ( self::is_connection_gated( $feature ) && ! self::is_connected() ) {
			return false;
		}

		if ( ! self::is_feature_gated( $feature ) ) {
			return true;
		}

		return self::tier_allows( self::get_effective_tier(), $feature );
	}

	/**
	 * Whether the effective tier is anything other than free.
	 *
	 * Semantic helper replacing the old in_array($tier, [PRO, FREE_PLUS]) pattern.
	 *
	 * @return bool True if the effective tier is not free.
	 */
	public static function is_paid_or_free_plus() {
		return self::TIER_FREE !== self::get_effective_tier();
	}

	/**
	 * Whether the site holds a REAL (non-trial) paid or Free+ subscription.
	 *
	 * Trial-blind counterpart to is_paid_or_free_plus(): checks the STORED tier
	 * rather than the effective tier, so an active trial (stored tier free,
	 * effective tier pro) does not count. Use for surfaces that reward
	 * a genuine subscription — e.g. the Pro Publisher achievement and the
	 * checklist's Pro step — which must never unlock from a 14-day trial.
	 *
	 * Floor-blind too: a site sitting at free-plus only because of the
	 * unconnected tier floor (default_tier()) has not subscribed to anything,
	 * so it must not unlock subscription rewards either.
	 *
	 * @return bool True if the stored tier is a real, non-free subscription.
	 */
	public static function is_paid_or_free_plus_subscriber() {
		if ( self::is_tier_floored() ) {
			return false;
		}

		return self::TIER_FREE !== self::get_subscription_tier();
	}

	/**
	 * Whether the site holds a REAL (non-trial) PAID subscription.
	 *
	 * Stricter than is_paid_or_free_plus_subscriber(): free-plus is granted
	 * automatically on connecting a site to Create Studio, so it must not count
	 * as "signed up for a premium plan". Checks the STORED tier, so an active
	 * trial (stored free, effective pro) does not count either.
	 *
	 * @return bool True if the stored tier is one of the paid tiers.
	 */
	public static function is_paid_subscriber() {
		return in_array( self::get_subscription_tier(), self::PAID_TIERS, true );
	}

	/**
	 * Deprecated: whether the current tier is "pro or higher".
	 *
	 * @deprecated Use is_paid_or_free_plus().
	 * @return bool True if the effective tier is not free.
	 */
	public static function is_pro_or_higher() {
		return self::is_paid_or_free_plus();
	}

	/**
	 * Map the effective tier to a legacy-compatible slug for the broadcasts feed.
	 *
	 * The external services API filters broadcasts by the OLD slug vocabulary, so
	 * new mid-tier slugs must be mapped to the nearest slug it understands.
	 *
	 * @return string A legacy broadcasts slug ('pro' | 'free-plus' | 'free').
	 */
	public static function tier_for_broadcasts() {
		$tier = self::get_effective_tier();

		switch ( $tier ) {
			case self::TIER_FREE:
				return 'free';
			case self::TIER_FREE_PLUS:
				return 'free-plus';
			case self::TIER_PRO:
				return 'pro';
			default:
				// pro-guides / pro-lists -> treat as pro for legacy targeting.
				return 'pro';
		}
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
	 * Localized display name for a canonical tier slug.
	 *
	 * Single source of truth for PHP-side tier labels (mirrors the admin UI
	 * TIER_LABELS / tierLabel()). Display-name workshops (CRE-161) should edit
	 * this map — never hardcode tier names in gate-error copy.
	 *
	 * @param string $tier Canonical tier slug.
	 * @return string Localized display name, or the raw slug if unknown.
	 */
	public static function tier_display_name( $tier ) {
		$labels = [
			self::TIER_FREE       => __( 'Free', 'mediavine-create' ),
			self::TIER_FREE_PLUS  => __( 'Free+', 'mediavine-create' ),
			self::TIER_PRO_GUIDES => __( 'Pro Guides', 'mediavine-create' ),
			self::TIER_PRO_LISTS  => __( 'Pro Lists', 'mediavine-create' ),
			self::TIER_PRO        => __( 'Pro Pass', 'mediavine-create' ),
		];

		return isset( $labels[ $tier ] ) ? $labels[ $tier ] : (string) $tier;
	}

	/**
	 * Localized, "or"-joined list of tiers that unlock a feature.
	 *
	 * Derived from feature_matrix() so gate-error copy stays in sync with the
	 * capability matrix. Tiers are listed in canonical (cheapest-first) order.
	 *
	 * @param string $feature Feature identifier.
	 * @return string e.g. "Pro Guides or Pro Pass", or empty string if ungated.
	 */
	public static function required_tiers_label( $feature ) {
		if ( ! self::is_feature_gated( $feature ) ) {
			return '';
		}

		$unlocking = self::feature_matrix()[ $feature ];
		// Preserve canonical order from $all_tiers; drop any unexpected slugs.
		$ordered = array_values( array_intersect( self::$all_tiers, $unlocking ) );
		$labels  = array_map( [ self::class, 'tier_display_name' ], $ordered );

		return self::join_or_list( $labels );
	}

	/**
	 * Join localized items with "or" (Oxford comma for 3+).
	 *
	 * @param string[] $items Localized strings.
	 * @return string
	 */
	private static function join_or_list( array $items ) {
		$count = count( $items );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $items[0];
		}
		if ( 2 === $count ) {
			/* translators: 1: first plan name, 2: second plan name */
			return sprintf( __( '%1$s or %2$s', 'mediavine-create' ), $items[0], $items[1] );
		}

		$last = array_pop( $items );
		/* translators: 1: comma-separated plan names, 2: final plan name */
		return sprintf( __( '%1$s, or %2$s', 'mediavine-create' ), implode( ', ', $items ), $last );
	}

	/**
	 * Enforce feature fallbacks when a site has dropped to the free floor.
	 *
	 * PRESERVE-by-default: stored settings are mutated ONLY on a genuine drop to
	 * free, and every value the reset overwrites is first captured in the
	 * pre-downgrade snapshot (SETTING_PRE_DOWNGRADE_SETTINGS). For all other
	 * transitions, render-time can_access() gating handles the rest
	 * (preserve-but-ignore). On any non-free tier, snapshotted values whose
	 * bound feature the tier allows are restored (and removed from the
	 * snapshot); the rest stay snapshotted for a later, higher upgrade.
	 *
	 * Business rule: Interactive Mode is forced ON while the effective tier is
	 * free-plus — see enforce_free_plus_interactive_mode().
	 *
	 * Tier-floor interaction: an unset stored tier resolves through
	 * get_subscription_tier() (via get_effective_tier()) to whichever free-band
	 * plan the publisher chose (default_tier() / get_free_plan_choice()), so a
	 * floored free-plus choice takes the free-plus branch here — nothing in
	 * this method resets it, and the next sync can't either (a real sync
	 * writes an explicit tier that outranks the choice before this runs). The
	 * free-floor reset only fires for a site that is genuinely free: a Studio
	 * downgrade to `free`, or a publisher who chose Free via
	 * set_free_plan_choice().
	 *
	 * @param string|null $previous_tier Normalized tier before this sync.
	 *                                    Informational; restores key off the
	 *                                    persisted snapshot rather than the
	 *                                    transition, so entries left behind by
	 *                                    a partial restore still restore on a
	 *                                    later, higher upgrade.
	 * @return void
	 */
	public static function enforce_feature_fallbacks( $previous_tier = null ) {
		$effective_tier = self::get_effective_tier();

		if ( self::TIER_FREE !== $effective_tier ) {
			self::restore_pre_downgrade_settings( $effective_tier );
			self::enforce_free_plus_interactive_mode( $effective_tier );
			return; // preserve all stored values; render-gate handles the rest.
		}

		self::reset_gated_settings_to_free_floor();
	}

	/**
	 * Restore snapshotted publisher values after an upgrade away from free.
	 *
	 * Each snapshot entry is restored only once the effective tier allows its
	 * bound feature; entries the tier doesn't unlock stay in the snapshot for a
	 * later, higher upgrade. Restored entries are removed from the snapshot.
	 *
	 * Business-rule exception: on free-plus, a falsy snapshotted Interactive
	 * Mode value is neither restored nor consumed — Free+ forces the feature ON
	 * (see enforce_free_plus_interactive_mode()), but the publisher's opt-out
	 * is kept so it wins on a later restore to a PAID tier.
	 *
	 * @param string $tier The effective (non-free) tier being restored to.
	 * @return void
	 */
	private static function restore_pre_downgrade_settings( $tier ) {
		$snapshot = self::get_pre_downgrade_snapshot();

		if ( empty( $snapshot ) ) {
			return;
		}

		$prefix  = Plugin::$settings_group . '_';
		$changed = false;

		foreach ( $snapshot as $suffix => $value ) {
			// Entries for settings we no longer gate can never restore; drop them.
			if ( ! isset( self::$setting_to_feature[ $suffix ] ) ) {
				unset( $snapshot[ $suffix ] );
				$changed = true;
				continue;
			}

			list( $feature ) = self::$setting_to_feature[ $suffix ];

			if ( ! self::tier_allows( $tier, $feature ) ) {
				continue; // keep for a later, higher upgrade.
			}

			if ( self::TIER_FREE_PLUS === $tier && 'enable_interactive_mode' === $suffix && empty( $value ) ) {
				continue; // free-plus forces IM on; keep the opt-out for a paid tier.
			}

			if ( self::TIER_FREE_PLUS === $tier && 'interactive_mode_card_types' === $suffix && 'both' !== $value ) {
				continue; // free-plus forces both card types; keep the choice for a paid tier.
			}

			self::update_setting_without_trial_extension( $prefix . $suffix, $value );
			unset( $snapshot[ $suffix ] );
			$changed = true;
		}

		if ( $changed ) {
			self::save_pre_downgrade_snapshot( $snapshot );
		}
	}

	/**
	 * Enforce the free-plus Interactive Mode business rules.
	 *
	 * Free+ is a free plan subsidized by Create's own ads served through hosted
	 * Interactive Mode, so a Free+ publisher must not be able to keep it off —
	 * not via snapshot restore, not via any transition. On paid tiers the
	 * publisher's own (possibly snapshotted) choice wins.
	 *
	 * Before forcing ON, any stored opt-out is captured in the pre-downgrade
	 * snapshot: a paid -> free-plus lapse never passes through the free floor's
	 * snapshot writer (which skips floor-equal values), so this is the only
	 * place the publisher's OFF can be preserved for a later PAID-tier restore.
	 * Existing snapshot entries are never clobbered — they predate anything our
	 * own enforcement wrote since (e.g. the forced `true` a later
	 * free-plus -> free bounce floors).
	 *
	 * Uses create_settings() (upsert-safe) because a site syncing its very
	 * first non-free tier may not have this row yet, and update_setting()
	 * assumes the row already exists.
	 *
	 * @param string $tier The effective tier.
	 * @return void
	 */
	private static function enforce_free_plus_interactive_mode( $tier ) {
		if ( self::TIER_FREE_PLUS !== $tier ) {
			return;
		}

		self::force_free_plus_interactive_mode_on();
		self::force_free_plus_interactive_mode_card_types();
	}

	/**
	 * Force the Interactive Mode toggle ON for free-plus.
	 *
	 * See the dispatcher above for why (ads-subsidized plan) and how the
	 * publisher's stored opt-out survives in the pre-downgrade snapshot.
	 *
	 * @return void
	 */
	private static function force_free_plus_interactive_mode_on() {
		$slug = Plugin::$settings_group . '_enable_interactive_mode';

		if ( Settings::get_setting( $slug ) ) {
			return;
		}

		// Snapshot the publisher's stored opt-out before overwriting it, so a
		// later upgrade to a PAID tier restores their choice (the free-plus
		// restore path deliberately skips falsy Interactive Mode entries). A
		// missing row is a fresh site, not a choice — nothing to snapshot.
		$existing = Settings::get_settings( $slug );
		if ( ! empty( $existing ) ) {
			$snapshot = self::get_pre_downgrade_snapshot();
			if ( ! array_key_exists( 'enable_interactive_mode', $snapshot ) ) {
				$snapshot['enable_interactive_mode'] = isset( $existing->value ) ? $existing->value : '';
				self::save_pre_downgrade_snapshot( $snapshot );
			}
		}

		Settings::create_settings(
			[
				'slug'  => $slug,
				'value' => true,
				'group' => Plugin::$settings_group . '_interactive_mode',
			]
		);
		Settings::reset_settings();
	}

	/**
	 * Force Interactive Mode onto both card types for free-plus.
	 *
	 * Narrowing Interactive Mode to a single card type is a paid-tier control:
	 * shrinking the ad-supported surface is exactly what Free+ trades away. As
	 * with the toggle itself, the publisher's stored choice is snapshotted
	 * first so a later PAID tier restores it.
	 *
	 * @return void
	 */
	private static function force_free_plus_interactive_mode_card_types() {
		$slug     = Plugin::$settings_group . '_interactive_mode_card_types';
		$existing = Settings::get_settings( $slug );

		// A missing row is a fresh site, and 'both' is already what we want.
		if ( empty( $existing ) || ! isset( $existing->value ) || 'both' === $existing->value ) {
			return;
		}

		$snapshot = self::get_pre_downgrade_snapshot();
		if ( ! array_key_exists( 'interactive_mode_card_types', $snapshot ) ) {
			$snapshot['interactive_mode_card_types'] = $existing->value;
			self::save_pre_downgrade_snapshot( $snapshot );
		}

		Settings::create_settings(
			[
				'slug'  => $slug,
				'value' => 'both',
				'group' => Plugin::$settings_group . '_interactive_mode',
			]
		);
		Settings::reset_settings();
	}

	/**
	 * Get the pre-downgrade settings snapshot.
	 *
	 * @return array<string,mixed> Setting suffix => snapshotted value.
	 */
	public static function get_pre_downgrade_snapshot() {
		$raw = Settings::get_setting( self::SETTING_PRE_DOWNGRADE_SETTINGS );

		if ( empty( $raw ) ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Persist the pre-downgrade settings snapshot.
	 *
	 * @param array $snapshot Setting suffix => snapshotted value.
	 * @return void
	 */
	private static function save_pre_downgrade_snapshot( array $snapshot ) {
		self::update_subscription_setting( self::SETTING_PRE_DOWNGRADE_SETTINGS, wp_json_encode( $snapshot ) );
	}

	/**
	 * Reset every gated setting to its free-floor fallback, snapshotting each
	 * overwritten publisher value first so a later upgrade can restore it (see
	 * restore_pre_downgrade_settings()).
	 *
	 * Value-aware settings (card_style, list_layout) only downgrade PREMIUM
	 * stored values — a deliberate free choice (e.g. the free 'hero' layout) is
	 * preserved — and each premium value maps to its closest free equivalent
	 * via setting_value_downgrades().
	 *
	 * @return void
	 */
	private static function reset_gated_settings_to_free_floor() {
		$prefix     = Plugin::$settings_group . '_';
		$downgrades = self::setting_value_downgrades();
		$snapshot   = self::get_pre_downgrade_snapshot();
		$captured   = false;

		foreach ( self::$setting_to_feature as $suffix => $config ) {
			list( $feature, $floor_value ) = $config;

			// free never includes any gated feature, so every mapped setting resets,
			// but the tier_allows() guard keeps this correct if the matrix changes.
			if ( self::tier_allows( self::TIER_FREE, $feature ) ) {
				continue;
			}

			// No stored row means nothing to reset (or snapshot); the registered
			// default seeds later and update_setting() needs an existing row.
			$setting = Settings::get_settings( $prefix . $suffix );
			if ( empty( $setting ) ) {
				continue;
			}

			$current = isset( $setting->value ) ? $setting->value : null;

			// Value-aware settings: free stored values stay; premium values map
			// to their closest free equivalent.
			if ( isset( $downgrades[ $suffix ] ) ) {
				if ( ! isset( $downgrades[ $suffix ][ $current ] ) ) {
					continue;
				}
				$floor_value = $downgrades[ $suffix ][ $current ];
			}

			// Already at the floor: nothing to change, nothing to snapshot.
			if ( $current === $floor_value || ( empty( $current ) && empty( $floor_value ) ) ) {
				continue;
			}

			// Snapshot the publisher's value so a later upgrade can restore it.
			// Never clobber an earlier entry — it predates anything our own
			// enforcement wrote since (e.g. the free-plus forced Interactive Mode).
			if ( ! array_key_exists( $suffix, $snapshot ) ) {
				$snapshot[ $suffix ] = $current;
				$captured            = true;
			}

			self::update_setting_without_trial_extension( $prefix . $suffix, $floor_value );
		}

		if ( $captured ) {
			self::save_pre_downgrade_snapshot( $snapshot );
		}
	}

	/**
	 * Update a setting without firing the trial auto-extension hook.
	 *
	 * Downgrade-driven writes must not spuriously extend a trial. The trial
	 * extension listens on the REST-fired mv_create_setting_updated_{slug} action;
	 * Settings::update_setting() does not fire it, but we belt-and-suspenders detach
	 * the listener around the write in case that ever changes.
	 *
	 * @param string $slug  The full setting slug.
	 * @param mixed  $value The value to store.
	 * @return void
	 */
	private static function update_setting_without_trial_extension( $slug, $value ) {
		$hook = 'mv_create_setting_updated_' . $slug;
		$had  = has_action( $hook, [ __CLASS__, 'maybe_extend_trial_on_setting' ] );

		if ( false !== $had ) {
			remove_action( $hook, [ __CLASS__, 'maybe_extend_trial_on_setting' ], $had );
		}

		Settings::update_setting( $slug, $value );

		if ( false !== $had ) {
			add_action( $hook, [ __CLASS__, 'maybe_extend_trial_on_setting' ], $had );
		}
	}

	/**
	 * Get list of all gated features (the matrix feature keys).
	 *
	 * @return array Array of feature identifier constants.
	 */
	public static function get_gated_features() {
		return array_keys( self::feature_matrix() );
	}

	/**
	 * Get the fallback value for a gated feature.
	 *
	 * For example, gated themes fall back to the 'big-image' theme. Resolved from
	 * the setting->feature map so there is a single source of fallback defaults.
	 *
	 * @param string $feature The feature identifier.
	 * @return mixed|null The fallback value, or null if no fallback defined.
	 */
	public static function get_fallback( $feature ) {
		// Per-feature render fallbacks (e.g. premium list layouts) win first.
		if ( isset( self::$feature_fallbacks[ $feature ] ) ) {
			return self::$feature_fallbacks[ $feature ];
		}

		foreach ( self::$setting_to_feature as $config ) {
			if ( $feature === $config[0] ) {
				return $config[1];
			}
		}

		return null;
	}

	/**
	 * Check if the current site can access a given premium list layout (from #5315).
	 *
	 * Non-premium layouts are always accessible. Premium layouts resolve through
	 * the tier matrix via can_access().
	 *
	 * @param string $layout The list layout slug.
	 * @return bool True if the site can render the layout.
	 */
	public static function can_access_list_layout( $layout ) {
		if ( ! isset( self::$gated_list_layouts[ $layout ] ) ) {
			return true;
		}

		return self::can_access( self::$gated_list_layouts[ $layout ] );
	}

	/**
	 * Resolve a list layout to one the current site can actually render (from #5315).
	 *
	 * If the requested layout is premium and the site can't access it, the gated
	 * layout's free fallback is returned (defaulting to 'numbered'). Otherwise the
	 * requested layout is returned unchanged.
	 *
	 * @param string $layout The requested list layout slug.
	 * @return string The accessible layout slug.
	 */
	public static function get_accessible_list_layout( $layout ) {
		if ( self::can_access_list_layout( $layout ) ) {
			return $layout;
		}

		$fallback = self::get_fallback( self::$gated_list_layouts[ $layout ] );

		return $fallback ? $fallback : 'numbered';
	}

	/**
	 * Premium card theme slugs mapped to their gating feature.
	 *
	 * @return array<string,string>
	 */
	public static function get_gated_themes() {
		return self::$gated_themes;
	}

	/**
	 * The gating feature for a card theme, or null when the theme is free.
	 *
	 * Lets declaration sites (e.g. the card_style setting's option list) label a
	 * theme's gate from the same map the runtime gates read.
	 *
	 * @param string $theme The card theme (style) slug.
	 * @return string|null The gating feature identifier, or null if ungated.
	 */
	public static function theme_feature( $theme ) {
		return isset( self::$gated_themes[ $theme ] ) ? self::$gated_themes[ $theme ] : null;
	}

	/**
	 * Check if the current site can access a given premium card theme.
	 *
	 * Free themes are always accessible; premium themes resolve through the tier
	 * matrix via can_access(). Mirrors can_access_list_layout().
	 *
	 * @param string $theme The card theme (style) slug.
	 * @return bool True if the site can render the theme.
	 */
	public static function can_access_theme( $theme ) {
		if ( ! isset( self::$gated_themes[ $theme ] ) ) {
			return true;
		}

		return self::can_access( self::$gated_themes[ $theme ] );
	}

	/**
	 * Resolve a card theme to one the current site can actually render.
	 *
	 * If the requested theme is premium and the site can't access it, the theme's
	 * declared free fallback is returned (from the Creations_Views_Themes
	 * registry). Otherwise the requested theme is returned unchanged. Mirrors
	 * get_accessible_list_layout().
	 *
	 * @param string $theme The requested card theme (style) slug.
	 * @return string The accessible theme slug.
	 */
	public static function get_accessible_theme( $theme ) {
		if ( self::can_access_theme( $theme ) ) {
			return $theme;
		}

		return Creations_Views_Themes::get_fallback_style( $theme );
	}

	/**
	 * Check if the site is currently in a Pro trial.
	 *
	 * Defensive expiry: a stored trial_end in the past means the trial is over
	 * even if the is_trialing flag is stale (e.g. every sync since it was set
	 * has failed). With no trial_end stored the flag alone is trusted.
	 *
	 * @return bool True if the site is trialing.
	 */
	public static function is_trialing() {
		if ( empty( Settings::get_setting( self::SETTING_IS_TRIALING ) ) ) {
			return false;
		}

		$trial_end = self::get_trial_end();

		if ( '' !== $trial_end ) {
			$end_timestamp = strtotime( $trial_end );

			if ( false !== $end_timestamp && time() >= $end_timestamp ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whole trial days left. The ONE place PHP counts them: the admin-bar
	 * pill, the Dashboard card, the subscription API and the admin's
	 * useGateKeeper().trialDaysRemaining (via calcTrialDaysRemaining() in
	 * helpers/trial.ts, the same rule) must all agree.
	 *
	 * Counted from the stored trial_end so it keeps ticking between syncs,
	 * falling back to Studio's synced counter when no end date is stored.
	 *
	 * @return int Days remaining in the trial, or 0 if not trialing.
	 */
	public static function get_trial_days_remaining() {
		if ( ! self::is_trialing() ) {
			return 0;
		}

		$end = self::get_trial_end();
		if ( '' === $end || false === strtotime( $end ) ) {
			return max( 0, (int) Settings::get_setting( self::SETTING_TRIAL_DAYS_REMAINING ) );
		}

		return self::calc_trial_days_remaining( $end );
	}

	/**
	 * Whole days until $trial_end. See calc_days_remaining().
	 *
	 * @param string   $trial_end ISO 8601 end date.
	 * @param int|null $now       Unix time; defaults to now.
	 * @return int
	 */
	public static function calc_trial_days_remaining( $trial_end, $now = null ) {
		return self::calc_days_remaining( $trial_end, $now );
	}

	/**
	 * Whole days until $end, exactly as Create Studio counts them
	 * (@create-studio/shared calcDaysRemaining): floor, but never 0 while any
	 * time remains; 0 once it has passed or when unparseable. The ONE rule for
	 * every user-facing "N days left" (trial, dunning grace), mirrored in the
	 * admin by helpers/trial.ts calcDaysRemaining().
	 *
	 * @param string   $end ISO 8601 date.
	 * @param int|null $now Unix time; defaults to now.
	 * @return int
	 */
	public static function calc_days_remaining( $end, $now = null ) {
		$end_ts = is_string( $end ) && '' !== $end ? strtotime( $end ) : false;
		if ( false === $end_ts ) {
			return 0;
		}

		$remaining = $end_ts - ( null === $now ? time() : (int) $now );
		if ( $remaining <= 0 ) {
			return 0;
		}

		return max( 1, (int) floor( $remaining / DAY_IN_SECONDS ) );
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
	 * The trial end date in the site's date format, or '' when not trialing
	 * or no end date is stored.
	 *
	 * @return string
	 */
	public static function get_trial_end_display() {
		if ( ! self::is_trialing() ) {
			return '';
		}

		$end = strtotime( self::get_trial_end() );

		return false === $end ? '' : (string) wp_date( get_option( 'date_format' ), $end );
	}

	/**
	 * Display name of the plan being trialed ("Pro Guides"). The effective
	 * tier is Pro Pass-equivalent during any trial, so this reads the plan the
	 * trial converts to, defaulting to Pro Pass.
	 *
	 * @return string
	 */
	public static function get_trial_plan_name() {
		$plan = self::sanitize_plan_tier( Settings::get_setting( self::SETTING_PLAN_TIER ) );
		$paid = [ self::TIER_PRO_GUIDES, self::TIER_PRO_LISTS, self::TIER_PRO ];

		return self::tier_display_name( in_array( $plan, $paid, true ) ? $plan : self::TIER_PRO );
	}

	/**
	 * Whether a renewal payment failed and the site is inside Studio's dunning
	 * grace window (CRE-295 / create-studio CS-27).
	 *
	 * This is NOT a downgrade signal — Studio deliberately keeps the paid tier
	 * while Stripe retries the card, so every gate stays open and only the
	 * "update your card" nag changes. Terminal `unpaid`/`canceled` are what
	 * actually drop the tier, and they arrive as an ordinary tier change.
	 *
	 * Defensive expiry mirrors is_trialing(): a stored grace_until in the past
	 * means the window closed even if the flag is stale because every sync
	 * since has failed. With no grace_until stored the flag alone is trusted.
	 *
	 * @return bool True while the site should be nagged to fix its card.
	 */
	public static function is_payment_failed() {
		if ( empty( Settings::get_setting( self::SETTING_PAYMENT_FAILED ) ) ) {
			return false;
		}

		$grace_until = self::get_grace_until();

		if ( '' !== $grace_until ) {
			$end_timestamp = strtotime( $grace_until );

			if ( false !== $end_timestamp && time() >= $end_timestamp ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the dunning grace deadline.
	 *
	 * @return string ISO 8601 deadline, or empty string when not in grace.
	 */
	public static function get_grace_until() {
		return (string) Settings::get_setting( self::SETTING_GRACE_UNTIL );
	}

	/**
	 * Whether the subscriber has cancelled and the paid plan is about to end.
	 *
	 * Like the dunning grace state this is NOT a downgrade signal: the paid tier
	 * stays in force until the period ends, then Studio drops it with an
	 * ordinary tier change. This only drives the "your plan cancels on <date>"
	 * heads-up, so it needs all of: the flag, a paid tier, and a stored period
	 * end still in the future.
	 *
	 * Defensive expiry mirrors is_payment_failed(): a period end in the past
	 * means the plan already ended even if the flag is stale because every sync
	 * since has failed. Unlike the dunning flag, a missing period end is NOT
	 * trusted: without a date there is nothing to tell the publisher.
	 *
	 * @return bool True while a cancellation is scheduled for a future date.
	 */
	public static function is_cancel_pending() {
		if ( empty( Settings::get_setting( self::SETTING_CANCEL_AT_PERIOD_END ) ) ) {
			return false;
		}

		if ( ! in_array( self::get_effective_tier(), self::PAID_TIERS, true ) ) {
			return false;
		}

		$end_timestamp = strtotime( self::get_cancel_date() );

		return false !== $end_timestamp && time() < $end_timestamp;
	}

	/**
	 * Get the date a pending cancellation takes effect (the current period end).
	 *
	 * @return string ISO 8601 date, or empty string when none is stored.
	 */
	public static function get_cancel_date() {
		return (string) Settings::get_setting( self::SETTING_CURRENT_PERIOD_END );
	}

	/**
	 * The cancel date as the publisher reads it: the site's date format and
	 * timezone.
	 *
	 * ONE definition shared by the wp-admin notice and the React admin's
	 * localized payload, so both always print the same string.
	 *
	 * wp_date() (not date_i18n()) because the stored value is a real UTC
	 * instant: date_i18n() would print its UTC wall-clock date, which can be a
	 * day off for the site's timezone.
	 *
	 * @return string Formatted date, or empty string unless a cancellation is pending.
	 */
	public static function get_cancel_date_display() {
		if ( ! self::is_cancel_pending() ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ), strtotime( self::get_cancel_date() ) );
	}

	/**
	 * Whole days left in the dunning grace window.
	 *
	 * Computed from the stored deadline rather than a synced counter so the
	 * number keeps counting down between syncs instead of freezing at whatever
	 * Studio last reported. Same rule as every other day count
	 * (calc_days_remaining()): the final partial day reads "1 day left".
	 *
	 * @return int Days remaining, or 0 when not in grace.
	 */
	public static function get_grace_days_remaining() {
		if ( ! self::is_payment_failed() ) {
			return 0;
		}

		return self::calc_days_remaining( self::get_grace_until() );
	}

	/**
	 * Get the Create Studio billing URL for this site.
	 *
	 * Studio's /admin/billing is its pass-through to the Stripe billing portal:
	 * a signed-in publisher goes straight to the portal (pay the failed invoice,
	 * change the card, reactivate a cancelling plan), and a signed-out one logs
	 * in first and is sent on. It is the page the Settings "Manage billing"
	 * action opens; this is the same destination as a plain link, without the
	 * SSO hop that action needs a linked account for. It used to point at
	 * Studio's site settings, which opened on the General tab with nothing
	 * about billing on it.
	 *
	 * @return string The full URL to Create Studio's billing pass-through.
	 */
	public static function get_billing_url() {
		$url = Plugin::$create_studio_base_url . '/admin/billing?site_url=' . rawurlencode( home_url() );

		// Without SSO (a plain link) Studio shows its login: prefill it with this
		// user's own hint (see Site_Verification::get_studio_email_hint()).
		$email = Site_Verification::get_studio_email_hint();
		if ( '' !== $email ) {
			$url .= '&email=' . rawurlencode( $email );
		}

		return $url;
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
