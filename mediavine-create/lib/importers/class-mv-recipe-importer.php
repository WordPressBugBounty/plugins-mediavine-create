<?php

namespace Mediavine\Create\Importers;

use Mediavine\Create\Plugin;
use Mediavine\Create\API_Services;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Helpers\Collection;
use Mediavine\Create\Importers\Sources\Import_Cookbook;
use Mediavine\Create\Importers\Sources\Import_Easy_Recipe;
use Mediavine\Create\Importers\Sources\Import_Meal_Planner;
use Mediavine\Create\Importers\Sources\Import_Purr;
use Mediavine\Create\Importers\Sources\Import_Recipe_Maker;
use Mediavine\Create\Importers\Sources\Import_Simple_Recipes_Pro;
use Mediavine\Create\Importers\Sources\Import_Simple_Recipes_Pro_Latest;
use Mediavine\Create\Importers\Sources\Import_Simple_Recipes_Pro_Legacy;
use Mediavine\Create\Importers\Sources\Import_Tasty_Recipes;
use Mediavine\Create\Importers\Sources\Import_WP_Ultimate_Recipe;
use Mediavine\Create\Importers\Sources\Import_Yummly;
use Mediavine\Create\Importers\Sources\Import_Zip_Recipes;
use Mediavine\Create\Importers\Sources\Import_Ziplist;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Settings;
use Mediavine\MV_DBI;

class MV_Recipe_Importer extends Plugin {

	private static $instance    = null;
	private static $image_sizes = [
		'wprm-metadata-1_1',
		'wprm-metadata-4_3',
		'wprm-metadata-16_9',
		'thumbnail',
		'full',
	];

	/**
	 * REST API route namespace
	 *
	 * @var string
	 */
	public $api_route = 'mv-create';

	/**
	 * REST API version
	 *
	 * @var string
	 */
	public $api_version = 'v1';

	public $importer_api = null;
	public $importers    = [];
	public $mv_creations = null;

