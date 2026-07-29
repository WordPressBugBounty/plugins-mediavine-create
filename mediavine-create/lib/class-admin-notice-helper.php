<?php
namespace Mediavine\Create;

/**
 * Shared helpers for admin notice dismissal and Create admin page detection.
 */
class Admin_Notice_Helper {

	/**
	 * Whether the current (or given) admin request URI is a Create plugin page.
	 *
	 * Uses the same URL param list as Admin_Init, without the post-editor
	 * special-case that loads Create scripts on arbitrary post types.
	 *
	 * @param string|null $request_uri Optional URI; defaults to $_SERVER['REQUEST_URI'].
	 * @return bool
	 */
	public static function is_create_page( $request_uri = null ) {
		if ( null === $request_uri ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		}

		if ( ! is_string( $request_uri ) || '' === $request_uri ) {
			return false;
		}

		/**
		 * Filters the Create admin URL strings checked against.
		 *
		 * @param array $mv_create_url_params List of URL strings to check.
		 */
		$needles = apply_filters( 'mv_create_url_params', Admin_Init::$mv_create_url_params );

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $request_uri, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a nonce-protected dismiss URL for the given action.
	 *
	 * @param string $action Dismiss action slug (also used as the nonce action).
	 * @param string $value  Value for the GET param (default '1').
	 * @return string
	 */
	public static function get_dismiss_url( $action, $value = '1' ) {
		return wp_nonce_url(
			add_query_arg( $action, $value ),
			$action
		);
	}

	/**
	 * If the current request is a valid dismiss for $action, return the value; else null.
	 *
	 * @param string $action Dismiss action slug / nonce action.
	 * @return string|null
	 */
	public static function get_verified_dismiss_value( $action ) {
		if ( ! isset( $_GET[ $action ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return null;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( $_GET[ $action ] ) );
	}

	/**
	 * Redirect back after dismiss, stripping dismiss query args and the nonce.
	 *
	 * @param string[] $query_args Args to remove (in addition to _wpnonce).
	 * @return void
	 */
	public static function redirect_after_dismiss( array $query_args ) {
		$redirect_url = remove_query_arg( array_merge( $query_args, [ '_wpnonce' ] ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
