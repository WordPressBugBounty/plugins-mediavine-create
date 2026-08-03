<?php
namespace Mediavine;

use Mediavine\Create\Images;

class View_Loader {

	private static $instance = null;

	public static $plugin_path = null;

	/**
	 * Plugin-side style directory aliases.
	 *
	 * Styles listed here fall back to the aliased style's plugin view files when
	 * they don't have their own. Only applies to the plugin lookup chain in
	 * locate_view; theme overrides still use the original style directory.
	 *
	 * @var array
	 */
	private static $style_aliases = [
		'centered-dark' => 'centered',
	];

	public static function get_instance( $plugin_path ) {
		if ( null === self::$instance ) {
			self::$plugin_path = $plugin_path;
			self::$instance    = new self();
		}

		return self::$instance;
	}

	public static function mv_image_tag( $image, $image_class, $image_alt_text = null ) {
		if ( class_exists( 'Mediavine\Create\Images' ) ) {
			return Images::mv_image_tag( $image, $image_class, $image_alt_text );
		}

		return null;
	}

	/**
	 * Gets the image tags for each of the available Create image sizes
	 *
	 * @param array $card_object_array Image data for the creation
	 * @param array $image_sizes Image size data for MV Create images
	 * @return array HTML tags that be be outputted for each image size
	 */
	public static function get_mv_image_tags( $card_object_array, $image_sizes = [] ) {
		$img_tags = [];
		if ( ! empty( $card_object_array['images'] ) && is_array( $card_object_array['images'] ) && ! empty( $image_sizes ) ) {

			$available_images = Images::get_available_image_sizes( $card_object_array['images'] );
			foreach ( $available_images as $image ) {
				$image      = (array) $image;
				$image_size = $image['image_size'];

				// Skip resolution variants; pick the best size from the base name.
				if ( Images::size_has_resolution_suffix( $image_size ) ) {
					continue;
				}

				// Use the best available image resolution
				$highest_res_image = Images::get_highest_available_image_size( $image['object_id'], $image_size, $available_images );
				$image_alt_text    = get_post_meta( $image['object_id'], '_wp_attachment_image_alt', true );

				if ( empty( $image_alt_text ) ) {
					$image_alt_text = $card_object_array['title'];
				}

				$img_tag = null;

				if ( 'full' === $highest_res_image ) {
					// Intermediate crop missing — serve the original file with the
					// registered Create size's class so card templates still get a
					// tag keyed by $image_size (e.g. mv_create_16x9).
					$img_tag = self::mv_image_tag_from_full_attachment(
						$image,
						$image_size,
						$image_sizes,
						$image_alt_text
					);
				} elseif ( ! empty( $image_sizes[ $highest_res_image ] ) && ! empty( $available_images[ $highest_res_image ] ) ) {
					// Replace image data with highest res data, and correct size name
					$image               = (array) $available_images[ $highest_res_image ];
					$image['image_size'] = $image_size;

					$img_tag = self::mv_image_tag( $image, $image_sizes[ $highest_res_image ], $image_alt_text );
				}

				if ( ! empty( $img_tag ) ) {
					$img_tags[ $image_size ] = $img_tag;
				}
			}
		}

		return $img_tags;
	}

	/**
	 * Build a Create card <img> tag from the full attachment when a crop is missing.
	 *
	 * @param array  $image          Card image row (needs object_id).
	 * @param string $image_size     Create size name to key the tag under.
	 * @param array  $image_sizes    Registered Create size definitions.
	 * @param string $image_alt_text Alt text.
	 * @return string|null
	 */
	private static function mv_image_tag_from_full_attachment( $image, $image_size, $image_sizes, $image_alt_text ) {
		if ( empty( $image['object_id'] ) || empty( $image_sizes[ $image_size ] ) ) {
			return null;
		}

		$full_src = wp_get_attachment_image_src( (int) $image['object_id'], 'full' );
		if ( empty( $full_src[0] ) ) {
			return null;
		}

		$image_meta            = $image_sizes[ $image_size ];
		$image_meta['width']   = $full_src[1];
		$image_meta['height']  = $full_src[2];

		$tag_image = [
			'object_id'           => $image['object_id'],
			'image_size'          => $image_size,
			'image_url'           => $full_src[0],
			'image_url_full_size' => $full_src[0],
			'image_srcset'        => '',
			'image_srcset_sizes'  => '',
		];

		return self::mv_image_tag( $tag_image, $image_meta, $image_alt_text );
	}

