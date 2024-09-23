<?php

/**
 * Checks for a minimum version
 *
 * @param int|string $minimum Minimum version to check
 * @param int|string $compare 'php' to check against PHP, 'wp' to check against WP, or a specific
 *                            value to check against
 * @return boolean True if the version is compatible
 */
function mv_create_is_compatible_check( $minimum, $compare = 0 ) {
	if ( 'php' === $compare ) {
		$compare = PHP_VERSION;
	}
	if ( 'wp' === $compare ) {
		global $wp_version;
		$compare = $wp_version;
	}

	if ( version_compare( $compare, $minimum, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Checks if Create is compatible
 *
 * @param boolean $return_errors Should the errors found be returned instead of false
 * @return boolean|array True if compatible. False or array of errors if not compatible
 */
function mv_create_is_compatible( $return_errors = false ) {
	$deprecated_wp   = '5.6'; // Current version was 5.9 when this was last updated.
	$minimum_wp      = '5.2'; // We should support at least ~4 versions of WordPress.
	$minimum_php     = '7.2';
	$deprecated_php  = '7.3'; // WP only requires 5.6.20, but PHP 7.3 support ended Dec 2021.
	$recommended_php = '7.4';

	$errors = [];

	if ( ! mv_create_is_compatible_check( $minimum_php, 'php' ) ) {
		$errors['php']             = $minimum_php;
		$errors['recommended_php'] = $recommended_php;
	}

	if ( ! mv_create_is_compatible_check( $minimum_wp, 'wp' ) ) {
		$errors['wp'] = $minimum_wp;
	}

	if ( $return_errors ) {
		if ( ! mv_create_is_compatible_check( $deprecated_php, 'php' ) ) {
			$errors['deprecated_php']  = $deprecated_php;
			$errors['recommended_php'] = $recommended_php;
		}

		if ( ! mv_create_is_compatible_check( $deprecated_wp, 'wp' ) ) {
			$errors['deprecated_wp'] = $deprecated_wp;
		}
	}

	if ( ! empty( $errors ) ) {
		if ( $return_errors ) {
			return $errors;
		}
		return false;
	}

	return true;
}

/**
 * Displays a WordPress admin error notice
 *
 * @param string $message Message to display in notice
 * @param string $type Type of notice. Defaults to error
 * @param string $dismissible Make param value 'is-dismissible' to make notice dismissible. Defaults to empty.
 * @return void
 */
function mv_create_admin_error_notice( $message, $type = 'error', $dismissible = '' ) {
	printf(
		'<div class="notice notice-%2$s %3$s"><p>%1$s</p></div>',
		wp_kses(
			$message,
			[
				'strong' => [],
				'code'   => [],
				'br'     => [],
				'a'      => [
					'href'   => true,
					'target' => true,
				],
			]
		),
		esc_attr( $type ),
		esc_attr( $dismissible )
	);
}

function mv_create_permalink_check() {

	// if no permalinks
	if ( ! get_option( 'permalink_structure' ) ) {
		$notice = sprintf(
		// translators: Link to learn about enabling permalinks
			__( '<strong>Create by Mediavine</strong> uses the WordPress REST API to power its functionality. In order for this to work properly, pretty permalinks must be enabled.<br><br>%1$s', 'mediavine' ),
			'<a href="https://wordpress.org/support/article/using-permalinks/" target="_blank">' . __( 'Learn about enabling permalinks', 'mediavine' ) . '</a>'
		);
		mv_create_admin_error_notice( $notice );
	}

}

/**
 * Adds incompatibility notices to admin if WP or PHP needs to be updated
 *
 * @return void
 */
function mv_create_incompatible_notice() {
	$compatible_errors = mv_create_is_compatible( true );
	$deactivate_plugin = false;
	if ( is_array( $compatible_errors ) ) {

		// Incompatible PHP notice
		if ( isset( $compatible_errors['php'] ) ) {
			$notice = sprintf(
			// translators: Required PHP version number; Recommended PHP version number; Current PHP version number; Link to learn about updating PHP
				__( '<strong>Create by Mediavine</strong> requires PHP version %1$s or higher, but recommends %2$s or higher. This site is running PHP version %3$s.<br><br>%4$s.', 'mediavine' ),
				$compatible_errors['php'],
				$compatible_errors['recommended_php'],
				PHP_VERSION,
				'<a href="https://wordpress.org/support/update-php/" target="_blank">' . __( 'Learn about updating PHP', 'mediavine' ) . '</a>'
			);
			mv_create_admin_error_notice( $notice );
			$deactivate_plugin = true;
		}

		// Incompatible WP notice
		if ( isset( $compatible_errors['wp'] ) ) {
			global $wp_version;
			$notice = sprintf(
			// translators: Required WP version number; Current WP version number
				__( '<strong>Create by Mediavine</strong> requires WordPress %1$s or higher. This site is running WordPress %2$s. Please update WordPress to activate <strong>Create by Mediavine</strong>.', 'mediavine' ),
				$compatible_errors['wp'],
				$wp_version,
				'<a href="https://wordpress.org/support/article/updating-wordpress/" target="_blank">' . __( 'Learn about updating WordPress', 'mediavine' ) . '</a>'
			);
			mv_create_admin_error_notice( $notice );
			$deactivate_plugin = true;
		}

		// Deprecated PHP warning
		if ( isset( $compatible_errors['deprecated_php'] ) ) {
			$notice = sprintf(
			// translators: Date within styled tag; Required PHP version number; Recommended PHP version number; Current PHP version number; Link to learn about updating PHP
				__( 'Starting %1$s, <strong>Create by Mediavine</strong> will require PHP version %2$s, but recommends %3$s or higher. This site is running PHP version %4$s. To maintain compatibility with <strong>Create by Mediavine</strong>, please upgrade your PHP version.<br><br>%5$s.', 'mediavine' ),
				'<strong style="font-size: 1.2em;">' . __( 'January 2021', 'mediavine' ) . '</strong>',
				$compatible_errors['deprecated_php'],
				$compatible_errors['recommended_php'],
				PHP_VERSION,
				'<a href="https://wordpress.org/support/update-php/" target="_blank">' . __( 'Learn about updating PHP', 'mediavine' ) . '</a>'
			);
			mv_create_admin_error_notice( $notice );
		}

		// Deprecated WP warning
		if ( isset( $compatible_errors['deprecated_wp'] ) ) {
			global $wp_version;
			$notice = sprintf(
			// translators: Date within styled tag; Required WP version number
				__( 'Starting %1$s, WordPress %2$s will be required for all functionality, however keeping WordPress up-to-date at the latest version is still recommended. To maintain future compatibility with <strong>Create by Mediavine</strong>, please update WordPress.', 'mediavine' ),
				'<strong style="font-size: 1.2em;">' . __( 'January 2021', 'mediavine' ) . '</strong>',
				$compatible_errors['deprecated_wp']
			);
			$notice .= '<br><br><a href="https://wordpress.org/support/article/updating-wordpress/" target="_blank">' . __( 'Learn about updating WordPress', 'mediavine' ) . '</a>';
			mv_create_admin_error_notice( $notice );
		}

		// Should we deactivate the plugin?
		if ( $deactivate_plugin ) {
			mv_create_admin_error_notice( __( '<strong>Create by Mediavine</strong> has been deactivated.', 'mediavine' ) );
			deactivate_plugins( MV_CREATE_PLUGIN_FILE );
			return;
		}
	}
}

function mv_create_throw_warnings() {
	$compatible    = true;
	$missing_items = [];
	if ( ! extension_loaded( 'mbstring' ) ) {
		$missing_items[] = 'php-mbstring';
		$compatible      = false;
	}
	if ( ! extension_loaded( 'xml' ) ) {
		$missing_items[] = 'php-xml';
		$compatible      = false;
	}
	if ( $compatible || empty( $missing_items ) ) {
		return;
	}

	$message = trim( implode( ', ', $missing_items ), ', ' );

	$notice = sprintf(
	// translators: a list of disabled PHP extensions
		__( '<strong>Create by Mediavine</strong> requires the following disabled PHP extensions in order to function properly: <code>%1$s</code>.<br/><br/>Your hosting environment does not currently have these enabled.<br/><br/>Please contact your hosting provider and ask them to ensure these extensions are enabled.', 'mediavine' ),
		$message
	);

	mv_create_admin_error_notice( $notice );
	return;
}

/**
 * Add a custom link to the action links below Description on the Plugins page
 *
 * @param array $links Array of links to include on Plugins page
 *
 * @return array
 */
function mv_create_add_action_links( $links ) {
	$create_links = [
		'<a href="' . admin_url( 'options-general.php?page=mv_settings' ) . '">Settings</a>',
	];
	if ( \Mediavine\Create\Plugin_Checker::is_mcp_active() ) {
		$create_links[] = '<a href="https://help.mediavine.com">Support</a>';
	}

	return array_merge( $links, $create_links );
}

function mv_create_plugin_info_links( $links, $file ) {
	if ( strpos( $file, 'mediavine-create.php' ) !== false ) {
		$new_links = [
			'importers' => '<a href="https://www.mediavine.com/mediavine-recipe-importers-download" target="_blank">Download Mediavine Recipe Importers Plugin</a>',
		];
		$links     = array_merge( $links, $new_links );
	}

	return $links;
}

/**
 * Return the plugin's absolute path.
 *
 * @param string $path Path to add to plugin abs path. Optional
 * @return string
 */
function mv_create_plugin_abs_path( $path = '' ) {
	$plugin_dir_path = MV_CREATE_DIR;
	if ( ! empty( $path ) ) {
		return $plugin_dir_path . trailingslashit( $path );
	}

	return $plugin_dir_path;
}

/**
 * Return the plugin's basename path.
 *
 * @param string $path Path to add to plugin abs path. Optional
 * @return string
 */
function mv_create_plugin_basename_dir( $path = '' ) {
	$plugin_base_path = trailingslashit( MV_CREATE_BASENAME_DIR );

	if ( empty( $path ) ) {
		return $plugin_base_path;
	}

	return $plugin_base_path . trailingslashit( $path );
}

/**
 * Return the plugin's base url.
 *
 * @param string $path Path to add to plugin base url. Optional.
 * @return string
 */
function mv_create_plugin_dir_url( $path = '' ) {
	$plugin_dir_url = trailingslashit( MV_CREATE_URL );

	if ( empty( $path ) ) {
		return $plugin_dir_url;
	}

	return $plugin_dir_url . $path;
}
