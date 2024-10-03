<?php
namespace Mediavine\Create;

/**
 * Class for initializing our admin scripts
 */
class Admin_Init extends Plugin {

	public static $mcp_data = null;

	public static $mv_create_url_params = [
		'post_type=mv_create',
		'page=mv_settings',
	];

	/**
	 * Manages default custom field registration.
	 */
	public static function custom_fields() {
		$fields = [];
		$fields = apply_filters( 'mv_create_fields', $fields );
		return $fields;
	}

	/**
	 * Gets localization data needed for both Gutenberg and general scripts
	 */
	public static function localization() {
		global $wpdb;
		$settings = apply_filters( 'mv_create_localized_admin_settings', self::get_translated_settings() );
		$shapes   = self::get_translated_shapes();

		self::$mcp_data = self::get_mcp_data();

		$args = array(
			'capability' => [ 'edit_posts' ],
			'fields'     => [ 'display_name' ],
		);

		// Capability queries were only introduced in WP 5.9.
		if ( version_compare( $GLOBALS['wp_version'], '5.9', '<' ) ) {
			$args['who'] = 'authors';
			unset( $args['capability'] );
		}

		$authors = get_users( $args );

		$sanitized_authors = [];
		foreach ( $authors as $author ) {
			if ( ! empty( $author->display_name ) && is_string( $author->display_name ) ) {
				$sanitized_authors[] = $author->display_name;
			}
		}

		// SECURITY CHECKED: Nothing in this query can be sanitized.
		$key_match_statement = "SELECT id, original_object_id from {$wpdb->prefix}mv_creations WHERE original_object_id";
		$results             = $wpdb->get_results( $key_match_statement );
		$keys                = [];
		foreach ( $results as $result ) {
			$keys[ $result->original_object_id ] = $result->id;
		}

		$current_user = wp_get_current_user();

		$amazon_provision_lock = (bool) Amazon::get_transient_timeout( 'mv_create_amazon_provision' );

		return [
			'__URL__'           => esc_url_raw( rest_url() ),
			'__NONCE__'         => wp_create_nonce( 'wp_rest' ),
			'__ADMIN_URL__'     => esc_url_raw( admin_url() ),
			'__STATIC__'        => MV_CREATE_URL . 'ui/static',
			'__SETTINGS__'      => $settings,
			'__SHAPES__'        => $shapes,
			'__AUTHORS__'       => $sanitized_authors,
			'__MCP__'           => self::$mcp_data,
			'__KEY_LOOKUP__'    => $keys,
			'__CUSTOM_FIELDS__' => self::custom_fields(),
			'__META_BLOCKS__'   => Creations_Meta_Blocks::get_meta_block_slugs(),
			'__USER__'          => [
				'current_user_email'      => $current_user->user_email,
				'current_firstname'       => $current_user->user_firstname,
				'current_lastname'        => $current_user->user_lastname,
				'site_url'                => site_url(),
				'mediavine_publisher'     => self::$mcp_enabled,
				'current_user_authorized' => \Mediavine\Permissions::is_user_authorized(),
			],
			'__FLAGS__'         => [
				'NO_DOM_DOC'            => class_exists( 'DOMDocument' ) === false,
				'AMAZON_PROVISION_LOCK' => $amazon_provision_lock,
			],
			'__ALLOWED_TYPES__' => [
				json_decode( \Mediavine\Settings::get_setting( 'mv_create_allowed_types' ) ),
			],
		];
	}

