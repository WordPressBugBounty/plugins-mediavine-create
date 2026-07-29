<?php
namespace Mediavine\Create;

use Mediavine\Settings;

use Mediavine\Create\Helpers\Str;

/**
 * Handles all Create-specific hooks
 */
class Creations_Views_Hooks extends Creations_Views {

	/**
	 * Tracked priority for the video hook on mv_create_card_content.
	 *
	 * @var int|null
	 */
	private static $video_hook_priority = null;

	/**
	 * Tracked priority for the products hook on mv_create_card_content.
	 *
	 * @var int|null
	 */
	private static $products_hook_priority = null;

	/**
	 * Card/list style currently registered via register_style_hooks().
	 *
	 * @var string|null
	 */
	private static $registered_style = null;

	/**
	 * Card type ('card' or 'list') for the currently registered style hooks.
	 *
	 * @var string|null
	 */
	private static $registered_type = null;

	/**
	 * Remove video and products hooks at their currently tracked priorities.
	 *
	 * Theme hook functions call add_action which is additive. If a previous
	 * render adjusted video/products to a non-default priority, that old
	 * registration persists. This method cleans up stale hooks before the
	 * theme function re-registers at default priorities.
	 */
	private static function cleanup_adjustable_hooks() {
		$callback_video    = [ self::class, 'mv_create_video' ];
		$callback_products = [ self::class, 'mv_create_products' ];

		if ( null !== self::$video_hook_priority ) {
			remove_action( 'mv_create_card_content', $callback_video, self::$video_hook_priority );
			self::$video_hook_priority = null;
		}

		if ( null !== self::$products_hook_priority ) {
			remove_action( 'mv_create_card_content', $callback_products, self::$products_hook_priority );
			self::$products_hook_priority = null;
		}
	}

