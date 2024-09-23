<?php
namespace Mediavine\Create;

use Mediavine\Models;
use Mediavine\MV_DBI;
use Mediavine\Permissions;
use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Str;
use WP_REST_Server;

class Images {

	const DB_VERSION = '0.1.0';

	public $api_route = 'mv-images';

	public $api_version = 'v1';

	public static $api_services = null;

	public static $models = null;

	public $images_table = 'mv_images';

	public $images_api = null;

	public $images_models = null;

	public $images_views = null;

	/**
	 * @var Queue
	 */
	public static $image_queue = null;

	public static $image_sizes = null;

	/**
	 * Get size information for all currently-registered image sizes.
	 *
	 * @param array $image_sizes Array of image sizes
	 *
	 * @return array $sizes Data for all currently-registered image sizes.
	 * @uses   get_intermediate_image_sizes()
	 * @global $_wp_additional_image_sizes
	 */
	public static function get_image_sizes( $image_sizes = null ) {
		global $_wp_additional_image_sizes;

		$sizes = [];
		if ( empty( $image_sizes ) ) {
			$image_sizes = get_intermediate_image_sizes();
		}

		foreach ( $image_sizes as $_size ) {
			if ( in_array( $_size, [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ], true ) ) {
				$sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
				$sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
				$sizes[ $_size ]['crop']   = (bool) get_option( "{$_size}_crop" );
			} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
				$sizes[ $_size ] = [
					'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
					'height' => $_wp_additional_image_sizes[ $_size ]['height'],
					'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
				];
			}
		}

