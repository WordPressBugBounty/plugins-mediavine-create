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
	const VERSION = '2.5.4';

	const DB_VERSION = '2.4.1';

	const TEXT_DOMAIN = 'mediavine-create';

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

		// Initialize Broadcast Notice only when connected (connecting = consent to Studio contact).
		if ( \Mediavine\Create\Create_Studio_Client::is_site_connected() ) {
			\Mediavine\Create\Broadcast_Notice::get_instance();
		}

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

		// Run upgrade check at init. Translations are auto-loaded by WordPress
		// 4.6+ for wp.org-hosted plugins since the text domain matches the slug.
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
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'importer_admin_notice' ], 60 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'update_services_api' ], 95 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'purge_used_css_caches_for_widget_safelist' ], 100 );
		add_action( self::PLUGIN_DOMAIN . '_plugin_updated', [ $this, 'schedule_image_metadata_backfill' ], 105 );

		// Fixes
		add_action( 'mv_create_backfill_image_metadata', [ $this, 'backfill_image_metadata' ] );

		// Shortcodes
		add_shortcode( 'mv_img', [ $this, 'mv_img_shortcode' ] );
		add_shortcode( 'mvc_ad', [ $this, 'mvc_ad_shortcode' ] );
		add_shortcode( 'mv_schema_meta', [ $this, 'mv_schema_meta_shortcode' ] );

		// For pubs that were beta-testing Indexes — hides shortcode output
		add_shortcode( 'mv_index', '__return_false' );

		add_filter( 'rest_prepare_post', [ $this, 'rest_prepare_post' ], 10, 3 );

		add_filter( 'mv_create_paapi_tag_settings_value', 'trim', 10 );
		add_filter( 'mv_create_creators_credential_id_settings_value', 'trim', 10 );
		add_filter( 'mv_create_creators_credential_secret_settings_value', 'trim', 10 );
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

		Revisions::get_instance();

		$JSON_LD = JSON_LD::get_instance();

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
					'label'        => __( 'Product Registration', 'mediavine-create' ),
					'instructions' => __( 'In order to use services like nutrition calculation or link scraping, you must register an account. This is a free, one-time action that will grant access to all of our external APIs.', 'mediavine-create' ),
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
				'url'     => null,
				'alt'     => null,
				'options' => null,
				'no-pin'  => null, // @todo check for option to turn pinning on or off
			], $atts
		);

		// Externally-hosted image/GIF referenced by URL (pasted in the editor).
		// Rendered as a direct <img> so animated GIFs keep animating and the file
		// never has to be downloaded into the Media Library.
		if ( ! isset( $a['id'] ) && ! empty( $a['url'] ) ) {
			$url = esc_url( $a['url'] );
			if ( empty( $url ) ) {
				return '';
			}

			$attr = [
				'src'     => $url,
				'alt'     => ! empty( $a['alt'] ) ? esc_attr( $a['alt'] ) : '',
				'loading' => 'lazy',
				'class'   => 'mv-img-external',
			];
			if ( isset( $a['no-pin'] ) ) {
				$attr['data-pin-nopin'] = esc_attr( $a['no-pin'] );
			}

			$html = '<img';
			foreach ( $attr as $name => $value ) {
				$html .= ' ' . $name . '="' . $value . '"';
			}
			$html .= ' />';

			return $html;
		}

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
	 * File extensions that can be referenced by a bare URL in editor content and
	 * "unfurled" into an inline image.
	 *
	 * @return string[]
	 */
	public static function unfurl_media_extensions() {
		/**
		 * Filter the media file extensions that are unfurled from a bare URL into
		 * an inline image in instructions/notes.
		 *
		 * @since 1.9.0
		 *
		 * @param string[] $extensions Lower-case extensions without the leading dot.
		 */
		return apply_filters(
			'mv_create_unfurl_media_extensions',
			[ 'gif', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'svg' ]
		);
	}

	/**
	 * Convert bare image/GIF URLs in editor content into [mv_img url="…"] shortcodes
	 * so they render as inline images (CRE-64 "unfurling").
	 *
	 * Only URLs that stand alone as text are unfurled: a URL that is part of an
	 * attribute value (preceded by =, ', " or /) — e.g. an existing href/src or an
	 * [mv_img url="…"] shortcode — is left untouched, so links and already-inserted
	 * images are not double-wrapped.
	 *
	 * @param string $html Instruction/notes HTML.
	 * @return string
	 */
	public static function unfurl_media_urls( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return $html;
		}

		$extensions = array_map( 'preg_quote', self::unfurl_media_extensions() );
		$extensions = implode( '|', $extensions );

		// Lazy body (`+?`) so adjacent bare URLs with no separating space (e.g.
		// `a.com/1.gif,b.com/2.gif`) don't get merged into a single match.
		$pattern = '#(?<![=\'"/])\bhttps?://[^\s<>"\']+?\.(?:' . $extensions . ')(?:[?\#][^\s<>"\']*)?#i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$matched = $matches[0];
				$url     = esc_url_raw( $matched );
				if ( empty( $url ) ) {
					return $matched;
				}
				return '[mv_img url="' . $url . '"]';
			},
			$html
		);
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
		// Version-gated setting migrations live here. Drop any block once every
		// supported install has passed its version gate (see docs/MigrationPolicy.md).
		return $settings;
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
		$last_plugin_version = get_option( 'mv_create_version', self::VERSION );
		$republish_ids       = [];

		// Republish cards with instructions that contain HTML entities (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.14', '<' ) ) {
			// Re-apply limit before each where(): query state resets after every call.
			$creations->set_limit( 10000 );
			$cards = $creations->where( [ 'published', 'LIKE', '%&lt;%' ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with rating_count > 0 (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.12', '<' ) ) {
			$creations->set_limit( 10000 );
			$cards = $creations->where( [ 'rating_count', '>', 0 ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with orphaned <li> tags in instructions (Remove February 2026)
		if ( version_compare( $last_plugin_version, '1.10.1', '<' ) ) {
			// Query for cards where published instructions start with <li> (orphaned list items)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
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

		// Republish list cards so ItemList schema fixes (1-based positions,
		// numberOfItems, url/@id, lookalike-host hardening, entity decoding)
		// reach sites on the legacy inline JSON-LD path
		// (mv_create_schema_in_head=false). Head-path sites rebuild ItemList at
		// render time; inline-path sites serve frozen publish-time JSON-LD.
		// Remove after January 2027 (or 12 minor releases past 2.5.4).
		if ( version_compare( $last_plugin_version, '2.5.4', '<' ) ) {
			$creations->set_limit( 10000 );
			$list_cards    = $creations->where( [ 'type', '=', 'list' ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $list_cards, 'id' ) ) );
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
			wp_kses_post( __( 'Thanks for installing Create!', 'mediavine-create' ) ),
			wp_kses_post(
				sprintf(
					/* translators: %1$s: link to importer setting, %2$s: closing anchor tag */
					__( 'If you\'re moving from another recipe plugin, %1$senable the importer%2$s and breathe new life into your old recipes.', 'mediavine-create' ),
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
	 * Schedule a one-off background sweep that regenerates attachment metadata
	 * for images Create sideloaded without it.
	 *
	 * Versions 2.1.0–2.5.x deferred image processing during REST requests to a
	 * path that bailed before doing any work, so images Create downloaded
	 * (list-item thumbnails, product and import images) were left with empty
	 * `_wp_attachment_metadata`. That blocks image optimizers and thumbnail
	 * generation. The sweep repairs the existing damage in small batches.
	 *
	 * @param string $last_plugin_version Version being upgraded from.
	 *
	 * @return void
	 */
	public function schedule_image_metadata_backfill( $last_plugin_version = '' ) {
		if ( empty( $last_plugin_version ) ) {
			$last_plugin_version = get_option( 'mv_create_version', self::VERSION );
		}

		// Only installs that ran an affected version (the bug landed in 2.1.0)
		// can have damaged images.
		if ( version_compare( $last_plugin_version, '2.1.0', '<' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'mv_create_backfill_image_metadata' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mv_create_backfill_image_metadata' );
		}
	}

	/**
	 * Regenerate missing attachment metadata in small batches, rescheduling
	 * itself until every affected image has been processed.
	 *
	 * @return void
	 */
	public function backfill_image_metadata() {
		global $wpdb;

		$batch_size = 25;

		// Attachments Create sideloaded (they carry an `origin_uri` marker) that
		// never received attachment metadata, excluding any already repaired or
		// previously found to be unrepairable (e.g. the original file is gone).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} origin
					ON origin.post_id = p.ID AND origin.meta_key = 'origin_uri'
				LEFT JOIN {$wpdb->postmeta} meta
					ON meta.post_id = p.ID AND meta.meta_key = '_wp_attachment_metadata'
				LEFT JOIN {$wpdb->postmeta} done
					ON done.post_id = p.ID AND done.meta_key = '_mv_create_metadata_backfilled'
				WHERE p.post_type = 'attachment'
					AND done.meta_id IS NULL
					AND ( meta.meta_id IS NULL OR meta.meta_value = '' OR meta.meta_value = %s )
				LIMIT %d",
				'a:0:{}',
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $attachment_ids ) ) {
			return;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$generated     = Images::generate_base_attachment_metadata( $attachment_id );

			// Mark unrepairable images so a missing original file can't trap the
			// sweep in an endless loop. Repaired images now have metadata and
			// naturally fall out of the query above.
			if ( empty( $generated ) ) {
				update_post_meta( $attachment_id, '_mv_create_metadata_backfilled', true );
			}
		}

		// More may remain — process the next batch on the following cron tick.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mv_create_backfill_image_metadata' );
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s",
				self::$settings_group . '_reader_experience',
				'%jump_to_recipe%',
				'%jump_to_how_to%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Social Footer settings from Pro to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Checklists, Reviews, and Ratings settings from Advanced to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s OR slug LIKE %s",
				self::$settings_group . '_reader_experience',
				'%checklist%',
				'%review%',
				'%rating%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Display settings to Appearance group.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE `group` = %s",
				self::$settings_group . '_appearance',
				self::$settings_group . '_display'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s",
				self::$settings_group . '_interactive_mode',
				'%interactive_mode%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Remove legacy debugging setting.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->delete( $table, [ 'slug' => self::$settings_group . '_enable_debugging' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug = %s",
				self::$settings_group . '_reader_experience',
				self::$settings_group . '_enable_hands_free_mode'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Forces cache plugins to regenerate "used CSS" snapshots that may have
	 * pruned `.cs-*` / `.mv-create-*` selectors before the new safelist
	 * filters in the rascal classes were registered.
	 *
	 * Sites running WP Rocket / LiteSpeed / Perfmatters / FlyingPress used-CSS
	 * features will keep serving the stale snapshot — and the broken widget
	 * styling — until their cached copy is invalidated. The new rascal hooks
	 * (Wp_Rocket, Litespeed_Cache, Perfmatters, Flying_Press) only apply when
	 * those caches are regenerated.
	 *
	 * Runs once on upgrade from < 2.5.4.
	 *
	 * Bumped from 2.4.5 so sites that already ran the WP Rocket / LiteSpeed
	 * purge also regenerate after Perfmatters / FlyingPress safelists land.
	 * Remove by: ~2026-12 (see docs/MigrationPolicy.md).
	 *
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public function purge_used_css_caches_for_widget_safelist( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.5.4', '<' ) ) {
			return;
		}

		\Mediavine\Cache_Manager::purge_full_page_caches( 'Mediavine Create 2.5.4 widget CSS safelist' );
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
				'label'        => __( 'CSS Class', 'mediavine-create' ),
				'instructions' => __( 'Add an additional CSS class to this card.', 'mediavine-create' ),
				'type'         => 'text',
			];
			$arr[] = [
				'slug'         => 'mv_create_nutrition_disclaimer',
				'label'        => __( 'Custom Nutrition Disclaimer', 'mediavine-create' ),
				'instructions' => __( 'Example: Nutrition information isn\'t always accurate.', 'mediavine-create' ),
				'type'         => 'textarea',
				'card'         => 'recipe',
			];
			$arr[] = [
				'slug'         => 'mv_create_affiliate_message',
				'label'        => __( 'Custom Affiliate Message', 'mediavine-create' ),
				'instructions' => __( 'Override the default affiliate message for this card.', 'mediavine-create' ),
				'type'         => 'textarea',
				'card'         => [ 'recipe', 'diy', 'list' ],
			];
			$arr[] = [
				'slug'         => 'mv_create_show_list_affiliate_message',
				'label'        => __( 'Show Custom Affiliate Message', 'mediavine-create' ),
				'instructions' => __( 'Check this box to display an affiliate message on this List.', 'mediavine-create' ),
				'type'         => 'checkbox',
				'card'         => 'list',
			];

			// Social footer overrides
			if ( \Mediavine\Settings::get_setting( 'mv_create_social_footer', false ) ) {
				$arr[] = [
					'slug'         => 'mv_create_social_footer_icon',
					'label'        => __( 'Social Footer Icon', 'mediavine-create' ),
					'instructions' => __( 'Override the default social footer icon for this card.', 'mediavine-create' ),
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
					'label'        => __( 'Social Footer Heading', 'mediavine-create' ),
					'instructions' => __( 'Override the default social footer heading for this card.', 'mediavine-create' ),
					'type'         => 'text',
					'card'         => [ 'recipe', 'diy' ],
				];
				$arr[] = [
					'slug'         => 'mv_create_social_footer_content',
					'label'        => __( 'Social Footer Content', 'mediavine-create' ),
					'instructions' => __( 'Override the default social footer content for this card.', 'mediavine-create' ),
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
				'name'   => __( 'Recipe', 'mediavine-create' ),
				'plural' => __( 'Recipes', 'mediavine-create' ),
				'slug'   => 'recipe',
				'icon'   => 'carrot',
				'shape'  => file_get_contents( __DIR__ . '/shapes/recipe.json' ),
			],
			[
				'name'   => __( 'How-To', 'mediavine-create' ),
				'plural' => __( 'How-Tos', 'mediavine-create' ),
				'slug'   => 'diy',
				'icon'   => 'lightbulb',
				'shape'  => file_get_contents( __DIR__ . '/shapes/how-to.json' ),
			],
			[
				'name'   => __( 'List', 'mediavine-create' ),
				'plural' => __( 'Lists', 'mediavine-create' ),
				'slug'   => 'list',
				'icon'   => '',
				'shape'  => file_get_contents( __DIR__ . '/shapes/list.json' ),
			],
		];
	}
}