	/**
	 * Hook wiring for each card/list style.
	 *
	 * Each entry is [ $hook, $callback_method, $priority ] or
	 * [ $hook, $callback_method, $priority, $accepted_args ].
	 *
	 * @return array<string, array<string, array<int, array<int, mixed>>>>
	 */
	private static function get_style_hooks_registry() {
		// Shared layout used by centered, editorial, and modern (social @ 80).
		$centered_layout = [
			[ 'mv_create_card_before', 'mv_create_schema', 10 ],
			[ 'mv_create_card_header', 'mv_create_image', 10 ],
			[ 'mv_create_card_header', 'mv_create_pin_button', 20 ],
			[ 'mv_create_card_header', 'mv_create_title', 30 ],
			[ 'mv_create_card_header', 'mv_create_times', 40 ],
			[ 'mv_create_card_header', 'mv_create_description', 50 ],
			[ 'mv_create_card_header', 'mv_create_rating', 60 ],
			[ 'mv_create_card_header', 'mv_create_print_button', 70 ],
			[ 'mv_create_card_content', 'mv_create_ad_div', 10 ],
			[ 'mv_create_card_content', 'mv_create_supplies', 20 ],
			[ 'mv_create_card_content', 'mv_create_instructions', 30 ],
			[ 'mv_create_card_content', 'mv_create_notes', 40 ],
			[ 'mv_create_card_content', 'mv_create_video', 50 ],
			[ 'mv_create_card_video_script', 'mv_create_video_script', 10 ],
			[ 'mv_create_card_content', 'mv_create_products', 60 ],
			[ 'mv_create_card_content', 'mv_create_nutrition', 70 ],
			[ 'mv_create_card_content', 'mv_create_social', 80 ],
			[ 'mv_create_card_social_icon', 'mv_create_social_icon', 10 ],
			[ 'mv_create_card_footer', 'mv_create_footer', 10 ],
		];

		// centered-dark matches centered except social priority 90.
		$centered_dark_layout = [];
		foreach ( $centered_layout as $entry ) {
			if ( 'mv_create_social' === $entry[1] ) {
				$centered_dark_layout[] = [ 'mv_create_card_content', 'mv_create_social', 90 ];
				continue;
			}
			$centered_dark_layout[] = $entry;
		}

		return [
			'card' => [
				'square'        => [
					[ 'mv_create_card_before', 'mv_create_schema', 10 ],
					[ 'mv_create_card_header', 'mv_create_title', 10 ],
					[ 'mv_create_card_header', 'mv_create_pin_button', 20 ],
					[ 'mv_create_card_header', 'mv_create_image_container', 30 ],
					[ 'mv_create_card_image_container', 'mv_create_image', 10 ],
					[ 'mv_create_card_image_container', 'mv_create_rating', 20 ],
					[ 'mv_create_card_image_container', 'mv_create_print_button', 30 ],
					[ 'mv_create_card_header', 'mv_create_description', 40 ],
					[ 'mv_create_card_content', 'mv_create_times', 10 ],
					[ 'mv_create_card_content', 'mv_create_ad_div', 20 ],
					[ 'mv_create_card_content', 'mv_create_supplies', 30 ],
					[ 'mv_create_card_content', 'mv_create_instructions', 40 ],
					[ 'mv_create_card_content', 'mv_create_notes', 50 ],
					[ 'mv_create_card_content', 'mv_create_video', 60 ],
					[ 'mv_create_card_content', 'mv_create_products', 70 ],
					[ 'mv_create_card_content', 'mv_create_nutrition', 80 ],
					[ 'mv_create_card_content', 'mv_create_social', 90 ],
					[ 'mv_create_card_social_icon', 'mv_create_social_icon', 10 ],
					[ 'mv_create_card_footer', 'mv_create_footer', 10 ],
				],
				'centered'      => $centered_layout,
				'centered-dark' => $centered_dark_layout,
				// mv_create_rating is included in the print button template for big-image.
				'big-image'     => [
					[ 'mv_create_card_before', 'mv_create_schema', 10 ],
					[ 'mv_create_card_header', 'mv_create_image', 10 ],
					[ 'mv_create_card_header', 'mv_create_pin_button', 20 ],
					[ 'mv_create_card_header', 'mv_create_title', 30 ],
					[ 'mv_create_card_content', 'mv_create_times', 10 ],
					[ 'mv_create_card_content', 'mv_create_description', 20 ],
					[ 'mv_create_card_content', 'mv_create_print_button', 30 ],
					[ 'mv_create_card_content', 'mv_create_ad_div', 40 ],
					[ 'mv_create_card_content', 'mv_create_supplies', 50 ],
					[ 'mv_create_card_content', 'mv_create_instructions', 60 ],
					[ 'mv_create_card_content', 'mv_create_notes', 70 ],
					[ 'mv_create_card_content', 'mv_create_video', 80 ],
					[ 'mv_create_card_video_script', 'mv_create_video_script', 10 ],
					[ 'mv_create_card_content', 'mv_create_products', 90 ],
					[ 'mv_create_card_content', 'mv_create_nutrition', 100 ],
					[ 'mv_create_card_content', 'mv_create_social', 110 ],
					[ 'mv_create_card_social_icon', 'mv_create_social_icon', 10 ],
					[ 'mv_create_card_footer', 'mv_create_footer', 10 ],
				],
				'editorial'     => $centered_layout,
				'modern'        => $centered_layout,
			],
			'list' => [
				'square' => [
					[ 'mv_create_card_before', 'mv_create_schema', 10 ],
					[ 'mv_create_card_header', 'mv_create_title', 10 ],
					[ 'mv_create_card_header', 'mv_create_description', 20 ],
					[ 'mv_create_card_header', 'mv_create_list_affiliate_message', 30 ],
					[ 'mv_create_card_content', 'mv_create_list', 10 ],
					[ 'mv_create_list_after_single', 'mv_create_list_ads', 10, 3 ],
					[ 'mv_create_list_after_row', 'mv_create_list_ads_grid', 10, 3 ],
				],
			],
		];
	}

