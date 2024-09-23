<?php
namespace Mediavine\Create;
use Snowplow\Tracker\Tracker;
use Snowplow\Tracker\Subject;
use Snowplow\Tracker\Emitters\SyncEmitter;

class Snowplow_Tracker {

	public static $instance;

	private static $emitter;
	private static $tracker;
	private static $subject;

	/**
	 * Makes sure class is only instantiated once and runs init
	 *
	 * @return object Instantiated class
	 */
	static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Hooks to be run on class instantiation
	 *
	 * @return void
	 */
	public function init() {
		self::$emitter = new SyncEmitter();
	}

}
