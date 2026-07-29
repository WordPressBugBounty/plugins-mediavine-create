<?php
/**
 * Shared scrape primitives: Amazon PAAPI, Create Studio services API, local LinkScraper.
 *
 * Callers compose these methods; there is no single orchestrator. Documented policies:
 *
 * - scrape_external(): Studio services when connected, else local LinkScraper; also
 *   falls back to local on services network/HTTP/empty failures. Surfaces 429 as
 *   rate_limited without local fallback. Used by Bulk_Scrape_API and by
 *   Products_API::scrape_non_amazon when no ASIN is present.
 * - scrape_amazon(): PAAPI only. Products_API returns wp_error (including 429) to
 *   the client. Bulk_Scrape_API falls through to scrape_external() on empty/error
 *   when affiliates are configured.
 *
 * Previously Products_API returned HTTP 401 `not_connected` when Studio was
 * disconnected; scrape_external() now uses the local LinkScraper fallback instead.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;
use Mediavine\Create\Helpers\Str;

/**
 * Scraper_Service shares PAAPI / Studio / local scrape implementations.
 */
class Scraper_Service {

	/**
	 * Create Studio services base URL.
	 *
	 * @var string
	 */
	private $services_api_url;

	/**
	 * @param string|null $services_api_url Override for tests; defaults to Plugin::$services_api_url.
	 */
	public function __construct( $services_api_url = null ) {
		$this->services_api_url = $services_api_url ? $services_api_url : Plugin::$services_api_url;
	}

	/**
	 * Attempt PAAPI scrape for an Amazon URL / ASIN.
	 *
	 * @param string      $url  URL (used to derive ASIN when not provided).
	 * @param string|null $asin Optional ASIN.
	 * @return array Success, empty, rate_limited (+ wp_error), wp_error, or skipped (no ASIN).
	 */
	public function scrape_amazon( $url, $asin = null ) {
		$amazon = Amazon_Adapter::get_instance();

		if ( empty( $asin ) ) {
			$asin = $amazon->get_asin_from_link( $url );
		}

		if ( empty( $asin ) || Str::length( $asin ) !== 10 ) {
			return [
				'status' => 'skipped',
				'reason' => 'no_asin',
			];
		}

		$products = $amazon->get_products_by_asin( $asin );

		if ( is_wp_error( $products ) ) {
			$error_data = $products->get_error_data();
			if ( isset( $error_data['status'] ) && 429 === (int) $error_data['status'] ) {
				return [
					'rate_limited' => true,
					'retry_after'  => 60,
					'wp_error'     => $products,
				];
			}

			return [
				'status'   => 'error',
				'wp_error' => $products,
				'asin'     => $asin,
			];
		}

		if ( empty( $products[ $asin ] ) ) {
			return [
				'status' => 'empty',
				'asin'   => $asin,
			];
		}

		$product = $products[ $asin ];
		$title   = Scraped_Content_Normalizer::sanitize_title(
			isset( $product['title'] ) ? $product['title'] : ''
		);

		return [
			'status'                 => 'success',
			'source'                 => 'amazon',
			'asin'                   => $asin,
			'data'                   => $product,
			// Convenience fields used by bulk scrape formatting.
			'title'                  => $title,
			'description'            => isset( $product['description'] ) ? $product['description'] : $title,
			'external_thumbnail_url' => isset( $product['external_thumbnail_url'] ) ? $product['external_thumbnail_url'] : null,
		];
	}