	/**
	 * Resolve a requested style to one present in the registry.
	 *
	 * Unknown card styles (e.g. "dark") and unknown list styles fall back to square.
	 *
	 * @param string $style Card style slug.
	 * @param string $type  'card' or 'list'.
	 * @return string Resolved style slug.
	 */
	public static function resolve_style_hooks( $style, $type = 'card' ) {
		$registry = self::get_style_hooks_registry();
		$type     = ( 'list' === $type ) ? 'list' : 'card';

		if ( isset( $registry[ $type ][ $style ] ) ) {
			return $style;
		}

		return 'square';
	}

	/**
	 * Register WordPress actions for a card/list style from the registry.
	 *
	 * @param string $style Card style slug.
	 * @param string $type  'card' or 'list'.
	 * @return string The resolved style that was registered.
	 */
	public static function register_style_hooks( $style, $type = 'card' ) {
		$type  = ( 'list' === $type ) ? 'list' : 'card';
		$style = self::resolve_style_hooks( $style, $type );
		$hooks = self::get_style_hooks_registry()[ $type ][ $style ];

		if ( 'card' === $type ) {
			self::cleanup_adjustable_hooks();
		}

		foreach ( $hooks as $entry ) {
			$hook          = $entry[0];
			$callback      = [ self::class, $entry[1] ];
			$priority      = $entry[2];
			$accepted_args = isset( $entry[3] ) ? (int) $entry[3] : 1;

			if ( 'mv_create_video' === $entry[1] ) {
				self::$video_hook_priority = $priority;
			}
			if ( 'mv_create_products' === $entry[1] ) {
				self::$products_hook_priority = $priority;
			}

			add_action( $hook, $callback, $priority, $accepted_args );
		}

		self::$registered_style = $style;
		self::$registered_type  = $type;

		return $style;
	}

	/**
	 * Unregister WordPress actions previously registered for a card/list style.
	 *
	 * Video and products use tracked priorities so adjusted positions are cleared.
	 *
	 * @param string|null $style Card style slug, or null to use the last registered style.
	 * @param string|null $type  'card' or 'list', or null to use the last registered type.
	 * @return void
	 */
	public static function unregister_style_hooks( $style = null, $type = null ) {
		$type  = null !== $type ? ( ( 'list' === $type ) ? 'list' : 'card' ) : self::$registered_type;
		$style = null !== $style ? self::resolve_style_hooks( $style, $type ? $type : 'card' ) : self::$registered_style;

		if ( null === $style || null === $type ) {
			self::cleanup_adjustable_hooks();
			return;
		}

		$hooks = self::get_style_hooks_registry()[ $type ][ $style ];

		foreach ( $hooks as $entry ) {
			$hook     = $entry[0];
			$method   = $entry[1];
			$callback = [ self::class, $method ];
			$priority = $entry[2];

			// Prefer tracked priorities when video/products were moved mid-render.
			if ( 'mv_create_video' === $method && null !== self::$video_hook_priority ) {
				$priority                  = self::$video_hook_priority;
				self::$video_hook_priority = null;
			}
			if ( 'mv_create_products' === $method && null !== self::$products_hook_priority ) {
				$priority                     = self::$products_hook_priority;
				self::$products_hook_priority = null;
			}

			remove_action( $hook, $callback, $priority );
		}

		self::$registered_style = null;
		self::$registered_type  = null;
	}

	public static function mv_create_schema( $args ) {
		$schema_display = true;

		// Check if schema should be output here or in wp_head
		if ( Settings::get_setting( 'mv_create_schema_in_head', true ) ) {
			$schema_display = false;
		}

		// Check isset so old cards still display schema,
		// and check empty because of some PHP interpreting `! $var` as strict with 0 strings
		if (
			isset( $args['creation']['schema_display'] ) &&
			empty( $args['creation']['schema_display'] )
		) {
			$schema_display = false;
		}

		// We don't want to output JSON-LD for display in non-canonical posts.
		if ( get_current_post_id() !== (int) $args['creation']['canonical_post_id'] ) {
			$schema_display = false;
		}

		// We don't want to output the JSON-LD to RSS feeds
		if ( is_feed() ) {
			$schema_display = false;
		}

		if ( 'list' === $args['creation']['type'] ) {
			$schema_display = self::check_list_for_schema_items( $args, $schema_display );
		}

		if ( empty( $args['print'] ) && $schema_display && ! empty( $args['creation']['json_ld'] ) ) {
			$json_ld_output = '<script type="application/ld+json">' . $args['creation']['json_ld'] . '</script>';
			$allowed_tags   = [ 'script' => [ 'type' => true ] ];

			echo wp_kses( $json_ld_output, $allowed_tags );
		}
	}

