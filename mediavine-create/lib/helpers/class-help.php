<?php
namespace Mediavine\Create;

/**
 * Debug / operational logging helpers.
 *
 * Raw error_log() is forbidden by phpcs (Generic.PHP.ForbiddenFunctions).
 * Route all logging through this class so call sites stay auditable and
 * debug dumps cannot flood publisher logs when WP_DEBUG is off.
 */
class Help {

	/**
	 * Write a message to the error log.
	 *
	 * Use for operational failures (API errors, import exceptions, etc.).
	 * Prefer dump() for ad-hoc debug output that should respect WP_DEBUG.
	 *
	 * @param mixed $message String message or value to print_r.
	 * @return void
	 */
	public static function log( $message ) {
		if ( ! is_string( $message ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- sole allowed error_log site.
			error_log( print_r( $message, true ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sole allowed error_log site.
		error_log( $message );
	}

	/**
	 * Dump a labeled value to the error log when WP_DEBUG is enabled.
	 *
	 * @param mixed  $var   Value to dump.
	 * @param string $label Optional label prefix.
	 * @return void
	 */
	public static function dump( $var, $label = 'Dump' ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( $label ) {
			$label = "\r\n\n##################\n#### {$label}\r\n##################\n\n\n";
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- debug dump gated by WP_DEBUG above.
		self::log( $label . print_r( $var, true ) );
	}

	/**
	 * Checks given data array or object for values at matching keys.
	 *
	 * Example:
	 * $keys = array(
	 *    'a',
	 *    'c',
	 * );
	 * $data = array(
	 *    'a' => 'apple',
	 *    'b' => 'banana',
	 * );
	 * $result = static::set_keys_where_value_exists( $keys, $data );
	 * // array(
	 * //    'a' => 'apple',
	 * // );
	 *
	 * @param array        $keys array of keys to check
	 * @param array|object $data array or object to check against
	 * @return array $return_data
	 */
	public static function set_keys_where_value_exists( $keys = [], $data = [] ) {
		$return_data = [];
		foreach ( $keys as $key ) {
			if ( is_array( $data ) ) {
				if ( ! empty( $data[ $key ] ) ) {
					$return_data[ $key ] = $data[ $key ];
				}
			}
			if ( is_object( $data ) ) {
				if ( ! empty( $data->{$key} ) ) {
					$return_data[ $key ] = $data->{$key};
				}
			}
		}
		return $return_data;
	}
}
