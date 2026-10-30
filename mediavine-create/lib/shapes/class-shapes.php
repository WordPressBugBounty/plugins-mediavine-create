<?php
namespace Mediavine\Create;

class Shapes extends Plugin {

	public static $instance = null;

	public $table_name = 'mv_shapes';

	public $schema = [
		'name'    => 'text',
		'plural'  => 'text',
		'slug'    => 'text',
		'icon'    => 'text',
		'shape'   => 'longtext',
		'enabled' => [
			'type'    => 'tinyint(1)',
			'default' => 1,
		],
	];

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Code-declared shape definitions (translated names + shape JSON).
	 *
	 * @return array[]
	 */
	public static function get_shapes_data() {
		return [
			[
				'name'   => __( 'Recipe', 'mediavine-create' ),
				'plural' => __( 'Recipes', 'mediavine-create' ),
				'slug'   => 'recipe',
				'icon'   => 'carrot',
				'shape'  => file_get_contents( MV_CREATE_DIR . '/shapes/recipe.json' ),
			],
			[
				'name'   => __( 'How-To', 'mediavine-create' ),
				'plural' => __( 'How-Tos', 'mediavine-create' ),
				'slug'   => 'diy',
				'icon'   => 'lightbulb',
				'shape'  => file_get_contents( MV_CREATE_DIR . '/shapes/how-to.json' ),
			],
			[
				'name'   => __( 'List', 'mediavine-create' ),
				'plural' => __( 'Lists', 'mediavine-create' ),
				'slug'   => 'list',
				'icon'   => '',
				'shape'  => file_get_contents( MV_CREATE_DIR . '/shapes/list.json' ),
			],
		];
	}

	/**
	 * Returns an array of shapes, each with the `shape` col json_decoded and filtered by unique slugs.
	 */
	public static function get_shapes() {
		$shapes         = new \Mediavine\MV_DBI( 'mv_shapes' );
		$found          = $shapes->find();
		$normalized     = [];
		$shapes_by_slug = [];

		foreach ( $found as $shape ) {
			$shape->shape = json_decode( $shape->shape );
			if ( ! isset( $shapes_by_slug[ $shape->slug ] ) ) {
				$shapes_by_slug[ $shape->slug ] = true;
				$normalized[]                   = $shape;
			}
		}
		return $normalized;
	}

	public function custom_schema( $custom_tables ) {
		$custom_tables[] = [
			'version'    => self::DB_VERSION,
			'table_name' => $this->table_name,
			'schema'     => $this->schema,
		];
		return $custom_tables;
	}

	function init() {
		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
	}
}