	/**
	 * Determine whether or not a list should display schema.
	 *
	 * Checks the items to see if any should be included in schema. If not, don't display.
	 * This will prevent old schema from displaying when items that _should_ display have
	 * been removed from the list, preventing the JSON+LD generation from overwriting the value
	 * in the database.
	 *
	 * @param array   $args the whole $args variable
	 * @param boolean $schema_display
	 * @return boolean $should_schema_display
	 */
	public static function check_list_for_schema_items( $args, $schema_display = true ) {
		// If something has already determined that the schema shouldn't display, don't display it.
		if ( ! $schema_display ) {
			return $schema_display;
		}
		$list_items            = $args['creation']['list_items'];
		$should_schema_display = array_filter(
			$list_items,
			function( $item ) use ( $args ) {
				// Section dividers are never schema items
				if ( self::is_list_item_divider( $item ) ) {
					return false;
				}

				// Text items are schema-eligible when the JSON-LD builder can give
				// them a fragment URL: the list's canonical post plus the item id
				if ( 'text' === $item['content_type'] ) {
					return ! empty( $args['creation']['canonical_post_id'] ) && ! empty( $item['id'] );
				}

				$link = null;
				if ( $item['url'] ) {
					$link = $item['url'];
				} elseif ( ! empty( $item['canonical_post_id'] ) ) {
					$link = get_the_permalink( $item['canonical_post_id'] );
				}
				// if there is no link or it's invalid, don't include it
				if ( ! $link || ! wp_http_validate_url( $link ) ) {
					return false;
				}

				// Don't add external URLs to JSON-LD
				$permalink_host = wp_parse_url(  $link );
				$current_host   = wp_parse_url(  home_url() );
				// If the link is a subdomain, we want to keep it in the JSON-LD
				// If the link is neither a subdomain nor the primary domain, skip it
				if ( ! Str::is_same_host_or_subdomain( $permalink_host['host'] ?? '', $current_host['host'] ?? '' ) ) {
					return false;
				}

				return true;
			}
		);
		return ! empty( $should_schema_display );
	}

	public static function mv_create_title( $args ) {
		self::$views->the_view( 'shortcode-mv-create-title', $args );
	}

	public static function mv_create_pin_button( $args ) {
		// Build Pinterest specific args
		if (
			isset( $args['creation']['pinterest_img'] ) &&
			isset( $args['creation']['pinterest_url'] ) &&
			isset( $args['creation']['pinterest_description'] )
		) {
			$args['pinterest'] = [
				'img'         => $args['creation']['pinterest_img'],
				'url'         => $args['creation']['pinterest_url'],
				'description' => Str::truncate( wp_strip_all_tags(  $args['creation']['pinterest_description'] ), 500 ),
			];

			self::$views->the_view( 'shortcode-mv-create-pin-button', $args );
		}
	}

	public static function mv_create_image_container( $args ) {
		self::$views->the_view( 'shortcode-mv-create-image-container', $args );
	}

	public static function mv_create_image( $args ) {
		self::$views->the_view( 'shortcode-mv-create-image', $args );
	}

	public static function mv_create_rating( $args ) {
		self::$views->the_view( 'shortcode-mv-create-rating', $args );
	}

	public static function mv_create_print_button( $args ) {
		self::$views->the_view( 'shortcode-mv-create-print-button', $args );
	}

