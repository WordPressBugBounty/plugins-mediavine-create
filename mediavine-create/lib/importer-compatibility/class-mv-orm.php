<?php
namespace Mediavine;

class MV_ORM {
	public static function get_models() {
		add_action( 'admin_head', [ 'Mediavine\Create\Importer_Compatibility', 'deactivate_importer' ], 10, 2 );
	}
}
