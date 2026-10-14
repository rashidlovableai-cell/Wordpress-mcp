<?php
/**
 * Connection keys: one key per AI account, each with a team role and an expiry.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Auth {

	const OPT = 'rahm_keys';

	/** Expiry choices shown in WordPress. */
	public static function expiries() {
		return array(
			'24h' => array( 'label' => '24 hours', 'sec' => DAY_IN_SECONDS ),
			'3d'  => array( 'label' => '3 days', 'sec' => 3 * DAY_IN_SECONDS ),
			'30d' => array( 'label' => '30 days', 'sec' => 30 * DAY_IN_SECONDS ),
			'1y'  => array( 'label' => '1 year', 'sec' => YEAR_IN_SECONDS ),
		);
	}

	/** Team roles. Each role decides which tools an AI account can use. */
	public static function roles() {
		$common = array( 'brief', 'skill', 'batch', 'inbox' );
		return array(
			'supervisor' => array(
				'label'   => 'Supervisor',
				'about'   => 'Coordinates the team, checks the work and reports to you. Goes live only after your yes in the chat.',
				'tools'   => '*',
			),
			'solo'       => array(
				'label'   => 'Solo (all roles in one)',
				'about'   => 'One key that does every job. Use it when you have only one AI account.',
				'tools'   => '*',
			),
			'designer'   => array(
				'label'   => 'Designer',
				'about'   => 'Layout, colors, typography, images and Elementor sections.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'design', 'elementor', 'code', 'qa', 'audit', 'site', 'rest', 'history' ) ),
			),
			'developer'  => array(
				'label'   => 'Developer',
				'about'   => 'CSS, HTML, JavaScript, PHP, plugins, themes and fixes.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'design', 'code', 'plugins', 'rest', 'qa', 'site', 'audit', 'history' ) ),
			),
			'seo'        => array(
				'label'   => 'SEO specialist',
				'about'   => 'Meta titles and descriptions, links, tags, URLs, permalinks and page-by-page SEO.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'seo', 'qa', 'audit', 'rest', 'site' ) ),
			),
			'copywriter' => array(
				'label'   => 'Copywriter',
				'about'   => 'Page text, headlines, product copy and tone of voice.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'seo', 'audit' ) ),
			),
			'marketing'  => array(
				'label'   => 'Marketing',
				'about'   => 'Calls to action, offers, social previews and conversion.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'seo', 'qa', 'audit' ) ),
			),
			'translator' => array(
				'label'   => 'Translator',
				'about'   => 'English and Urdu pages, with correct right-to-left layout.',
				'tools'   => array_merge( $common, array( 'content', 'seo' ) ),
			),
			'qa'         => array(
				'label'   => 'QA tester',
				'about'   => 'Checks speed, mobile, errors and broken links, then reports.',
				'tools'   => array_merge( $common, array( 'content', 'seo', 'qa', 'audit', 'site' ) ),
			),
			'ecommerce'  => array(
				'label'   => 'Store manager',
				'about'   => 'WooCommerce products, categories and store pages.',
				'tools'   => array_merge( $common, array( 'content', 'media', 'rest', 'qa', 'seo' ) ),
			),
		);
	}

	public static function role_allows( $role, $tool ) {
		$r = self::roles();
		if ( ! isset( $r[ $role ] ) ) {
			return false;
		}
		return $r[ $role ]['tools'] === '*' || in_array( $tool, $r[ $role ]['tools'], true );
	}

	/* ---------- Keys ---------- */

	private static function hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	public static function keys() {
		return (array) get_option( self::OPT, array() );
	}

	/** Create a key. Returns array( token, record ). The token is shown once and never stored. */
	public static function generate( $role, $label, $expiry, $user_id ) {
		$roles = self::roles();
		$exp   = self::expiries();
		if ( ! isset( $roles[ $role ] ) ) {
			throw new Exception( 'Unknown role.' );
		}
		if ( ! isset( $exp[ $expiry ] ) ) {
			$expiry = '30d';
		}
		$token  = 'rahm_' . bin2hex( random_bytes( 20 ) );
		$record = array(
			'id'        => substr( md5( $token . microtime() ), 0, 8 ),
			'label'     => $label ? sanitize_text_field( $label ) : $roles[ $role ]['label'],
			'role'      => $role,
			'hash'      => self::hash( $token ),
			'created'   => time(),
			'expires'   => time() + $exp[ $expiry ]['sec'],
			'expiry'    => $expiry,
			'last_used' => 0,
			'revoked'   => 0,
			'user'      => (int) $user_id,
		);
		$keys   = self::keys();
		$keys[] = $record;
		update_option( self::OPT, $keys, false );
		return array( $token, $record );
	}

	/** Check a token. Returns the key record or WP_Error. */
	public static function verify( $token ) {
		if ( ! $token || strpos( $token, 'rahm_' ) !== 0 ) {
			return new WP_Error( 'rahm_auth', 'Invalid or missing key.', array( 'status' => 401 ) );
		}
		$h    = self::hash( $token );
		$keys = self::keys();
		foreach ( $keys as $i => $k ) {
			if ( ! hash_equals( $k['hash'], $h ) ) {
				continue;
			}
			if ( $k['revoked'] ) {
				return new WP_Error( 'rahm_revoked', 'This key was turned off by the site owner.', array( 'status' => 401 ) );
			}
			if ( (int) $k['expires'] < time() ) {
				return new WP_Error( 'rahm_expired', 'This key has expired. Ask the site owner to generate a new one.', array( 'status' => 401 ) );
			}
			if ( time() - (int) $k['last_used'] > 60 ) {
				$keys[ $i ]['last_used'] = time();
				update_option( self::OPT, $keys, false );
			}
			return $k;
		}
		return new WP_Error( 'rahm_auth', 'Invalid or missing key.', array( 'status' => 401 ) );
	}

	public static function revoke( $id ) {
		$keys = self::keys();
		foreach ( $keys as $i => $k ) {
			if ( $k['id'] === $id ) {
				$keys[ $i ]['revoked'] = 1;
			}
		}
		update_option( self::OPT, $keys, false );
	}

	public static function delete( $id ) {
		$keys = array_values( array_filter( self::keys(), function ( $k ) use ( $id ) { return $k['id'] !== $id; } ) );
		update_option( self::OPT, $keys, false );
	}

	public static function status( $k ) {
		if ( $k['revoked'] ) {
			return 'revoked';
		}
		return (int) $k['expires'] < time() ? 'expired' : 'active';
	}

	public static function link( $token ) {
		return rest_url( 'rahm/v1/mcp/' . $token );
	}
}