	public static function mv_create_description( $args ) {
		self::$views->the_view( 'shortcode-mv-create-description', $args );
	}

	public static function mv_create_list_affiliate_message( $args ) {
		self::$views->the_view( 'shortcode-mv-create-list-affiliate-message', $args );
	}

	public static function mv_create_times( $args ) {
		self::$views->the_view( 'shortcode-mv-create-times', $args );
	}

	/**
	 * Displays div for Mediavine ads if Mediavine Control Panel is installed
	 * @param array $args Array of arguments
	 *
	 * @return void
	 */
	public static function mv_create_ad_div( $args ) {
		if ( Plugin_Checker::has_mv_ads() ) {
			$attributes = null;
			if ( 'recipe' !== $args['type'] ) {
				$attributes = ' data-disable-chicory="1"';
			}

			echo wp_kses_post( '<div class="mv-create-target mv-create-primary-unit"><div class="mv_slot_target" data-slot="recipe"' . $attributes . '></div></div>' );
		}
	}

	public static function mv_create_supplies( $args ) {
		self::$views->the_view( 'shortcode-mv-create-supplies', $args );
	}

	/**
	 * Output card instructions.
	 *
	 * @param array $args Array of arguments
	 */
	public static function mv_create_instructions( $args ) {
		self::$views->the_view( 'shortcode-mv-create-instructions', $args );
	}

	public static function mv_create_notes( $args ) {
		self::$views->the_view( 'shortcode-mv-create-notes', $args );
	}

	public static function mv_create_video( $args ) {
		self::$views->the_view( 'shortcode-mv-create-video', $args, '', true );
	}

	public static function mv_create_products( $args ) {
		self::$views->the_view( 'shortcode-mv-create-products', $args );
	}

	public static function mv_create_nutrition( $args ) {
		self::$views->the_view( 'shortcode-mv-create-nutrition', $args );
	}

	public static function mv_create_social( $args ) {
		self::$views->the_view( 'shortcode-mv-create-social', $args );
	}

	public static function mv_create_social_icon( $args ) {
		// Allowlist before concatenating into an include path (CRE-212).
		$social_icon = Creations_Views::sanitize_social_icon(
			isset( $args['creation']['social_icon'] ) ? $args['creation']['social_icon'] : ''
		);
		if ( empty( $social_icon ) ) {
			return;
		}

		$allowed_tags    = [
			'a' => [
				'class'  => true,
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			],
		];
		$social_link_tag = Creations_Views::get_social_link_tag( $social_icon );
		if ( ! empty( $social_link_tag ) ) {
			echo wp_kses( $social_link_tag, $allowed_tags );
		}
		self::$views->the_view( 'shortcode-mv-create-social-icon-' . $social_icon, $args );
		if ( ! empty( $social_link_tag ) ) {
			echo '</a>';
		}
	}

	public static function mv_create_list( $args ) {
		// Allowlist before concatenating into an include path (CRE-212).
		$layout = Creations_Views::sanitize_list_layout(
			isset( $args['creation']['layout'] ) ? $args['creation']['layout'] : ''
		);
		if ( empty( $layout ) ) {
			return;
		}
		self::$views->the_view( 'shortcode-mv-create-list-' . $layout, $args );
	}

	/**
	 * Insert ads into `Grid` style lists.
	 *
	 * Because grid-styled lists operate on a different counter, we need
	 * a separate function to determine ad positions.
	 *
	 * This function takes the row count (instead of the index count) and
	 * determines the ad insertion based on number of rows between ads
	 * (as opposed to number of list items between ads).
	 *
	 * @param array $args array of arguments
	 * @param int   $row the row of list items we're on
	 * @param int   $count the total number of list items
	 * @return void
	 */
	public static function mv_create_list_ads_grid( $args, $row, $count ) {
		$ads_enabled = apply_filters(
			'mv_create_list_ads_enabled',
			(bool) \Mediavine\Settings::get_setting( Plugin::$settings_group . '_list_ads_enabled', Plugin_Checker::has_mv_ads() ? '1' : '0' )
		);

		if (
			$ads_enabled &&
			! empty( $args['creation']['list_items_between_ads'] ) &&
			! $args['print'] &&
			( 1 !== $row ) &&
			( 0 === $row % $args['creation']['list_items_between_ads'] ) &&
			( $row * 2 ) < $count
		) {
			$should_insert = apply_filters( 'mv_create_should_insert_list_ad', true, $args, $row, $count );

			if ( $should_insert ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_list_ad_html returns fixed MV markup or admin HTML sanitized via sanitize_custom_list_ad_html().
				echo self::get_list_ad_html( $args, $row, $count );
			}
		}
	}

