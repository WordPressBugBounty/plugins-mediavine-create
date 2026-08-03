<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\MV_Recipe_Importer;

/**
 * Importer for the GetMeCooking Recipe Template plugin.
 *
 * GetMeCooking stores each recipe as a `gmc_recipe` post with two child post
 * types related via post_parent: `gmc_recipeingredient` (ingredient line in
 * post_title, optional note in post_content, optional quantity/unit/group/
 * optional-flag postmeta) and `gmc_recipestep` (instruction text in
 * post_content, optional `_thumbnail_id`). Recipe-level fields live in
 * `gmc-*` postmeta. Blog posts embed a recipe with the `[gmc_recipe {ID}]`
 * shortcode and mirror the ID in a `gmc_local_id` postmeta row.
 *
 * Structured labels (Meal type / Allergy / Misc / Occasion / Region) are WP
 * taxonomies (`gmc_course`, `gmc_allergy`, `gmc_misc`, `gmc_occasion`,
 * `gmc_region`, `gmc_dietary`). Course maps to Create category, region to
 * cuisine and dietary to suitable_for_diet; the full term lists are retained
 * under metadata.import.source_taxonomies.
 *
 * Child ordering uses menu_order, but roughly a third of real-world recipes
 * have a flat menu_order (all rows share one value), where creation order
 * (post ID) preserves the intended sequence — hence ORDER BY menu_order, ID.
 */
class Import_GetMeCooking extends Abstract_Source_Importer {

	/**
	 * GetMeCooking's recipe post type.
	 *
	 * @var string
	 */
	private static $post_type = 'gmc_recipe';

	/**
	 * Course terms in descending priority, for picking a single card category.
	 *
	 * A Create card carries one `category`, but GetMeCooking lets a recipe hold
	 * several courses — 92% of a real 800-recipe library does, averaging 3.3 —
	 * and the source offers no tie-break: `term_order` is 0 on every row, and
	 * `populate_taxonomies()` seeds all 13 terms alphabetically in one batch, so
	 * name, term_id and term_taxonomy_id order all collapse to the same
	 * uninformative sequence. Dish-form terms ("what it is") therefore beat
	 * meal-role terms, which beat the vague catch-alls; before this ranking,
	 * "English Cream of Sorrel Soup" filed under Lunch and "Pan-Fried Sea Bass
	 * Fillets" under Lunch rather than Salad.
	 *
	 * Keyed on the plugin's English seed names. GetMeCooking translates them, so
	 * on a non-English site nothing here matches and we fall back to the first
	 * term — the behaviour that predates this ranking. Unranked terms (a hand-
	 * added course) likewise only win when no ranked term is present.
	 *
	 * @var string[]
	 */
	private static $course_priority = [
		// Narrow dish forms — picking one of these is a deliberate act.
		'condiment',
		'beverage',
		'dessert',
		'bread',
		'soup',
		// Main Dish outranks Salad because authors also apply Salad to mean
		// "serve alongside" — that mis-filed a pan-fried sea bass fillet as a
		// salad. Soup stays above it (a soup tagged Main Dish is still a soup)
		// but below Bread, which stops scones tagged "Soup" landing there.
		'main dish',
		'salad',
		'breakfast',
		'side dish',
		'starter',
		'appetizer',
		// Catch-alls carrying almost no signal: 304 of 776 recipes led with
		// Lunch purely because it sorts early.
		'lunch',
		'snack',
	];

