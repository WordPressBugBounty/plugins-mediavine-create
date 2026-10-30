<?php

namespace Mediavine\Create;

/**
 * Activation, deactivation, and the version-upgrade chain.
 *
 * plugin_update_check() runs at `init` priority 1 and fires
 * `mv_create_plugin_updated`; the listeners below hang off it at hand-tuned
 * priorities (tables before settings before shapes before republishing). All
 * registrations stay in Plugin::init(), in order, pinned by the bootstrap test.
 *
 * Because the chain runs at `init` @1 — before Plugin::init_translatable_data()
 * fills Plugin::$settings / Plugin::$shapes at @2 — create_settings() and
 * create_shapes() lazily fill those statics themselves. Keep those guards.
 *
 * See docs/MigrationPolicy.md for when version-gated blocks may be dropped.
 */
class Upgrader {

	/**
	 * Runs hook at plugin activation.
	 *
	 * The update hook will run a bit later through its own hook
	 *
	 * @return void
	 */
	public static function plugin_activation() {
		do_action( Plugin::PLUGIN_DOMAIN . '_plugin_activated' );
	}

	/**
	 * Runs hook at plugin update.
	 *
	 * This runs after all plugins are loaded so it can run after update. It also performs a
	 * check based on version number, just in case someone updates in a non-conventional way.
	 * After completing hooks, Create version number is updated in the db.
	 *
	 * @return void
	 */
	public static function plugin_update_check() {
		if ( get_option( 'mv_create_version' ) === Plugin::VERSION ) {
			return;
		}

		$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );

		/**
		 * Runs just before the plugin saves its new version to the database.
		 *
		 * @param string $last_plugin_version The last version the plugin was on. If this is a new
		 *                                    install, the last plugin version will be the current version.
		 */
		do_action( Plugin::PLUGIN_DOMAIN . '_plugin_updated', $last_plugin_version );

