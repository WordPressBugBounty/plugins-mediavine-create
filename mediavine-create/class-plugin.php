<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;
use Mediavine\Create\Settings\Advanced;
use Mediavine\Create\Settings\Affiliates;
use Mediavine\Create\Settings\Control_Panel;
use Mediavine\Create\Settings\Dev;
use Mediavine\Create\Settings\Appearance;
use Mediavine\Create\Settings\List_Ads;
use Mediavine\Create\Settings\Lists;
use Mediavine\Create\Settings\Reader_Experience;
use Mediavine\Create\Settings\Recipes;
use Mediavine\Settings;
use Mediavine\Create\Importers\Importers;

/**
 * Plugin bootstrap class
 */
class Plugin {
	const VERSION = '2.1.1';

	const DB_VERSION = '2.1.1';

	const TEXT_DOMAIN = 'mediavine';

	const PLUGIN_DOMAIN = 'mv_create';

	const PREFIX = '_mv_';

	const PLUGIN_FILE_PATH = __FILE__;

	const PLUGIN_ACTIVATION_FILE = 'mediavine-create.php';

	const REQUIRED_IMPORTER_VERSION = '0.10.3';

	public $api_route = 'mv-create';

	public $api_version = 'v1';

	public static $services_api_url = 'https://create.studio/api/v2';
	
	// Important: keep the trailing slash
	public static $js_services_api_url = 'https://create.studio/api/v2/';

	public static $create_studio_base_url = 'https://create.studio';

	public static $db_interface = null;

	public static $views = null;

	public static $api_services = null;

	public static $models = null;

	public static $models_v2 = null;

	public static $custom_content = null;

	public static $settings = null;

	public static $settings_group = 'mv_create';

	public static $shapes = null;

	public static $mcp_enabled = false;

	public static $create_settings_slugs = [
		'mv_create_affiliate_message',
		'mv_create_copyright_attribution',
	];

	public $rest_response = null;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	public static function assets_url() {
			return MV_CREATE_URL;
	}

	/**
	 * Get MCP site id if it exists
	 *
	 * @return  string|false  Site id if it exists and MCP is active, or false
	 */
	public static function get_mcp_data() {
		$mcp_data = false;
		if ( self::$mcp_enabled ) {
			// TODO: Check if video support exists and is authorized with identity service
			$mcp_data = [
				'site_id' => get_option( 'MVCP_site_id' ),
				'version' => get_option( 'mv_mcp_version', null ),
			];
		}

		return $mcp_data;
	}

	/**
	 * Return image size value and label
	 *
	 * @return array
	 */
	public static function get_image_size_values() {
		$image_sizes                   = Creations::get_image_sizes();
		$image_sizes_values_and_labels = [];

		// @TODO add a filter for adding size names to exclude from disable list?
		$exclude = [
			'mv_create_no_ratio',
			'mv_create_vert',
		];

		foreach ( $image_sizes as $size => $size_data ) {
			if ( strpos( $size, '_high_res' ) || in_array( $size, $exclude, true ) ) {
				continue;
			}

			$image_sizes_values_and_labels[ $size ] = $size_data['name'];
		}

		return $image_sizes_values_and_labels;
	}

	/**
	 * Modify setting array to include custom post types
	 *
	 * @param array $settings array of current settings to be modified with addition of custom post types
	 *
	 * @return array
	 */
	public function set_custom_post_type_option_value( $settings ) {
		$allowed_post_types = $this->get_custom_post_types();

		$cpt_field = array_search( self::$settings_group . '_allowed_cpt_types', wp_list_pluck( $settings, 'slug' ), true );

		if ( empty( $allowed_post_types ) ) {
			$settings[ $cpt_field ]->value = []; // reset value
			return $settings;
		}

		if ( ! empty( $settings[ $cpt_field ] ) ) {
			// two things need to happen:
			// 1. CPTs that no longer exist need to be removed as a saved value
			// 2. New CPTs need to be added to options but NOT to saved values — this should already be handled by $this->get_custom_post_types()

			$values        = array_keys( $allowed_post_types );
			$stored_values = json_decode($settings[ $cpt_field ]->value ?: '{}');

			if ( ! is_array( $stored_values ) ) {
				return $settings;
			}

			$keys_to_remove = array_diff( $stored_values, $values ); // CPTs that were removed

			// remove invalid CPTs from saved values
			$new_values = array_filter(
				$stored_values, function ( $i ) use ( $keys_to_remove ) {
				return ! in_array( $i, $keys_to_remove, true );
				}
			);

			$settings[ $cpt_field ]->data['options'] = $allowed_post_types;
			$settings[ $cpt_field ]->value           = json_encode( array_values( $new_values ) );
		}

		return $settings;
	}

	public static function get_activation_path() {
		return MV_CREATE_DIR . '/' . self::PLUGIN_ACTIVATION_FILE;
	}

	public function load_models() {
		$models_loader = new \stdClass();

		return $models_loader;
	}

