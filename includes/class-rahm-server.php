<?php
/**
 * MCP server (JSON-RPC 2.0 over HTTPS). Works with any app that supports remote MCP.
 * Link form:  /wp-json/rahm/v1/mcp/<key>     or     /wp-json/rahm/v1/mcp  with  Authorization: Bearer <key>
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Server {

	const PROTOCOLS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/** @var array|null key record of the current request */
	private static $key = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors' ), 10, 4 );
	}

	public static function routes() {
		$args = array(
			'methods'             => array( 'POST', 'GET', 'DELETE', 'OPTIONS' ),
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		);
		register_rest_route( 'rahm/v1', '/mcp/(?P<token>rahm_[A-Za-z0-9]+)', $args );
		register_rest_route( 'rahm/v1', '/mcp', $args );
	}

	public static function cors( $served, $result, $request, $server ) {
		if ( strpos( $request->get_route(), '/rahm/v1/mcp' ) !== 0 ) {
			return $served;
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Rahm-Key, Mcp-Session-Id, Mcp-Protocol-Version' );
		header( 'Access-Control-Expose-Headers: Mcp-Session-Id' );
		return $served;
	}

	public static function permission( WP_REST_Request $req ) {
		if ( $req->get_method() === 'OPTIONS' ) {
			return true;
		}
		$token = $req->get_param( 'token' );
		if ( ! $token ) {
			$h = $req->get_header( 'authorization' );
			if ( $h && stripos( $h, 'bearer ' ) === 0 ) {
				$token = trim( substr( $h, 7 ) );
			}
			if ( ! $token ) {
				$token = $req->get_header( 'x_rahm_key' );
			}
		}
		$key = RAHM_Auth::verify( (string) $token );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		// Gentle rate limit: 300 calls per minute per key.
		$rk = 'rahm_rl_' . $key['id'];
		$n  = (int) get_transient( $rk );
		if ( $n >= 300 ) {
			return new WP_Error( 'rahm_rate', 'Too many requests. Slow down.', array( 'status' => 429 ) );
		}
		set_transient( $rk, $n + 1, MINUTE_IN_SECONDS );
		self::$key = $key;
		return true;
	}

	public static function handle( WP_REST_Request $req ) {
		if ( $req->get_method() === 'OPTIONS' ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( $req->get_method() !== 'POST' ) {
			return new WP_REST_Response( array( 'error' => 'This is the Rashid Ahm MCP endpoint. Connect with an MCP app (POST JSON-RPC).' ), 405, array( 'Allow' => 'POST' ) );
		}
		$body = json_decode( $req->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) ), 200 );
		}

		$user = self::$key['user'] ? get_userdata( self::$key['user'] ) : null;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
			$user   = $admins ? $admins[0] : null;
		}
		if ( ! $user ) {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32000, 'message' => 'No administrator account found.' ) ), 200 );
		}
		wp_set_current_user( $user->ID );
		RAHM_Ctx::set( 'mcp', self::$key );

		try {
			if ( isset( $body[0] ) ) {
				$out = array();
				foreach ( $body as $m ) {
					$r = self::rpc( $m );
					if ( $r !== null ) {
						$out[] = $r;
					}
				}
				$res = $out ? new WP_REST_Response( $out, 200 ) : new WP_REST_Response( null, 202 );
			} else {
				$r   = self::rpc( $body );
				$res = $r === null ? new WP_REST_Response( null, 202 ) : new WP_REST_Response( $r, 200 );
			}
		} finally {
			RAHM_Ctx::set( 'system' );
		}
		return $res;
	}

	private static function ok( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private static function err( $id, $code, $msg ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $msg ) );
	}

	private static function rpc( $m ) {
		if ( ! is_array( $m ) || empty( $m['method'] ) ) {
			return self::err( isset( $m['id'] ) ? $m['id'] : null, -32600, 'Invalid request' );
		}
		$id     = array_key_exists( 'id', $m ) ? $m['id'] : null;
		$method = $m['method'];
		$params = isset( $m['params'] ) && is_array( $m['params'] ) ? $m['params'] : array();
		if ( strpos( $method, 'notifications/' ) === 0 || ! array_key_exists( 'id', $m ) ) {
			return null;
		}
		switch ( $method ) {
			case 'initialize':
				$want = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
				return self::ok( $id, array(
					'protocolVersion' => in_array( $want, self::PROTOCOLS, true ) ? $want : self::PROTOCOLS[0],
					'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
					'serverInfo'      => array( 'name' => 'Rashid Ahm MCP', 'version' => RAHM_VERSION ),
					'instructions'    => 'Rashid Ahm MCP: an AI agency team for this WordPress site. Call the brief tool first, then skill. Plan first and ask the owner before changes. Workers\' changes are sent to the supervisor for approval.',
				) );
			case 'ping':
				return self::ok( $id, new stdClass() );
			case 'tools/list':
				return self::ok( $id, array( 'tools' => self::list_tools() ) );
			case 'resources/list':
				return self::ok( $id, array( 'resources' => array() ) );
			case 'prompts/list':
				return self::ok( $id, array( 'prompts' => array() ) );
			case 'tools/call':
				return self::ok( $id, self::call( isset( $params['name'] ) ? $params['name'] : '', isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array() ) );
		}
		return self::err( $id, -32601, 'Method not found: ' . $method );
	}

	private static function list_tools() {
		$out = array();
		foreach ( RAHM_Tools::all() as $name => $t ) {
			if ( ! RAHM_Auth::role_allows( RAHM_Ctx::role(), $name ) ) {
				continue;
			}
			$props = array();
			foreach ( $t['p'] as $k => $spec ) {
				$parts = array_pad( explode( '|', $spec, 2 ), 2, '' );
				$def   = array( 'type' => $parts[0] );
				if ( $parts[0] === 'array' ) {
					$def['items'] = new stdClass();
				}
				if ( $parts[0] === 'object' ) {
					$def['additionalProperties'] = true;
				}
				if ( $parts[1] !== '' ) {
					$def['description'] = $parts[1];
				}
				$props[ $k ] = $def;
			}
			$out[] = array(
				'name'        => $name,
				'description' => $t['d'],
				'inputSchema' => array( 'type' => 'object', 'properties' => $props ? $props : new stdClass() ),
			);
		}
		return $out;
	}

	private static function call( $name, array $args ) {
		try {
			$r    = RAHM_Tools::run( $name, $args );
			$text = RAHM_Tools::enc( $r );
			if ( strlen( $text ) > 60000 ) {
				$text = substr( $text, 0, 60000 ) . '...[truncated]';
			}
			return array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) );
		} catch ( Throwable $e ) {
			RAHM_Store::log( 'error', $name, $e->getMessage() );
			return array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ) );
		}
	}
}
