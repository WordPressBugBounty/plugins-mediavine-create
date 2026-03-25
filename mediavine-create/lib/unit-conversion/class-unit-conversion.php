<?php
/**
 * Unit Conversion Service
 *
 * Handles unit conversion for recipe ingredients via the Create Studio API.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Unit Conversion service class.
 *
 * Calls the Create Studio batch conversion API, caches results
 * in the creation metadata JSON field, and exposes a REST endpoint
 * for manual conversion triggers.
 */
class Unit_Conversion extends Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Unit_Conversion|null
	 */
	private static $instance = null;

	/**
	 * Metadata key used to store conversion data.
	 *
	 * @var string
	 */
	const METADATA_KEY = 'unit_conversions';

	/**
	 * Metadata schema version.
	 *
	 * @var int
	 */
	const VERSION = 2;

	/**
	 * Map of Unicode fraction characters to their ASCII equivalents.
	 *
	 * @var string[]
	 */
	const UNICODE_FRACTION_MAP = [
		"\xC2\xBC" => '1/4',   // ¼
		"\xC2\xBD" => '1/2',   // ½
		"\xC2\xBE" => '3/4',   // ¾
		"\xE2\x85\x93" => '1/3',   // ⅓
		"\xE2\x85\x94" => '2/3',   // ⅔
		"\xE2\x85\x95" => '1/5',   // ⅕
		"\xE2\x85\x96" => '2/5',   // ⅖
		"\xE2\x85\x97" => '3/5',   // ⅗
		"\xE2\x85\x98" => '4/5',   // ⅘
		"\xE2\x85\x99" => '1/6',   // ⅙
		"\xE2\x85\x9A" => '5/6',   // ⅚
		"\xE2\x85\x9B" => '1/8',   // ⅛
		"\xE2\x85\x9C" => '3/8',   // ⅜
		"\xE2\x85\x9D" => '5/8',   // ⅝
		"\xE2\x85\x9E" => '7/8',   // ⅞
	];

	/**
	 * Units that are convertible from US customary to metric.
	 *
	 * @var string[]
	 */
	const CONVERTIBLE_UNITS = [
		'cups',
		'cup',
		'tbsp',
		'tablespoon',
		'tablespoons',
		'tsp',
		'teaspoon',
		'teaspoons',
		'fl oz',
		'fluid ounce',
		'fluid ounces',
		'oz',
		'ounce',
		'ounces',
		'lb',
		'lbs',
		'pound',
		'pounds',
		'pint',
		'pints',
		'quart',
		'quarts',
		'gallon',
		'gallons',
		'mL',
		'ml',
		'milliliter',
		'milliliters',
		'L',
		'l',
		'liter',
		'liters',
		'g',
		'gram',
		'grams',
		'kg',
		'kilogram',
		'kilograms',
	];

	/**
	 * Get the singleton instance.
	 *
	 * @return Unit_Conversion
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the service.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'mv_post_update_recipe_card', [ $this, 'invalidate_cache' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$namespace,
			'/creations/(?P<id>\d+)/conversions',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'handle_conversion_request' ],
					'permission_callback' => [ self::$api_services, 'permitted' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
			]
		);
	}

	/**
	 * Handle a REST request to trigger conversions for a creation.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_conversion_request( \WP_REST_Request $request ) {
		$creation_id = (int) $request->get_param( 'id' );

		$creation = self::$models_v2->mv_creations->find_one_by_id( $creation_id );

		if ( empty( $creation ) ) {
			return new \WP_Error(
				'not_found',
				__( 'Creation not found', 'mediavine' ),
				[ 'status' => 404 ]
			);
		}

		$result = $this->convert_creation( $creation_id, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'data' => $result,
			],
			200
		);
	}

	/**
	 * Convert ingredients for a creation, using cached data when available.
	 *
	 * @param int  $creation_id The creation ID.
	 * @param bool $force       Whether to bypass the cache.
	 * @return array|\WP_Error The conversion data or a WP_Error.
	 */
	public function convert_creation( $creation_id, $force = false ) {
		if ( ! $force && ! Plugin::is_dev_mode() ) {
			$cached = $this->get_cached_conversions( $creation_id );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		$ingredients = $this->get_convertible_ingredients( $creation_id );

		if ( empty( $ingredients ) ) {
			return [];
		}

		$response = $this->call_conversion_api( $creation_id, $ingredients );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$formatted = $this->format_api_response( $response );

		$this->save_conversions( $creation_id, $formatted );

		return $formatted;
	}

	/**
	 * Get ingredients from a creation that have convertible units.
	 *
	 * @param int $creation_id The creation ID.
	 * @return array List of ingredient data for the API.
	 */
	public function get_convertible_ingredients( $creation_id ) {
		$supplies = Supplies::get_creation_supplies( $creation_id, 'ingredients' );

		if ( empty( $supplies ) ) {
			return [];
		}

		$ingredients = [];

		foreach ( $supplies as $supply ) {
			$unit   = $supply->unit ?? '';
			$amount = isset( $supply->amount ) ? $this->normalize_amount( (string) $supply->amount ) : '';

			// DEBUG: Log raw supply fields for density conversion debugging
			error_log( sprintf(
				'[UnitConversion] Supply #%d — item: %s | amount: %s | unit: %s | original_text: %s',
				$supply->id ?? 0,
				var_export( $supply->item ?? null, true ),
				var_export( $supply->amount ?? null, true ),
				var_export( $supply->unit ?? null, true ),
				substr( strip_tags( $supply->original_text ?? '' ), 0, 80 )
			) );

			// Fall back to parsing original_text when unit column is empty.
			if ( empty( $unit ) && ! empty( $supply->original_text ) ) {
				$parsed = $this->parse_amount_unit( strip_tags( $supply->original_text ) );
				if ( $parsed ) {
					if ( '' === $amount ) {
						$amount = $parsed['amount'];
					}
					$unit = $parsed['unit'];
				}
			}

			if ( empty( $unit ) || ! $this->is_convertible_unit( $unit ) ) {
				continue;
			}

			if ( '' === $amount ) {
				continue;
			}

			$ingredient = [
				'id'     => (int) $supply->id,
				'amount' => $amount,
				'unit'   => $unit,
				'item'   => $supply->item ?? '',
			];

			if ( ! empty( $supply->max_amount ) ) {
				$ingredient['max_amount'] = $this->normalize_amount( (string) $supply->max_amount );
			} else {
				$ingredient['max_amount'] = null;
			}

			$ingredients[] = $ingredient;
		}

		return $ingredients;
	}

	/**
	 * Parse amount and unit from an ingredient text string.
	 *
	 * Handles formats like "2 cups flour", "1 1/2 tbsp sugar", "3/4 cup milk".
	 *
	 * @param string $text The ingredient text.
	 * @return array|null Array with 'amount' and 'unit' keys, or null if not parseable.
	 */
	private function parse_amount_unit( $text ) {
		$text = $this->normalize_amount( trim( $text ) );

		// Match leading amount (integer, fraction, mixed, decimal, or range) followed by a unit.
		// Examples: "2 cups", "1 1/2 tbsp", "3/4 cup", "1.5 oz", "2-3 cups"
		$amount_pattern = '(\d+(?:\s+\d+\/\d+|\.\d+|\/\d+)?(?:\s*[-–]\s*\d+(?:\s+\d+\/\d+|\.\d+|\/\d+)?)?)';
		$units_escaped  = array_map(
			function ( $u ) {
				return preg_quote( $u, '/' );
			},
			self::CONVERTIBLE_UNITS
		);
		// Sort longest first so "tablespoons" matches before "tbsp".
		usort(
			$units_escaped,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$units_pattern = '(' . implode( '|', $units_escaped ) . ')';

		if ( preg_match( '/^' . $amount_pattern . '\s+' . $units_pattern . '(?:\s|$)/i', $text, $m ) ) {
			return [
				'amount' => trim( $m[1] ),
				'unit'   => trim( $m[2] ),
			];
		}

		return null;
	}

	/**
	 * Normalize an amount string by replacing Unicode fractions with ASCII equivalents.
	 *
	 * Handles Unicode fraction characters (e.g., "½" → "1/2") and mixed amounts
	 * (e.g., "1½" → "1 1/2"). Fractional strings like "1/2" and "1 1/2" are passed
	 * through as-is since the API handles parsing.
	 *
	 * @param string $amount The amount string to normalize.
	 * @return string The normalized amount string.
	 */
	public function normalize_amount( $amount ) {
		$amount = trim( $amount );

		if ( '' === $amount ) {
			return $amount;
		}

		// Replace Unicode fraction characters with ASCII equivalents.
		$normalized = strtr( $amount, self::UNICODE_FRACTION_MAP );

		// If a Unicode fraction was appended directly to a whole number (e.g., "1½" became "11/2"),
		// insert a space between the whole number and the fraction.
		if ( $normalized !== $amount ) {
			$normalized = preg_replace( '/(\d)(\d+\/\d+)/', '$1 $2', $normalized );
		}

		return $normalized;
	}

	/**
	 * Check whether a unit string is convertible.
	 *
	 * @param string $unit The unit to check.
	 * @return bool
	 */
	public function is_convertible_unit( $unit ) {
		$normalized = strtolower( trim( $unit ) );

		foreach ( self::CONVERTIBLE_UNITS as $convertible ) {
			if ( strtolower( $convertible ) === $normalized ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Call the Create Studio batch conversion API.
	 *
	 * @param int   $creation_id The creation ID.
	 * @param array $ingredients The list of ingredients to convert.
	 * @return array|\WP_Error The API response data or a WP_Error.
	 */
	private function call_conversion_api( $creation_id, $ingredients ) {
		$response = Create_Studio_Client::request(
			'POST',
			'/conversions/batch',
			[
				'creation_id'   => $creation_id,
				'source_system' => 'us_customary',
				'ingredients'   => $ingredients,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['success'] ) ) {
			return new \WP_Error(
				'conversion_api_error',
				__( 'Unit conversion API request failed', 'mediavine' ),
				[
					'status'      => $response['status_code'] ?? 500,
					'api_response' => $response['data'] ?? null,
				]
			);
		}

		return $response['data'];
	}

	/**
	 * Format the API response for metadata storage.
	 *
	 * @param array $api_response The raw API response data.
	 * @return array Formatted conversion data for metadata.
	 */
	private function format_api_response( $api_response ) {
		$ingredients_map = [];

		if ( ! empty( $api_response['conversions'] ) ) {
			foreach ( $api_response['conversions'] as $conversion ) {
				if ( empty( $conversion['convertible'] ) ) {
					continue;
				}

				$id = (string) $conversion['id'];

				$ingredients_map[ $id ] = [
					'amount'     => $conversion['amount'],
					'unit'       => $conversion['unit'],
					'max_amount' => $conversion['max_amount'] ?? null,
				];
			}
		}

		return [
			'version'       => self::VERSION,
			'source_system' => 'us_customary',
			'generated_at'  => gmdate( 'c' ),
			'ingredients'   => $ingredients_map,
		];
	}

	/**
	 * Get cached conversion data from creation metadata.
	 *
	 * @param int $creation_id The creation ID.
	 * @return array|null The cached conversion data, or null if not found.
	 */
	public function get_cached_conversions( $creation_id ) {
		$creation = self::$models_v2->mv_creations->find_one_by_id( $creation_id );

		if ( empty( $creation ) || empty( $creation->metadata ) ) {
			return null;
		}

		$metadata = json_decode( $creation->metadata, true );

		if ( empty( $metadata[ self::METADATA_KEY ] ) ) {
			return null;
		}

		$cached = $metadata[ self::METADATA_KEY ];

		// Invalidate cache when VERSION changes (e.g., density-based conversions added)
		if ( empty( $cached['version'] ) || (int) $cached['version'] < self::VERSION ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Save conversion data to creation metadata.
	 *
	 * @param int   $creation_id    The creation ID.
	 * @param array $conversion_data The formatted conversion data.
	 * @return bool Whether the save succeeded.
	 */
	public function save_conversions( $creation_id, $conversion_data ) {
		$creation = self::$models_v2->mv_creations->find_one_by_id( $creation_id );

		if ( empty( $creation ) ) {
			return false;
		}

		$metadata = [];
		if ( ! empty( $creation->metadata ) ) {
			$metadata = json_decode( $creation->metadata, true );
			if ( ! is_array( $metadata ) ) {
				$metadata = [];
			}
		}

		$metadata[ self::METADATA_KEY ] = $conversion_data;

		self::$models_v2->mv_creations->update(
			[
				'id'       => $creation_id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);

		return true;
	}

	/**
	 * Invalidate the conversion cache when a recipe card is updated.
	 *
	 * Hooked to `mv_post_update_recipe_card`.
	 *
	 * @param object $creation The updated creation object.
	 * @return void
	 */
	public function invalidate_cache( $creation ) {
		if ( empty( $creation->id ) ) {
			return;
		}

		$existing = self::$models_v2->mv_creations->find_one_by_id( $creation->id );

		if ( empty( $existing ) || empty( $existing->metadata ) ) {
			return;
		}

		$metadata = json_decode( $existing->metadata, true );

		if ( ! is_array( $metadata ) || ! isset( $metadata[ self::METADATA_KEY ] ) ) {
			return;
		}

		unset( $metadata[ self::METADATA_KEY ] );

		self::$models_v2->mv_creations->update(
			[
				'id'       => $creation->id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);
	}
}
