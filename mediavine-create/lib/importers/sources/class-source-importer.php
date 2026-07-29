<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\MV_Recipe_Importer;

/**
 * Contract for registered recipe source importers.
 *
 * Per-plugin serialize/ratings/reimport quirks live on implementing classes so
 * MV_Recipe_Importer::bulk_import and ::reimport stay registry-driven.
 */
interface Source_Importer {

	/**
	 * Registry slug for this importer (e.g. 'cookbook', 'tasty').
	 *
	 * @return string
	 */
	public static function get_slug();

	/**
	 * Serialize a found-recipe row into Create card data.
	 *
	 * May return a single recipe array, a list of recipe arrays when
	 * returns_multiple() is true, or false/empty to skip.
	 *
	 * @param array $found_recipe Recipe stub from find (original_id, title, …).
	 * @return array|array[]|false
	 */
	public static function serialize_found( $found_recipe );

	/**
	 * Whether serialize_found() returns a list of recipes for one found row.
	 *
	 * @return bool
	 */
	public static function returns_multiple();

	/**
	 * Collect native ratings after a recipe has been stored.
	 *
	 * @param array              $stored_recipe Stored Create recipe (has id).
	 * @param array              $serialized    Serialized source recipe.
	 * @param array              $found_recipe  Original find stub.
	 * @param MV_Recipe_Importer $context       Importer host (ratings helpers).
	 * @return array|false
	 */
	public static function get_import_ratings( $stored_recipe, $serialized, $found_recipe, MV_Recipe_Importer $context );

	/**
	 * Whether to also run the Simple Recipes Pro ratings double-check.
	 *
	 * @return bool
	 */
	public static function should_check_srp_ratings();

	/**
	 * Post ID used for the SRP ratings double-check.
	 *
	 * @param array $stored_recipe Stored Create recipe.
	 * @return int|string|null
	 */
	public static function get_srp_ratings_post_id( $stored_recipe );

	/**
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport();

	/**
	 * Import a list of found-recipe stubs for this source.
	 *
	 * @param array              $found_recipes List of find stubs.
	 * @param MV_Recipe_Importer $context       Importer host.
	 * @return array Result rows for the REST response.
	 */
	public static function bulk_import_recipes( $found_recipes, MV_Recipe_Importer $context );

	/**
	 * Run reimport serialization for this source.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function reimport( $api_data );
}
