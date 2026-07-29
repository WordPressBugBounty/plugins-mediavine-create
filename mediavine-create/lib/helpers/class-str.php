<?php
namespace Mediavine\Create\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * PHP 8.0+ function polyfills for backward compatibility
 */
if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Polyfill for str_contains() - PHP 8.0+
	 *
	 * Determines if a string contains a given substring.
	 *
	 * @since PHP 8.0
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for in the haystack.
	 * @return bool True if needle is in haystack, false otherwise.
	 */
	function str_contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Polyfill for str_starts_with() - PHP 8.0+
	 *
	 * Checks if a string starts with a given substring.
	 *
	 * @since PHP 8.0
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for in the haystack.
	 * @return bool True if haystack starts with needle, false otherwise.
	 */
	function str_starts_with( string $haystack, string $needle ): bool {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Polyfill for str_ends_with() - PHP 8.0+
	 *
	 * Checks if a string ends with a given substring.
	 *
	 * @since PHP 8.0
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for in the haystack.
	 * @return bool True if haystack ends with needle, false otherwise.
	 */
	function str_ends_with( string $haystack, string $needle ): bool {
		return '' === $needle || ( '' !== $haystack && 0 === substr_compare( $haystack, $needle, -strlen( $needle ) ) );
	}
}

/**
 * String manipulation helper class
 *
 * Provides various string manipulation utilities and methods for working with strings.
 * Also includes polyfills for PHP 8.0+ string functions to maintain backward compatibility.
 *
 * @package Mediavine\Create\Helpers
 * @since 1.0.0
 */
class Str {

	/**
	 * The cache of snake-cased words.
	 *
	 * @var array
	 */
	protected static $snakeCache = [];

