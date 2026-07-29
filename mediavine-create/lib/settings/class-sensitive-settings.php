<?php
namespace Mediavine\Create;

/**
 * Sensitive_Settings
 *
 * Redacts credential-class setting values before they are handed to the
 * browser (either via the localized admin payload or the settings REST API).
 *
 * The site JWT (`mv_create_api_token`) must never reach the browser: the admin
 * UI only needs to know whether the site is connected, so its value is replaced
 * with a non-secret presence flag. Other credential-class values (e.g. the
 * Amazon Creators API secret) are only exposed to users who can actually manage
 * them (`manage_options`); for everyone else the value is blanked.
 */
class Sensitive_Settings {

	/**
	 * Non-secret value substituted for a present site JWT. The admin UI only
	 * ever checks this for truthiness (`isSiteConnected`), so a boolean flag is
	 * enough and cannot be replayed against Studio APIs.
	 */
	const PRESENCE_FLAG = true;

	/**
	 * Slugs whose value is replaced with a presence flag for every user. The
	 * raw value is never needed browser-side.
	 *
	 * @return string[]
	 */
	public static function presence_only_slugs() {
		/**
		 * Filters the settings whose value is reduced to a presence flag before
		 * being sent to the browser.
		 *
		 * @param string[] $slugs Setting slugs.
		 */
		return apply_filters(
			'mv_create_presence_only_settings',
			[ 'mv_create_api_token' ]
		);
	}

	/**
	 * Slugs whose value is only exposed to users with `manage_options`. For
	 * every other user the value is blanked.
	 *
	 * @return string[]
	 */
	public static function admin_only_slugs() {
		/**
		 * Filters the credential settings that should only be sent to the
		 * browser for users who can manage options.
		 *
		 * @param string[] $slugs Setting slugs.
		 */
		return apply_filters(
			'mv_create_admin_only_settings',
			[ 'mv_create_creators_credential_secret' ]
		);
	}

	/**
	 * Return a redacted copy of a settings collection.
	 *
	 * Each item may be a stdClass object (localized payload) or an associative
	 * array (REST response). The originals are never mutated — the settings
	 * objects are shared with the server-side settings cache, so redacting in
	 * place would blank the real token for the rest of the request.
	 *
	 * @param array $settings         Collection of setting objects/arrays.
	 * @param bool  $include_privileged Whether the recipient can manage options.
	 * @return array Redacted copy of the collection.
	 */
	public static function redact( $settings, $include_privileged = false ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $settings;
		}

		$presence_slugs = self::presence_only_slugs();
		$admin_slugs    = self::admin_only_slugs();

		$redacted = [];
		foreach ( $settings as $key => $setting ) {
			$redacted[ $key ] = self::redact_item( $setting, $presence_slugs, $admin_slugs, $include_privileged );
		}

		return $redacted;
	}

	/**
	 * Redact a single setting item without mutating the original.
	 *
	 * @param object|array $setting            Setting object or array.
	 * @param string[]     $presence_slugs     Presence-only slugs.
	 * @param string[]     $admin_slugs        Admin-only slugs.
	 * @param bool         $include_privileged Whether the recipient can manage options.
	 * @return object|array Redacted copy.
	 */
	private static function redact_item( $setting, array $presence_slugs, array $admin_slugs, $include_privileged ) {
		if ( is_object( $setting ) ) {
			$slug = isset( $setting->slug ) ? $setting->slug : null;

			if ( null === $slug || ! self::should_redact( $slug, $presence_slugs, $admin_slugs, $include_privileged ) ) {
				return $setting;
			}

			$copy        = clone $setting;
			$copy->value = self::redacted_value( $slug, isset( $setting->value ) ? $setting->value : '', $presence_slugs );
			return $copy;
		}

		if ( is_array( $setting ) ) {
			$slug = isset( $setting['slug'] ) ? $setting['slug'] : null;

			if ( null === $slug || ! self::should_redact( $slug, $presence_slugs, $admin_slugs, $include_privileged ) ) {
				return $setting;
			}

			$setting['value'] = self::redacted_value( $slug, isset( $setting['value'] ) ? $setting['value'] : '', $presence_slugs );
			return $setting;
		}

		return $setting;
	}

	/**
	 * Whether a slug's value needs redacting for this recipient.
	 *
	 * @param string   $slug               Setting slug.
	 * @param string[] $presence_slugs     Presence-only slugs.
	 * @param string[] $admin_slugs        Admin-only slugs.
	 * @param bool     $include_privileged Whether the recipient can manage options.
	 * @return bool
	 */
	private static function should_redact( $slug, array $presence_slugs, array $admin_slugs, $include_privileged ) {
		if ( in_array( $slug, $presence_slugs, true ) ) {
			return true;
		}

		if ( ! $include_privileged && in_array( $slug, $admin_slugs, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Compute the redacted value for a slug.
	 *
	 * @param string   $slug           Setting slug.
	 * @param mixed    $value          Original value.
	 * @param string[] $presence_slugs Presence-only slugs.
	 * @return mixed Presence flag for present JWTs, empty string otherwise.
	 */
	private static function redacted_value( $slug, $value, array $presence_slugs ) {
		if ( in_array( $slug, $presence_slugs, true ) ) {
			// Preserve the disconnected state (empty) so connection checks stay accurate.
			return empty( $value ) ? '' : self::PRESENCE_FLAG;
		}

		return '';
	}
}
