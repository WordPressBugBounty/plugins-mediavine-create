<?php
namespace Mediavine\WordPress\Router\Tests;
/**
 * Base test case
 *
 * @package Mediavine_WordPress_Router
 */

class MV_TestCase extends \WP_UnitTestCase {


	protected $namespaced_route = '/mv-router/v1';
	protected $original_namespace = '/mv-router/v1';

	static $admin;
	static $subscriber;

	function setUp() {
		parent::setUp();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server;

		self::$admin      = $this->factory->user->create( [ 'role' => 'administrator' ] );
		self::$subscriber = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		do_action( 'rest_api_init' );
		do_action( 'init' );
	}

	function with_namespace( $namespace ) {
		$this->namespaced_route = '/' . trim($namespace, '/');
		return $this;
	}

	/**
	 * Make a GET request to the given route.
	 *
	 * Assumes `$this->namespaced_route` is already set.
	 *
	 * @param  string $route        the route to GET -- `/creations`
	 * @param  array  $query_params an array of query params -- `[ 'id' => 1 ]`
	 * @return \WP_REST_Response`
	 */
	function get( $route, $query_params = [] ) {
		$request = new \WP_REST_Request( 'GET', $this->namespaced_route . $route );
		$request->set_query_params( $query_params );

		$response = rest_do_request( $request );
		$this->namespaced_route = $this->original_namespace;
		return $response;
	}

	function post( $route, $params = [] ) {
		$request = new \WP_REST_Request( 'POST', $this->namespaced_route . $route );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->namespaced_route = $this->original_namespace;
		return $response;
	}

	function patch( $route, $params = [] ) {
		$request = new \WP_REST_Request( 'PATCH', $this->namespaced_route . $route );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->namespaced_route = $this->original_namespace;
		return $response;
	}

	function put( $route, $params = [] ) {
		$request = new \WP_REST_Request( 'PUT', $this->namespaced_route . $route );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->namespaced_route = $this->original_namespace;
		return $response;
	}

	function delete( $route, $params = [] ) {
		$request = new \WP_REST_Request( 'DELETE', $this->namespaced_route . $route );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->namespaced_route = $this->original_namespace;
		return $response;
	}

	function get_page( $route ) {
		$path = trim( $_SERVER['REMOTE_ADDR'], '/' ) . DIRECTORY_SEPARATOR . trim( $route, '/' );
		ob_start();
		$this->go_to( $path );
		$response = ob_get_contents();
		ob_end_clean();
		return $response;
	}

	/**
	 * Dump the contents of a variable into the readable stream.
	 *
	 * @param  mixed  $var
	 * @param  string $message
	 * @return void
	 */
	function dump( $var, $message = '' ) {
		fwrite( STDERR, $message . "\r\n" . print_r( $var, true ) );
	}

	function dd( $var, $message = '' ) {
		$this->dump( $var, $message );
		die;
	}

	/**
	 * @test
	 */
	function test_that_get_helper_function_fails_if_the_namespaced_route_does_not_exist() {
		$this->namespaced_route = '/non-existent/v1';
		$response               = $this->get( '/posts' );

		$this->assertEquals( 404, $response->status );
	}

}
