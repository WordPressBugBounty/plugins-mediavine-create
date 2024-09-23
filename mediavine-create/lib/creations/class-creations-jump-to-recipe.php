<?php
namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Jump To Recipe feature.
 */
class Creations_Jump_To_Recipe extends Creations_Views {

	public static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	function init() {
		$this->add_jtr_link_hooks();

		add_action( 'mv_create_card_before', [ $this, 'insert_jtr_hint' ] );
		add_action( 'mv_create_card_after', [ $this, 'close_jtr_hint' ] );

		add_filter( 'safe_style_css', [ $this, 'add_display_to_safe_css' ] );

		add_shortcode( 'mv_create_jtr', [ $this, 'mv_jump_to_recipe_shortcode' ] );
	}

	/**
	 * Adds the hooks required to output the Jump to Recipe button and screen reader skip link
	 *
	 * @return void
	 */
	public function add_jtr_link_hooks() {
		if ( Theme_Checker::is_trellis() ) {
			// Output button within <aside> if on supported version of Trellis
			if ( function_exists( 'mvt_aside_before_entry_content' ) ) {
				add_action( 'tha_aside_before_entry_content', [ $this, 'add_mv_jtr_before_article' ], 20 );
			} else {
				add_action( 'tha_entry_before', [ $this, 'add_mv_jtr_before_article' ], 555 );
			}
			add_action( 'tha_header_before', [ $this, 'add_screen_reader_jtr_link' ] );
		} elseif ( Theme_Checker::is_genesis() ) {
			add_action( 'genesis_before_entry_content', [ $this, 'add_mv_jtr_before_article' ], 555 );
			add_action( 'genesis_before_header', [ $this, 'add_screen_reader_jtr_link' ] );
		} elseif ( Theme_Checker::is_tha_enabled( [ 'all', 'entry' ] ) ) {
			add_action( 'tha_entry_content_before', [ $this, 'add_mv_jtr_before_article' ], 20 );
			add_action( 'tha_header_before', [ $this, 'add_screen_reader_jtr_link' ] );
		} else {
			/**
			 * Filters the hook used for the jump to recipe button output
			 *
			 * @param string $jtr_button_filter
			 */
			$jtr_button_filter = apply_filters( 'mv_create_jtr_button_filter', 'the_content' );
			add_filter( $jtr_button_filter, [ $this, 'add_mv_jump_to_recipe_shortcode' ] );

			/**
			 * Filters the hook used for the jump to recipe screen reader skip link output
			 *
			 * @param string $jtr_button_filter
			 */
			$jtr_screen_reader_filter = apply_filters( 'mv_create_jtr_screen_reader_filter', 'wp_footer' );
			add_filter( $jtr_screen_reader_filter, [ $this, 'add_screen_reader_jtr_link' ] );
		}
	}

	/**
	 * Checks if Jump to recipe feature is enabled for specific post/page
	 *
	 * @return boolean True if enabled, false if not
	 */
	public function is_jtr_enabled() {
		global $post;
		if ( ! Settings::get_setting( 'mv_create_enable_jump_to_recipe', false ) ) {
			return false;
		}
		if ( ! is_singular() ) {
			return false;
		}
		// Only display if there's a post
		if ( ! empty( $post->ID ) ) {
			// JTR enabled by default if we are to this point, so only disable if disable is set to true
			$enable_jtr = true;
			if ( ! empty( get_post_meta( $post->ID, 'disable-jtr', true ) ) ) {
				$enable_jtr = false;
			}

			return $enable_jtr;
		}

		return false;
	}