	/**
	 * Locate view file
	 *
	 * Search Order (theme only checked if hook enabled):
	 * 1.  /themes/{$theme_name}/{$view_theme_base}/{$view_style}/{$view_name}-{$view_type}.php
	 * 2.  /themes/{$theme_name}/{$view_theme_base}/{$view_style}/{$view_name}.php
	 * 5.  /themes/{$theme_name}/{$view_theme_base}/{$view_name}-{$view_type}.php
	 * 6.  /themes/{$theme_name}/{$view_theme_base}/{$view_name}.php
	 * 3.  /themes/{$theme_name}/{$view_style}/{$view_name}-{$view_type}.php
	 * 4.  /themes/{$theme_name}/{$view_style}/{$view_name}.php
	 * 7.  /themes/{$theme_name}/{$view_name}-{$view_type}.php
	 * 8.  /themes/{$theme_name}/{$view_name}.php
	 * 9.  /plugins/{$plugin_name}/lib/views/{$view_version}/{$view_style}/{$view_name}-{$view_type}.php
	 * 10. /plugins/{$plugin_name}/lib/views/{$view_version}/{$view_style}/{$view_name}.php
	 * 11. /plugins/{$plugin_name}/lib/views/{$view_version}/{$aliased_style}/{$view_name}-{$view_type}.php (aliased styles only)
	 * 12. /plugins/{$plugin_name}/lib/views/{$view_version}/{$aliased_style}/{$view_name}.php (aliased styles only)
	 * 13. /plugins/{$plugin_name}/lib/views/{$view_version}/{$view_name}-{$view_type}.php
	 * 14. /plugins/{$plugin_name}/lib/views/{$view_version}/{$view_name}.php
	 *
	 * @return  string  Path to the view file
	 */
	public function locate_view( $view_name, $args = [], $default_path = '' ) {
		// Initialize view path segments explicitly (avoids variable-variables that PHPStan cannot track).
		// Force trailing slash on all but type/base.
		$view_base    = ! empty( $args['base'] ) ? $args['base'] : null;
		$view_type    = ! empty( $args['type'] ) ? $args['type'] : null;
		$view_style   = ! empty( $args['style'] ) ? trailingslashit( $args['style'] ) : null;
		$view_version = ! empty( $args['version'] ) ? trailingslashit( $args['version'] ) : null;

		// Remove php from file name
		if ( substr( $view_name, -4 ) === '.php' ) {
			$view_name = explode( '.php', $view_name );
			$view_name = $view_name[0];
		}

		// Set default plugin views path
		if ( ! $default_path ) {
			$default_path = self::$plugin_path . 'lib/views/'; // Path to the view folder
		}

		$view             = null;
		$has_custom_style = apply_filters( 'mv_' . $view_base . '_style_version', false );

		// Only search if theme supports custom styles
		if ( $has_custom_style ) {

			// Apply filters to base directory for themes
			$view_theme_base = apply_filters( 'mv_' . $view_base . '_view_theme_dir', "mv_$view_base" );
			$view_theme_base = trailingslashit( $view_theme_base );

			// Use correct version if style file in theme doesn't exist
			$view_version = trailingslashit( $has_custom_style );

			// Template search order
			$locate_template = [
				// 1. base/style/file-type.php
				$view_theme_base . $view_style . $view_name . '-' . $view_type . '.php',
				// 2. base/style/file.php
				$view_theme_base . $view_style . $view_name . '.php',
				// 3. base/file-type.php
				$view_theme_base . $view_name . '-' . $view_type . '.php',
				// 4. base/file.php
				$view_theme_base . $view_name . '.php',
				// 5. style/file-type.php
				$view_style . $view_name . '-' . $view_type . '.php',
				// 6. style/file.php
				$view_style . $view_name . '.php',
				// 7. file-type.php
				$view_name . '-' . $view_type . '.php',
				// 8. file.php
				$view_name . '.php',
			];

			// Search view file in site's theme folder
			$view = locate_template( $locate_template );

		}

		// Aliased style directory for the plugin-side lookup only
		$view_style_alias = null;
		if ( ! empty( $view_style ) && ! empty( self::$style_aliases[ untrailingslashit( $view_style ) ] ) ) {
			$view_style_alias = trailingslashit( self::$style_aliases[ untrailingslashit( $view_style ) ] );
		}

		// Get view file from plugin style and type file
		// 1. version/style/file-type.php
		if ( empty( $view ) ) {
			$view = $default_path . $view_version . $view_style . $view_name . '-' . $view_type . '.php';
		}
		// 2. version/style/file.php
		if ( ! file_exists( $view ) ) {
			$view = $default_path . $view_version . $view_style . $view_name . '.php';
		}
		// 3. version/aliased-style/file-type.php
		if ( ! empty( $view_style_alias ) && ! file_exists( $view ) ) {
			$view = $default_path . $view_version . $view_style_alias . $view_name . '-' . $view_type . '.php';
		}
		// 4. version/aliased-style/file.php
		if ( ! empty( $view_style_alias ) && ! file_exists( $view ) ) {
			$view = $default_path . $view_version . $view_style_alias . $view_name . '.php';
		}
		// 5. version/file-type.php
		if ( ! file_exists( $view ) ) {
			$view = $default_path . $view_version . $view_name . '-' . $view_type . '.php';
		}
		// 6. version/file.php
		if ( ! file_exists( $view ) ) {
			$view = $default_path . $view_version . $view_name . '.php';
		}

		return apply_filters( 'mv_locate_view', $view, $view_name, $view_style, $default_path );
	}