	/**
	 * Insert ads into lists.
	 *
	 * @param array $args array of arguments
	 * @param int   $i the index of the list item
	 * @param int   $count the total number of list items
	 * @return void
	 */
	public static function mv_create_list_ads( $args, $i, $count ) {
		$ads_enabled = apply_filters(
			'mv_create_list_ads_enabled',
			(bool) \Mediavine\Settings::get_setting( Plugin::$settings_group . '_list_ads_enabled', Plugin_Checker::has_mv_ads() ? '1' : '0' )
		);

		if (
			$ads_enabled &&
			! empty( $args['creation']['list_items_between_ads'] ) &&
			! $args['print'] &&
			( 0 === ( $i + 1 ) % $args['creation']['list_items_between_ads'] ) &&
			( $i + 1 ) !== $count
		) {
			$should_insert = apply_filters( 'mv_create_should_insert_list_ad', true, $args, $i, $count );

			if ( $should_insert ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_list_ad_html returns fixed MV markup or admin HTML sanitized via sanitize_custom_list_ad_html().
				echo self::get_list_ad_html( $args, $i, $count );
			}
		}
	}

	/**
	 * Get the HTML for a list ad slot.
	 *
	 * Uses the custom ad HTML setting for non-Mediavine publishers,
	 * or the default Mediavine slot markup. Filterable via `mv_create_list_ad_html`.
	 *
	 * @param array $args  Card arguments.
	 * @param int   $index Item index or row number.
	 * @param int   $count Total item count.
	 * @return string Ad slot HTML.
	 */
	private static function get_list_ad_html( $args, $index, $count ) {
		if ( Plugin_Checker::has_mv_ads() ) {
			$default_html = '<div class="mv-list-adwrap"><div class="mv_slot_target" data-slot="content"></div></div>';
		} else {
			// For non-MV publishers, use their custom ad HTML (or nothing)
			$custom_html = trim( \Mediavine\Settings::get_setting( Plugin::$settings_group . '_list_ad_custom_html', '' ) );
			$default_html = ! empty( $custom_html )
				? '<div class="mv-list-adwrap">' . self::sanitize_custom_list_ad_html( $custom_html ) . '</div>'
				: '';
		}

		/**
		 * Filter the ad slot HTML inserted between list items.
		 *
		 * @param string $html  The ad slot HTML.
		 * @param array  $args  Card template arguments.
		 * @param int    $index The current item index (or row number for grid layouts).
		 * @param int    $count The total number of list items.
		 */
		return apply_filters( 'mv_create_list_ad_html', $default_html, $args, $index, $count );
	}

	/**
	 * Sanitize custom ad slot HTML.
	 *
	 * Allows only div and span elements with class, id, and data-* attributes.
	 * All other tags are unwrapped (children preserved) and all other attributes
	 * are stripped. Script tags and event handlers are removed entirely.
	 *
	 * @param string $html Raw HTML input.
	 * @return string Sanitized HTML safe for output.
	 */
	private static function sanitize_custom_list_ad_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		self::sanitize_dom_node( $dom, $body );

		$result = '';
		foreach ( $body->childNodes as $child ) {
			$result .= $dom->saveHTML( $child );
		}