		update_option( 'mv_create_version', Plugin::VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Runs hook at plugin deactivation and flushes rewrite rules.
	 *
	 * @return void
	 */
	public static function plugin_deactivation() {
		do_action( Plugin::PLUGIN_DOMAIN . '_plugin_deactivated' );
		flush_rewrite_rules();
	}

	/**
	 * Create or upgrade Create's custom tables.
	 *
	 * @return void
	 */
	public static function generate_tables() {
		\Mediavine\MV_DBI::upgrade_database_check( Plugin::PLUGIN_DOMAIN, Plugin::DB_VERSION );
	}

	/**
	 * Write the registered settings (Plugin::$settings) to mv_settings.
	 *
	 * @return void
	 */
	public static function create_settings() {
		if ( null === Plugin::$settings ) {
			Plugin::$settings = Settings_Registration::get_settings();
		}
		$settings = self::update_settings( Plugin::$settings );
		\Mediavine\Settings::create_settings_filter( $settings );
	}

	/**
	 * Upsert the code-declared shapes into mv_shapes.
	 *
	 * @return void
	 */
	public static function create_shapes() {
		if ( null === Plugin::$shapes ) {
			Plugin::$shapes = Shapes::get_shapes_data();
		}
		$shape_dbi = new \Mediavine\MV_DBI( 'mv_shapes' );

		foreach ( Plugin::$shapes as $shape ) {
			$shape_dbi->upsert( $shape );
		}
	}

	/**
	 * Migrates old settings to newer versions within settings table
	 *
	 * Always check for less than current version as this is run before the version is updated
	 * Add estimated removal date (6 months) so we don't clutter code with future publishes
	 * Remove code within this function, but don't remove this function
	 *
	 * Example usage:
	 * ```
	 * if ( version_compare( $last_plugin_version, '1.0.0', '<' ) ) {
	 *     $settings = \Mediavine\Settings::migrate_setting_value( $settings, Plugin::$settings_group . '_slug', 'old_value', 'new_value' );
	 *     $settings = \Mediavine\Settings::migrate_setting_slug( $settings, Plugin::$settings_group . '_old_slug', Plugin::$settings_group . '_new_slug' );
	 * }
	 * ```
	 *
	 * @param   array $settings  Current list of settings before running create settings
	 * @return  array             List of settings after migrated changes made
	 */
	public static function update_settings( $settings ) {
		// Version-gated setting migrations live here. Drop any block once every
		// supported install has passed its version gate (see docs/MigrationPolicy.md).

		// "Allow Anonymous Ratings" → review threshold (idempotent; acts once).
		$settings = Rating_Policy::migrate_legacy_settings( $settings );

		return $settings;
	}

	/**
	 * Deletes old settings that are no longer used in Create
	 *
	 * @return void
	 */
	public static function delete_old_settings() {
		\Mediavine\Settings::delete_setting( Plugin::$settings_group . '_enable_link_scraping' );
		\Mediavine\Settings::delete_setting( Plugin::$settings_group . '_ad_density' );
		\Mediavine\Settings::delete_setting( Plugin::$settings_group . '_measurement_system' );
	}

	/**
	 * Migrates settings to the new Reader Experience group.
	 *
	 * Moves settings from Pro and Advanced groups to the new reader_experience group
	 * as part of the Settings UI Redesign.
	 *
	 * Settings moved:
	 * - From Pro: Jump to Recipe, Social Footer settings
	 * - From Advanced: Checklists, Reviews, Ratings settings
	 *
	 * @since 2.1.0
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public static function migrate_reader_experience_settings( $last_plugin_version ) {
		// Only run migration for versions before the settings redesign.
		if ( ! version_compare( $last_plugin_version, '2.1.0', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Jump to Recipe settings from Pro to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s",
				Plugin::$settings_group . '_reader_experience',
				'%jump_to_recipe%',
				'%jump_to_how_to%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Social Footer settings from Pro to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s OR slug LIKE %s",
				Plugin::$settings_group . '_reader_experience',
				'%social_footer%',
				'%social_service%',
				'%facebook_username%',
				'%instagram_username%',
				'%pinterest_username%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Checklists, Reviews, and Ratings settings from Advanced to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s OR slug LIKE %s OR slug LIKE %s",
				Plugin::$settings_group . '_reader_experience',
				'%checklist%',
				'%review%',
				'%rating%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Move Display settings to Appearance group.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE `group` = %s",
				Plugin::$settings_group . '_appearance',
				Plugin::$settings_group . '_display'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrates Interactive Mode settings to their own group.
	 *
	 * Moves Interactive Mode settings from reader_experience to interactive_mode
	 * group so they appear in their own settings section.
	 *
	 * @since 2.0.9
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public static function migrate_interactive_mode_settings( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.9', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Interactive Mode settings from reader_experience to interactive_mode.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug LIKE %s",
				Plugin::$settings_group . '_interactive_mode',
				'%interactive_mode%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Remove legacy debugging setting.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->delete( $table, [ 'slug' => Plugin::$settings_group . '_enable_debugging' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Migrates card style preview image URLs from PNG to WebP format.
	 *
	 * In 2.0.10, card style preview images were converted from PNG to WebP.
	 * The image URLs are stored as JSON in the settings data column and need
	 * to be updated so the theme selector doesn't show 404 images on upgrade.
	 *
	 * @since 2.0.10
	 *
	 * @param string $last_plugin_version The version being upgraded from.
	 */
	public static function migrate_card_style_preview_images( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.10', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Replace .png with .webp in the data JSON for card style settings.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			"UPDATE {$table} SET data = REPLACE(data, 'card-style-editorial.png', 'card-style-editorial.webp'),
				data = REPLACE(data, 'card-style-modern.png', 'card-style-modern.webp'),
				data = REPLACE(data, 'card-style-big-image.png', 'card-style-big-image.webp'),
				data = REPLACE(data, 'card-style-default.png', 'card-style-default.webp'),
				data = REPLACE(data, 'card-style-dark.png', 'card-style-dark.webp'),
				data = REPLACE(data, 'card-style-centered.png', 'card-style-centered.webp'),
				data = REPLACE(data, 'card-style-centered-dark.png', 'card-style-centered-dark.webp')
			WHERE slug LIKE '%_card_style' AND data LIKE '%card-style-%.png%'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Migrates Hands-free Mode setting from Advanced to Reader Experience.
	 *
	 * @since 2.0.12
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public static function migrate_hands_free_to_reader_experience( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.0.12', '<' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mv_settings';

		// Move Hands-free Mode from advanced to reader_experience.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET `group` = %s WHERE slug = %s",
				Plugin::$settings_group . '_reader_experience',
				Plugin::$settings_group . '_enable_hands_free_mode'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * One-time normalization of the locally stored subscription tier.
	 *
	 * Rewrites the stored `mv_create_subscription_tier` setting to the canonical
	 * slug the new plugin reads, via GateKeeper::normalize_tier() (unknown->free,
	 * trial->free, anything unknown->free fail-closed). This only touches the
	 * LOCAL stored copy — it never changes 'pro' semantics for old plugin
	 * compatibility (Studio remains the source of truth and keeps emitting 'pro').
	 *
	 * Runs once on upgrade from < 2.6.0.
	 *
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public static function migrate_subscription_tier_to_canonical( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.6.0', '<' ) ) {
			return;
		}

		$stored = \Mediavine\Settings::get_setting( \Mediavine\Create\GateKeeper::SETTING_SUBSCRIPTION_TIER );
		if ( empty( $stored ) ) {
			return;
		}

		$canonical = \Mediavine\Create\GateKeeper::normalize_tier( $stored );
		if ( $canonical === $stored ) {
			return;
		}

		\Mediavine\Settings::create_settings(
			[
				'slug'  => \Mediavine\Create\GateKeeper::SETTING_SUBSCRIPTION_TIER,
				'value' => $canonical,
				'group' => 'mv_create_subscription',
			]
		);
		\Mediavine\Settings::reset_settings();
	}

	/**
	 * Republishes create cards depending on plugin version
	 *
	 * Always check for less than current version as this is run before the version is updated
	 * Add estimated removal date (6 months) so we don't clutter code with future publishes
	 * Remove code within this function, but don't remove this function
	 *
	 * @return  void
	 */
	public static function republish_queue() {
		global $wpdb;
		$creations = new \Mediavine\MV_DBI( 'mv_creations' );
		$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );
		$republish_ids       = [];

		// Republish cards with instructions that contain HTML entities (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.14', '<' ) ) {
			// Re-apply limit before each where(): query state resets after every call.
			$creations->set_limit( 10000 );
			$cards = $creations->where( [ 'published', 'LIKE', '%&lt;%' ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with rating_count > 0 (Remove January 2026)
		if ( version_compare( $last_plugin_version, '1.9.12', '<' ) ) {
			$creations->set_limit( 10000 );
			$cards = $creations->where( [ 'rating_count', '>', 0 ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $cards, 'id' ) ) );
		}

		// Republish cards with orphaned <li> tags in instructions (Remove February 2026)
		if ( version_compare( $last_plugin_version, '1.10.1', '<' ) ) {
			// Query for cards where published instructions start with <li> (orphaned list items)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
			$orphaned_list_cards = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mv_creations
					WHERE published IS NOT NULL
					AND published != ''
					AND JSON_EXTRACT(published, '$.instructions') LIKE %s
					AND (
						JSON_EXTRACT(published, '$.instructions') NOT LIKE %s
						OR LOCATE('<li', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))) <
							COALESCE(NULLIF(LOCATE('<ol', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))), 0), 999999)
					)
					AND (
						JSON_EXTRACT(published, '$.instructions') NOT LIKE %s
						OR LOCATE('<li', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))) <
							COALESCE(NULLIF(LOCATE('<ul', JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions'))), 0), 999999)
					)",
					'%<li%',
					'%<ol%',
					'%<ul%'
				)
			);
			if ( ! empty( $orphaned_list_cards ) ) {
				$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $orphaned_list_cards, 'id' ) ) );
			}
		}

		// Republish list cards so ItemList schema fixes (1-based positions,
		// numberOfItems, url/@id, lookalike-host hardening, entity decoding)
		// reach sites on the legacy inline JSON-LD path
		// (mv_create_schema_in_head=false). Head-path sites rebuild ItemList at
		// render time; inline-path sites serve frozen publish-time JSON-LD.
		// Remove after January 2027 (or 12 minor releases past 2.5.4).
		if ( version_compare( $last_plugin_version, '2.5.4', '<' ) ) {
			$creations->set_limit( 10000 );
			$list_cards    = $creations->where( [ 'type', '=', 'list' ] );
			$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $list_cards, 'id' ) ) );
		}

		// Republish recipe and how-to cards whose instructions have headings so
		// their stored JSON-LD groups steps into HowToSections.
		// Remove after April 2027.
		if ( version_compare( $last_plugin_version, '2.6.5.2', '<' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal; values bound via prepare()
			$sectioned_cards = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mv_creations
					WHERE type IN ( %s, %s )
					AND published IS NOT NULL
					AND published != ''
					AND JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions')) REGEXP %s
					AND JSON_UNQUOTE(JSON_EXTRACT(published, '$.instructions')) LIKE %s",
					'recipe',
					'diy',
					'<h[1-6]',
					'%<li%'
				)
			);
			if ( ! empty( $sectioned_cards ) ) {
				$republish_ids = array_merge( $republish_ids, array_values( wp_list_pluck( $sectioned_cards, 'id' ) ) );
			}
		}

		if ( ! empty( $republish_ids ) ) {
			\Mediavine\Create\Publish::update_publish_queue( $republish_ids );
		}
	}

	/**
	 * Repair card totals that don't match the sum of the times.
	 *
	 * Editors before 2.0.12 could save a Total Time that didn't match the
	 * other times (e.g. 10m prep + 20m cook + 1h30 additional saved as 30m),
	 * and nothing ever recalculated it. Remove after April 2027.
	 *
	 * @param string $last_plugin_version Version being upgraded from.
	 *
	 * @return void
	 */
	public static function repair_stale_total_times( $last_plugin_version = '' ) {
		if ( empty( $last_plugin_version ) ) {
			$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );
		}

		if ( version_compare( $last_plugin_version, '2.6.6', '<' ) ) {
			\Mediavine\Create\Creations::repair_stale_totals();
		}
	}

	/**
	 * Display importer admin notice if importers not enabled
	 *
	 * @return void
	 */
	public static function importer_admin_notice() {
		if ( ! class_exists( 'Mediavine\Create\Importer\Plugin' ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'importer_admin_notice_display' ] );
		}
	}

	/**
	 * Display importer admin notice
	 *
	 * @return void
	 */
	public static function importer_admin_notice_display() {
		$settings_url = admin_url( 'options-general.php?page=mv_settings&setting=mv_create_enable_importers' );
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
			wp_kses_post( __( 'Thanks for installing Create!', 'mediavine-create' ) ),
			wp_kses_post(
				sprintf(
					/* translators: %1$s: link to importer setting, %2$s: closing anchor tag */
					__( 'If you\'re moving from another recipe plugin, %1$senable the importer%2$s and breathe new life into your old recipes.', 'mediavine-create' ),
					'<a href="' . esc_url( $settings_url ) . '">',
					'</a>'
				)
			)
		);
	}

	/**
	 * Send version info to Create Studio API after a plugin update.
	 *
	 * Called via the mv_create_plugin_updated action hook.
	 * Sends current PHP, WP, and Create versions to the Studio API,
	 * and logs the version transition if the plugin was actually updated.
	 *
	 * @param string $last_plugin_version The previous plugin version before the update.
	 */
	public static function update_services_api( $last_plugin_version = '' ) {
		global $wp_version;

		$site_id = Create_Studio_Client::get_site_id();

		if ( empty( $site_id ) ) {
			return;
		}

		// Send current version info
		Create_Studio_Client::request( 'POST', '/sites/' . $site_id, [
			'php_version'    => PHP_VERSION,
			'wp_version'     => $wp_version,
			'create_version' => Plugin::VERSION,
		] );

		// Log the version transition if this is an actual update (not a fresh install)
		if ( ! empty( $last_plugin_version ) && $last_plugin_version !== Plugin::VERSION ) {
			Create_Studio_Client::request( 'POST', '/sites/' . $site_id . '/version-log', [
				'from' => $last_plugin_version,
				'to'   => Plugin::VERSION,
			] );
		}
	}

	/**
	 * Forces cache plugins to regenerate "used CSS" snapshots that may have
	 * pruned `.cs-*` / `.mv-create-*` selectors before the new safelist
	 * filters in the rascal classes were registered.
	 *
	 * Sites running WP Rocket / LiteSpeed / Perfmatters / FlyingPress used-CSS
	 * features will keep serving the stale snapshot — and the broken widget
	 * styling — until their cached copy is invalidated. The new rascal hooks
	 * (Wp_Rocket, Litespeed_Cache, Perfmatters, Flying_Press) only apply when
	 * those caches are regenerated.
	 *
	 * Runs once on upgrade from < 2.5.4.
	 *
	 * Bumped from 2.4.5 so sites that already ran the WP Rocket / LiteSpeed
	 * purge also regenerate after Perfmatters / FlyingPress safelists land.
	 * Remove by: ~2026-12 (see docs/MigrationPolicy.md).
	 *
	 * @param string $last_plugin_version The previous plugin version.
	 * @return void
	 */
	public static function purge_used_css_caches_for_widget_safelist( $last_plugin_version ) {
		if ( ! version_compare( $last_plugin_version, '2.5.4', '<' ) ) {
			return;
		}

		\Mediavine\Cache_Manager::purge_full_page_caches( 'Mediavine Create 2.5.4 widget CSS safelist' );
	}
}
