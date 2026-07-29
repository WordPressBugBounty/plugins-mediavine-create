<?php
/**
 * Shared sanitizers for scraped product/list titles and thumbnails.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

/**
 * Normalizes whitespace in titles and external thumbnail fields on scrape results.
 */
class Scraped_Content_Normalizer {

	/**
	 * Sanitize a scraped title by collapsing newlines and excess whitespace.
	 *
	 * @param string|null $title Raw title.
	 * @return string Sanitized title, or empty string when input is empty.
	 */
	public static function sanitize_title( $title ) {
		if ( empty( $title ) ) {
			return '';
		}

		$title = str_replace( [ "\r\n", "\r", "\n" ], ' ', (string) $title );
		$title = preg_replace( '/\s+/', ' ', $title );

		return trim( $title );
	}

	/**
	 * Copy external_thumbnail_url onto the thumbnail URI fields used by the editor.
	 *
	 * Accepts either an object or an array result shape (Products_API returns both).
	 *
	 * @param array|object $result Scrape or product result.
	 * @return array|object Same type as input, with thumbnail fields set when applicable.
	 */
	public static function apply_external_thumbnail( $result ) {
		if ( is_object( $result ) && ! empty( $result->external_thumbnail_url ) ) {
			$result->thumbnail_id         = null;
			$result->thumbnail_uri        = $result->external_thumbnail_url;
			$result->remote_thumbnail_uri = $result->external_thumbnail_url;
			return $result;
		}

		if ( is_array( $result ) && ! empty( $result['external_thumbnail_url'] ) ) {
			$result['thumbnail_id']         = null;
			$result['thumbnail_uri']        = $result['external_thumbnail_url'];
			$result['remote_thumbnail_uri'] = $result['external_thumbnail_url'];
		}

		return $result;
	}
}