	/**
	 * Scrape via Create Studio services API, falling back to local LinkScraper.
	 *
	 * @param string $url URL to scrape.
	 * @return array
	 */
	public function scrape_external( $url ) {
		$api_token_setting = Settings::get_settings( 'mv_create_api_token' );

		if ( empty( $api_token_setting ) || empty( $api_token_setting->value ) ) {
			return $this->scrape_local( $url );
		}

		$scrape_url = $this->services_api_url . '/scraper/scrape';
		$response   = wp_remote_post(
			$scrape_url,
			[
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'bearer ' . $api_token_setting->value,
				],
				'body'    => wp_json_encode( [ 'url' => $url ] ),
				// Scrapes routinely exceed VIP's 3s default; match Bulk_Scrape_API.
				'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- scrape latency
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->scrape_local( $url );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 429 === (int) $status_code ) {
			$headers     = wp_remote_retrieve_headers( $response );
			$retry_after = isset( $headers['retry-after'] ) ? (int) $headers['retry-after'] : 30;

			return [
				'rate_limited' => true,
				'retry_after'  => $retry_after,
			];
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->scrape_local( $url );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! isset( $data['data'] ) ) {
			return $this->scrape_local( $url );
		}

		$scraped_data = $data['data'];

		$thumbnail    = null;
		$image_fields = [ 'lead_image_url', 'image', 'og_image', 'thumbnail', 'external_thumbnail_url', 'remote_thumbnail_uri' ];
		foreach ( $image_fields as $field ) {
			if ( ! empty( $scraped_data[ $field ] ) ) {
				$thumbnail = $scraped_data[ $field ];
				break;
			}
		}

		$description = '';
		$desc_fields = [ 'description', 'dek', 'excerpt' ];
		foreach ( $desc_fields as $field ) {
			if ( ! empty( $scraped_data[ $field ] ) ) {
				$description = $scraped_data[ $field ];
				break;
			}
		}

		$thumbnail_alt = '';
		$alt_fields    = [ 'lead_image_alt', 'image_alt', 'og_image_alt' ];
		foreach ( $alt_fields as $field ) {
			if ( ! empty( $scraped_data[ $field ] ) ) {
				$thumbnail_alt = $scraped_data[ $field ];
				break;
			}
		}

		$title = Scraped_Content_Normalizer::sanitize_title(
			isset( $scraped_data['title'] ) ? $scraped_data['title'] : ''
		);

		// Shape compatible with Products_API (legacy services payload) and bulk.
		$normalized = array_merge(
			$scraped_data,
			[
				'title'                  => $title,
				'description'            => $description,
				'external_thumbnail_url' => $thumbnail,
				'remote_thumbnail_uri'   => $thumbnail,
				'thumbnail_uri'          => $thumbnail,
				'thumbnail_alt'          => $thumbnail_alt,
				'source'                 => isset( $scraped_data['source'] ) ? $scraped_data['source'] : 'external-service',
			]
		);

		return [
			'status' => 'success',
			'source' => 'external-service',
			'data'   => $normalized,
		];
	}

	/**
	 * Scrape using the local LinkScraper.
	 *
	 * @param string $url URL to scrape.
	 * @return array
	 */
	public function scrape_local( $url ) {
		$scraper = new LinkScraper();
		$result  = $scraper->scrape( $url );

		if ( empty( $result['title'] ) && empty( $result['remote_thumbnail_uri'] ) ) {
			return [
				'status' => 'error',
				'error'  => __( 'Could not scrape URL. No title or image found.', 'mediavine-create' ),
			];
		}

		$title     = Scraped_Content_Normalizer::sanitize_title( isset( $result['title'] ) ? $result['title'] : '' );
		$thumbnail = isset( $result['remote_thumbnail_uri'] ) ? $result['remote_thumbnail_uri'] : null;

		$normalized = [
			'title'                  => $title,
			'description'            => isset( $result['description'] ) ? $result['description'] : '',
			'external_thumbnail_url' => $thumbnail,
			'remote_thumbnail_uri'   => $thumbnail,
			'thumbnail_uri'          => $thumbnail,
			'source'                 => isset( $result['source'] ) ? $result['source'] : 'local-scraper',
		];

		return [
			'status' => 'success',
			'source' => 'local-scraper',
			'data'   => $normalized,
		];
	}
}
