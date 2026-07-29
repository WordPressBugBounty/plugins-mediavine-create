<?php
namespace Mediavine\Create;

use Mediavine\Create\Helpers\Str;

/**
 * Prepares list-item rows (images, URLs, meta, Pinterest) for Create list cards.
 *
 * Extracted from Creations_Views::prep_creation_view() (CRE-129 step 4).
 */
class Creations_Views_List_Item_Preparer {

	/**
	 * Sort, filter, and enrich list items on a published creation.
	 *
	 * @param array  $published_creation Published card data with list_items.
	 * @param array  $atts               Shortcode attributes (layout is overwritten from published).
	 * @param string $pinterest_location Pinterest location setting (class slug or 'off').
	 * @return array
	 */
	public static function prepare( $published_creation, $atts, $pinterest_location ) {
		if (
			'list' !== $atts['type'] ||
			empty( $published_creation['list_items'] ) ||
			! is_array( $published_creation['list_items'] )
		) {
			return $published_creation;
		}

		// Force pinterest if not set to off.
		if ( 'off' !== $pinterest_location ) {
			$published_creation['pinterest_display'] = true;
		}

		$atts['layout'] = $published_creation['layout'];
		// Preserve the filter caller name from prep_creation_view for mv_create_image_sizes.
		$img_sizes = Creations_Views::get_all_image_sizes( 'prep_creation_view' );
		$photo_ratio    = Creations_Views::get_image_size();
		$has_ratio      = ( 'mv_create_no_ratio' !== $photo_ratio );

		// Order list items by position because we can't guarantee DB write order.
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

		$list_ads_enabled = \Mediavine\Settings::get_setting(
			Plugin::$settings_group . '_list_ads_enabled',
			Plugin_Checker::has_mv_ads() ? '1' : '0'
		);
		$published_creation['list_items_between_ads'] = $list_ads_enabled
			? \Mediavine\Settings::get_setting( Plugin::$settings_group . '_list_items_between_ads', 3 )
			: 0;

		global $post;
		foreach ( $published_creation['list_items'] as $key => &$item ) {
			if (
				! empty( $post ) &&
				'post' === $item['content_type'] &&
				(int) $item['relation_id'] === (int) $post->ID
			) {
				unset( $published_creation['list_items'][ $key ] );
				continue;
			}

			// Section dividers (explicit type or legacy text-with-no-media)
			// don't get image/url/button prep — promote the legacy heuristic
			// to an explicit content_type so downstream code (templates,
			// JSON-LD, numbering) handles it consistently.
			if ( Creations_Views::is_list_item_divider( $item ) ) {
				$item['content_type'] = 'divider';
				continue;
			}

			// Thumbnail url logic.
			$layout_image_sizes   = [
				'circles'  => 'mv_create_1x1',
				'grid'     => $has_ratio ? $photo_ratio : 'mv_create_16x9',
				'hero'     => $has_ratio ? $photo_ratio : 'mv_create_vert',
				'numbered' => $has_ratio ? $photo_ratio : 'mv_create_vert',
			];
			$thumbnail_image_size = 'mv_create_1x1';
			if ( array_key_exists( $atts['layout'], $layout_image_sizes ) ) {
				$thumbnail_image_size = $layout_image_sizes[ $atts['layout'] ];
			}

			// Generate thumbnail if it doesn't exist.
			// Skip synchronous image processing during REST API requests or in admin to avoid timeouts.
			if ( ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) && ! is_admin() ) {
				Images::check_image_size( $item['thumbnail_id'], $img_sizes );
			}
			$highest_res_image = Images::get_highest_available_image_size( $item['thumbnail_id'], $thumbnail_image_size );

			$item_alt_text = get_post_meta( $item['thumbnail_id'], '_wp_attachment_image_alt', true );
			if ( empty( $item_alt_text ) ) {
				/* translators: %s: list item title */
				$item_alt_text = sprintf( __( 'Image for %s', 'mediavine-create' ), $item['title'] );
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

			// Get permalink for all non-external items, including CPTs.
			// Products use their URL from the products table, not a permalink.
			if ( 'external' !== $item['content_type'] && 'product' !== $item['content_type'] ) {
				$item['url'] = get_the_permalink( $item['canonical_post_id'] );
			}

			// Provide button text.
			$item['btn_text'] = Creations_Views::resolve_list_item_button_text( $item );

			if ( 'card' === $item['content_type'] ) {
				$item = self::prepare_card_item( $item, $key, $published_creation );
				if ( null === $item ) {
					continue;
				}
			}

			$item = Creations_Views::create_list_item_extra( $item );
		}
		unset( $item );

		return $published_creation;
	}

