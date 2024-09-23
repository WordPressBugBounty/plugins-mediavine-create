<?php
namespace Mediavine\Create;

class Recipes {
	public function __construct() {
		add_action( 'admin_head', [ 'Mediavine\Create\Importer_Compatibility', 'deactivate_importer' ], 10, 2 );
	}
}
