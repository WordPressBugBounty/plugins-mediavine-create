<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;
use Mediavine\Settings;
use Mediavine\Create\Importers\Importers;

/**
 * Plugin bootstrap class
 */
class Plugin {
	const VERSION = '3.1.0';

	const DB_VERSION = '2.6.0';

	const TEXT_DOMAIN = 'mediavine-create';

	const PLUGIN_DOMAIN = 'mv_create';

	const PLUGIN_FILE_PATH = __FILE__;

	const PLUGIN_ACTIVATION_FILE = 'mediavine-create.php';

	const REQUIRED_IMPORTER_VERSION = '0.10.3';

	public $api_route = 'mv-create';

	public $api_version = 'v1';

	public static $services_api_url = 'https://create.studio/api/v2';
	
	// Important: keep the trailing slash
	public static $js_services_api_url = 'https://create.studio/api/v2/';

	public static $create_studio_base_url = 'https://create.studio';

	public static $views = null;

	public static $api_services = null;

	public static $models = null;

	public static $models_v2 = null;

	public static $custom_content = null;

	public static $settings = null;

	public static $settings_group = 'mv_create';

	public static $shapes = null;

	public static $create_settings_slugs = [
		'mv_create_affiliate_message',
		'mv_create_copyright_attribution',
	];

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Bootstrap the plugin.
	 */
	public function init() {
		self::$models = new \stdClass();

		Plugin_Context::set_mcp_status();

		// initialize Admin_Notices
		\Mediavine\Create\Admin_Notices::get_instance();

		// Initialize the Create 3.0 plan-choice gate (blocks the free-band admin
		// UI until an explicit choice is made) + "what's new" landing page.
		\Mediavine\Create\Plan_Choice::get_instance();

		// Initialize Broadcast Notice only when connected (connecting = consent to Studio contact).
		if ( \Mediavine\Create\Create_Studio_Client::is_site_connected() ) {
			\Mediavine\Create\Broadcast_Notice::get_instance();
		}

		// Initialize Admin Bar (adds quick edit links for Create cards)
		\Mediavine\Create\Admin_Bar::get_instance();

		// Before any subsystem captures the Studio URLs...
		Plugin_Context::resolve_studio_urls();
		// ...and again after every plugin has loaded, since a dev-tools plugin
		// that loads after Create would miss the first pass.
		add_action( 'plugins_loaded', [ Plugin_Context::class, 'resolve_studio_urls' ], 999 );

		self::$views        = \Mediavine\View_Loader::get_instance( MV_CREATE_DIR );
		self::$api_services = \Mediavine\Create\API_Services::get_instance();
		\Mediavine\Cache_Manager::init();
		\Mediavine\Create\REST_Redirect_Guard::init();
		self::$models_v2    = \Mediavine\MV_DBI::get_models(
			[
				'mv_images',
				'mv_nutrition',
				'mv_products',
				'mv_products_map',
				'mv_reviews',
				'mv_reviews_responses',
				'mv_creations',
				'mv_supplies',
				'mv_relations',
				'mv_revisions',
				'posts',
			]
		);

		// Register feature flags early.
		add_action( 'after_setup_theme', '\Mediavine\Create\register_flags' );

		Custom_Fields::register();

		// Register default Create settings for Creation published data
		add_filter(
			'mv_publish_create_settings', function ( $arr ) {
			// Get the authenticated user to assign the copyright attribution if none has been set in settings.
			$user = wp_get_current_user();
			$arr[ \Mediavine\Create\Plugin::$settings_group . '_copyright_attribution' ] = $user->display_name;

			// Assign the default settings. These can be overwritten by using this filter.
			foreach ( \Mediavine\Create\Plugin::$create_settings_slugs as $slug ) {
				$setting = \Mediavine\Settings::get_setting( $slug );
				if ( $setting ) {
					$arr[ $slug ] = $setting;
				}
			}
			return $arr;
			}
		);

		self::$custom_content = Custom_Content::make( 'mv-create', 'Create' );

		// Connect GateKeeper subscription tier to mv_create_is_pro filter.
		// True for any non-basic effective tier (kept semantically stable for 3rd parties).
		add_filter( 'mv_create_is_pro', [ 'Mediavine\Create\GateKeeper', 'is_paid_or_free_plus' ] );

		// Expose the granular effective tier slug to 3rd parties.
		add_filter(
			'mv_create_effective_tier', function () {
				return \Mediavine\Create\GateKeeper::get_effective_tier();
			}
		);

		register_activation_hook( Plugin_Context::get_activation_path(), [ Upgrader::class, 'plugin_activation' ] );
		register_deactivation_hook( Plugin_Context::get_activation_path(), [ Upgrader::class, 'plugin_deactivation' ] );

		add_filter(
			'mv_wp_router_config', function( $config ) {
				$config->set(
					'api', [
						'namespace'            => 'mv-create',
						'version'              => 'v1',
						'controller_namespace' => 'Mediavine\\Create\\Controllers\\',
					]
				);
			return $config;
			}
		);

		// Run upgrade check at init. Translations are auto-loaded by WordPress
		// 4.6+ for wp.org-hosted plugins since the text domain matches the slug.
		add_action( 'init', [ Upgrader::class, 'plugin_update_check' ], 1 );
		add_action( 'init', [ $this, 'init_translatable_data' ], 2 );

		// Activations hooks, forcing order
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'generate_tables' ], 20 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'delete_old_settings' ], 21 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'migrate_reader_experience_settings' ], 25 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'migrate_interactive_mode_settings' ], 26 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'migrate_card_style_preview_images' ], 27 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'migrate_hands_free_to_reader_experience' ], 28 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'migrate_subscription_tier_to_canonical' ], 29 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'create_settings' ], 30 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'create_shapes' ], 35 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'republish_queue' ], 40 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'repair_stale_total_times' ], 41 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'importer_admin_notice' ], 60 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'update_services_api' ], 95 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Upgrader::class, 'purge_used_css_caches_for_widget_safelist' ], 100 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ Image_Metadata_Backfill::class, 'schedule' ], 105 );

		// Fixes
		add_action( Image_Metadata_Backfill::HOOK, [ Image_Metadata_Backfill::class, 'run' ] );

		// Shortcodes
		add_shortcode( 'mv_img', [ Shortcodes::class, 'mv_img_shortcode' ] );
		add_shortcode( 'mvc_ad', [ Shortcodes::class, 'mvc_ad_shortcode' ] );
		add_shortcode( 'mv_schema_meta', [ Shortcodes::class, 'mv_schema_meta_shortcode' ] );

		// For pubs that were beta-testing Indexes — hides shortcode output
		add_shortcode( 'mv_index', '__return_false' );

		add_filter( 'rest_prepare_post', [ REST_Post_Fields::class, 'rest_prepare_post' ], 10, 3 );

		add_filter( 'mv_create_paapi_tag_settings_value', 'trim', 10 );
		add_filter( 'mv_create_creators_credential_id_settings_value', 'trim', 10 );
		add_filter( 'mv_create_creators_credential_secret_settings_value', 'trim', 10 );
		add_filter( 'mv_create_localized_admin_settings', [ Settings_Registration::class, 'set_custom_post_type_option_value' ], 10 );
		$Images = new Images();
		$Images->init();

		$Settings = new \Mediavine\Settings();
		$Settings->init();

		$Nutrition = new Nutrition();
		$Nutrition->init();

		$Products = new Products();
		$Products->init();

		$Products_Map = new Products_Map();
		$Products_Map->init();

		$Relations = new Relations();
		$Relations->init();

		$Reviews_Models = new Reviews_Models();
		$Reviews_Models->init();

		$Reviews_API = new Reviews_API();
		$Reviews_API->init();

		$Reviews = new Reviews();
		$Reviews->init();

		$Featured_Review = new Featured_Review();
		$Featured_Review->init();

		$Featured_Review_API = Featured_Review_API::get_instance();
		$Featured_Review_API->init();

		$Featured_Review_Block = new Featured_Review_Block();
		$Featured_Review_Block->init();

		Shapes::get_instance();
		Creations::get_instance();
		Supplies::get_instance();

		Revisions::get_instance();

		JSON_LD::get_instance();

		$Dashboard_API = new Dashboard_API();
		$Dashboard_API->init();

		$Admin_Init = new Admin_Init();
		$Admin_Init->init();

		$Site_Verification = new Site_Verification();
		$Site_Verification->init();

		$User_Verification = new User_Verification();
		$User_Verification->init();

		// Initialize GateKeeper for subscription-based feature gating
		GateKeeper::init();

		// Initialize webhook handler for Create Studio → plugin communication.
		Webhook_Handler::init();

		// Initialize Feedback API for error reporting to Create Studio.
		Feedback_API::init();

		// Initialize Trial API for trial extension proxy.
		Trial_API::init();

		// Initialize Subscription API for user-invoked subscription sync.
		Subscription_API::init();

		// Initialize Bulk Scrape API for list bulk import feature
		$Bulk_Scrape_API = new Bulk_Scrape_API();
		$Bulk_Scrape_API->init();

		// Initialize Recipe Importers feature
		$Importers = Importers::get_instance();
		$Importers->init();

		Plugin_Checker::get_instance();
		Theme_Checker::get_instance();

		// Initialize unit conversion. It registers its REST route and cache
		// invalidation hook on every install; Pro-tier access is enforced at
		// runtime by GateKeeper (the mv_create_is_pro filter registered above
		// plus GateKeeper::can_access checks at the REST and output layers).
		Unit_Conversion::get_instance()->init();
	}

	/**
	 * Forwarder kept for theme overrides of the v1 instruction/notes templates,
	 * which call this directly.
	 *
	 * @deprecated 3.0.0 Use Shortcodes::unfurl_media_urls().
	 *
	 * @param string $html Instruction/notes HTML.
	 * @return string
	 */
	public static function unfurl_media_urls( $html ) {
		return Shortcodes::unfurl_media_urls( $html );
	}

	/**
	 * Whether or not this instance of the plugin is Pro.
	 *
	 * @return bool
	 */
	public static function is_pro(): bool {
		return (bool) apply_filters( 'mv_create_is_pro', false );
	}

	/**
	 * Initialize data that requires translated strings.
	 * Hooked to 'init' so the textdomain is loaded first.
	 */
	public function init_translatable_data() {
		self::$settings = Settings_Registration::get_settings();
		self::$shapes   = Shapes::get_shapes_data();
	}

}