	/**
	 * Enrich a list item that references another Create card.
	 *
	 * @param array $item                List item.
	 * @param int   $key                 Index in list_items.
	 * @param array $published_creation  Published creation (list_items may be unset).
	 * @return array|null Null when the item was removed as unassociated.
	 */
	private static function prepare_card_item( $item, $key, &$published_creation ) {
		// We don't want any unassociated cards.
		if ( empty( $item['canonical_post_id'] ) ) {
			unset( $published_creation['list_items'][ $key ] );
			return null;
		}

		$item['url']  = get_the_permalink( $item['canonical_post_id'] );
		$item_data    = \mv_create_get_creation( $item['relation_id'], true );
		$item['data'] = [];

		$item_meta = json_decode( $item['meta'] ?: '{}' );

		// Add meta types.
		if ( is_array( $item_meta ) ) {
			if ( in_array( 'prep_time', $item_meta, true ) && ! empty( $item_data->prep_time ) ) {
				$time_output = Creations_Views::prep_creation_time( $item_data->prep_time );
				if ( ! empty( $time_output['time'] ) ) {
					$item['data'][] = [ __( 'Prep Time', 'mediavine-create' ), $time_output['time'] ];
				}
			}
			if ( in_array( 'active_time', $item_meta, true ) && ! empty( $item_data->active_time ) ) {
				$time_output = Creations_Views::prep_creation_time( $item_data->active_time );
				if ( ! empty( $time_output['time'] ) ) {
					$item['data'][] = [ __( 'Active Time', 'mediavine-create' ), $time_output['time'] ];
				}
			}
			if ( in_array( 'additional_time', $item_meta, true ) && ! empty( $item_data->additional_time ) ) {
				$time_output = Creations_Views::prep_creation_time( $item_data->additional_time );
				if ( ! empty( $time_output['time'] ) ) {
					$item['data'][] = [ __( 'Additional Time', 'mediavine-create' ), $time_output['time'] ];
				}
			}
			if ( in_array( 'total_time', $item_meta, true ) && ! empty( $item_data->total_time ) ) {
				$time_output = Creations_Views::prep_creation_time( $item_data->total_time );
				if ( ! empty( $time_output['time'] ) ) {
					$item['data'][] = [ __( 'Total Time', 'mediavine-create' ), $time_output['time'] ];
				}
			}
			if ( in_array( 'yield', $item_meta, true ) && ! empty( $item_data->yield ) ) {
				$item['data'][] = [ __( 'Yield', 'mediavine-create' ), $item_data->yield ];
			}
			if ( in_array( 'category', $item_meta, true ) && ! empty( $item_data->category ) ) {
				$term           = \get_term( $item_data->category, 'category' );
				$item['data'][] = [ __( 'Category', 'mediavine-create' ), $term->name ];
			}
			// Recipes.
			if ( in_array( 'calories', $item_meta, true ) && ! empty( $item_data->nutrition ) ) {
				$item['data'][] = [ __( 'Calories', 'mediavine-create' ), $item_data->nutrition->calories ];
			}
			if ( in_array( 'cuisine', $item_meta, true ) && ! empty( $item_data->secondary_term ) ) {
				$term           = \get_term( $item_data->secondary_term, 'mv_cuisine' );
				$item['data'][] = [ __( 'Cuisine', 'mediavine-create' ), $term->name ];
			}
			// DIY.
			if ( in_array( 'project_type', $item_meta, true ) && ! empty( $item_data->secondary_term ) ) {
				$term           = \get_term( $item_data->secondary_term, 'mv_project_types' );
				$item['data'][] = [ __( 'Project Type', 'mediavine-create' ), $term->name ];
			}
			if ( in_array( 'cost', $item_meta, true ) && ! empty( $item_data->estimated_cost ) ) {
				$item['data'][] = [ __( 'Cost', 'mediavine-create' ), $item_data->estimated_cost ];
			}
			if ( in_array( 'difficulty', $item_meta, true ) && ! empty( $item_data->difficulty ) ) {
				$item['data'][] = [ __( 'Difficulty', 'mediavine-create' ), $item_data->difficulty ];
			}
		}

		// Add Pinterest.
		$item['pinterest'] = [];
		if ( ! empty( $item_data->pinterest_url ) ) {
			$item['pinterest']['url'] = $item_data->pinterest_url;
		} elseif ( ! empty( $item_data->canonical_post_id ) ) {
			$item['pinterest']['url'] = get_permalink( $item_data->canonical_post_id );
		} else {
			$item['pinterest']['url'] = $item['url'];
		}

		if ( ! empty( $item_data->pinterest_description ) ) {
			$item['pinterest']['description'] = Str::truncate( $item_data->pinterest_description, 500 );
		} else {
			$item['pinterest']['description'] = Str::truncate( wp_strip_all_tags( $item['description'] ), 500 );
		}

		if ( ! empty( $item_data->pinterest_img_id ) ) {
			$pinterest_img = wp_get_attachment_image_src( $item_data->pinterest_img_id, 'mv_creation_vert' );
		} else {
			$pinterest_img = wp_get_attachment_image_src( $item['thumbnail_id'], 'mv_creation_vert' );
		}

		// Have this fail (no Pin button) if no image is available.
		if ( ! empty( $pinterest_img[0] ) ) {
			$item['pinterest']['img'] = $pinterest_img[0];
		}

		return $item;
	}
}
