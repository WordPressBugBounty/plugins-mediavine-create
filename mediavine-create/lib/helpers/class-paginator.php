<?php
namespace Mediavine\Create;

use Mediavine\Create\Helpers\Str;

class Paginator {

	/**
	 * Get links with required fields from a table.
	 *
	 * Looks up first/last/current plus keyset-neighbor (prev/next) rows
	 * instead of loading every matching row into PHP, so cost stays flat
	 * regardless of table size. `previous`/`next` wrap around to `last`/`first`
	 * at the boundaries, matching the ordered-list behavior this replaces.
	 *
	 * @param string $table
	 * @param array  $fields
	 * @param mixed  $id
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

		// Build and prep the scoping where clause, shared by every query below.
		$scope_where  = '';
		$scope_params = [];
		if ( ! empty( $type ) ) {
			if ( is_array( $type ) ) {
				$placeholders = implode(',', array_fill(0, count($type), '%s'));
				$scope_where  = "type IN ($placeholders)";
				$scope_params = $type;
			} else {
				$scope_where  = 'type = %s';
				$scope_params = [ $type ];
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

		// Fetches a single row matching the shared scope plus an optional extra
		// condition (bound via %d against $id_column), ordered so LIMIT 1 picks
		// the boundary or keyset-neighbor row the caller wants.
		$fetch_one = function ( $extra_where, $extra_params, $order ) use ( $wpdb, $table, $sanitized_fields, $id_column, $scope_where, $scope_params ) {
			$conditions = $scope_where;
			if ( $extra_where ) {
				$conditions = $conditions ? "{$conditions} AND {$extra_where}" : $extra_where;
			}
			$where  = $conditions ? "WHERE {$conditions}" : '';
			$params = array_merge( $scope_params, $extra_params );

			$statement = "SELECT {$sanitized_fields} FROM {$table} {$where} ORDER BY {$id_column} {$order} LIMIT 1";

			// wpdb::prepare() requires a placeholder to bind; skip it when there's
			// nothing to bind (no type filter and no id comparison) — the rest of
			// the statement is already built from allowlisted/sanitized parts.
			if ( $params ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
				$statement = $wpdb->prepare( $statement, $params );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
			$row = $wpdb->get_row( $statement, ARRAY_A );
			return $row ? $row : null;
		};

		$first = $fetch_one( '', [], 'ASC' );
		if ( empty( $first ) ) {
			return [];
		}

		$links = [
			'first' => $first,
			'last'  => $fetch_one( '', [], 'DESC' ),
		];

		$current = $fetch_one( "{$id_column} = %d", [ (int) $id ], 'ASC' );
		if ( empty( $current ) ) {
			return $links;
		}
		$links['current'] = $current;

		// A missing neighbor means $current sits at that boundary; wrap around.
		$previous          = $fetch_one( "{$id_column} < %d", [ (int) $id ], 'DESC' );
		$links['previous'] = $previous ? $previous : $links['last'];
		$next              = $fetch_one( "{$id_column} > %d", [ (int) $id ], 'ASC' );
		$links['next']     = $next ? $next : $links['first'];

		return $links;
	}
}
