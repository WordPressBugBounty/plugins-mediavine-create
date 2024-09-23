<?php

namespace Mediavine\WordPress\Router\Tests;

use Mediavine\WordPress\Router\API\Route;

/**
 * API Route Test
 *
 * @package Mediavine_WordPress_Router
 */
class TestAPIRoutes extends MV_TestCase {

	/** @test */
	public function it_can_register_a_GET_route() {
		$route = Route::get(
			'/test-route', function() {
				return mv_response( [ 'test' => 'response' ] );
			}
		)
		->register();
		$data  = $this->get( '/test-route' )->data;
		$this->assertEquals( 'response', $data['test'] );
	}

	/**
	 * @test
	 */
	function it_can_register_a_protected_route() {
		$route    = Route::get(
			'/test-route', function() {
				return mv_response( [ 'test' => 'response' ] );
			}
		)
		->auth(
			function() {
				return current_user_can( 'manage_options' );
			}
		)
		->register();
		$response = $this->get( '/test-route' );
		$this->assertEquals( 401, $response->get_status() );

		wp_set_current_user( static::$subscriber );
		$response = $this->get( '/test-route' );
		$this->assertEquals( 403, $response->get_status() );

		wp_set_current_user( static::$admin );
		$response = $this->get( '/test-route' );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * @test
	 */
	function it_can_register_middleware() {
		Route::get(
			'/test-route', function( \WP_REST_Request $request, \WP_REST_Response $response ) {
				$response->data['test'] = 'response';
				return $response;
			}
		)
		->middleware(
			function( \WP_REST_Request $request, \WP_REST_Response $response ) {
				$response->data['param'] = $request->get_param( 'param' );

				return $response;
			}
		)
		->register();

		$response = $this->get( '/test-route', [ 'param' => 'test_value' ] );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'response', $response->get_data()['test'] );
		$this->assertEquals( 'test_value', $response->get_data()['param'] );
	}

	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	public function it_can_register_a_POST_route( $postContent ) {
		Route::post(
			'/posts', function( \WP_REST_Request $request ) {
				$params  = mv_get_request_params( $request, [ 'post_title', 'post_content' ] );
				$post_id = \wp_insert_post( $params );
				$data    = \get_post( $post_id );
				return mv_response( $data );
			}
		)
		->register();
		$response = $this->post( '/posts', $postContent );

		$post = $response->get_data();

		$this->assertEquals( $postContent['post_title'], $post['post_title'] );
		$this->assertEquals( $postContent['post_content'], $post['post_content'] );
	}

	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	public function it_can_register_a_PUT_route( $originalContent, $updatedContent ) {
		$id = \wp_insert_post( $originalContent );
		Route::put(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$params = mv_get_request_params( $request, [ 'ID', 'post_title', 'post_content' ] );

				\wp_update_post( $params );
				$data = \get_post( $params['ID'] );
				return mv_response( $data );
			}
		)
		->register();
		$response = $this->put( '/posts/' . $id, $updatedContent );

