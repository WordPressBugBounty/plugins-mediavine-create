<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine whether or not a table exists.
 *
 * @param string $table_name
 * @param string $prefix
 * @return bool
 */
function mv_create_table_exists( $table_name, $prefix = '' ) {
	global $wpdb;
	$table_name = ( $prefix ? $prefix : $wpdb->prefix ) . $table_name;
	$table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name );
	$statement  = "SHOW TABLES LIKE '%{$table_name}%'";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
	return ! empty( $wpdb->get_results( $statement ) );
}

/**
 * Gets a list of reviews by creation_id
 * @param integer $creation_id ID of Creation from which you want reviews.
 * @param array   $args limit and offset to get max number or paginate (default 50, 0)
 * @return array Returns an array of objects
 */
function mv_create_get_reviews( $creation_id, $args = [] ) {
	return \Mediavine\Create\Reviews::get_reviews( $creation_id, $args );
}

/**
 * Gets a list of Creation IDs associated with a Post ID.
 *
 * Can be filtered by card type.
 *
 * @param integer $post_id ID of WP Post from which you want a list of Associated Creations.
 * @param array   $filter_types Array of card types to filter by. And empty array will display all card types.
 * @return array Returns an array of objects
 */
function mv_get_post_creations( $post_id, $filter_types = [] ) {
	return \Mediavine\Create\Creations::get_creation_ids_by_post( $post_id, $filter_types );
}

/**
 * Gets a single creation by ID
 * @param  {number}  $id        Creation ID
 * @param  {boolean} $published Return published data
 * @return {object}             Card data
 */
function mv_create_get_creation( $id, $published = false ) {
	$creations_dbi = new \Mediavine\MV_DBI( 'mv_creations' );
	$creation      = $creations_dbi->find_one_by_id( $id );
	if ( $published ) {
		$published_content = '[]';
		if ( is_array( $creation ) && isset( $creation['published'] ) ) {
			$published_content = $creation['published'];
		}
		if ( is_object( $creation ) && isset( $creation->published ) ) {
			$published_content = $creation->published;
		}
		return json_decode( $published_content );
	}
	return $creation;
}

/**
 * Get a custom field registered to a creation
 *
 * @since 1.1.0
 * @param {number} $id   Creation ID
 * @param {string} $slug Custom field slug
 * @return {mixed} Value of field, or null if missing
 */
function mv_create_get_field( $id, $slug ) {
	$creation = mv_create_get_creation( $id );
	if ( empty( $creation ) || is_wp_error( $creation ) ) {
		return null;
	}

	$custom_fields = null;
	if ( is_array( $creation ) && isset( $creation['custom_fields'] ) ) {
		$custom_fields = $creation['custom_fields'];
	} elseif ( is_object( $creation ) && isset( $creation->custom_fields ) ) {
		$custom_fields = $creation->custom_fields;
	}

	if ( empty( $custom_fields ) || ! is_string( $custom_fields ) ) {
		return null;
	}

	$parsed_data = json_decode( $custom_fields, true );
	if ( empty( $parsed_data ) || ! is_array( $parsed_data ) || empty( $parsed_data[ $slug ] ) ) {
		return null;
	}
	return $parsed_data[ $slug ];
}

/**
 * Declares that a theme supports integration with a particular version of Create skins.
 * (For now, if a theme integrates, just pass 'v1')
 *
 * If this is _not_ called in the theme's functions.php file, custom skins will _not_ override defaults.
 *
 * @since 1.1.0
 * @param  {string} $version  Compatible version
 * @return {void}
 */
function mv_create_theme_support( $version ) {
	add_filter(
		'mv_create_style_version',
		function() use ( $version ) {
			return $version;
		}
	);
}


/**
 * Display a JTR button
 *
 * @param integer $id Creation ID. Optional
 * @param string  $type Creation Type. Optional
 */
function mv_create_jtr_button( $id = null, $type = null ) {
	global $post;

	if ( empty( $id ) || empty( $type ) ) {
		$atts = \Mediavine\Create\Creations_Jump_To_Recipe::get_jtr_atts( get_post_field( 'post_content', $post ) );
		$id   = $atts['id'];
		$type = $atts['type'];
	}

	echo do_shortcode( vsprintf( '[mv_create_jtr id="%d" type="%s"]', [ $id, $type ] ) );
}

