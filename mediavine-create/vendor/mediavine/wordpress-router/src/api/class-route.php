<?php
namespace Mediavine\WordPress\Router\API;

use \WP_REST_Server as Server;
use Mediavine\WordPress\Router\Router;
use Mediavine\WordPress\Router\Middleware;

class Route extends Router {
	/**
	 * @method static resource( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static get( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static post( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static put( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static delete( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static patch( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 * @method static any( string $method, string $uri, Closure|array|string|callable|null $action, array $args, Closure|array|string|callable $auth, bool $override)
	 */

	public static $verbs   = [
		'index'    => Server::READABLE,
		'get'      => Server::READABLE,
		'post'     => Server::CREATABLE,
		'delete'   => Server::DELETABLE,
		'put'      => Server::EDITABLE,
		'patch'    => Server::EDITABLE,
		'resource' => [ Server::READABLE, Server::CREATABLE, Server::EDITABLE, Server::DELETABLE ],
		'any'      => Server::ALLMETHODS,
	];
	public $resource_verbs = [
		'index'  => [
			'append_uri' => '/',
			'method'     => 'index',
		],
		'get'    => [
			'append_uri' => '/{d:id}',
			'method'     => 'show',
		],
		'post'   => [
			'append_uri' => '/',
			'method'     => 'store',
		],
		'put'    => [
			'append_uri' => '/{d:id}',
			'method'     => 'update',
		],
		'delete' => [
			'append_uri' => '/{d:id}',
			'method'     => 'destroy',
		],
	];

	protected $original_namespace;
	protected $namespace;
	protected $uri;
	protected $controller;
	protected $args;
	protected static $instance = null;
	public $routes             = [];

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static;
		}
		return self::$instance;
	}

	public function __construct() {
		parent::__construct();
		$this->original_namespace = $this->get_namespace();
		$this->namespace          = $this->get_namespace();
	}

	public function register() {
		$namespace = $this->get_namespace();

		// loop over the object routes
		foreach ( $this->routes as $uri => $method_groups ) {
			// for each uri, loop over methods and route arguments for that uri/method combination
			foreach ( $method_groups as $method => $route ) {
				register_rest_route(
					$namespace,
					$uri,
					[
						[
							'methods'             => static::$verbs[ $method ],
							'callback'            => $this->parse_action( $route['action'] ),
							'args'                => $route['args'],
							'permission_callback' => $route['auth'],
						],
					]
				);
			}
		}
		return $this->with_namespace( $this->original_namespace );
	}

	public function register_route( $method, $uri, $action, $args = [], $auth = '__return_true', $override = false ) {
		$uri    = $this->parse_uri( $uri );
		$action = $this->parse_action( $action );

		$route = [
			'method' => $method,
			'uri'    => $uri,
			'action' => $action,
			'args'   => $args,
			'auth'   => $auth,
		];

		// organize routes by `uri` -> `method` -> `$route`
		$this->routes[ $uri ][ $method ] = $route;

		$this->current_route = $route;

		return $this;
	}

	public function register_multiple_routes( $routes ) {
		$defaults = [
			'method' => 'get',
			'uri'    => '/',
			'action' => null,
			'args'   => [],
			'auth'   => '__return_true',
		];
		foreach ( $routes as $route ) {
			$route = array_merge( $defaults, $route );

			$this->register_route(
				$route['method'],
				$route['uri'],
				$route['action'],
				$route['args'],
				$route['auth'],
				true
			);
		}

		// because routes are held in state and registering resource routes requires new state each
		// iteration, we have to register multiple routes immediately instead of waiting for
		// controllers to register them
		return $this->register();
	}

	public function auth( $callback ) {
		$route  = $this->current_route;
		$uri    = $route['uri'];
		$method = $route['method'];

		$this->current_route                     = array_merge( $this->current_route, [ 'auth' => $callback ] );
		$this->routes[ $uri ][ $method ]['auth'] = $callback;

		return $this;
	}

	public function middleware( $stack = [] ) {
		if ( empty( $stack ) ) {
			return $this;
		}
		if ( ! wp_is_numeric_array( $stack ) ) {
			$stack = [ $stack ];
		}
		$route  = $this->current_route;
		$uri    = $route['uri'];
		$method = $route['method'];
		$action = $route['action'];

		$stack[] = $action;

		$action = new Middleware( $stack );

		$this->current_route['action']             = $action;
		$this->routes[ $uri ][ $method ]['action'] = $action;

		return $this;
	}

	public function register_resource_routes( $uri, $controller, $args = [] ) {
		$routes = [];
		foreach ( $this->resource_verbs as $verb => $details ) {
			$auth   = '__return_true';
			$method = $verb;
			if ( 'index' === $verb ) {
				$method = 'get';
			}
			$args[ $verb ] = isset( $args[ $verb ] ) ? $args[ $method ] : [];
			$path          = $uri . $details['append_uri'];
			$action        = $controller . '@' . $details['method'];
			if ( isset( $args[ $verb ]['middleware'] ) ) {
				$stack   = $args[ $verb ]['middleware'];
				$stack[] = $this->parse_action( $action );
				$action  = new Middleware( $stack );
				unset( $args[ $verb ]['middleware'] );
			}
			if ( isset( $args[ $verb ]['auth'] ) ) {
				$auth = $args[ $verb ]['auth'];
			}
			$routes[] = [
				'method' => $method,
				'uri'    => $path,
				'action' => $action,
				'args'   => $args[ $verb ],
				'auth'   => $auth,
			];

		}
		$this->register_multiple_routes( $routes );
		return $this;
	}

	public function get_namespace() {
		return ! empty( $this->config()->get( 'api.namespace' ) ) ? $this->config()->get( 'api.namespace' ) . '/' . $this->config()->get( 'api.version' ) : $this->namespace;
	}

	public function with_namespace( $namespace = '' ) {
		// if nothing is sent in, ¯\_(ツ)_/¯
		if ( empty( $namespace ) || ! strrpos( $namespace, '/' ) ) {
			$this->namespace = $this->get_namespace();
			return $this;
		}
		// store the original namespace so we can reset it later
		$this->original_namespace = $this->namespace ?: $this->get_namespace();

		$this->namespace = $namespace;

		// set the different config parts
		add_filter( 'mv_wp_router_config', [ $this, 'update_namespace' ] );

		return $this;
	}

	public function update_namespace( $config ) {
		// assuming the namespace is a complete namespace with a version...
		$parts = explode( '/', $this->namespace );
		$config->set( 'api.namespace', $parts[0] );
		$config->set( 'api.version', $parts[1] );

		return $config;
	}

	public static function __callStatic( $method, $arguments ) {
		$verbs = [ 'get', 'post', 'put', 'patch', 'delete', 'any', 'fallback' ];
		if ( in_array( $method, $verbs, true ) ) {
			return static::get_instance()->register_route( $method, ...$arguments );
		}
		if ( 'resource' === $method ) {
			return ( new static )->register_resource_routes( ...$arguments );
		}

		throw new \BadMethodCallException( 'This method does not exist.' );
	}
}
