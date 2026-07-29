<?php

namespace Mediavine\Create\Importers\Helpers;

/**
 * Unserialize helpers that refuse object instantiation.
 */
class Safe_Unserialize {

	/**
	 * Unserialize data only if it was serialized, without allowing classes.
	 *
	 * Mirrors WordPress's maybe_unserialize() but passes allowed_classes => false
	 * so PHP object injection is not possible.
	 *
	 * @param mixed $data Data that might be serialized.
	 * @return mixed
	 */
	public static function maybe( $data ) {
		if ( is_serialized( $data ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- importer postmeta; classes blocked.
			return @unserialize( trim( $data ), [ 'allowed_classes' => false ] );
		}

		return $data;
	}
}
