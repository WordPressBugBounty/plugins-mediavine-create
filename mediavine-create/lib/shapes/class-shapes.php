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
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
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
