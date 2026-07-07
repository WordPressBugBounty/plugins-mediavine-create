<?php

namespace Mediavine\Create;

use Mediavine\Settings;
use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;

class Creations_Views extends Creations {


	public static $instance = null;

	public static $multiple_recipes = false;

	public static $multiple_howtos = false;

	public static $multiple_lists = false;

	public static $available_image_sizes = false;

	public static $studio_script_enqueued = false;

	/**
	 * Tracks whether a Create card has been rendered on the current page.
	 */
	public static $has_card = false;

	/**
	 * Tracks whether the first card's primary image has been rendered with LCP attributes.
	 * Only the first card on the page should get fetchpriority="high".
	 */
	public static $lcp_image_rendered = false;

	public $creations_jump_to_recipe = null;

	public static function get_instance() {
		 if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Server-render the star-rating markup the Reviews app would otherwise inject
	 * on hydration. Rendering it up front gives the reviews block its final height
	 * at first paint, so mounting the Preact app causes no layout shift (the cause
	 * of the mobile CLS this addresses). The app still hydrates over this markup to
	 * manage live state (submitting a rating); the static output it replaces is the
	 * same size, so the swap is invisible.
	 *
	 * Mirrors the Stars SVG in client/src/Reviews/Stars/index.js and the reviewcount
	 * in client/src/Reviews/Ratings/Base.js — keep the three in sync.
	 *
	 * @param mixed $rating Average rating for the card.
	 * @param mixed $total  Number of ratings.
	 * @return string Inner HTML for the `.mv-create-reviews` container.
	 */
	public static function render_review_stars( $rating, $total ) {
		$total   = (int) $total;
		$rating  = (float) $rating;
		// The app shows the average only when ratings exist; otherwise empty stars.
		$display = $total > 0 ? $rating : 0;
		// Pick a pre-generated clip class (0–50 = 0.0–5.0 stars in 0.1 steps).
		// All styling lives in Stars.scss because the views run through wp_kses,
		// which strips inline `style`; classes survive. See Stars.scss.
		$clip_class = 'mv-stars-r-' . max( 0, min( 50, (int) round( $display * 10 ) ) );

		$paths = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$paths .= '<path d="M6,0,7.875,3.75,12,4.35,9,7.275,9.675,11.4,6,9.45,2.325,11.4,3,7.275,0,4.35l4.125-.6Z" transform="translate(' . ( $i * 14 ) . ')"></path>';
		}

		// width/height give the SVGs an intrinsic size so they stay bounded even
		// before the (async-loaded) card stylesheet applies — otherwise they'd
		// render at the ~150px replaced-element default and flash/shift. CSS
		// overrides both once it loads.
		$empty_svg  = '<svg class="mv-stars-svg mv-stars-empty" width="150" height="25" aria-hidden="true" viewBox="0 0 68 11.4" preserveAspectRatio="none">' . $paths . '</svg>';
		$filled_svg = '<svg class="mv-stars-svg mv-stars-filled ' . $clip_class . '" width="150" height="25" aria-hidden="true" viewBox="0 0 68 11.4" preserveAspectRatio="none">' . $paths . '</svg>';

		$stars = sprintf(
			'<div class="mv-reviews-stars mv-stars mv-star-ratings " role="img" aria-label="%s">%s%s</div>',
			esc_attr( $display . ' out of 5 stars' ),
			$empty_svg,
			$filled_svg
		);

		if ( $total > 0 ) {
			/* translators: %s: average star rating, e.g. "4.9" */
			$stars_label   = sprintf( __( '%s Stars', 'mediavine' ), sprintf( '%.1f', $rating ) );
			$reviews_label = $total > 1
				/* translators: %s: number of reviews */
				? sprintf( __( '%s Reviews', 'mediavine' ), $total )
				/* translators: %s: number of reviews */
				: sprintf( __( '%s Review', 'mediavine' ), $total );
			$count = '<span>' . esc_html( $stars_label . ' (' . $reviews_label . ')' ) . '</span>';
		} else {
			$count = esc_html( __( 'No Ratings', 'mediavine' ) );
		}

		return '<div class="mv-reviews"><div>' . $stars . '</div><div class="mv-reviews-reviewcount">' . $count . '</div></div>';
	}

	/**
	 * Enqueue studio script once per page
	 *
	 * Interactive mode features (servings adjustment, interactive widgets) require
	 * a Pro subscription. If user doesn't have access, the script is not loaded
	 * and cards display in standard, non-interactive format.
	 */
	public static function enqueue_studio_script() {
		// Studio script powers interactive features (servings, checklists, unit conversion)
		// available on both Pro and Free+ tiers — only free users are excluded.
		if ( self::$studio_script_enqueued ) {
			return;
		}
		if ( ! GateKeeper::is_pro_or_higher() ) {
			return;
		}

		$site_url = site_url();

		$logging_enabled = Settings::get_setting('mv_create_enable_logging', false);

		$debug = Plugin::is_dev_mode() || $logging_enabled ? "data-create-studio-debug='true'" : "";
		$timestamp = time();
		$studio_script = self::$create_studio_base_url . "/embed/main.js";
		if ($debug) {
			$studio_script .= "?v=$timestamp";
		}

		$css_url = self::$create_studio_base_url . "/embed/entry.css";
		if ($debug) {
			// JS uses Date.getTime() (milliseconds) for cache-busting; we can't predict
			// that exact value, so skip the CSS preload in debug mode to avoid a wasted
			// preload on a URL that won't match what main.js actually requests.
			$preload_css = false;
		} else {
			$preload_css = true;
		}

		// Emit preconnect + preload hints early in <head> so the browser can
		// fetch main.js and entry.css in parallel during HTML parsing, rather
		// than waiting for main.js to execute and dynamically inject the CSS link.
		add_action('wp_head', function() use ($studio_script, $css_url, $preload_css) {
			echo '<link rel="preconnect" href="https://create.studio" crossorigin>' . "\n";
			printf('<link rel="preload" href="%s" as="script" crossorigin>' . "\n", esc_url($studio_script));
			if ($preload_css) {
				printf('<link rel="preload" href="%s" as="style">' . "\n", esc_url($css_url));
			}
		}, 5);

		// Add script to footer with data attributes
		add_action('wp_footer', function() use ($studio_script, $site_url, $debug) {
			printf('<script defer type="module" id="create-studio-embed" data-site-url="%s" src="%s" %s></script>',
			esc_attr($site_url),
			esc_url($studio_script),
			$debug);
		}, 20);

		// Force Autoptimize to NOT aggregate inline scripts so it doesn't break JS
		add_filter('autoptimize_js_include_inline', '__return_false');
		self::$studio_script_enqueued = true;
	}

	function init() {
	   $this->creations_jump_to_recipe = Creations_Jump_To_Recipe::get_instance();
		add_filter( 'mv_create_custom_css_settings_value', 'wp_strip_all_tags' );
		add_action('init', [ $this, 'add_image_sizes' ]);
		add_action('image_size_names_choose', [ $this, 'add_image_size_names' ]);
		add_action('wp_enqueue_scripts', [ $this, 'register_styles' ]);
		add_action('wp_enqueue_scripts', [ $this, 'register_scripts' ]);
		add_action('wp_head', [ $this, 'lists_rounded_corners' ]);
		add_action('wp_footer', [ $this, 'output_custom_css' ], 999);

		add_filter('script_loader_tag', [ $this, 'add_async_attribute' ], 10, 2);
		add_filter('style_loader_tag', [ $this, 'add_async_styles' ], 10, 3);
		add_filter('wp_kses_allowed_html', [ $this, 'allow_data_attributes' ], 10, 2);
		add_filter('mv_create_image_sizes', [ $this, 'disable_image_sizes' ], 20, 2);

		add_shortcode('mv_create', [ $this, 'mv_create_shortcode' ]);
		add_shortcode('mv_recipe', [ $this, 'mv_recipe_shortcode' ]);

		// Theme preview banner for admins
		if ( ! empty( $_GET['create_theme'] ) ) {
			add_action( 'wp_footer', [ $this, 'render_theme_preview_banner' ] );
		}
		add_action( 'admin_post_mv_create_apply_theme', [ $this, 'handle_apply_theme' ] );

		// If MCP is disabled, we don't want a dead shortcode displayed
		if ( ! shortcode_exists('mv_video') ) {
			add_shortcode('mv_video', '__return_false');
		}
	}

	/**
	 * Gets the opening portion of the social profile link tag
	 *
	 * @param string $social_service Selected social service
	 * @return string HTML output of the opening tag for the social media profile link
	 */
	public static function get_social_link_tag( $social_service ) {
		$tag = null;
		if ( ! empty($social_service) ) {
			$username = Settings::get_setting('mv_create_social_cta_' . $social_service . '_user');

			if ( ! empty($username) ) {
				switch ( $social_service ) {
					case 'facebook':
						$link  = 'https://www.facebook.com/' . $username;
						$title = __('Facebook Page:', 'mediavine') . ' ' . $username;
						break;
					case 'instagram':
						$link  = 'https://instagram.com/' . $username;
						$title = __('Instagram:', 'mediavine') . ' ' . $username;
						break;
					case 'pinterest':
						$link  = 'https://www.pinterest.com/' . $username;
						$title = __('Pinterest Profile:', 'mediavine') . ' ' . $username;
						break;
				}

				if ( ! empty($title) && ! empty($link) ) {
					$tag = '<a href="' . $link . '" title="' . $title . '" class="mv-create-social-link" target="_blank" rel="noreferrer noopener">';
				}
			}
		}

		return $tag;
	}

	/**
	 * Build the CSS custom properties for the card's color theme.
	 *
	 * Returned as an inline style string applied directly to each
	 * .mv-create-card element. A wp_head <style> block was previously used,
	 * but Remove-Unused-CSS optimizers (WP Rocket RUCSS, etc.) strip it
	 * because the rule body is just custom-property declarations they can't
	 * tie back to a "used" selector. Element style attributes are not
	 * touched by those optimizers.
	 */
	public static function get_card_inline_style() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$color           = trim( (string) \Mediavine\Settings::get_setting( 'mv_create_color' ) );
		$secondary_color = trim( (string) \Mediavine\Settings::get_setting( 'mv_create_secondary_color' ) );

		$properties = [];

		$inherit_font_size = \Mediavine\Settings::get_setting( 'mv_create_inherit_theme_fontsize', false );
		if ( $inherit_font_size ) {
			$properties[] = 'font-size: 1em;';
			$properties[] = '--mv-create-base-font-size: 1em;';
			$properties[] = '--mv-create-title-primary: 1.875em;';
			$properties[] = '--mv-create-title-secondary: 1.5em;';
			$properties[] = '--mv-create-subtitles: 1.125em;';
		}

		$has_primary   = ! empty( $color ) && '#' !== $color;
		$has_secondary = ! empty( $secondary_color ) && '#' !== $secondary_color;

		if ( $has_primary ) {
			$properties[] = '--mv-create-base: ' . $color . ' !important;';

			$color_alt = Creations_Views_Colors::darken( $color, 20 );
			if ( Creations_Views_Colors::is_dark( $color ) ) {
				$color_alt    = Creations_Views_Colors::lighten( $color, 20 );
				$properties[] = '--mv-create-alt: ' . $color_alt . ' !important;';
				$properties[] = '--mv-create-alt-text: ' . Creations_Views_Colors::contrast_text( $color_alt ) . ' !important;';
			}

			$color_hover = Creations_Views_Colors::darken( $color_alt, 20 );
			if ( Creations_Views_Colors::is_dark( $color_alt ) ) {
				$color_hover  = Creations_Views_Colors::lighten( $color_alt, 20 );
				$properties[] = '--mv-create-alt-hover: ' . $color_hover . ' !important;';
			}

			$properties[] = '--mv-create-text: ' . Creations_Views_Colors::contrast_text( $color ) . ' !important;';
		}

