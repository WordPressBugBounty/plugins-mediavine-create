<?php

namespace Mediavine\Create;

class JSON_LD {

	public static $instance = null;

	/**
	 * @var JSON_LD_Types
	 */
	private $json_ld_types;

	/**
	 * @var JSON_LD_Runtime
	 */
	private $json_ld_runtime;

	/**
	 * Singleton factory.
	 *
	 * @return JSON_LD|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Singleton __construct equivalent.
	 *
	 * @return void
	 */
	public function init() {
		$this->json_ld_types   = JSON_LD_Types::get_instance();
		$this->json_ld_runtime = JSON_LD_Runtime::get_instance();
	}

	/**
	 * Adds the JSON-LD property based on the type of schema property.
	 *
	 * @param string $schema_type Type of schema property to build
	 * @param array $schema_prop Schema property to add to JSON-LD
	 * @param array $schema_map Map of schema properties with their associated types
	 * @param array $creation Creations data
	 * @param array $json_ld Current JSON-LD data
	 * @param array $schema_flags Flags to manipulate schema property output
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_based_on_type( $schema_type, $schema_prop, $schema_map, $creation, $json_ld, $schema_flags ) {
		switch ( $schema_type ) {
			case 'author':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_author( $json_ld, $creation[ $schema_map ], $schema_prop, $creation, $schema_flags );
				}
				break;
			case 'date':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_date( $json_ld, $creation[ $schema_map ], $schema_prop, $creation );
				}
				break;
			case 'duration':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_duration( $json_ld, $creation[ $schema_map ], $schema_prop, $creation );
				}
				break;
			case 'image':
				if ( isset( $creation[ $schema_map['haystack'] ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_image( $json_ld, $creation[ $schema_map['haystack'] ], $schema_prop, $schema_map, $creation );
				}
				break;
			case 'list':
				if ( empty( $schema_map['haystack'] ) ||
					empty( $schema_map['needle'] ) ||
					! isset( $creation[ $schema_map['haystack'] ] ) ||
					! is_array( $creation[ $schema_map['haystack'] ] )
				) {
					break;
				}
				$json_ld = $this->json_ld_types->add_json_ld_list( $json_ld, $creation[ $schema_map['haystack'] ], $schema_prop, $schema_map, $creation );
				break;
			case 'nutrition':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_nutrition( $json_ld, $creation[ $schema_map ], $schema_prop, $creation );
				}
				break;
			case 'rating':
				$rating_value = [];
				foreach ( $schema_map as $key => $map ) {
					if ( ! empty( $creation[ $map ] ) && '0.0' !== $creation[ $map ] ) {
						$rating_value[ $key ] = $creation[ $map ];
					}
				}
				if ( ! empty( $rating_value ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_rating( $json_ld, $rating_value, $schema_prop, $creation );
				}
				break;
			case 'step':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_step( $json_ld, $creation[ $schema_map ], $schema_prop, $creation, $schema_flags );
				}
				break;
			case 'string':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_string( $json_ld, $creation[ $schema_map ], $schema_prop, $creation, $schema_flags );
				}
				break;
			case 'integer':
				if ( isset( $creation[ $schema_map ] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_integer( $json_ld, $creation[ $schema_map ], $schema_prop, $creation, $schema_flags );
				}
				break;
			case 'video':
				if ( isset( $creation['video'] ) || isset( $creation['external_video'] ) ) {
					$json_ld = $this->json_ld_types->add_json_ld_video( $json_ld, $creation['video'], $creation['external_video'], $creation );
				}
				break;
			case 'item_list':
				$json_ld = $this->json_ld_types->add_json_ld_item_list( $json_ld, $creation['list_items'], $schema_prop, $creation );
				break;
			default:
				break;
		}

		return $json_ld;
	}

	/**
	 * Builds the JSON-LD for a card.
	 *
	 * @param array $creation Creation data
	 * @param string $type Type of card to build schema for
	 * @return array JSON-LD output
	 */
	public function build_json_ld( $creation, $type ) {
		// Actions to perform before building json_ld
		// Allows hooks to be added on a per type basis
		do_action( 'mv_create_before_json_ld_build', $creation, $type );
		do_action( 'mv_create_before_json_ld_build_' . $type, $creation );

		// Filter creation content for JSON LD
		$creation = apply_filters( 'mv_create_json_ld_build_creation', $creation, $type );
		$creation = apply_filters( 'mv_create_json_ld_build_creation_' . $type, $creation );

		$schema_types = apply_filters( 'mv_schema_types', $this->json_ld_types->get_schema_types(), $type, $creation );

		$json_ld = [
			'@context' => 'http://schema.org',
		];

		// Get type
		$json_ld = $this->json_ld_types->add_json_ld_type( $json_ld, $type, $schema_types );

		// If no type, we don't want to even attempt to render JSON-LD
		if ( false === $json_ld['@type'] ) {
			return false;
		}

		// TODO: There are way too many nested levels here. This should be refactored for readability.
		// Loop through each schema property and set correct value based on type
		if ( ! empty( $schema_types[ $type ]['properties'] ) && is_array( $schema_types[ $type ] ) ) {
			foreach ( $schema_types[ $type ]['properties'] as $schema_prop => $schema_data ) {

				// If schema_prop doesn't have data array, prop is map and data is type
				$schema_type  = $schema_data;
				$schema_map   = $schema_prop;
				$schema_flags = [];
				if ( is_array( $schema_data ) ) {
					// If no type then move on
					if ( ! isset( $schema_data['type'] ) ) {
						continue;
					}
					$schema_type = $schema_data['type'];
					if ( isset( $schema_data['map'] ) ) {
						$schema_map = $schema_data['map'];
					}
					if ( isset( $schema_data['flags'] ) ) {
						$schema_flags = $schema_data['flags'];
					}
				}

				// Don't do anything with missing value unless forced or is map data array
				if ( $schema_map && ! is_array( $schema_map ) && empty( $creation[ $schema_map ] ) && empty( $schema_flags['force'] ) ) {
					continue;
				}

				$json_ld = $this->add_json_ld_based_on_type( $schema_type, $schema_prop, $schema_map, $creation, $json_ld, $schema_flags );
			}
		}

		// Filter final JSON LD output
		$json_ld = apply_filters( 'mv_create_json_ld_output', $json_ld, $type, $creation );
		$json_ld = apply_filters( 'mv_create_json_ld_output_' . $type, $json_ld, $creation );

		return $json_ld;
	}
}
