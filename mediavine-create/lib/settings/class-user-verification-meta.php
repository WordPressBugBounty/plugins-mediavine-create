<?php
/**
 * User Verification Meta Helper Class
 *
 * This class handles storing and retrieving user-specific verification data
 * in WordPress user meta for Create Studio multi-user verification.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

/**
 * User Verification Meta helper class
 *
 * Provides methods for managing user-level verification data stored in WordPress user meta.
 */
class User_Verification_Meta {

	/**
	 * Meta key for user-specific API token
	 */
	const TOKEN_KEY = 'mv_create_studio_token';

	/**
	 * Meta key for verified email address
	 */
	const EMAIL_KEY = 'mv_create_studio_email';

	/**
	 * Meta key for verification timestamp
	 */
	const VERIFIED_AT_KEY = 'mv_create_studio_verified_at';

	/**
	 * Meta key for Studio email verification status
	 */
	const EMAIL_VERIFIED_KEY = 'mv_create_studio_email_verified';

	/**
	 * Meta key for link session ID (temporary, during linking)
	 */
	const LINK_SESSION_KEY = 'mv_create_studio_link_session';

	/**
	 * Get the user's Create Studio token.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return string|null The token or null if not set.
	 */
	public static function get_token( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$token = get_user_meta( $user_id, self::TOKEN_KEY, true );
		return ! empty( $token ) ? $token : null;
	}

	/**
	 * Set the user's Create Studio token.
	 *
	 * @param string   $token   The API token to store.
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function set_token( $token, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return (bool) update_user_meta( $user_id, self::TOKEN_KEY, sanitize_text_field( $token ) );
	}

	/**
	 * Get the user's verified email address.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return string|null The email or null if not set.
	 */
	public static function get_email( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$email = get_user_meta( $user_id, self::EMAIL_KEY, true );
		return ! empty( $email ) ? $email : null;
	}

	/**
	 * Set the user's verified email address.
	 *
	 * @param string   $email   The verified email address.
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function set_email( $email, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return (bool) update_user_meta( $user_id, self::EMAIL_KEY, sanitize_email( $email ) );
	}

	/**
	 * Get the user's verification timestamp.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return string|null ISO 8601 timestamp or null if not set.
	 */
	public static function get_verified_at( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$verified_at = get_user_meta( $user_id, self::VERIFIED_AT_KEY, true );
		return ! empty( $verified_at ) ? $verified_at : null;
	}

	/**
	 * Set the user's verification timestamp.
	 *
	 * @param string   $verified_at ISO 8601 timestamp.
	 * @param int|null $user_id     WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function set_verified_at( $verified_at, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return (bool) update_user_meta( $user_id, self::VERIFIED_AT_KEY, sanitize_text_field( $verified_at ) );
	}

	/**
	 * Get the user's Studio email verification status.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool Whether the Studio email is verified.
	 */
	public static function get_email_verified( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return '1' === get_user_meta( $user_id, self::EMAIL_VERIFIED_KEY, true );
	}

	/**
	 * Set the user's Studio email verification status.
	 *
	 * @param bool     $verified Whether the email is verified.
	 * @param int|null $user_id  WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function set_email_verified( $verified, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return (bool) update_user_meta( $user_id, self::EMAIL_VERIFIED_KEY, $verified ? '1' : '0' );
	}

	/**
	 * Get the user's pending link session ID.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return string|null The link session ID or null if not set.
	 */
	public static function get_link_session( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$session = get_user_meta( $user_id, self::LINK_SESSION_KEY, true );
		return ! empty( $session ) ? $session : null;
	}

	/**
	 * Set the user's pending link session ID.
	 *
	 * @param string   $session_id The link session ID.
	 * @param int|null $user_id    WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function set_link_session( $session_id, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return (bool) update_user_meta( $user_id, self::LINK_SESSION_KEY, sanitize_text_field( $session_id ) );
	}

	/**
	 * Clear the user's pending link session ID.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True on success, false on failure.
	 */
	public static function clear_link_session( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return delete_user_meta( $user_id, self::LINK_SESSION_KEY );
	}

	/**
	 * Check if the user is verified.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True if user has a valid token, false otherwise.
	 */
	public static function is_verified( $user_id = null ) {
		return ! empty( self::get_token( $user_id ) );
	}

	/**
	 * Store verification data when user is verified.
	 *
	 * @param string   $token       The user-specific API token.
	 * @param string   $email       The verified email address.
	 * @param string   $verified_at ISO 8601 timestamp of verification.
	 * @param int|null $user_id     WordPress user ID. Defaults to current user.
	 * @return bool True if all data was saved successfully.
	 */
	public static function store_verification( $token, $email, $verified_at, $user_id = null, $email_verified = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$token_saved       = self::set_token( $token, $user_id );
		$email_saved       = self::set_email( $email, $user_id );
		$verified_at_saved = self::set_verified_at( $verified_at, $user_id );
		self::clear_link_session( $user_id );

		if ( null !== $email_verified ) {
			self::set_email_verified( $email_verified, $user_id );
		}

		return $token_saved && $email_saved && $verified_at_saved;
	}

	/**
	 * Clear all user verification data.
	 *
	 * Used when disconnecting a user from Create Studio.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return bool True if all data was cleared.
	 */
	public static function clear_all( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		delete_user_meta( $user_id, self::TOKEN_KEY );
		delete_user_meta( $user_id, self::EMAIL_KEY );
		delete_user_meta( $user_id, self::VERIFIED_AT_KEY );
		delete_user_meta( $user_id, self::EMAIL_VERIFIED_KEY );
		delete_user_meta( $user_id, self::LINK_SESSION_KEY );

		return true;
	}

	/**
	 * Get all verification data for a user.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to current user.
	 * @return array Array containing token, email, verified_at, link_session, and is_verified.
	 */
	public static function get_all( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();

		return [
			'token'          => self::get_token( $user_id ),
			'email'          => self::get_email( $user_id ),
			'verified_at'    => self::get_verified_at( $user_id ),
			'email_verified' => self::get_email_verified( $user_id ),
			'link_session'   => self::get_link_session( $user_id ),
			'is_verified'    => self::is_verified( $user_id ),
		];
	}

}
