<?php

namespace Mediavine\WordPress\Router;

use Mediavine\WordPress\Router\Exceptions\RestResponseException;

class Middleware {

	private $middlewares;

	function __construct( $middleware_stack = [] ) {
		$this->middlewares = $middleware_stack;
	}

	function callback() {
		if ( empty( $this->middlewares ) ) {
			return [];
		}

		return function ( \WP_REST_Request $request ) {
			$response = new \WP_REST_Response();
			try {
				foreach ( $this->middlewares as $callable ) {
					$response = call_user_func( $callable, $request, $response );
					// Return error with first failure
					if ( is_wp_error( $response ) ) {
						// Make sure "status" error codes get moved to data as status for older Create WP_Errors
						$status     = 400;
						$error_code = $response->get_error_code();
						if ( is_integer( $error_code ) ) {
							$status = $error_code;
						}
						if ( empty( $response->error_data[ $error_code ]['status'] ) ) {
							$response->error_data[ $error_code ]['status'] = $status;
						}
						break;
					}
				}
			} catch ( RestResponseException $e ) {
				return new \WP_Error(
					$e->getErrorCode(), $e->getMessage(), [
						'status' => $e->getCode(),
						'data'   => $request->get_params(),
					]
				);
			}
			return $response;
		};
	}
}