	public function insert_jtr_continue_link( $atts ) {
		// check for active registration before starting - return early
		$jtr_enabled       = \Mediavine\Settings::get_setting( self::$settings_group . '_enable_jump_to_recipe', false );
		$api_token_setting = \Mediavine\Settings::get_settings( self::$settings_group . '_api_token' );

		if ( ! $api_token_setting || ! $jtr_enabled ) {
			return;
		}

		// Only display on singular posts
		if ( ! is_singular() ) {
			return;
		}

		$link_text = 'Continue to Content';

		$force_uppercase = \Mediavine\Settings::get_setting( self::$settings_group . '_force_uppercase' );

		$atts = shortcode_atts(
			[
				'id'        => null,
				'type'      => 'recipe',
				'link_text' => $link_text,
			],
			$atts,
			'mv_create_jtr'
		);

		$class_names = [ 'mv-create-jtr', 'mv-create-jtr-link' ];

		if ( $force_uppercase ) {
			$class_names[] = 'mv-create-jtr-button-uppercase';
		}

		$svg_caret = '<svg class="mv-create-jtr-caret" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1"  viewBox="0 0 444.819 444.819" width="16px" height="16px"><path d="M434.252,114.203l-21.409-21.416c-7.419-7.04-16.084-10.561-25.975-10.561c-10.095,0-18.657,3.521-25.7,10.561 L222.41,231.549L83.653,92.791c-7.042-7.04-15.606-10.561-25.697-10.561c-9.896,0-18.559,3.521-25.979,10.561l-21.128,21.416 C3.615,121.436,0,130.099,0,140.188c0,10.277,3.619,18.842,10.848,25.693l185.864,185.865c6.855,7.23,15.416,10.848,25.697,10.848 c10.088,0,18.75-3.617,25.977-10.848l185.865-185.865c7.043-7.044,10.567-15.608,10.567-25.693 C444.819,130.287,441.295,121.629,434.252,114.203z"/></svg>';

		$output = sprintf(
			'<div class="mv-create-jtr-continue" style="display:none;"><a href="#mv-creation-%s" class="%s">%s %s</a></div>',
			esc_attr( $atts['id'] ),
			esc_attr( implode( ' ', $class_names ) ),
			$svg_caret,
			esc_html( $atts['link_text'] )
		);

		return $output;
	}

	/**
	 * Adds an additional ad at the beginning of the recipe if JTR is enabled
	 *
	 * @param array $args Args passed from do_action
	 * @return void
	 */
	public function insert_jtr_hint( $args ) {
		// Only add extra ad if JTR is enabled
		if ( $this->is_jtr_enabled() && 'list' !== $args['type'] ) {
			?>
			<div id="mv-creation-<?php echo esc_attr( $args['creation']['id'] ); ?>-jtr-hint-wrapper" class="mv-create-jtr-hint-wrapper">
			<div id="mv-creation-<?php echo esc_attr( $args['creation']['id'] ); ?>-jtr" class="mv-pre-create-target">
				<?php
				echo wp_kses(
					$this->insert_jtr_continue_link(
						[
							'id'   => $args['key'],
							'type' => $args['type'],
						]
					),
					[
						'a'     => [
							'href'  => true,
							'class' => true,
						],
						'div'   => [
							'class' => true,
							'id'    => true,
							'style' => true,
						],
						'svg'   => [
							'class'           => true,
							'aria-hidden'     => true,
							'aria-labelledby' => true,
							'xmlns'           => true,
							'width'           => true,
							'height'          => true,
							'viewbox'         => true, // <= Must be lower case!
						],
						'g'     => [ 'fill' => true ],
						'title' => [ 'title' => true ],
						'path'  => [
							'd'    => true,
							'fill' => true,
						],
					]
				);
				?>
			</div>
			<?php
		}
	}

	/**
	 * Closes JTR hint wrapper
	 *
	 * @return void
	 */
	public function close_jtr_hint( $args ) {
		if ( $this->is_jtr_enabled() && 'list' !== $args['type'] ) {
		?>
			</div>
		<?php
		}
	}

	/**
	 * Globally adds jump to recipe shortcode on matching pages
	 *
	 * @param string $content Post/page content
	 * @return string Post/page content
	 */
	public function add_mv_jump_to_recipe_shortcode( $content ) {
		// Bounce before everything else if custom button is supported
		if ( $this->theme_supports_custom_jtr() ) {
			return $content;
		}

		// Return early if not singular or setting disabled, or recipe create card doesn't exist
		if (
			! $this->is_jtr_enabled() ||
			! has_shortcode( $content, 'mv_create' )
		) {
			return $content;
		}

		$jtr_button = $this->build_mv_jtr_shortcode( $content );

		// Adds button to top of page if not filtered out
		$display_jtr = apply_filters( 'mv_create_auto_output_jtr_shortcode', true );
		if ( $display_jtr ) {
			return $jtr_button . $content;
		}

		return $content;
	}

