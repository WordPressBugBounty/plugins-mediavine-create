<?php
namespace Mediavine\Create;

/**
 * Keeps list-item relations in sync with their sources.
 *
 * Post and page items follow their WP post (relation_id): when the post's
 * featured image or title changes, matching wp_mv_relations rows with
 * sync_with_post enabled update in place. Card items follow the card itself
 * (relation_id → mv_creations): every card publish re-syncs stored title and
 * thumbnail. Either way, the parent creation's published cache is queued for
 * rebuild.
 *
 * Hooks only register when the site has access to the LIST_ITEM_SYNC feature.
 */
class Relations_Sync {

	/**
	 * Content types whose relations can follow a source.
	 * Posts/pages sync from the WP post; card items from the card row.
	 */
	const SYNCABLE_TYPES = [ 'post', 'page', 'card' ];

	/**
	 * The subset whose source of truth is a WP post.
	 */
	const POST_SOURCED_TYPES = [ 'post', 'page' ];

	/**
	 * @var Queue
	 */
	private $republish_queue;

	public function init() {
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_LIST_ITEM_SYNC ) ) {
			return;
		}

		$this->republish_queue = new Queue(
			[
				'queue_name'     => 'mv_list_sync_republish_queue',
				'transient_name' => 'mv_list_sync_republish_lock',
				'lock_timeout'   => 60,
				'auto_unlock'    => true,
			]
		);

		add_action( 'added_post_meta', [ $this, 'on_thumbnail_meta_written' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'on_thumbnail_meta_written' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'on_thumbnail_meta_deleted' ], 10, 4 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_source_post_removed' ], 10, 1 );
		add_action( 'trashed_post', [ $this, 'on_source_post_removed' ], 10, 1 );
		add_action( 'deleted_post', [ $this, 'on_attachment_deleted' ], 10, 2 );
		// Card items: every meaningful card save flows through publish_creation
		// (editor publish, importers, our own republish queue).
		add_action( 'mv_create_card_post_publish', [ $this, 'on_card_published' ], 10, 2 );
		add_action( 'init', [ $this, 'step_republish_queue' ] );
	}

	public function on_thumbnail_meta_written( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}
		$this->sync_field_for_post( (int) $post_id, 'thumbnail_id', (int) $meta_value );
	}

	public function on_thumbnail_meta_deleted( $meta_ids, $post_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}
		$this->sync_field_for_post( (int) $post_id, 'thumbnail_id', 0 );
	}

	public function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( empty( $post_after ) || empty( $post_before ) ) {
			return;
		}
		if ( $post_after->post_title === $post_before->post_title ) {
			return;
		}
		$this->sync_field_for_post( (int) $post_id, 'title', (string) $post_after->post_title );
	}

	public function on_source_post_removed( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		$this->disable_sync_for_post( $post_id );
	}

	/**
	 * Re-sync card items when their card publishes. The publish payload
	 * transforms fields, so the canonical row is re-read by id.
	 *
	 * @param object $creation  Prepped publish data (only ->id is trusted).
	 * @param string $published Published JSON (unused).
	 */
	public function on_card_published( $creation, $published = null ) {
		$creation_id = is_object( $creation ) ? (int) ( $creation->id ?? 0 ) : 0;
		if ( ! $creation_id ) {
			return;
		}
		$card = Plugin::$models_v2->mv_creations->find_one( $creation_id );
		if ( empty( $card ) ) {
			return;
		}
		$this->sync_card_relations( $creation_id, (string) $card->title, (int) $card->thumbnail_id );
	}

	public function on_attachment_deleted( $post_id, $post = null ) {
		if ( empty( $post ) || 'attachment' !== $post->post_type ) {
			return;
		}

		global $wpdb;
		$table   = Plugin::$models_v2->mv_relations->table_name;
		$post_id = (int) $post_id;

		// SECURITY CHECKED: prepared below.
		$affected = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT creation FROM {$table}
				 WHERE sync_with_post = 1 AND thumbnail_id = %d",
				$post_id
			)
		);

		if ( empty( $affected ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET thumbnail_id = 0, sync_with_post = 0
				 WHERE sync_with_post = 1 AND thumbnail_id = %d",
				$post_id
			)
		);

		$this->queue_republish( $affected );
	}

	public function step_republish_queue() {
		if ( empty( $this->republish_queue ) ) {
			return;
		}
		$this->republish_queue->step(
			function ( $creation_id ) {
				$creation_id = (int) $creation_id;
				if ( ! $creation_id ) {
					return;
				}
				Creations::publish_creation( $creation_id );
			}
		);
	}

	/**
	 * Sync a card's relations with its current title and thumbnail.
	 *
	 * Diff-aware on purpose: publish fires often (editor saves, importers, our
	 * own republish queue), and skipping rows already in the target state is
	 * what keeps queue → publish → sync from ping-ponging between lists that
	 * embed each other.
	 *
	 * @param int    $card_id      mv_creations id of the source card.
	 * @param string $title        The card's current title.
	 * @param int    $thumbnail_id The card's current thumbnail attachment id.
	 */
	private function sync_card_relations( $card_id, $title, $thumbnail_id ) {
		if ( ! $card_id ) {
			return;
		}

		global $wpdb;
		$table = Plugin::$models_v2->mv_relations->table_name;

		$affected = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT creation FROM {$table}
				 WHERE sync_with_post = 1 AND content_type = 'card' AND relation_id = %d
				   AND ( COALESCE( title, '' ) != %s OR COALESCE( thumbnail_id, 0 ) != %d )",
				[ $card_id, $title, $thumbnail_id ]
			)
		);
		if ( empty( $affected ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET title = %s, thumbnail_id = %d
				 WHERE sync_with_post = 1 AND content_type = 'card' AND relation_id = %d",
				[ $title, $thumbnail_id, $card_id ]
			)
		);

		$this->queue_republish( $affected );
	}

	/**
	 * Update a single syncable column on every post/page relation that
	 * references the given WP post, then queue parent creations for republish.
	 * Card items don't follow WP posts — they sync from the card on publish.
	 *
	 * @param int    $post_id WP post ID of the source post.
	 * @param string $field   Column to write — 'thumbnail_id' or 'title'.
	 * @param mixed  $value   New value.
	 */
	private function sync_field_for_post( $post_id, $field, $value ) {
		if ( ! $post_id ) {
			return;
		}
		if ( ! in_array( $field, [ 'thumbnail_id', 'title' ], true ) ) {
			return;
		}

		$where        = $this->sql_where_post_match();
		$affected     = $this->select_creations( $where['sql'], $where['args'], $post_id );
		if ( empty( $affected ) ) {
			return;
		}

		global $wpdb;
		$table        = Plugin::$models_v2->mv_relations->table_name;
		$value_format = 'thumbnail_id' === $field ? '%d' : '%s';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET {$field} = {$value_format}
				 WHERE {$where['sql']}",
				array_merge( [ $value ], $where['args']( $post_id ) )
			)
		);

		$this->queue_republish( $affected );
	}

	/**
	 * Turn sync off for every relation referencing a post that's been deleted/trashed.
	 */
	private function disable_sync_for_post( $post_id ) {
		$where    = $this->sql_where_post_match();
		$affected = $this->select_creations( $where['sql'], $where['args'], $post_id );
		if ( empty( $affected ) ) {
			return;
		}

		global $wpdb;
		$table = Plugin::$models_v2->mv_relations->table_name;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET sync_with_post = 0 WHERE {$where['sql']}",
				$where['args']( $post_id )
			)
		);

		$this->queue_republish( $affected );
	}

	/**
	 * Shared WHERE clause for "this post/page relation refers to $post_id" —
	 * sync_with_post=1 AND content_type IN ('post','page') AND relation_id=X.
	 * Card items are excluded: their source is the card, not a WP post.
	 *
	 * Returns SQL fragment plus a builder that produces the placeholder args so
	 * callers can compose a prepared statement without repeating the content-type
	 * list.
	 *
	 * @return array{sql:string,args:callable}
	 */
	private function sql_where_post_match() {
		$types_sql = $this->in_placeholders( self::POST_SOURCED_TYPES );
		return [
			'sql'  => "sync_with_post = 1 AND content_type IN ({$types_sql}) AND relation_id = %d",
			'args' => function ( $post_id ) {
				return array_merge( self::POST_SOURCED_TYPES, [ $post_id ] );
			},
		];
	}

	private function select_creations( $where_sql, callable $build_args, $post_id ) {
		global $wpdb;
		$table = Plugin::$models_v2->mv_relations->table_name;

		// SECURITY CHECKED: $where_sql contains only %s/%d placeholders.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT creation FROM {$table} WHERE {$where_sql}",
				$build_args( $post_id )
			)
		);
	}

	private function in_placeholders( array $values ) {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	}

	private function queue_republish( $creation_ids ) {
		if ( empty( $creation_ids ) || empty( $this->republish_queue ) ) {
			return;
		}
		$creation_ids = array_values( array_unique( array_map( 'intval', $creation_ids ) ) );
		$creation_ids = array_filter( $creation_ids );
		if ( empty( $creation_ids ) ) {
			return;
		}
		$this->republish_queue->push_many( $creation_ids );
	}

	/**
	 * REST permission callback: authorized user AND feature access.
	 */
	public function rest_permission( \WP_REST_Request $request ) {
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_LIST_ITEM_SYNC ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'This feature requires Create Pro.', 'mediavine-create' ),
				[ 'status' => 403 ]
			);
		}
		return \Mediavine\Permissions::is_user_authorized();
	}

	/**
	 * GET /list-sync/wizard-data
	 *
	 * Returns every list creation that has at least one syncable relation
	 * (post/page/card), with each relation's stored snapshot alongside the
	 * current value on its source — the WP post for post/page items, the card
	 * itself for card items — so the wizard can show a diff.
	 */
	public function rest_wizard_data( \WP_REST_Request $request, \WP_REST_Response $response ) {
		global $wpdb;

		$relations_table = Plugin::$models_v2->mv_relations->table_name;
		$creations_table = Plugin::$models_v2->mv_creations->table_name;
		$types_sql       = $this->in_placeholders( self::SYNCABLE_TYPES );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.creation, r.content_type, r.relation_id, r.canonical_post_id,
				        r.title, r.thumbnail_id, r.sync_with_post,
				        c.title AS list_title
				 FROM {$relations_table} r
				 INNER JOIN {$creations_table} c ON c.id = r.creation
				 WHERE r.content_type IN ({$types_sql})
				   AND c.type = 'list'
				 ORDER BY c.id DESC, r.position ASC",
				self::SYNCABLE_TYPES
			)
		);

		// Prime post caches so the per-row get_post() / get_post_thumbnail_id()
		// calls below hit the object cache instead of N individual queries, and
		// batch-fetch the source cards for card items.
		$source_post_ids = [];
		$source_card_ids = [];
		foreach ( (array) $rows as $row ) {
			if ( 'card' === $row->content_type ) {
				if ( (int) $row->relation_id ) {
					$source_card_ids[] = (int) $row->relation_id;
				}
			} elseif ( (int) $row->relation_id ) {
				$source_post_ids[] = (int) $row->relation_id;
			}
		}
		if ( ! empty( $source_post_ids ) ) {
			_prime_post_caches( array_values( array_unique( $source_post_ids ) ), true, true );
		}
		$source_cards = [];
		if ( ! empty( $source_card_ids ) ) {
			$card_ids_sql = implode( ', ', array_fill( 0, count( array_unique( $source_card_ids ) ), '%d' ) );
			$card_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, thumbnail_id FROM {$creations_table} WHERE id IN ({$card_ids_sql})",
					array_values( array_unique( $source_card_ids ) )
				)
			);
			foreach ( $card_rows as $card_row ) {
				$source_cards[ (int) $card_row->id ] = $card_row;
			}
		}

		$lists = [];
		foreach ( (array) $rows as $row ) {
			$stored_thumb_id = (int) $row->thumbnail_id;

			if ( 'card' === $row->content_type ) {
				$source_card     = $source_cards[ (int) $row->relation_id ] ?? null;
				$source_exists   = (bool) $source_card;
				$source_id       = (int) $row->relation_id;
				$source_title    = $source_card ? (string) $source_card->title : '';
				$source_thumb_id = $source_card ? (int) $source_card->thumbnail_id : 0;
			} else {
				$source_post     = (int) $row->relation_id ? get_post( (int) $row->relation_id ) : null;
				$source_exists   = (bool) $source_post;
				$source_id       = (int) $row->relation_id;
				$source_title    = $source_post ? (string) $source_post->post_title : '';
				$source_thumb_id = $source_post ? (int) get_post_thumbnail_id( $source_post->ID ) : 0;
			}

			$item = [
				'id'                   => (int) $row->id,
				'creation_id'          => (int) $row->creation,
				'content_type'         => $row->content_type,
				// For card items this is the source card's creation id; the
				// field name is kept for API stability.
				'source_post_id'       => $source_id,
				'source_exists'        => $source_exists,
				'title'                => (string) $row->title,
				'source_title'         => $source_title,
				'title_differs'        => $source_exists && ( $row->title !== $source_title ),
				'thumbnail_id'         => $stored_thumb_id,
				'thumbnail_uri'        => $stored_thumb_id ? wp_get_attachment_url( $stored_thumb_id ) : '',
				'source_thumbnail_id'  => $source_thumb_id,
				'source_thumbnail_uri' => $source_thumb_id ? wp_get_attachment_url( $source_thumb_id ) : '',
				'image_differs'        => $source_exists && ( $stored_thumb_id !== $source_thumb_id ),
				'sync_with_post'       => (int) $row->sync_with_post === 1,
			];

			$creation_id = (int) $row->creation;
			if ( ! isset( $lists[ $creation_id ] ) ) {
				$lists[ $creation_id ] = [
					'id'    => $creation_id,
					'title' => (string) $row->list_title,
					'items' => [],
				];
			}
			$lists[ $creation_id ]['items'][] = $item;
		}

		$data = [
			'lists'          => array_values( $lists ),
			'total_eligible' => array_sum( array_map( fn( $l ) => count( $l['items'] ), $lists ) ),
		];

		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 200 );
		return $response;
	}

	/**
	 * POST /list-sync/bulk-apply
	 *
	 * Body:
	 *   { "sync_all": true }   — turn sync on for every syncable relation
	 *   { "sync_all": false }  — turn sync off for every syncable relation
	 *   { "items": [ { "id": 123, "sync_with_post": 1 }, ... ] }
	 *   { "dismiss": true }    — mark wizard dismissed, no row updates
	 */
	public function rest_bulk_apply( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();
		global $wpdb;

		$table  = Plugin::$models_v2->mv_relations->table_name;
		$queued = [];
		$count  = 0;

		if ( array_key_exists( 'sync_all', $params ) ) {
			[ $queued, $count ] = $this->apply_sync_all( ! empty( $params['sync_all'] ) );
		} elseif ( ! empty( $params['items'] ) && is_array( $params['items'] ) ) {
			[ $queued, $count ] = $this->apply_sync_items( $params['items'] );
		}

		$this->queue_republish( $queued );

		// Flip the wizard-dismissed flag whenever the client hits this endpoint.
		// Groupless on purpose: the Settings UI renders every `mv_create_lists`
		// row, and this internal marker has no `data` to render with.
		// Settings::reset_settings() nukes the in-memory cache, so only invalidate
		// if there's actually a new setting to read on the next request.
		$already_dismissed = (bool) \Mediavine\Settings::get_setting( 'mv_create_list_sync_wizard_dismissed' );
		if ( ! $already_dismissed ) {
			\Mediavine\Settings::create_settings(
				[
					'slug'  => 'mv_create_list_sync_wizard_dismissed',
					'value' => '1',
				]
			);
			\Mediavine\Settings::reset_settings();
		}

		$data = [
			'updated'      => $count,
			'queued_lists' => count( array_unique( $queued ) ),
		];

		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 200 );
		return $response;
	}

	/**
	 * @return array{0:int[],1:int} [queued creation ids, affected row count]
	 */
	private function apply_sync_all( bool $on ) {
		global $wpdb;
		$table           = Plugin::$models_v2->mv_relations->table_name;
		$creations_table = Plugin::$models_v2->mv_creations->table_name;
		$types_sql       = $this->in_placeholders( self::SYNCABLE_TYPES );
		$target          = $on ? 1 : 0;

		// Only rows the update would actually change — a re-run must not
		// rewrite and republish lists that are already in the target state.
		// When enabling, skip relations whose source is gone: the wizard
		// renders those as "can't sync" and the sync hooks have nothing to
		// follow. Posts/pages source from wp_posts; cards from mv_creations.
		$source_guard = $on
			? " AND (
					( r.content_type IN ('post', 'page') AND EXISTS (
						SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = r.relation_id
					) )
					OR ( r.content_type = 'card' AND EXISTS (
						SELECT 1 FROM {$creations_table} src WHERE src.id = r.relation_id
					) )
				)"
			: '';

		$affected_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.creation FROM {$table} r
				 INNER JOIN {$creations_table} c ON c.id = r.creation AND c.type = 'list'
				 WHERE r.content_type IN ({$types_sql})
				   AND COALESCE( r.sync_with_post, 0 ) != %d{$source_guard}",
				array_merge( self::SYNCABLE_TYPES, [ $target ] )
			)
		);

		if ( empty( $affected_rows ) ) {
			return [ [], 0 ];
		}

		$ids          = array_map( fn( $r ) => (int) $r->id, $affected_rows );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET sync_with_post = %d WHERE id IN ({$placeholders})",
				array_merge( [ $target ], $ids )
			)
		);

		return [
			array_map( fn( $r ) => (int) $r->creation, $affected_rows ),
			count( $affected_rows ),
		];
	}

	/**
	 * @param array $items incoming [{ id, sync_with_post }, ...]
	 * @return array{0:int[],1:int}
	 */
	private function apply_sync_items( array $items ) {
		$by_target = [ 0 => [], 1 => [] ];
		foreach ( $items as $entry ) {
			if ( empty( $entry['id'] ) ) {
				continue;
			}
			$target                  = ! empty( $entry['sync_with_post'] ) ? 1 : 0;
			$by_target[ $target ][]  = (int) $entry['id'];
		}

		$all_ids = array_merge( $by_target[0], $by_target[1] );
		if ( empty( $all_ids ) ) {
			return [ [], 0 ];
		}

		global $wpdb;
		$table = Plugin::$models_v2->mv_relations->table_name;

		// One SELECT for all candidate rows, then at most one UPDATE per target value.
		$id_placeholders = implode( ', ', array_fill( 0, count( $all_ids ), '%d' ) );
		$rows            = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, creation, sync_with_post FROM {$table} WHERE id IN ({$id_placeholders})",
				$all_ids
			)
		);

		$known   = [];
		$current = [];
		foreach ( $rows as $r ) {
			$known[ (int) $r->id ]   = (int) $r->creation;
			$current[ (int) $r->id ] = (int) $r->sync_with_post;
		}

		$queued = [];
		$count  = 0;
		foreach ( [ 0, 1 ] as $target ) {
			// The wizard echoes every item it saw, changed or not — skip rows
			// already in the target state so unchanged lists aren't rewritten
			// and republished, and the "updated" count stays honest.
			$ids = array_values(
				array_filter(
					$by_target[ $target ],
					fn( $id ) => isset( $known[ $id ] ) && $current[ $id ] !== $target
				)
			);
			if ( empty( $ids ) ) {
				continue;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET sync_with_post = %d WHERE id IN ({$placeholders})",
					array_merge( [ $target ], $ids )
				)
			);
			$count += count( $ids );
			foreach ( $ids as $id ) {
				$queued[] = $known[ $id ];
			}
		}

		return [ $queued, $count ];
	}
}
