<?php

namespace Mediavine\Create;

class LinkScraper {
	private $defaults;
	private $methods;

	/**
	 * @param array $defaults Default values for responses.
	 * @param array $methods  Extra methods to try, in addition to OpenGraph and others.
	 *                        Should be passed as an associative array with the keys
	 *                        'title', 'thumbnail_uri', or 'description', and the values
	 *                        being regex patterns used to scrape that property from
	 *                        an HTML document.
	 */
	function __construct(
		$defaults = [
			'title'                => '',
			'remote_thumbnail_uri' => '',
			'description'          => null,
		],
		$methods = []
	) {
		$this->defaults = $defaults;
		$this->methods  = array_merge(
			[
				// Ref: https://regex101.com/r/gM4jyh/1
				'open-graph' => [
					'title'                => '/(?:property="og:title"[^>]+content="([^"]+))|(?:content="([^"]+)[^>]+property="og:title")/',
					'remote_thumbnail_uri' => '/(?:property="og:image"[^>]+content="([^"]+))|(?:content="([^"]+)[^>]+property="og:image")/',
					'description'          => '/(?:property="og:description"[^>]+content="([^"]+))|(?:content="([^"]+)[^>]+property="og:description")/',
				],
				// Amazon product data must come from the Creators API, never from
				// scraping amazon.com — see Bulk_Scrape_API::scrape_amazon_url() and
				// Products_API::scrape(), which both refuse rather than fall back here.
				// These patterns are retained only for non-Amazon pages that happen to
				// use the same markup; they are deliberately not maintained against
				// Amazon's current DOM.
				'amazon'     => [
					'title'                => '/id="productTitle" class="a-size-large">\s*(.*?)\s*<\/span>/s',
					'remote_thumbnail_uri' => '/id="landingImage"\sdata-a-dynamic-image="{&quot;(.*?)&quot;/',
					'description'          => '/name="description" content="(.*?)"/',
				],
				'fallback'   => [
					'title'                => '/<title>(.*?)</',
					'remote_thumbnail_uri' => '/<img src="(.*?)"/',
				],
			], $methods
		);
	}

	/**
	 * Public method to execute a scrape
	 * @param  string $url      URL of resource to be scraped.
	 * @param  array  $priority Array of strings that correspond to scrape methods.
	 *                          Scrapes will be attempted in this order.
	 * @return array            Array
	 */
	public function scrape( $url, $priority = [ 'open-graph', 'amazon', 'fallback' ] ) {
		// Fetch through the SSRF-guarded helper so an authenticated user can't turn
		// this scraper into an SSRF that reflects internal content back in the REST
		// response. The helper validates the initial URL *and every redirect hop*
		// against a guard that (unlike WP's) also blocks link-local (169.254.x) and
		// CGNAT, returning a WP_Error for any unsafe hop.
		$response = mv_create_safe_remote_get( $url );

		// Early return if request fails or was blocked as unsafe.
		if ( is_wp_error( $response ) ) {
			return array_merge( $this->defaults, [ 'source' => 'default' ] );
		}

		// Set some defaults
		$data   = $this->defaults;
		$method = 'default';

		// Fill each property from the highest-priority method that matched it.
		// Stopping at the first method with *any* match loses properties that
		// method didn't cover: an Amazon page has a product image and a meta
		// description but no `og:title`, which used to yield a blank title even
		// though the `<title>` fallback would have found one.
		foreach ( $priority as $method_name ) {
			if ( ! isset( $this->methods[ $method_name ] ) ) {
				continue;
			}

			$matches = $this->process( $response['body'], $this->methods[ $method_name ] );

			if ( ! $matches ) {
				continue;
			}

			// Source reports the first method that contributed anything, as before.
			if ( 'default' === $method ) {
				$method = $method_name;
			}

			foreach ( $matches as $property => $value ) {
				if ( empty( $data[ $property ] ) && ! empty( $value ) ) {
					$data[ $property ] = $value;
				}
			}

			if ( $this->is_complete( $data ) ) {
				break;
			}
		}

		// If we don't have a match, return early with defaults
		if ( 'default' === $method ) {
			return array_merge( $this->defaults, [ 'source' => 'default' ] );
		}

		return array_merge( $data, [ 'source' => $method ] );
	}

	/**
	 * Whether every scrapable property already has a value.
	 *
	 * @param array $data Accumulated scrape results.
	 * @return bool True when no further method could add anything.
	 */
	private function is_complete( $data ) {
		foreach ( [ 'title', 'remote_thumbnail_uri', 'description' ] as $property ) {
			if ( empty( $data[ $property ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Handles a scrape.
	 * @param  string $body     HTML document
	 * @param  array  $patterns Array of regex patterns. Should have 'title',
	 *                          'remote_thumbnail_uri', and 'description' keys.
	 * @return array|null       Array of match data
	 */
	private function process( $body, $patterns ) {
		// Set bool flag for if we have a match. We might have a partial match,
		// in which case we would want to merge that with our results, but if we
		// don't have any match, we want to return a false-y value instead of defaults.
		$has_match = false;
		$data      = $this->defaults;

		// Check for matches for each property
		$properties = [ 'title', 'remote_thumbnail_uri', 'description' ];
		foreach ( $properties as $property ) {
			$match = null;
			// Skip over methods that don't exist.
			if ( isset( $patterns[ $property ] ) ) {
				preg_match( $patterns[ $property ], $body, $match );

				if ( is_array( $match ) ) {
					// We iterate over each subpattern by starting at 1st index.
					for ( $i = 1; $i < count( $match ); $i++ ) {
						// If a subpattern is matched, use it as the value.
						if ( isset( $match[ $i ] ) && $match[ $i ] && strlen( $match[ $i ] ) ) {
							// We have a match, so we want to return something from this function.
							$has_match         = true;
							$data[ $property ] = trim( $match[ $i ] );
							// We found a match, so we can stop.
							break;
						}
					}
				}
			}
		}

		// Return results
		return $has_match ? $data : null;
	}

}