	/**
	 * Builds [mv_create_jtr] shortcode for page output
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function build_mv_jtr_shortcode( $content ) {
		$atts = self::get_jtr_atts( $content );
		if ( ! $atts ) {
			return '';
		}

		$jtr_button = '[mv_create_jtr id="' . $atts['id'] . '" type="' . $atts['type'] . '"]';

		return $jtr_button;
	}

	/**
	 * Parse content for mv_create shortcode and return needed arguments
	 * @param string $content
	 *
	 * @return bool|array
	 */
	public static function get_jtr_atts( $content ) {
		// https://regex101.com/r/dh45kM/8
		// groups <dummy1> and <dummy2> are non-matching placeholder groups that allow the regex to backtrack
		// and ensure `key` and `type` are in the shortcode in no particular order
		// https://regex101.com/r/dh45kM/8/tests
		$re = '/\[mv_create .*(?:key=\"(?<key>\d+)\".*(?<dummy1>)|type=\"?(?<type>\w+)\".*(?<dummy2>)){2}\k<dummy2>\k<dummy1>.*\]/mU';
		preg_match_all( $re, $content, $matches, PREG_OFFSET_CAPTURE, 0 );

		// Return early if no mv_create shortcode found
		if ( empty( $matches['key'] ) ) {
			return false;
		}

		$id = $matches['key'][0];

		// If no type set to recipe
		$type = 'recipe';
		$id   = false;
		if ( ! empty( $matches['type'] ) ) {
			$index = 0;
			foreach ( $matches['type'] as $match_type ) {
				if ( 'list' !== $match_type[0] ) {
					$type = $match_type[0];
					$id   = $matches['key'][ $index ][0];
					break;
				}
				$type = $match_type[0];
				$index++;
			}

			// Return early if list is the shortcode type
			if ( 'list' === $matches['type'][0] ) {
				return false;
			}
		}

		return [
			'id'   => $id,
			'type' => $type,
		];
	}

	/**
	 * Adds the JTR button before the article if Trellis or Genesis
	 * @global \WP_Post $post
	 */
	public function add_mv_jtr_before_article() {
		global $post;

		// bounce early if the theme supports custom buttons
		if ( $this->theme_supports_custom_jtr() ) {
			return;
		}

		$content = get_post_field( 'post_content', $post );

		// Return early if JTR disabled or there's no create shortcode or we are not singular
		if (
			! $this->is_jtr_enabled() ||
			! has_shortcode( $content, 'mv_create' ) ||
			! is_singular( $post )
		) {
			return;
		}

		$jtr_button  = $this->build_mv_jtr_shortcode( $content );
		$display_jtr = apply_filters( 'mv_create_auto_output_jtr_shortcode', true );
		if ( $display_jtr ) {
			echo do_shortcode( $jtr_button );
		}
	}

