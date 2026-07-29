<?php
/**
 * Bulk Scrape API - Bulk URL scraping for list items
 *
 * Handles bulk scraping of URLs for the List Bulk Import feature.
 * Supports internal links (posts, pages, Create cards) and external URLs.
 *
 * @package Mediavine\Create
 * @since 2.1.0
 */

namespace Mediavine\Create;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Bulk_Scrape_API class for handling bulk URL scraping.
 *
 * This class handles:
 * - URL validation and parsing
 * - Internal vs external link detection
 * - Integration with LinkScraper for external URLs
 * - Integration with Amazon scraper for Amazon links
 * - Rate limit handling from external scraper service
 */
class Bulk_Scrape_API {

	/**
	 * The REST API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'mv-create/v1';

	/**
	 * Services API URL for external scraping.
	 *
	 * @var string
	 */
	private static $services_api_url;

	/**
	 * Initialize the API and register routes.
	 *
	 * @return void
	 */
	public function init() {
		self::$services_api_url = Plugin::$services_api_url;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/lists/bulk-scrape',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_scrape' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				'args'                => [
					'urls' => [
						'required'          => true,
						'type'              => 'array',
						'items'             => [
							'type' => 'string',
						],
						'sanitize_callback' => [ $this, 'sanitize_urls' ],
						'validate_callback' => [ $this, 'validate_urls' ],
					],
				],
			]
		);
	}

	/**
	 * Sanitize array of URLs.
	 *
	 * @param array $urls Array of URL strings.
	 * @return array Sanitized URLs.
	 */
	public function sanitize_urls( $urls ) {
		if ( ! is_array( $urls ) ) {
			return [];
		}

		return array_map( 'esc_url_raw', $urls );
	}

	/**
	 * Validate array of URLs.
	 *
	 * @param array $urls Array of URL strings.
	 * @return bool True if valid.
	 */
	public function validate_urls( $urls ) {
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle bulk scrape request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The response with scraped data.
	 */
	public function bulk_scrape( WP_REST_Request $request ) {
		// Pro feature gate (capability matches single scrape via Permissions::editor).
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_LIST_BULK_IMPORT ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Bulk import is a Pro feature. Please upgrade to access this feature.', 'mediavine-create' ),
				[
					'status'      => 403,
					'upgrade_url' => GateKeeper::get_upgrade_url(),
				]
			);
		}

		$urls    = $request->get_param( 'urls' );
		$results = [];

		// Track rate limiting.
		$rate_limited   = false;
		$retry_after    = 0;
		$remaining_urls = [];

		foreach ( $urls as $url ) {
			// If we've been rate limited, add remaining URLs to the list.
			if ( $rate_limited ) {
				$remaining_urls[] = $url;
				continue;
			}

			$result = $this->scrape_single_url( $url );

			// Check for rate limit response.
			if ( is_array( $result ) && isset( $result['rate_limited'] ) && $result['rate_limited'] ) {
				$rate_limited = true;
				$retry_after  = isset( $result['retry_after'] ) ? $result['retry_after'] : 30;
				$remaining_urls[] = $url;
				continue;
			}

			$results[] = $result;
		}

		// Build response.
		$response_data = [
			'results' => $results,
		];

		// Add rate limit info if applicable.
		if ( $rate_limited ) {
			$response_data['rate_limited']   = true;
			$response_data['retry_after']    = $retry_after;
			$response_data['remaining_urls'] = $remaining_urls;
		}

		// Fire action for trial extension detection.
		if ( ! empty( $results ) ) {
			do_action( 'mv_create_bulk_import_completed', $results );
		}

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Scrape a single URL.
	 *
	 * @param string $url The URL to scrape.
	 * @return array Result array with url, status, type, and data/error.
	 */
	private function scrape_single_url( $url ) {
		// Detect URL type.
		$url_type = $this->detect_url_type( $url );

		switch ( $url_type['type'] ) {
			case 'post':
			case 'page':
				return $this->scrape_internal_post( $url, $url_type );

			case 'card':
				return $this->scrape_create_card( $url, $url_type );

			case 'amazon':
				return $this->scrape_amazon_url( $url );

			case 'external':
			default:
				return $this->scrape_external_url( $url );
		}
	}

	/**
	 * Detect the type of URL (internal post, page, card, amazon, or external).
	 *
	 * @param string $url The URL to analyze.
	 * @return array Array with 'type' and optional 'post_id' or 'card_id'.
	 */
	private function detect_url_type( $url ) {
		// Check for Amazon URLs first.
		if ( $this->is_amazon_url( $url ) ) {
			return [ 'type' => 'amazon' ];
		}

		// Check if this is an internal URL.
		$site_url = home_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		// Not an internal URL.
		if ( $site_host !== $url_host ) {
			return [ 'type' => 'external' ];
		}

		// Check for Create card URL pattern.
		if ( strpos( $url, '/mv_create/' ) !== false || strpos( $url, 'post_type=mv_create' ) !== false ) {
			$card_id = $this->get_card_id_from_url( $url );
			if ( $card_id ) {
				return [
					'type'    => 'card',
					'card_id' => $card_id,
				];
			}
		}

		// Use WordPress's url_to_postid() for internal links.
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_type = $post->post_type;

				// Check if it's a Create card custom post type.
				if ( 'mv_create' === $post_type ) {
					return [
						'type'    => 'card',
						'card_id' => $post_id,
					];
				}

				return [
					'type'      => ( 'page' === $post_type ) ? 'page' : 'post',
					'post_id'   => $post_id,
					'post_type' => $post_type,
				];
			}
		}

		// Internal URL but couldn't resolve to a post.
		return [ 'type' => 'external' ];
	}

	/**
	 * Check if URL is an Amazon URL.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if Amazon URL.
	 */
	private function is_amazon_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		// Match amazon.* or amzn.* domains.
		return (
			strpos( $host, 'amazon.' ) !== false ||
			strpos( $host, 'amzn.' ) !== false
		);
	}

	/**
	 * Extract Create card ID from URL.
	 *
	 * @param string $url The URL to parse.
	 * @return int|null Card ID or null if not found.
	 */
	private function get_card_id_from_url( $url ) {
		// Try to match /mv_create/123 pattern.
		if ( preg_match( '/\/mv_create\/(\d+)/', $url, $matches ) ) {
			return (int) $matches[1];
		}

		// Try query string pattern.
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $params );
			if ( isset( $params['p'] ) && isset( $params['post_type'] ) && 'mv_create' === $params['post_type'] ) {
				return (int) $params['p'];
			}
		}

		return null;
	}

	/**
	 * Scrape internal post/page data.
	 *
	 * @param string $url      The URL.
	 * @param array  $url_type URL type detection result.
	 * @return array Result array.
	 */
	private function scrape_internal_post( $url, $url_type ) {
		$post_id = $url_type['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return [
				'url'    => $url,
				'status' => 'error',
				'type'   => $url_type['type'],
				'error'  => __( 'Post not found or not published.', 'mediavine-create' ),
			];
		}

		// Get post data.
		$title       = $this->sanitize_title( $post->post_title );
		$description = $this->get_post_excerpt( $post );
		$thumbnail   = $this->get_post_thumbnail( $post_id );

		return [
			'url'    => $url,
			'status' => 'success',
			'type'   => $url_type['type'],
			'data'   => [
				'title'         => $title,
				'description'   => $description,
				'thumbnail_uri' => $thumbnail,
				'post_id'       => $post_id,
				'post_type'     => $url_type['post_type'],
				'source'        => 'internal',
			],
		];
	}

	/**
	 * Scrape Create card data.
	 *
	 * @param string $url      The URL.
	 * @param array  $url_type URL type detection result.
	 * @return array Result array.
	 */
	private function scrape_create_card( $url, $url_type ) {
		$card_id = $url_type['card_id'];

		// Get card from database.
		$card = Plugin::$models_v2->mv_creations->find_one( $card_id );

		if ( ! $card ) {
			return [
				'url'    => $url,
				'status' => 'error',
				'type'   => 'card',
				'error'  => __( 'Create card not found.', 'mediavine-create' ),
			];
		}

		// Get thumbnail.
		$thumbnail = null;
		if ( ! empty( $card->thumbnail_id ) ) {
			$thumbnail = wp_get_attachment_url( $card->thumbnail_id );
		}

		return [
			'url'    => $url,
			'status' => 'success',
			'type'   => 'card',
			'data'   => [
				'title'         => $this->sanitize_title( $card->title ),
				'description'   => ! empty( $card->description ) ? $card->description : '',
				'thumbnail_uri' => $thumbnail,
				'card_id'       => $card_id,
				'card_type'     => $card->type,
				'source'        => 'internal',
			],
		];
	}

	/**
	 * Scrape Amazon URL using Amazon API.
	 *
	 * @param string $url The Amazon URL.
	 * @return array Result array.
	 */
	private function scrape_amazon_url( $url ) {
		$scraper = new Scraper_Service( self::$services_api_url );
		$amazon  = Amazon_Adapter::get_instance();

		// If Amazon API is not set up, fall back to external scraper.
		if ( ! $amazon->amazon_affiliates_setup() ) {
			return $this->scrape_external_url( $url );
		}

		$result = $scraper->scrape_amazon( $url );

		if ( ! empty( $result['rate_limited'] ) ) {
			return $result;
		}

		if ( ! empty( $result['status'] ) && 'success' === $result['status'] ) {
			return [
				'url'    => $url,
				'status' => 'success',
				'type'   => 'external',
				'data'   => [
					'title'         => $result['title'],
					'description'   => $result['description'],
					'thumbnail_uri' => $result['external_thumbnail_url'],
					'asin'          => $result['asin'],
					'source'        => 'amazon',
				],
			];
		}

		// No product found or PAAPI error — fall back to external scraper.
		return $this->scrape_external_url( $url );
	}

	/**
	 * Scrape external URL using the services API.
	 *
	 * @param string $url The URL to scrape.
	 * @return array Result array.
	 */
	private function scrape_external_url( $url ) {
		$scraper = new Scraper_Service( self::$services_api_url );
		$result  = $scraper->scrape_external( $url );

		if ( ! empty( $result['rate_limited'] ) ) {
			return $result;
		}

		if ( empty( $result['status'] ) || 'success' !== $result['status'] ) {
			$error = isset( $result['error'] ) ? $result['error'] : __( 'Could not scrape URL. No title or image found.', 'mediavine-create' );
			return $this->create_error_result( $url, $error );
		}

		$data = $result['data'];

		return [
			'url'    => $url,
			'status' => 'success',
			'type'   => 'external',
			'data'   => [
				'title'         => isset( $data['title'] ) ? $data['title'] : '',
				'description'   => isset( $data['description'] ) ? $data['description'] : '',
				'thumbnail_uri' => isset( $data['thumbnail_uri'] ) ? $data['thumbnail_uri'] : null,
				'thumbnail_alt' => isset( $data['thumbnail_alt'] ) ? $data['thumbnail_alt'] : '',
				'source'        => isset( $data['source'] ) ? $data['source'] : $result['source'],
			],
		];
	}

	/**
	 * Create an error result array.
	 *
	 * @param string $url   The URL that failed.
	 * @param string $error The error message.
	 * @return array Error result array.
	 */
	private function create_error_result( $url, $error ) {
		return [
			'url'    => $url,
			'status' => 'error',
			'type'   => 'external',
			'error'  => $error,
		];
	}

	/**
	 * Sanitize title by removing newlines and extra whitespace.
	 *
	 * @param string $title The title to sanitize.
	 * @return string Sanitized title.
	 */
	private function sanitize_title( $title ) {
		return Scraped_Content_Normalizer::sanitize_title( $title );
	}

	/**
	 * Get post excerpt or generate from content.
	 *
	 * @param WP_Post $post The post object.
	 * @return string The excerpt.
	 */
	private function get_post_excerpt( $post ) {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		// Generate excerpt from content.
		$content = wp_strip_all_tags( $post->post_content );
		$content = str_replace( "\n", ' ', $content );
		$content = preg_replace( '/\s+/', ' ', $content );

		return wp_trim_words( $content, 30, '...' );
	}

	/**
	 * Get post thumbnail URL.
	 *
	 * @param int $post_id The post ID.
	 * @return string|null Thumbnail URL or null.
	 */
	private function get_post_thumbnail( $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( $thumbnail_id ) {
			$thumbnail = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
			if ( $thumbnail && isset( $thumbnail[0] ) ) {
				return $thumbnail[0];
			}
		}

		return null;
	}
}