		$post = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );
	}

	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	public function it_can_register_a_PATCH_route( $originalContent, $updatedContent ) {
		$id = \wp_insert_post( $originalContent );
		Route::patch(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$params = mv_get_request_params( $request, [ 'ID', 'post_title', 'post_content' ] );

				\wp_update_post( $params );
				$data = \get_post( $params['ID'] );
				return mv_response( $data );
			}
		)
		->register();
		$response = $this->patch( '/posts/' . $id, $updatedContent );

		$post = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );
	}

	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	function it_can_register_a_get_and_update_route( $originalContent, $updatedContent ) {
		$id = \wp_insert_post( $originalContent );
		Route::get(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$id   = $request->get_param( 'ID' );
				$data = \get_post( $id );
				return mv_response( $data );
			}
		)
		->register();
		Route::put(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$params = mv_get_request_params( $request, [ 'ID', 'post_title', 'post_content' ] );

				\wp_update_post( $params );
				$data = \get_post( $params['ID'] );
				return mv_response( $data );
			}
		)
		->register();
		$response = $this->put( '/posts/' . $id, $updatedContent );
		$post     = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );

		$response = $this->get( '/posts/' . $id );
		$post     = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );
	}


	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	function it_can_register_a_get_and_update_route_with_middleware( $originalContent, $updatedContent ) {
		$id         = \wp_insert_post( $originalContent );
		$middleware = function( $request, $response ) {
				return $response;
		};

		Route::get(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$id   = $request->get_param( 'ID' );
				$data = \get_post( $id );
				return mv_response( $data );
			}
		)
		->middleware( $middleware )
		->register();

		Route::put(
			'/posts/{ID}', function( \WP_REST_Request $request ) {
				$params = mv_get_request_params( $request, [ 'ID', 'post_title', 'post_content' ] );

				\wp_update_post( $params );
				$data = \get_post( $params['ID'] );
				return mv_response( $data );
			}
		)
		->middleware( $middleware )
		->register();

		$response = $this->put( '/posts/' . $id, $updatedContent );
		$post     = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );

		$response = $this->get( '/posts/' . $id );
		$post     = $response->get_data();

		$this->assertEquals( $updatedContent['post_title'], $post['post_title'] );
		$this->assertEquals( $updatedContent['post_content'], $post['post_content'] );
	}

	/**
	 * @test
	 * @dataProvider postContentProvider
	 */
	public function it_can_register_a_DELETE_route( $originalContent ) {
		$id = \wp_insert_post( $originalContent );
		Route::delete(
			'/posts/{id}', function( \WP_REST_Request $request ) {
				$params = mv_get_request_params( $request, 'id' );

				$data = wp_delete_post( $params['id'], true );
				return mv_response( compact( 'data' ) );
			}
		)->register();
		$response = $this->delete( '/posts/' . $id );

		$post = $response->get_data()['data'];
		// WordPress returns false if the deletion failed ¯\_(ツ)_/¯
		$this->assertNotSame( false, $post );
		$this->assertEquals( $id, $post->ID );
		// just to be sure, let's try to get the post (which WordPress returns as null instead of an error ¯\_(ツ)_/¯)
		$post = get_post( $id );
		$this->assertEmpty( $post );
	}

	/**
	 * @test
	 */
	function it_can_register_a_resource_route() {
		Route::resource( '/test', 'ResourceController' )->register();
		$index   = $this->get( '/test' );
		$post    = $this->post( '/test' );
		$single  = $this->get( '/test/1' );
		$update  = $this->put( '/test/1' );
		$destroy = $this->delete( '/test/1' );

		$this->assertEquals( 'index', $index->get_data() );
		$this->assertEquals( 'created', $post->get_data() );
		$this->assertEquals( 'shown', $single->get_data() );
		$this->assertEquals( 'updated', $update->get_data() );
		$this->assertEquals( 'destroyed', $destroy->get_data() );
	}

	/**
	 * @test
	 */
	function it_can_register_a_resource_route_with_middleware() {
		$middleware = [
			'middleware' => [function($request, $response) {
				return $response;
			}]
		];
		Route::resource( '/test', 'ResourceControllerWithMiddleware', [
			'index' => $middleware,
			'get' => $middleware,
			'post' => $middleware,
			'put' => $middleware,
			'delete' => $middleware,
		])
		->register();

		$index   = $this->get( '/test' );
		$post    = $this->post( '/test' );
		$single  = $this->get( '/test/1' );
		$update  = $this->put( '/test/1' );
		$destroy = $this->delete( '/test/1' );

		$this->assertEquals( 'index', $index->get_data() );
		$this->assertEquals( 'created', $post->get_data() );
		$this->assertEquals( 'shown', $single->get_data() );
		$this->assertEquals( 'updated', $update->get_data() );
		$this->assertEquals( 'destroyed', $destroy->get_data() );
	}

	/**
	 * @test
	 */
	function it_can_register_an_invokeable_controller() {
		Route::get( '/test', 'InvokeableController' )->register();
		$response = $this->get( '/test' )->get_data();

		$this->assertEquals( 'success', $response );
	}

	/**
	 * @test
	 */
	function it_can_register_a_route_on_a_different_namespace()
	{
		Route::get('/test', function() {
				return 'hi';
			})
			->with_namespace('different/v2')
			->register();
		$response = $this->with_namespace('different/v2')
			->get('/test')
			->get_data();
			$this->assertEquals('hi', $response);

		// verify the namespace is only changed for this instance of Route
		Route::get('/test-2', function() {return 'hey';})
			->with_namespace($this->original_namespace)
			->register();
		$response = $this->get('/test-2')->get_data();
		$this->assertEquals('hey', $response);
	}

	public function postContentProvider() {
		return [
			[
				[
					'post_title'   => 'Title',
					'post_content' => 'Content',
				],
				[
					'post_title'   => 'Changed Title',
					'post_content' => 'Changed Content',
				],
			],
			[
				[
					'post_title'   => 'A Different Title',
					'post_content' => 'Different Content',
				],
				[
					'post_title'   => 'Another Changed Title',
					'post_content' => 'More Changed Content',
				],
			],
		];
	}


}