		if ( $has_secondary ) {
			$properties[] = '--mv-create-secondary-base: ' . $secondary_color . ' !important;';

			$secondary_color_alt = Creations_Views_Colors::darken( $secondary_color, 20 );
			if ( Creations_Views_Colors::is_dark( $secondary_color ) ) {
				$secondary_color_alt = Creations_Views_Colors::lighten( $secondary_color, 20 );
				$properties[]        = '--mv-create-secondary-alt: ' . $secondary_color_alt . ' !important;';
			}

			$secondary_color_hover = Creations_Views_Colors::darken( $secondary_color_alt, 20 );
			if ( Creations_Views_Colors::is_dark( $secondary_color_alt ) ) {
				$secondary_color_hover = Creations_Views_Colors::lighten( $secondary_color_alt, 20 );
				$properties[]          = '--mv-create-secondary-alt-hover: ' . $secondary_color_hover . ' !important;';
			}

			$properties[] = '--mv-create-secondary-text: ' . Creations_Views_Colors::contrast_text( $secondary_color ) . ' !important;';
			$properties[] = '--mv-create-secondary-base-trans: ' . Creations_Views_Colors::to_rgba( $secondary_color, 0.8 ) . ' !important;';
			$properties[] = '--mv-star-fill: ' . Creations_Views_Colors::mix( $secondary_color, '#fff' ) . ' !important;';
			$properties[] = '--mv-star-fill-hover: ' . $secondary_color . ' !important;';
		}