	/**
	 * Determine if a given string contains a given substring.
	 *
	 * Signature matches PHP's str_contains( $haystack, $needle ) and Laravel's
	 * Str::contains( $haystack, $needles ): haystack first, needle(s) second.
	 *
	 * @param  string       $haystack The string to search in.
	 * @param  string|array $needles  The substring(s) to search for.
	 * @return bool
	 */
	public static function contains( $haystack, $needles ): bool {
		if ( is_null( $haystack ) ) {
			return false;
		}
		foreach ( Arr::wrap( $needles ) as $needle ) {
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determine if a given string matches a given pattern.
	 *
	 * @param  string|array $possibilities
	 * @param  string       $value
	 * @return bool
	 */
	public static function is( $possibilities, $value ): bool {
		foreach ( Arr::wrap( $possibilities ) as $possibility ) {
			if ( $possibility === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the length of the given string.
	 *
	 * @param  string $value
	 * @param  string $encoding
	 * @return int
	 */
	public static function length( $value, $encoding = null ): int {
		if ( is_null( $value ) ) {
			return 0;
		}
		$encoding = $encoding ? $encoding : mb_internal_encoding();
		return mb_strlen( $value, $encoding );
	}

	/**
	 * Replace each occurrence of a given value in the string.
	 *
	 * @param  string|int $search
	 * @param  string|int $replace
	 * @param  string     $subject
	 * @return string
	 */
	public static function replace( $search, $replace, $subject ): string {
		if ( empty( $search ) || $search === $replace ) {
			return $subject;
		}
		if ( is_array( $search ) ) {
			foreach ( $search as $s ) {
				$subject = static::replace( $s, $replace, $subject );
			}
			return $subject;
		}
		$search   = (string) $search;
		$replace  = (string) $replace;
		$position = strpos( $subject, $search );
		if ( false !== $position ) {
			$subject = str_replace( $search, $replace, $subject );
		}
		return $subject;
	}

	/**
	 * Encode a UTF-8 string as HTML numeric entities for DOMDocument::loadHTML().
	 *
	 * Replaces the PHP 8.2-deprecated `mb_convert_encoding( ..., 'HTML-ENTITIES', 'UTF-8' )`
	 * so high-bit characters survive libxml's ISO-8859-1 default.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function to_html_entities( $value ): string {
		$value = (string) $value;
		if ( function_exists( 'mb_encode_numericentity' ) ) {
			return mb_encode_numericentity( $value, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
		}
		return $value;
	}

	/**
	 * Determines whether string `$subject` ends with string `$search`.
	 *
	 * @param string|int $search
	 * @param string     $needle
	 * @return boolean
	 */
	public static function endsWith( $search, $subject ): bool {
		$search = (string) $search;
		$length = strlen( $search );
		if ( 0 === $length ) {
			return true;
		}
		return substr( $subject, -$length ) === $search;
	}

	/**
	 * Determines whether a host is the same as, or a subdomain of, a base host.
	 *
	 * A plain substring check is not safe here: for base host `example.com`,
	 * hosts like `example.com.evil.net` or `notexample.com` contain the base
	 * host but are unrelated domains.
	 *
	 * @param string $host Host to check (e.g. from a permalink)
	 * @param string $base_host Base host (e.g. the site host)
	 * @return boolean
	 */
	public static function is_same_host_or_subdomain( $host, $base_host ): bool {
		if ( empty( $host ) || empty( $base_host ) || ! is_string( $host ) || ! is_string( $base_host ) ) {
			return false;
		}
		$host      = strtolower( $host );
		$base_host = strtolower( $base_host );

		return $host === $base_host || static::endsWith( '.' . $base_host, $host );
	}

	/**
	 * Determines whether string `$subject` begins with string `$search`.
	 *
	 * @param string|int $search
	 * @param string     $needle
	 * @return boolean
	 */
	public static function beginsWith( $search, $subject ): bool {
		$search = (string) $search;
		$length = strlen( $search );
		if ( 0 === $length ) {
			return true;
		}
		return substr( $subject, 0, $length ) === $search;
	}

	/**
	 * Appends a string to the end of a string.
	 *
	 * @param string $append
	 * @param string $subject
	 * @param string $appendWith
	 * @return string the concatenated string
	 */
	public static function append( $append, $subject = '', $appendWith = ' ' ): string {
		if ( empty( $append ) ) {
			return $subject;
		}
		return $subject . $appendWith . $append;
	}

	/**
	 * Prepends a string to the beginning of a string.
	 *
	 * @param string $prepend
	 * @param string $subject
	 * @param string $appendWith
	 * @return string the concatenated string
	 */
	public static function prepend( $prepend, $subject, $prependWith = ' ' ): string {
		if ( empty( $prepend ) || empty( $subject ) ) {
			return $subject;
		}
		return $prepend . $prependWith . $subject;
	}

	/**
	 * Combines multiple strings together with glue.
	 *
	 * @param string|array $one either a string or an array of strings
	 * @param string       $two either the second string to combine or the glue to combine an array of strings with
	 * @param string       $glue the glue to combine strings with if $one is not an array
	 * @return string $final the combined string
	 */
	public static function combine( $one, $two = ' ', $glue = ' ' ): string {
		if ( is_array( $one ) ) {
			$glue  = $two;
			$final = implode( $glue, $one );
			return trim( $final, $glue );
		}
		return trim( $one . $glue . $two );
	}

	public static function snake( $value, $delimiter = '_' ): string {
		$key = $value . $delimiter;
		if ( isset( static::$snakeCache[ $key ] ) ) {
			return static::$snakeCache[ $key ];
		}
		if ( ! ctype_lower( $value ) ) {
			$replace = '$1' . $delimiter . '$2';
			$value   = strtolower( preg_replace( '/(.)([A-Z])/', $replace, $value ) );
		}
		static::$snakeCache[ $key ] = $value;
		return static::$snakeCache[ $key ];
	}

	public static function truncate( $value, $maximum_length = 255, $end_with = '...' ): string {
		$value = wp_strip_all_tags(  $value );
		if ( self::length( $value ) <= $maximum_length ) {
			return $value;
		}

		$maximum_length = $maximum_length - self::length( $end_with );
		$parts          = preg_split( '/([\s\n\r]+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		$parts_count    = count( $parts );

		$length    = 0;
		$last_part = 0;
		for ( ; $last_part < $parts_count; ++$last_part ) {
			$length += strlen( $parts[ $last_part ] );
			if ( $length > $maximum_length ) {
				break;
			}
		}

		return trim( implode( array_slice( $parts, 0, $last_part ) ) ) . $end_with;
	}

	/**
	 * Get the class "basename" of the given object / class.
	 *
	 * @param  string|object $class
	 * @return string
	 */
	public static function class_basename( $class ) {
		$class = is_object( $class ) ? get_class( $class ) : $class;
		return basename( str_replace( '\\', '/', $class ) );
	}
}
