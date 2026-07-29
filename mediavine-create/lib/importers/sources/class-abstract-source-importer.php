<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\MV_Recipe_Importer;

/**
 * Shared bulk_import / reimport flow for source importers.
 *
 * Subclasses override serialize_found, ratings hooks, and reimport helpers for
 * per-plugin quirks. New importers should extend this and register in
 * MV_Recipe_Importer::get_importers().
 */
abstract class Abstract_Source_Importer implements Source_Importer {

	/**
	 * Whether serialize_found() returns a list of recipes for one found row.
	 *
	 * @return bool
	 */
	public static function returns_multiple() {
		return false;
	}

	/**
	 * Collect native ratings after a recipe has been stored.
	 *
	 * @param array              $stored_recipe Stored Create recipe (has id).
	 * @param array              $serialized    Serialized source recipe.
	 * @param array              $found_recipe  Original find stub.
	 * @param MV_Recipe_Importer $context       Importer host (ratings helpers).
	 * @return array|false
	 */
	public static function get_import_ratings( $stored_recipe, $serialized, $found_recipe, MV_Recipe_Importer $context ) {
		return false;
	}

	/**
	 * Whether to also run the Simple Recipes Pro ratings double-check.
	 *
	 * @return bool
	 */
	public static function should_check_srp_ratings() {
		return true;
	}

	/**
	 * Post ID used for the SRP ratings double-check.
	 *
	 * @param array $stored_recipe Stored Create recipe.
	 * @return int|string|null
	 */
	public static function get_srp_ratings_post_id( $stored_recipe ) {
		if ( ! empty( $stored_recipe['original_post_id'] ) ) {
			return $stored_recipe['original_post_id'];
		}
		if ( ! empty( $stored_recipe['original_id'] ) ) {
			return $stored_recipe['original_id'];
		}
		return null;
	}

	/**
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport() {
		return false;
	}

	/**
	 * Whether an empty/false serialization should skip the found row.
	 *
	 * @return bool
	 */
	public static function skip_empty_serialization() {
		return false;
	}

	/**
	 * Mutate serialized data before store_found_recipe.
	 *
	 * @param array $serialized   Serialized recipe.
	 * @param array $found_recipe Original find stub.
	 * @return array
	 */
	public static function prepare_serialized( $serialized, $found_recipe ) {
		$serialized['importer'] = static::get_slug();
		return $serialized;
	}

	/**
	 * Attach native ratings onto the arrays that will be returned.
	 *
	 * @param array $stored_recipe Stored recipe (by ref).
	 * @param array $found_recipe  Found stub (by ref).
	 * @param array $ratings       Rating rows.
	 * @return void
	 */
	public static function assign_native_ratings( &$stored_recipe, &$found_recipe, $ratings ) {
		$stored_recipe['ratings'] = $ratings;
	}

	/**
	 * Build the per-recipe REST result after store + ratings.
	 *
	 * @param array $stored_recipe Stored Create recipe.
	 * @param array $serialized    Serialized source recipe.
	 * @param array $found_recipe  Original find stub.
	 * @return array
	 */
	public static function format_result( $stored_recipe, $serialized, $found_recipe ) {
		return MV_Recipe_Importer::verify_required_fields( $stored_recipe, $serialized );
	}

	/**
	 * Prepare api_data before reimport. Return false to abort.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function prepare_reimport_data( $api_data ) {
		return $api_data;
	}

	/**
	 * When true, reimport never publishes even if publish=true was requested.
	 *
	 * @return bool
	 */
	public static function reimport_forces_unpublished() {
		return false;
	}

	/**
	 * Run reimport serialization for this source.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function reimport( $api_data ) {
		return static::serialize_found( $api_data );
	}

	/**
	 * Import a list of found-recipe stubs for this source.
	 *
	 * @param array              $found_recipes List of find stubs.
	 * @param MV_Recipe_Importer $context       Importer host.
	 * @return array Result rows for the REST response.
	 */
	public static function bulk_import_recipes( $found_recipes, MV_Recipe_Importer $context ) {
		$results = [];

		foreach ( $found_recipes as $found_recipe ) {
			try {
				$imported = static::import_found_recipe( $found_recipe, $context );
				foreach ( $imported as $row ) {
					$results[] = $row;
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					\Mediavine\Create\Help::log(
						'Create importer error (' . static::get_slug() . '): ' . $e->getMessage()
					);
				}
				$found_recipe['error'] = $e->getMessage();
				$found_recipe['id']    = null;
				$results[]             = $found_recipe;
			}
		}

		return $results;
	}

	/**
	 * Import one found-recipe stub (may expand to multiple Create cards).
	 *
	 * Each serialized item is stored in its own try/catch so a failure on the
	 * Nth Easy Recipe (returns_multiple) card does not drop result rows for
	 * cards 1..N-1 that were already persisted.
	 *
	 * @param array              $found_recipe Find stub.
	 * @param MV_Recipe_Importer $context      Importer host.
	 * @return array Result rows.
	 */
	protected static function import_found_recipe( $found_recipe, MV_Recipe_Importer $context ) {
		$serialized = static::serialize_found( $found_recipe );

		if ( static::skip_empty_serialization() && ! $serialized ) {
			return [];
		}

		$items = static::returns_multiple() ? $serialized : [ $serialized ];
		if ( ! is_array( $items ) ) {
			$items = [ $items ];
		}

		$results = [];
		foreach ( $items as $item ) {
			try {
				$results[] = static::store_and_rate( $item, $found_recipe, $context );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					\Mediavine\Create\Help::log(
						'Create importer error (' . static::get_slug() . '): ' . $e->getMessage()
					);
				}
				$error_row          = $found_recipe;
				$error_row['error'] = $e->getMessage();
				$error_row['id']    = null;
				$results[]          = $error_row;
			}
		}
		return $results;
	}

	/**
	 * Store a serialized recipe, import ratings, and format the result row.
	 *
	 * @param array              $serialized   Serialized source recipe.
	 * @param array              $found_recipe Original find stub.
	 * @param MV_Recipe_Importer $context      Importer host.
	 * @return array
	 */
	protected static function store_and_rate( $serialized, $found_recipe, MV_Recipe_Importer $context ) {
		$serialized    = static::prepare_serialized( $serialized, $found_recipe );
		$stored_recipe = $context->store_found_recipe( $serialized );

		$original_id = null;
		if ( ! empty( $found_recipe['original_id'] ) ) {
			$original_id = $found_recipe['original_id'];
		} elseif ( ! empty( $serialized['original_id'] ) ) {
			$original_id = $serialized['original_id'];
		}
		if ( null !== $original_id ) {
			$stored_recipe['original_id'] = $original_id;
		}

		$ratings = static::get_import_ratings( $stored_recipe, $serialized, $found_recipe, $context );
		if ( $ratings ) {
			$context->store_found_ratings( $ratings );
			static::assign_native_ratings( $stored_recipe, $found_recipe, $ratings );
		}

		if ( static::should_check_srp_ratings() ) {
			$srp_post_id = static::get_srp_ratings_post_id( $stored_recipe );
			if ( $srp_post_id ) {
				$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $srp_post_id, $stored_recipe['id'] );
				if ( $maybe_srp_ratings ) {
					$context->store_found_ratings( $maybe_srp_ratings );
					$stored_recipe['ratings'] = $maybe_srp_ratings;
				}
			}
		}

		return static::format_result( $stored_recipe, $serialized, $found_recipe );
	}
}
