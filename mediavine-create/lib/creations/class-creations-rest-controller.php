<?php
namespace Mediavine\Create;

/**
 * REST controller for `wp/v2/mv_create`.
 *
 * The core posts controller lets anyone read published posts of a `show_in_rest` post
 * type, so every Create Card was publicly listable at `/wp-json/wp/v2/mv_create`. Feed,
 * app and headless tools that read the REST API could show cards from there. Reads now
 * require the same access level as the rest of Create; writes keep the core checks.
 */
class Creations_REST_Controller extends \WP_REST_Posts_Controller {

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! self::can_read() ) {
			return self::forbidden();
		}
		return parent::get_items_permissions_check( $request );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! self::can_read() ) {
			return self::forbidden();
		}
		return parent::get_item_permissions_check( $request );
	}

	/**
	 * @return bool
	 */
	private static function can_read() {
		return current_user_can( \Mediavine\Permissions::access_level() );
	}

	/**
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to view Create Cards.', 'mediavine-create' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
