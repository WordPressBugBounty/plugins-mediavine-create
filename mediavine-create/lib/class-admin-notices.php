<?php
namespace Mediavine\Create;

/**
 * Tools to help manage admin notices.
 */
class Admin_Notices {

	/** @var Admin_Notices  */
	private static $instance = null;

	/** @var string[] Names of notices that can be dismissed per user */
	private const PER_USER_NOTICES = [];

	/**
	 * @return Admin_Notices
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 *
	 */
	public function init() {
		// setup per-user dismissals
		add_action( 'admin_init', [ $this, 'plugin_notice_dismiss' ] );

		// Create notices

	}

	/**
	 *
	 * Builds and displays our admin notices
	 *
	 * @param string  $name the name of the notice being built
	 * @param string  $message the message content for the notice being built
	 * @param string  $level the notice level
	 * @param boolean $dismissible if we want this notice to be dismissible on a per-user basis
	 */
	private function admin_error_notice( $name, $message, $level = 'error', $dismissible = false ) {
		global $current_user;

		$user_id = $current_user->ID;

		// add the 'Dismiss' link to any notices we want to be able to dismiss on a per-user basis
		if ( $dismissible ) {
			$message .= ' <a href="?' . $name . '-dismiss-notice">Dismiss</a>';
		}

		// early return if the notice has already been dismissed
		$val = (int) get_user_meta($user_id, $name, true);
		if ( $val ) {
			return;
		}

		// print the notice
		printf(
			'<div class="notice notice-' . esc_attr($level) . '"><p>%1$s</p></div>',
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
					'p'      => [],
				]
			)
		);
	}

	/**
	 *
	 * Checks for URL param from clicked link
	 * Adds user meta telling us if this user has dismissed the given notice
	 *
	 * @param string $name the name of the notice
	 */
	private function plugin_notice_dismiss_per_user( $name ) {

		global $current_user;

		$user_id = $current_user->ID;

		$filtered_name = filter_input(INPUT_GET, $name . '-dismiss-notice');

		if ( ! empty( $name ) && in_array($name, self::PER_USER_NOTICES) && isset( $filtered_name ) ) {

			add_user_meta( $user_id, $name, 1, true );

		}

	}

	/**
	 * Hook in admin notices dismissals by looping through allowed list.
	 */
	public function plugin_notice_dismiss() {
		array_map( [ $this, 'plugin_notice_dismiss_per_user' ], self::PER_USER_NOTICES );
	}
}