		return trim( $result );
	}

	/**
	 * Recursively sanitize DOM node children in-place.
	 *
	 * @param \DOMDocument $dom    The owner document.
	 * @param \DOMNode     $parent Parent node whose children will be sanitized.
	 */
	private static function sanitize_dom_node( \DOMDocument $dom, \DOMNode $parent ): void {
		$allowed_tags = [ 'div', 'span' ];

		// Snapshot children since we modify the list while iterating
		$children = iterator_to_array( $parent->childNodes );

		foreach ( $children as $child ) {
			if ( ! ( $child instanceof \DOMElement ) ) {
				// Remove comments, processing instructions, etc.; keep text nodes
				if ( ! ( $child instanceof \DOMText ) ) {
					$parent->removeChild( $child );
				}
				continue;
			}

			// Executable tags: strip entirely including all children
			$strip_entirely = [ 'script', 'style', 'iframe', 'form', 'object', 'embed' ];
			if ( in_array( strtolower( $child->tagName ), $strip_entirely, true ) ) {
				$parent->removeChild( $child );
				continue;
			}

			if ( ! in_array( strtolower( $child->tagName ), $allowed_tags, true ) ) {
				// Unwrap: sanitize children first, then move them up
				self::sanitize_dom_node( $dom, $child );
				$grandchildren = iterator_to_array( $child->childNodes );
				foreach ( $grandchildren as $gc ) {
					$parent->insertBefore( $gc, $child );
				}
				$parent->removeChild( $child );
				continue;
			}

			// Strip disallowed attributes
			$remove_attrs = [];
			foreach ( $child->attributes as $attr ) {
				$name    = strtolower( $attr->nodeName );
				$allowed = ( 'class' === $name )
					|| ( 'id' === $name )
					|| ( 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) );
				if ( ! $allowed ) {
					$remove_attrs[] = $attr->nodeName;
				}
			}
			foreach ( $remove_attrs as $attr_name ) {
				$child->removeAttribute( $attr_name );
			}

			// Sanitize class values
			if ( $child->hasAttribute( 'class' ) ) {
				$classes = preg_split( '/\s+/', trim( $child->getAttribute( 'class' ) ) );
				$classes = array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) );
				if ( $classes ) {
					$child->setAttribute( 'class', implode( ' ', $classes ) );
				} else {
					$child->removeAttribute( 'class' );
				}
			}

			// Sanitize id value
			if ( $child->hasAttribute( 'id' ) ) {
				$sanitized_id = sanitize_html_class( $child->getAttribute( 'id' ) );
				if ( $sanitized_id ) {
					$child->setAttribute( 'id', $sanitized_id );
				} else {
					$child->removeAttribute( 'id' );
				}
			}

			// Escape data-* attribute values
			$data_attrs = [];
			foreach ( $child->attributes as $attr ) {
				if ( 0 === strpos( $attr->nodeName, 'data-' ) ) {
					$data_attrs[ $attr->nodeName ] = $attr->nodeValue;
				}
			}
			foreach ( $data_attrs as $attr_name => $attr_value ) {
				$child->setAttribute( $attr_name, esc_attr( $attr_value ) );
			}

			self::sanitize_dom_node( $dom, $child );
		}
	}

	public static function mv_create_footer( $args ) {
		self::$views->the_view( 'shortcode-mv-create-footer', $args );
	}

	/**
	 * Get the hook priority for video based on position setting and theme style.
	 *
	 * @param string $position Position setting value.
	 * @param string $style Card theme style.
	 * @return int|null Hook priority, or null to keep the default.
	 */
	public static function get_video_priority( $position, $style ) {
		$theme_priorities = [
			'square'        => [
				'supplies'     => 30,
				'instructions' => 40,
				'notes'        => 50,
			],
			'centered'      => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
			],
			'centered-dark' => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
			],
			'big-image'     => [
				'supplies'     => 50,
				'instructions' => 60,
				'notes'        => 70,
			],
			'editorial'     => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
			],
			'modern'        => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
			],
		];

		$priorities = $theme_priorities[ $style ] ?? $theme_priorities['square'];

		switch ( $position ) {
			case 'above_supplies':
				return $priorities['supplies'] - 5;
			case 'above_instructions':
				return $priorities['instructions'] - 5;
			case 'below_instructions':
				return $priorities['instructions'] + 5;
			case 'below_notes':
				return $priorities['notes'] + 5;
			default:
				return null;
		}
	}

	/**
	 * Adjust video hook priority based on card and global settings.
	 *
	 * @param array $args Card rendering arguments.
	 */
	public static function adjust_video_priority( $args ) {
		$position = null;
		if ( ! empty( $args['creation']['video_position'] ) ) {
			$position = $args['creation']['video_position'];
		} else {
			$position = \Mediavine\Settings::get_setting( 'mv_create_video_position', '' );
		}

		if ( empty( $position ) ) {
			return;
		}

		$style    = $args['style'] ?? 'square';
		$priority = self::get_video_priority( $position, $style );

		if ( null === $priority ) {
			return;
		}

		// Remove video hook from current tracked priority
		if ( null !== self::$video_hook_priority ) {
			remove_action( 'mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_video' ], self::$video_hook_priority );
		}

		// Re-add at the correct priority and track it
		self::$video_hook_priority = $priority;
		add_action( 'mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_video' ], $priority );
	}

	/**
	 * Get the hook priority for products based on position setting and theme style.
	 *
	 * @param string $position Position setting value.
	 * @param string $style Card theme style.
	 * @return int Hook priority.
	 */
	public static function get_products_priority( $position, $style ) {
		// Define base priorities for each theme's sections
		$theme_priorities = [
			'square'       => [
				'supplies'     => 30,
				'instructions' => 40,
				'notes'        => 50,
				'video'        => 60,
			],
			'centered'     => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
				'video'        => 50,
			],
			'centered-dark' => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
				'video'        => 50,
			],
			'big-image'    => [
				'supplies'     => 50,
				'instructions' => 60,
				'notes'        => 70,
				'video'        => 80,
			],
			'editorial'    => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
				'video'        => 50,
			],
			'modern'       => [
				'supplies'     => 20,
				'instructions' => 30,
				'notes'        => 40,
				'video'        => 50,
			],
		];

		// Default to square theme priorities if style not found
		$priorities = $theme_priorities[ $style ] ?? $theme_priorities['square'];

		// Calculate products priority based on position
		switch ( $position ) {
			case 'above_supplies':
				return $priorities['supplies'] - 5;
			case 'above_instructions':
				return $priorities['instructions'] - 5;
			case 'below_instructions':
				return $priorities['instructions'] + 5;
			case 'below_notes':
				return $priorities['notes'] + 5;
			case 'after_video':
			default:
				// Use the actual adjusted video priority if available, otherwise fall back to theme default.
				// Use +1 so products always renders immediately after video regardless of nearby sections.
				$video_priority = null !== self::$video_hook_priority ? self::$video_hook_priority : $priorities['video'];
				return $video_priority + 1;
		}
	}

	/**
	 * Adjust products hook priority based on card and global settings.
	 *
	 * @param array $args Card rendering arguments.
	 */
	public static function adjust_products_priority( $args ) {
		// Get position from card override or global setting
		$position = null;
		if ( ! empty( $args['creation']['products_position'] ) ) {
			$position = $args['creation']['products_position'];
		} else {
			$position = \Mediavine\Settings::get_setting( 'mv_create_products_position', 'after_video' );
		}

		// Get the card style
		$style = $args['style'] ?? 'square';

		// Get the correct priority for this position and style
		$priority = self::get_products_priority( $position, $style );

		// Remove products hook from current tracked priority
		if ( null !== self::$products_hook_priority ) {
			remove_action( 'mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_products' ], self::$products_hook_priority );
		}

		// Re-add at the correct priority and track it
		self::$products_hook_priority = $priority;
		add_action( 'mv_create_card_content', [ 'Mediavine\Create\Creations_Views_Hooks', 'mv_create_products' ], $priority );
	}
}