/**
 * Register a custom field.
 *
 * A helper function to quickly register a custom field to the Custom Fields section of Create Cards.
 *
 * @since 1.1.0
 * @param array $field Refer to CustomFields.md for acceptable params
 * @return void
 */
function mv_create_register_custom_field( $field ) {
	add_filter(
		'mv_create_fields', function( $arr ) use ( $field ) {
			$arr[] = $field;
			return $arr;
		}
	);
}

/**
 * SSRF guard: decide whether a URL is safe to fetch server-side.
 *
 * Layers WordPress's wp_http_validate_url() (scheme, embedded credentials,
 * unsafe ports, unresolvable hosts, IPv6 literals, and its built-in private
 * ranges: 127/8, 10/8, 0/8, 172.16-31, 192.168) with an explicit check for the
 * IPv4 ranges WP misses — link-local 169.254.0.0/16 (the cloud-metadata
 * endpoint 169.254.169.254) and carrier-grade NAT 100.64.0.0/10.
 *
 * Requests to the site's own host are allowed without a DNS lookup, mirroring
 * wp_http_validate_url()'s same-host rule — self-requests are not the threat.
 *
 * @param string $url URL to validate.
 * @return bool True when the URL is safe to request.
 */
function mv_create_is_safe_remote_url( $url ) {
	if ( empty( $url ) || ! is_string( $url ) || ! wp_http_validate_url( $url ) ) {
		return false;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( empty( $host ) ) {
		return false;
	}
	$host = trim( $host, '[]' );

	$home_host = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
	if ( $home_host && strtolower( $home_host ) === strtolower( $host ) ) {
		return true;
	}

	// Resolve the host (a literal IP resolves to itself) and re-inspect the IP.
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ip = $host;
	} else {
		$ip = gethostbyname( $host );
		if ( ! $ip || $ip === $host ) {
			// Could not resolve to an IP — treat as unsafe.
			return false;
		}
	}

	// Reject loopback, private, and reserved ranges. This covers 169.254/16 and
	// all non-global IPv6 addresses.
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}

	// PHP's filter flags don't cover carrier-grade NAT (100.64.0.0/10); reject it too.
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$long = ip2long( $ip );
		if ( false !== $long && ( $long & 0xffc00000 ) === ( ip2long( '100.64.0.0' ) & 0xffc00000 ) ) {
			return false;
		}
	}

	return true;
}

/**
 * SSRF-safe wrapper around wp_safe_remote_get() that validates every redirect
 * hop with mv_create_is_safe_remote_url().
 *
 * WordPress follows redirects but only re-validates each Location with
 * wp_http_validate_url() (WP_Http::validate_redirects()), which — like the
 * initial-URL check — misses link-local (169.254.169.254) and CGNAT
 * (100.64.0.0/10). So a public URL that 302s to the cloud-metadata endpoint
 * would slip past a pre-check on the initial URL only. Here we disable
 * automatic redirect following and walk each hop ourselves, rejecting any hop
 * whose host isn't globally routable.
 *
 * @param string $url  URL to fetch.
 * @param array  $args Optional wp_remote_get() args. `redirection` caps the hop
 *                     count (default 5); it's enforced manually.
 * @return array|\WP_Error The final WP HTTP response array, or a WP_Error when a
 *                         hop is unsafe, the request fails, or redirects loop.
 */
function mv_create_safe_remote_get( $url, $args = [] ) {
	$max_redirects = isset( $args['redirection'] ) ? (int) $args['redirection'] : 5;
	// We follow redirects manually so each hop passes the stronger guard.
	$args['redirection'] = 0;

	$current = $url;
	for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
		if ( ! mv_create_is_safe_remote_url( $current ) ) {
			return new \WP_Error(
				'mv_create_unsafe_url',
				__( 'Refused to fetch a URL that resolves to a private or reserved address.', 'mediavine-create' )
			);
		}

		$response = wp_safe_remote_get( $current, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 300 || $code >= 400 ) {
			return $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( empty( $location ) ) {
			// A redirect status with no Location — nothing more to follow.
			return $response;
		}

		// Resolve relative redirects against the current URL before re-checking.
		$current = \WP_Http::make_absolute_url( $location, $current );
	}

	return new \WP_Error( 'http_request_failed', __( 'Too many redirects.', 'mediavine-create' ) );
}