	/**
	 * GetMeCooking dietary terms to schema.org RestrictedDiet values.
	 *
	 * Create's `suitable_for_diet` holds a single value while `gmc_dietary` is
	 * multi-select, so array order doubles as precedence: the most restrictive
	 * label wins. Vegan always co-occurs with Vegetarian in practice, and
	 * Vegetarian is the least informative here (57% of a real library carries
	 * it), so it ranks below Gluten Free. Every term is still preserved under
	 * metadata.import.source_taxonomies.
	 *
	 * @var array<string, string>
	 */
	private static $diet_map = [
		'vegan'       => 'VeganDiet',
		'gluten free' => 'GlutenFreeDiet',
		'vegetarian'  => 'VegetarianDiet',
		'diabetic'    => 'DiabeticDiet',
	];

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'getmecooking';
	}

	/**
	 * Serialize a found-recipe row into Create card data.
	 *
	 * @param array $found_recipe Recipe stub from find.
	 * @return array|false
	 */
	public static function serialize_found( $found_recipe ) {
		return static::serializer( $found_recipe );
	}

	/**
	 * Skip stubs whose source recipe has been deleted since the scan.
	 *
	 * @return bool
	 */
	public static function skip_empty_serialization() {
		return true;
	}

	/**
	 * GetMeCooking predates Simple Recipes Pro coexistence; skip the SRP probe.
	 *
	 * @return bool
	 */
	public static function should_check_srp_ratings() {
		return false;
	}

	/**
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport() {
		return true;
	}

	/**
	 * Abort reimport when the source recipe no longer exists.
	 *
	 * Without this guard an empty serialization would reach
	 * store_found_recipe() and wipe the card's ingredients and times.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function prepare_reimport_data( $api_data ) {
		if ( empty( $api_data['original_id'] ) ) {
			return false;
		}
		$post = get_post( $api_data['original_id'] );
		if ( ! $post || self::$post_type !== $post->post_type ) {
			return false;
		}
		return $api_data;
	}

	/**
	 * Find GetMeCooking recipes to import.
	 *
	 * @param int|null $post_id Scan a single post's content when provided.
	 * @return array List of found-recipe stubs.
	 */
	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$post_type = self::$post_type;

		$data = [];

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return $data;
			}

			if ( $post_type === $post->post_type ) {
				$data[] = [
					'original_id' => $post->ID,
					'title'       => $post->post_title,
				];
				return $data;
			}

			preg_match_all( '/\[gmc_recipe (\d+)\]/', $post->post_content, $matches );
			if ( empty( $matches[1] ) ) {
				return $data;
			}
			foreach ( $matches[1] as $recipe_id ) {
				$recipe = get_post( $recipe_id );
				if ( ! $recipe || $post_type !== $recipe->post_type ) {
					continue;
				}
				$data[] = [
					'original_id' => $recipe->ID,
					'title'       => $recipe->post_title,
				];
			}

			return $data;
		}

		$statement = "SELECT p.ID as original_id, p.post_title as title,
			IFNULL((SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} cp ON cp.ID = pm.post_id
				WHERE pm.meta_key='gmc_local_id' AND pm.meta_value = p.ID AND cp.post_status = 'publish'
				LIMIT 1), FALSE) as canonical_post_id
			FROM {$wpdb->posts} p
			WHERE p.post_type='{$post_type}' AND p.post_status = 'publish'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; no external values interpolated
		return $wpdb->get_results( $statement, ARRAY_A );
	}

	/**
	 * Published post embedding a recipe, resolved via its gmc_local_id meta.
	 *
	 * @param int $recipe_id gmc_recipe post ID.
	 * @return int Post ID, or 0 when the recipe is not embedded anywhere.
	 */
	public static function canonical_post_id( $recipe_id ) {
		global $wpdb;

		$recipe_id = absint( $recipe_id );
		$statement = "SELECT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key='gmc_local_id' AND pm.meta_value = {$recipe_id} AND p.post_status = 'publish'
			LIMIT 1";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; recipe id coerced via absint()
		return (int) $wpdb->get_var( $statement );
	}

	/**
	 * Build the Create card array from GetMeCooking post data.
	 *
	 * @param array $api_data Found stub or reimport payload (needs original_id).
	 * @return array|false False when the source recipe no longer exists.
	 */
	public static function serializer( $api_data ) {
		$recipe_id = ! empty( $api_data['original_id'] ) ? (int) $api_data['original_id'] : 0;
		if ( ! $recipe_id ) {
			return false;
		}

		$post = get_post( $recipe_id );
		if ( ! $post || self::$post_type !== $post->post_type ) {
			return false;
		}

		$meta = get_post_meta( $recipe_id );

		$canonical_post_id = ! empty( $api_data['canonical_post_id'] ) ? (int) $api_data['canonical_post_id'] : self::canonical_post_id( $recipe_id );

		$formatted = [
			'original_id'       => $recipe_id,
			'title'             => html_entity_decode( $post->post_title ),
			'description'       => self::parse_description( $post, $meta ),
			'canonical_post_id' => $canonical_post_id,
			'instructions'      => self::parse_instructions( $recipe_id ),
			'total_time'        => 0,
		];

		$prep_time = self::time_in_seconds( $meta, 'gmc-prep-time-hours', 'gmc-prep-time-mins' );
		if ( $prep_time ) {
			$formatted['prep_time']       = $prep_time;
			$formatted['prep_time_label'] = __( 'Prep Time', 'mediavine-create' );
			$formatted['total_time']     += $prep_time;
		}

		$cook_time = self::time_in_seconds( $meta, 'gmc-cooking-time-hours', 'gmc-cooking-time-mins' );
		if ( $cook_time ) {
			$formatted['active_time']       = $cook_time;
			$formatted['active_time_label'] = __( 'Cook Time', 'mediavine-create' );
			$formatted['total_time']       += $cook_time;
		}

		if ( isset( $meta['gmc-nr-servings'][0] ) && '' !== trim( $meta['gmc-nr-servings'][0] ) ) {
			$formatted['yield'] = trim( $meta['gmc-nr-servings'][0] );
		}

		if ( ! empty( $meta['_thumbnail_id'][0] ) ) {
			$formatted['thumbnail_id'] = (int) $meta['_thumbnail_id'][0];
		}

		$source_type = isset( $meta['gmc-source-type'][0] ) ? trim( $meta['gmc-source-type'][0] ) : '';
		$source_name = isset( $meta['gmc-source-name'][0] ) ? trim( $meta['gmc-source-name'][0] ) : '';
		$source_url  = isset( $meta['gmc-source-url'][0] ) ? trim( $meta['gmc-source-url'][0] ) : '';

		if ( 'Author' === $source_type && $source_name ) {
			$formatted['author'] = $source_name;
			if ( $source_url ) {
				// The author field is plain text; keep the source link in notes.
				$formatted['notes'] = self::attribution_note( $source_name, $source_url );
			}
		} else {
			$user = get_userdata( $post->post_author );
			if ( $user ) {
				$formatted['author'] = $user->display_name;
			}
			if ( $source_name ) {
				// Book/Website/Magazine attribution would otherwise be lost.
				$formatted['notes'] = self::attribution_note( $source_name, $source_url );
			}
		}

		$ingredient_sections = self::parse_ingredients( $recipe_id );
		if ( $ingredient_sections ) {
			$formatted['ingredient_sections'] = $ingredient_sections;
		}

		$source_taxonomies = self::get_source_taxonomies( $recipe_id );
		if ( $source_taxonomies ) {
			// Preserve full term lists for fields Create doesn't model yet
			// (allergy / misc / occasion; multi-value course and dietary).
			// Mapped onto category/cuisine/diet where we have a column today.
			$formatted['import_meta'] = [
				'source_taxonomies' => $source_taxonomies,
			];
			if ( ! empty( $source_taxonomies['course'] ) ) {
				$formatted['category'] = self::highest_ranked(
					$source_taxonomies['course'],
					self::$course_priority
				);
			}
			// gmc_region is single-select in practice: every recipe in an
			// 800-card library carried exactly one term.
			if ( ! empty( $source_taxonomies['region'][0] ) ) {
				$formatted['cuisine'] = $source_taxonomies['region'][0];
			}
			if ( ! empty( $source_taxonomies['dietary'] ) ) {
				$diet = self::highest_ranked(
					$source_taxonomies['dietary'],
					array_keys( self::$diet_map ),
					false
				);
				if ( null !== $diet ) {
					$formatted['suitable_for_diet'] = self::$diet_map[ self::normalize_term( $diet ) ];
				}
			}
		}

		return $formatted;
	}

	/**
	 * The first of $names matching $ranking, in ranking order.
	 *
	 * Used to collapse a multi-select GMC taxonomy onto a single-value Create
	 * column. Terms absent from $ranking never beat one that is present.
	 *
	 * @param string[] $names        Term names attached to the recipe.
	 * @param string[] $ranking      Normalized term names, most preferred first.
	 * @param bool     $fall_back    Return $names[0] when nothing ranks, rather
	 *                               than null. Appropriate where any value beats
	 *                               none (category), not where a wrong value is
	 *                               worse than none (diet).
	 * @return string|null The winning term name as it appears in $names.
	 */
	private static function highest_ranked( array $names, array $ranking, $fall_back = true ) {
		$normalized = [];
		foreach ( $names as $name ) {
			$normalized[ self::normalize_term( $name ) ] = $name;
		}

		foreach ( $ranking as $ranked ) {
			if ( isset( $normalized[ $ranked ] ) ) {
				return $normalized[ $ranked ];
			}
		}

		return $fall_back && isset( $names[0] ) ? $names[0] : null;
	}

	/**
	 * Term name reduced to a comparable key.
	 *
	 * GMC content arrives HTML-encoded from the database — real recipe titles
	 * contain `&#038;` — so decode before folding case, in case a site's term
	 * names picked up the same treatment.
	 *
	 * @param string $name Raw term name.
	 * @return string
	 */
	private static function normalize_term( $name ) {
		return strtolower( trim( html_entity_decode( $name, ENT_QUOTES ) ) );
	}

	/**
	 * GMC taxonomy names attached to a recipe, keyed by Create-friendly slug.
	 *
	 * Reads term tables directly so this works even when the GetMeCooking
	 * plugin is deactivated (taxonomies unregistered but rows remain).
	 *
	 * @param int $recipe_id gmc_recipe post ID.
	 * @return array<string, string[]> Taxonomy slug => list of term names.
	 */
	private static function get_source_taxonomies( $recipe_id ) {
		global $wpdb;

		// GetMeCooking registers every taxonomy twice against gmc_recipe — a
		// `gmc_`-prefixed name and a legacy unprefixed one — so terms live under
		// either depending on when the recipe was saved. Query both and merge.
		$taxonomies = [
			'gmc_course'   => 'course',
			'course'       => 'course',
			'gmc_allergy'  => 'allergy',
			'allergy'      => 'allergy',
			'gmc_misc'     => 'misc',
			'misc'         => 'misc',
			'gmc_occasion' => 'occasion',
			'occasion'     => 'occasion',
			'gmc_region'   => 'region',
			'region'       => 'region',
			'gmc_dietary'  => 'dietary',
			'dietary'      => 'dietary',
		];

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$args         = array_merge( [ (int) $recipe_id ], array_keys( $taxonomies ) );

		$statement = "SELECT tt.taxonomy, t.name
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id = %d AND tt.taxonomy IN ({$placeholders})
			ORDER BY tt.taxonomy, t.name";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from fixed taxonomy map; values bound via prepare()
		$prepared = $wpdb->prepare( $statement, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $taxonomies as $wp_tax => $key ) {
			$out[ $key ] = [];
		}
		foreach ( $rows as $row ) {
			$key           = $taxonomies[ $row['taxonomy'] ];
			$out[ $key ][] = $row['name'];
		}
		// The same term can be attached under both the prefixed and legacy name.
		foreach ( $out as &$names ) {
			$names = array_values( array_unique( $names ) );
		}
		unset( $names );

		return array_filter( $out );
	}

	/**
	 * Source-attribution notes line, linked when a source URL exists.
	 *
	 * @param string $source_name Source credit (person, site, book).
	 * @param string $source_url  Optional source URL.
	 * @return string
	 */
	private static function attribution_note( $source_name, $source_url ) {
		if ( $source_url ) {
			return sprintf(
				'<p>%s <a href="%s">%s</a>.</p>',
				esc_html__( 'Recipe from', 'mediavine-create' ),
				esc_url( $source_url ),
				esc_html( $source_name )
			);
		}
		return sprintf( '<p>%s %s.</p>', esc_html__( 'Recipe from', 'mediavine-create' ), esc_html( $source_name ) );
	}

	/**
	 * Card description: the gmc-description summary, else the recipe post body.
	 *
	 * @param \WP_Post $post Recipe post.
	 * @param array    $meta Recipe postmeta (get_post_meta full map).
	 * @return string
	 */
	private static function parse_description( $post, $meta ) {
		$description = isset( $meta['gmc-description'][0] ) ? trim( $meta['gmc-description'][0] ) : '';
		if ( '' === $description ) {
			// ~98% of real recipes also fill post_content; unlike gmc-description it may contain HTML.
			$description = trim( $post->post_content );
		}
		if ( '' === $description ) {
			return '';
		}
		return wpautop( $description );
	}

	/**
	 * Combine GMC's split hours/minutes meta into seconds.
	 *
	 * @param array  $meta      Recipe postmeta.
	 * @param string $hours_key Hours meta key (absent when zero).
	 * @param string $mins_key  Minutes meta key.
	 * @return int Seconds.
	 */
	private static function time_in_seconds( $meta, $hours_key, $mins_key ) {
		// (int) also handles the rare free-text values like "10 to 15".
		$hours   = isset( $meta[ $hours_key ][0] ) ? (int) $meta[ $hours_key ][0] : 0;
		$minutes = isset( $meta[ $mins_key ][0] ) ? (int) $meta[ $mins_key ][0] : 0;

		return max( 0, $hours * HOUR_IN_SECONDS + $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Child rows for a recipe in intended display order.
	 *
	 * menu_order alone is unreliable: ~30% of real recipes store the same
	 * menu_order on every ingredient, where post ID (creation order)
	 * preserves the sequence. ORDER BY menu_order, ID handles both patterns.
	 *
	 * @param int    $recipe_id  gmc_recipe post ID.
	 * @param string $child_type gmc_recipeingredient|gmc_recipestep.
	 * @return array[] Rows with ID, post_title, post_content.
	 */
	private static function get_children( $recipe_id, $child_type ) {
		global $wpdb;

		$statement = "SELECT ID, post_title, post_content FROM {$wpdb->posts}
			WHERE post_type = %s AND post_parent = %d AND post_status = 'publish'
			ORDER BY menu_order, ID";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$prepared = $wpdb->prepare( $statement, [ $child_type, $recipe_id ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $prepared, ARRAY_A );
	}

	/**
	 * Build ingredient_sections from gmc_recipeingredient children.
	 *
	 * @param int $recipe_id gmc_recipe post ID.
	 * @return array[]|false Single-section list, or false when no ingredients.
	 */
	private static function parse_ingredients( $recipe_id ) {
		$rows = self::get_children( $recipe_id, 'gmc_recipeingredient' );
		if ( empty( $rows ) ) {
			return false;
		}

		$section = [ 'ingredients' => [] ];

		foreach ( $rows as $row ) {
			$name = trim( html_entity_decode( $row['post_title'] ) );
			if ( '' === $name ) {
				continue;
			}

			$quantity = trim( (string) get_post_meta( $row['ID'], 'gmc-ingredientquantity', true ) );
			$unit     = trim( (string) get_post_meta( $row['ID'], 'gmc-ingredientmeasurement', true ) );
			$group    = trim( (string) get_post_meta( $row['ID'], 'gmc-ingredientgroup', true ) );
			$optional = 'Y' === get_post_meta( $row['ID'], 'gmc-ingredientoptional', true );
			$note     = trim( $row['post_content'] );

			// Most rows carry the full line in post_title; a minority split
			// quantity/unit into meta with post_title holding just the name.
			$original_text = trim( implode( ' ', array_filter( [ $quantity, $unit, $name ] ) ) );
			if ( '' !== $note ) {
				$original_text .= ', ' . $note;
			}
			if ( $optional ) {
				$original_text .= ' (' . __( 'optional', 'mediavine-create' ) . ')';
			}

			$ingredient = [
				'name'          => $name,
				'original_text' => $original_text,
			];
			if ( '' !== $quantity ) {
				$ingredient['quantity'] = $quantity;
			}
			if ( '' !== $unit ) {
				$ingredient['unit'] = $unit;
			}
			if ( '' !== $group ) {
				$ingredient['group'] = $group;
			}

			$section['ingredients'][] = $ingredient;
		}

		if ( empty( $section['ingredients'] ) ) {
			return false;
		}

		return [ $section ];
	}

	/**
	 * Build the instructions HTML from gmc_recipestep children.
	 *
	 * Step post_title is a discardable "{recipe title} step" placeholder; the
	 * instruction text is plain-text post_content, sometimes multi-paragraph.
	 *
	 * @param int $recipe_id gmc_recipe post ID.
	 * @return string `<ol>` HTML, empty string when the recipe has no steps.
	 */
	private static function parse_instructions( $recipe_id ) {
		$rows = self::get_children( $recipe_id, 'gmc_recipestep' );
		if ( empty( $rows ) ) {
			return '';
		}

		$instructions = '<ol>';
		foreach ( $rows as $row ) {
			$text = trim( $row['post_content'] );
			if ( '' === $text ) {
				continue;
			}

			$image_id        = get_post_meta( $row['ID'], '_thumbnail_id', true );
			$image_shortcode = $image_id ? ' [mv_img id="' . (int) $image_id . '"]' : '';

			$instructions .= '<li>' . wpautop( $text ) . $image_shortcode . '</li>';
		}
		$instructions .= '</ol>';

		if ( '<ol></ol>' === $instructions ) {
			return '';
		}

		return $instructions;
	}

	/**
	 * Replace `[gmc_recipe {id}]` shortcodes with the imported Create card.
	 *
	 * @param array $api_data Row from bulk_replace (id, original_id).
	 * @return array
	 */
	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		if ( empty( $api_data['original_id'] ) || empty( $api_data['id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		global $wpdb;
		$models = \Mediavine\MV_DBI::get_models( [ 'mv_creations' ] );

		$recipe = $models->mv_creations->find_one( $api_data['id'] );
		if ( empty( $recipe ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}
		if ( ! empty( $recipe->canonical_post_id ) ) {
			$api_data['canonical_post_id'] = $recipe->canonical_post_id;
		}
		if ( ! empty( $recipe->original_post_id ) ) {
			$api_data['original_post_id'] = $recipe->original_post_id;
		}

		$needle    = '[gmc_recipe ' . (int) $api_data['original_id'] . ']';
		$thumbnail = wp_get_attachment_url( $recipe->thumbnail_id );
		// Escape title/thumbnail — 28 of 813 recipes in the client dump have " in the title.
		$mv_shortcode = MV_Recipe_Importer::format_mv_create_shortcode(
			$recipe->id,
			$recipe->title,
			$thumbnail ? $thumbnail : ''
		);
		$mv_block     = MV_Recipe_Importer::get_gutenberg_block_from_shortcode( $mv_shortcode );

		$statement = "SELECT ID as post_id, post_content as original_content
			FROM {$wpdb->posts}
			WHERE post_type IN ('post', 'page')
				AND post_status = 'publish'
				AND post_content LIKE '%%%s%%'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$prepared = $wpdb->prepare( $statement, $needle );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$posts = $wpdb->get_results( $prepared, ARRAY_A );

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			$replace_with = $mv_shortcode;
			if ( function_exists( 'has_blocks' ) && has_blocks( $post['original_content'] ) ) {
				$replace_with = $mv_block;
			}

			$updated_content = str_replace( $needle, $replace_with, $post['original_content'] );
			if ( $updated_content === $post['original_content'] ) {
				continue;
			}

			$updated_post_id = wp_update_post(
				[
					'ID'           => $post['post_id'],
					'post_content' => $updated_content,
				], true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['errors'] = $updated_post_id->get_error_messages();
				$api_data['error']  = $error_message;
				continue;
			}

			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;
	}
}
