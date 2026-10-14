<?php
/**
 * Live Studio back end: the owner's own REST routes (cookie login + nonce).
 * The preview refreshes when the activity feed shows a new change; direct edits made by clicking
 * in the preview come back through /studio/edit.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Studio {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' );
	}

	public static function routes() {
		$r = function ( $path, $method, $cb ) {
			register_rest_route( 'rahm/v1', '/studio/' . $path, array( 'methods' => $method, 'callback' => $cb, 'permission_callback' => array( __CLASS__, 'can' ) ) );
		};
		$r( 'pages', 'GET', array( __CLASS__, 'pages' ) );
		$r( 'feed', 'GET', array( __CLASS__, 'feed' ) );
		$r( 'edit', 'POST', array( __CLASS__, 'edit' ) );
		$r( 'audit', 'POST', array( __CLASS__, 'audit' ) );
	}

	/** Run $fn as the owner (no confirmation questions, everything backed up). */
	private static function as_owner( callable $fn ) {
		RAHM_Ctx::set( 'studio' );
		try {
			return rest_ensure_response( call_user_func( $fn ) );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
		} finally {
			RAHM_Ctx::set( 'system' );
		}
	}

	public static function pages() {
		$front = (int) get_option( 'page_on_front' );
		$rows  = array( array( 'id' => $front, 'title' => 'Home', 'url' => home_url( '/' ), 'builder' => $front && RAHM_Direct::is_elementor( $front ) ? 'elementor' : 'classic' ) );
		$q     = get_posts( array( 'post_type' => array( 'page', 'post', 'product' ), 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => 80, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
		foreach ( $q as $p ) {
			if ( $p->ID === $front ) { $rows[0]['title'] = $p->post_title . ' (Home)'; continue; }
			$rows[] = array( 'id' => $p->ID, 'title' => $p->post_title . ( $p->post_status !== 'publish' ? ' [' . $p->post_status . ']' : '' ), 'url' => $p->post_status === 'publish' ? get_permalink( $p ) : add_query_arg( 'preview', 'true', get_permalink( $p ) ), 'builder' => RAHM_Direct::is_elementor( $p->ID ) ? 'elementor' : ( has_blocks( $p->post_content ) ? 'blocks' : 'classic' ) );
		}
		if ( ! $front ) { $rows[0]['id'] = 0; $rows[0]['title'] = 'Home (latest posts)'; }
		return rest_ensure_response( $rows );
	}

	public static function feed( WP_REST_Request $r ) {
		$since = (int) $r->get_param( 'since' );
		return rest_ensure_response( array(
			'seq'     => RAHM_Store::current_seq(),
			'entries' => $since < 0 ? array_slice( RAHM_Store::log_since( 0 ), -1 ) : RAHM_Store::log_since( $since ),
		) );
	}

	public static function edit( WP_REST_Request $r ) {
		$b = $r->get_json_params();
		return self::as_owner( function () use ( $b ) {
			return RAHM_Direct::apply( (int) $b['post_id'], isset( $b['op'] ) ? $b['op'] : '', is_array( $b ) ? $b : array() );
		} );
	}

	public static function audit( WP_REST_Request $r ) {
		$role = (string) $r->get_param( 'role' );
		return self::as_owner( function () use ( $role ) {
			$res = RAHM_Audit::run( $role, 6 );
			RAHM_Store::save_audit( $res['role'], $res );
			return $res;
		} );
	}
}
