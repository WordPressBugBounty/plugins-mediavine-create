<?php
namespace Mediavine\Create;

class Queue {

	/**
	 * Array representation of the queue stored in the database as JSON
	 *
	 * @var array $queue
	 */
	private $queue = [];

	/**
	 * Name to use for the transient that will control whether the queue is locked or not.
	 *
	 * @var string $transient_name
	 */
	public $transient_name = null;

	/**
	 * Name to use for option that will store this queue.
	 *
	 * @var string $queue_name
	 */
	public $queue_name = null;

	/**
	 * Number of seconds to set the lock transient for
	 *
	 * @var integer $lock_timeout
	 */
	private $lock_timeout = 300;

	/**
	 * Whether to unlock the queue automatically after a step is done, or to wait for the timeout
	 *
	 * @var bool $auto_unlock
	 */
	private $auto_unlock = true;

	public function __construct( array $options = [] ) {
		if ( array_key_exists( 'transient_name', $options ) ) {
			$this->transient_name = $options['transient_name'];
		}
		if ( array_key_exists( 'queue_name', $options ) ) {
			$this->queue_name = $options['queue_name'];
		}
		if ( array_key_exists( 'lock_timeout', $options ) ) {
			$this->lock_timeout = $options['lock_timeout'];
		}
		if ( array_key_exists( 'auto_unlock', $options ) ) {
			$this->auto_unlock = $options['auto_unlock'];
		}
		if ( ! $this->queue_name ) {
			throw new \RuntimeException( 'Cannot initialize queue without a queue name.' );
		}
		if ( ! $this->transient_name ) {
			$this->transient_name = $this->queue_name;
		}
	}

	/**
	 * Set the name of the transient that will be used to control the lock
	 *
	 * @param string $name
	 * @return string
	 */
	public function set_transient_name( string $name ) {
		$this->transient_name = $name;
		return $this->transient_name;
	}

	/**
	 * Set the name of the option that will be hold the queue in the database
	 *
	 * @param string $name
	 * @return string
	 */
	public function set_queue_name( string $name ) {
		$this->queue_name = $name;
		return $this->queue_name;
	}

	/**
	 * Set the maximum time in seconds that the lock will be held for
	 *
	 * @param integer $seconds Time in seconds
	 * @return integer
	 */
	public function set_lock_timeout( $seconds ) {
		$this->lock_timeout = $seconds;
		return $this->lock_timeout;
	}

	/**
	 * Set the value of the $queue property to be that of the option stored in the database
	 *
	 * @return void
	 */
	private function sync() {
		$queue_option = get_option( $this->queue_name );
		if ( ! empty( $queue_option ) && ! empty( json_decode( $queue_option, true ) ) ) {
			$this->queue = json_decode( $queue_option, true );
		}
	}

	/**
	 * Find the index of an item if it exists in the queue.
	 *
	 * @param mixed $item Item to find in the queue
	 * @return integer|bool The index of the given item in the queue, false if not found.
	 */
	public function find_item_index( $item ) {
		foreach ( $this->queue as $index => $queue_item ) {
			$queue_item_arr = is_array( $queue_item );
			$item_arr       = is_array( $item );
			if ( $queue_item_arr !== $item_arr ) {
				continue;
			}
			if ( $queue_item_arr ) {
				if ( isset( $queue_item['key'] ) &&
					isset( $item['key'] ) &&
					$queue_item['key'] === $item['key']
				) {
					return $index;
				}
				continue;
			}
			if ( $queue_item === $item ) {
				return $index;
			}
		}
		return false;
	}

	/**
	 * Determine if item exists in the queue
	 *
	 * @param mixed $item Item to find in the queue
	 * @return bool Whether or not the given item is present in the queue
	 */
	public function has_item( $item ) {
		return $this->find_item_index( $item ) !== false;
	}

	/**
	 * Update the queue stored in the database with a JSON encoded version of the passed queue array
	 *
	 * @param array $queue Queue to be passed
	 * @return void
	 */
	private function update( $queue ) {
		update_option( $this->queue_name, wp_json_encode( $queue ) );
	}

	/**
	 * Dump out all items in the queue
	 *
	 * @return array All items from the queue
	 */
	public function dump() {
		$this->sync();
		return $this->queue;
	}

	/**
	 * Push an item on to the queue if it is not found in the queue
	 *
	 * @param mixed $item
	 * @param bool $force If true, do not check if the item exists before putting it on the queue
	 * @return mixed Returns false if item is not added, otherwise returns the updated queue property
	 */
	public function push( $item, $force = false ) {
		$this->sync();
		if ( ! $force && $this->has_item( $item ) ) {
			return false;
		}
		$this->queue[] = $item;
		$this->update( $this->queue );
		return $this->queue;
	}

	/**
	 * Push an item on to the queue if it is not found in the queue
	 *
	 * @param array $item
	 * @param bool $force If true, do not check if the item exists before putting it on the queue
	 * @return mixed Returns false if item is not added, otherwise returns the updated queue property
	 */
	public function push_many( $items, $force = false ) {
		$this->sync();
		foreach ( $items as $item ) {
			if ( ! $force && $this->has_item( $item ) ) {
				continue;
			}
			$this->queue[] = $item;
		}
		$this->update( $this->queue );
		return $this->queue;
	}

	/**
	 * Remove from the queue if it is found in the queue
	 *
	 * @param mixed $item
	 * @return mixed Returns false if item is not removed, otherwise returns the updated queue property
	 */
	public function remove( $item ) {
		$this->sync();
		$key = $this->find_item_index( $item );
		if ( false === $key ) {
			return false;
		}
		unset( $this->queue[ $key ] );
		$this->update( $this->queue );
		return $this->queue;
	}

	/**
	 * Replaces the queue with an empty array
	 *
	 * @return void
	 */
	public function clear() {
		$this->queue = [];
		$this->update( $this->queue );
	}

	/**
	 * Removes and returns the item from the front of the queue
	 *
	 * @return mixed The item from the front of the queue
	 */
	public function shift() {
		$this->sync();
		$next_item = array_shift( $this->queue );
		$this->update( $this->queue );
		return $next_item;
	}

	/**
	 * Remove the item from the front of the queue and run a passed function on it and return the result.
	 * If the lock is set, the step will not be taken. Step will set the lock before it begins processing,
	 * and unlock when it is done.
	 *
	 * @param callable $process Callable that will be passed the item from the queue
	 * @return mixed Result of the passed callable be called with the item from the front of the queue
	 */
	public function step( callable $process ) {
		$this->sync();
		if ( empty( $this->queue ) ) {
			return null;
		}
		if ( $this->is_locked() ) {
			return false;
		}
		$this->lock();
		$item   = $this->shift();
		$result = false;
		if ( ! is_null( $item ) ) {
			$result = $process( $item );
		}
		if ( $this->auto_unlock ) {
			$this->unlock();
		}
		return $result;
	}

	/**
	 * Set the lock transient
	 *
	 * @return void
	 */
	public function lock( $timeout = null ) {
		if ( is_null( $timeout ) ) {
			$timeout = $this->lock_timeout;
		}
		set_transient( $this->transient_name, true, $timeout );
	}

	/**
	 * Delete the lock transient
	 *
	 * @return void
	 */
	public function unlock() {
		delete_transient( $this->transient_name );
	}

	/**
	 * Whether or not the lock is set
	 *
	 * @return boolean
	 */
	public function is_locked() {
		return get_transient( $this->transient_name );
	}

}
