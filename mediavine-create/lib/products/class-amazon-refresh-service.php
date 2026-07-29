<?php
/**
 * Shared Amazon expiry refresh and queue stepping for Products and Relations.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

/**
 * Parameterized Amazon refresh: expiry query, rate-limit transient, queue push/step, PAAPI fetch.
 *
 * Multiple instances register once; a single `init` callback runs every registered service
 * so Products and Relations no longer each attach their own pair of init hooks.
 */
class Amazon_Refresh_Service {

	/**
	 * Registered services run by the consolidated init hook.
	 *
	 * @var self[]
	 */
	private static $registry = [];

	/**
	 * Whether the shared init hook has been attached.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * @var Queue
	 */
	private $queue;

	/**
	 * Amazon Creators API (via adapter).
	 *
	 * @var Amazon_Creators
	 */
	private $amazon;

	/**
	 * DBI model (mv_products or mv_relations).
	 *
	 * @var object
	 */
	private $model;

	/**
	 * Transient that rate-limits enqueueing of expiring rows.
	 *
	 * @var string
	 */
	private $expiring_transient;

	/**
	 * Column holding the Amazon URL (`link` for products, `url` for relations).
	 *
	 * @var string
	 */
	private $url_field;

	/**
	 * Extra where clauses for the expiry query.
	 *
	 * @var array
	 */
	private $extra_where;

	/**
	 * Optional callback invoked before enqueueing expiring rows.
	 *
	 * @var callable|null
	 */
	private $before_refresh;

	/**
	 * Maps a PAAPI item onto the DB record before update.
	 *
	 * Signature: function( array $record, array $paapi_item ): array
	 *
	 * @var callable
	 */
	private $apply_result;

	/**
	 * @param array $args {
	 *     @type Queue         $queue              Required.
	 *     @type Amazon_Creators $amazon           Required.
	 *     @type object        $model              Required DBI model.
	 *     @type string        $expiring_transient Required rate-limit transient name.
	 *     @type string        $url_field          URL column name. Default 'link'.
	 *     @type array         $extra_where        Extra where clauses. Default [].
	 *     @type callable|null $before_refresh     Optional pre-refresh hook.
	 *     @type callable      $apply_result       Required mapper for PAAPI → record.
	 * }
	 */
	public function __construct( array $args ) {
		$this->queue              = $args['queue'];
		$this->amazon             = $args['amazon'];
		$this->model              = $args['model'];
		$this->expiring_transient = $args['expiring_transient'];
		$this->url_field          = isset( $args['url_field'] ) ? $args['url_field'] : 'link';
		$this->extra_where        = isset( $args['extra_where'] ) ? $args['extra_where'] : [];
		$this->before_refresh     = isset( $args['before_refresh'] ) ? $args['before_refresh'] : null;
		$this->apply_result       = $args['apply_result'];
	}

	/**
	 * Add this service to the shared init runner.
	 *
	 * Idempotent per expiring_transient so a second Products/Relations::init()
	 * (e.g. get_instance after Plugin bootstrap) does not enqueue twice.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::$registry as $existing ) {
			if ( $existing->expiring_transient === $this->expiring_transient ) {
				return;
			}
		}
		self::$registry[] = $this;
		self::ensure_hooks();
	}

	/**
	 * Attach a single init action that refreshes and steps every registered service.
	 *
	 * @return void
	 */
	public static function ensure_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'init', [ __CLASS__, 'run_registered' ] );
	}

	/**
	 * Refresh expiring rows and step queues for all registered services.
	 *
	 * @return void
	 */
	public static function run_registered() {
		foreach ( self::$registry as $service ) {
			$service->refresh_expiring();
			$service->step_queue();
		}
	}

	/**
	 * Reset registry (unit tests only).
	 *
	 * @return void
	 */
	public static function reset_registry_for_tests() {
		self::$registry = [];
		if ( self::$hooks_registered ) {
			remove_action( 'init', [ __CLASS__, 'run_registered' ] );
		}
		self::$hooks_registered = false;
	}

	/**
	 * @return Queue
	 */
	public function get_queue() {
		return $this->queue;
	}

	/**
	 * Rows whose Amazon images expire within $within seconds from now.
	 *
	 * @param int $within Seconds from now. Default 3 hours.
	 * @param int $limit  Max rows. Default 50.
	 * @return array
	 */
	public function get_expiring( $within = 10800, $limit = 50 ) {
		$timestamp = gmdate( 'Y-m-d H:i:s', strtotime( "+{$within} seconds" ) );

		$this->model->set_select( '*' )
			->set_order_by( 'expires' )
			->set_order( 'ASC' )
			->set_limit( $limit );

		$where = array_merge(
			[
				[ 'asin', 'IS NOT', 'NULL' ],
				[ 'expires', 'IS NOT', 'NULL' ],
				[ 'expires', '<', $timestamp ],
			],
			$this->extra_where
		);

		return $this->model->where( $where );
	}

	/**
	 * Enqueue expiring Amazon rows when the rate-limit transient is clear.
	 *
	 * @return false|void
	 */
	public function refresh_expiring() {
		if ( $this->before_refresh ) {
			call_user_func( $this->before_refresh );
		}

		if ( get_transient( $this->expiring_transient ) ) {
			return false;
		}

		$three_hours       = 3 * 60 * 60;
		$amazon_rate_limit = apply_filters( 'mv_create_amazon_rate_limit', $three_hours );
		$expiring          = $this->get_expiring( $amazon_rate_limit );
		if ( empty( $expiring ) ) {
			return false;
		}

		$expiring = array_column( $expiring, 'id' );
		$this->queue->push_many( $expiring );

		set_transient( $this->expiring_transient, time(), $amazon_rate_limit );
	}

	/**
	 * Advance the Amazon queue by one item when affiliates are configured.
	 *
	 * @return mixed
	 */
	public function step_queue() {
		if ( $this->amazon->amazon_affiliates_setup() ) {
			return $this->queue->step( [ $this, 'build_amazon_data' ] );
		}
	}

	/**
	 * Fetch PAAPI data for one queued row and persist the mapped result.
	 *
	 * Keeps the empty-result guard so a missing ASIN key does not emit undefined-index warnings.
	 *
	 * @param int $id Product or relation ID.
	 * @return false|void
	 */
	public function build_amazon_data( $id ) {
		$record = (array) $this->model->select_one_by_id( $id );
		if ( empty( $record ) || is_wp_error( $record ) ) {
			return false;
		}

		if ( empty( $record['asin'] ) ) {
			$url            = isset( $record[ $this->url_field ] ) ? $record[ $this->url_field ] : '';
			$record['asin'] = $this->amazon->get_asin_from_link( $url );
		}

		$asin   = $record['asin'];
		$result = $this->amazon->get_products_by_asin( $asin );

		if ( empty( $result ) || is_wp_error( $result ) ) {
			return false;
		}

		// Guard: PAAPI may return a non-empty map that still omits this ASIN.
		if ( empty( $result[ $asin ] ) ) {
			return false;
		}

		$record = call_user_func( $this->apply_result, $record, $result[ $asin ] );
		$this->model->update( $record );
	}
}