	/**
	 * Gets fresh settings from the database and returns their translations
	 */
	private static function get_translated_settings() {
		// force the get_settings call to grab settings fresh from the database
		$saved_settings = (array) \Mediavine\Settings::get_settings(null, null, true);
		if ( 'en_US' === get_locale() ) {
			return $saved_settings;
		}
		$translated_settings = self::get_settings();
		$saved_slug_keys     = [];
		// let's set the array key for each slug of the settings from the DB
		// into a temporary array
		foreach ( $saved_settings as $key => $value ) {
			$saved_slug_keys[ $value->slug ] = $key;
		}
		// loop through all of the translated settings and if the slug exists
		// in our temporary array, use the key in the temp array
		// to update the string data/translations in the settings from the DB.
		foreach ( $translated_settings as $setting ) {
			if ( array_key_exists( $setting['slug'], $saved_slug_keys ) ) {
				$saved_settings[ $saved_slug_keys[ $setting['slug'] ] ]->data = $setting['data'];
			}
		}
		return $saved_settings;
	}
	private static function get_translated_shapes() {
		$shapes = \Mediavine\Create\Shapes::get_shapes();
		if ( 'en_US' === get_locale() ) {
			return $shapes;
		}
		$translated_shapes = self::get_shapes_data();
		$saved_slug_keys   = [];

		// we currently only use the plural string from SHAPES in the UI
		// let's make sure it's translated

		// let's set the array key for each slug of the shapes from the DB
		// into a temporary array
		foreach ( $shapes as $key => $value ) {
			$saved_slug_keys[ $value->slug ] = $key;
		}
		// loop through all of the translated shapes and if the slug exists
		// in our temporary array, use the key in the temp array
		// to update the string plural in the shapes from the DB.
		foreach ( $translated_shapes as $shape ) {
			if ( array_key_exists( $shape['slug'], $saved_slug_keys ) ) {
				$shapes[ $saved_slug_keys[ $shape['slug'] ] ]->plural = $shape['plural'];
			}
		}
		return $shapes;
	}

	public static function get_current_url() {
		$current_url = null;
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$current_url = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		return $current_url;
	}

