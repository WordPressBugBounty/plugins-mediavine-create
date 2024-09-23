<?php

namespace Mediavine\WordPress\Router\Tests\Controllers;
use \WP_REST_Request as Request;
use \WP_REST_Response as Response;

class ResourceControllerWithMiddleware {
	public function index( Request $request, Response $response ) {
		return 'index';
	}
	public function store( Request $request, Response $response ) {
		return 'created';
	}
	public function update( Request $request, Response $response ) {
		return 'updated';
	}
	public function show( Request $request, Response $response ) {
		return 'shown';
	}
	public function destroy( Request $request, Response $response ) {
		return 'destroyed';
	}
}
