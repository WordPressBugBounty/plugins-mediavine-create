<?php

namespace Mediavine\Create;

/**
 * One-off cron sweep that regenerates attachment metadata for images Create
 * sideloaded without it.
 *
 * Scheduled from the `mv_create_plugin_updated` chain and run on the
 * `mv_create_backfill_image_metadata` cron hook; both registrations live in
 * Plugin::init() so their order stays pinned by the bootstrap test.
 */
class Image_Metadata_Backfill {

	/**
	 * Cron hook name. Sites already have events scheduled against it, so it
	 * must never change.
	 */
	const HOOK = 'mv_create_backfill_image_metadata';

	/**
	 * Schedule a one-off background sweep that regenerates attachment metadata
	 * for images Create sideloaded without it.
	 *
	 * Versions 2.1.0–2.5.x deferred image processing during REST requests to a
	 * path that bailed before doing any work, so images Create downloaded
	 * (list-item thumbnails, product and import images) were left with empty
	 * `_wp_attachment_metadata`. That blocks image optimizers and thumbnail
	 * generation. The sweep repairs the existing damage in small batches.
	 *
	 * @param string $last_plugin_version Version being upgraded from.
	 *
	 * @return void
	 */
	public static function schedule( $last_plugin_version = '' ) {
		if ( empty( $last_plugin_version ) ) {
			$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );
		}

		// Only installs that ran an affected version (the bug landed in 2.1.0)
		// can have damaged images.
		if ( version_compare( $last_plugin_version, '2.1.0', '<' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Regenerate missing attachment metadata in small batches, rescheduling
	 * itself until every affected image has been processed.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		$batch_size = 25;

		// Attachments Create sideloaded (they carry an `origin_uri` marker) that
		// never received attachment metadata, excluding any already repaired or
		// previously found to be unrepairable (e.g. the original file is gone).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; table is $wpdb->prefix . literal or core table; values bound via prepare() where applicable
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} origin
					ON origin.post_id = p.ID AND origin.meta_key = 'origin_uri'
				LEFT JOIN {$wpdb->postmeta} meta
					ON meta.post_id = p.ID AND meta.meta_key = '_wp_attachment_metadata'
				LEFT JOIN {$wpdb->postmeta} done
					ON done.post_id = p.ID AND done.meta_key = '_mv_create_metadata_backfilled'
				WHERE p.post_type = 'attachment'
					AND done.meta_id IS NULL
					AND ( meta.meta_id IS NULL OR meta.meta_value = '' OR meta.meta_value = %s )
				LIMIT %d",
				'a:0:{}',
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $attachment_ids ) ) {
			return;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$generated     = Images::generate_base_attachment_metadata( $attachment_id );

			// Mark unrepairable images so a missing original file can't trap the
			// sweep in an endless loop. Repaired images now have metadata and
			// naturally fall out of the query above.
			if ( empty( $generated ) ) {
				update_post_meta( $attachment_id, '_mv_create_metadata_backfilled', true );
			}
		}

		// More may remain — process the next batch on the following cron tick.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}
}
