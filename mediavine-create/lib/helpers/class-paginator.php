<?php
namespace Mediavine\Create;

use Mediavine\WordPress\Support\Str;

class Paginator {

	/**
	 * Get links with required fields from a table.
	 *
	 * @param string $table
	 * @param array $fields
	 * @param mixed $id
	 * @param string $id_column
	 * @return array
	 */
	public static function make_links( $args = [] ) {
		$default_args = [
			'table'     => '',
			'fields'    => [],
			'id'        => null,
			'id_column' => 'id',
			'type'      => '',
		];
		$args         = array_merge( $default_args, $args );
		$table        = preg_replace('/[^a-zA-Z0-9_]/', '', $args['table'] );
		$fields       = $args['fields'];
		$id           = $args['id'];
		$id_column    = preg_replace('/[^a-zA-Z0-9_]/', '', $args['id_column'] );
		$type         = $args['type'];
		global $wpdb;
		if ( empty( $id ) ) {
			return [];
		}

		$table = Str::contains( $table, $wpdb->prefix ) ? $table : $wpdb->prefix . $table;

		// Build and prep where clause
		$where  = '';
		$params = [];
		if ( ! empty( $type ) ) {
			if ( is_array( $type ) ) {
				$placeholders = implode(',', array_fill(0, count($type), '%s'));
				$where        = "WHERE type IN ($placeholders)";
				$params       = $type;
			} else {
				$where  = 'WHERE type = %s';
				$params = [ $type ];
			}
		}

		// Sanitize fields
		$sanitized_fields = [];
		foreach ( $fields as $field ) {
			if ( preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field ) ) {
				$sanitized_fields[] = $field;
			}
		}
		$sanitized_fields = trim( implode( ', ', $sanitized_fields ), ', ' );

		// SECURITY CHECKED: This query is properly prepared.
		$statement = "SELECT {$sanitized_fields} FROM {$table} {$where} ORDER BY {$id_column} ASC";
		$prepared  = $wpdb->prepare( $statement, $params );
		$items     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( empty( $items ) ) {
			return [];
		}
		$links = [
			'first' => reset( $items ),
			'last'  => end( $items ),
		];
		$total = count( $items );

		foreach ( $items as $key => $item ) {
			if ( $item[ $id_column ] == $id ) { // phpcs:ignore
				$links['current'] = $items[ $key ];
				// If the item is not the first in the array ($key > 0),
				// the previous item is one index lower than the current ($key - 1).
				// If the key is the first in the array ($key === 0),
				// the previous item is the last item in the array (end( $items ))
				$links['previous'] = $key > 0 ? $items[ $key - 1 ] : end( $items );
				// If the item index is lower than the count - 1 (because 0-indexing),
				// the next item is the next index ($key + 1).
				// Otherwise, the next item is the first in the array ( reset($items) ).
				$links['next'] = $key < $total - 1 ? $items[ $key + 1 ] : reset( $items );
			}
		}

		return $links;
	}
}