	/**
	 * [mv_create_jtr] Jump To Recipe shortcode
	 *
	 * @param array $atts possible shortcode setting for link_text
	 * @return string|void
	 */
	public function mv_jump_to_recipe_shortcode( $atts ) {
		// Must have an ID
		if ( empty( $atts['id'] ) ) {
			return;
		}

		// Don't output JTR on list types
		if ( ! empty( $atts['type'] ) && 'list' === $atts['type'] ) {
			return;
		}

		// check for active registration before starting - return early
		$jtr_enabled       = \Mediavine\Settings::get_setting( self::$settings_group . '_enable_jump_to_recipe', false );
		$api_token_setting = \Mediavine\Settings::get_settings( self::$settings_group . '_api_token' );

		if ( ! $api_token_setting || ! $jtr_enabled ) {
			return;
		}

		// Only display on singular posts
		if ( ! is_singular() ) {
			return;
		}

		$link_text = \Mediavine\Settings::get_setting( self::$settings_group . '_jump_to_recipe_text', __( 'Jump to Recipe', 'mediavine' ) );
		if ( isset( $atts['type'] ) && 'diy' === $atts['type'] ) {
			$link_text = \Mediavine\Settings::get_setting( self::$settings_group . '_jump_to_howto_text', __( 'Jump to How-To', 'mediavine' ) );
		}

		$btn_style = \Mediavine\Settings::get_setting( self::$settings_group . '_jump_to_btn_style' );

		$force_uppercase = \Mediavine\Settings::get_setting( self::$settings_group . '_force_uppercase' );

		$atts = shortcode_atts(
			[
				'id'        => null,
				'type'      => 'recipe',
				'link_text' => $link_text,
			],
			$atts,
			'mv_create_jtr'
		);

		/* @since 1.9.0 'mv-create-jtr-slot-v2' is targetted by the MV Ad Wrapper. */
		$class_names = [ 'mv-create-jtr', 'mv-create-jtr-slot-v2' ];

		if ( $btn_style ) {
			$class_names[] = $btn_style;
		}

		if ( $force_uppercase ) {
			$class_names[] = 'mv-create-jtr-button-uppercase';
		}

		$svg_caret = '<svg class="mv-create-jtr-caret" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1"  viewBox="0 0 444.819 444.819" width="16px" height="16px"><path d="M434.252,114.203l-21.409-21.416c-7.419-7.04-16.084-10.561-25.975-10.561c-10.095,0-18.657,3.521-25.7,10.561 L222.41,231.549L83.653,92.791c-7.042-7.04-15.606-10.561-25.697-10.561c-9.896,0-18.559,3.521-25.979,10.561l-21.128,21.416 C3.615,121.436,0,130.099,0,140.188c0,10.277,3.619,18.842,10.848,25.693l185.864,185.865c6.855,7.23,15.416,10.848,25.697,10.848 c10.088,0,18.75-3.617,25.977-10.848l185.865-185.865c7.043-7.044,10.567-15.608,10.567-25.693 C444.819,130.287,441.295,121.629,434.252,114.203z"/></svg>';

		$output = sprintf(
			'<div class="mv-create-jtr-wrapper"><a href="#mv-creation-%s-jtr" class="%s">%s %s</a></div>',
			esc_attr( $atts['id'] ),
			esc_attr( implode( ' ', $class_names ) ),
			$svg_caret,
			esc_html( $atts['link_text'] )
		);

		// Get correct button/text colors
		$base_color = '#333';
		$alt_color  = '#666';
		if ( 'custom' === \Mediavine\Settings::get_setting( 'mv_create_jump_to_btn_color' ) ) {
			$custom_base_color = \Mediavine\Settings::get_setting( 'mv_create_color', '#333' );
			// If the color has been previously set, it will now appear as a blank setting,
			// so we only set the color if a value exists.
			if ( ! empty( $custom_base_color ) ) {
				$base_color = '#' . str_replace( '#', '', $custom_base_color );
			}

			$custom_alt_color = \Mediavine\Settings::get_setting( 'mv_create_secondary_color', '#666' );
			// If the color has been previously set, it will now appear as a blank setting,
			// so we only set the color if a value exists.
			if ( ! empty( $custom_alt_color ) ) {
				$alt_color = '#' . str_replace( '#', '', $custom_alt_color );
			}
		}

		/*
		 * This is CRITICAL CSS as it will always be at the top of a post, and prevents async flash
		 * Ref file from /client/src/style/components/__jtr.scss and minified with sassmeister.com
		 */
		$output .= '<style>.mv-create-jtr-wrapper{margin-bottom:20px}';

		// We will always needs the links styles for Continue to Content
		$output .= "a.mv-create-jtr-link{display:inline-block;padding:10px 10px 10px 0;color:$base_color;font-size:16px;text-decoration:none!important;-webkit-transition:color .5s;transition:color .5s}a.mv-create-jtr-link:hover,a.mv-create-jtr-link:focus{color:$alt_color}.mv-create-jtr-link .mv-create-jtr-caret{margin-right:5px;padding-top:6px;fill:$base_color}.mv-create-jtr-link:hover .mv-create-jtr-caret,.mv-create-jtr-link:focus .mv-create-jtr-caret{fill:$alt_color}";

		if ( 'mv-create-jtr-button-hollow' === $btn_style ) {
			$output .= "a.mv-create-jtr-button-hollow{display:inline-block;padding:.75em 1.5em;border:1px solid $base_color;color:$base_color;background:transparent;box-shadow:none;text-shadow:none;font-size:16px;text-align:center;text-decoration:none!important;cursor:pointer;-webkit-transition:background .5s;transition:background .5s}a.mv-create-jtr-button-hollow:hover,a.mv-create-jtr-button-hollow:focus{border:1px solid $alt_color;color:$alt_color;background:transparent;box-shadow:none}.mv-create-jtr-button-hollow .mv-create-jtr-caret{margin-right:5px;padding-top:6px;fill:$base_color}.mv-create-jtr-button-hollow:hover .mv-create-jtr-caret,.mv-create-jtr-button-hollow:focus .mv-create-jtr-caret{fill:$alt_color}";
		}
		if ( 'mv-create-jtr-button' === $btn_style ) {
			$text_color       = '#fff';
			$text_hover_color = '#fff';
			if ( Creations_Views_Colors::is_light( $base_color ) ) {
				$text_color = '#000';
			}
			if ( Creations_Views_Colors::is_light( $alt_color ) ) {
				$text_hover_color = '#000';
			}
			$output .= "a.mv-create-jtr-button{display:inline-block;padding:.75em 1.5em;border:0;color:$text_color;background:$base_color;box-shadow:none;text-shadow:none;font-size:16px;text-align:center;text-decoration:none!important;cursor:pointer;-webkit-transition:background .5s;transition:background .5s}a.mv-create-jtr-button:hover,a.mv-create-jtr-button:focus{color:$text_hover_color;background:$alt_color}.mv-create-jtr-button .mv-create-jtr-caret{margin-right:5px;padding-top:6px;fill:$text_color}.mv-create-jtr-button:hover .mv-create-jtr-caret,.mv-create-jtr-button:focus .mv-create-jtr-caret{fill:$text_hover_color}";
		}

		if ( $force_uppercase ) {
			$output .= '.mv-create-jtr-button-uppercase{text-transform:uppercase}';
		}

		$output .= '</style>';

		return $output;
	}

