<?php

namespace Mediavine\Create\Importers;

use Mediavine\Create\Plugin;
use Mediavine\Settings;

/**
 * Main orchestrator class for Recipe Importers feature
 */
class Importers {

	/**
	 * Singleton instance
	 *
	 * @var Importers|null
	 */
	private static $instance = null;

	/**
	 * Recipe importer instance
	 *
	 * @var MV_Recipe_Importer|null
	 */
	public $recipe_importer = null;

	/**
	 * Admin instance
	 *
	 * @var Importers_Admin|null
	 */
	public $admin = null;

	/**
	 * Get singleton instance
	 *
	 * @return Importers
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if importers are enabled via settings
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Settings::get_setting( 'mv_create_enable_importers' );
	}

	/**
	 * Initialize the importers feature
	 */
	public function init() {
		// Always check for standalone plugin and handle migration
		add_action( 'admin_init', [ $this, 'handle_standalone_migration' ] );

		// Only initialize if enabled
		if ( ! self::is_enabled() ) {
			return;
		}

		$this->recipe_importer = MV_Recipe_Importer::get_instance();
		$this->admin           = new Importers_Admin();

		// Initialize components
		$this->recipe_importer->init();
		$this->admin->init();

		// Run migration fixes
		$this->run_migration_fixes();
	}

	/**
	 * Handle migration from standalone plugin
	 */
	public function handle_standalone_migration() {
		// Check if standalone plugin is active
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$standalone_slugs = [
			'mediavine-recipe-importers/mediavine-recipe-importer.php',
			'create-recipe-importers/mediavine-recipe-importer.php',
		];

		$active_slug = null;
		foreach ( $standalone_slugs as $slug ) {
			if ( is_plugin_active( $slug ) ) {
				$active_slug = $slug;
				break;
			}
		}

		if ( $active_slug ) {
			// Deactivate standalone plugin
			deactivate_plugins( $active_slug );

			// Enable integrated importers
			Settings::update_setting( 'mv_create_enable_importers', true );

			// Set transient for admin notice
			set_transient( 'mv_create_importer_migration_notice', true, WEEK_IN_SECONDS );
		}

		// Show migration notice if transient exists
		if ( get_transient( 'mv_create_importer_migration_notice' ) ) {
			add_action( 'admin_notices', [ $this, 'display_migration_notice' ] );
		}
	}

	/**
	 * Display migration notice
	 */
	public function display_migration_notice() {
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'mv_create_dismiss_importer_migration', '1' ),
			'mv_create_dismiss_importer_migration'
		);

		// Handle dismissal
		if (
			isset( $_GET['mv_create_dismiss_importer_migration'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mv_create_dismiss_importer_migration' )
		) {
			delete_transient( 'mv_create_importer_migration_notice' );
			return;
		}

		$settings_url = admin_url( 'edit.php?post_type=mv_create&page=settings' );
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Create Recipe Importer Migration', 'mediavine-create' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					wp_kses(
						/* translators: %s: URL to the Settings -> Advanced page */
						__( 'The standalone Create Recipe Importer plugin has been integrated into Create. The standalone plugin has been deactivated and the integrated importers have been enabled. You can manage this setting in <a href="%s">Settings → Advanced</a>.', 'mediavine-create' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_url( $settings_url )
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'mediavine-create' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Run migration fixes from standalone plugin
	 */
	private function run_migration_fixes() {
		if ( null === $this->recipe_importer ) {
			return;
		}

		$this->recipe_importer->fix_mpp_reviews();
		$this->recipe_importer->fix_tasty_reviews();
		$this->fix_wpurp_cuisine_imports();
		$this->fix_tasty_cuisine_keywords_imports();
	}

	/**
	 * Fixes cuisines that were imported as integers instead of text from WPURP.
	 */
	public function fix_wpurp_cuisine_imports() {
		global $wpdb;
		$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );
		if ( version_compare( $last_plugin_version, '1.4.19', '<' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
			$creations = $wpdb->get_results(
				"SELECT id, category, secondary_term, metadata
					FROM {$wpdb->prefix}mv_creations
					WHERE type='recipe'
						AND metadata LIKE '%wp_ultimate%'
						AND metadata NOT LIKE '%fixed_wpurp_cuisine%'",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids       = [];
			foreach ( $creations as $creation ) {
				if ( ! empty( $creation['secondary_term'] ) && is_numeric( $creation['secondary_term'] ) ) {
					$term = get_term( $creation['secondary_term'], 'cuisine' );
					if ( $term && ! is_wp_error( $term ) ) {
						$creation['secondary_term'] = $term->name;
					}
				}
				if ( ! empty( $creation['category'] ) && is_numeric( $creation['category'] ) ) {
					$term = get_term( $creation['category'], 'category' );
					if ( $term && ! is_wp_error( $term ) ) {
						$creation['category'] = $term->name;
					}
				}
				$metadata                      = json_decode( $creation['metadata'] );
				$metadata->fixed_wpurp_cuisine = true;
				$creation['metadata']          = wp_json_encode( $metadata );
				Plugin::$models_v2->mv_creations->update( $creation );
				$ids[] = $creation['id'];
			}
			\Mediavine\Create\Publish::update_publish_queue( $ids );
		}
	}

	/**
	 * Fixes missing cuisine and keyword imports from Tasty recipes.
	 */
	public function fix_tasty_cuisine_keywords_imports() {
		global $wpdb;
		$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );

		if ( version_compare( $last_plugin_version, '1.4.18', '<' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
			$creations = $wpdb->get_results(
				"SELECT id, original_post_id, secondary_term, keywords, metadata
					FROM {$wpdb->prefix}mv_creations
					WHERE type='recipe'
						AND original_post_id IS NOT NULL
						AND (
							secondary_term IS NULL
							OR keywords IS NULL
						)
						AND metadata LIKE '%tasty%'
						AND metadata NOT LIKE '%fixed_tasty_cuisine_keywords%'",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids       = [];
			foreach ( $creations as $creation ) {
				$post = get_post( $creation['original_post_id'] );
				if ( empty( $creation['secondary_term'] ) ) {
					$cuisine = get_post_meta( $post->ID, 'cuisine', true );
					if ( $cuisine ) {
						$cuisine_id                 = $this->recipe_importer->upsert_cuisine( $cuisine );
						$creation['secondary_term'] = $cuisine_id;
					}
				}
				if ( empty( $creation['keywords'] ) ) {
					$keywords = get_post_meta( $post->ID, 'keywords', true );
					if ( $keywords ) {
						$creation['keywords'] = $keywords;
					}
				}
				$metadata                               = json_decode( $creation['metadata'] );
				$metadata->fixed_tasty_cuisine_keywords = true;
				$creation['metadata']                   = wp_json_encode( $metadata );
				Plugin::$models_v2->mv_creations->update( $creation );
				$ids[] = $creation['id'];
			}
			\Mediavine\Create\Publish::update_publish_queue( $ids );
		}
	}
}