		$cached = implode( ' ', $properties );
		return $cached;
	}

	/**
	 * Output custom CSS in the footer so it loads after all theme stylesheets.
	 *
	 * Theme stylesheets may be enqueued late (during shortcode rendering) or
	 * injected by the dev server, so outputting in wp_footer guarantees the
	 * custom CSS always has the final word on specificity.
	 *
	 * Only outputs on pages where a Create card was rendered.
	 */
	public function output_custom_css() {
		if ( empty( self::$has_card ) ) {
			return;
		}
		$custom_css = \Mediavine\Settings::get_setting( 'mv_create_custom_css' );
		if ( ! empty( $custom_css ) && GateKeeper::can_access( GateKeeper::FEATURE_CUSTOM_CSS ) ) {
			echo '<style id="mv-create-custom-css">' . wp_strip_all_tags( $custom_css ) . '</style>';
		}
	}

	/**
	 * Disable image sizes, use `mv_create_disable_image_sizes` filter
	 *
	 * @param array  $sizes Image sizes from WordPress
	 * @param string $function Function called by filter (not used)
	 *
	 * @return array
	 */
	public function disable_image_sizes( $sizes, $function ) {

		$sizes_to_disable = json_decode(Settings::get_setting('mv_create_disable_image_sizes', '[]') ?: '[]');
		if ( empty($sizes_to_disable) ) {
			return $sizes;
		}

		foreach ( $sizes_to_disable as $size ) {
			unset($sizes[ $size ]);
		}

		return $sizes;
	}

	public function add_image_sizes() {
		 $img_sizes = apply_filters('mv_create_image_sizes', self::$img_sizes, __FUNCTION__);
		foreach ( $img_sizes as $img_size => $img_meta ) {
			add_image_size($img_size, $img_meta['width'], $img_meta['height'], $img_meta['crop']);
		}
	}

	public function add_image_size_names( $sizes ) {
		$img_sizes                  = apply_filters('mv_create_image_sizes', self::$img_sizes, __FUNCTION__);
		$mv_create_image_size_names = [];

		foreach ( $img_sizes as $img_size => $img_meta ) {
			$mv_create_image_size_names[ $img_size ] = $img_meta['name'];
		}

		$new_sizes = apply_filters('mv_create_image_size_names', $mv_create_image_size_names);
		$sizes     = array_merge($sizes, $new_sizes);

		return $sizes;
	}

	public function allow_data_attributes( $allowed, $context ) {
		if ( 'post' === $context ) {
			$allowed['div']['data-mv-create-total-ratings']     = true;
			$allowed['div']['data-mv-create-rating']            = true;
			$allowed['div']['data-mv-create-id']                = true;
			$allowed['div']['data-mv-pinterest-desc']           = true;
			$allowed['div']['data-mv-pinterest-img-src']        = true;
			$allowed['div']['data-mv-pinterest-url']            = true;
			$allowed['div']['data-mv-create-object-id']         = true;
			$allowed['div']['data-mv-create-assets-url']        = true;
			$allowed['div']['data-mv-rest-url']                 = true;
			$allowed['div']['data-mv-create-list-content-type'] = true;
			$allowed['div']['data-mv-create-link-href']         = true;
			$allowed['div']['data-mv-create-link-target']       = true;
			$allowed['div']['data-disable-chicory']             = true;
			$allowed['div']['data-slot']                        = true;
		}
		$allowed['img']['nopin']          = true;
		$allowed['img']['data-pin-media'] = true;
		$allowed['img']['data-pin-nopin'] = true;

		$allowed['iframe']['src']             = true;
		$allowed['iframe']['frameborder']     = true;
		$allowed['iframe']['allow']           = true;
		$allowed['iframe']['allowfullscreen'] = true;

		$allowed['img']['srcset'] = true;
		$allowed['img']['sizes']  = true;

		return $allowed;
	}

	/**
	 * Adds async to enqued style
	 *
	 * @param string $tag script tag to be outputted
	 * @param string $handle enque handle
	 *
	 * @return string script tag to be outputted
	 */
	public function add_async_styles( $tag, $handle, $href ) {
		$prefix = 'mv-create-card_';
		if ( 'mv-create-card-base' === $handle || substr($handle, 0, strlen($prefix)) === $prefix ) {
			$new_tag  = '<link rel="stylesheet preload" class="mv-create-styles" href="' . $href . '" as="style">';
			$new_tag .= "<noscript>$tag</noscript>";

			$tag = $new_tag;
		}

		return $tag;
	}

	public function register_styles() {
		$base_url = apply_filters('mv_recipe_stylesheet', Plugin::assets_url() . 'client/build/card-base.' . Plugin::VERSION . '.css');
		wp_register_style('mv-create-card-base', $base_url, [], Plugin::VERSION);

		 $card_styles = [
			 'big-image',
			 'centered',
			 'centered-dark',
			 'dark',
			 'square',
			 'editorial',
			 'modern',
		 ];
		foreach ( $card_styles as $card_style ) {
			$style_url = apply_filters('mv_recipe_stylesheet', Plugin::assets_url() . "client/build/card-$card_style." . Plugin::VERSION . '.css');
			wp_register_style("mv-create-card_$card_style", $style_url, [ 'mv-create-card-base' ], Plugin::VERSION);
		}
	}

	/**
	 * Adds async to enqued script
	 *
	 * @param string $tag script tag to be outputted
	 * @param string $handle enque handle
	 *
	 * @return string script tag to be outputted
	 */
	public function add_async_attribute( $tag, $handle ) {
		$prefix = Plugin::PLUGIN_DOMAIN . '/client.js';
		if ( substr($handle, 0, strlen($prefix)) === $prefix ) {
			$tag = str_replace(' src', ' async data-noptimize src', $tag);
		}

		return $tag;
	}

	public function register_scripts() {
		$handle     = Plugin::PLUGIN_DOMAIN . '/client.js';
		$script_url = Plugin::assets_url() . 'client/build/bundle.' . Plugin::VERSION . '.js';
		if ( apply_filters('mv_create_dev_mode', false) ) {
			$client_dev_port = apply_filters( 'mv_create_client_dev_port', defined( 'MV_CREATE_CLIENT_DEV_PORT' ) ? MV_CREATE_CLIENT_DEV_PORT : 8080 );
			$script_url = 'http://localhost:' . $client_dev_port . '/bundle.js';
		}
		wp_register_script($handle, $script_url, [], Plugin::VERSION, true);


		// Get user-supplied element to mount reviews UI on, but revert to null if "enable" option isn't set.
		$reviews_div            = \Mediavine\Settings::get_setting(self::$settings_group . '_public_reviews_el');
		$public_reviews_enabled = \Mediavine\Settings::get_setting(self::$settings_group . '_enable_public_reviews');
		if ( empty($public_reviews_enabled) ) {
			$reviews_div = null;
		}

		// Set ratings submit threshold based on setting (setting is temporarily disabled)
		$enable_anonymous_ratings = \Mediavine\Settings::get_setting(self::$settings_group . '_enable_anonymous_ratings');
		$ratings_submit_threshold = 5.5;
		if ( $enable_anonymous_ratings ) {
			$ratings_submit_threshold = 4;
		}

		// Allow filter override of ratings submit and prompt thresholds
		$ratings_submit_threshold = apply_filters('mv_create_ratings_submit_threshold', $ratings_submit_threshold);
		$ratings_prompt_threshold = apply_filters('mv_create_ratings_prompt_threshold', 5.5);

		$px_btwn_ads = \Mediavine\Settings::get_setting(self::$settings_group . '_ad_density');

		wp_localize_script(
			$handle,
			'MV_CREATE_SETTINGS',
			[
				'__API_ROOT__'         => rest_url(),
				'__REVIEWS_DIV__'      => $reviews_div,
				'__PROMPT_THRESHOLD__' => $ratings_prompt_threshold,
				'__SUBMIT_THRESHOLD__' => $ratings_submit_threshold,
				'__PX_BETWEEN_ADS__'   => $px_btwn_ads,
				'__USER_ID__'          => get_current_user_id(),
				'__REVIEW_LIMITS__'    => [
					'author_name'    => Reviews_API::MAX_AUTHOR_NAME_LENGTH,
					'review_title'   => Reviews_API::MAX_REVIEW_TITLE_LENGTH,
					'review_content' => Reviews_API::MAX_REVIEW_CONTENT_LENGTH,
					'email'          => Reviews_API::MAX_EMAIL_LENGTH,
				],
				'__OPTIONS__'          => [
					'reviews_ctas'    => (bool) \Mediavine\Settings::get_setting('mv_create_reviews_ctas', false),
					'jtc_enabled'     => (bool) \Mediavine\Settings::get_setting('mv_create_enable_jump_to_recipe', false),
					'asset_url'       => self::assets_url(),
					'wakeLockEnabled' => (bool) \Mediavine\Settings::get_setting('mv_create_enable_hands_free_mode', false),
					'canReplyToReviews' => \Mediavine\Permissions::is_user_authorized(),
					'checklistsEnabled' => (bool) \Mediavine\Settings::get_setting('mv_create_enable_checklists', false) && GateKeeper::can_access( GateKeeper::FEATURE_CHECKLISTS ),
					'checklistSections' => \Mediavine\Settings::get_setting('mv_create_checklist_sections', 'ingredients'),
					'unitConversionEnabled' => (bool) \Mediavine\Settings::get_setting('mv_create_enable_unit_conversion', false) && GateKeeper::can_access( GateKeeper::FEATURE_UNIT_CONVERSION ),
					'ctaVariant' => \Mediavine\Settings::get_setting('mv_create_interactive_mode_cta_variant', 'inline-banner'),
					'ctaTitle' => \Mediavine\Settings::get_setting('mv_create_interactive_mode_cta_title', ''),
					'ctaSubtitle' => \Mediavine\Settings::get_setting('mv_create_interactive_mode_cta_subtitle', ''),
				],
			]
		);

		wp_localize_script(
			$handle,
			'MV_CREATE_I18N',
			Translation::client_terms()
		);
	}

	public static function create_wp_kses( $allowed, $context ) {
		// Create card specifics
		$allowed['div']['data-mv-create-total-ratings'] = true;
		$allowed['div']['data-mv-create-rating']        = true;
		$allowed['div']['data-mv-create-id']            = true;
		$allowed['div']['data-mv-pinterest-desc']       = true;
		$allowed['div']['data-mv-pinterest-img-src']    = true;
		$allowed['div']['data-mv-pinterest-url']        = true;
		$allowed['div']['data-mv-create-object-id']     = true;
		$allowed['div']['data-mv-create-assets-url']    = true;
		$allowed['div']['data-mv-rest-url']             = true;
		$allowed['div']['data-derive-font-from']        = true;

		// Video Shortcode
		$allowed['div']['data-value']        = true;
		$allowed['div']['data-sticky']       = true;
		$allowed['div']['data-autoplay']     = true;
		$allowed['div']['data-ratio']        = true;
		$allowed['div']['data-volume']       = true;
		$allowed['script']['type']           = true;
		$allowed['script']['src']            = true;
		$allowed['script']['async']          = true;
		$allowed['script']['data-noptimize'] = true;

		$allowed['img']['nopin']          = true;
		$allowed['img']['data-pin-media'] = true;
		$allowed['img']['data-pin-nopin'] = true;

		$allowed['input']['type']  = true;
		$allowed['input']['name']  = true;
		$allowed['input']['value'] = true;

		$allowed['button']['data-mv-print'] = true;
		$allowed['button']['rel']           = true;

		$allowed['a']['data-derive-button-from'] = true;

		$allowed['form']['class']  = true;
		$allowed['form']['method'] = true;
		$allowed['form']['action'] = true;
		$allowed['form']['target'] = true;

		// SVGs
		$allowed['svg']  = [
			'class'   => true,
			'xmlns'   => true,
			'width'   => true,
			'height'  => true,
			'viewbox' => true, // <= Must be lower case!
		];
		$allowed['path'] = [
			'd'    => true,
			'fill' => true,
		];

		return $allowed;
	}

	/**
	 * Gets all available image size data for MV Create images
	 *
	 * @param string $function Name of function
	 * @return array Image size data for MV Create images
	 */
	public static function get_all_image_sizes( $function = __FUNCTION__ ) {
		// Retrieve from static if available so we don't do this work repeatedly
		if ( empty(self::$available_image_sizes) ) {
			$image_sizes = apply_filters('mv_create_image_sizes', self::$img_sizes, $function);

			// Make sure base sizes exist or we will lose available image sizes
			$image_sizes = Images::get_required_base_sizes($image_sizes);

			self::$available_image_sizes = $image_sizes;
		}

		return self::$available_image_sizes;
	}

	public static function prep_creation_view( $atts ) {
		$id       = get_current_post_id();
		$creation = self::$models_v2->mv_creations->find_one($atts['key']);

		// We need a creation id to move any further, meaning creation does exist
		if ( empty($creation->id) ) {
			return;
		}
		// These are to be removed later
		$creation = self::restore_video_data($creation);
		$creation = \Mediavine\Create\Products::restore_product_images($creation);

		// Check if post is associated to card
		$associated_posts = [];
		if ( ! empty($creation->associated_posts) ) {
			$associated_posts = json_decode($creation->associated_posts ?: '[]');
		}
		if ( is_singular() && ! in_array($id, $associated_posts, true) ) {
			self::associate_post_with_creation($creation->id, $id);
		}

		// This stays forever.
		// This method checks several factors to decide if the card needs
		// to be republished before being displayed. It allows us to add cards
		// to a `republish_queue` when things need to be fixed en masse.
		$creation = \Mediavine\Create\Publish::maybe_republish($creation);

		$published_creation = json_decode($creation->published ?: '{}', true);

		// Merge card-level fields that live outside the published JSON blob.
		// Always use the DB column as source of truth, overriding any stale
		// values that may have been captured in the published JSON at publish time.
		if ( $published_creation ) {
			$card_fields = [ 'video_position', 'products_position', 'products_display_mode', 'products_section_title' ];
			foreach ( $card_fields as $field ) {
				if ( isset( $creation->$field ) ) {
					$published_creation[ $field ] = $creation->$field;
				}
			}
		}

		// If a card specifies its own layout (for instance, for Lists)
		// it should override the style
		if ( ! empty($atts['layout']) ) {
			$atts['style'] = $atts['layout'];
		}

		if ( $published_creation ) {
			$published_creation['classes'] = [
				'mv-create-card',
				'mv-create-card-' . $atts['key'],
				'mv-' . $atts['type'] . '-card',
				'mv-create-card-style-' . str_replace('/', '-', $atts['style']),
			];

			// Only have mv-no-js class if not print
			if ( empty($atts['print']) ) {
				$published_creation['classes'][] = 'mv-no-js';
			}

			// Add specific classes to print layout
			if ( ! empty($atts['print']) ) {
				$published_creation['classes'][] = 'mv-create-xl';
				$published_creation['classes'][] = 'js';
			}

			$aggressive_buttons = \Mediavine\Settings::get_setting(self::$settings_group . '_aggressive_buttons');
			if ( $aggressive_buttons ) {
				$published_creation['classes'][] = 'mv-create-aggressive-buttons';
			}

			$aggressive_widgets = \Mediavine\Settings::get_setting(self::$settings_group . '_aggressive_widgets');
			if ( $aggressive_widgets ) {
				$published_creation['classes'][] = 'mv-create-aggressive-widgets';
			}

			$aggressive_nutrition = \Mediavine\Settings::get_setting(self::$settings_group . '_aggressive_nutrition');
			if ( $aggressive_nutrition ) {
				$published_creation['classes'][] = 'mv-create-aggressive-nutrition';
			}

			$center_cards = \Mediavine\Settings::get_setting(self::$settings_group . '_center_cards', true);
			if ( $center_cards ) {
				$published_creation['classes'][] = 'mv-create-center-cards';
			}

			// We don't want to waste resources for lists
			if ( 'list' !== $atts['type'] ) {
				// Forced settings classes
				$uppercase = \Mediavine\Settings::get_setting(self::$settings_group . '_force_uppercase');
				if ( $uppercase || is_null($uppercase) ) { // Null means no setting, so we get default
					$published_creation['classes'][] = 'mv-create-has-uppercase';
				}
				$aggressive_lists = \Mediavine\Settings::get_setting(self::$settings_group . '_aggressive_lists');
				if ( $aggressive_lists ) {
					$published_creation['classes'][] = 'mv-create-aggressive-lists';
				}
				$use_ugly_nutrition_display = \Mediavine\Settings::get_setting(self::$settings_group . '_use_realistic_nutrition_display');
				if ( $use_ugly_nutrition_display ) {
					$published_creation['classes'][] = 'mv-create-traditional-nutrition';
				}

				// Print view
				if ( $atts['print'] ) {
					$published_creation['classes'][] = 'mv-create-print-view';

					// Hide images on print
					$mv_create_enable_print_thumbnails = \Mediavine\Settings::get_setting(self::$settings_group . '_enable_print_thumbnails');
					if ( empty($mv_create_enable_print_thumbnails) ) {
						$published_creation['classes'][] = 'mv-create-hide-img';
					}
				}

				// Make sure products have images
				if ( $published_creation['products'] ) {
					foreach ( $published_creation['products'] as &$product ) {
						$product['thumbnail_src'] = Products_Map::get_correct_thumbnail_src($product);
					}
				}

				// Custom fields need to be decoded before we prep the social footer content
				$published_creation['custom_fields'] = json_decode($published_creation['custom_fields'] ?: '{}', true);

				// Get social footer content if enabled
				$published_creation['social_footer'] = \Mediavine\Settings::get_setting(self::$settings_group . '_social_footer', false);
				if ( $published_creation['social_footer'] ) {
					// Get correct social footer content, either from settings or override
					$published_creation['social_icon']      = self::get_custom_field(
						$published_creation,
						'mv_create_social_footer_icon',
						\Mediavine\Settings::get_setting(self::$settings_group . '_social_service'),
						true
					);
					$published_creation['social_cta_title'] = self::get_custom_field(
						$published_creation,
						'mv_create_social_footer_header',
						\Mediavine\Settings::get_setting(self::$settings_group . '_social_cta_title_' . $atts['type'])
					);

					// Grab default title if empty
					if ( empty($published_creation['social_cta_title']) ) {
						$social_card_type = 'recipe';
						if ( 'diy' === $atts['type'] ) {
							$social_card_type = 'project';
						}
						$published_creation['social_cta_title'] = sprintf(
							// Translators: Type of card. Will output either 'recipe' or 'project'
							__('Did you make this %s?', 'mediavine'),
							$social_card_type
						);
					}

					$published_creation['social_cta_body'] = self::get_custom_field(
						$published_creation,
						'mv_create_social_footer_content'
					);

					// The WYSIWYG changes empty values to `<p></p>` so we need to check for that and grab the global setting value
					if ( '<p></p>' === $published_creation['social_cta_body'] || empty($published_creation['social_cta_body']) ) {
						$published_creation['social_cta_body'] = \Mediavine\Settings::get_setting(self::$settings_group . '_social_cta_body_' . $atts['type']);
					}

					// Grab default message if body empty
					if ( '<p></p>' === $published_creation['social_cta_body'] || empty($published_creation['social_cta_body']) ) {
						$published_creation['social_cta_body'] = '<p>' . sprintf(
							// Translators: Social Service name with link
							__('Please leave a comment on the blog or share a photo on %s', 'mediavine'),
							self::get_social_link_tag($published_creation['social_icon']) . ucfirst($published_creation['social_icon']) . '</a>'
						) . '</p>';
					}

					$published_creation['social_body_kses'] = [
						'a'      => [
							'class'  => true,
							'href'   => true,
							'target' => true,
							'rel'    => true,
						],
						'strong' => [
							'class' => true,
						],
						'em'     => [
							'class' => true,
						],
					];
				}
			}

			// Add image tags
			$img_sizes                    = self::get_all_image_sizes(__FUNCTION__);
			$img_size                     = self::get_image_size();
			$published_creation['images'] = \Mediavine\View_Loader::get_mv_image_tags($published_creation, $img_sizes);

			// Determine if card has an image
			$has_img_class = 'mv-create-no-image';
			if ( ! is_null($published_creation['images']) ) {
				$has_img_class = 'mv-create-has-image';
			}

			$published_creation['classes'][] = $has_img_class;

			// Add photo ratio class for list layouts
			if ( 'list' === $atts['type'] && 'mv_create_no_ratio' !== $img_size ) {
				$published_creation['classes'][] = 'mv-create-list-ratio-' . str_replace( 'mv_create_', '', $img_size );
			}

			$published_creation['classes'] = implode(' ', $published_creation['classes']);

			if ( isset($published_creation['images'][ $img_size ]) ) {
				$description          = htmlentities($published_creation['pinterest_description'] ?: '');
				$data_pin_description = 'data-pin-description="' . $description . '"';

				$published_creation['images'][ $img_size ] = str_replace(' alt', " $data_pin_description alt", $published_creation['images'][ $img_size ]);
			}

			// Get Pinterest settings
			$pinterest_location = \Mediavine\Settings::get_setting(self::$settings_group . '_pinterest_location', 'mv-creation-pin-button');

			$published_creation['pinterest_class'] = $pinterest_location;

			if ( isset($published_creation['images']) && isset($published_creation['images']['mv_create_vert']) && 'off' !== $pinterest_location ) {

				// Set Pinterest description as image alt text so browser extension picks it up
				$pin_img                                        = $published_creation['images']['mv_create_vert'];
				$pin_img_alt_text                               = 'alt="" data-pin-description="' . htmlentities($published_creation['pinterest_description'] ?: '' ) . '"';
				$published_creation['images']['mv_create_vert'] = str_replace('class', "$pin_img_alt_text class", $pin_img);

				$has_img_class = 'mv-create-no-image';
				if ( ! is_null($published_creation['images']) ) {
					$has_img_class = 'mv-create-has-image';
				}

				$published_creation['pinterest_display'] = true;

				if ( empty($published_creation['pinterest_description']) ) {
					$published_creation['pinterest_description'] = $published_creation['title'];
				}

				if ( empty($published_creation['pinterest_url']) ) {
					$published_creation['pinterest_url'] = get_the_permalink();
				}

				if ( empty($published_creation['pinterest_img_id']) ) {
					$published_creation['pinterest_img_id'] = $published_creation['thumbnail_id'];
				}
			}

			// Remove Pinterest image if the Pinterest button display is set to off
			if ( isset($published_creation['images']) && 'off' === $pinterest_location ) {
				unset($published_creation['images']['mv_create_vert']);
			}

			// Enable override of author by default copyright
			if ( \Mediavine\Settings::get_setting(self::$settings_group . '_copyright_override') ) {
				$published_creation['author'] = \Mediavine\Settings::get_setting(self::$settings_group . '_copyright_attribution');
			}

			$published_creation_pinterest_img = wp_get_attachment_image_src($published_creation['pinterest_img_id'], 'mv_creation_vert');
			if ( is_array($published_creation_pinterest_img) ) {
				$published_creation['pinterest_img'] = $published_creation_pinterest_img[0];
			}

			if ( 'list' === $atts['type'] && ! empty($published_creation['list_items']) && is_array($published_creation['list_items']) ) {
				// Force pinterest if not set to off
				if ( 'off' !== $pinterest_location ) {
					$published_creation['pinterest_display'] = true;
				}

				$atts['layout'] = $published_creation['layout'];
				$img_sizes      = self::get_all_image_sizes(__FUNCTION__);
				$photo_ratio    = self::get_image_size();
				$has_ratio      = ( 'mv_create_no_ratio' !== $photo_ratio );

				// Order list items by position because we can't guarantee DB write order
				usort(
					$published_creation['list_items'],
					function ( $a, $b ) {
						if ( $a['position'] > $b['position'] ) {
							return 1;
						}
						if ( $b['position'] > $a['position'] ) {
							return -1;
						}

						return 0;
					}
				);

				$list_ads_enabled = \Mediavine\Settings::get_setting( self::$settings_group . '_list_ads_enabled', Plugin_Checker::has_mv_ads() ? '1' : '0' );
				$published_creation['list_items_between_ads'] = $list_ads_enabled
					? \Mediavine\Settings::get_setting( self::$settings_group . '_list_items_between_ads', 3 )
					: 0;
				global $post;
				foreach ( $published_creation['list_items'] as $key => &$item ) {
					if (
						! empty($post) &&
						'post' === $item['content_type'] &&
						(int) $item['relation_id'] === (int) $post->ID
					) {
						unset($published_creation['list_items'][ $key ]);
						continue;
					}

					// Section dividers (explicit type or legacy text-with-no-media)
					// don't get image/url/button prep — promote the legacy heuristic
					// to an explicit content_type so downstream code (templates,
					// JSON-LD, numbering) handles it consistently.
					if ( self::is_list_item_divider( $item ) ) {
						$item['content_type'] = 'divider';
						continue;
					}

					// Thumbnail url logic
					$layout_image_sizes   = [
						'circles'  => 'mv_create_1x1',
						'grid'     => $has_ratio ? $photo_ratio : 'mv_create_16x9',
						'hero'     => $has_ratio ? $photo_ratio : 'mv_create_vert',
						'numbered' => $has_ratio ? $photo_ratio : 'mv_create_vert',
					];
					$thumbnail_image_size = 'mv_create_1x1';
					if ( array_key_exists($atts['layout'], $layout_image_sizes) ) {
						$thumbnail_image_size = $layout_image_sizes[ $atts['layout'] ];
					}

					// Generate thumbnail if it doesn't exist
					// Skip synchronous image processing during REST API requests or in admin to avoid timeouts
					if ( ( ! defined('REST_REQUEST') || ! REST_REQUEST ) && ! is_admin() ) {
						Images::check_image_size($item['thumbnail_id'], $img_sizes);
					}
					$highest_res_image = Images::get_highest_available_image_size($item['thumbnail_id'], $thumbnail_image_size);

					$item_alt_text = get_post_meta( $item['thumbnail_id'], '_wp_attachment_image_alt', true );
					if ( empty( $item_alt_text ) ) {
						/* translators: %s: list item title */
						$item_alt_text = sprintf( __( 'Image for %s', 'mediavine' ), $item['title'] );
					}

					$item['thumbnail_url'] = wp_get_attachment_image(
						$item['thumbnail_id'],
						$highest_res_image,
						false,
						[
							'class'          => 'mv-list-single-img no_pin ggnoads',
							'alt'            => $item_alt_text,
							'data-pin-nopin' => 'true',
						]
					);

					$item['pinterest_url'] = wp_get_attachment_image_url(
						$item['thumbnail_id'],
						'mv_create_vert',
						false
					);

					// Get permalink for all non-external items, including CPTs
					// Products use their URL from the products table, not a permalink
					if ( 'external' !== $item['content_type'] && 'product' !== $item['content_type'] ) {
						$item['url'] = get_the_permalink($item['canonical_post_id']);
					}

					// Provide button text
					$item['btn_text'] = self::resolve_list_item_button_text( $item );

					if ( 'card' === $item['content_type'] ) {
						// We don't wany any unassociated cards
						if ( empty($item['canonical_post_id']) ) {
							unset($published_creation['list_items'][ $key ]);
							continue;
						}

						$item['url']  = get_the_permalink($item['canonical_post_id']);
						$item_data    = \mv_create_get_creation($item['relation_id'], true);
						$item['data'] = [];

						$item_meta = json_decode($item['meta'] ?: '{}');

						// Add meta types
						if ( is_array($item_meta) ) {
							if ( in_array('prep_time', $item_meta, true) && ! empty($item_data->prep_time) ) {
								$time_output = self::prep_creation_time($item_data->prep_time);
								if ( ! empty($time_output['time']) ) {
									$item['data'][] = [ __('Prep Time', 'mediavine'), $time_output['time'] ];
								}
							}
							if ( in_array('active_time', $item_meta, true) && ! empty($item_data->active_time) ) {
								$time_output = self::prep_creation_time($item_data->active_time);
								if ( ! empty($time_output['time']) ) {
									$item['data'][] = [ __('Active Time', 'mediavine'), $time_output['time'] ];
								}
							}
							if ( in_array('additional_time', $item_meta, true) && ! empty($item_data->additional_time) ) {
								$time_output = self::prep_creation_time($item_data->additional_time);
								if ( ! empty($time_output['time']) ) {
									$item['data'][] = [ __('Additional Time', 'mediavine'), $time_output['time'] ];
								}
							}
							if ( in_array('total_time', $item_meta, true) && ! empty($item_data->total_time) ) {
								$time_output = self::prep_creation_time($item_data->total_time);
								if ( ! empty($time_output['time']) ) {
									$item['data'][] = [ __('Total Time', 'mediavine'), $time_output['time'] ];
								}
							}
							if ( in_array('yield', $item_meta, true) && ! empty($item_data->yield) ) {
								$item['data'][] = [ __('Yield', 'mediavine'), $item_data->yield ];
							}
							if ( in_array('category', $item_meta, true) && ! empty($item_data->category) ) {
								$term           = \get_term($item_data->category, 'category');
								$item['data'][] = [ __('Category', 'mediavine'), $term->name ];
							}
							// Recipes
							if ( in_array('calories', $item_meta, true) && ! empty($item_data->nutrition) ) {
								$item['data'][] = [ __('Calories', 'mediavine'), $item_data->nutrition->calories ];
							}
							if ( in_array('cuisine', $item_meta, true) && ! empty($item_data->secondary_term) ) {
								$term           = \get_term($item_data->secondary_term, 'mv_cuisine');
								$item['data'][] = [ __('Cuisine', 'mediavine'), $term->name ];
							}
							// DIY
							if ( in_array('project_type', $item_meta, true) && ! empty($item_data->secondary_term) ) {
								$term           = \get_term($item_data->secondary_term, 'mv_project_types');
								$item['data'][] = [ __('Project Type', 'mediavine'), $term->name ];
							}
							if ( in_array('cost', $item_meta, true) && ! empty($item_data->estimated_cost) ) {
								$item['data'][] = [ __('Cost', 'mediavine'), $item_data->estimated_cost ];
							}
							if ( in_array('difficulty', $item_meta, true) && ! empty($item_data->difficulty) ) {
								$item['data'][] = [ __('Difficulty', 'mediavine'), $item_data->difficulty ];
							}
						}

						// Add Pinterest
						$item['pinterest'] = [];
						if ( ! empty($item_data->pinterest_url) ) {
							$item['pinterest']['url'] = $item_data->pinterest_url;
						} elseif ( ! empty($item_data->canonical_post_id) ) {
							$item['pinterest']['url'] = get_permalink($item_data->canonical_post_id);
						} else {
							$item['pinterest']['url'] = $item['url'];
						}

						if ( ! empty($item_data->pinterest_description) ) {
							$item['pinterest']['description'] = Str::truncate($item_data->pinterest_description, 500);
						} else {
							$item['pinterest']['description'] = Str::truncate(strip_tags($item['description']), 500);
						}

						if ( ! empty($item_data->pinterest_img_id) ) {
							$pinterest_img = wp_get_attachment_image_src($item_data->pinterest_img_id, 'mv_creation_vert');
						} else {
							$pinterest_img = wp_get_attachment_image_src($item['thumbnail_id'], 'mv_creation_vert');
						}

						// have this fail (no Pin button) if no image is available
						if ( ! empty($pinterest_img[0]) ) {
							$item['pinterest']['img'] = $pinterest_img[0];
						}
					}

					$item = self::create_list_item_extra($item);
				}
			}

			// Add unit conversion data if feature is enabled and card is a recipe
			$uc_is_recipe   = 'recipe' === $atts['type'];
			$uc_gatekeeper  = GateKeeper::can_access( GateKeeper::FEATURE_UNIT_CONVERSION );
			$uc_setting     = Settings::get_setting( 'mv_create_enable_unit_conversion', false );

			if ( $uc_is_recipe && $uc_gatekeeper && $uc_setting ) {
				$conversion_data = self::get_unit_conversion_data( $creation->id );
				if ( ! empty( $conversion_data ) ) {
					$published_creation['unit_conversions'] = $conversion_data;
				}
			}

			// Remove hardcoded ad hints from instructions
			$published_creation['instructions'] = str_replace('<div class="mv-create-target"><div class="mv_slot_target" data-slot="recipe"></div></div>', '', (string) $published_creation['instructions']);

			// Remove meta span tags from instructions
			$mv_schema_meta_regex               = get_shortcode_regex([ 'mv_schema_meta' ]);
			$published_creation['instructions'] = preg_replace('/' . $mv_schema_meta_regex . '/s', '', $published_creation['instructions']);

			// Sanitize empty-ish fields, which may contain nothing but empty p tags
			$fields_to_check = [ 'instructions', 'notes' ];

			// Loop over fields
			foreach ( $fields_to_check as $field ) {
				$temp = $published_creation[ $field ];
				// Strip out HTML tags
				$no_more_tags   = strip_tags($temp ?: '');
				$no_more_spaces = preg_replace('/\s+/', '', $no_more_tags);
				// If the -stripped- string doesn't have any content, we set to null
				if ( ! strlen($no_more_spaces) ) {
					$published_creation[ $field ] = null;
				}
			}

			// Prevent multiple JSON-LD for Lists and How Tos
			if (
				( self::$multiple_recipes && 'recipe' === $atts['type'] ) ||
				( self::$multiple_howtos && 'diy' === $atts['type'] ) ||
				( self::$multiple_lists && 'list' === $atts['type'] )
			) {
				unset($published_creation['json_ld']);
			}

			$is_canonical = false;
			if ( $id === (int) $published_creation['canonical_post_id'] ) {
				$is_canonical = true;
			}

			// Only set howto to true if JSON_LD is outputted
			if (
				$is_canonical &&
				! empty($published_creation['json_ld']) &&
				'recipe' === $atts['type'] &&
				// Reverse of what is used to display JSON-LD
				! (
					// Check isset so old cards still display schema,
					// and check empty because of some PHP interpreting `! $var` as strict with 0 strings
					isset($published_creation['schema_display']) &&
					empty($published_creation['schema_display'])
				)
			) {
				self::$multiple_recipes = true;
			}

			// Only set howto to true if JSON_LD is outputted
			if (
				$is_canonical &&
				! empty($published_creation['json_ld']) &&
				'diy' === $atts['type'] &&
				// Reverse of what is used to display JSON-LD
				! (
					// Check isset so old cards still display schema,
					// and check empty because of some PHP interpreting `! $var` as strict with 0 strings
					isset($published_creation['schema_display']) &&
					empty($published_creation['schema_display'])
				)
			) {
				self::$multiple_howtos = true;
			}

			// Only set list to true if JSON_LD is outputted
			if (
				$is_canonical &&
				! empty($published_creation['json_ld']) &&
				'list' === $atts['type'] &&
				// Reverse of what is used to display JSON-LD
				! (
					// Check isset so old cards still display schema,
					// and check empty because of some PHP interpreting `! $var` as strict with 0 strings
					isset($published_creation['schema_display']) &&
					empty($published_creation['schema_display'])
				)
			) {
				self::$multiple_lists = true;
			}
		}

		return $published_creation;
	}

	/**
	 * Gets image size setting
	 * @return string Image size setting
	 */
	public static function get_image_size() {
	   return \Mediavine\Settings::get_setting(self::$settings_group . '_photo_ratio', 'mv_create_16x9');
	}

	/**
	 * Performs nutrition logic for frontend card render
	 *
	 * @param array array with nutrition values
	 *
	 * @return array|false updated nutrition values or false if none exist
	 */
	public static function get_nutrition_data( $nutrition ) {
		$nutrition_output = [
			'items' => [],
		];

		if ( ! empty($nutrition) ) {
			$use_ugly_nutrition_display = Settings::get_setting(self::$settings_group . '_use_realistic_nutrition_display');

			// Set the nutrition sugar alcohol and net zero display if it's not set or is using the global setting
			if ( ! isset($nutrition['display_zeros']) || '' === $nutrition['display_zeros'] ) {
				$nutrition['display_zeros'] = Settings::get_setting(
					self::$settings_group . '_display_nutrition_zeros',
					false
				);
			}

			$nutrition_facts = [
				'calories'        => [
					'name'  => __('Calories', 'mediavine'),
					'unit'  => null,
					'class' => 'calories',
				],
				'total_fat'       => [
					'name'  => __('Total Fat', 'mediavine'),
					'unit'  => 'g',
					'class' => 'total-fat',
				],
				'saturated_fat'   => [
					'name'  => __('Saturated Fat', 'mediavine'),
					'unit'  => 'g',
					'class' => 'saturated-fat mv-create-nutrition-indent',
				],
				'trans_fat'       => [
					'name'  => __('Trans Fat', 'mediavine'),
					'unit'  => 'g',
					'class' => 'trans-fat mv-create-nutrition-indent',
				],
				'unsaturated_fat' => [
					'name'  => __('Unsaturated Fat', 'mediavine'),
					'unit'  => 'g',
					'class' => 'unsaturated-fat mv-create-nutrition-indent',
				],
				'cholesterol'     => [
					'name'  => __('Cholesterol', 'mediavine'),
					'unit'  => 'mg',
					'class' => 'cholesterol',
				],
				'sodium'          => [
					'name'  => __('Sodium', 'mediavine'),
					'unit'  => 'mg',
					'class' => 'sodium',
				],
				'carbohydrates'   => [
					'name'  => __('Carbohydrates', 'mediavine'),
					'unit'  => 'g',
					'class' => 'carbohydrates',
				],
				'net_carbs'       => [
					'name'  => __('Net Carbohydrates', 'mediavine'),
					'unit'  => 'g',
					'class' => 'net-carbohydrates mv-create-nutrition-indent',
				],
				'fiber'           => [
					'name'  => __('Fiber', 'mediavine'),
					'unit'  => 'g',
					'class' => 'fiber mv-create-nutrition-indent',
				],
				'sugar'           => [
					'name'  => __('Sugar', 'mediavine'),
					'unit'  => 'g',
					'class' => 'sugar mv-create-nutrition-indent',
				],
				'sugar_alcohols'  => [
					'name'  => __('Sugar Alcohols', 'mediavine'),
					'unit'  => 'g',
					'class' => 'sugar-alcohols mv-create-nutrition-indent',
				],
				'protein'         => [
					'name'  => __('Protein', 'mediavine'),
					'unit'  => 'g',
					'class' => 'protein',
				],
			];

			foreach ( $nutrition_facts as $slug => $nutrition_fact ) {
				if ( isset($nutrition[ $slug ]) && ( ! empty($nutrition[ $slug ]) || 0 === $nutrition[ $slug ] || '0' === $nutrition[ $slug ] ) ) {
					$nutrition_label             = ( $use_ugly_nutrition_display ) ? $nutrition_fact['name'] : $nutrition_fact['name'] . ':';
					$nutrition_output['items'][] = [
						'slug'  => $slug,
						'label' => $nutrition_label,
						'value' => $nutrition[ $slug ],
						'unit'  => $nutrition_fact['unit'],
						'class' => $nutrition_fact['class'],
					];
				}
			}
		}

		if ( ! empty($nutrition_output['items']) ) {
			$nutrition_output['number_of_servings'] = $nutrition['number_of_servings'];
			$nutrition_output['serving_size']       = $nutrition['serving_size'];
			$nutrition_output['display_zeros']      = $nutrition['display_zeros'];

			return $nutrition_output;
		}

		return false;
	}

	/**
	 * Build unit conversion data for frontend localization.
	 *
	 * Checks for cached conversions in metadata. If not cached and the feature
	 * is enabled, triggers lazy computation via the Unit_Conversion service.
	 *
	 * @param int $creation_id The creation ID.
	 * @return array|null Conversion data for frontend, or null if unavailable.
	 */
	public static function get_unit_conversion_data( $creation_id ) {
		$converter = Unit_Conversion::get_instance();
		$cached    = Plugin::is_dev_mode() ? null : $converter->get_cached_conversions( $creation_id );

		// Lazy compute on first frontend request if not cached (or dev mode)
		if ( empty( $cached ) ) {
			$result = $converter->convert_creation( $creation_id );
			if ( is_wp_error( $result ) ) {
				return null;
			}
			if ( empty( $result ) ) {
				return null;
			}
			$cached = $result;
		}

		if ( empty( $cached['ingredients'] ) ) {
			return null;
		}

		$default_system = Settings::get_setting( 'mv_create_unit_conversion_default_system', 'auto' );
		$label          = Settings::get_setting( 'mv_create_unit_conversion_label', 'Unit Conversion' );

		return [
			'enabled'        => true,
			'default_system' => $default_system,
			'source_system'  => $cached['source_system'] ?? 'us_customary',
			'label'          => $label,
			'conversions'    => $cached['ingredients'],
			// Emitted into data-cs-config so the widget can compare against its
			// own expected schema version and run a fresh fetch when the markup
			// trails it. We emit the version stored alongside the cached blob
			// (sourced from Studio's /conversions/batch response) rather than
			// Unit_Conversion::VERSION, so a schema bump on the server reaches
			// the widget without waiting for a plugin code update.
			'version'        => isset( $cached['version'] ) ? (int) $cached['version'] : Unit_Conversion::VERSION,
		];
	}

	/**
	 * Returns true if a list item should render as a section divider.
	 *
	 * Either explicitly content_type='divider', or a legacy text item with
	 * no thumbnail, url, or button text — the pre-2.0 shape that publishers
	 * used as section headings inside numbered lists.
	 *
	 * @param array $item List item data.
	 * @return bool
	 */
	public static function is_list_item_divider( $item ) {
		$type = $item['content_type'] ?? '';
		if ( 'divider' === $type ) {
			return true;
		}
		if ( 'text' !== $type ) {
			return false;
		}
		$has_media  = ! empty( $item['thumbnail_id'] );
		$has_url    = ! empty( $item['url'] );
		$has_button = ! empty( $item['link_text'] ) || ! empty( $item['btn_text'] );
		return ! $has_media && ! $has_url && ! $has_button;
	}

	/**
	 * Resolve the button text for a list item.
	 *
	 * Priority: item link_text > content/type defaults > first custom button option > hardcoded fallback.
	 *
	 * @param array $item List item data with optional link_text, content_type, secondary_type keys.
	 * @return string
	 */
	public static function resolve_list_item_button_text( $item ) {
		if ( ! empty( $item['link_text'] ) ) {
			return $item['link_text'];
		}

		if ( 'product' === ( $item['content_type'] ?? '' ) ) {
			return __( 'View Product', 'mediavine' );
		}

		if ( 'recipe' === ( $item['secondary_type'] ?? '' ) ) {
			return __( 'Get the Recipe', 'mediavine' );
		}

		if ( 'diy' === ( $item['secondary_type'] ?? '' ) ) {
			return __( 'Read the Guide', 'mediavine' );
		}

		$custom_buttons = \Mediavine\Settings::get_setting( self::$settings_group . '_custom_buttons', 'Continue Reading\nRead More\nGet Recipe' );
		$buttons        = preg_split( '/\\\\n|\n/', $custom_buttons );

		$first = trim( $buttons[0] ?? '' );

		return '' !== $first ? $first : __( 'Continue Reading', 'mediavine' );
	}

	public static function create_list_item_extra( $item ) {
		ob_start();
		?>
		<div class="mv-list-meta">
			<?php
			if ( isset($item['data']) ) {
				foreach ( $item['data'] as $value ) {
			?>
					<span class="mv-list-meta-item">
						<strong><?php echo esc_html($value[0]); ?></strong> <?php echo wp_kses_post($value[1]); ?>
					</span>
			<?php
				}
			}
			?>
		</div>
	<?php
		$item['extra'] = ob_get_clean();

		// Prevent empty-ish content
		$item['extra'] = preg_replace('/^\s*$/', '', $item['extra']);

		return $item;
	}

	/**
	 * Converts the rounded corner setting to a CSS variable
	 *
	 * @return void
	 */
	public function lists_rounded_corners() {
	   $radius_enabled = \Mediavine\Settings::get_setting('mv_create_lists_rounded_corners');
	?>
		<style>
			:root {
				--mv-create-radius: <?php echo esc_attr($radius_enabled); ?>;
			}
		</style>
	<?php
	}

	// [mv_create] shortcode
	public function mv_create_shortcode( $atts, $content = null ) {
		// Return if no key
		if ( empty($atts['key']) || 'undefined' === $atts['key'] ) {
			return;
		}

		// Base for themes is create
		$atts['base'] = 'create';

		// Use recipe if no type
		if ( empty($atts['type']) ) {
			$atts['type'] = 'recipe';
		}

		// Get version
		$atts['version'] = apply_filters('mv_create_style_version', $this->card_style_version, $atts);

		// Add allowed html for description
		$atts['allowed_html'] = [
			'a'      => [
				'href'   => [],
				'title'  => [],
				'target' => [],
				'rel'    => [],
			],
			'em'     => [],
			'strong' => [],
			'p'      => [],
			'br'     => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
		];

		// Get card style
		$default_card_style = 'square';
		$card_style         = \Mediavine\Settings::get_setting(self::$settings_group . '_card_style');

		if ( ! empty($card_style) ) {
			$default_card_style = $card_style;
		}

		if ( empty($atts['style']) ) {
			$atts['style'] = $default_card_style;
		}

		// Theme preview override (admin only)
		if ( ! empty( $_GET['create_theme'] ) && current_user_can( 'manage_options' ) ) {
			$preview_theme = sanitize_text_field( wp_unslash( $_GET['create_theme'] ) );
			$valid_themes  = [ 'square', 'dark', 'centered', 'centered-dark', 'big-image', 'editorial', 'modern' ];
			if ( in_array( $preview_theme, $valid_themes, true ) ) {
				$atts['style'] = $preview_theme;
			}
		}

		// Apply theme fallback for gated themes if user doesn't have Pro access
		// Allow admins to preview gated themes via ?create_theme= without fallback
		$gated_themes    = [ 'editorial', 'modern' ];
		$is_theme_preview = ! empty( $_GET['create_theme'] ) && current_user_can( 'manage_options' );
		if ( in_array( $atts['style'], $gated_themes, true ) && ! GateKeeper::is_pro_or_higher() && ! $is_theme_preview ) {
			$atts['style'] = 'big-image'; // Fallback to Hero Image theme
		}

		// Print view
		$print = false;
		if ( isset($atts['print']) ) {
			$print = true;
		}
		$atts['print'] = $print;

		// Build layout with card style hooks
		$card_type = 'card';
		if ( 'list' === $atts['type'] ) {
			$card_type = 'list';
		}

		$card_style_hook_function = $card_type . '_style_' . str_replace('-', '_', $atts['style']) . '_hooks';
		if ( ! method_exists('Mediavine\Create\Creations_Views_Hooks', $card_style_hook_function) ) {
			$card_style_hook_function = 'card_style_square_hooks';
			if ( 'list' === $atts['type'] ) {
				$card_style_hook_function = 'list_style_square_hooks';
				$atts['style']            = 'square';
			}
		}
		Creations_Views_Hooks::$card_style_hook_function($atts['type'], $atts['version']);

		// Hooks for template overrides cannot be removed unless they are run AFTER we have hooked them
		do_action('mv_create_modify_card_style_hooks', $atts['style'], $atts['type']);

		// Prep creation
		$atts['creation'] = self::prep_creation_view($atts);

		// Adjust video first, then products (products may position relative to video)
		if ( 'list' !== $atts['type'] && ! empty( $atts['creation'] ) ) {
			Creations_Views_Hooks::adjust_video_priority( $atts );
			Creations_Views_Hooks::adjust_products_priority( $atts );
		}

		// Don't display a card if there's no creation data
		if ( empty($atts['creation']) ) {
			return;
		}

		// Don't display a list if there are no list items
		if ( 'list' === $atts['creation']['type'] && empty($atts['creation']['list_items']) ) {
			return;
		}

		$atts['allow_reviews'] = \Mediavine\Settings::get_setting(self::$settings_group . '_allow_reviews');

		$attrs_to_be_normalized = [ 'author', 'notes', 'description', 'instructions' ];

		foreach ( $attrs_to_be_normalized as $attr ) {
			if ( ! empty($atts['creation'][ $attr ]) ) {
				$atts['creation'][ $attr ] = static::normalize_block_tags($atts['creation'][ $attr ]);
				$atts['creation'][ $attr ] = str_replace('&quot;', '"', $atts['creation'][ $attr ]);
			}
		}

		$atts['creation']['secondary_term_label'] = __('Type', 'mediavine');
		if ( 'recipe' === $atts['creation']['type'] ) {
			$atts['creation']['secondary_term_label'] = __('Cuisine', 'mediavine');
		}
		if ( 'diy' === $atts['creation']['type'] ) {
			$atts['creation']['secondary_term_label'] = __('Project Type', 'mediavine');
		}

		$atts['enable_nutrition']                = \Mediavine\Settings::get_setting(self::$settings_group . '_enable_nutrition');
		$atts['use_realistic_nutrition_display'] = \Mediavine\Settings::get_setting(self::$settings_group . '_use_realistic_nutrition_display');
		$atts['ad_density']                      = \Mediavine\Settings::get_setting(self::$settings_group . '_ad_density');

		// Add old keys to array if custom template
		$has_custom_v1_template = apply_filters('mv_create_style_version', false);
		if ( 'v1' === $has_custom_v1_template ) {
			$atts['disable_nutrition'] = ! $atts['enable_nutrition'];
			$atts['disable_reviews']   = ! $atts['allow_reviews'];
		}

		// Run filter for wp_kses output and then remove after shortcode added
		add_filter('wp_kses_allowed_html', [ $this, 'create_wp_kses' ], 2, 10);

		/**
		 * Fires immediately before Create card template has been built
		 *
		 * @param array $atts All card attributes used to generate card
		 */
		do_action('mv_create_card_before_render', $atts);

		$creation_view = self::$views->get_view('shortcode-mv-create.php', $atts);

		/**
		 * Fires immediately after Create card template has been built
		 *
		 * @param array $atts All card attributes used to generate card
		 * @param array $creation_view Rendered HTML of Create card
		 */
		do_action('mv_create_card_after_render', $atts, $creation_view);

		// We have some overlapping actions that can create duplicate content if we don't clean up after a card is rendered.
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_image' ], 10);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_title' ], 10);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_pin_button' ], 20);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_title' ], 30);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_description' ], 20);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_description' ], 40);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_description' ], 50);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_rating' ], 60);
		remove_action('mv_create_card_header', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_print_button' ], 70);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_print_button' ], 30);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_description' ], 20);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_ad_div' ], 10);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_ad_div' ], 20);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_ad_div' ], 40);
		remove_action('mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_list' ], 10);
		remove_action('mv_create_card_image_container', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_image' ], 10);
		remove_action('mv_create_card_image_container', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_rating' ], 20);
		remove_action('mv_create_card_image_container', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_print_button' ], 30);
		remove_action('mv_create_card_footer', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_footer' ], 10);

		remove_filter('wp_kses_allowed_html', [ $this, 'create_wp_kses' ], 10);

		if ( ! empty($creation_view) ) {
			self::$has_card = true;
			if ( ! apply_filters('mv_create_dev_mode', false) ) {
				wp_enqueue_style('mv-create-card_' . $atts['style']);
			}
			wp_enqueue_script(Plugin::PLUGIN_DOMAIN . '/client.js');
			self::enqueue_studio_script();

			// Converts heading tags down if setting requires
			$creation_view = self::adjust_headings_level($creation_view, $atts['creation']);

			/**
			 * Filters the rendered Create card content
			 *
			 * @param array $atts List of attributes used to render card
			 */
			$creation_view = apply_filters('mv_create_card_render', $creation_view, $atts);

			return $creation_view;
		}

		return false;
	}

	public static function prep_creation_times( $creation, array $additionals = [] ) {
		$prepared_times = [];
		$times_to_parse = [
			'prep_time',
			'active_time',
			'additional_time',
			'perform_time',
			'total_time',
		];
		$times_to_parse = apply_filters('mv_times_to_parse', $times_to_parse);

		$creation_times         = [];
		$creation_times_keys    = [];
		$creation_times_objects = Arr::only($creation, $times_to_parse);
		$creation_times_objects = array_filter($creation_times_objects);
		foreach ( $creation_times_objects as $key => $time ) {
			$creation_times[ $key ] = (array) $time;
			$creation_times_keys[]  = $key;
		}
		if ( empty($creation_times) ) {
			return $prepared_times;
		}

		if ( empty($creation['time_display']) ) {
			$creation['time_display'] = 'prep_time,active_time,additional_time';
		}

		$time_display_order = trim($creation['time_display'], ',');
		$time_display_order = explode(',', $time_display_order);
		$time_display_order = array_intersect($time_display_order, $creation_times_keys);
		$localized_labels   = [
			'Prep Time'       => __('Prep Time', 'mediavine'),
			'Cook Time'       => __('Cook Time', 'mediavine'),
			'Additional Time' => __('Additional Time', 'mediavine'),
		];

		foreach ( $time_display_order as $time_display ) {
			$label = '';
			if ( ! empty($creation[ $time_display . '_label' ]) ) {
				$label = $creation[ $time_display . '_label' ];
			}

			if ( array_key_exists($label, $localized_labels) ) {
				$label = $localized_labels[ $label ];
			}

			$prepared_time = static::prep_creation_time($creation_times[ $time_display ], $time_display, $label);
			if ( ! empty($prepared_time) ) {
				$prepared_times[] = $prepared_time;
			}
		}

		if ( count($prepared_times) && ! empty($creation['total_time']) ) {
			$prepared_times[] = static::prep_creation_time($creation['total_time'], 'total_time', __('Total Time', 'mediavine'));
		}

		// We will set additionals if DIY type and nothing previously added
		if ( 'diy' === $creation['type'] && empty($additionals) ) {
			$diy_additionals = [
				'difficulty'     => [
					'value' => $creation['difficulty'],
					'label' => __('Difficulty', 'mediavine'),
				],
				'estimated_cost' => [
					'value' => $creation['estimated_cost'],
					'label' => __('Estimated Cost', 'mediavine'),
				],
			];
			$additionals     = apply_filters('mv_create_diy_additionals', $diy_additionals, $creation);
		}

		if ( ! empty($additionals) ) {
			foreach ( $additionals as $meta => $data ) {
				if ( is_array($data) && ! empty($data['value']) && ! empty($data['label']) ) {
					$prepared_times[] = [
						'time'  => $data['value'],
						'label' => $data['label'],
						'class' => $meta,
					];
				}
			}
		}

		return $prepared_times;
	}

	/**
	 * Preps the creation time for output with localized time ranges.
	 *
	 * @param array|object $time_array Array out times to adjust
	 * @param string       $time_display The type of time passed through to determine the class
	 * @param string       $label The label to be passed through
	 * @return array List containing time's output, label and class
	 */
	public static function prep_creation_time( $time_array, $time_display = '', $label = '' ) {
		$time = [];

		// Force to array if object
		if ( is_object($time_array) ) {
			$time_array = (array) $time_array;
		}

		// Return early if not array
		if ( ! is_array($time_array) ) {
			return $time;
		}

		$prepared_time = [];

		// Prep time formats for translation
		$time_text = [
			'years'   => [
				'single' => __('year', 'mediavine'),
				'plural' => __('years', 'mediavine'),
			],
			'months'  => [
				'single' => __('month', 'mediavine'),
				'plural' => __('months', 'mediavine'),
			],
			'days'    => [
				'single' => __('day', 'mediavine'),
				'plural' => __('days', 'mediavine'),
			],
			'hours'   => [
				'single' => __('hour', 'mediavine'),
				'plural' => __('hours', 'mediavine'),
			],
			'minutes' => [
				'single' => __('minute', 'mediavine'),
				'plural' => __('minutes', 'mediavine'),
			],
			'seconds' => [
				'single' => __('second', 'mediavine'),
				'plural' => __('seconds', 'mediavine'),
			],
		];

		// Create output
		$time_array['output'] = null;
		foreach ( $time_text as $time => $format ) {
			if ( ! empty($time_array[ $time ]) ) {
				$text = $format['single'];
				if ( 1 !== $time_array[ $time ] ) {
					$text = $format['plural'];
				}
				$time_array['output'] .= '<span class="mv-time-part mv-time-' . $time . '">' . $time_array[ $time ] . ' ' . esc_html($text) . '</span> ';
			}
		}
		$prepared_time['time'] = apply_filters('mv_create_time_output', $time_array['output'], $time_array);

		$prepared_time['label'] = $label;
		$prepared_time_class    = explode('_time', $time_display);
		$prepared_time['class'] = $prepared_time_class[0];

		return $prepared_time;
	}

	// [mv_recipe] shortcode
	public function mv_recipe_shortcode( $atts, $content = null ) {
		$creation = self::$models_v2->mv_creations->find_one(
			[
				'where' => [
					'original_object_id' => $atts['post_id'],
				],
			]
		);
		if ( empty($creation->id) ) {
			return false;
		}
		$atts['key']  = $creation->id;
		$atts['type'] = 'recipe';

		return $this->mv_create_shortcode($atts);
	}

	/**
	 * Checks for existence of a custom field for a given Creation and returns its value or a default value.
	 *
	 * @param array  $creation Specifically `$args['creation']` as used in card styles
	 * @param string $slug The slug of the desired custom field
	 * @param string $default The value to return if no custom field data is found
	 * @param bool   $default_check If the value is `default` then use the default value
	 * @return string|mixed
	 */
	public static function get_custom_field( $creation, $slug, $default = '', $default_check = false ) {
		$value = $default;

		// Force array if json string
		if ( ! empty($creation['custom_fields']) && is_string($creation['custom_fields']) ) {
			$creation['custom_fields'] = json_decode($creation['custom_fields'], true);
		}

		if ( ! empty($creation['custom_fields'][ $slug ]) ) {
			$value = $creation['custom_fields'][ $slug ];
		}

		// Use default value if 'default' is the current value
		if ( $default_check && 'default' === $value ) {
			$value = $default;
		}

		return $value;
	}

	/**
	 * Given a string that might contain block tags leftover from EZR, transform into valid HTML
	 *
	 * Supported:
	 *   - [br] --> line break
	 *   - [url:<id>]...[/url] --> <a> tag with href of permalink of post with ID <id>
	 *   - [url...href...]...[/url] --> <a> tag with href
	 *   - [b]...[/b] --> <strong> tag
	 *   - [i]...[/i] --> <em> tag
	 *   - [u]...[/u] --> <u> tag
	 */
	public static function normalize_block_tags( $string ) {
		// Replace line breaks
		$string = str_replace('[br]', '<br/>', $string);

		// Replace strong and em tags
		$string = preg_replace('/\[b](.*?)\[\/b]/', '<strong>$1</strong>', $string);
		$string = preg_replace('/\[i](.*?)\[\/i]/', '<em>$1</em>', $string);
		$string = preg_replace('/\[u](.*?)\[\/u]/', '<u>$1</u>', $string);

		// Replace links with href
		$string = preg_replace('/\[url([^]]+href[^]]+)](.*?)\[\/url]/', '<a $1>$2</a>', $string);

		// Replace links with ids
		$string = preg_replace_callback(
			'/\[url:(\d+)](.*)\[\/url]/',
			function ( $matches ) {
				$permalink = get_the_permalink($matches[1]);

				return '<a href="' . $permalink . '">' . $matches[2] . '</a>';
			},
			$string
		);

		return $string;
	}

	/**
	 * Reduces headings from h1s to h2s and down if setting is set
	 *
	 * @param string $creation_view Current output of creation card
	 * @param array  $creation Current creation data
	 *
	 * @return string Output of creation card
	 */
	public static function adjust_headings_level( $creation_view, $creation ) {
		// Only adjust if setting to adjust set to true and title not hidden
		if (
			'h2' === \Mediavine\Settings::get_setting(self::$settings_group . '_primary_headings', 'h2') &&
			empty($creation['title_hide'])
		) {
			$headings = [
				'<h3'  => '<h4',
				'</h3' => '</h4',
				'<h2'  => '<h3',
				'</h2' => '</h3',
				'<h1'  => '<h2',
				'</h1' => '</h2',
			];

			foreach ( $headings as $old => $new ) {
				$creation_view = str_replace($old, $new, $creation_view);
			}
		}

		return $creation_view;
	}

	/**
	 * Inline script to disable mediavine pagespeed on print views
	 *
	 * Will only display if `mv-script-wrapper` has been added to page
	 *
	 * @return void
	 */
	public function print_inline_script() {
		 wp_add_inline_script(
			Plugin::PLUGIN_DOMAIN . '/client.js',
			'
			window.$mediavine = window.$mediavine || {}
			window.$mediavine.web = window.$mediavine.web || {}
			window.$mediavine.web.disable_pagespeed = true

			document.addEventListener("load", window.setTimeout(function(){ window.print() }, 1500) );
		'
		);

		add_filter('mv_trellis_nonasync_js_handles', [ $this, 'disable_client_async' ]);
	}

	/**
	 * make sure trellis adds the inline script for printing cards
	 *
	 * @param array $disallowed_handles array of script handles to exclude from async/defering
	 *
	 * @return array
	 */
	public function disable_client_async( $disallowed_handles ) {
		$disallowed_handles[] = Plugin::PLUGIN_DOMAIN . '/client.js';

		return $disallowed_handles;
	}

	public function print_view( \WP_REST_Request $request ) {
		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		$api_services = new \Mediavine\Create\API_Services();
		$params       = $api_services->process_inbound($request);
		$creation     = self::$models_v2->mv_creations->find_one( (int) $params['id']);

		add_action('wp_enqueue_scripts', [ $this, 'print_inline_script' ]);

		add_action(
			'mv_create_card_footer',
			function ( $args ) {
				if ( isset($args['creation']) && isset($args['creation']['canonical_post_id']) ) {
					echo '<span class="mv-create-canonical-link">' . esc_url(get_the_permalink($args['creation']['canonical_post_id'])) . '</span>';
				}
			},
			100
		);

		if ( empty($creation) ) {
			header('HTTP/1.0 404 Not Found');
			esc_html_e('No Card with ID found', 'mediavine');
			exit();
		}

		$print_title = apply_filters('mv_create_print_title', esc_html($creation->title . ' - ' . get_bloginfo('name')));
		$canonical   = get_permalink($creation->canonical_post_id);

		// Use recipe if no type
		$default_type = 'recipe';
		if ( ! empty($creation->type) ) {
			$default_type = $creation->type;
		}

		$card_style = apply_filters('mv_create_print_card_style', 'square');
		$card_style = apply_filters('mv_create_' . $default_type . '_print_card_style', $card_style);

		// Do not async styles when in print view
		remove_filter('style_loader_tag', [ $this, 'add_async_styles' ], 10, 3);

		/**
		 * last chance to add/remove things before output
		 */
		do_action('mv_create_card_before_print_render');
	?>
		<!DOCTYPE html>
		<html>

		<head>
			<title><?php echo esc_html($print_title); ?></title>
			<meta name="robots" content="noindex">
			<meta name="pinterest" content="nopin" description="Sorry, you can't pin print pages." />
			<meta property="og:url" content="<?php echo esc_attr($canonical); ?>" />
			<link rel="canonical" href="<?php echo esc_attr($canonical); ?>">
			<?php
			do_action(
				'mv_create_print_head',
				[
					'creation'   => $creation,
					'card_style' => $card_style,
					'type'       => $default_type,
				]
			);
			?>
			<?php wp_head(); ?>

		</head>

		<body>

			<?php
			/**
			 * mv_create_print_before hook.
			 */
			do_action(
				'mv_create_print_before',
				[
					'creation'   => $creation,
					'card_style' => $card_style,
					'type'       => $default_type,
				]
			);

			self::$views->the_view(
				'v1/print-mv-create.php',
				[
					'creation'   => $creation,
					'card_style' => $card_style,
					'type'       => $default_type,
				]
			);

			/**
			 * mv_create_print_after hook.
			 */
			do_action(
				'mv_create_print_after',
				[
					'creation'   => $creation,
					'card_style' => $card_style,
					'type'       => $default_type,
				]
			);
			?>

			<?php wp_footer(); ?>
		</body>

		</html>

<?php
		exit();
	}

	/**
	 * Output rel attribute
	 *
	 * @param array   $item
	 * @param boolean $target_blank
	 * @return void|string
	 */
	public static function rel_attribute( $item, $target_blank = true ) {
		if ( ! $target_blank ) {
			return '';
		}

		$rel_string = 'rel="%s"';
		$rel        = [];

		if ( $item['nofollow'] ) {
			$rel[] = 'nofollow';
		}

		$rel[] = 'noopener';

		return sprintf($rel_string, implode(' ', $rel));
	}


	/**
	 * Output list image
	 *
	 * @param array $item List item to process
	 *
	 * @return string
	 */
	public static function img( $item ) {
		$external_thumbnail_url = self::get_external_thumbnail_url($item);
		if ( ! empty($item['asin']) && ! empty($external_thumbnail_url) ) {
			$alt = ! empty( $item['title'] )
				/* translators: %s: list item title */
				? sprintf( __( 'Image for %s', 'mediavine' ), $item['title'] )
				: '';
			return sprintf('<img src="%s" alt="%s" data-pin-nopin="true" />', $external_thumbnail_url, esc_attr( $alt ));
		}

		return $item['thumbnail_url'];
	}

	/**
	 * Parse item meta field for external_thumbnail_url
	 *
	 * @param array $item Item to process
	 * @return string
	 */
	public static function get_external_thumbnail_url( $item ) {
		if ( empty($item['meta']) ) {
			return '';
		}

		$meta = json_decode($item['meta'] ?: '{}');
		// Ensure $meta is an object (json_decode can return array if meta contains [])
		if ( ! is_object($meta) || empty($meta->external_thumbnail_url) ) {
			return '';
		}

		return esc_url($meta->external_thumbnail_url);
	}

	/**
	 * Parse item meta field for Amazon product description
	 *
	 * @param array $item Item to process
	 * @return string
	 */
	public static function get_amazon_description( $item ) {
		if ( empty($item['meta']) ) {
			return '';
		}

		$meta = json_decode($item['meta'] ?: '{}');
		// Ensure $meta is an object (json_decode can return array if meta contains [])
		if ( ! is_object($meta) || empty($meta->description) ) {
			return '';
		}

		return $meta->description;
	}


	/**
	 * Validate Pinterest arguments for building
	 *
	 * @param array $item List item details
	 *
	 * @return bool True if args are validated
	 */
	public static function validate_pinterest_args( $item ) {
		// We don't want to add pin buttons to pinterest links
		if ( strpos($item['url'], '//www.pinterest.') || strpos($item['url'], '//pinterest.') ) {
			return false;
		}

		return true;
	}

	/**
	 * Build array of Pinterest arguments
	 *
	 * @param array $item List item details
	 * @param array $args Default item arguments
	 *
	 * @return array Updated item arguments
	 */
	public static function build_pinterest_args( $item, $args ) {
		// If items is not valid, build with an empty array so no button will appear
		if ( ! self::validate_pinterest_args($item) ) {
			return [];
		}

		// Build Pinterest specific args
		if ( ! isset($item['pinterest']) ) {
			$description = $item['description'];
			if ( empty($description) && ! empty($item['asin']) ) {
				$description = self::get_amazon_description($item);
			}

			$args['pinterest'] = [
				'img'         => empty($item['asin']) ? $item['pinterest_url'] : self::get_external_thumbnail_url($item),
				'url'         => $item['url'],
				'description' => Str::truncate(strip_tags($description), 500),
			];
		} else {
			$args['pinterest'] = $item['pinterest'];
		}

		return $args;
	}

	/**
	 * Handle the "Apply Theme" action from the preview banner.
	 */
	public function handle_apply_theme() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'flavor' ) );
		}

		check_admin_referer( 'mv_create_apply_theme' );

		$theme        = sanitize_text_field( wp_unslash( $_GET['create_theme'] ?? '' ) );
		$valid_themes = [ 'square', 'dark', 'centered', 'centered-dark', 'big-image', 'editorial', 'modern' ];

		if ( ! in_array( $theme, $valid_themes, true ) ) {
			wp_die( esc_html__( 'Invalid theme.', 'flavor' ) );
		}

		$gated_themes = [ 'editorial', 'modern' ];
		if ( in_array( $theme, $gated_themes, true ) && ! GateKeeper::is_pro_or_higher() ) {
			wp_die( esc_html__( 'This theme requires a Pro subscription.', 'flavor' ) );
		}

		\Mediavine\Settings::update_setting( self::$settings_group . '_card_style', $theme );

		$referer = wp_get_referer();
		$redirect = $referer ? remove_query_arg( 'create_theme', $referer ) : admin_url();

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render a floating banner when previewing a card theme via ?create_theme=
	 *
	 * Styled to match the ThemeSelector modal action bar from the admin UI.
	 */
	public function render_theme_preview_banner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$preview_theme = sanitize_text_field( wp_unslash( $_GET['create_theme'] ) );
		$gated_themes  = [ 'editorial', 'modern' ];
		$is_pro        = GateKeeper::is_pro_or_higher();
		$is_premium    = in_array( $preview_theme, $gated_themes, true ) && ! $is_pro;
		$upgrade_url   = GateKeeper::get_upgrade_url();
		$theme_options = [
			'square'        => 'Simple Square',
			'dark'          => 'Dark Simple Square',
			'centered'      => 'Classy Circle',
			'centered-dark' => 'Dark Classy Circle',
			'big-image'     => 'Hero Image',
			'editorial'     => 'Editorial',
			'modern'        => 'Modern',
		];

		if ( ! isset( $theme_options[ $preview_theme ] ) ) {
			return;
		}

		$dismiss_url = remove_query_arg( 'create_theme' );
		$apply_url   = wp_nonce_url(
			add_query_arg(
				[
					'action'       => 'mv_create_apply_theme',
					'create_theme' => $preview_theme,
				],
				admin_url( 'admin-post.php' )
			),
			'mv_create_apply_theme'
		);

		$base_url  = remove_query_arg( 'create_theme' );
		$bar_class = $is_premium ? ' mv-preview-bar--pro' : '';
		?>
		<style>
			#mv-create-theme-preview-bar {
				position: fixed;
				bottom: 0;
				left: 0;
				right: 0;
				z-index: 999999;
				padding: 14px 24px;
				border-top: 1px solid #e8e6e3;
				background: #fff;
				font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
				box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
			}
			#mv-create-theme-preview-bar * {
				box-sizing: border-box;
			}
			.mv-preview-bar__inner {
				max-width: 800px;
				margin: 0 auto;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
			}
			.mv-preview-bar__info {
				flex: 1;
				min-width: 0;
			}
			.mv-preview-bar__label {
				font-size: 0.8125rem;
				color: #78716c;
				margin: 0 0 2px;
			}
			.mv-preview-bar__name {
				font-size: 0.9375rem;
				font-weight: 600;
				color: #1c1917;
				margin: 0;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.mv-preview-bar__badge {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 2px 8px;
				font-size: 0.6875rem;
				font-weight: 600;
				color: #3d5b6d;
				background: #f0f5f7;
				border-radius: 9999px;
				text-transform: uppercase;
				letter-spacing: 0.02em;
			}
			.mv-preview-bar__badge--premium {
				color: #d4af37;
				background: rgba(212, 175, 55, 0.1);
			}
			.mv-preview-bar__actions {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-shrink: 0;
			}
			.mv-preview-bar__select {
				height: 34px;
				padding: 0 32px 0 12px;
				font-family: inherit;
				font-size: 0.8125rem;
				color: #44403c;
				background: #fff;
				border: 1px solid #e8e6e3;
				border-radius: 8px;
				cursor: pointer;
				appearance: none;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2378716c' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
				background-repeat: no-repeat;
				background-position: right 10px center;
				transition: all 150ms ease;
			}
			.mv-preview-bar__select:hover {
				border-color: #d4d1cc;
				background-color: #fafaf9;
			}
			.mv-preview-bar__select:focus-visible {
				outline: 2px solid #3d5b6d;
				outline-offset: 2px;
			}
			.mv-preview-bar__btn-cancel {
				display: inline-flex;
				align-items: center;
				height: 34px;
				padding: 0 14px;
				font-family: inherit;
				font-size: 0.8125rem;
				font-weight: 500;
				color: #57534e;
				background: transparent;
				border: 1px solid #e8e6e3;
				border-radius: 8px;
				cursor: pointer;
				text-decoration: none;
				transition: all 150ms ease;
			}
			.mv-preview-bar__btn-cancel:hover {
				background: #f5f4f3;
				border-color: #d4d1cc;
				color: #292524;
				text-decoration: none;
			}
			.mv-preview-bar__btn-cancel:focus-visible {
				outline: 2px solid #3d5b6d;
				outline-offset: 2px;
			}
			.mv-preview-bar__btn-apply {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				height: 34px;
				padding: 0 16px;
				font-family: inherit;
				font-size: 0.8125rem;
				font-weight: 600;
				color: #fff;
				background: #3d5b6d;
				border: none;
				border-radius: 8px;
				cursor: pointer;
				text-decoration: none;
				transition: all 150ms ease;
			}
			.mv-preview-bar__btn-apply:hover {
				background: #2d4555;
				color: #fff;
				text-decoration: none;
			}
			.mv-preview-bar__btn-apply:focus-visible {
				outline: 2px solid #3d5b6d;
				outline-offset: 2px;
			}
			.mv-preview-bar__btn-apply svg {
				width: 14px;
				height: 14px;
				fill: currentColor;
			}
			/* Pro/Premium variant */
			#mv-create-theme-preview-bar.mv-preview-bar--pro {
				background: #1c1917;
				border-top-color: #44403c;
				box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
			}
			.mv-preview-bar--pro .mv-preview-bar__label {
				color: #a8a29e;
			}
			.mv-preview-bar--pro .mv-preview-bar__name {
				color: #fff;
			}
			.mv-preview-bar--pro .mv-preview-bar__select {
				color: #e7e5e4;
				background-color: #292524;
				border-color: #44403c;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23a8a29e' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
			}
			.mv-preview-bar--pro .mv-preview-bar__select:hover {
				border-color: #57534e;
				background-color: #1c1917;
			}
			.mv-preview-bar--pro .mv-preview-bar__btn-cancel {
				color: #a8a29e;
				border-color: #44403c;
			}
			.mv-preview-bar--pro .mv-preview-bar__btn-cancel:hover {
				background: #292524;
				border-color: #57534e;
				color: #e7e5e4;
			}
			.mv-preview-bar__btn-upgrade {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				height: 34px;
				padding: 0 16px;
				font-family: inherit;
				font-size: 0.8125rem;
				font-weight: 600;
				color: #d4af37;
				background: transparent;
				border: 1px solid #d4af37;
				border-radius: 8px;
				cursor: pointer;
				text-decoration: none;
				transition: all 150ms ease;
			}
			.mv-preview-bar__btn-upgrade:hover {
				background: rgba(212, 175, 55, 0.1);
				color: #d4af37;
				text-decoration: none;
			}
			.mv-preview-bar__btn-upgrade:focus-visible {
				outline: 2px solid #d4af37;
				outline-offset: 2px;
			}
			@media (max-width: 640px) {
				.mv-preview-bar__inner {
					flex-direction: column;
					align-items: stretch;
					gap: 8px;
				}
				#mv-create-theme-preview-bar {
					padding: 12px 16px;
				}
				.mv-preview-bar__actions {
					justify-content: flex-end;
				}
			}
		</style>
		<div id="mv-create-theme-preview-bar" class="<?php echo esc_attr( trim( $bar_class ) ); ?>">
			<div class="mv-preview-bar__inner">
				<div class="mv-preview-bar__info">
					<p class="mv-preview-bar__label"><?php esc_html_e( 'Previewing theme', 'flavor' ); ?></p>
					<p class="mv-preview-bar__name">
						<?php echo esc_html( $theme_options[ $preview_theme ] ); ?>
						<?php if ( $is_premium ) : ?>
							<span class="mv-preview-bar__badge mv-preview-bar__badge--premium">
								<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
								<?php esc_html_e( 'Premium', 'flavor' ); ?>
							</span>
						<?php else : ?>
							<span class="mv-preview-bar__badge">
								<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
								<?php esc_html_e( 'Preview', 'flavor' ); ?>
							</span>
						<?php endif; ?>
					</p>
				</div>
				<div class="mv-preview-bar__actions">
					<select id="mv-preview-bar-select" class="mv-preview-bar__select">
						<?php foreach ( $theme_options as $value => $label ) :
							$option_label = $label;
							if ( in_array( $value, $gated_themes, true ) && ! $is_pro ) {
								$option_label .= ' (Premium)';
							}
						?>
							<option
								value="<?php echo esc_url( add_query_arg( 'create_theme', $value, $base_url ) ); ?>"
								<?php selected( $value, $preview_theme ); ?>
							>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( $dismiss_url ); ?>" class="mv-preview-bar__btn-cancel">
						<?php esc_html_e( 'Cancel', 'flavor' ); ?>
					</a>
					<?php if ( $is_premium ) : ?>
						<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" class="mv-preview-bar__btn-upgrade">
							<?php esc_html_e( 'Upgrade to Pro', 'flavor' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( $apply_url ); ?>" class="mv-preview-bar__btn-apply">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
							<?php esc_html_e( 'Apply Theme', 'flavor' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<script>
		(function() {
			var select = document.getElementById('mv-preview-bar-select');
			if (!select) return;

			// Get the card hash: preserve existing hash, or find the first card on the page.
			function getCardHash() {
				var existing = window.location.hash;
				if (existing && existing.indexOf('mv-creation') !== -1) {
					return existing;
				}
				var card = document.querySelector('[id^="mv-creation-"]');
				return card ? '#' + card.id : '';
			}

			select.addEventListener('change', function() {
				if (this.value) {
					window.location.href = this.value + getCardHash();
				}
			});
		})();
		</script>
		<?php
	}
}