		return $sizes;
	}

	/**
	 * If we have functions that are needed, then load them
	 */
	public static function load_missing_wp_functions() {
		// if used as part of a queue, maybe require_once would be better?
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			include( ABSPATH . 'wp-admin/includes/image.php' );
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			include( ABSPATH . 'wp-includes/pluggable.php' );
		}
	}


	/**
	 * Defer image size generation
	 *
	 * @param int $image_id Attachment image ID
	 * @param array $img_sizes Array of Created-supported image sizes. @see Creations_Views::add_images_to_creation()
	 *
	 * @return bool|void
	 */
	public static function generate_intermediate_sizes_deferred( $image_id, $img_sizes ) {
		self::load_missing_wp_functions();

		// this can happen if a card is published without an image
		if ( 0 === $image_id ) {
			return false;
		}

		$original_attach_data = wp_get_attachment_metadata( $image_id );
		if ( ! isset( $original_attach_data['sizes'] ) ) {
			return false;
		}

		if ( in_array( 'mv_create_1x1', array_keys( $original_attach_data['sizes'] ), true ) ) {
			return false;
		}

		$has_queued = self::$image_queue->dump();
		$ids        = wp_list_pluck( $has_queued, 'id' ); // wp_list_pluck can be intensive–perhaps array_filter could be used instead?
		if ( in_array( $image_id, $ids, true ) ) {
			return false;
		}

		self::$image_queue->push(
			[
				'id'    => $image_id,
				'sizes' => $img_sizes,
				'meta'  => $original_attach_data,
			]
		);
	}

	/**
	 * Generates all image sizes of an image uploaded to media
	 *
	 * Image sizes can be filtered with mv_intermediate_image_sizes_advanced
	 *
	 * @param int|string $image_id Attachment ID of the thumbnail
	 * @param array  List of image sizes
	 * @param array Attachment meta-data
	 *
	 * @return array|bool|int      Generated attachment metadata including new sizes
	 */
	public static function generate_intermediate_sizes( $image_id, array $img_sizes = [], array $original_attach_data = [] ) {
		self::load_missing_wp_functions();

		// $original_attach_data could be empty if generate_intermediate_sizes is called outside of a queue
		// check and populate accordingly
		if ( empty( $original_attach_data ) ) {
			$original_attach_data = wp_get_attachment_metadata( $image_id );
		}

		/**
		 * Filters to return early from generate_intermediate_sizes
		 *
		 * We have found some servers have issues with the core `wp_generate_attachment_metadata` function
		 *
		 * @param bool Default 'false'. Return 'true' to return early
		 *
		 * @return bool
		 */
		$return_early = apply_filters( 'mv_generate_intermediate_sizes_return_early', false );
		if ( $return_early ) {
			return $original_attach_data;
		}

		/**
		 * TODO: add Sentry reporting
		 */
		if ( 'array' !== gettype( $original_attach_data ) ) {
			return false;
		}

		// Filter out currently existing sizes
		if ( ! empty( $original_attach_data['sizes'] ) ) {
			foreach ( $img_sizes as $img_size => $img_meta ) {
				if ( ! empty( $original_attach_data['sizes'][ $img_size ] ) ) {
					unset( $img_sizes[ $img_size ] );
				}
			}
		}

		self::get_mv_intermediate_image_sizes( $img_sizes, $image_id );

		$attached_file = get_attached_file( $image_id );

		// No file found, so we will just return the current data
		if ( ! empty( $attached_file ) && 'string' !== gettype( $attached_file ) ) {
			return $original_attach_data;
		}

		$new_attach_data = wp_generate_attachment_metadata( $image_id, $attached_file );

		// Merge to original attach data so we don't lose other image sizes or metadata
		if ( ! empty( $new_attach_data['sizes'] ) ) {
			if ( ! empty( $original_attach_data['sizes'] ) ) {
				$original_attach_data['sizes'] = array_merge( $original_attach_data['sizes'], $new_attach_data['sizes'] );
			} else {
				$original_attach_data['sizes'] = $new_attach_data['sizes'];
			}
		}

		wp_update_attachment_metadata( $image_id, $original_attach_data );
	}

	/**
	 * Get the Create image sizes
	 *
	 * @param array $img_sizes
	 * @param int $image_id
	 */
	public static function get_mv_intermediate_image_sizes( $img_sizes, $image_id ) {

		self::$image_sizes = apply_filters( 'mv_intermediate_image_sizes_advanced', $img_sizes, $image_id );

		add_filter(
			'intermediate_image_sizes_advanced',
			[ 'Mediavine\Create\Images', 'set_intermediate_image_sizes_for_generation' ],
			556
		);
	}

	/**
	 * Set Create-specific image sizes for generation
	 *
	 * @param array $new_sizes Associative array of image sizes to be created.
	 *
	 * @return array
	 */
	public static function set_intermediate_image_sizes_for_generation( $new_sizes ) {
		// Pull attachment meta-data and look for "generated" flag
		$sizes_to_generate = self::$image_sizes;

		if ( ! empty( $sizes_to_generate ) ) {
			return $sizes_to_generate;
		} else {
			return $new_sizes;
		}
	}

	/**
	 * Prepare image metadata
	 *
	 * @param array $params
	 *
	 * @return array with additional values
	 */
	public static function prep_image( $params ) {
		if ( ! empty( $params['object_id'] ) ) {
			if ( empty( $params['image_size'] ) ) {
				$params['image_size'] = 'full';
			}

			$image_meta = wp_get_attachment_metadata( $params['object_id'] );
			$image_data = wp_get_attachment_image_src( $params['object_id'], $params['image_size'] );
			$image_full = wp_get_attachment_image_src( $params['object_id'], 'full' );

			$params['image_url']           = $image_data ? $image_data[0] : null;
			$params['image_url_full_size'] = $image_full ? $image_full[0] : null;
			$params['image_srcset']        = ''; // wpdb:insert won't insert NULL
			$params['image_srcset_sizes']  = ''; // wpdb:insert won't insert NULL

			if ( is_array( $image_meta ) ) {
				$size_array   = [ $image_data[1], $image_data[2] ];
				$srcset       = wp_calculate_image_srcset( $size_array, $image_data[0], $image_meta, $params['object_id'] );
				$srcset_sizes = wp_calculate_image_sizes( $size_array, $image_data[0], $image_meta, $params['object_id'] );

				if ( $srcset && $srcset_sizes ) {
					$params['image_srcset']       = $srcset;
					$params['image_srcset_sizes'] = $srcset_sizes;
				}
			}
		}

		return $params;
	}

	/**
	 * Returns HTML image with srcset data
	 *
	 * @param array|string $image Image array with 'image_url', 'image_srcset'
	 *                      (optional), and 'image_srcset_sizes' (optional).
	 *                      String also accepted with just image URL.
	 * @param string $image_alt_text Image alt text
	 *
	 * @return string HTML <img> tag
	 */
	public static function mv_image_tag( $image, $image_meta, $image_alt_text = null ) {
		$img_tag    = null;
		$img_url    = null;
		$img_class  = null;
		$srcset     = null;
		$pinterest  = null;
		$attributes = ' ';

		if ( is_string( $image ) ) {
			$img_url = esc_url( $image );
		}

		if ( is_array( $image ) ) {
			if ( ! empty( $image['image_url'] ) ) {
				$img_url = esc_url( $image['image_url'] );
			}
			if ( ! empty( $image['image_srcset'] ) && ! empty( $image['image_srcset_sizes'] ) ) {
				$srcset = ' srcset="' . $image['image_srcset'] . '" sizes="' . $image['image_srcset_sizes'] . '"';
			}
		}

		if ( ! empty( $image_meta['class'] ) ) {
			$img_class = ' class="' . $image_meta['class'] . '"';
		}

		if ( 'mv_create_vert' !== $image['image_size'] ) {
			$attributes .= 'data-pin-nopin="true" ';
		}

		// Always display alt attribute, even if empty for HTML validation
		$attributes .= 'alt="' . $image_alt_text . '" ';

		// Full resolution image for pinterest
		if ( ! empty( $image['image_url_full_size'] ) && $image['image_url_full_size'] !== $img_url ) {
			$pinterest = ' data-pin-media="' . $image['image_url_full_size'] . '"';
		}

		if ( ! empty( $img_url ) ) {
			$img_tag = '<img src="' . $img_url . '"' . $img_class . $attributes . $srcset . $pinterest . '>';
		}

		return $img_tag;
	}

	public static function is_image_correct_dimensions( $img_id, $img_size ) {
		$img_meta  = wp_get_attachment_image_src( $img_id, $img_size );
		$size_meta = self::get_image_sizes( [ $img_size ] );

		if ( is_array( $img_meta ) && is_array( $size_meta )
			&& ! empty( $size_meta[ $img_size ] )
			&& $img_meta[1] >= $size_meta[ $img_size ]['width']
			&& $img_meta[2] >= $size_meta[ $img_size ]['height']
		) {
			return true;
		}
		return false;
	}

	/**
	 * Prepends base sizes for each ratio if they don't exist using the lowest available matching ratio
	 *
	 * @param array $current_sizes Current list of sizes with data
	 * @return array List of sizes with required base ratios
	 */
	public static function get_required_base_sizes( $current_sizes ) {
		$required_ratios = [
			'1x1',
			'4x3',
			'16x9',
		];
		$resolutions     = apply_filters(
			'mv_create_image_resolutions', [
				'_medium_res',
				'_medium_high_res',
				'_high_res',
			]
		);
		foreach ( $required_ratios as $required_ratio ) {
			if ( ! array_key_exists( 'mv_create_' . $required_ratio, $current_sizes ) ) {
				// Add first matching ratio
				foreach ( $resolutions as $resolution ) {
					if ( array_key_exists( 'mv_create_' . $required_ratio . $resolution, $current_sizes ) ) {
						// Reverse results we need these at the beginning of the array
						$current_sizes = array_reverse( $current_sizes, true );

						$current_sizes[ 'mv_create_' . $required_ratio ] = $current_sizes[ 'mv_create_' . $required_ratio . $resolution ];

						// Correct the image size if it exists
						if ( array_key_exists( 'image_size', $current_sizes[ 'mv_create_' . $required_ratio ] ) ) {
							$current_sizes[ 'mv_create_' . $required_ratio ]['image_size'] = 'mv_create_' . $required_ratio;
						}

						// Reset the array order
						$current_sizes = array_reverse( $current_sizes, true );
					break;
					}
				}
			}
		}

		return $current_sizes;
	}

	/**
	 * Guarantees that available image sizes are an associative array
	 *
	 * @param array $available_sizes Current array of image sizes
	 * @return array Associative array of index sizes
	 */
	public static function get_available_image_sizes( $available_sizes ) {
		if ( ! Arr::isAssoc( $available_sizes ) ) {
			$fixed_available_sizes = [];
			foreach ( $available_sizes as $available_size ) {
				if ( ! empty( $available_size['image_size'] ) ) {
					$fixed_available_sizes[ $available_size['image_size'] ] = $available_size;
				}
			}
			$available_sizes = $fixed_available_sizes;
		}

		// Make sure base sizes exist or we will lose available image sizes
		$available_sizes = self::get_required_base_sizes( $available_sizes );

		return $available_sizes;
	}


	/**
	 * Finds the highest available resolution with the correct ratio
	 *
	 * @param int $img_id Image ID
	 * @param string $img_size Un-suffixed size resolution to test against
	 * @param array $available_sizes (Optional) List of sizes to test against
	 * @return  string                    Highest possible resolution image size
	 */
	public static function get_highest_available_image_size( $img_id, $img_size, $available_sizes = null ) {
		$prefix      = $img_size;
		$image_sizes = self::get_image_sizes();
		$resolutions = apply_filters(
			'mv_create_image_resolutions', [
				'_medium_res',
				'_medium_high_res',
				'_high_res',
			]
		);

		foreach ( $image_sizes as $size => $size_meta ) {
			foreach ( $resolutions as $resolution ) {
				if ( is_array( $available_sizes ) ) {
					// Don't check images that aren't available
					if ( empty( $available_sizes[ $prefix . $resolution ] ) ) {
						continue;
					}
				}

				if ( $size === $prefix . $resolution ) {
					$is_bigger_size = static::is_image_correct_dimensions( $img_id, $size );
					if ( $is_bigger_size ) {
						$img_size = $size;
					}
				}
			}
		}

		return $img_size;
	}

	/**
	 * Run queue on page-load
	 *
	 * @return mixed
	 */
	public function step_queue() {
		self::$image_queue->unlock();
		return self::$image_queue->step(
			function ( $item ) {
				self::generate_intermediate_sizes( $item['id'], $item['sizes'], $item['meta'] );
			}
		);
	}

	/**
	 * Checks that Create sizes exist for ID and generates if they don't
	 *
	 * This only checks for the lowest size image (mv_create_1x1) so that smaller
	 * images aren't continuously rebuilt. Images smaller than that size will
	 * unfortunately have to deal with the performance hit, but should be rare
	 *
	 * @param int|string $image_id ID of the image to check
	 * @param array $create_image_sizes Sizes to be generated if they exist
	 * @param boolean $return Return the $image_meta
	 * @return array|void Image meta if $return is true
	 */
	public static function check_image_size( $image_id, $create_image_sizes = [], $size = 'mv_create_1x1', $return = false ) {
		$image_sizes = self::get_image_sizes();

		// We will check for our 1x1 image, but in the case it's been filtered out, we return
		if ( empty( $image_sizes[ $size ] ) ) {
			return;
		}

		$image_meta = wp_get_attachment_image_src( $image_id, $size );

		// Check given image with correct size and return true if correct
		if (
			! empty( $image_meta ) &&
			$image_sizes[ $size ]['width'] === $image_meta[1] &&
			$image_sizes[ $size ]['height'] === $image_meta[2]
		) {
			if ( $return ) {
				return $image_meta;
			}

			return;
		}
		// Generate image sizes
		self::generate_intermediate_sizes( $image_id, $image_sizes );

		if ( $return ) {
			$image_meta = wp_get_attachment_image_src( $image_id, $size );

			return $image_meta;
		}
	}

	/**
	 * Download an image from a url and add to WP Media library
	 *
	 * @param string $img_src
	 *
	 * @return false|int|\WP_Error|null
	 */
	public static function download_image_from_url( $img_src ) {

		self::load_missing_wp_functions();

		$origin = $img_src;

		// Fetch image data
		$img_response = wp_remote_get(
			$img_src, [
				// this prevents servers from rejecting download requests based on the user-agent
				'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.85 Safari/537.36',
			]
		);

		$http_code = wp_remote_retrieve_response_code( $img_response );
		if ( is_wp_error( $img_response ) || ( 200 !== $http_code ) ) {
			// Handle error
			return false;
		}

		$body = wp_remote_retrieve_body( $img_response );
		if ( ! isset( $body ) ) {
			return false;
		}

		$img_data   = $body;
		$img_name   = basename( $img_src );
		$upload_dir = wp_upload_dir();

		// Save as WP attachment
		// Check folder permission and define file location
		if ( wp_mkdir_p( $upload_dir['path'] ) ) {
			$path = $upload_dir['path'] . '/';
		} else {
			$path = $upload_dir['basedir'] . '/';
		}

		$unique_filename = wp_unique_filename( $path, $img_name );
		$filename        = basename( $unique_filename );

		// Check image file type
		$wp_filetype = wp_check_filetype( $filename, null );
		$type        = $wp_filetype['type'] ? '' : '.jpg';
		$file        = $path . $filename . $type;
		file_put_contents( $file, $img_data );

		$attachment = [
			'post_mime_type' => $wp_filetype['type'] ? $wp_filetype['type'] : 'image/jpeg',
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		// Create the attachment
		$attach_id = wp_insert_attachment( $attachment, $file );

		if ( 0 === $attach_id ) {
			return null;
		}

		update_post_meta( $attach_id, 'origin_uri', $origin );

		// Define attachment metadata
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	/**
	 * Given an image URL, return thumbnail id. Fetches based on the following strategy:
	 * - Check for existing image with http, then https
	 * - Fetch external image based on url
	 * - Fetch external image based on url prepended with https
	 * - Fetch external image based on url prepended with http
	 * - Return 0 (image does not exist)
	 *
	 * @param  string $url String url that should reference an image
	 * @return number Attachment id
	 */
	public static function get_attachment_id_from_url( $url ) {
		$attachment_id = null;

		if ( ! $url ) {
			return $attachment_id;
		}

		$postmeta = new \Mediavine\MV_DBI( 'postmeta' );
		$postmeta->set_order_by( 'post_id' ); // override default order column `created`
		// WordPress linter is claiming meta_key and meta_value are "possible" slow queries
		$result = $postmeta->select_one(
			[
				'where' => [
					'meta_key'   => 'origin_uri', // @phpcs:ignore
					'meta_value' => $url, // @phpcs:ignore
				],
			]
		);

		if ( ! empty( $result->post_id ) ) {
			return $result->post_id;
		}

		$posts_model = new \Mediavine\MV_DBI( 'posts' );

		$stripped       = explode( '//', $url );
		$protocol       = $stripped[0];
		$uri            = $stripped[1];
		$attachment_url = $protocol . '//' . $uri;

		// Get the upload directory paths
		$upload_dir_paths = wp_upload_dir();

		// Make sure the upload path base directory exists in the attachment URL, to verify that we're working with a media library image
		if ( false !== strpos( $attachment_url, $upload_dir_paths['baseurl'] ) ) {

			// Strip the thumbnail dimensions from the url
			$attachment_url = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif)$)/i', '', $attachment_url );

			// Find the attachment post by guid
			$local_attachment = attachment_url_to_postid( $attachment_url );

			if ( ! empty( $local_attachment ) ) {
				return $local_attachment;
			}
		}

		// Sometimes, the URL part is stripped and just the filepath exists ¯\_(ツ)_/¯
		// Also, if someone has their `siteurl` configured as `http://` when SSL is active and it should be `https://`,
		// we need to remove the protocol from the URLs in order to get a match.
		$url_without_protocol              = explode( '//', $url )[1];
		$upload_dir_paths_without_protocol = explode( '//', $upload_dir_paths['baseurl'] )[1];
		$file_path                         = Str::replace( $upload_dir_paths_without_protocol . DIRECTORY_SEPARATOR, '', $url_without_protocol );
		$result                            = $postmeta->find_one(
			[
				'col' => 'meta_value',
				'key' => $file_path,
			]
		);

		if ( isset( $result->post_id ) ) {
			return $result->post_id;
		}

		// If that failed, try downloading the image from the original $url
		$attachment_id_from_url = self::download_image_from_url( $url );

		if ( $attachment_id_from_url ) {
			return $attachment_id_from_url;
		}

		// Still failed? Wow. Try from the formatted $attachment_url
		$attachment_id_from_url = self::download_image_from_url( $attachment_url );

		if ( $attachment_id_from_url ) {
			return $attachment_id_from_url;
		}

		// Oh well, return 0
		return $attachment_id;
	}

	public function init() {
		$this->images_api = new Images_API();
		$this->images_api->init();

		$this->images_models = new Images_Models();
		$this->images_models->init();

		self::$image_queue = new \Mediavine\Create\Queue(
			[
				'queue_name'     => 'mv_image_queue',
				'transient_name' => 'mv_image_queue_lock',
				'lock_timeout'   => 300,
				'auto_unlock'    => true,
			]
		);

		self::$api_services = API_Services::get_instance();

		self::$models             = new \stdClass();
		self::$models->{'images'} = new MV_DBI( $this->images_table );

		add_action( 'edit_attachment', [ $this, 'updated_image' ] );
		add_action( 'rest_api_init', [ $this, 'images_routes' ] );
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'disable_intermediate_image_sizes' ], 555 );
	}

	/**
	 * Disable Create image sizes to prevent automatic generation on upload
	 *
	 * @param array $new_sizes Associative array of image sizes to be created.
	 *
	 * @return array Array with mv_create_ images stripped out
	 */
	public function disable_intermediate_image_sizes( $new_sizes ) {

		// filter out mv_create_* sizes
		return array_filter(
			$new_sizes,
			static function ( $key ) {
				return ! ( strpos( $key, 'mv_create' ) !== false );
			}, ARRAY_FILTER_USE_KEY
		);
	}

	public function updated_image( $image_id ) {
		$args          = [
			'limit' => 500, // Should never reach this, but will prevent a timeout
			'where' => [
				'object_id' => (int) $image_id,
			],
		];
		$affected_rows = self::$models->images->find( $args );

		// TODO: Create update in bulk method in ORM
		foreach ( $affected_rows as $row ) {
			$updated_image = self::prep_image( (array) $row );
			self::$models->images->update( $updated_image, $updated_image['id'], false );
		}

		// TODO: Run publish recipe function after image table updated
		do_action( 'mv_image_updated', $image_id );
	}

	public function images_routes() {

		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/images', [
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this->images_api, 'create_image' ],
					'permission_callback' => static function() {
						return Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this->images_api, 'read_images' ],
					'permission_callback' => static function() {
						return Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/images/bulk', [
				[
					'methods'             => WP_REST_server::READABLE,
					'callback'            => [ $this->images_api, 'fetch_media_urls' ],
					'permission_callback' => function () {
						return Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/images/(?P<id>\d+)', [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this->images_api, 'read_single_image' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this->images_api, 'update_single_image' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this->images_api, 'delete_single_image' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/images/verify-integrity', [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this->images_api, 'verify_integrity' ],
					'permission_callback' => function () {
						return Permissions::is_user_authorized();
					},
				],
			]
		);

	}
}