	/**
	 * Runs hook at plugin activation.
	 *
	 * The update hook will run a bit later through its own hook
	 *
	 * @return void
	 */
	public function plugin_activation() {
		do_action( self::PLUGIN_DOMAIN . '_plugin_activated' );
	}

	/**
	 * Runs hook at plugin update.
	 *
	 * This runs after all plugins are loaded so it can run after update. It also performs a
	 * check based on version number, just in case someone updates in a non-conventional way.
	 * After completing hooks, Create version number is updated in the db.
	 *
	 * @return void
	 */
	public function plugin_update_check() {
		if ( get_option( 'mv_create_version' ) === self::VERSION ) {
			return;
		}

		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		/**
		 * Runs just before the plugin saves its new version to the database.
		 *
		 * @param $last_plugin_version The last version the plugin was on. If this is a new
		 *              install, the last plugin version will be the current version.
		 */
		do_action( self::PLUGIN_DOMAIN . '_plugin_updated', $last_plugin_version );

		update_option( 'mv_create_version', self::VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs hook at plugin deactivation and flushes rewrite rules.
	 *
	 * @return void
	 */
	public function plugin_deactivation() {
		do_action( self::PLUGIN_DOMAIN . '_plugin_deactivated' );
		flush_rewrite_rules();
	}

	public function generate_tables() {
		\Mediavine\MV_DBI::upgrade_database_check( self::PLUGIN_DOMAIN, self::DB_VERSION );
	}

	/**
	 * Determine whether Mediavine Control Panel is enabled.
	 */
	public function set_mcp_status() {
		if (
			(
				Plugin_Checker::is_mcp_active()
			) && get_option( 'MVCP_site_id' )
		) {
			self::$mcp_enabled = true;
		}
	}

	/**
	 * Bootstrap the plugin.
	 */
	public function init() {
		self::$models = new \stdClass();

		$this->set_mcp_status();

		// initialize Admin_Notices
		\Mediavine\Create\Admin_Notices::get_instance();

		// Initialize Welcome Notice (handles 2.0 upgrade welcome screen)
		\Mediavine\Create\Welcome_Notice::get_instance();

		// Initialize Broadcast Notice (shows Studio broadcast banners in WP admin)
		\Mediavine\Create\Broadcast_Notice::get_instance();

		// Initialize Admin Bar (adds quick edit links for Create cards)
		\Mediavine\Create\Admin_Bar::get_instance();

		$dev_mode = json_decode( get_option( 'mediavine_devmode', '[]' ), true );
		if ( isset( $dev_mode['create'] ) && $dev_mode['create'] === 'on' ) {
			self::$services_api_url = 'https://cs.test/api/v2';
			// Important: keep the trailing slash
			self::$js_services_api_url = 'https://cs.test/api/v2/';
			self::$create_studio_base_url = 'https://cs.test';
		}

		self::$views        = \Mediavine\View_Loader::get_instance( MV_CREATE_DIR );
		self::$api_services = \Mediavine\Create\API_Services::get_instance();
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

		$this->register_custom_fields();

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

		// Connect GateKeeper subscription tier to mv_create_is_pro filter
		add_filter( 'mv_create_is_pro', [ 'Mediavine\Create\GateKeeper', 'is_pro_or_higher' ] );

		register_activation_hook( self::get_activation_path(), [ $this, 'plugin_activation' ] );
		register_deactivation_hook( self::get_activation_path(), [ $this, 'plugin_deactivation' ] );

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

		// Load translations and run upgrade check at init. The upgrade hooks
		// (create_settings, etc.) use __() so textdomain must load first.
		add_action( 'init', 'mv_create_load_plugin_textdomain', 0 );
		add_action( 'init', [ $this, 'plugin_update_check' ], 1 );
		add_action( 'init', [ $this, 'init_translatable_data' ], 2 );

		// Activations hooks, forcing order
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'generate_tables' ], 20 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'delete_old_settings' ], 21 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'migrate_reader_experience_settings' ], 25 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'migrate_interactive_mode_settings' ], 26 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'migrate_card_style_preview_images' ], 27 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'migrate_hands_free_to_reader_experience' ], 28 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'create_settings' ], 30 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'create_shapes' ], 35 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'republish_queue' ], 40 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'update_reviews_table' ], 50 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'importer_admin_notice' ], 60 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'fix_cloned_ratings' ], 70 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'fix_cookbook_canonical_post_ids' ], 80 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'add_initial_revision_to_cards' ], 85 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'queue_existing_amazon_products' ], 90 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'update_services_api' ], 95 );

		// Fixes
		add_action( 'mv_fix_video_description_queue_action', [ $this, 'fix_video_description' ] );

		// Shortcodes
		add_shortcode( 'mv_img', [ $this, 'mv_img_shortcode' ] );
		add_shortcode( 'mvc_ad', [ $this, 'mvc_ad_shortcode' ] );
		add_shortcode( 'mv_schema_meta', [ $this, 'mv_schema_meta_shortcode' ] );

		// For pubs that were beta-testing Indexes — hides shortcode output
		add_shortcode( 'mv_index', '__return_false' );

		add_filter( 'rest_prepare_post', [ $this, 'rest_prepare_post' ], 10, 3 );

		add_filter( 'mv_create_paapi_access_key_settings_value', 'trim', 10 );
		add_filter( 'mv_create_paapi_secret_key_settings_value', 'trim', 10 );
		add_filter( 'mv_create_paapi_tag_settings_value', 'trim', 10 );
		add_filter( 'mv_create_localized_admin_settings', [ $this, 'set_custom_post_type_option_value' ], 10 );
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

		$Images->step_queue();

		Revisions::get_instance();

		$JSON_LD = JSON_LD::get_instance();

		\Mediavine\API_Services::get_instance();

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

		// Initialize Bulk Scrape API for list bulk import feature
		$Bulk_Scrape_API = new Bulk_Scrape_API();
		$Bulk_Scrape_API->init();

		// Initialize Recipe Importers feature
		$Importers = Importers::get_instance();
		$Importers->init();

		Plugin_Checker::get_instance();
		Theme_Checker::get_instance();

		// Version-specific feature registration.
		if ( defined( 'MV_CREATE_IS_PRO' ) ) {
			$this->register_pro_features();
		} else {
			$this->register_free_features();
		}
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
	 * Check if Create dev mode is enabled.
	 *
	 * Dev mode is enabled when:
	 * 1. The mediavine_devmode option has create set to 'on', OR
	 * 2. The mv_create_dev_mode filter returns true
	 *
	 * @return bool True if dev mode is enabled.
	 */
	public static function is_dev_mode(): bool {
		// Check the mediavine_devmode option
		$dev_mode = json_decode( get_option( 'mediavine_devmode', '[]' ), true );
		if ( isset( $dev_mode['create'] ) && 'on' === $dev_mode['create'] ) {
			return true;
		}

		// Check the filter (used by class-admin-init.php for localhost dev server)
		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Register Pro-only features.
	 */
	public function register_pro_features() {
		add_filter( 'mv_create_is_pro', '__return_true' );

		$unit_conversion = Unit_Conversion::get_instance();
		$unit_conversion->init();
	}

	/**
	 * Register Free-only features.
	 */
	public function register_free_features() {
		// Do nothing.
	}

	/**
	 * Initialize data that requires translated strings.
	 * Hooked to 'init' so the textdomain is loaded first.
	 */
	public function init_translatable_data() {
		self::$settings = self::get_settings();
		self::$shapes   = self::get_shapes_data();
	}

	/**
	 * Handle registration of all settings classes and pass complete array
	 * @return array
	 */
	public static function get_settings() {
		// Settings classes divided by groups
		$settings = [
			[
				'slug'  => self::$settings_group . '_secondary_color',
				'value' => null,
				'group' => 'mv_create_hidden',
				'order' => '0',
				'data'  => [],
			],
			[
				'slug'  => self::$settings_group . '_api_token',
				'value' => null,
				'group' => self::$settings_group . '_api',
				'order' => 105,
				'data'  => [
					'type'         => 'api_authentication',
					'label'        => __( 'Product Registration', 'mediavine' ),
					'instructions' => __( 'In order to use services like nutrition calculation or link scraping, you must register an account. This is a free, one-time action that will grant access to all of our external APIs.', 'mediavine' ),
				],
			],
			[
				'slug'  => self::$settings_group . '_api_user_id',
				'value' => false,
				'group' => 'hidden',
				'order' => 105,
				'data'  => [],
			],
		];

		$settings = array_merge(
			Advanced::settings(),
			Dev::settings(),
			Appearance::settings(),
			Recipes::settings(),
			Lists::settings(),
			List_Ads::settings(),
			Reader_Experience::settings(),
			Affiliates::settings(),
			Control_Panel::settings(),
			$settings
		);

		return apply_filters( 'mv_create_init_settings', $settings );
	}

	/**
	 * Updates Create Services Site ID with php, create and wp versions
	 *
	 * @return void
	 */
	/**
	 * Send version info to Create Studio API after a plugin update.
	 *
	 * Called via the mv_create_plugin_updated action hook.
	 * Sends current PHP, WP, and Create versions to the Studio API,
	 * and logs the version transition if the plugin was actually updated.
	 *
	 * @param string $last_plugin_version The previous plugin version before the update.
	 */
	function update_services_api( $last_plugin_version = '' ) {
		global $wp_version;

		$site_id = Create_Studio_Client::get_site_id();

		if ( empty( $site_id ) ) {
			return;
		}

		// Send current version info
		Create_Studio_Client::request( 'POST', '/sites/' . $site_id, [
			'php_version'    => PHP_VERSION,
			'wp_version'     => $wp_version,
			'create_version' => self::VERSION,
		] );

		// Log the version transition if this is an actual update (not a fresh install)
		if ( ! empty( $last_plugin_version ) && $last_plugin_version !== self::VERSION ) {
			Create_Studio_Client::request( 'POST', '/sites/' . $site_id . '/version-log', [
				'from' => $last_plugin_version,
				'to'   => self::VERSION,
			] );
		}
	}

	public function mv_schema_meta_shortcode( $atts ) {
		if ( isset( $atts['name'] ) ) {
			return '<span data-schema-name="' . esc_attr( $atts['name'] ) . '" style="display: none;"></span>';
		}
		return '';
	}

	public function mv_img_shortcode( $atts ) {
		$a = shortcode_atts(
			[
				'id'      => null,
				'options' => null,
				'no-pin'  => null, // @todo check for option to turn pinning on or off
			], $atts
		);

		if ( isset( $a['id'] ) ) {
			$attr = [];
			if ( isset( $a['no-pin'] ) ) {
				$attr['data-pin-nopin'] = $a['no-pin'];
			}

			if ( isset( $a['options'] ) ) {
				$meta    = wp_prepare_attachment_for_js( $a['id'] );
				$alt     = $meta['alt'];
				$title   = $meta['title'];
				$options = json_decode($a['options'] ?: '{}');

				$class = 'align' . esc_attr( $options->alignment ) . ' size-' . esc_attr( $options->size ) . ' wp-image-' . $a['id'];
				$class = apply_filters( 'get_image_tag_class', $class, $a['id'], $options->alignment, $options->size );

				$attr = [
					'alt'   => $alt,
					'title' => $title,
					'class' => $class,
				];
			}

			$img = wp_get_attachment_image( $a['id'], '', false, $attr );

			return $img;
		}
		return '';
	}

	/**
	 * In 1.4.12, we moved ad insertion logic from the admin UI to the client, see #2860.
	 * This shortcode output is intentionally left empty to provide backwards compatibility
	 * with content that includes the old ad target shortcode.
	 */
	public function mvc_ad_shortcode() {
		return '';
	}

	public function create_settings() {
		if ( null === self::$settings ) {
			self::$settings = self::get_settings();
		}
		$settings = $this->update_settings( self::$settings );
		\Mediavine\Settings::create_settings_filter( $settings );
	}

	public function create_shapes() {
		if ( null === self::$shapes ) {
			self::$shapes = self::get_shapes_data();
		}
		$shape_dbi = new \Mediavine\MV_DBI( 'mv_shapes' );

		foreach ( self::$shapes as $shape ) {
			$shape_dbi->upsert( $shape );
		}
	}

	/**
	 * Migrates old settings to newer versions within settings table
	 *
	 * Always check for less than current version as this is run before the version is updated
	 * Add estimated removal date (6 months) so we don't clutter code with future publishes
	 * Remove code within this function, but don't remove this function
	 *
	 * Example usage:
	 * ```
	 * if ( version_compare( $last_plugin_version, '1.0.0', '<' ) ) {
	 *     $settings = \Mediavine\Settings::migrate_setting_value( $settings, self::$settings_group . '_slug', 'old_value', 'new_value' );
	 *     $settings = \Mediavine\Settings::migrate_setting_slug( $settings, self::$settings_group . '_old_slug', self::$settings_group . '_new_slug' );
	 * }
	 * ```
	 *
	 * @param   array $settings  Current list of settings before running create settings
	 * @return  array             List of settings after migrated changes made
	 */
	public function update_settings( $settings ) {
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		// Update incorrect card style slug of mv_create to square (Remove Jan 2020)
		if ( version_compare( $last_plugin_version, '1.4.8', '<' ) ) {
			$settings = \Mediavine\Settings::migrate_setting_value( $settings, self::$settings_group . '_card_style', 'mv_create', 'square' );
		}

		return $settings;
	}

	public function fix_video_description( $id ) {
		// fix the video description
		$creations = new \Mediavine\MV_DBI( 'mv_creations' );
		$creation  = $creations->find_one_by_id( $id );

		if ( ! empty( $creation->video ) ) {
			$video_data         = json_decode($creation->video ?: '{}');
			$make_the_call      = false;
			$video_data_changed = false;
			$update_data        = [
				'id' => $creation->id,
			];

			if ( empty( $video_data->description ) ) {
				if (
					! empty( $video_data->rawData ) &&
					! empty( $video_data->rawData->description )
				) {
					$video_data->description = $video_data->rawData->description;
					$video_data_changed      = true;
				} else {
					$make_the_call = true;
				}
			}

			if ( empty( $video_data->duration ) ) {
				if (
					! empty( $video_data->rawData ) &&
					! empty( $video_data->rawData->duration )
				) {
					$video_data->duration = 'PT' . $video_data->rawData->duration . 'S';
					$video_data_changed   = true;
				} else {
					$make_the_call = true;
				}
			}

			if ( $make_the_call && $video_data->slug ) {
				$api_data = file_get_contents( 'https://embed.mediavine.com/oembed/?url=https%3A%2F%2Fvideo.mediavine.com%2Fvideos%2F' . $video_data->slug );
				if ( $api_data ) {
					$new_video_data = json_decode($api_data ?: '{}');

					if ( ! empty( $new_video_data->duration ) ) {
						$video_data->duration = 'PT' . $new_video_data->duration . 'S';
						$video_data_changed   = true;
					}

					if ( ! empty( $new_video_data->description ) ) {
						$video_data->description = $new_video_data->description;
						$video_data_changed      = true;
					}

					if ( ! empty( $new_video_data->keywords ) ) {
						$video_data->keywords = $new_video_data->keywords;
						$video_data_changed   = true;
					}
				}
			}

			if ( $video_data_changed ) {
				$creation->video      = wp_json_encode( $video_data );
				$update_data['video'] = $creation->video;
				if ( ! empty( $creation->json_ld ) ) {
					$json_ld     = json_decode($creation->json_ld ?: '{}');
					$upload_date = $json_ld->video->uploadDate;
					if ( ! empty( $video_data->rawData->uploadDate ) ) {
						$upload_date = $video_data->rawData->uploadDate;
					}

					$json_ld->video         = [
						'@type'        => 'VideoObject',
						'name'         => $json_ld->video->name,
						'description'  => $video_data->description,
						'thumbnailUrl' => $json_ld->video->thumbnailUrl,
						'contentUrl'   => $json_ld->video->contentUrl,
						'duration'     => $video_data->duration,
						'uploadDate'   => $upload_date,
					];
					$creation->json_ld      = wp_json_encode( $json_ld );
					$update_data['json_ld'] = $creation->json_ld;

					if ( ! empty( $creation->published ) ) {
						$published_data           = json_decode($creation->published ?: '{}');
						$published_data->video    = $creation->json_ld;
						$update_data['published'] = wp_json_encode( $published_data );
					}
				}

				$creations->update( $update_data );

			}
		}
	}

	/**
	 * Republishes create cards depending on plugin version
	 *
	 * Always check for less than current version as this is run before the version is updated
	 * Add estimated removal date (6 months) so we don't clutter code with future publishes
	 * Remove code within this function, but don't remove this function
	 *
	 * @return  void
	 */
	public function republish_queue() {
		global $wpdb;
		$creations = new \Mediavine\MV_DBI( 'mv_creations' );
		$creations->set_limit( 10000 );
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );
		$republish_ids       = [];

		// Republish cards with instructions that contain HTML entities (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.14', '<' ) ) {
			$cards = $creations->where( [ 'published', 'LIKE', '%&lt;%' ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with rating_count > 0 (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.12', '<' ) ) {
			$cards = $creations->where( [ 'rating_count', '>', 0 ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with orphaned <li> tags in instructions (Remove February 2026)
		if ( version_compare( $last_plugin_version, '1.10.1', '<' ) ) {
			// Query for cards where published instructions start with <li> (orphaned list items)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$orphaned_list_cards = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mv_creations
					WHERE published IS NOT NULL
					AND published != ''
					AND JSON_EXTRACT(published, '$.instructions') LIKE %s
					AND (
						JSON_EXTRACT(published, '$.instructions') NOT LIKE %s
						OR LOCATE('<li', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))) <
							COALESCE(NULLIF(LOCATE('<ol', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))), 0), 999999)
					)
					AND (
						JSON_EXTRACT(published, '$.instructions') NOT LIKE %s
						OR LOCATE('<li', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))) <
							COALESCE(NULLIF(LOCATE('<ul', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))), 0), 999999)
					)",
					'%<li%',
					'%<ol%',
					'%<ul%'
				)
			);
			if ( ! empty( $orphaned_list_cards ) ) {
				$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $orphaned_list_cards, 'id' ) ) );
			}
		}
		if ( ! empty( $republish_ids ) ) {
			\Mediavine\Create\Publish::update_publish_queue( $republish_ids );
		}
	}

	/**
	 * Display importer admin notice
	 *
	 * @return void
	 */
	public function importer_admin_notice_display() {
		$settings_url = admin_url( 'options-general.php?page=mv_settings&setting=mv_create_enable_importers' );
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
			wp_kses_post( __( 'Thanks for installing Create!', 'mediavine' ) ),
			wp_kses_post(
				sprintf(
					/* translators: %1$s: link to importer setting, %2$s: closing anchor tag */
					__( 'If you\'re moving from another recipe plugin, %1$senable the importer%2$s and breathe new life into your old recipes.', 'mediavine' ),
					'<a href="' . esc_url( $settings_url ) . '">',
					'</a>'
				)
			)
		);
	}

	/**
	 * Display importer admin notice if importers not enabled
	 *
	 * @return void
	 */
	public function importer_admin_notice() {
		if ( ! class_exists( 'Mediavine\Create\Importer\Plugin' ) ) {
			add_action( 'admin_notices', [ $this, 'importer_admin_notice_display' ] );
		}
	}

	/**
	 * Fixes reviews that were imported from other plugins.
	 *
	 * Importers were assigning a `recipe_id` to imported reviews instead of `creation`.
	 * This caused reviews to not show up, even though they'd been imported.
	 * This method fixes that by reassigning imported reviews.
	 *
	 * Remove Apr 2019
	 *
	 * @since 1.1.1
	 *
	 * @return {void}
	 */
	public function update_reviews_table() {
		global $wpdb;
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		if ( version_compare( $last_plugin_version, '1.2.0', '<' ) ) {
			// Not all users had the plugin when `recipe_id` was a column in the `mv_reviews` table.
			// Check for this column before trying to update it.
			// SECURITY CHECKED: Nothing in this query can be sanitized.
			$has_recipe_id_column_statement = "SHOW COLUMNS FROM {$wpdb->prefix}mv_reviews LIKE 'recipe_id'";
			$has_recipe_id_column           = $wpdb->get_row( $has_recipe_id_column_statement );
			if ( ! $has_recipe_id_column ) {
				return;
			}

			// SECURITY CHECKED: Nothing in this query can be sanitized.
			$statement = "UPDATE {$wpdb->prefix}mv_reviews a
							INNER JOIN {$wpdb->prefix}mv_reviews b on a.id = b.id
							SET a.creation = b.recipe_id
							WHERE b.recipe_id";
			$wpdb->query( $statement );
		}
	}

	/**
	 * Fixes cloned cards' ratings.
	 *
	 * Previously, cloned cards retained the originating card's `rating` and `rating_count`
	 * attributes, giving the client-facing card the appearance of its ratings having been
	 * duplicated. Resetting the count resolves this issue.
	 *
	 * Remove November 2019
	 *
	 * @since 1.3.20
	 *
	 * @return void
	 */
	public function fix_cloned_ratings() {
		global $wpdb;
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		if ( version_compare( $last_plugin_version, '1.3.20', '<' ) ) {
			// SECURITY CHECKED: Nothing in this query can be sanitized.
			$creations_with_ratings = $wpdb->get_results(
				"SELECT id as creation FROM {$wpdb->prefix}mv_creations WHERE rating AND rating_count;"
			);
			$model                  = new Reviews_Models();
			foreach ( $creations_with_ratings as $review ) {
				$model->update_creation_rating( $review );
			}
		}
	}

	/**
	 * Fixes canonical post ids of imported Cookbook recipes.
	 *
	 * Recipes imported from Cookbook were using the Cookbook recipe id as the canonical_post_id.
	 * Obviously, this was not good, so we need to fix that.
	 *
	 * Remove December 2019
	 *
	 * @since 1.4.6
	 *
	 * @return void
	 */
	public function fix_cookbook_canonical_post_ids() {
		global $wpdb;
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		if ( version_compare( $last_plugin_version, '1.4.6', '<' ) ) {
			// SECURITY CHECKED: Nothing in this query can be sanitized.
			$creations = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}mv_creations WHERE type='recipe' AND metadata LIKE '%cookbook%' AND metadata NOT LIKE '%fixed_canonical_post_id%'",
				ARRAY_A
			);
			$ids       = [];
			foreach ( $creations as $creation ) {
				$post     = get_post( $creation['canonical_post_id'] );
				$metadata = json_decode($creation['metadata'] ?: '{}');
				$posts    = json_decode($creation['associated_posts'] ?: '[]');
				if ( 'cookbook_recipe' === $post->post_type && ! empty( $posts ) ) {
					$creation['canonical_post_id'] = $posts[0];
				}
				$metadata->fixed_canonical_post_id = true;
				$creation['metadata']              = wp_json_encode( $metadata );
				self::$models_v2->mv_creations->update_without_modified_date( $creation );
				$ids[] = $creation->id;
			}
			\Mediavine\Create\Publish::update_publish_queue( $ids );
		}
	}

	/**
	 * Ensures all current versions of create cards store a revision.
	 *
	 * Remove January 2020
	 *
	 * @since 1.4.11
	 *
	 * @return void
	 */
	public function add_initial_revision_to_cards() {
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		if ( version_compare( $last_plugin_version, '1.4.11', '<' ) ) {
			Publish::add_all_to_publish_queue();
		}
	}

	/**
	 * Queues up all currently existing Amazon products after the API changes
	 *
	 * Remove May 2020
	 *
	 * @since 1.5.1
	 *
	 * @return void
	 */
	public function queue_existing_amazon_products() {
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );

		if ( version_compare( $last_plugin_version, '1.5.4', '<' ) ) {
			$Products = Products::get_instance();
			$Products->initial_queue_products();
		}
	}

	/**
	 * Sets the default JSON-LD Schema in Head setting to disabled for existing installs.
	 *
	 * Remove Sept 2021
	 *
	 * @since 1.6.7
	 *
	 * @return void
	 */
	public function set_default_schema_setting_on_existing_installs( $last_plugin_version ) {
		// Don't run new installs (previous version newer than 1.6.7).
		// We run the not (!) check because we sometimes give patch releases in an x.x.x.x format.
		if ( ! version_compare( $last_plugin_version, '1.6.7', '<' ) ) {
			return;
		}

		// Build mock setting for JSON-LD Schema in Head with disabled value.
		$fake_schema_setting = [
			[
				'slug'  => self::$settings_group . '_schema_in_head',
				'value' => false,
			],
		];

		// Create mock setting into database before real settings are updated.
		// This will keep the value of the fake setting, but everything else of the real setting.
		Settings::create_settings( $fake_schema_setting );
	}

	/**
	 * Deletes old settings that are no longer used in Create
	 *
	 * @return void
	 */
	public function delete_old_settings() {
		\Mediavine\Settings::delete_setting( self::$settings_group . '_enable_link_scraping' );
		\Mediavine\Settings::delete_setting( self::$settings_group . '_ad_density' );
		\Mediavine\Settings::delete_setting( self::$settings_group . '_measurement_system' );
	}

	/**
	 * Migrates settings to the new Reader Experience group.
	 *
	 * Moves settings from Pro and Advanced groups to the new reader_experience group
	 * as part of the Settings UI Redesign.
	 *
	 * Settings moved:
	 * - From Pro: Jump to Recipe, Social Footer settings
	 * - From Advanced: Checklists, Reviews, Ratings settings
	 *
	 * @since 2.1.0
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public function migrate_reader_experience_settings( $last_plugin_version ) {
		// Only run migration for versions before the settings redesign.
		if ( ! version_compare( $last_plugin_version, '2.1.0', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Jump to Recipe settings from Pro to reader_experience.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s",
				self::$settings_group . '_reader_experience',
				'%jump_to_recipe%',
				'%jump_to_how_to%'
			)
		);

		// Move Social Footer settings from Pro to reader_experience.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s",
				self::$settings_group . '_reader_experience',
				'%social_footer%',
				'%social_service%',
				'%facebook_username%',
				'%instagram_username%',
				'%pinterest_username%'
			)
		);

		// Move Checklists, Reviews, and Ratings settings from Advanced to reader_experience.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s OR slug LIKE %s",
				self::$settings_group . '_reader_experience',
				'%checklist%',
				'%review%',
				'%rating%'
			)
		);

		// Move Display settings to Appearance group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE `group` = %s",
				self::$settings_group . '_appearance',
				self::$settings_group . '_display'
			)
		);
	}

	/**
	 * Migrates Interactive Mode settings to their own group.
	 *
	 * Moves Interactive Mode settings from reader_experience to interactive_mode
	 * group so they appear in their own settings section.
	 *
	 * @since 2.0.9
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public function migrate_interactive_mode_settings( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.9', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Interactive Mode settings from reader_experience to interactive_mode.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s",
				self::$settings_group . '_interactive_mode',
				'%interactive_mode%'
			)
		);

		// Remove legacy debugging setting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'slug' => self::$settings_group . '_enable_debugging' ] );
	}

	/**
	 * Migrates card style preview image URLs from PNG to WebP format.
	 *
	 * In 2.0.10, card style preview images were converted from PNG to WebP.
	 * The image URLs are stored as JSON in the settings data column and need
	 * to be updated so the theme selector doesn't show 404 images on upgrade.
	 *
	 * @since 2.0.10
	 *
	 * @param string $last_plugin_version The version being upgraded from.
	 */
	public function migrate_card_style_preview_images( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.10', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Replace .png with .webp in the data JSON for card style settings.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"UPDATE {$table} SET data = REPLACE(data, 'card-style-editorial.png', 'card-style-editorial.webp'),
				data = REPLACE(data, 'card-style-modern.png', 'card-style-modern.webp'),
				data = REPLACE(data, 'card-style-big-image.png', 'card-style-big-image.webp'),
				data = REPLACE(data, 'card-style-default.png', 'card-style-default.webp'),
				data = REPLACE(data, 'card-style-dark.png', 'card-style-dark.webp'),
				data = REPLACE(data, 'card-style-centered.png', 'card-style-centered.webp'),
				data = REPLACE(data, 'card-style-centered-dark.png', 'card-style-centered-dark.webp')
			WHERE slug LIKE '%_card_style' AND data LIKE '%card-style-%.png%'"
		);
	}

	/**
	 * Migrates Hands-free Mode setting from Advanced to Reader Experience.
	 *
	 * @since 2.0.12
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public function migrate_hands_free_to_reader_experience( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.12', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Hands-free Mode from advanced to reader_experience.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug = %s",
				self::$settings_group . '_reader_experience',
				self::$settings_group . '_enable_hands_free_mode'
			)
		);
	}

	/**
	 * Extend default REST API with useful data.
	 *
	 * @param [object] $data the current object outbound to rest response
	 * @param [object] $post post object for use in the outbound response
	 * @param [object] $request the wp rest request object.
	 * @return [object] update $data object
	 */
	public function rest_prepare_post( $data, $post, $request ) {
		$_data                        = $data->data;
		$_data['mv']                  = [];
		$_data['mv']['thumbnail_id']  = null;
		$_data['mv']['thumbnail_uri'] = null;

		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( empty( $thumbnail_id ) ) {
			$data->data = $_data;
			return $data;
		}

		$thumbnail                   = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
		$_data['mv']['thumbnail_id'] = $thumbnail_id;

		if ( isset( $thumbnail[0] ) ) {
			$_data['mv']['thumbnail_uri'] = $thumbnail[0];
		}

		$data->data = $_data;
		return $data;
	}

	/**
	 * Retrieve an array of custom post-types that are public and not built-in
	 *
	 * @return array
	 */
	private function get_custom_post_types() {
		/**
		 * @var \WP_Post_Type[]
		 */
		$post_types = get_post_types(
			[
				'public'   => true,
				'_builtin' => false,
			], 'objects'
		);

		$allowed_post_types = [];
		foreach ( $post_types as $post_type ) {
			$post_type_label = $post_type->label;
			if ( ! empty( $post_type->labels->singular_name ) ) {
				$post_type_label = $post_type->labels->singular_name;
			}

			$allowed_post_types[ $post_type->name ] = $post_type_label;
		}

		return $allowed_post_types;
	}

	/**
	 * Register custom fields for users.
	 */
	public static function register_custom_fields() {
		add_filter(
			'mv_create_fields', function ( $arr ) {
			$arr[] = [
				'slug'         => 'class',
				'label'        => __( 'CSS Class', 'mediavine' ),
				'instructions' => __( 'Add an additional CSS class to this card.', 'mediavine' ),
				'type'         => 'text',
			];
			$arr[] = [
				'slug'         => 'mv_create_nutrition_disclaimer',
				'label'        => __( 'Custom Nutrition Disclaimer', 'mediavine' ),
				'instructions' => __( 'Example: Nutrition information isn\'t always accurate.', 'mediavine' ),
				'type'         => 'textarea',
				'card'         => 'recipe',
			];
			$arr[] = [
				'slug'         => 'mv_create_affiliate_message',
				'label'        => __( 'Custom Affiliate Message', 'mediavine' ),
				'instructions' => __( 'Override the default affiliate message for this card.', 'mediavine' ),
				'type'         => 'textarea',
				'card'         => [ 'recipe', 'diy', 'list' ],
			];
			$arr[] = [
				'slug'         => 'mv_create_show_list_affiliate_message',
				'label'        => __( 'Show Custom Affiliate Message', 'mediavine' ),
				'instructions' => __( 'Check this box to display an affiliate message on this List.', 'mediavine' ),
				'type'         => 'checkbox',
				'card'         => 'list',
			];

			// Social footer overrides
			if ( \Mediavine\Settings::get_setting( 'mv_create_social_footer', false ) ) {
				$arr[] = [
					'slug'         => 'mv_create_social_footer_icon',
					'label'        => __( 'Social Footer Icon', 'mediavine' ),
					'instructions' => __( 'Override the default social footer icon for this card.', 'mediavine' ),
					'type'         => 'select',
					'defaultValue' => 'default',
					'options'      => [
						'default'   => 'Use Default',
						'facebook'  => 'Facebook',
						'instagram' => 'Instagram',
						'pinterest' => 'Pinterest',
					],
					'card'         => [ 'recipe', 'diy' ],
				];
				$arr[] = [
					'slug'         => 'mv_create_social_footer_header',
					'label'        => __( 'Social Footer Heading', 'mediavine' ),
					'instructions' => __( 'Override the default social footer heading for this card.', 'mediavine' ),
					'type'         => 'text',
					'card'         => [ 'recipe', 'diy' ],
				];
				$arr[] = [
					'slug'         => 'mv_create_social_footer_content',
					'label'        => __( 'Social Footer Content', 'mediavine' ),
					'instructions' => __( 'Override the default social footer content for this card.', 'mediavine' ),
					'type'         => 'wysiwyg',
					'card'         => [ 'recipe', 'diy' ],
				];
			}

			return $arr;
			}
		);
	}

	/**
	 * @return array[]
	 */
	public static function get_shapes_data() {
		return [
			[
				'name'   => __( 'Recipe', 'mediavine' ),
				'plural' => __( 'Recipes', 'mediavine' ),
				'slug'   => 'recipe',
				'icon'   => 'carrot',
				'shape'  => file_get_contents( __DIR__ . '/shapes/recipe.json' ),
			],
			[
				'name'   => __( 'How-To', 'mediavine' ),
				'plural' => __( 'How-Tos', 'mediavine' ),
				'slug'   => 'diy',
				'icon'   => 'lightbulb',
				'shape'  => file_get_contents( __DIR__ . '/shapes/how-to.json' ),
			],
			[
				'name'   => __( 'List', 'mediavine' ),
				'plural' => __( 'Lists', 'mediavine' ),
				'slug'   => 'list',
				'icon'   => '',
				'shape'  => file_get_contents( __DIR__ . '/shapes/list.json' ),
			],
		];
	}
}