	// Get view file
	public function get_view( $view_name, $args = [], $default_path = '' ) {
		$view_file = $this->locate_view( $view_name, $args, $default_path );

		ob_start();

		if ( file_exists( $view_file ) ) {
			include $view_file;
		}

		$view = ob_get_clean();

		return $view;
	}

	// Display view file
	public function the_view( $view_name, $args = [], $default_path = '', $do_shortcode = false ) {
		$view          = $this->get_view( $view_name, $args, $default_path );
		$kses_defaults = wp_kses_allowed_html( 'post' );
		$svg_kses      = [
			'svg'     => [
				'class'               => true,
				'aria-hidden'         => true,
				'preserveaspectratio' => true,
				'aria-labelledby'     => true,
				'xmlns'               => true,
				'width'               => true,
				'height'              => true,
				'viewbox'             => true, // <= Must be lower case!
			],
			'g'       => [ 'fill' => true ],
			'title'   => [ 'title' => true ],
			'path'    => [
				'd'         => true,
				'fill'      => true,
				'class'     => true,
				'transform' => true,
			],
			'rect'    => [
				'x'      => true,
				'y'      => true,
				'height' => true,
				'width'  => true,
				'class'  => true,
				'rx'     => true,
				'ry'     => true,
			],
			'ellipse' => [
				'x'      => true,
				'y'      => true,
				'height' => true,
				'width'  => true,
				'class'  => true,
			],
		];
		$allowed_tags  = array_merge( $kses_defaults, $svg_kses );

	if ( ! empty( $view ) ) {
			if ( $do_shortcode ) {
				echo do_shortcode( wp_kses( $view, $allowed_tags ) );
			} else {
				echo wp_kses( $view, $allowed_tags );
			}
		}

		return;
	}

}
