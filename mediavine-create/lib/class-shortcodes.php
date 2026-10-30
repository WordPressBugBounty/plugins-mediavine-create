<?php

namespace Mediavine\Create;

/**
 * Create's content shortcodes (`[mv_img]`, `[mvc_ad]`, `[mv_schema_meta]`) and
 * the bare-URL media "unfurling" that feeds `[mv_img url="…"]`.
 *
 * Shortcode tags are registered in Plugin::init(); the tags and the
 * `mv_create_unfurl_media_extensions` filter are public API.
 */
class Shortcodes {

	/**
	 * [mv_schema_meta name="…"] — a hidden span carrying a schema property name.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function mv_schema_meta_shortcode( $atts ) {
		if ( isset( $atts['name'] ) ) {
			return '<span data-schema-name="' . esc_attr( $atts['name'] ) . '" style="display: none;"></span>';
		}
		return '';
	}

	/**
	 * [mv_img] — an inline image by attachment id or external URL.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function mv_img_shortcode( $atts ) {
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
	public static function mvc_ad_shortcode() {
		return '';
	}
}