	public static function add_display_to_safe_css( $safe_css ) {
		$safe_css[] = 'display';
		return $safe_css;
	}

	/**
	 * Check if theme supports custom placement of JTR button
	 * @return boolean|mixed
	 */
	public function theme_supports_custom_jtr() {
		return get_theme_support( 'mv_create_custom_jtr' );
	}

	/**
	 * Builds the markup for a screen reader skip to card button.
	 *
	 * @param string $content
	 * @return string
	 */
	public function build_screen_reader_jtr_link( $content ) {
		$screen_reader_class = 'mv-create-screen-reader-text';
		$screen_reader_css   = '<style>.mv-create-screen-reader-text{overflow:hidden;clip:rect(1px,1px,1px,1px);position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;border:0;clip-path:inset(50%)}.mv-create-screen-reader-text:focus{clip:auto!important;z-index:1000000;top:5px;left:5px;width:auto;height:auto;padding:15px 23px 14px;color:#444;background-color:#eee;font-size:1em;line-height:normal;text-decoration:none;clip-path:none}</style>';

		// Add core Trellis screen_reader class and remove CSS if Trellis
		if ( Theme_Checker::is_trellis() ) {
			$screen_reader_class = 'screen-reader-text';
			$screen_reader_css   = null;
		}

		/**
		 * Filters the screen reader class used by Create
		 *
		 * @param string $screen_reader_class
		 */
		$screen_reader_class = apply_filters( 'mv_create_screen_reader_skip_to_card_class', $screen_reader_class );

		/**
		 * Filters the screen reader CSS added by Create
		 *
		 * @param string $screen_reader_css
		 */
		$screen_reader_css = apply_filters( 'mv_create_screen_reader_skip_to_card_css', $screen_reader_css );

		// Add ID to elements hooked to WP footer so they can be hoisted through JS
		$element_id = null;
		$hoist_js   = null;
		if ( doing_filter( 'wp_footer' ) ) {
			$element_id = 'id="mv-create-screen-reader-text-in-footer" ';
			$hoist_js   = '<script type="text/javascript>var screenReaderLink=document.getElementById("mv-create-screen-reader-text-in-footer");document.body.prepend(screenReaderLink)</script>';
		}

		$atts = $this->get_jtr_atts( $content );

		// ID required
		if ( empty( $atts['id'] ) ) {
			return;
		}

		// Don't output jump on list types
		if ( ! empty( $atts['type'] ) && 'list' === $atts['type'] ) {
			return;
		}

		$link_text = ( isset( $atts['type'] ) && 'diy' === $atts['type'] ) ? 'Skip to Instructions' : 'Skip to Recipe';

		return '<a href="#mv-creation-' . $atts['id'] . '" ' . $element_id . 'class="' . $screen_reader_class . '">' . $link_text . '</a>' . $hoist_js . $screen_reader_css;
	}

	/**
	 * Outputs the markup for a screen reader skip to card button.
	 *
	 * @return void
	 */
	public function add_screen_reader_jtr_link() {
		// Only display this if we are on a singular post/page
		if ( ! is_singular() ) {
			return false;
		}

		global $post;
		$content = get_post_field( 'post_content', $post );

		$output = $this->build_screen_reader_jtr_link( $content );

		$allowed_tags = [
			'a'      => [
				'class' => true,
				'href'  => true,
				'id'    => true,
			],
			'script' => [
				'type' => true,
			],
			'style'  => [],
		];

		echo wp_kses( $output, $allowed_tags );
	}
}
