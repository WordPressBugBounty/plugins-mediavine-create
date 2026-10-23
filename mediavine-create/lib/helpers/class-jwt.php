<?php
namespace Mediavine\Create\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * JWT helpers for Create Studio tokens.
 *
 * Decodes payloads without verifying signatures. Studio JWTs are treated as
 * opaque site/user credentials; we only need to read claims like site_id.
 *
 * @package Create
 */
class Jwt {

	/**
	 * Decode a JWT payload segment (base64url) to an associative array.
	 *
	 * @param string $token Full JWT string (header.payload.signature).
	 * @return array|null Decoded payload, or null if the token is malformed.
	 */
	public static function decode_payload( $token ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			return null;
		}

		$token_parts = explode( '.', $token );

		if ( empty( $token_parts[1] ) ) {
			return null;
		}

		$json = self::decode_segment( $token_parts[1] );
		if ( null === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Decode a single JWT base64url segment to a raw string.
	 *
	 * JWT segments use the URL-safe alphabet (`-`/`_`) and omit padding.
	 * Plain `base64_decode()` silently drops those characters and yields garbage.
	 *
	 * @param string $segment Base64url-encoded segment.
	 * @return string|null Decoded bytes, or null on failure.
	 */
	public static function decode_segment( $segment ) {
		if ( ! is_string( $segment ) || '' === $segment ) {
			return null;
		}

		$b64 = strtr( $segment, '-_', '+/' );
		$pad = strlen( $b64 ) % 4;
		if ( $pad > 0 ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}

		$decoded = base64_decode( $b64, true );

		return false === $decoded ? null : $decoded;
	}
}