	/**
	 * Check if we are on a Create admin URL
	 *
	 * @return boolean True if on a Create admin URL
	 */
	public static function is_create_admin_url() {
		$current_url = static::get_current_url();

		/**
		 * Filters the Create admin URL strings checked against
		 *
		 * @param array $mv_create_url_params List of URL strings to check
		 */
		$mv_create_url_params = apply_filters( 'mv_create_url_params', self::$mv_create_url_params );

		foreach ( $mv_create_url_params as $url_param_to_check ) {
			if ( strpos( $current_url, $url_param_to_check ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Outputs the Slate JS Chrome CSS fix if Chrome is detected as browser.
	 *
	 * While this is unreliable and can be spoofed, we are just using this to output CSS.
	 * If we can't detect, then we will output the CSS anyway. Pure CSS solution was found at
	 * https://github.com/ianstormtaylor/slate/issues/5119#issuecomment-1264590939.
	 */
	public function add_slate_chrome_fix() {
		// If no user agent, spoof it as chrome and add CSS anyway, because this info should always
		// be available.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- just a quick user agent check
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : 'Chrome';

		// If no Chrome, abort.
		if ( ! preg_match( '/Chrome/i', $user_agent ) ) {
			return;
		}

		// Make sure Edge isn't mimicking Chrome.
		if ( preg_match( '/Edge/i', $user_agent ) ) {
			return;
		}

		// Begin Chrome version check.
		preg_match_all( '/Chrome\/(\d+)/i', $user_agent, $versions );

		// If no verisons found, then we have something funny or spoofed, so add CSS fix anyway.
		if ( empty( $versions[1] ) ) {
			echo "<style>div[data-slate-editor]{-webkit-user-modify: read-write !important;}</style>";

			return;
		}

		// Only add CSS fix if Chrome version is 105 or greater.
		foreach ( $versions[1] as $version ) {
			if ( version_compare( (int) $version, 105, '>=' ) ) {
				echo "<style>div[data-slate-editor]{-webkit-user-modify: read-write !important;}</style>";
			}
		}
	}

	/**
	 * Enqueues the admin scripts on the page.
	 */
	function admin_enqueue_scripts() {
		wp_register_style( 'mv-font/open-sans', 'https://fonts.googleapis.com/css?family=Open+Sans:400,600,700' );

		// Pull Proxima Nova from CDN using correct protocol
		$proxima_nova_cdn = 'http://cdn.mediavine.com/fonts/ProximaNova/stylesheet.css';
		if ( is_ssl() ) {
			$proxima_nova_cdn = 'https://cdn.mediavine.com/fonts/ProximaNova/stylesheet.css';
		}
		wp_enqueue_style( 'mv-font/proxima-nova', $proxima_nova_cdn );

		$script_url      = Plugin::assets_url() . 'admin/ui/build/app.build.' . self::VERSION . '.js';
		$fake_script_url = Plugin::assets_url() . 'admin/ui/assets/fake-for-importers.js';

		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			$script_url = '//localhost:3000/app.build.' . self::VERSION . '.js';
			wp_enqueue_script( 'mv_create_dev_runtime', '//localhost:3000/runtime.build.' . self::VERSION . '.js', [], self::VERSION, true );
			wp_enqueue_script( 'mv_create_dev_vendor', '//localhost:3000/vendor.build.' . self::VERSION . '.js', [], self::VERSION, true );
		}

		if ( $this::is_create_admin_url() ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( 'mv_raven', 'https://cdn.ravenjs.com/3.25.2/raven.min.js', [], self::VERSION, true );
		wp_enqueue_style( 'mv-create-card/css' );

		$deps = [ 'mv_raven' ];


		if ( function_exists( 'is_gutenberg_page' ) && is_gutenberg_page() ) {
			$deps = array_merge( $deps, [ 'wp-plugins', 'wp-i18n', 'wp-element' ] );
		}

		wp_enqueue_script( Plugin::PLUGIN_DOMAIN . '/mv-create.js', $fake_script_url, $deps, self::VERSION, true );

		wp_register_script(
			Plugin::PLUGIN_DOMAIN .
			'-script',
			$script_url,
			$deps,
			self::VERSION,
			true
		);

		wp_localize_script( Plugin::PLUGIN_DOMAIN . '/mv-create.js', 'MV_CREATE', self::localization() );

		if ( ! wp_script_is( 'mv-blocks' ) ) {
			wp_set_script_translations( Plugin::PLUGIN_DOMAIN . '-script', 'mediavine', plugin_dir_path( __DIR__ ) . 'languages/' );
			wp_enqueue_script( Plugin::PLUGIN_DOMAIN . 'create-const' );
			wp_enqueue_script( Plugin::PLUGIN_DOMAIN . '-script' );
		}

		// Add CSS to fix Chrome editor if needed
		$this->add_slate_chrome_fix( $deps );
	}

	function admin_head() {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		// check if WYSIWYG is enabled
		if ( 'true' === get_user_option( 'rich_editing' ) ) {
			add_filter( 'tiny_mce_before_init', [ $this, 'tiny_mce_before_init' ] );
		}

		echo '<style>.post-type-mv_create #wpbody #wpbody-content { display: none };</style>';
	}

	function admin_footer() {
		echo '<div id="mv-gb-modal"></div>';
	}

	function admin_menu() {
		$shapes         = \Mediavine\Create\Shapes::get_shapes();
		$allowed_shapes = \Mediavine\Settings::get_setting( 'mv_create_allowed_types' );
		$allowed_shapes = json_decode( $allowed_shapes );

		$menu_keys = [
			'recipe' => __( 'Recipes', 'mediavine' ),
			'diy'    => __( 'How-Tos', 'mediavine' ),
			'list'   => __( 'Lists', 'medivine' ),
		];

		// normalize shapes list for backwards compatibility.
		foreach ( $shapes as $card ) {
			if (
				! array_key_exists( $card->slug, $menu_keys ) ||
				(
						! empty( $allowed_shapes ) && ! in_array( $card->slug, $allowed_shapes, true )
				)
			) {
				continue;
			}

			add_submenu_page(
				'edit.php?post_type=mv_create',
				$menu_keys[ $card->slug ],
				$menu_keys[ $card->slug ],
				'manage_options',
				$card->slug,
				[ $this, 'card_page' ]
			);
		}

		$static_pages = [];
		$static_pages[ __( 'Recommended Products', 'mediavine' ) ] = 'products';
		$static_pages[ __( 'User Reviews', 'mediavine' ) ]         = 'reviews';

		foreach ( $static_pages as $label => $value ) {
			add_submenu_page(
				'edit.php?post_type=mv_create',
				$label,
				$label,
				'manage_options',
				$value,
				[ $this, 'card_page' ]
			);
		}

		add_submenu_page(
			'edit.php?post_type=mv_create',
			__( 'Create by Mediavine Plugin Settings', 'mediavine' ),
			__( 'Settings', 'mediavine' ),
			'manage_options',
			'settings',
			[ $this, 'menu_page' ]
		);

		add_options_page(
			__( 'Create by Mediavine Plugin Settings', 'mediavine' ),
			__( 'Create by Mediavine', 'mediavine' ),
			'manage_options',
			'mv_settings',
			[ $this, 'menu_page' ]
		);
	}

	function card_page() {
		$screen_object = get_current_screen();
		$exploded      = explode( '_', $screen_object->base );
		$position      = count( $exploded ) - 1;
		$type          = $exploded[ $position ];
		?>
		<div id="MVRoot" data-type="<?php echo esc_html( $type ); ?>"></div>
		<?php
			}

			// Blank function prevents PHP notice
			function menu_page() {}

			function media_buttons( $editor_id ) {
				if ( 'content' !== $editor_id ) {
					return;
				}
				?>
		<div data-shortcode="mv_create"></div>
		<?php
	}

	/**
	 * Adds Create styles to TinyMCE (Classic Editor) load
	 *
	 * @param array $mceInit An array with TinyMCE config.
	 * @return array
	 */
	function tiny_mce_before_init( $mceInit ) {
		// Prevent PHP errors/notices as this can be filtered by other plugins
		if ( ! is_array( $mceInit ) ) {
			return $mceInit;
		}
		$content_css = Plugin::assets_url() . 'admin/ui/static/tinymce.css?' . self::VERSION;
		if ( ! empty( $mceInit['content_css'] ) ) {
			$mceInit['content_css'] .= ', ' . $content_css;
		} else {
			$mceInit['content_css'] = $content_css;
		}

		return $mceInit;
	}

	/**
	 * Register the block categories.
	 *
	 * @param array $categories An array of categories available to the editors
	 * @return array $categories
	 */
	public function block_categories( $categories = [] ) {
		// TODO: the following page check should no longer be needed once a fix for the following issue is released
		// https://github.com/WordPress/gutenberg/issues/28517
		global $pagenow;
		if ( 'widgets.php' === $pagenow || 'customize.php' === $pagenow ) {
			// This is a widgets block editor.  We only want our blocks registered for post/page editors.
			return $categories;
		} else {
			$merged = array_merge(
				$categories,
				[
					[
						'slug'  => 'mediavine-create',
						'title' => __( 'Create by Mediavine', 'mediavine' ),
						'icon'  => 'mediavine',
					],
				]
			);
			return $merged;
		}
	}

	function init() {
		global $wp_version;
		// version-check for filter compatibility
		$block_categories_filter = 'block_categories';
		if ( version_compare( $wp_version, '5.8', '>=' ) ) {
			$block_categories_filter = 'block_categories_all';
		}

		add_action( 'admin_head', [ $this, 'admin_head' ] );
		add_action( 'admin_footer', [ $this, 'admin_footer' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 11 );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'media_buttons', [ $this, 'media_buttons' ] );
		add_filter( $block_categories_filter, [ $this, 'block_categories' ], 10, 1 );
	}

}
