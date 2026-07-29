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
		'page=mv_create_welcome',
		'page=create_home',
		'page=create_editor',
		'page=create_dashboard',
		'page=import',
		'page=theme_elements',
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

		// Never ship credential-class values to the browser. The site JWT is
		// reduced to a presence flag for everyone; other credentials (e.g. the
		// Amazon Creators secret) are only kept for users who can manage them.
		$settings = Sensitive_Settings::redact( $settings, current_user_can( 'manage_options' ) );

		$shapes   = self::get_translated_shapes();

		self::$mcp_data = self::get_mcp_data();

		$args = [
			'capability' => [ 'edit_posts' ],
			'fields'     => [ 'display_name' ],
		];

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

		$key_match_statement = "SELECT id, original_object_id from {$wpdb->prefix}mv_creations WHERE original_object_id IS NOT NULL AND original_object_id != 0";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$results             = $wpdb->get_results( $key_match_statement );
		$keys                = [];
		foreach ( $results as $result ) {
			$keys[ $result->original_object_id ] = $result->id;
		}

		$current_user = wp_get_current_user();

		$amazon_provision_lock = (bool) Amazon_Creators::get_transient_timeout( 'mv_create_amazon_provision' );

		return [
			'__VERSION__'            => Plugin::VERSION,
			'__WP_VERSION__'         => $GLOBALS['wp_version'],
			'__PHP_VERSION__'        => PHP_VERSION,
			'__URL__'                => esc_url_raw( rest_url() ),
			'__NONCE__'              => wp_create_nonce( 'wp_rest' ),
			'__ADMIN_NONCE__'        => wp_create_nonce( 'mv_create_admin' ),
			'__ADMIN_URL__'          => esc_url_raw( admin_url() ),
			'__STATIC__'             => MV_CREATE_URL . 'ui/static',
			'__SITE_URL__'           => esc_url_raw( site_url() ),
			'__CREATE_STUDIO_URL__'  => esc_url_raw( self::$create_studio_base_url ),
			'__CURRENT_USER_EMAIL__' => $current_user->user_email,
			'__SETTINGS__'           => $settings,
			'__SERVICES_URL__'       => esc_url_raw( self::$js_services_api_url ),
			'__SHAPES__'             => $shapes,
			'__AUTHORS__'            => $sanitized_authors,
			'__MCP__'                => self::$mcp_data,
			'__KEY_LOOKUP__'         => $keys,
			'__CUSTOM_FIELDS__'      => self::custom_fields(),
			'__META_BLOCKS__'        => Creations_Meta_Blocks::get_meta_block_slugs(),
			'__USER__'               => [
				'current_user_email'      => $current_user->user_email,
				'studio_email'            => User_Verification_Meta::get_email( $current_user->ID ),
				'current_firstname'       => $current_user->user_firstname,
				'display_name'            => $current_user->display_name,
				'current_lastname'        => $current_user->user_lastname,
				'avatar_url'              => get_avatar_url( $current_user->ID, [ 'size' => 96 ] ),
				'site_url'                => esc_url_raw( site_url() ),
				'mediavine_publisher'     => self::$mcp_enabled,
				'current_user_authorized' => \Mediavine\Permissions::is_user_authorized(),
			],
			'__FLAGS__'              => [
				'NO_DOM_DOC'            => class_exists( 'DOMDocument' ) === false,
				'AMAZON_PROVISION_LOCK' => $amazon_provision_lock,
				'DEV_MODE'              => Plugin::is_dev_mode(),
			],
			'__ALLOWED_TYPES__'      => [
				json_decode( \Mediavine\Settings::get_setting( 'mv_create_allowed_types' ) ),
			],
			'__SERVING_ADJUSTMENT_LABEL__' => \Mediavine\Settings::get_setting( 'mv_create_servings_adjustment_label' ),
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
		if ( ! is_string( $current_url ) || '' === $current_url ) {
			return false;
		}

		/**
		 * Filters the Create admin URL strings checked against
		 *
		 * @param array $mv_create_url_params List of URL strings to check
		 */
		$mv_create_url_params = apply_filters( 'mv_create_url_params', self::$mv_create_url_params );

		foreach ( $mv_create_url_params as $url_param_to_check ) {
			if ( strpos( $current_url, $url_param_to_check ) !== false ) {
				return true;
			}
		}

		// For post.php and post-new.php, check if ANY editor is present
		// This covers all post types including Ultimate Recipe, Osetin, and custom post types
		if ( strpos( $current_url, 'post.php' ) !== false || strpos( $current_url, 'post-new.php' ) !== false ) {
			$screen = get_current_screen();

			// Gutenberg/block editor
			if ( $screen && ! empty( $screen->is_block_editor ) ) {
				return true;
			}

			// Classic editor - check if post type supports editor
			if ( $screen && $screen->post_type && post_type_supports( $screen->post_type, 'editor' ) ) {
				return true;
			}

			// Fallback: if screen exists and has an edit base, load Create
			if ( $screen && $screen->base === 'post' ) {
				return true;
			}

			return false;
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
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Chrome';

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
			echo '<style>div[data-slate-editor]{-webkit-user-modify: read-write !important;}</style>';

			return;
		}

		// Only add CSS fix if Chrome version is 105 or greater.
		foreach ( $versions[1] as $version ) {
			if ( version_compare( (int) $version, 105, '>=' ) ) {
				echo '<style>div[data-slate-editor]{-webkit-user-modify: read-write !important;}</style>';
			}
		}
	}

	/**
	 * Enqueues the admin scripts on the page.
	 */
	function admin_enqueue_scripts() {
		$script_url = Plugin::assets_url() . 'admin/ui/build/app.build.' . self::VERSION . '.js';

		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			$dev_port   = apply_filters( 'mv_create_dev_port', defined( 'MV_CREATE_DEV_PORT' ) ? MV_CREATE_DEV_PORT : 3000 );
			$script_url = 'http://localhost:' . $dev_port . '/app.build.' . self::VERSION . '.js';
		}

		if ( $this::is_create_admin_url() ) {
			wp_enqueue_media();

			// Self-hosted fonts (both SIL OFL 1.1), served from the plugin over the
			// site's own scheme. Only loaded on Create's own admin screens.
			// Nunito is the primary UI typeface; Fraunces is the display face used
			// for the dashboard headings.
			wp_enqueue_style(
				'mv-font/nunito',
				Plugin::assets_url() . 'assets/fonts/nunito/nunito.css',
				[],
				self::VERSION
			);
			wp_enqueue_style(
				'mv-font/fraunces',
				Plugin::assets_url() . 'assets/fonts/fraunces/fraunces.css',
				[],
				self::VERSION
			);

			// Core dependencies that should always be loaded
			$deps = [ 'lodash', 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-data' ];

			// Add editor dependencies when available (for Gutenberg sidebar/plugins)
			if ( function_exists( 'is_gutenberg_page' ) && is_gutenberg_page() ) {
				$deps = array_merge( $deps, [ 'wp-plugins', 'wp-editor' ] );
			}

			// In block editor context, ensure we load before the editor initializes
			$screen = get_current_screen();
			$in_footer = true;
			if ( $screen && ! empty( $screen->is_block_editor ) ) {
				// Load in header for block editor to ensure blocks register before editor parses content
				$in_footer = false;
				$deps[] = 'wp-edit-post';
			}

			wp_register_script(
				Plugin::PLUGIN_DOMAIN . '-script',
				$script_url,
				$deps,
				self::VERSION,
				$in_footer
			);

			wp_localize_script( Plugin::PLUGIN_DOMAIN . '-script', 'MV_CREATE', self::localization() );

			if ( ! wp_script_is( 'mv-blocks' ) ) {
				wp_set_script_translations( Plugin::PLUGIN_DOMAIN . '-script', 'mediavine-create', plugin_dir_path( __DIR__ ) . 'languages/' );
				wp_enqueue_script( Plugin::PLUGIN_DOMAIN . '-script' );
			}

			// Add CSS to fix Chrome editor if needed
			$this->add_slate_chrome_fix();
		}
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

		// Router page — hidden submenu entry that redirects to the user's
		// preferred default page via maybe_redirect_default_admin_page().
		add_submenu_page(
			'edit.php?post_type=mv_create',
			__( 'Create', 'mediavine-create' ),
			__( 'Create', 'mediavine-create' ),
			'edit_posts',
			'create_home',
			'__return_null'
		);

		// Dashboard page
		add_submenu_page(
			'edit.php?post_type=mv_create',
			__( 'Dashboard', 'mediavine-create' ),
			__( 'Dashboard', 'mediavine-create' ),
			'manage_options',
			'create_dashboard',
			[ $this, 'dashboard_page' ]
		);

		$menu_keys = [
			'recipe' => __( 'Recipes', 'mediavine-create' ),
			'diy'    => __( 'How-Tos', 'mediavine-create' ),
			'list'   => __( 'Lists', 'mediavine-create' ),
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
		$static_pages[ __( 'Recommended Products', 'mediavine-create' ) ] = 'products';
		$static_pages[ __( 'User Reviews', 'mediavine-create' ) ]         = 'reviews';

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
			__( 'Create Plugin Settings', 'mediavine-create' ),
			__( 'Settings', 'mediavine-create' ),
			'manage_options',
			'settings',
			[ $this, 'menu_page' ]
		);

		add_options_page(
			__( 'Create Plugin Settings', 'mediavine-create' ),
			__( 'Create', 'mediavine-create' ),
			'manage_options',
			'mv_settings',
			[ $this, 'menu_page' ]
		);

		// Editor page under Create menu. Registered under the real parent so
		// WordPress keeps the submenu open and handles capabilities correctly.
		// The menu item is hidden via CSS in editor_hide_menu_item().
		add_submenu_page(
			'edit.php?post_type=mv_create',
			__( 'Edit Create Card', 'mediavine-create' ),
			__( 'Edit Card', 'mediavine-create' ),
			'edit_posts',
			'create_editor',
			[ $this, 'editor_page' ]
		);

		// Hidden welcome page (no menu item)
		add_submenu_page(
			'',
			__( 'Welcome to Create 2.0', 'mediavine-create' ),
			__( 'Welcome', 'mediavine-create' ),
			'manage_options',
			'mv_create_welcome',
			[ $this, 'welcome_page' ]
		);

		// Theme Elements page (dev mode only)
		if ( Plugin::is_dev_mode() ) {
			add_submenu_page(
				'edit.php?post_type=mv_create',
				__( 'Theme Elements', 'mediavine-create' ),
				__( 'Theme Elements', 'mediavine-create' ),
				'manage_options',
				'theme_elements',
				[ $this, 'theme_elements_page' ]
			);
		}

		// Reorder submenu and set the parent menu href to the router page.
		//
		// WordPress uses $submenu[$parent][0][2] as the href for the top-level
		// menu item. For CPT menus, position 0 is auto-generated as "All {type}"
		// with the slug set to the full parent URL (e.g. edit.php?post_type=mv_create).
		// We change position 0's slug to the router URL so the parent menu link
		// goes through the router, then add "All Create Cards" as its own entry.
		global $submenu;
		$parent = 'edit.php?post_type=mv_create';
		if ( isset( $submenu[ $parent ] ) ) {
			$dashboard = null;

			foreach ( $submenu[ $parent ] as $key => $item ) {
				if ( 'create_dashboard' === $item[2] ) {
					$dashboard = $item;
					unset( $submenu[ $parent ][ $key ] );
				} elseif ( 'create_home' === $item[2] ) {
					unset( $submenu[ $parent ][ $key ] );
				}
			}

			$submenu[ $parent ] = array_values( $submenu[ $parent ] );

			// Position 0 is the auto-generated "All Create Cards" entry.
			// Change its slug to the router URL so clicking the top-level
			// "Create" menu item goes through the router. Keep the label.
			if ( isset( $submenu[ $parent ][0] ) ) {
				$all_cards_label = $submenu[ $parent ][0][0];
				$all_cards_cap   = $submenu[ $parent ][0][1];

				// Point position 0 to the router page (full URL so WP uses it directly).
				$submenu[ $parent ][0][2] = 'edit.php?post_type=mv_create&page=create_home';

				// Re-add "All Create Cards" as a real submenu entry at position 2.
				$all_cards_item = [ $all_cards_label, $all_cards_cap, $parent ];
				array_splice( $submenu[ $parent ], 1, 0, [ $all_cards_item ] );
			}

			// Insert Dashboard at position 1 (right after the router, before All Cards).
			if ( $dashboard ) {
				array_splice( $submenu[ $parent ], 1, 0, [ $dashboard ] );
			}
		}
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

			function dashboard_page() {
			?>
			<div id="MVRoot" data-page="dashboard"></div>
			<?php
		}

		function editor_page() {
			?>
			<div id="MVRoot" data-page="editor"></div>
			<?php
		}

		function welcome_page() {
				?>
				<div id="MVRoot" data-page="welcome"></div>
				<?php
			}

		function theme_elements_page() {
			?>
			<div id="MVRoot" data-page="theme-elements"></div>
			<?php
		}

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
						'title' => __( 'Create', 'mediavine-create' ),
						'icon'  => 'mediavine',
					],
				]
			);
			return $merged;
		}
	}

	/**
	 * Register the Create script early so it can be referenced by block registration.
	 * This runs on 'init' before register_gutenberg_blocks.
	 */
	function register_create_script() {
		$script_url  = Plugin::assets_url() . 'admin/ui/build/app.build.' . self::VERSION . '.js';
		$is_dev_mode = apply_filters( 'mv_create_dev_mode', false );

		if ( $is_dev_mode ) {
			$dev_port   = apply_filters( 'mv_create_dev_port', defined( 'MV_CREATE_DEV_PORT' ) ? MV_CREATE_DEV_PORT : 3000 );
			$script_url = 'http://localhost:' . $dev_port . '/app.build.' . self::VERSION . '.js';
		}

		// Register script early with block editor dependencies
		wp_register_script(
			Plugin::PLUGIN_DOMAIN . '-script',
			$script_url,
			[ 'lodash', 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-api-fetch', 'wp-data' ],
			self::VERSION,
			false // Load in header
		);
	}

	/**
	 * Register Gutenberg blocks server-side
	 *
	 * This server-side registration provides:
	 * - Block type recognition so Gutenberg doesn't show "unsupported block" errors
	 * - Attribute schema for proper serialization
	 * - Render callback for frontend output
	 * - Script dependency so WordPress loads our script when blocks are used
	 */
	function register_gutenberg_blocks() {
		// Get allowed types from settings
		$allowed_shapes = \Mediavine\Settings::get_setting( 'mv_create_allowed_types' );
		$allowed_shapes = json_decode( $allowed_shapes );
		// Default to all types if none specified
		if ( empty( $allowed_shapes ) ) {
			$allowed_shapes = ['recipe', 'list', 'diy'];
		}

		// Register blocks for each allowed type
		foreach ( $allowed_shapes as $type ) {
			$block_name = "mv/{$type}";

			// Skip if block is already registered (prevents re-registration in test environments)
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				continue;
			}

			register_block_type( $block_name, [
				'api_version' => 3,
				'editor_script' => Plugin::PLUGIN_DOMAIN . '-script',
				'render_callback' => [ $this, 'render_block' ],
				'attributes' => [
					'id' => [
						'type' => 'number',
					],
					'title' => [
						'type' => 'string',
						'default' => '',
					],
					'thumbnail_uri' => [
						'type' => 'string',
						'default' => '',
					],
					'type' => [
						'type' => 'string',
						'default' => $type,
					],
					'layout' => [
						'type' => 'string',
					],
				],
			] );
		}
	}
	
	/**
	 * Render callback for Gutenberg blocks
	 */
	function render_block( $attributes ) {
		$id = isset( $attributes['id'] ) ? $attributes['id'] : '';
		$title = isset( $attributes['title'] ) ? $attributes['title'] : '';
		$thumbnail = isset( $attributes['thumbnail_uri'] ) ? $attributes['thumbnail_uri'] : '';
		$type = isset( $attributes['type'] ) ? $attributes['type'] : 'recipe';
		$layout = isset( $attributes['layout'] ) ? $attributes['layout'] : '';
		
		// Build shortcode
		$shortcode = "[mv_create key=\"{$id}\" type=\"{$type}\" title=\"{$title}\" thumbnail=\"{$thumbnail}\"";
		if ( !empty( $layout ) ) {
			$shortcode .= " layout=\"{$layout}\"";
		}
		$shortcode .= "]";
		
		return $shortcode;
	}

	/**
	 * Enqueue scripts specifically for the block editor.
	 * This runs at the right time for Gutenberg integration.
	 */
	function enqueue_block_editor_assets() {
		// The main admin_enqueue_scripts might not have run yet, so register the script here too
		$script_url = Plugin::assets_url() . 'admin/ui/build/app.build.' . self::VERSION . '.js';

		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			$dev_port   = apply_filters( 'mv_create_dev_port', defined( 'MV_CREATE_DEV_PORT' ) ? MV_CREATE_DEV_PORT : 3000 );
			$script_url = 'http://localhost:' . $dev_port . '/app.build.' . self::VERSION . '.js';
		}

		// If script is not registered yet, register it
		if ( ! wp_script_is( Plugin::PLUGIN_DOMAIN . '-script', 'registered' ) ) {
			$deps = [ 'lodash', 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-edit-post', 'wp-api-fetch', 'wp-data' ];

			wp_register_script(
				Plugin::PLUGIN_DOMAIN . '-script',
				$script_url,
				$deps,
				self::VERSION,
				false // Load in header for block editor
			);

			wp_localize_script( Plugin::PLUGIN_DOMAIN . '-script', 'MV_CREATE', self::localization() );
			wp_set_script_translations( Plugin::PLUGIN_DOMAIN . '-script', 'mediavine-create', plugin_dir_path( __DIR__ ) . 'languages/' );
		}

		wp_enqueue_script( Plugin::PLUGIN_DOMAIN . '-script' );
	}

	/**
	 * Highlight the correct submenu item (Recipes, How-Tos, Lists) when on the editor page.
	 *
	 * @param string $submenu_file The submenu file slug.
	 * @return string
	 */
	function editor_submenu_file( $submenu_file ) {
		global $plugin_page;
		if ( 'create_editor' === $plugin_page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
			if ( $type ) {
				return $type;
			}
		}
		return $submenu_file;
	}

	/**
	 * Hide the "Edit Card" link from the Create submenu sidebar via CSS.
	 *
	 * The editor page is registered under the real Create parent so WordPress
	 * can properly highlight the menu. But we don't want the "Edit Card" link
	 * visible in the sidebar — it's accessed via collection/card links.
	 */
	function editor_hide_menu_item() {
		?>
		<style>
			#adminmenu .wp-submenu a[href*="page=create_home"],
			#adminmenu a[href*="page=create_editor"] { display: none !important; }
		</style>
		<?php
	}

	/**
	 * Redirect post.php Create editor URLs to admin.php?page=create_editor
	 * and repair bad object_id values on the editor page.
	 *
	 * WordPress core's post.php validates the post type of the loaded post before
	 * our React app can mount. If a creation's object_id points to a non-mv_create
	 * post (e.g., wprm_recipe from an old import), post.php dies with "Invalid post type."
	 *
	 * This hook:
	 * 1. Redirects post.php?post_type=mv_create URLs to admin.php?page=create_editor
	 *    to avoid WordPress core's post type validation entirely.
	 * 2. On the admin.php editor page, repairs the creation's object_id if it doesn't
	 *    point to a valid mv_create post.
	 */
	function maybe_repair_creation_object_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check
		$current_url = static::get_current_url();
		if ( ! $current_url ) {
			return;
		}

		// Redirect post.php Create editor URLs to admin.php?page=create_editor
		if (
			strpos( $current_url, 'post.php' ) !== false &&
			strpos( $current_url, 'post_type=mv_create' ) !== false &&
			strpos( $current_url, 'action=edit' ) !== false
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : null;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : null;

			if ( $id && $type ) {
				$redirect_url = admin_url( 'admin.php?page=create_editor&id=' . $id . '&type=' . $type );
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}

		// On the admin.php editor page, repair bad object_id values
		if ( strpos( $current_url, 'page=create_editor' ) === false ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$creation_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : null;
		if ( ! $creation_id ) {
			return;
		}

		// Look up the creation
		$creation = self::$models_v2->mv_creations->find_one( $creation_id );
		if ( ! $creation || ! isset( $creation->object_id ) ) {
			return;
		}

		// Check if object_id points to a valid mv_create post
		$post = get_post( $creation->object_id );
		if ( $post && 'mv_create' === $post->post_type ) {
			return; // Already correct
		}

		// object_id is missing or points to wrong post type — create a new mv_create post
		$mv_create_post_id = wp_insert_post(
			[
				'post_title'  => $creation->title ?? '',
				'post_type'   => 'mv_create',
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $mv_create_post_id ) ) {
			return;
		}

		// Update the creation's object_id
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'        => $creation_id,
				'object_id' => $mv_create_post_id,
			]
		);
	}

	/**
	 * Redirect the create_home router page to the user's preferred admin page.
	 *
	 * Runs on admin_init (before output) so we can safely redirect.
	 */
	function maybe_redirect_default_admin_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'create_home' !== $_GET['page'] ) {
			return;
		}

		$default_page = \Mediavine\Settings::get_setting( 'mv_create_default_admin_page' );

		$slug_map = [
			'dashboard' => 'create_dashboard',
			'all_cards' => null, // special case — go to the CPT list
			'recipe'    => 'recipe',
			'diy'       => 'diy',
			'list'      => 'list',
		];

		// Default to dashboard if the setting is empty or unrecognised.
		if ( empty( $default_page ) || ! array_key_exists( $default_page, $slug_map ) ) {
			$default_page = 'dashboard';
		}

		if ( null === $slug_map[ $default_page ] ) {
			// "All Cards" — the CPT list table with no page param.
			$url = admin_url( 'edit.php?post_type=mv_create' );
		} else {
			$url = admin_url( 'edit.php?post_type=mv_create&page=' . $slug_map[ $default_page ] );
		}

		wp_safe_redirect( $url );
		exit;
	}

	function init() {
		global $wp_version;
		// version-check for filter compatibility
		$block_categories_filter = 'block_categories';
		if ( version_compare( $wp_version, '5.8', '>=' ) ) {
			$block_categories_filter = 'block_categories_all';
		}

		add_action( 'admin_init', [ $this, 'maybe_redirect_default_admin_page' ] );
		add_action( 'admin_init', [ $this, 'maybe_repair_creation_object_id' ] );
		add_filter( 'submenu_file', [ $this, 'editor_submenu_file' ] );
		add_action( 'admin_head', [ $this, 'editor_hide_menu_item' ] );
		add_action( 'admin_head', [ $this, 'admin_head' ] );
		add_action( 'admin_footer', [ $this, 'admin_footer' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 11 );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'media_buttons', [ $this, 'media_buttons' ] );
		// Register script first (priority 5), then blocks (priority 10)
		add_action( 'init', [ $this, 'register_create_script' ], 5 );
		add_action( 'init', [ $this, 'register_gutenberg_blocks' ], 10 );
		add_filter( $block_categories_filter, [ $this, 'block_categories' ], 10, 1 );
		// Also enqueue for block editor specifically with early priority
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ], 1 );
	}

}