	public static $comment_ratings_retrieved = [];

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Get All WP Posts
	 * @return ARRAY array of WP Posts (I'm concerned about this conceptually if the length is particularly long)
	 */
	public function get_all_posts() {
		$args      = [
			'posts_per_page' => 100,
			'offset'         => 100,
		];
		$all_posts = get_posts( $args );

		return $all_posts;
	}

	function __construct() {
		$this->mv_creations = new \Mediavine\Create\Creations();
	}

	/**
	 * Importer registry keyed by slug.
	 *
	 * Built lazily rather than in the constructor: each `plugin_meta` name
	 * calls __(), and this importer is instantiated during plugin bootstrap
	 * (Importers::init), which runs before the `init` action where our
	 * textdomain is loaded. Translating at construction time trips
	 * WordPress 6.7+'s _load_textdomain_just_in_time notice. Consumers only
	 * read the registry during REST requests, long after `init`.
	 *
	 * @return array
	 */
	public function get_importers() {
		if ( ! empty( $this->importers ) ) {
			return $this->importers;
		}
		$this->importers = [
			'cookbook'                 => [
				'importer'    => Import_Cookbook::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Cookbook', 'mediavine' ) ],
			],
			'ez_recipes'               => [
				'importer'    => Import_Easy_Recipe::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'EasyRecipe/EasyRecipe Pro', 'mediavine' ) ],
			],
			'meal_planner'             => [
				'importer'    => Import_Meal_Planner::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Meal Planner Pro Recipes', 'mediavine' ) ],
			],
			'purr'                     => [
				'importer'    => Import_Purr::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Purr Recipe Cards', 'mediavine' ) ],
			],
			'recipe_maker'             => [
				'importer'    => Import_Recipe_Maker::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'WP Recipe Maker', 'mediavine' ) ],
			],
			'simple_recipe_pro'        => [
				'importer'    => Import_Simple_Recipes_Pro::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Simple Recipes Pro', 'mediavine' ) ],
			],
			'simple_recipe_pro_latest' => [
				'importer'    => Import_Simple_Recipes_Pro_Latest::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Simple Recipe Pro (Newer Versions)', 'mediavine' ) ],
			],
			'simple_recipe_pro_legacy' => [
				'importer'    => Import_Simple_Recipes_Pro_Legacy::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Simple Recipe Pro (Older Versions)', 'mediavine' ) ],
			],
			'tasty'                    => [
				'importer'    => Import_Tasty_Recipes::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'WP Tasty', 'mediavine' ) ],
			],
			'wp_ultimate'              => [
				'importer'    => Import_WP_Ultimate_Recipe::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'WP Ultimate Recipe', 'mediavine' ) ],
			],
			'yummly'                   => [
				'importer'    => Import_Yummly::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Yummly', 'mediavine' ) ],
			],
			'ziplist'                  => [
				'importer'    => Import_Ziplist::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'ZipList Recipes', 'mediavine' ) ],
			],
			'zip_recipes'              => [
				'importer'    => Import_Zip_Recipes::class,
				'recipes'     => [],
				'plugin_meta' => [ 'name' => __( 'Zip Recipes', 'mediavine' ) ],
			],
		];

		return $this->importers;
	}


	function init() {
		add_action( 'rest_api_init', [ $this, 'routes' ] );

		// Remove Recipe Maker hooks on init
		if ( class_exists( 'WPRM_Recipe_Saver' ) ) {
			remove_action( 'save_post', [ 'WPRM_Recipe_Saver', 'update_post' ] );
		}
	}


	/**
	 * Find check if ingredient section heading
	 *
	 * @param string $string
	 * @return section heading text || false
	 */
	public static function is_heading( $string ) {

		/*
		Matches lines that start with `!`
		Matches lines that start with `[b]` and end with `[/b]`
		Matches lines that start with `*` and end with `*`
		https://regex101.com/r/6JQUdF/2
		*/
		$re = '/^!\s?(.*)|^\[b\](.*)\[\/b\]$|^\*(.*)\*$/m';

		preg_match( $re, $string, $matches, PREG_OFFSET_CAPTURE, 0 );

		if ( empty( $matches ) ) {
			return false;
		}

		if ( isset( $matches[3] ) && isset( $matches[3][0] ) ) {
			return $matches[3][0];
		}

		if ( isset( $matches[2] ) && isset( $matches[2][0] ) ) {
			return $matches[2][0];
		}

		if ( isset( $matches[1] ) && isset( $matches[1][0] ) ) {
			return $matches[1][0];
		}

		return false;
	}

	public static function extract_floats( $string ) {
		return (float) filter_var( $string, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
	}

	public static function extract_digits( $string ) {
		return preg_replace( '/\D/', '', $string );
	}

	/**
	 * Replaces simple style shortcodes with HTML equivalents.
	 *
	 * Replaces the following shortcodes with their equivalent HTML tags:
	 * - `[b]`     => `<br />`
	 * - `[i][/i]` => `<em></em>`
	 * - `[b][/b]` => `<strong></strong>`
	 * - `[u][/u]` => `<u></u>`
	 *
	 * @param String $content the string needing shortcodes replaced.
	 * @return String $formatted the formatted string
	 **/
	public static function replace_simple_shortcodes( $content = '' ) {
		$pairs = [
			[
				'shortcodes' => [ '[br]' ],
				'tag'        => [ '<br>' ],
			],
			[
				'shortcodes' => [ '[i]', '[/i]' ],
				'tag'        => [ '<em>', '</em>' ],
			],
			[
				'shortcodes' => [ '[b]', '[/b]' ],
				'tag'        => [ '<strong>', '</strong>' ],
			],
			[
				'shortcodes' => [ '[u]', '[/u]' ],
				'tag'        => [ '<u>', '</u>' ],
			],
		];

		$formatted = $content;

		foreach ( $pairs as $pair ) {
			$formatted = str_replace( $pair['shortcodes'], $pair['tag'], $formatted );
		}

		return $formatted;
	}

	/**
	 * Get seconds from a string of human readable time, https://regex101.com/r/KpT7kV/2/
	 * FIXME: This doesn't handle things formatted like 0:25
	 * @param string $time_string, time string witho hours or minutes.
	 * @return integer of seconds
	 */
	public static function time_to_seconds( $time_string ) {
		$result = 0;
		// https://regex101.com/r/dg17bW/1/
		$colon_notation_pattern = '/(?:(\d{1,2}):)?(\d{1,2}):(\d{2})/';
		preg_match( $colon_notation_pattern, $time_string, $colon_matches );
		if ( $colon_matches ) {
			$days    = isset( $colon_matches[1] ) ? $colon_matches[1] : 0;
			$hours   = isset( $colon_matches[2] ) ? $colon_matches[2] : 0;
			$minutes = isset( $colon_matches[3] ) ? $colon_matches[3] : 0;
			$result  = ( $days * DAY_IN_SECONDS ) + ( $hours * HOUR_IN_SECONDS ) + ( $minutes * MINUTE_IN_SECONDS );
			return $result;
		}

		$re = '/(\d+)\s?(d|h|m|s)/i';
		preg_match_all( $re, $time_string, $matches, PREG_SET_ORDER, 0 );
		foreach ( $matches as $match ) {
			if ( isset( $match[0] ) && isset( $match[1] ) && isset( $match[2] ) ) {
				$letter = strtolower( $match[2] );
				$number = self::extract_floats( $match[1] );
				if ( 'd' === $letter ) {
					$result += ( $number * DAY_IN_SECONDS );
				}
				if ( 'h' === $letter ) {
					$result += ( $number * HOUR_IN_SECONDS );
				}
				if ( 'm' === $letter ) {
					$result += ( $number * MINUTE_IN_SECONDS );
				}
			}
		}
		return $result;
	}

	/**
	 * Process MPP, Yummly, and ZipList markdown-ish markup into HTML
	 *
	 * @param string $content
	 * @return string
	 */
	public static function markdownish_to_html( $item ) {
		$output   = $item;
		$link_ptr = '#\[(.*?)\| *(.*?)( (.*?))?\]#';
		preg_match_all( $link_ptr, $item, $matches );
		if ( isset( $matches[0] ) ) {
			$orig         = $matches[0];
			$substitution = preg_replace( $link_ptr, '<a href="$2"$3>$1</a>', str_replace( '"', '', $orig ) );
			$output       = str_replace( $orig, $substitution, $item );
		}

		// Must be an image.
		if ( '%http' === substr( $output, 0, 5 ) ) {
			$output = '<img src="' . esc_url( substr( $output, 1 ) ) . '">';
		}
		$output = preg_replace( '/(^|\s)\*([^\s\*][^\*]*[^\s\*]|[^\s\*])\*(\W|$)/', '$1<strong>$2</strong>$3', $output );
		$output = preg_replace( '/(^|\s)_([^\s_][^_]*[^\s_]|[^\s_])_(\W|$)/', '$1<em>$2</em>$3', $output );
		return $output;
	}

	public static function process_block_text( $content, $supports_images = false, $ordered_list = false ) {

		if ( $ordered_list ) {
			$exploded = array_map( 'trim', explode( PHP_EOL, $content ) );
			// filter out empty lines, and reorder array
			$exploded = array_values(
				array_filter( $exploded )
			);
		} else {
			$exploded = explode( PHP_EOL, $content );
		}

		$results_array = [];
		$open_tag      = '<p>';
		$close_tag     = '</p>';
		if ( $ordered_list ) {
			$open_tag  = '<li>';
			$close_tag = '</li>';
		}
		$section = [
			'heading' => '',
			'list'    => [],
		];
		foreach ( $exploded as &$line ) {
			$img_re = '/^%(.*)/';
			if ( preg_match( $img_re, $line, $matches ) ) {
				if ( $supports_images ) {
					$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $matches[1] );
					$line          = '[mv_img id="' . $attachment_id . '"]';
				} else {
					continue;
				}
			}
			$line          = MV_Recipe_Importer::markdownish_to_html( $line );
			$found_heading = MV_Recipe_Importer::is_heading( $line );
			if ( $found_heading ) {
				if ( ! empty( $section['heading'] ) || ! empty( $section['list'] ) ) {
					$results_array[] = $section;
				}
				$section = [
					'heading' => $found_heading,
					'list'    => [],
				];
				continue;
			}
			$section['list'][] = $line;
		}
		$results_array[] = $section;
		$result          = '';
		foreach ( $results_array as $section ) {
			if ( ! empty( $section['heading'] ) ) {
				$result .= "<h3>{$section['heading']}</h3>";
			}
			if ( ! empty( $section['list'] ) ) {
				if ( $ordered_list ) {
					$result .= '<ol>';
				}
				foreach ( $section['list'] as $item ) {
					if ( empty( $item ) ) {
						continue;
					}
					$result .= $open_tag . $item . $close_tag;
				}
				if ( $ordered_list ) {
					$result .= '</ol>';
				}
			}
		}
		$stripped_result = preg_replace( '!(\<br ?/?\>)([ ]|\s)+!i', '<br />', $result );
		return $stripped_result;
	}

	/**
	 * Takes a block of text and parses the HTML or non-HTML content for ingredients.
	 *
	 * @param String $content
	 * @return $ingredients_sections
	 */
	public static function parse_ingredients( $content ) {

		// return if there's nothing, obvs
		if ( empty( $content ) ) {
			return $content;
		}

		$heading = '';
		$section = [];
		// ensure that the content coming in is indeed HTML
		$html = mb_convert_encoding( '<section>' . $content . '</section>', 'HTML-ENTITIES', 'UTF-8' );

		// determine whether there is HTML to parse or not
		// this strips HTML tags and compares the result to see if there is any
		if ( strip_tags( $content, '<div><section>' ) === $content ) {
			// because the content is not iterable HTML, we need to strip it of any possible divs or sections before parsing
			$stripped = strip_tags( $content );
			// split the content by new line so we can iterate
			$lines = explode( PHP_EOL, $stripped );
			foreach ( $lines as $line ) {
				$parsed = [];
				$line   = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}
				// look for things that might be headers
				if ( strpos( $line, ':' ) || strpos( $line, '!' ) ) {
					$heading = $line;
					continue;
				}
				$parsed          = Ingredient_Parse::parse( $line );
				$parsed['group'] = $heading;
				if ( ! empty( $parsed['original_text'] ) ) {
					$section['ingredients'][] = $parsed;
				}
			}
		} else {

			$html = wpautop( $content, false );

			$html = Str::replace( "<br />\r", "</p>\n<p>", $html );
			$html = Str::replace( "<br />\n", "</p>\n<p>", $html );
			$html = Str::replace( '<br />', "</p>\n<p>", $html );
			// some li tags may have extra attributes in the HTML i.e <li data-renderer-mark="true">, let's remove it
			$html = preg_replace( '/<\s*li.*?>/', '<li>', $html );
			// <i> tags cause imports to act like they import, but don't actaulyl save the card
			$html = preg_replace( '/<\s*i.*?>/', '<em>', $html );
			$html = Str::replace( '</i>', '</em>', $html );
			// remove new lines before <li> and after what is inside. 
			$html = preg_replace( '/<li>\n(.*?)\n<\/li>/', '<li>$1</li>', $html );
			// let's find any tags that would trigger a heading inside of <li> tags and replace them with regular text
			//<em>
			$html = preg_replace( '/<li>(.*?)<em(?:.*?)>(.+?)<\/em>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<strong>
			$html = preg_replace( '/<li>(.*?)<strong(?:.*?)>(.+?)<\/strong>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<b>
			$html = preg_replace( '/<li>(.*?)<b(?:.*?)>(.+?)<\/b>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<h3>
			$html = preg_replace( '/<li>(.*?)<h3(?:.*?)>(.+?)<\/h3>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<h4>
			$html = preg_replace( '/<li>(.*?)<h4(?:.*?)>(.+?)<\/h4>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<p>
			$html = preg_replace( '/<li>(.*?)<p(?:.*?)>(.+?)<\/p>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			//<div>
			$html = preg_replace( '/<li>(.*?)<div(?:.*?)>(.+?)<\/div>(.*?)<\/li>/', "<li>$1$2$3</li>", $html );
			$html = Str::replace( '<p><strong>', '<strong>', $html );
			$html = Str::replace( '</strong></p>', '</strong>', $html );
			$html = Str::replace( '<p><em>', '<em>', $html );
			$html = Str::replace( '</em></p>', '</em>', $html );
			$html = '<ul>' . Str::replace( '<p>', '<li>', $html );
			$html = Str::replace( '</p>', '</li>', $html ) . '</ul>';
			// we know that this is valid HTML, so we can load it into DOMDocument
			$document = new \DOMDocument();
			@$document->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			foreach ( $document->getElementsByTagName( '*' ) as $node ) {
				$parsed = [];
				$tag    = $node->nodeName;
				$text   = trim( $node->nodeValue );
				$link   = '';

				// Links are stripped from node text values, but if there is a link child node, we can get the value
				// by specifically looking for `a` tags and `href` attributes on nodes
				if ( method_exists( $node, 'hasAttribute' ) && $node->hasAttribute( 'href' ) ) {
					$link   = $node->getAttribute( 'href' );
					$parent = $node->parentNode;
					// get the previous element's index and ingredient
					$previous            = count( $section['ingredients'] ) - 1;
					$previous_ingredient = $section['ingredients'][ $previous ];
					// <li>I am an ingredient and <a href="google.com">I have a link</a></li>
					if ( Str::contains( $node->nodeValue, $previous_ingredient['original_text'] ) ) {
						// here we replace the text that matches the link text with a markdown link (`I have a link` in the example above)
						$new_text = Str::replace( $node->nodeValue, "[{$node->nodeValue}]", $parent->nodeValue );
						// now we set the previous element's ingredient original text to the modified text
						$section['ingredients'][ $previous ]['original_text'] = $new_text;
						$section['ingredients'][ $previous ]['link']          = $link;
						// ends as <li>I am an ingredient and [I have a link](google.com)</li>
					}
				}
				/**
				 * look for all sorts of headers.
				 * included in this list of conditions is a scenario in which someone does
				 * not denote a header with any traditional means, but just puts plain text
				 * above an un/ordered list
				 *
				 * `I'm a heading:`
				 * `!I'm a heading`
				 * `<h4>I'm a heading</h4>`
				 * `<strong>I'm a heading</strong>`
				 * `<b>I'm a heading</b>`
				 * ```html
				 * <div>I'm a heading</div>
				 * <ul>
				 *  <li>I'm an ingredient</li>
				 * </ul>
				 * ```
				 */
				if (
					Str::endsWith( ':', $text ) ||
					Str::endsWith( '!', $text ) ||
					Str::beginsWith( '!', $text ) ||
					Str::is( [ 'strong', 'b', 'h3', 'h4', 'em' ], $tag ) ||
					( Str::contains( '<li>', $html ) && Str::is( [ 'div', 'p' ], $tag ) )
				) {
					// In the event that a heading is denoted as `! I'm a heading`,
					// we want to remove the `!` and trim the extra whitespace
					$heading = trim( str_replace( '!', '', $text ) );
					continue;
				}
				// DOMDocument includes `#text` as a node, which means that text inside tags is included as a separate node.
				// This causes duplicate ingredients.
				if ( 'li' !== $tag && 'p' !== $tag ) {
					continue;
				}
				$parsed          = Ingredient_Parse::parse( $text, $link );
				$parsed['group'] = $heading;
				if ( ! empty( $parsed['original_text'] ) ) {
					$section['ingredients'][] = $parsed;
				}
			}
		}
		return [ $section ];
	}

	/**
	 * Find or Create Ingredients
	 * @param  int $creation_id of Create Card
	 * @param  array   Associative Array of Ingredient Properties
	 * @return array   Ingredients that have been added to Recipe
	 */
	function upsert_ingredients( $creation_id, $ingredients ) {
		$section = [];

		if ( empty( $ingredients['ingredients'] ) ) {
			return $section;
		}

		foreach ( $ingredients['ingredients'] as $position => $ingredient ) {
			$formatted_ingredient = [
				'creation'      => $creation_id,
				'amount'        => '',
				'measurement'   => '',
				'original_text' => '',
				'position'      => $position,
				'group'         => 'mv-has-no-group',
				'type'          => 'ingredients',
			];

			if ( isset( $ingredient['quantity'] ) ) {
				$formatted_ingredient['amount'] = $ingredient['quantity'];
			}

			if ( isset( $ingredient['unit'] ) ) {
				$formatted_ingredient['measurement'] = $ingredient['unit'];
			}

			if ( isset( $ingredient['original_text'] ) ) {
				$formatted_ingredient['original_text'] = $ingredient['original_text'];
			}

			if ( isset( $ingredient['group'] ) ) {
				$formatted_ingredient['group'] = $ingredient['group'];
			}

			if ( isset( $ingredient['link'] ) ) {
				$formatted_ingredient['link'] = $ingredient['link'];
			}

			$ingredient = self::$models_v2->mv_supplies->insert( $formatted_ingredient );
			$section[]  = $ingredient;
		}

		return $section;
	}

	/**
	 * Get ratings
	 *
	 * EZ recipe stores ratings in wp_commentmeta
	 * Get the comment ids, get the ratings and store them in an array
	 *
	 * @since 1.0.0
	 * @param int $post_id Ez Recipe Post Id
	 * @return array $rating_fields
	 */
	public function get_ratings_from_comments( $post_id, $rating_key, $recipe_id ) {
		global $wpdb;

		if ( array_key_exists( $post_id, array_flip( self::$comment_ratings_retrieved ) ) ) {
			return [];
		}

		$statement = "SELECT
			%s AS creation,
			meta_value AS rating,
			comment_author AS author_name,
			comment_author_email AS author_email,
			comment_content AS review_content,
			comment_date AS created,
			comment_date AS modified,
			CONCAT('Review from ', comment_author) AS review_title
			FROM {$wpdb->commentmeta} AS cm
			JOIN {$wpdb->comments} AS c ON (c.comment_ID = cm.comment_id)
			WHERE c.comment_approved = 1
			AND ( cm.meta_key = %s
				OR cm.meta_key = 'ERRating'
				OR cm.meta_key = 'cookbook_comment_rating'
				OR cm.meta_key = 'recipe_rating'
				OR cm.meta_key = 'wprm-comment-rating' )
			AND cm.meta_value != 0
			AND c.comment_post_ID = %d";

		$prepared = $wpdb->prepare( $statement, [ $recipe_id, $rating_key, $post_id ] );
		$comments = $wpdb->get_results( $prepared, 'ARRAY_A' );
		if ( $comments ) {
			self::$comment_ratings_retrieved[] = $post_id;
		}

		return $comments;
	}

	/**
	 * Find or create category by name
	 *
	 * @param  string $category category name
	 * @return integer Term ID
	 */
	function upsert_category( $category ) {
		if ( is_numeric( $category ) ) {
			$term = get_term( (int) $category, 'category' );
			if ( ! is_wp_error( $term ) ) {
				return $term->term_id;
			} elseif ( is_wp_error( $term ) || is_empty( $term ) ) {
				return '';
			}
		}

		$existing_term = term_exists( $category, 'category' );

		if ( $existing_term ) {
			return $existing_term['term_id'];
		}

		$new_term = wp_insert_term( $category, 'category' );

		if ( is_wp_error( $new_term ) ) {
			return '';
		}
		return $new_term['term_id'];
	}


	/**
	 * Find or create cuisines by name
	 *
	 * @param  string $cuisine_name cuisine name
	 * @return integer Term ID
	 */
	function upsert_cuisine( $cuisine_name ) {
		if ( is_numeric( $cuisine_name ) ) {
			$term = get_term( (int) $cuisine_name );
			if ( ! is_wp_error( $term ) ) {
				$cuisine_name = $term->name;
			} elseif ( is_wp_error( $term ) || is_empty( $term ) ) {
				return '';
			}
		}

		$existing_term = term_exists( $cuisine_name, 'mv_cuisine' );

		if ( $existing_term ) {
			return $existing_term['term_id'];
		}

		$new_term = wp_insert_term( $cuisine_name, 'mv_cuisine' );

		if ( is_wp_error( $new_term ) ) {
			return '';
		}
		return $new_term['term_id'];
	}

	function create_nutrition( $recipe_id, $nutrition ) {
		foreach ( $nutrition as $key => &$item ) {
			// Replace all non-numeric characters _except_ decimal points
			if ( 'serving_size' === $key ) {
				continue;
			}
			$item = preg_replace( '/[^\d.]/', '', $item );
		}
		$nutrition['creation'] = $recipe_id;
		add_filter( 'query', [ $this, 'allow_null' ] );
		return self::$models_v2->mv_nutrition->upsert( $nutrition, [ 'creation' => $recipe_id ] );
	}

	public function allow_null( $query ) {
		return str_ireplace( "'NULL'", 'NULL', $query );
	}

	function previously_imported( $recipe ) {
		global $wpdb;
		$hash_identity = md5( $recipe['importer'] . $recipe['title'] );
		$statement     = "SELECT id, title, original_post_id FROM {$wpdb->prefix}mv_creations WHERE metadata LIKE '%%%s%%'";
		$prepared      = $wpdb->prepare( $statement, $hash_identity );
		return $wpdb->get_row( $prepared, ARRAY_A );
	}

	public static function extract_mcp_video_slug_from_embed_code( $video_code ) {
		// https://regex101.com/r/7Yk2I7/1
		$re = '/data-video-id="(?<id>[a-zA-Z0-9]+)"/i';
		preg_match( $re, $video_code, $matches, PREG_OFFSET_CAPTURE, 0 );
		if ( $matches && ! empty( $matches['id'] ) ) {
			return $matches['id'][0];
		}
		return '';
	}


	/**
	 * Extract a video id from a url.
	 *
	 * Currently supports YouTube and Vimeo urls.
	 *
	 * @param string $video_url
	 * @return string $video_id
	 */
	static function extract_video_id_from_video_url( $video_url ) {

		// MEDIAVINE
		// https://regex101.com/r/LHucmT/2/tests
		$re = '/.*\/\/(([a-zA-Z]+)?\.mediavine\.com)\/videos\/(?<id>[a-zA-Z0-9]+)/i';
		preg_match( $re, $video_url, $matches, PREG_OFFSET_CAPTURE, 0 );
		if ( $matches && ! empty( $matches['id'] ) ) {
			return $matches['id'][0];
		}
		// YOUTUBE
		// https://regex101.com/r/NjJJl9/1/tests
		// thanks to https://gist.github.com/ghalusa/6c7f3a00fd2383e5ef33
		$re = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)(?<id>[^"&?/ ]{11})%i';
		preg_match( $re, $video_url, $matches, PREG_OFFSET_CAPTURE, 0 );
		if ( $matches && ! empty( $matches['id'] ) ) {
			return $matches['id'][0];
		}
		// VIMEO
		// https://regex101.com/r/mcZzKx/2/tests
		$re = '/(vimeo(pro)?\.com)\/(?:[^\d]+)?(?<id>\d+)\??(.*)?$/i';
		preg_match( $re, $video_url, $matches, PREG_OFFSET_CAPTURE, 0 );
		if ( $matches && ! empty( $matches['id'] ) ) {
			return $matches['id'][0];
		}
		return '';
	}
	/**
	 * Extracts the source from a video url.
	 *
	 * Currently supports YouTube and Vimeo urls.
	 *
	 * @param string $video_url
	 * @return string $source
	 */
	static function extract_video_source_from_video_url( $video_url ) {
		$supported_sources = [
			'YOUTUBE'   => 'yt|youtube|youtu',
			'VIMEO'     => 'vimeo',
			'MEDIAVINE' => 'mediavine',
		];
		// https://regex101.com/r/HnSbng/3/tests
		$pattern   = '/(.+:\/\/)?(www.)?(?<source>[a-zA-Z]+)\.\S+/m';
		$source    = '';
		$video_url = Str::replace( 'player.', '', $video_url );
		$video_url = Str::replace( 'video.', '', $video_url );
		$video_url = Str::replace( 'video-shield.', '', $video_url );
		$video_url = Str::replace( 'dashboard.', '', $video_url );
		preg_match( $pattern, $video_url, $match );
		if ( ! empty( $match['source'] ) ) {
			foreach ( $supported_sources as $supported_source => $possible_urls ) {
				$urls = explode( '|', $possible_urls );
				if ( in_array( $match['source'], $urls, true ) ) {
					$source = $supported_source;
				}
			}
		}
		return $source;
	}

	static function get_mcp_video( $slug, $tries = 2 ) {
		$endpoint = 'https://mcp-video.mediavine.com/api/v1/videos/' . $slug;
		$auth     = get_option( 'mcp_mcp-services-api-token', '' );
		if ( empty( $auth ) ) {
			return false;
		}
		$auth     = json_decode( $auth );
		$token    = $auth->value;
		$args     = [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
			],
		];
		$response = \wp_remote_get( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			if ( ! $tries ) {
				return false;
			}
			return self::get_mcp_video( $slug, $tries - 1 );
		}
		$data           = json_decode( $response['body'], true )['data'];
		$cloudinary_url = 'https://mediavine-res.cloudinary.com/';
		$image          = ! empty( $data['image'] ) ? "{$cloudinary_url}{$data['image']}.jpg" : "{$cloudinary_url}video/upload/{$slug}.jpg";

		$video = [
			'slug'        => $slug,
			'include'     => true,
			'volume'      => 70,
			'aspectRatio' => '',
			'title'       => $data['title'],
			'thumbnail'   => $image,
			'duration'    => 'PT' . $data['duration'] . 'S',
			'rawData'     => $data,
			'key'         => $slug,
		];
		return $video;
	}

	function store_found_recipe( $found_recipe, $creation_id = null ) {
		$previous = $this->previously_imported( $found_recipe );
		if ( ! $creation_id && $previous ) {
			// there was a bug with WPUR imports if the instructions had two groups
			// lets allow these to be re-imported
			if ( 'wp_ultimate' !== $found_recipe['importer'] ) {
				return $previous;
			}
		}

		$errors               = [];
		$new_recipe           = $found_recipe;
		$ingredients_sections = [];
		$nutrition_info       = [];
		$time_keys            = [
			'active_time',
			'prep_time',
			'additional_time',
			'total_time',
		];

		$new_recipe['original_post_id'] = ! empty( $new_recipe['original_post_id'] ) ? $new_recipe['original_post_id'] : $new_recipe['original_id'];

		$image_sources = [];
		if ( ! empty( $new_recipe['thumbnail_id'] ) ) {
			foreach ( static::$image_sizes as $size ) {
				if ( has_image_size( $size ) ) {
					// TODO: ONLY WORKS IF THE PLUGIN IS ACTIVATED (comment in post/changelog)
					$source = wp_get_attachment_image_src( $new_recipe['thumbnail_id'], $size );
					if ( empty( $source ) || empty( $source[0] ) ) {
						continue;
					}
					$image_sources[] = $source[0];
				}
			}
		}

		// `'import'` key: which importer and an ident key for determining whether the recipe has been imported before
		// `'imported_images'` key: image sources from original recipe to combat Google not using updated images for results (for shame, Google!)
		$metadata               = [
			'import'          => [
				'importer' => $found_recipe['importer'],
				'identity' => md5( $found_recipe['importer'] . $found_recipe['title'] ),
			],
			'imported_images' => $image_sources,
		];
		$new_recipe['metadata'] = wp_json_encode( $metadata );

		if ( isset( $found_recipe['external_video'] ) ) {
			$new_recipe['external_video'] = $found_recipe['external_video'];
		}

		if ( isset( $found_recipe['video'] ) ) {
			$new_recipe['video'] = $found_recipe['video'];
		}

		if ( isset( $new_recipe['cook_time'] ) ) {
			$new_recipe['active_time'] = $new_recipe['cook_time'];
			unset( $new_recipe['cook_time'] );
		}

		if ( empty( $found_recipe['author'] ) ) {
			$author = get_the_author( $found_recipe['canonical_post_id'] );
			if ( empty( $author ) ) {
				$author = '';
			}
			$new_recipe['author'] = $author;
		}
		if ( ! empty( $new_recipe['ingredient_sections'] ) ) {
			$ingredients_sections = $new_recipe['ingredient_sections'];
		}
		unset( $new_recipe['ingredient_sections'] );

		if ( ! empty( $new_recipe['nutrition'] ) ) {
			$nutrition_info = $new_recipe['nutrition'];
		}
		unset( $new_recipe['nutrition'] );

		if ( empty( $new_recipe['additional_time_label'] ) && ! empty( $new_recipe['additional_time'] ) ) {
			$new_recipe['additional_time_label'] = __( 'Additional Time', 'mediavine' );
		}

		if ( ! $creation_id ) {

			$object_id = \wp_insert_post(
				[
					'post_title'  => $found_recipe['title'],
					'post_type'   => 'mv_create',
					'post_status' => 'publish',
				], true
			);

			if ( is_wp_error( $object_id ) ) {
				$errors = self::$api_services->normalize_errors(
					$errors, 500, [
						'title'   => __( 'Recipe Not Generated', 'mediavine' ),
						'details' => __( 'WordPress failed to create new recipe.', 'mediavine' ),
					], 'error'
				);
				return $errors;
			}

			$new_recipe['object_id'] = $object_id;
		}

		if ( isset( $found_recipe['cuisine'] ) ) {
			$cuisine_id                   = $this->upsert_cuisine( $found_recipe['cuisine'] );
			$new_recipe['secondary_term'] = $cuisine_id;
			unset( $found_recipe['cuisine'] );
		}

		if ( isset( $found_recipe['category'] ) ) {
			$category_id            = $this->upsert_category( $found_recipe['category'] );
			$new_recipe['category'] = $category_id;
			unset( $found_recipe['category'] );
		}

		// Add post thumbnail if no thumbnail exists
		if ( empty( $found_recipe['thumbnail_id'] ) && ! empty( $found_recipe['canonical_post_id'] ) ) {
			$new_recipe['thumbnail_id'] = get_post_thumbnail_id( $found_recipe['canonical_post_id'] );
		}

		// Ensure all time values are set
		foreach ( $time_keys as $key ) {
			if ( ! isset( $new_recipe[ $key ] ) ) {
				$new_recipe[ $key ] = 0;
			}
		}

		// Automatically set additional_time to difference between total and calculated times
		if ( ( $new_recipe['active_time'] + $new_recipe['prep_time'] + $new_recipe['additional_time'] ) < $new_recipe['total_time'] ) {
			$time                          = $new_recipe['active_time'] + $new_recipe['prep_time'];
			$new_recipe['additional_time'] = $new_recipe['total_time'] - $time;
		}

		if ( empty( $new_recipe['additional_time_label'] ) ) {
			$new_recipe['additional_time_label'] = __( 'Inactive Time', 'mediavine' );
		}

		$new_recipe['type'] = 'recipe';

		if ( $creation_id ) {
			$new_recipe['id'] = $creation_id;
			$inserted         = self::$models_v2->mv_creations->upsert( $new_recipe );
		} else {
			$inserted = self::$models_v2->mv_creations->create( $new_recipe );
			$update   = [
				'id'                    => $inserted->id,
				'additional_time_label' => $new_recipe['additional_time_label'],
			];
			$inserted = self::$models_v2->mv_creations->update( $update );
		}

		if ( $inserted ) {

			// if we're reimporting, we want to get rid of all the old ingredients first
			\Mediavine\Create\Supplies::delete_all_supplies( $creation_id, 'ingredients' );

			foreach ( $ingredients_sections as $section ) {
				$this->upsert_ingredients( $inserted->id, $section );
			}

			if ( ! empty( $nutrition_info ) ) {
				$this->create_nutrition( $inserted->id, $nutrition_info );
			}

			return [
				'id'                => $inserted->id,
				'title'             => $inserted->title,
				'original_post_id'  => $inserted->original_post_id,
				'canonical_post_id' => $inserted->canonical_post_id,
			];
		}

		$errors = self::$api_services->normalize_errors(
			$errors, 500, [
				'title'   => __( 'Recipe Not Generated', 'mediavine' ),
				'details' => __( 'WordPress failed to create new recipe.', 'mediavine' ),
			], 'error'
		);

		return $errors;
	}

	function store_found_ratings( $ratings ) {
		global $wpdb;
		foreach ( $ratings as $data ) {

			$date             = date( 'Y-m-d H:i:s' );
			$data['created']  = isset( $data['created'] ) ? $data['created'] : $date;
			$data['modified'] = isset( $data['modified'] ) ? $data['modified'] : $date;

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$dbi             = new MV_DBI( 'mv_reviews' );
			$normalized_data = $dbi->normalize_data( $data );
			add_filter( 'query', [ $dbi, 'allow_null' ] );
			$wpdb->insert( $dbi->table_name, $normalized_data );
			remove_filter( 'query', [ $dbi, 'allow_null' ] );
		}
		return true;
	}

	function find_recipe_post_shortcodes( $shortcode ) {
		global $wpdb;
		$wpdb->flush(); // Flush cache because function may run a couple times

		$table_name = $wpdb->prefix . 'posts';
		$statement  = "SELECT id, post_content FROM $table_name WHERE post_content LIKE '%%%s%%' AND post_type LIKE 'post'";
		$prepared   = $wpdb->prepare( $statement, $shortcode );
		$posts      = $wpdb->get_results( $prepared, 'ARRAY_A' );
		$recipes    = [];

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$recipe_id = explode( ']', explode( $shortcode, $post['post_content'] )[1] )[0];

				$recipes[ $recipe_id ][] = $post['id'];
			}

			return $recipes;
		}

		return false;
	}

	/**
	 * Find recipes to import
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function find_recipes( \WP_REST_Request $request ) {
		$response    = self::$api_services->default_response;
		$wp_response = new \WP_REST_Response();

		$sanitized = $request->sanitize_params();
		if ( is_wp_error( $sanitized ) ) {
			$response['errors'] = self::$api_services->normalize_errors(
				$response['errors'], 403, [
					'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
					'details' => __( 'You\'re submission includes unsafe characters', 'mediavine' ),
				], 'error'
			);

			$wp_response->set_status( 403 );
			$wp_response->set_data( $response );

			return $wp_response;
		}

		$params = $request->get_params();

		// if $post_id, search for recipes in post
		$post_id = isset( $params['post_id'] ) ? $params['post_id'] : null;

		if ( $post_id ) {
			$data = Collection::make( $this->get_importers() )
			->map(
				function( $importer, $name ) use ( $post_id ) {
					$recipes = call_user_func( [ $importer['importer'], 'find_recipes' ], $post_id );
					return compact( 'recipes' );
				}
			)->all();

			$wp_response->set_status( 200 );
			$wp_response->set_data( compact( 'data' ) );

			return $wp_response;
		}

		$rescan = isset( $params['rescan'] );

		// if rescanning, skip caching
		$cached = $rescan ? [] : json_decode( Settings::get_setting( 'mv_recipe_found_recipes', '[]' ), true );

		/**
		 * By default, we want to return the cached results.
		 * However, if we're explicitly rescanning, we should skip the cache.
		 */
		if ( $cached && ! $rescan ) {
			$data = $cached;
			if ( isset( $data['completed'] ) ) {
				unset( $data['completed'] );
				$cached_result = true;

				$wp_response->set_status( 200 );
				$wp_response->set_data( compact( 'data', 'cached_result' ) );

				return $wp_response;
			}
		}

		/**
		 * Map over the importers to scan for recipes.
		 * Cache the results so we can skip scanning an importer if it's been scanned before.
		 */
		$data = Collection::make( $this->get_importers() )
			->map(
				function( $importer, $name ) use ( $cached ) {
					if ( isset( $cached[ $name ]['completed'] ) ) {
						return $cached[ $name ];
					}
					$recipes   = call_user_func( [ $importer['importer'], 'find_recipes' ] );
					$completed = true;
					$cache     = compact( 'recipes', 'completed' );
					if ( empty( $post_id ) ) {
						$cached = Collection::make( $cached )->merge( [ $name => $cache ] );
						Settings::create_settings(
							[
								'slug'  => 'mv_recipe_found_recipes',
								'value' => $cached->toJson(),
							]
						);
						$completed = true;
					}
					return $cache;
				}
			);

		/**
		 * Filter out completed importers to determine if entire scan is complete.
		 * This will allow us to continue a scan from where it left off
		 * in the event of a request failure/timeout.
		 */
		$incomplete = $data->filter(
			function( $importer, $name ) use ( $cached ) {
				return isset( $cached[ $name ]['completed'] );
			}
		);

		$cache = $data;
		if ( $incomplete->isEmpty() ) {
			$cache = $data->add( [ 'completed' => true ] );
		}

		// Cache results to speed up subsequent requests to `/find` endpoint
		$settings = [
			'slug'  => 'mv_recipe_found_recipes',
			'value' => $cache->toJson(),
		];
		Settings::create_settings( $settings );

		$data = $data->all();
		$this->check_for_imported_recipes( $data );

		$wp_response->set_status( 200 );
		$wp_response->set_data( compact( 'data' ) );

		return $wp_response;
	}

	/**
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_REST_Response
	 */
	function bulk_replace( \WP_REST_Request $request ) {
		$response = new \WP_REST_Response();
		$params   = $request->get_params();

		$plugins = $params['data'];
		$results = [];

		if ( ! empty( $plugins['cookbook']['recipes'] ) ) {
			$cookbook_recipes = $plugins['cookbook']['recipes'];
			$replaced         = [];
			foreach ( $cookbook_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Cookbook::replace( $recipe );
			}
			$plugins['cookbook']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['ez_recipes']['recipes'] ) ) {
			$ez_recipes = $plugins['ez_recipes']['recipes'];
			$replaced   = [];
			foreach ( $ez_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$mv_recipe  = self::$models_v2->mv_creations->find_one( $recipe['id'] );
				$replaced[] = Import_Easy_Recipe::replace( $recipe, $mv_recipe );
			}
			$plugins['ez_recipes']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['meal_planner']['recipes'] ) ) {
			$meal_planner_recipes = $plugins['meal_planner']['recipes'];
			$replaced             = [];

			$shortcode       = '[mpprecipe-recipe:';
			$post_shortcodes = $this->find_recipe_post_shortcodes( $shortcode );

			foreach ( $meal_planner_recipes as $api_data ) {
				if ( empty( $api_data['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Meal_Planner::replace( $api_data, $post_shortcodes );
			}

			$plugins['meal_planner']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['purr']['recipes'] ) ) {
			$purr_recipes = $plugins['purr']['recipes'];
			$replaced     = [];

			foreach ( $purr_recipes as $api_data ) {
				if ( empty( $api_data['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Purr::replace( $api_data );
			}

			$plugins['purr']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['recipe_maker']['recipes'] ) ) {
			$recipe_maker_recipes = $plugins['recipe_maker']['recipes'];
			$replaced             = [];

			foreach ( $recipe_maker_recipes as $api_data ) {
				if ( empty( $api_data['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Recipe_Maker::replace( $api_data );
			}

			$plugins['recipe_maker']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['simple_recipe_pro']['recipes'] ) ) {
			$simple_recipes = $plugins['simple_recipe_pro']['recipes'];
			$replaced       = [];
			foreach ( $simple_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Simple_Recipes_Pro::replace( $recipe );
			}
			$plugins['simple_recipe_pro']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['simple_recipe_pro_legacy']['recipes'] ) ) {
			$simple_recipes = $plugins['simple_recipe_pro_legacy']['recipes'];
			$replaced       = [];
			foreach ( $simple_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Simple_Recipes_Pro_Legacy::replace( $recipe );
			}
			$plugins['simple_recipe_pro_legacy']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['simple_recipe_pro_latest']['recipes'] ) ) {
			$simple_recipes = $plugins['simple_recipe_pro_latest']['recipes'];
			$replaced       = [];
			foreach ( $simple_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Simple_Recipes_Pro_Latest::replace( $recipe );
			}
			$plugins['simple_recipe_pro_latest']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['tasty']['recipes'] ) ) {
			$tasty_recipes = $plugins['tasty']['recipes'];
			$replaced      = [];
			foreach ( $tasty_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Tasty_Recipes::replace( $recipe );
			}
			$plugins['tasty']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['wp_ultimate']['recipes'] ) ) {
			$ultimate_recipes = $plugins['wp_ultimate']['recipes'];
			$replaced         = [];
			foreach ( $ultimate_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_WP_Ultimate_Recipe::replace( $recipe );
			}
			$plugins['wp_ultimate']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['yummly']['recipes'] ) ) {
			$yummly_recipes = $plugins['yummly']['recipes'];
			$replaced       = [];
			foreach ( $yummly_recipes as $recipe ) {
				if ( empty( $recipe['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Yummly::replace( $recipe );
			}
			$plugins['yummly']['recipes'] = $replaced;

		}

		if ( ! empty( $plugins['ziplist']['recipes'] ) ) {
			$ziplist_recipes = $plugins['ziplist']['recipes'];
			$replaced        = [];

			foreach ( $ziplist_recipes as $api_data ) {
				if ( empty( $api_data['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Ziplist::replace( $api_data );
			}

			$plugins['ziplist']['recipes'] = $replaced;
		}

		if ( ! empty( $plugins['zip_recipes']['recipes'] ) ) {
			$ziplist_recipes = $plugins['zip_recipes']['recipes'];
			$replaced        = [];

			foreach ( $ziplist_recipes as $api_data ) {
				if ( empty( $api_data['id'] ) ) {
					continue;
				}
				$replaced[] = Import_Ziplist::replace( $api_data );
			}

			$plugins['zip_recipes']['recipes'] = $replaced;
		}

		$accumulated = [];
		foreach ( $plugins as $plugin => $data ) {
			foreach ( $data['recipes'] as $recipe ) {
				if ( $recipe['id'] ) {
					$item = [
						'type'        => $plugin,
						'id'          => $recipe['id'],
						'original_id' => $recipe['original_id'],
						'title'       => $recipe['title'],
					];
					if ( ! empty( $recipe['canonical_post_id'] ) ) {
						$item['canonical_post_id'] = $recipe['canonical_post_id'];
					}
					$accumulated[] = $item;
				}
			}
		}
		self::save_replaced_recipes( $accumulated );

		$response->set_data(
			[
				'data' => $plugins,
			]
		);
		$response->set_status( 200 );

		return $response;
	}

	public static function verify_required_fields( $recipe, $found_recipe ) {
		$required_fields = [
			'title',
			'thumbnail_id',
			'time',
			'description',
			'ingredient_sections',
			'instructions',
			'nutrition',
		];
		$recipe['fail']  = [];

		foreach ( $required_fields as $field ) {
			if ( 'time' === $field ) {
				if ( ( ! isset( $found_recipe['active_time'] ) || ! $found_recipe['active_time'] ) && ( ! isset( $found_recipe['prep_time'] ) || ! $found_recipe['prep_time'] ) ) {
					$recipe['fail'][] = $field;
				}
				continue;
			}
			if ( 'nutrition' === $field ) {
				if ( empty( $found_recipe[ $field ] ) || empty( $found_recipe[ $field ]['number_of_servings'] ) ) {
					$recipe['fail'][] = $field;
				}
				continue;
			}
			if ( empty( $found_recipe[ $field ] ) ) {
				$recipe['fail'][] = $field;
			}
		}
		return $recipe;
	}

	/**
	 * Some MPP recipe reviews were saved with the MPP recipe id instead of the creation id.
	 * This re-imports those ratings.
	 *
	 * @return void
	 */
	function fix_mpp_reviews() {
		if ( Settings::get_setting( 'mv_recipe_imported_recipes_fixed_mpp_reviews', false ) ) {
			return;
		}
		global $wpdb;

		$statement = "SELECT id, original_post_id as post_id
			FROM {$wpdb->prefix}mv_creations
			WHERE metadata LIKE '%meal_planner%'";
		$creations = $wpdb->get_results( $statement );
		if ( $creations && ! is_wp_error( $creations ) ) {
			foreach ( $creations as $creation ) {
				$ratings = Import_Meal_Planner::find_ratings( $creation->id, $creation->post_id );
				if ( $ratings ) {
					$this->store_found_ratings( $ratings );
				}
			}
		}

		$settings = [
			'slug'  => 'mv_recipe_imported_recipes_fixed_mpp_reviews',
			'value' => true,
		];

		Settings::create_settings( $settings );
	}

	/**
	 * Fixes issue with Tasty reviews being missed on initial import
	 * @return void
	 */
	function fix_tasty_reviews() {
		// reviews were missed on initial import
		// this method should fix the issue
		if ( Settings::get_setting( 'mv_recipe_imported_recipes_fixed_tasty_reviews', false ) ) {
			return;
		}

		global $wpdb;

		$sql = "SELECT
					creations.id AS creation,
					creations.title AS creation_title,
					creations.canonical_post_id AS original_post,
					comments.*,
					comment_meta.meta_key,
					comment_meta.meta_value as rating
				FROM {$wpdb->prefix}mv_creations creations
					INNER JOIN {$wpdb->comments} comments ON creations.canonical_post_id=comments.comment_post_ID
					INNER JOIN {$wpdb->commentmeta} comment_meta ON comment_meta.comment_id=comments.comment_ID
					LEFT JOIN {$wpdb->prefix}mv_reviews reviews ON reviews.author_email=comments.comment_author_email
				WHERE comment_meta.meta_key='ERRating'
					GROUP BY comments.comment_ID, creations.id, comment_meta.meta_value ORDER BY creations.id";

		$results = $wpdb->get_results( $sql );

		if ( empty( $results ) ) {
			return;
		}

		$reviews_inserted = [];
		/**
		 * @var \CommentMissedReview[] $results
		 */
		foreach ( $results as $missed_review ) {
			$reviews_inserted[] = Import_Tasty_Recipes::import_missed_reviews( [ 'creation_id' => $missed_review->creation ] );
		}

		$settings = [
			'slug'  => 'mv_recipe_imported_recipes_fixed_tasty_reviews',
			'value' => true,
		];

		Settings::create_settings( $settings );
	}

	/**
	 * Check the database for recipes previously imported but not stored in `previously_imported`.
	 *
	 * This process has 5 steps:
	 *
	 * 1. Get title and original_post_id of all Create cards before 2018-10-01 00:00:01 where an original_post_id exists
	 * 2. Get find results
	 *   2.5. Gather relevant data
	 * 3. Compare Create cards to find results
	 *   3.5. If a Create card has the same title and original_post_id of a find result, that was it's importer
	 * 4. Add metadata to Create card
	 * 5. Update imported recipes setting with new create cards
	 *
	 * @param array $bulk_find -- results from the `find_recipes` method on the importer
	 */
	function check_for_imported_recipes( $bulk_find ) {
		global $wpdb;
		$date = '2018-10-01 00:00:01';

		// if we've already done this process, no need to do it again
		if ( Settings::get_setting( 'mv_recipe_imported_recipes_backcompat_check' ) ) {
			return;
		}

		// Step 1 -- get the Creations from before we saved importer metadata
		$statement = "SELECT id as creation, title, original_post_id as original_id
		FROM {$wpdb->prefix}mv_creations
		WHERE original_post_id IS NOT NULL
		AND (metadata IS NULL OR metadata NOT LIKE '%importer%')
		AND created < '{$date}'";

		$creations = $wpdb->get_results( $statement, ARRAY_A );

		// if there are no creations missing importer data, don't do any more logic--we're done.
		if ( ! $creations ) {
			// make a setting to check really quickly
			$settings = [
				'slug'  => 'mv_recipe_imported_recipes_backcompat_check',
				'value' => true,
			];
			Settings::create_settings( $settings );
			return;
		}

		// Step 2.5 -- get necessary data for comparisons
		// Take the found recipes (`$bulk_find['data']`) and take only the recipes (get rid of the importer metadata, etc).
		// For comparisons, we only need the title, original_id, and type of importer
		$found_recipes = Collection::make( $bulk_find['data'] )
			->map(
				function( $importer, $importer_name ) {
					return Collection::make( $importer['recipes'] )
					->map(
						function ( $recipe ) use ( $importer_name ) {
							return Collection::make( $recipe )->add( [ 'type' => $importer_name ] )->only( [ 'title', 'original_id', 'type' ] )->all();
						}
					)
					->all();
				}
			)
			->filter(
				function( $item ) {
					return ! empty( $item );
				}
			)
			->flatten( 1 );

		$imported = Collection::make( $creations )
			->map(
				function( $creation ) use ( $found_recipes ) {
					// Step 3 -- compare found recipes to existing creations
					$match = $found_recipes->filter(
						function( $recipe ) use ( $creation ) {
							return $recipe['title'] === $creation['title'] && $recipe['original_id'] === $creation['original_id'];
						}
					);
					if ( $match->isEmpty() ) {
						return;
					}
					return $match
					->values()
					->each(
						// Step 4 -- update the creation's metadata
						function( $recipe ) use ( $creation ) {
							$metadata = [
								'import' => [
									'importer'           => $recipe['type'],
									'identity'           => md5( $recipe['type'] . $recipe['title'] ),
									'title'              => $recipe['title'],
									'original_recipe_id' => ! empty( $recipe['original_id'] ) ? $recipe['original_id'] : null,
									'original_post_id'   => $recipe['original_post_id'],
									'imported_on'        => date( 'Y-m-d H:i:s' ),
								],
							];
							$update   = [
								'id'       => $creation['creation'],
								'metadata' => wp_json_encode( $metadata ),
							];
							self::$models_v2->mv_creations->update( $update );
						}
					)
					->all();
				}
			)
			->flatten( 1 )
			->all();

		if ( $imported ) {
			// Step 5 -- save imported recipes
			self::save_imported_recipes( $imported );
		}
		$settings = [
			'slug'  => 'mv_recipe_imported_recipes_backcompat_check',
			'value' => true,
		];
		Settings::create_settings( $settings );
	}

	public function reimport( \WP_REST_Request $request ) {
		/**
		 * 1. get the original creation
		 * 2. get the import data
		 * 3. send data to appropriate plugin's `reimport` method
		 * 4. replace imported_recipe
		 * 4. return data
		 */

		// TODO: Implement real error.
		$response = [
			'data'  => [],
			'error' => false,
		];

		/**
		 * Possible params:
		 * id => the id of the Create card
		 * publish => boolean determining whether or not to store the reimported data
		 */
		$params = $request->get_params();

		$creation_id       = $params['id'];
		$original_creation = self::$models_v2->mv_creations->find_one( $creation_id );
		$metadata          = json_decode( $original_creation->metadata, true );

		// Currently only Purr importer supports re-importing (and only ingredients)
		if ( empty( $metadata['import'] ) ) {
			$response['error'] = true;
			return $response;
		}

		$original_post_id = $original_creation->original_post_id;

		// Do we want to import a specific part of the recipe, or the whole thing?
		$part = ! empty( $params['part'] ) ? $params['part'] : null;

		$import_data = $metadata['import'];
		$importer    = $import_data['importer'];

		$api_data = [
			'creation_id' => $creation_id,
			'original_id' => $original_post_id,
		];

		$response_data = [];
		// TODO: Add tasty, recipe_maker, and meal_planner next
		$response_data['importer'] = $importer;
		switch ( $importer ) {
			case 'purr':
				$response_data             = Import_Purr::serializer( $api_data );
				$response_data['importer'] = $importer;
				break;
			case 'cookbook':
				$response_data             = Import_Cookbook::serializer( $api_data );
				$response_data['importer'] = $importer;
				break;
			case 'recipe_maker':
				$api_data['original_id'] = Import_Recipe_Maker::find_recipe_id_from_parent_post( $api_data['original_id'] );
				if ( ! $api_data['original_id'] ) {
					break;
				}
				$response_data = Import_Recipe_Maker::serializer( $api_data );
				break;
			case 'tasty':
				$response_data             = Import_Tasty_Recipes::import_missed_reviews( $api_data );
				$response_data['importer'] = $importer;
				$params['publish']         = false;
				break;
			default:
				unset( $response_data['importer'] );
				break;
		}

		if ( $part ) {
			$response_data = $response_data[ $part ];
		}

		if ( ! empty( $params['publish'] ) ) {
			$response_data = $this->store_found_recipe( $response_data, $creation_id );
			\Mediavine\Create\Creations::publish_creation( $creation_id );
			$response['data'] = $response_data;
			return $response;
		}
		$response_data = $this->parse_recipe( $response_data );

		$response['data'] = $response_data;

		return new \WP_REST_Response( $response );
	}

	function parse_recipe( $recipe ) {
		$formatted = $recipe;

		if ( ! empty( $recipe['nutrition'] ) ) {
			$formatted['nutrition'] = [];
			foreach ( $recipe['nutrition'] as $key => $nutrition ) {
				$formatted['nutrition'][ $key ] = empty( $nutrition ) ? '' : $nutrition;
			}
		}

		if ( ! empty( $recipe['ingredient_sections'] ) ) {
			$formatted['supplies'] = [];
			unset( $formatted['ingredient_sections'] );
			foreach ( $recipe['ingredient_sections'][0]['ingredients'] as $key => $ingredient ) {
				$ingredient['type']      = 'ingredients';
				$ingredient['position']  = $key;
				$ingredient['nofollow']  = '1';
				$formatted['supplies'][] = $ingredient;
			}
		}

		return $formatted;
	}

	/**
	 * Perform a bulk import of all recipes
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function bulk_import( \WP_REST_Request $request ) {
		$plugins = $request->get_param( 'data' );
		$results = [];

		if ( ! empty( $plugins['cookbook']['recipes'] ) ) {
			$stored_cookbook_recipes = [];
			foreach ( $plugins['cookbook']['recipes'] as $found_recipe ) {
				try {
					$recipe                       = Import_Cookbook::serializer( $found_recipe );
					$recipe['importer']           = 'cookbook';
					$stored_recipe                = $recipe;
					$stored_recipe                = $this->store_found_recipe( $recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$ratings                      = Import_Cookbook::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}
					$stored_cookbook_recipes[] = self::verify_required_fields( $stored_recipe, $recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (cookbook): ' . $e->getMessage() );
					$found_recipe['error'] = $e->getMessage();
					$found_recipe['id']    = null;
					$stored_cookbook_recipes[] = $found_recipe;
				}
			}
			$plugins['cookbook']['recipes'] = $stored_cookbook_recipes;
		}

		if ( ! empty( $plugins['ez_recipes']['recipes'] ) ) {
			$ez_recipes        = $plugins['ez_recipes']['recipes'];
			$stored_ez_recipes = [];
			$Import_EZ_Recipe  = new Import_Easy_Recipe();
			$final_recipes     = [];
			foreach ( $ez_recipes as $recipe ) {
				try {
					$processed_ez_recipes = $Import_EZ_Recipe->serializer( $recipe['original_id'] );
					if ( ! $processed_ez_recipes ) {
						continue;
					}
					foreach ( $processed_ez_recipes as $ez_recipe ) {
						$ez_recipe['original_post_id']  = $recipe['original_id'];
						$ez_recipe['canonical_post_id'] = $recipe['original_id'];
						$ez_recipe['importer']          = 'ez_recipes';
						$stored_recipe                  = $this->store_found_recipe( $ez_recipe );
						$stored_recipe['original_id']   = $recipe['original_id'];
						$ratings                        = $this->get_ratings_from_comments( $recipe['original_id'], 'ERRating', $stored_recipe['id'] );
						if ( $ratings ) {
							$this->store_found_ratings( $ratings );
							$recipe['ratings'] = $ratings;
						}
						// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
						$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
						if ( $maybe_srp_ratings ) {
							$this->store_found_ratings( $maybe_srp_ratings );
							$stored_recipe['ratings'] = $maybe_srp_ratings;
						}
						$recipe['id']    = $stored_recipe['id'];
						$final_recipes[] = self::verify_required_fields( $recipe, $ez_recipe );
					}
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (ez_recipes): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$final_recipes[] = $recipe;
				}
			}
			$plugins['ez_recipes']['recipes'] = $final_recipes;
		}

		if ( ! empty( $plugins['meal_planner']['recipes'] ) ) {
			$meal_planner_recipes = $plugins['meal_planner']['recipes'];
			$stored_recipes       = [];
			foreach ( $meal_planner_recipes as $recipe ) {
				try {
					$found_recipe                 = Import_Meal_Planner::serializer( $recipe );
					$found_recipe['importer']     = 'meal_planner';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];

					$ratings = Import_Meal_Planner::find_ratings( $stored_recipe['id'], $stored_recipe['original_post_id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}

					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (meal_planner): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['meal_planner']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['purr']['recipes'] ) ) {
			$stored_recipes = [];
			foreach ( $plugins['purr']['recipes'] as $recipe ) {
				try {
					$found_recipe                 = Import_Purr::serializer( $recipe );
					$found_recipe['importer']     = 'purr';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$found_recipe['ratings']      = Import_Purr::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );

					if ( $found_recipe['ratings'] ) {
						$this->store_found_ratings( $found_recipe['ratings'] );
						$stored_recipe['ratings'] = $found_recipe['ratings'];
					}
					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}

					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (purr): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['purr']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['recipe_maker']['recipes'] ) ) {
			$recipes        = $plugins['recipe_maker']['recipes'];
			$stored_recipes = [];
			foreach ( $recipes as $recipe ) {
				try {
					$found_recipe                 = Import_Recipe_Maker::serializer( $recipe );
					$found_recipe['importer']     = 'recipe_maker';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$full_recipe                  = $found_recipe;
					$full_recipe['id']            = $stored_recipe['id'];
					$ratings                      = Import_Recipe_Maker::get_ratings( $full_recipe );

					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}

					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}

					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (recipe_maker): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}

			$plugins['recipe_maker']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['simple_recipe_pro']['recipes'] ) ) {
			$srps           = $plugins['simple_recipe_pro']['recipes'];
			$stored_recipes = [];
			foreach ( $srps as $recipe ) {
				try {
					$found_recipe                 = Import_Simple_Recipes_Pro::serializer( $recipe );
					$found_recipe['importer']     = 'simple_recipe_pro';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];

					$ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (simple_recipe_pro): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['simple_recipe_pro']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['simple_recipe_pro_legacy']['recipes'] ) ) {
			$srps           = $plugins['simple_recipe_pro_legacy']['recipes'];
			$stored_recipes = [];
			foreach ( $srps as $recipe ) {
				try {
					$found_recipe                 = Import_Simple_Recipes_Pro_Legacy::serializer( $recipe );
					$found_recipe['importer']     = 'simple_recipe_pro_legacy';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];

					$ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (simple_recipe_pro_legacy): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['simple_recipe_pro_legacy']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['simple_recipe_pro_latest']['recipes'] ) ) {
			$srps           = $plugins['simple_recipe_pro_latest']['recipes'];
			$stored_recipes = [];
			foreach ( $srps as $recipe ) {
				try {
					$found_recipe                 = Import_Simple_Recipes_Pro_Latest::serializer( $recipe );
					$found_recipe['importer']     = 'simple_recipe_pro_latest';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];

					$ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (simple_recipe_pro_latest): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['simple_recipe_pro_latest']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['tasty']['recipes'] ) ) {
			$stored_tasty_recipes = [];
			foreach ( $plugins['tasty']['recipes'] as $found_recipe ) {
				try {
					$recipe                       = Import_Tasty_Recipes::serializer( $found_recipe );
					$recipe['importer']           = 'tasty';
					$stored_recipe                = $recipe;
					$stored_recipe                = $this->store_found_recipe( $recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$ratings                      = Import_Tasty_Recipes::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}
					$stored_tasty_recipes[] = self::verify_required_fields( $stored_recipe, $recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (tasty): ' . $e->getMessage() );
					$found_recipe['error'] = $e->getMessage();
					$found_recipe['id']    = null;
					$stored_tasty_recipes[] = $found_recipe;
				}
			}
			$plugins['tasty']['recipes'] = $stored_tasty_recipes;
		}

		if ( ! empty( $plugins['ziplist']['recipes'] ) ) {
			$ziplist_recipes = $plugins['ziplist']['recipes'];
			$stored_recipes  = [];
			foreach ( $ziplist_recipes as $recipe ) {
				try {
					$found_recipe                 = Import_Ziplist::serializer( $recipe['original_id'] );
					$found_recipe['importer']     = 'ziplist';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					// ZipList does not have ratings/reviews, but...
					// just in case SRP is ninja-hijacking the recipe, we'll run their ratings function
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}

					$stored_recipes[] = $stored_recipe;
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (ziplist): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['ziplist']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['zip_recipes']['recipes'] ) ) {
			$ziplist_recipes = $plugins['zip_recipes']['recipes'];
			$stored_recipes  = [];
			foreach ( $ziplist_recipes as $recipe ) {
				try {
					$found_recipe                 = Import_Zip_Recipes::serializer( $recipe['original_id'] );
					$found_recipe['importer']     = 'zip_recipes';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$ratings                      = Import_Zip_Recipes::get_ratings( $stored_recipe );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$recipe['ratings'] = $ratings;
					}
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}
					$stored_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (zip_recipes): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_recipes[] = $recipe;
				}
			}
			$plugins['zip_recipes']['recipes'] = $stored_recipes;
		}

		if ( ! empty( $plugins['wp_ultimate']['recipes'] ) ) {
			$stored_ultimate_recipes = [];
			foreach ( $plugins['wp_ultimate']['recipes'] as $found_recipe ) {
				try {
					$recipe                       = Import_WP_Ultimate_Recipe::serializer( $found_recipe );
					$recipe['importer']           = 'wp_ultimate';
					$stored_recipe                = $this->store_found_recipe( $recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];
					$ratings                      = Import_WP_Ultimate_Recipe::get_ratings( $stored_recipe );
					if ( $ratings ) {
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}
					$stored_ultimate_recipes[] = self::verify_required_fields( $stored_recipe, $recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (wp_ultimate): ' . $e->getMessage() );
					$found_recipe['error'] = $e->getMessage();
					$found_recipe['id']    = null;
					$stored_ultimate_recipes[] = $found_recipe;
				}
			}
			$plugins['wp_ultimate']['recipes'] = $stored_ultimate_recipes;
		}

		if ( ! empty( $plugins['yummly']['recipes'] ) ) {
			$stored_yummly_recipes = [];
			foreach ( $plugins['yummly']['recipes'] as $recipe ) {
				try {
					$found_recipe                 = Import_Yummly::serializer( $recipe['original_id'] );
					$found_recipe['importer']     = 'yummly';
					$stored_recipe                = $this->store_found_recipe( $found_recipe );
					$stored_recipe['original_id'] = $recipe['original_id'];

					$rating = $found_recipe['rating'];

					if ( ! empty( $rating ) ) {
						$ratings = [];
						for ( $i = 0; $i < 3; $i++ ) {
							$ratings[] = [
								'creation' => $stored_recipe['id'],
								'rating'   => $rating,
							];
						}
						$this->store_found_ratings( $ratings );
						$stored_recipe['ratings'] = $ratings;
					}
					$maybe_srp_ratings = Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
					if ( $maybe_srp_ratings ) {
						$this->store_found_ratings( $maybe_srp_ratings );
						$stored_recipe['ratings'] = $maybe_srp_ratings;
					}

					$stored_yummly_recipes[] = self::verify_required_fields( $stored_recipe, $found_recipe );
				} catch ( \Throwable $e ) {
					error_log( 'Create importer error (yummly): ' . $e->getMessage() );
					$recipe['error'] = $e->getMessage();
					$recipe['id']    = null;
					$stored_yummly_recipes[] = $recipe;
				}
			}
			$plugins['yummly']['recipes'] = $stored_yummly_recipes;
		}

		$imported = [];
		foreach ( $plugins as $plugin => $data ) {
			foreach ( $data['recipes'] as $recipe ) {
				if ( $recipe['id'] ) {
					$item = [
						'type'        => $plugin,
						'id'          => $recipe['id'],
						'original_id' => $recipe['original_id'],
						'title'       => $recipe['title'],
					];
					if ( ! empty( $recipe['canonical_post_id'] ) ) {
						$item['canonical_post_id'] = $recipe['canonical_post_id'];
					}
					$imported[] = $item;
				}
			}
		}
		self::save_imported_recipes( $imported );

		do_action( 'mv_create_bulk_import_completed', $imported );

		$response = new \WP_REST_Response();

		$response->set_status( 200 );

		$response->set_data(
			[
				'data' => $plugins,
			]
		);

		return $response;
	}

	public static function get_imported_recipes() {
		global $wpdb;
		$recipes_dbi = new \Mediavine\MV_DBI( 'mv_creations' );
		$imported    = Settings::get_setting( 'mv_recipe_imported_recipes' );
		$imported    = json_decode( $imported );
		if ( ! $imported ) {
			return null;
		}
		$has_recipe_id_column_statement = "SHOW COLUMNS FROM {$wpdb->prefix}mv_reviews LIKE 'recipe_id'";
		$has_recipe_id_column           = $wpdb->get_row( $has_recipe_id_column_statement );
		$final_imported                 = [];
		foreach ( $imported as &$imported_recipe ) {
			if ( empty( $imported_recipe ) || ! isset( $imported_recipe->id ) ) {
				continue;
			}
			$prepare   = [ $imported_recipe->id ];
			$recipe    = $recipes_dbi->find_one_by_id( $imported_recipe->id );
			$statement = "SELECT * FROM {$wpdb->prefix}mv_reviews WHERE creation = %d";
			if ( $has_recipe_id_column ) {
				$statement .= ' OR recipe_id = %d';
				$prepare[]  = $imported_recipe->id;
			}

			$prepared = $wpdb->prepare( $statement, $prepare );
			$ratings  = $wpdb->get_results( $prepared, ARRAY_A );
			if ( ! empty( $ratings ) ) {
				$imported_recipe->ratings = $ratings;
			}
			$imported_recipe = self::verify_required_fields( (array) $imported_recipe, (array) $recipe );

			// Enrich with post IDs from the creation record for frontend "Edit Post" links
			if ( $recipe && ! empty( $recipe->canonical_post_id ) ) {
				$imported_recipe['canonical_post_id'] = $recipe->canonical_post_id;
			}
			if ( $recipe && ! empty( $recipe->original_post_id ) ) {
				$imported_recipe['original_post_id'] = $recipe->original_post_id;
			}

			// Unfortunately, since after a recipe is imported `ingredient_sections` is lost, it will
			// always show up as a fail. Since the odds of this ever actually -being- a fail are almost
			// non-existent, we can just remove it from the returned object.
			$imported_recipe['fail'] = array_values(
				array_filter(
					$imported_recipe['fail'], function( $item ) {
						return 'ingredient_sections' !== $item;
					}
				)
			);
			if ( array_key_exists( 'id', $imported_recipe ) ) {
				$final_imported[] = $imported_recipe;
			}
		}
		return wp_json_encode( $final_imported );
	}

	public static function get_replaced_recipes() {
		return Settings::get_setting( 'mv_recipe_replaced_recipes' );
	}

	public static function save_imported_recipes( $newly_imported = [] ) {
		$imported = json_decode( self::get_imported_recipes(), true );
		if ( ! $imported ) {
			$imported = [];
		}
		$new = array_merge( $imported, $newly_imported );
		if ( $new ) {
			$unique = [];
			foreach ( $new as $item ) {
				$identity_hash            = md5( $item['type'] . $item['title'] );
				$unique[ $identity_hash ] = $item;
			}
			$new = array_values( $unique );
		}
		$settings                   = [
			'slug'  => 'mv_recipe_imported_recipes',
			'value' => json_encode( $new ),
		];
		$mv_recipe_imported_recipes = (array) Settings::create_settings( $settings );
	}

	public static function save_replaced_recipes( $newly_replaced ) {
		$replaced = json_decode( self::get_replaced_recipes() );
		if ( ! $replaced ) {
			$replaced = [];
		}
		$new                        = array_merge( $replaced, $newly_replaced );
		$settings                   = [
			'slug'  => 'mv_recipe_replaced_recipes',
			'value' => json_encode( $new ),
		];
		$mv_recipe_replaced_recipes = (array) Settings::create_settings( $settings );
	}

	/**
	 * Replaces block content with Create card, correcting the search based on the shortcode
	 *
	 * @param string $old_shortcode Old shortcode to search by
	 * @param string $new_shortcode New Create card shortcode to replace with
	 * @param string $content Content to be replaced
	 * @return string Updated content with Create shortcodes
	 */
	public static function replace_block_content( $old_shortcode, $new_shortcode, $content ) {
		// https://regex101.com/r/Bz5Zkj/3
		$pattern = '/<!-- wp:' . preg_quote( $old_shortcode, '/' ) . '.*?(-->[\s\S]*?<!-- \/wp:.*|\/)?-->/';

		// If the old shortcode is actually a shortcode, we need to adjust the regex
		if ( '[' === $old_shortcode[0] ) {
			// https://regex101.com/r/yTS6UB/1
			$pattern = '/<!-- wp:shortcode -->.?' . preg_quote( $old_shortcode, '/' ) . '.*?<!-- \/wp:shortcode -->/s';
		}

		$new_content = preg_replace(
			$pattern,
			self::get_gutenberg_block_from_shortcode( $new_shortcode ),
			$content
		);

		return $new_content;
	}

	/**
	 * API callback for /importer/replace/block
	 */
	public function replace_blocks( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$data   = $params['data'];

		$creation_dbi = new \Mediavine\MV_DBI( 'mv_creations' );
		$creation     = $creation_dbi->find_one( $data['creation'] );

		$content = \get_post( $data['post'] )->post_content . "\n\n";

		$thumbnail_uri = wp_get_attachment_url( $creation->thumbnail_id );

		$new_shortcode = '[mv_create key="' . $creation->id . '" type="recipe" title="' . $creation->title . '" thumbnail="' . $thumbnail_uri . '"]';

		$new_content = $this::replace_block_content( $data['original_shortcode'], $new_shortcode, $content );

		$updated_post = \wp_update_post(
			[
				'ID'           => $data['post'],
				'post_content' => trim( $new_content ),
			]
		);

		if ( is_wp_error( $updated_post ) ) {
			return $updated_post;
		}

		if ( 0 === $updated_post ) {
			$error = new \WP_Error( 404, __( 'Post not found', 'mediavine' ), [ 'post' => esc_attr( $data['post'] ) ] );

			return $error;
		}

		$response = API_Services::set_response_data( $updated_post, new \WP_REST_Response() );
		$response->set_status( 201 );

		return $response;

	}

	/**
	 * Gets a gutenberg block from an MV_Create shortcode.
	 *
	 * @param sting $shortcode an `[MV_Create...]` shortcode
	 * @return string $block the appropriate gutenberg block
	 */
	static function get_gutenberg_block_from_shortcode( $shortcode ) {
		// Set default attributes in case a shortcode doesn't include these
		$default_attributes = [
			'title'     => '',
			'thumbnail' => '',
			'type'      => 'recipe',
		];
		// Add a space to the end of the shortcode so `shortcode_parse_atts` can actually parse the final attribute
		$shortcode  = Str::replace( '"]', '" ]', $shortcode );
		$attributes = shortcode_parse_atts( $shortcode );
		$attributes = array_merge( $default_attributes, $attributes );
		$block      = '';
		if ( $attributes['key'] ) {
			$block_attributes = json_encode(
				[
					'id'            => (int) $attributes['key'],
					'title'         => esc_attr( $attributes['title'] ),
					'thumbnail_uri' => esc_attr( $attributes['thumbnail'] ),
					'type'          => esc_attr( $attributes['type'] ),
					'layout'        => null,
				]
			);
			$block            = <<<EOT
<!-- wp:mv/recipe $block_attributes -->
	<div class="wp-block-mv-recipe">[mv_create key="{$attributes['key']}" type="{$attributes['type']}" title="{$attributes['title']}" thumbnail="{$attributes['thumbnail']}"]</div>
<!-- /wp:mv/recipe -->
EOT;
		}

		return trim( $block );
	}

	/**
	 * Replaces another plugin's recipe block with MV block.
	 *
	 * @param int|string $post_id
	 * @param string $post_content
	 * @param string $block_signature
	 * @param string $mv_block
	 * @return void
	 */
	static function replace_recipe_blocks( $post_id, $post_content, $original_id, $block_signature, $mv_block ) {
		$original_content = $post_content;
		if ( function_exists( 'has_blocks' ) && function_exists( 'parse_blocks' ) && has_blocks( $original_content ) ) {
			$blocks = Collection::make( parse_blocks( $original_content ) )
				->filter(
					function( $block ) use ( $block_signature ) {
						return $block_signature === $block['blockName'];
					}
				);

			$block_signature = Str::replace( '/', '\/', $block_signature );
			if ( $blocks->isNotEmpty() ) {
				$changed = $blocks->filter(
					function( $block ) use ( $post_id, $original_id, $original_content, $block_signature, $mv_block ) {
						$block_attributes = ! empty( $block['attrs'] ) ? wp_json_encode( $block['attrs'] ) : '';
						if ( ! isset( $block['attrs']['id'] ) || (int) $original_id !== $block['attrs']['id'] ) {
							return false;
						}
						// https://regex101.com/r/VvqTps/1 -- use the `U` for ungreedy (only find/replace a single block)
						$re = '/<!-- wp:' . $block_signature . '.*' . $block_attributes . '.*\/wp:' . $block_signature . ' -->/sU';
						preg_match( $re, $original_content, $match );
						if ( empty( $match ) ) {
							return false;
						}
						$to_replace = $match[0];
						$updated    = Str::replace( $to_replace, $mv_block, $original_content );
						if ( $updated !== $original_content ) {
							wp_update_post(
								[
									'ID'           => $post_id,
									'post_content' => $updated,
								]
							);
							return true;
						}
					}
				);
				if ( $changed->isNotEmpty() ) {
					return true;
				}
			}
		}
		return false;
	}

	function routes() {
		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/importers/find', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'find_recipes' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/importers/bulk', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'bulk_import' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/importers/replace', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'bulk_replace' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/importers/reimport', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'reimport' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/importers/block', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'replace_blocks' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);
	}
}
