<?php
/**
 * Plugin Name: Rashid Agency MCP
 * Description: Turns Claude into a small WordPress agency team (strategist, designer, developer, copywriter, SEO, QA). Fast, token-efficient MCP server with undo, backups and safety switches.
 * Version: 1.0.0
 * Author: Rashid Ahmad
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: rashid-agency-mcp
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RAM {
	const OPT  = 'ram_settings';
	const LOG  = 'ram_log';
	const UNDO = 'ram_undo';
	const NONE = '__none__';
	private static $tools = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ram_token', array( __CLASS__, 'token_action' ) );
		add_action( 'admin_post_ram_save', array( __CLASS__, 'save_action' ) );
		add_action( 'wp_head', array( __CLASS__, 'head_seo' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title_seo' ), 20 );
	}

	/* ================= Settings ================= */

	public static function settings() {
		$s = wp_parse_args( get_option( self::OPT, array() ), array( 'token_hash' => '', 'user' => 0, 'created' => 0, 'readonly' => 0, 'files' => 0, 'php' => 0, 'profile' => array() ) );
		$s['profile'] = array_merge( array( 'brand' => '', 'voice' => '', 'langs' => '', 'colors' => '', 'rules' => '', 'skills' => '' ), (array) $s['profile'] );
		return $s;
	}

	/* ================= Auth + MCP transport ================= */

	public static function routes() {
		$a = array( 'methods' => array( 'POST', 'GET', 'DELETE' ), 'callback' => array( __CLASS__, 'handle' ), 'permission_callback' => array( __CLASS__, 'auth' ) );
		register_rest_route( 'agency-mcp/v1', '/mcp', $a );
		register_rest_route( 'agency-mcp/v1', '/mcp/(?P<tok>[A-Za-z0-9_\-]+)', $a );
	}

	private static function hash( $t ) { return hash_hmac( 'sha256', $t, wp_salt( 'auth' ) ); }

	private static function token( WP_REST_Request $r ) {
		$t = $r->get_param( 'tok' );
		if ( $t ) { return (string) $t; }
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_RAM_TOKEN' ) as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$h = trim( wp_unslash( $_SERVER[ $k ] ) );
				return stripos( $h, 'Bearer ' ) === 0 ? trim( substr( $h, 7 ) ) : $h;
			}
		}
		return '';
	}

	public static function auth( WP_REST_Request $r ) {
		$s = self::settings();
		$t = self::token( $r );
		if ( ! $s['token_hash'] || ! $t || ! hash_equals( $s['token_hash'], self::hash( $t ) ) ) {
			return new WP_Error( 'ram_auth', 'Invalid or missing token.', array( 'status' => 401 ) );
		}
		wp_set_current_user( (int) $s['user'] );
		return current_user_can( 'manage_options' );
	}

	public static function handle( WP_REST_Request $r ) {
		if ( $r->get_method() !== 'POST' ) { return new WP_REST_Response( array( 'error' => 'Use POST (JSON-RPC).' ), 405 ); }
		$k = 'ram_rl_' . floor( time() / 60 );
		$n = (int) get_transient( $k );
		if ( $n >= 240 ) { return new WP_REST_Response( array( 'error' => 'Rate limit: 240 requests/minute.' ), 429 ); }
		set_transient( $k, $n + 1, 120 );
		$b = json_decode( $r->get_body(), true );
		if ( ! is_array( $b ) ) { return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) ), 400 ); }
		$batch = isset( $b[0] );
		$out   = array();
		foreach ( $batch ? $b : array( $b ) as $q ) {
			$o = self::dispatch( is_array( $q ) ? $q : array() );
			if ( $o !== null ) { $out[] = $o; }
		}
		if ( ! $out ) { status_header( 202 ); exit; }
		return new WP_REST_Response( $batch ? $out : $out[0], 200 );
	}

	private static function ok( $id, $res ) { return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $res ); }
	private static function err( $id, $c, $m ) { return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $c, 'message' => $m ) ); }
	private static function enc( $v ) { return wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }

	private static function instructions() {
		$s = self::settings();
		$i = 'You lead a small WordPress agency team (strategist, designer, developer, copywriter, SEO, QA). For any goal: call brief once, load needed roles with skill, do the work (use batch to combine calls), run qa on changed pages, then reply briefly. Create content as drafts first. Ask the user before destructive or high-impact steps (tools answer CONFIRMATION REQUIRED). Never invent facts. Created by Rashid Ahmad.';
		if ( $s['profile']['langs'] ) { $i .= ' Language preferences: ' . $s['profile']['langs'] . '. Reply to the user in their own language.'; }
		return $i;
	}

	private static function dispatch( $q ) {
		if ( ! isset( $q['id'] ) ) { return null; }
		$id = $q['id'];
		$m  = isset( $q['method'] ) ? $q['method'] : '';
		$p  = isset( $q['params'] ) && is_array( $q['params'] ) ? $q['params'] : array();
		switch ( $m ) {
			case 'initialize':
				return self::ok( $id, array( 'protocolVersion' => isset( $p['protocolVersion'] ) ? $p['protocolVersion'] : '2025-03-26', 'capabilities' => array( 'tools' => new stdClass() ),
					'serverInfo' => array( 'name' => 'rashid-agency-mcp', 'title' => 'Rashid Agency MCP (by Rashid Ahmad)', 'version' => '1.0.0' ), 'instructions' => self::instructions() ) );
			case 'ping':
				return self::ok( $id, new stdClass() );
			case 'tools/list':
				$l = array();
				foreach ( self::tools() as $n => $t ) { $l[] = array( 'name' => $n, 'description' => $t['d'], 'inputSchema' => self::schema( $t['p'] ) ); }
				return self::ok( $id, array( 'tools' => $l ) );
			case 'tools/call':
				$n = isset( $p['name'] ) ? $p['name'] : '';
				$a = isset( $p['arguments'] ) && is_array( $p['arguments'] ) ? $p['arguments'] : array();
				$T = self::tools();
				if ( ! isset( $T[ $n ] ) ) { return self::err( $id, -32602, 'Unknown tool: ' . $n ); }
				try {
					$res = call_user_func( $T[ $n ]['f'], $a );
					self::log( $n, $a, true );
					return self::ok( $id, array( 'content' => array( array( 'type' => 'text', 'text' => self::enc( $res ) ) ) ) );
				} catch ( Throwable $e ) {
					self::log( $n, $a, false );
					return self::ok( $id, array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ) ) );
				}
		}
		return self::err( $id, -32601, 'Method not found' );
	}

	private static function schema( $props ) {
		$o = array();
		foreach ( $props as $k => $v ) {
			$x = explode( '|', $v, 2 );
			$e = array( 'type' => $x[0] );
			if ( isset( $x[1] ) ) { $e['description'] = $x[1]; }
			if ( $x[0] === 'array' ) { $e['items'] = new stdClass(); }
			$o[ $k ] = $e;
		}
		return array( 'type' => 'object', 'properties' => (object) $o );
	}

	private static function log( $tool, $a, $ok ) {
		$s = self::enc( $a );
		$l = get_option( self::LOG, array() );
		$l[] = array( 't' => time(), 'tool' => $tool, 'args' => function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 200 ) : substr( $s, 0, 200 ), 'ok' => $ok );
		update_option( self::LOG, array_slice( $l, -100 ), false );
	}

	/* ================= Small helpers ================= */

	private static function pp( $a ) { return isset( $a['p'] ) && is_array( $a['p'] ) ? $a['p'] : array(); }

	private static function act( $a, $allowed ) {
		$x = isset( $a['action'] ) ? $a['action'] : '';
		if ( ! in_array( $x, $allowed, true ) ) { throw new Exception( 'Unknown action. Use one of: ' . implode( ', ', $allowed ) ); }
		return $x;
	}

	private static function guard() {
		if ( self::settings()['readonly'] ) { throw new Exception( 'Read-only mode is ON. The site owner can turn it off in Agency MCP settings.' ); }
	}

	private static function need( $a, $what ) {
		if ( empty( $a['confirm'] ) ) { throw new Exception( "CONFIRMATION REQUIRED: this will $what. Tell the user exactly what you will do, get approval, then call again with confirm=true." ); }
	}

	private static function cut( $s, $full, $max = 6000 ) {
		if ( ! is_string( $s ) || $full || mb_strlen( $s ) <= $max ) { return $s; }
		return mb_substr( $s, 0, $max ) . '…[truncated, use full=true or offset]';
	}

	private static function blocked() {
		return array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'users_can_register', 'default_role', 'wp_user_roles', self::OPT, self::LOG, self::UNDO );
	}

	/* ---- undoable design changes ---- */

	private static function chg( $type, $name, $old ) {
		$u = get_option( self::UNDO, array() );
		$n = $u ? ( end( $u )['id'] + 1 ) : 1;
		if ( strlen( self::enc( $old ) ) > 60000 ) { $old = '__too_large__'; }
		$u[] = array( 'id' => $n, 't' => time(), 'type' => $type, 'name' => $name, 'old' => $old );
		update_option( self::UNDO, array_slice( $u, -40 ), false );
		return $n;
	}

	private static function set_opt( $name, $val ) { self::chg( 'opt', $name, get_option( $name, self::NONE ) ); update_option( $name, $val ); }
	private static function set_mod( $name, $val ) { self::chg( 'mod', $name, get_theme_mod( $name, self::NONE ) ); set_theme_mod( $name, $val ); }
	private static function set_key( $opt, $key, $val ) {
		$o = get_option( $opt, array() );
		if ( ! is_array( $o ) ) { throw new Exception( 'Option is not an array; use value without key.' ); }
		self::chg( 'key', $opt . '|' . $key, array_key_exists( $key, $o ) ? $o[ $key ] : self::NONE );
		$o[ $key ] = $val;
		update_option( $opt, $o );
	}

	private static function undo( $id ) {
		$u = get_option( self::UNDO, array() );
		foreach ( $u as $e ) {
			if ( (int) $e['id'] !== (int) $id ) { continue; }
			$old = $e['old'];
			if ( $old === '__too_large__' ) { throw new Exception( 'That change was too large to store for undo.' ); }
			switch ( $e['type'] ) {
				case 'opt': $old === self::NONE ? delete_option( $e['name'] ) : update_option( $e['name'], $old ); break;
				case 'mod': $old === self::NONE ? remove_theme_mod( $e['name'] ) : set_theme_mod( $e['name'], $old ); break;
				case 'key':
					list( $opt, $key ) = explode( '|', $e['name'], 2 );
					$o = get_option( $opt, array() );
					if ( $old === self::NONE ) { unset( $o[ $key ] ); } else { $o[ $key ] = $old; }
					update_option( $opt, $o );
					break;
				case 'css': wp_update_custom_css_post( $old ); break;
				case 'switch': switch_theme( $old ); break;
			}
			return array( 'undone' => (int) $id, 'type' => $e['type'], 'name' => $e['name'] );
		}
		throw new Exception( 'Undo id not found.' );
	}

	/* ================= Skills (the "team") ================= */

	private static function builtin() {
		return array(
			'strategist'  => 'Goal first. Call brief, restate the goal in one line, ask at most 2 questions ONLY if blocked (audience, language, brand). Make a 5-line plan: pages, design direction, SEO targets, risks. Work in small reversible steps, draft before publish, then qa, then a 5-line report: what was done, where (URLs), undo ids/backups, next ideas.',
			'designer'    => 'Design system before pixels: 1 primary + 1 accent + neutrals (60/30/10), max 2 fonts, 8px spacing scale, WCAG AA contrast, mobile-first, generous whitespace, one clear CTA per screen. Use brand colors from brief. Change theme settings with design opt/mods first, Additional CSS (design css) for the rest, no !important chains. RTL languages: set direction and mirror spacing. Verify with qa. Every change is undoable (design undo).',
			'developer'   => 'Code only in a child theme (design child) or a small custom plugin; never edit parent themes or third-party plugins. Read before write, change the minimum, escape output, sanitize input, nonces on forms. Prefer CSS/JS/HTML over PHP (PHP needs the owner switch). Batch related files in one code write. After writing: site flush, qa, site log if anything breaks; report backup names.',
			'copywriter'  => 'Write in the user language and the brand voice from brief. Benefit-led headlines, short paragraphs, scannable H2/H3, one CTA per section. Use only facts the user gave; mark gaps as [TO CONFIRM]. Create as drafts. Keep HTML clean (block markup for block themes). No filler.',
			'seo'         => 'One primary keyword per page. Title <=60 chars with the keyword near the start, description 120-160 chars with a reason to click, exactly one H1, descriptive alt text, internal links, clean slugs. Run seo audit before and after; use seo set with items for bulk. No keyword stuffing. Only one SEO plugin should be active.',
			'ecommerce'   => 'Use rest with /wc/v3 (products, orders, coupons). Confirm any price, stock or order change with the user first. Clear product titles, short and long descriptions, categories, images with alt. After changes qa the shop, cart and checkout pages.',
			'translator'  => 'Preserve HTML, shortcodes, block comments and links. Keep terminology consistent; adapt idioms, not word-for-word. RTL languages (Urdu, Arabic, Pashto): check direction/alignment and use suitable fonts. If Polylang/WPML is active use it via rest; otherwise create separate pages with a language slug suffix and link them.',
			'qa'          => 'After every change run qa on affected URLs. Fix what it flags (viewport, H1, alt, PHP errors, slow load, mixed content) and recheck. Also judge the result like a visitor: broken layout, contrast, mobile. Finish with a pass/fail list.',
			'security'    => 'Least privilege. Never create admin users, change roles or expose secrets without explicit user approval. Keep file/PHP switches off unless needed. Back up before big changes (code write does it automatically). Flag outdated or unused plugins. Never put tokens or passwords in content or code.',
			'performance' => 'Measure with qa (ms, kb). Compress images before upload, avoid heavy sliders/builders for simple pages, remove unused plugins, lazy-load below-the-fold media, flush caches after changes, re-measure to prove the gain.',
		);
	}

	private static function skills() {
		$o = self::builtin();
		foreach ( preg_split( '/^##\s*/m', self::settings()['profile']['skills'] ) as $blk ) {
			$blk = trim( $blk );
			if ( $blk === '' ) { continue; }
			$l = explode( "\n", $blk, 2 );
			$n = sanitize_key( trim( $l[0] ) );
			if ( $n ) { $o[ $n ] = trim( isset( $l[1] ) ? $l[1] : '' ); }
		}
		return $o;
	}

	/* ================= Tools ================= */

	private static function tools() {
		if ( self::$tools !== null ) { return self::$tools; }
		$t  = array();
		$d  = function ( $name, $desc, $props, $fn ) use ( &$t ) { $t[ $name ] = array( 'd' => $desc, 'p' => $props, 'f' => $fn ); };
		$ap = array( 'action' => 'string', 'p' => 'object|action parameters', 'confirm' => 'boolean|true only after the user approved' );

		$d( 'brief', 'CALL FIRST. Site snapshot, brand profile, languages, safety switches and the list of team skills.', array(), function () {
			$th = wp_get_theme();
			$s  = self::settings();
			$pl = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $f ) { $pl[] = dirname( $f ) === '.' ? $f : dirname( $f ); }
			return array( 'site' => array( 'name' => get_bloginfo( 'name' ), 'tagline' => get_bloginfo( 'description' ), 'url' => home_url(), 'locale' => get_locale(), 'rtl' => is_rtl(), 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
				'theme' => $th->get_stylesheet(), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null, 'block_theme' => wp_is_block_theme(), 'plugins' => $pl, 'seo_plugins' => array_keys( array_filter( self::seo_engines() ) ) ),
				'profile' => array_filter( $s['profile'], function ( $v, $k ) { return $k !== 'skills' && $v !== ''; }, ARRAY_FILTER_USE_BOTH ),
				'switches' => array( 'readonly' => (bool) $s['readonly'], 'file_writes' => (bool) $s['files'], 'php_writes' => (bool) $s['php'] ),
				'skills' => array_keys( self::skills() ),
				'workflow' => 'strategist plans -> designer/developer/copywriter/seo build (batch calls) -> qa verifies -> short report' );
		} );

		$d( 'skill', 'Load team role playbooks on demand (names from brief). Costs tokens only when used.', array( 'names' => 'array|e.g. ["designer","seo"]' ), function ( $a ) {
			$all = self::skills();
			$o   = array();
			foreach ( (array) ( isset( $a['names'] ) ? $a['names'] : array() ) as $n ) { $o[ $n ] = isset( $all[ $n ] ) ? $all[ $n ] : 'Unknown skill.'; }
			return $o;
		} );

		$d( 'batch', 'Run up to 15 tool calls in ONE request (faster, fewer tokens): {calls:[{tool,args}], stop_on_error:true}.', array( 'calls' => 'array', 'stop_on_error' => 'boolean' ), function ( $a ) use ( &$t ) {
			$out  = array();
			$stop = ! isset( $a['stop_on_error'] ) || $a['stop_on_error'];
			foreach ( array_slice( (array) ( isset( $a['calls'] ) ? $a['calls'] : array() ), 0, 15 ) as $c ) {
				$n = isset( $c['tool'] ) ? $c['tool'] : '';
				if ( ! isset( $t[ $n ] ) || $n === 'batch' ) { $out[] = array( 'tool' => $n, 'ok' => false, 'error' => 'Unknown or not allowed' ); if ( $stop ) { break; } continue; }
				$args = isset( $c['args'] ) && is_array( $c['args'] ) ? $c['args'] : array();
				try { $out[] = array( 'tool' => $n, 'ok' => true, 'result' => call_user_func( $t[ $n ]['f'], $args ) ); self::log( $n, $args, true ); }
				catch ( Throwable $e ) { $out[] = array( 'tool' => $n, 'ok' => false, 'error' => $e->getMessage() ); self::log( $n, $args, false ); if ( $stop ) { break; } }
			}
			return $out;
		} );

		$d( 'content', 'Posts/pages/any post type. Actions: list{type,status,search,limit,page} | get{id,meta_keys[],full} | save{id?,title,content,excerpt,status(default draft),slug,type,parent,meta{},terms{tax:[names]},featured_image} or save{items:[...]} for bulk | delete{id,force} (confirm).', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'get', 'save', 'delete' ) ) ) {
				case 'list':
					$q = new WP_Query( array( 'post_type' => isset( $p['type'] ) ? $p['type'] : 'any', 'post_status' => isset( $p['status'] ) ? $p['status'] : 'any', 's' => isset( $p['search'] ) ? $p['search'] : '', 'posts_per_page' => min( 50, (int) ( isset( $p['limit'] ) ? $p['limit'] : 20 ) ), 'paged' => max( 1, (int) ( isset( $p['page'] ) ? $p['page'] : 1 ) ), 'no_found_rows' => true ) );
					return array_map( function ( $x ) { return array( 'id' => $x->ID, 'title' => $x->post_title, 'type' => $x->post_type, 'status' => $x->post_status, 'slug' => $x->post_name, 'url' => get_permalink( $x ) ); }, $q->posts );
				case 'get':
					$x = get_post( (int) ( isset( $p['id'] ) ? $p['id'] : 0 ) );
					if ( ! $x ) { throw new Exception( 'Post not found.' ); }
					$full = ! empty( $p['full'] );
					$meta = array();
					foreach ( get_post_meta( $x->ID ) as $k => $v ) { if ( $k !== '' && $k[0] !== '_' ) { $meta[ $k ] = self::cut( maybe_unserialize( $v[0] ), $full, 1500 ); } }
					foreach ( (array) ( isset( $p['meta_keys'] ) ? $p['meta_keys'] : array() ) as $k ) { $meta[ $k ] = self::cut( get_post_meta( $x->ID, $k, true ), $full ); }
					$terms = array();
					foreach ( get_object_taxonomies( $x->post_type ) as $tax ) { $terms[ $tax ] = wp_get_object_terms( $x->ID, $tax, array( 'fields' => 'names' ) ); }
					return array( 'id' => $x->ID, 'type' => $x->post_type, 'status' => $x->post_status, 'title' => $x->post_title, 'slug' => $x->post_name, 'url' => get_permalink( $x ), 'excerpt' => $x->post_excerpt, 'content' => self::cut( $x->post_content, $full ), 'chars' => mb_strlen( $x->post_content ), 'meta' => $meta, 'terms' => $terms );
				case 'save':
					self::guard();
					$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array( $p );
					return array_map( array( __CLASS__, 'save_post' ), array_slice( $items, 0, 30 ) );
				case 'delete':
					self::guard();
					self::need( $a, 'delete post ' . (int) $p['id'] . ( ! empty( $p['force'] ) ? ' PERMANENTLY' : ' (move to trash)' ) );
					return array( 'ok' => (bool) ( ! empty( $p['force'] ) ? wp_delete_post( (int) $p['id'], true ) : wp_trash_post( (int) $p['id'] ) ) );
			}
		} );

		$d( 'media', 'Media library. Actions: list{search,limit} | upload{url,title,alt,filename} or {items:[...]} | alt{id,alt,title,caption}.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'upload', 'alt' ) ) ) {
				case 'list':
					return array_map( function ( $m ) { return array( 'id' => $m->ID, 'title' => $m->post_title, 'url' => wp_get_attachment_url( $m->ID ), 'alt' => get_post_meta( $m->ID, '_wp_attachment_image_alt', true ) ); }, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 's' => isset( $p['search'] ) ? $p['search'] : '', 'numberposts' => min( 50, (int) ( isset( $p['limit'] ) ? $p['limit'] : 20 ) ) ) ) );
				case 'upload':
					self::guard();
					$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array( $p );
					return array_map( array( __CLASS__, 'upload_one' ), array_slice( $items, 0, 10 ) );
				case 'alt':
					self::guard();
					$id = (int) $p['id'];
					if ( isset( $p['alt'] ) ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) ); }
					$u = array( 'ID' => $id );
					if ( isset( $p['title'] ) ) { $u['post_title'] = sanitize_text_field( $p['title'] ); }
					if ( isset( $p['caption'] ) ) { $u['post_excerpt'] = sanitize_text_field( $p['caption'] ); }
					if ( count( $u ) > 1 ) { wp_update_post( $u ); }
					return array( 'ok' => true );
			}
		} );

		$d( 'design', 'Look and feel, all undoable. Actions: info | branding{logo_id,site_icon_id,title,tagline} | css{css,mode:append|replace} (reads if css omitted) | mods{mods{}} (reads if omitted) | opt{name,key?,value?} (reads if value omitted; use key for array options like astra-settings) | themes | switch{stylesheet} (confirm) | child{parent,name} (confirm, needs file switch) | undo{id?} (omit id to list).', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'info', 'branding', 'css', 'mods', 'opt', 'themes', 'switch', 'child', 'undo' ) ) ) {
				case 'info':
					$th = wp_get_theme();
					$lg = get_theme_mod( 'custom_logo' );
					return array( 'theme' => $th->get_stylesheet(), 'name' => $th->get( 'Name' ), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null, 'block_theme' => wp_is_block_theme(), 'logo_id' => $lg, 'logo_url' => $lg ? wp_get_attachment_url( $lg ) : null, 'site_icon' => get_option( 'site_icon' ), 'css_chars' => strlen( wp_get_custom_css() ), 'mod_keys' => array_keys( (array) get_theme_mods() ) );
				case 'branding':
					self::guard();
					if ( isset( $p['logo_id'] ) ) { self::set_mod( 'custom_logo', (int) $p['logo_id'] ); }
					if ( isset( $p['site_icon_id'] ) ) { self::set_opt( 'site_icon', (int) $p['site_icon_id'] ); }
					if ( isset( $p['title'] ) ) { self::set_opt( 'blogname', sanitize_text_field( $p['title'] ) ); }
					if ( isset( $p['tagline'] ) ) { self::set_opt( 'blogdescription', sanitize_text_field( $p['tagline'] ) ); }
					return array( 'ok' => true );
				case 'css':
					if ( ! isset( $p['css'] ) ) { return array( 'css' => wp_get_custom_css() ); }
					self::guard();
					$old = wp_get_custom_css();
					$new = ( isset( $p['mode'] ) && $p['mode'] === 'replace' ) ? $p['css'] : $old . "\n" . $p['css'];
					$r   = wp_update_custom_css_post( $new );
					if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); }
					return array( 'ok' => true, 'undo_id' => self::chg( 'css', '', $old ) );
				case 'mods':
					if ( empty( $p['mods'] ) || ! is_array( $p['mods'] ) ) { return get_theme_mods(); }
					self::guard();
					foreach ( $p['mods'] as $k => $v ) { self::set_mod( $k, $v ); }
					return array( 'ok' => true );
				case 'opt':
					$n = isset( $p['name'] ) ? $p['name'] : '';
					if ( ! array_key_exists( 'value', $p ) ) {
						$v = get_option( $n );
						if ( isset( $p['key'] ) ) { return is_array( $v ) && array_key_exists( $p['key'], $v ) ? $v[ $p['key'] ] : null; }
						return self::cut( is_string( $v ) ? $v : self::enc( $v ), false, 8000 );
					}
					self::guard();
					if ( in_array( $n, self::blocked(), true ) ) { throw new Exception( 'This option is blocked.' ); }
					if ( isset( $p['key'] ) ) { self::set_key( $n, $p['key'], $p['value'] ); } else { self::set_opt( $n, $p['value'] ); }
					return array( 'ok' => true );
				case 'themes':
					$o = array();
					foreach ( wp_get_themes() as $s => $th ) { $o[] = array( 'stylesheet' => $s, 'name' => $th->get( 'Name' ), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null, 'active' => get_stylesheet() === $s ); }
					return $o;
				case 'switch':
					self::guard();
					if ( ! wp_get_theme( $p['stylesheet'] )->exists() ) { throw new Exception( 'Theme not found.' ); }
					self::need( $a, 'switch the active theme to ' . $p['stylesheet'] . ' (the design changes immediately)' );
					$id = self::chg( 'switch', '', get_stylesheet() );
					switch_theme( $p['stylesheet'] );
					return array( 'active' => get_stylesheet(), 'undo_id' => $id );
				case 'child':
					self::guard();
					return self::child_theme( $a, $p );
				case 'undo':
					self::guard();
					if ( empty( $p['id'] ) ) { return array_map( function ( $e ) { return array( 'id' => $e['id'], 'type' => $e['type'], 'name' => $e['name'], 'when' => wp_date( 'm-d H:i', $e['t'] ) ); }, array_slice( array_reverse( get_option( self::UNDO, array() ) ), 0, 15 ) ); }
					return self::undo( $p['id'] );
			}
		} );

		$d( 'plugins', 'Actions: list | activate{plugin file} | deactivate{plugin file} | install{type:plugin|theme,slug,activate} — activate/deactivate/install need confirm.', $ap, function ( $a ) {
			$p = self::pp( $a );
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			switch ( self::act( $a, array( 'list', 'activate', 'deactivate', 'install' ) ) ) {
				case 'list':
					$o = array();
					foreach ( get_plugins() as $f => $x ) { $o[] = array( 'file' => $f, 'name' => $x['Name'], 'version' => $x['Version'], 'active' => is_plugin_active( $f ) ); }
					return $o;
				case 'activate':
				case 'deactivate':
					self::guard();
					if ( $p['plugin'] === plugin_basename( __FILE__ ) ) { throw new Exception( 'Cannot change this plugin from itself.' ); }
					self::need( $a, $a['action'] . ' plugin ' . $p['plugin'] );
					if ( $a['action'] === 'activate' ) { $r = activate_plugin( $p['plugin'] ); if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); } } else { deactivate_plugins( $p['plugin'] ); }
					return array( 'active' => is_plugin_active( $p['plugin'] ) );
				case 'install':
					self::guard();
					self::need( $a, 'install the ' . $p['type'] . ' "' . $p['slug'] . '" from WordPress.org' );
					return self::install( $p );
			}
		} );

		$d( 'code', 'Theme/plugin files under themes/ plugins/ mu-plugins/ (path like themes/astra-child/style.css). Actions: list{path} | read{path,offset,limit} | write{path,content} or {files:[{path,content}]} (needs file switch in settings, PHP its own switch; auto-backup; confirm) | backups | restore{backup} (confirm).', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'read', 'write', 'backups', 'restore' ) ) ) {
				case 'list':
					$full = self::resolve( $p['path'] );
					if ( ! is_dir( $full ) ) { throw new Exception( 'Not a folder.' ); }
					$o = array();
					foreach ( array_diff( scandir( $full ), array( '.', '..' ) ) as $f ) { $o[] = is_dir( "$full/$f" ) ? $f . '/' : $f; }
					return $o;
				case 'read':
					$full = self::resolve( $p['path'] );
					if ( ! is_file( $full ) || filesize( $full ) > 1048576 ) { throw new Exception( 'Not a readable file under 1 MB.' ); }
					$c = file_get_contents( $full );
					$off = max( 0, (int) ( isset( $p['offset'] ) ? $p['offset'] : 0 ) );
					$lim = min( 20000, (int) ( isset( $p['limit'] ) ? $p['limit'] : 8000 ) );
					return array( 'path' => $p['path'], 'chars' => mb_strlen( $c ), 'offset' => $off, 'content' => mb_substr( $c, $off, $lim ), 'more' => mb_strlen( $c ) > $off + $lim );
				case 'write':
					self::guard();
					return self::write_files( $a, $p );
				case 'backups':
					$o = array();
					foreach ( array_diff( scandir( self::bdir() ), array( '.', '..', '.htaccess', 'index.php' ) ) as $f ) { $o[] = $f; }
					return array_slice( array_reverse( $o ), 0, 30 );
				case 'restore':
					self::guard();
					$src = self::bdir() . '/' . basename( $p['backup'] );
					if ( ! is_file( $src ) ) { throw new Exception( 'Backup not found.' ); }
					$rel  = rawurldecode( substr( strstr( basename( $p['backup'] ), '__' ), 2 ) );
					$full = self::resolve( $rel );
					self::need( $a, 'restore ' . $rel . ' from backup' );
					if ( file_exists( $full ) ) { self::backup( $rel, $full ); }
					copy( $src, $full );
					return array( 'restored' => $rel );
			}
		} );

		$d( 'seo', 'Yoast / Rank Math / built-in. Actions: get{id} | set{id,title,description,focus_keyword,noindex} or {items:[...]} | audit{id|ids} (omit = latest 10 pages).', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'get', 'set', 'audit' ) ) ) {
				case 'get':
					return self::seo_read( (int) $p['id'] );
				case 'set':
					self::guard();
					$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array( $p );
					return array_map( array( __CLASS__, 'seo_set' ), array_slice( $items, 0, 30 ) );
				case 'audit':
					$ids = isset( $p['ids'] ) ? (array) $p['ids'] : ( isset( $p['id'] ) ? array( $p['id'] ) : get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 10, 'fields' => 'ids' ) ) );
					return array_map( array( __CLASS__, 'seo_audit' ), array_slice( $ids, 0, 20 ) );
			}
		} );

		$d( 'rest', 'Call any WordPress REST route internally (menus, widgets, global styles, WooCommerce /wc/v3, other plugins): {method,route,params} or {calls:[...]} via p. DELETE and users/plugins/themes writes need confirm.', $ap, function ( $a ) {
			$p     = self::pp( $a );
			$calls = isset( $p['calls'] ) && is_array( $p['calls'] ) ? $p['calls'] : array( $p );
			$out   = array();
			foreach ( array_slice( $calls, 0, 15 ) as $c ) {
				$m     = strtoupper( isset( $c['method'] ) ? $c['method'] : 'GET' );
				$route = '/' . ltrim( isset( $c['route'] ) ? $c['route'] : '', '/' );
				if ( $m !== 'GET' ) { self::guard(); }
				if ( $m !== 'GET' && ( $m === 'DELETE' || preg_match( '#^/wp/v2/(users|plugins|themes)#', $route ) ) ) { self::need( $a, "$m $route" ); }
				$req = new WP_REST_Request( $m, $route );
				$pr  = isset( $c['params'] ) && is_array( $c['params'] ) ? $c['params'] : array();
				if ( $m === 'GET' || $m === 'DELETE' ) { $req->set_query_params( $pr ); } else { $req->set_body_params( $pr ); }
				$res  = rest_do_request( $req );
				$data = rest_get_server()->response_to_data( $res, false );
				if ( $res->is_error() || $res->get_status() >= 400 ) { throw new Exception( $route . ': ' . self::enc( $data ) ); }
				$out[] = $data;
			}
			return count( $out ) === 1 ? $out[0] : $out;
		} );

		$d( 'qa', 'Open pages like a visitor and check status, load time, size, title, meta description, viewport, lang, H1 count, images without alt, noindex, PHP errors, mixed content. {urls:[...]} or {post_ids:[...]} (max 5, same site only).', array( 'urls' => 'array', 'post_ids' => 'array' ), function ( $a ) {
			$urls = array();
			foreach ( (array) ( isset( $a['post_ids'] ) ? $a['post_ids'] : array() ) as $id ) { $urls[] = get_permalink( (int) $id ); }
			foreach ( (array) ( isset( $a['urls'] ) ? $a['urls'] : array() ) as $u ) { $urls[] = $u; }
			if ( ! $urls ) { $urls[] = home_url( '/' ); }
			return array_map( array( __CLASS__, 'qa_one' ), array_slice( array_filter( $urls ), 0, 5 ) );
		} );

		$d( 'site', 'Actions: flush (object cache, rewrite rules, Elementor CSS) | log{n} (tail of wp-content/debug.log).', $ap, function ( $a ) {
			$p = self::pp( $a );
			if ( self::act( $a, array( 'flush', 'log' ) ) === 'flush' ) {
				self::guard();
				wp_cache_flush();
				flush_rewrite_rules( false );
				try { if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); } } catch ( Throwable $e ) { unset( $e ); }
				return array( 'ok' => true );
			}
			$f = WP_CONTENT_DIR . '/debug.log';
			if ( ! is_file( $f ) ) { return array( 'log' => 'No debug.log found (WP_DEBUG_LOG is off).' ); }
			$h = fopen( $f, 'rb' );
			fseek( $h, max( 0, filesize( $f ) - 16384 ) );
			$tail = stream_get_contents( $h );
			fclose( $h );
			return array( 'log' => implode( "\n", array_slice( explode( "\n", trim( $tail ) ), - min( 100, (int) ( isset( $p['n'] ) ? $p['n'] : 40 ) ) ) ) );
		} );

		self::$tools = $t;
		return $t;
	}

	/* ================= Tool implementations ================= */

	private static function save_post( $x ) {
		$m = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'type' => 'post_type', 'parent' => 'post_parent' );
		$d = array();
		foreach ( $m as $k => $f ) { if ( isset( $x[ $k ] ) ) { $d[ $f ] = $x[ $k ]; } }
		if ( ! empty( $x['id'] ) ) {
			$d['ID'] = (int) $x['id'];
			$id      = wp_update_post( wp_slash( $d ), true );
		} else {
			$d  += array( 'post_status' => 'draft', 'post_type' => 'post' );
			$id  = wp_insert_post( wp_slash( $d ), true );
		}
		if ( is_wp_error( $id ) ) { return array( 'error' => $id->get_error_message() ); }
		if ( ! empty( $x['meta'] ) && is_array( $x['meta'] ) ) { foreach ( $x['meta'] as $k => $v ) { update_post_meta( $id, $k, wp_slash( $v ) ); } }
		if ( ! empty( $x['terms'] ) && is_array( $x['terms'] ) ) { foreach ( $x['terms'] as $tax => $names ) { wp_set_object_terms( $id, (array) $names, $tax ); } }
		if ( ! empty( $x['featured_image'] ) ) { set_post_thumbnail( $id, (int) $x['featured_image'] ); }
		return array( 'id' => $id, 'status' => get_post_status( $id ), 'url' => get_permalink( $id ) );
	}

	private static function upload_one( $x ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( empty( $x['url'] ) ) { return array( 'error' => 'url missing' ); }
		$tmp = download_url( $x['url'] );
		if ( is_wp_error( $tmp ) ) { return array( 'error' => $tmp->get_error_message() ); }
		$name = sanitize_file_name( ! empty( $x['filename'] ) ? $x['filename'] : basename( (string) wp_parse_url( $x['url'], PHP_URL_PATH ) ) );
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, isset( $x['title'] ) ? $x['title'] : null );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return array( 'error' => $id->get_error_message() ); }
		if ( ! empty( $x['alt'] ) ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $x['alt'] ) ); }
		return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
	}

	private static function install( $p ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		$isp  = $p['type'] === 'plugin';
		$info = $isp ? plugins_api( 'plugin_information', array( 'slug' => $p['slug'], 'fields' => array( 'sections' => false ) ) ) : themes_api( 'theme_information', array( 'slug' => $p['slug'], 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $info ) ) { throw new Exception( $info->get_error_message() ); }
		$skin = new WP_Ajax_Upgrader_Skin();
		$up   = $isp ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		$res  = $up->install( $info->download_link );
		if ( is_wp_error( $res ) ) { throw new Exception( $res->get_error_message() ); }
		if ( ! $res ) { $m = $skin->get_error_messages(); throw new Exception( $m ? implode( '; ', $m ) : 'Install failed (is the filesystem writable? Pantheon Dev needs SFTP mode).' ); }
		if ( ! empty( $p['activate'] ) && $isp && $up->plugin_info() ) { activate_plugin( $up->plugin_info() ); }
		return array( 'installed' => $p['slug'], 'activated' => ! empty( $p['activate'] ) );
	}

	private static function child_theme( $a, $p ) {
		if ( ! self::settings()['files'] ) { throw new Exception( 'File writes are disabled in Agency MCP settings.' ); }
		if ( ! wp_get_theme( $p['parent'] )->exists() ) { throw new Exception( 'Parent theme not found.' ); }
		$slug = sanitize_title( $p['name'] );
		$dir  = self::resolve( 'themes/' . $slug );
		if ( file_exists( $dir ) ) { throw new Exception( 'A theme with that folder already exists.' ); }
		self::need( $a, 'create child theme themes/' . $slug );
		wp_mkdir_p( $dir );
		file_put_contents( "$dir/style.css", "/*\nTheme Name: " . sanitize_text_field( $p['name'] ) . "\nTemplate: " . $p['parent'] . "\nAuthor: Rashid Agency MCP\nVersion: 1.0.0\n*/\n" );
		file_put_contents( "$dir/functions.php", "<?php\nadd_action( 'wp_enqueue_scripts', function () {\n\twp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );\n\twp_enqueue_style( 'child-style', get_stylesheet_uri(), array( 'parent-style' ), wp_get_theme()->get( 'Version' ) );\n} );\n" );
		return array( 'created' => 'themes/' . $slug, 'next' => 'design switch to activate it' );
	}

	private static function write_files( $a, $p ) {
		$s = self::settings();
		if ( ! $s['files'] ) { throw new Exception( 'File writes are disabled. The site owner can enable them in Agency MCP settings.' ); }
		$files = isset( $p['files'] ) && is_array( $p['files'] ) ? $p['files'] : array( $p );
		$plan  = array();
		foreach ( array_slice( $files, 0, 15 ) as $f ) {
			if ( ! isset( $f['path'], $f['content'] ) ) { throw new Exception( 'Each file needs path and content.' ); }
			$full = self::resolve( $f['path'] );
			$ext  = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'php', 'css', 'js', 'json', 'html', 'txt', 'md', 'svg', 'twig' ), true ) ) { throw new Exception( 'File type not allowed: ' . $f['path'] ); }
			if ( $ext === 'php' ) {
				if ( ! $s['php'] ) { throw new Exception( 'PHP writes are disabled. The site owner can enable them in Agency MCP settings.' ); }
				try { token_get_all( $f['content'], TOKEN_PARSE ); } catch ( ParseError $e ) { throw new Exception( 'PHP syntax error in ' . $f['path'] . ', nothing written: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')' ); }
			}
			$plan[] = array( $f['path'], $full, $f['content'] );
		}
		self::need( $a, 'write ' . count( $plan ) . ' file(s): ' . implode( ', ', array_column( $plan, 0 ) ) . ' (existing files are backed up)' );
		$out = array();
		foreach ( $plan as $x ) {
			$bk = file_exists( $x[1] ) ? self::backup( $x[0], $x[1] ) : null;
			wp_mkdir_p( dirname( $x[1] ) );
			if ( file_put_contents( $x[1], $x[2], LOCK_EX ) === false ) { throw new Exception( 'Could not write ' . $x[0] . ' (read-only filesystem? Pantheon Dev needs SFTP mode).' ); }
			$out[] = array( 'path' => $x[0], 'bytes' => strlen( $x[2] ), 'backup' => $bk );
		}
		return $out;
	}

	private static function resolve( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( strpos( $rel, '..' ) !== false ) { throw new Exception( 'Invalid path.' ); }
		$parts = explode( '/', $rel );
		$root  = array_shift( $parts );
		if ( ! in_array( $root, array( 'themes', 'plugins', 'mu-plugins' ), true ) ) { throw new Exception( 'Path must start with themes/, plugins/ or mu-plugins/.' ); }
		$base = realpath( WP_CONTENT_DIR . '/' . $root );
		if ( ! $base ) { throw new Exception( 'Folder does not exist.' ); }
		$full = $base . ( $parts ? '/' . implode( '/', $parts ) : '' );
		$chk  = file_exists( $full ) ? realpath( $full ) : realpath( dirname( $full ) );
		if ( ! $chk ) { $chk = $base; }
		if ( strpos( $chk, $base ) !== 0 ) { throw new Exception( 'Path escapes allowed folders.' ); }
		$self = realpath( plugin_dir_path( __FILE__ ) );
		if ( $self && strpos( $chk . '/', $self . '/' ) === 0 ) { throw new Exception( "This plugin's own files cannot be changed from here." ); }
		return $full;
	}

	private static function bdir() {
		$u   = wp_upload_dir();
		$dir = $u['basedir'] . '/agency-mcp-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php // silence\n" );
		}
		return $dir;
	}

	private static function backup( $rel, $full ) {
		$name = gmdate( 'Ymd-His' ) . '__' . rawurlencode( $rel );
		copy( $full, self::bdir() . '/' . $name );
		return $name;
	}

	/* ---- QA ---- */

	private static function qa_one( $url ) {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		if ( $h !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { return array( 'url' => $url, 'error' => 'Only pages of this site can be checked.' ); }
		$t = microtime( true );
		$r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'user-agent' => 'RashidAgencyMCP-QA', 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $r ) ) { return array( 'url' => $url, 'error' => $r->get_error_message() ); }
		$ms   = (int) round( ( microtime( true ) - $t ) * 1000 );
		$b    = wp_remote_retrieve_body( $r );
		$code = (int) wp_remote_retrieve_response_code( $r );
		$kb   = (int) round( strlen( $b ) / 1024 );
		$i    = array();
		if ( $code !== 200 ) { $i[] = "HTTP $code"; }
		if ( $ms > 2500 ) { $i[] = "Slow ({$ms} ms)"; }
		if ( $kb > 2000 ) { $i[] = "Heavy HTML ({$kb} KB)"; }
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $b, $m ) ) { if ( mb_strlen( trim( wp_strip_all_tags( $m[1] ) ) ) > 60 ) { $i[] = 'Title over 60 chars'; } } else { $i[] = 'No <title>'; }
		if ( ! preg_match( '/<meta[^>]+name=["\']description["\']/i', $b ) ) { $i[] = 'No meta description'; }
		if ( ! preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $b ) ) { $i[] = 'No viewport meta (not mobile-ready)'; }
		if ( ! preg_match( '/<html[^>]+lang=/i', $b ) ) { $i[] = 'No lang attribute'; }
		$h1 = preg_match_all( '/<h1\b/i', $b );
		if ( $h1 !== 1 ) { $i[] = "H1 count is $h1 (should be 1)"; }
		$na = preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $b );
		if ( $na ) { $i[] = "$na image(s) without alt"; }
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', $b ) ) { $i[] = 'Page is noindex'; }
		if ( preg_match( '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)\s*[^<\n]{0,100}/', $b, $m ) ) { $i[] = 'PHP message: ' . trim( $m[0] ); }
		if ( strpos( $url, 'https://' ) === 0 && ( $mc = preg_match_all( '/(?:src|href)=["\']http:\/\/[^"\']+/i', $b ) ) ) { $i[] = "$mc mixed-content link(s)"; }
		return array( 'url' => $url, 'status' => $code, 'ms' => $ms, 'kb' => $kb, 'issues' => $i ? $i : array( 'OK' ) );
	}

	/* ---- SEO ---- */

	public static function seo_engines() {
		return array( 'yoast' => defined( 'WPSEO_VERSION' ), 'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) );
	}

	private static function seo_read( $id ) {
		$e = self::seo_engines();
		$o = array();
		if ( $e['yoast'] ) { $o['yoast'] = array( 'title' => get_post_meta( $id, '_yoast_wpseo_title', true ), 'description' => get_post_meta( $id, '_yoast_wpseo_metadesc', true ), 'focus_keyword' => get_post_meta( $id, '_yoast_wpseo_focuskw', true ) ); }
		if ( $e['rank_math'] ) { $o['rank_math'] = array( 'title' => get_post_meta( $id, 'rank_math_title', true ), 'description' => get_post_meta( $id, 'rank_math_description', true ), 'focus_keyword' => get_post_meta( $id, 'rank_math_focus_keyword', true ) ); }
		if ( ! $e['yoast'] && ! $e['rank_math'] ) { $o['builtin'] = array( 'title' => get_post_meta( $id, '_ram_seo_title', true ), 'description' => get_post_meta( $id, '_ram_seo_desc', true ), 'focus_keyword' => get_post_meta( $id, '_ram_seo_kw', true ) ); }
		return $o;
	}

	private static function seo_set( $a ) {
		$id = (int) ( isset( $a['id'] ) ? $a['id'] : 0 );
		if ( ! get_post( $id ) ) { return array( 'id' => $id, 'error' => 'Post not found' ); }
		$e   = self::seo_engines();
		$map = array(
			'yoast'     => array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw' ),
			'rank_math' => array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' ),
			'builtin'   => array( 'title' => '_ram_seo_title', 'description' => '_ram_seo_desc', 'focus_keyword' => '_ram_seo_kw' ),
		);
		$used = array();
		foreach ( $map as $eng => $keys ) {
			if ( $eng !== 'builtin' && empty( $e[ $eng ] ) ) { continue; }
			if ( $eng === 'builtin' && ( $e['yoast'] || $e['rank_math'] ) ) { continue; }
			foreach ( $keys as $f => $mk ) { if ( isset( $a[ $f ] ) ) { update_post_meta( $id, $mk, sanitize_text_field( $a[ $f ] ) ); } }
			if ( isset( $a['noindex'] ) ) {
				if ( $eng === 'yoast' ) { update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', $a['noindex'] ? '1' : '2' ); }
				if ( $eng === 'rank_math' ) { update_post_meta( $id, 'rank_math_robots', $a['noindex'] ? array( 'noindex' ) : array( 'index' ) ); }
			}
			$used[] = $eng;
		}
		return array( 'id' => $id, 'updated_in' => $used );
	}

	private static function seo_audit( $id ) {
		$p = get_post( (int) $id );
		if ( ! $p ) { return array( 'id' => $id, 'error' => 'Post not found' ); }
		$title = '';
		$desc  = '';
		foreach ( self::seo_read( $p->ID ) as $v ) { if ( ! $title && ! empty( $v['title'] ) ) { $title = $v['title']; } if ( ! $desc && ! empty( $v['description'] ) ) { $desc = $v['description']; } }
		$title = $title ? $title : $p->post_title;
		$c     = $p->post_content;
		$i     = array();
		if ( mb_strlen( $title ) > 60 ) { $i[] = 'SEO title over 60 chars'; }
		$dl = mb_strlen( $desc );
		if ( $dl < 120 || $dl > 160 ) { $i[] = "Meta description should be 120-160 chars (now $dl)"; }
		if ( preg_match_all( '/<h1\b/i', $c ) > 1 ) { $i[] = 'More than one H1'; }
		$na = preg_match_all( '/<img\b(?![^>]*\balt=")[^>]*>/i', $c );
		if ( $na ) { $i[] = "$na image(s) without alt"; }
		$w = str_word_count( wp_strip_all_tags( $c ) );
		if ( $w < 300 ) { $i[] = "Thin content ($w words)"; }
		return array( 'id' => $p->ID, 'title' => $p->post_title, 'issues' => $i ? $i : array( 'OK' ) );
	}

	public static function head_seo() {
		$e = self::seo_engines();
		if ( $e['yoast'] || $e['rank_math'] || ! is_singular() ) { return; }
		$d = get_post_meta( get_queried_object_id(), '_ram_seo_desc', true );
		if ( $d ) { echo '<meta name="description" content="' . esc_attr( $d ) . "\">\n"; }
	}

	public static function title_seo( $t ) {
		$e = self::seo_engines();
		if ( $e['yoast'] || $e['rank_math'] || ! is_singular() ) { return $t; }
		$v = get_post_meta( get_queried_object_id(), '_ram_seo_title', true );
		return $v ? $v : $t;
	}

	/* ================= Admin page ================= */

	public static function menu() {
		add_menu_page( 'Rashid Agency MCP', 'Agency MCP', 'manage_options', 'rashid-agency-mcp', array( __CLASS__, 'page' ), 'dashicons-groups', 80 );
	}

	public static function token_action() {
		check_admin_referer( 'ram' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		$s = self::settings();
		if ( isset( $_POST['op'] ) && $_POST['op'] === 'generate' ) {
			$tok             = 'ram_' . bin2hex( random_bytes( 24 ) );
			$s['token_hash'] = self::hash( $tok );
			$s['user']       = get_current_user_id();
			$s['created']    = time();
			set_transient( 'ram_new_' . get_current_user_id(), $tok, 600 );
		} else {
			$s['token_hash'] = '';
		}
		update_option( self::OPT, $s );
		wp_safe_redirect( admin_url( 'admin.php?page=rashid-agency-mcp' ) );
		exit;
	}

	public static function save_action() {
		check_admin_referer( 'ram' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		$s             = self::settings();
		$s['readonly'] = empty( $_POST['readonly'] ) ? 0 : 1;
		$s['files']    = empty( $_POST['files'] ) ? 0 : 1;
		$s['php']      = ( $s['files'] && ! empty( $_POST['php'] ) ) ? 1 : 0;
		foreach ( array( 'brand', 'voice', 'langs', 'colors', 'rules', 'skills' ) as $k ) { $s['profile'][ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : ''; }
		update_option( self::OPT, $s );
		wp_safe_redirect( admin_url( 'admin.php?page=rashid-agency-mcp' ) );
		exit;
	}

	private static function copybox( $id, $val, $rows = 2 ) {
		echo '<textarea id="' . esc_attr( $id ) . '" readonly rows="' . (int) $rows . '" style="width:100%;max-width:900px;font-family:monospace">' . esc_textarea( $val ) . '</textarea><p><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'' . esc_js( $id ) . '\').value);this.textContent=\'Copied!\'">Copy</button></p>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s   = self::settings();
		$pr  = $s['profile'];
		$uid = get_current_user_id();
		$new = get_transient( 'ram_new_' . $uid );
		if ( $new ) { delete_transient( 'ram_new_' . $uid ); }
		$base = rest_url( 'agency-mcp/v1/mcp' );
		$f    = admin_url( 'admin-post.php' );
		echo '<div class="wrap"><h1>Rashid Agency MCP <small style="font-weight:400">by Rashid Ahmad</small></h1>';
		echo '<p>Turns Claude into a small agency team for this site. Use on a dev/staging site first.</p><h2>1. Connect (copy and paste)</h2>';
		echo '<form method="post" action="' . esc_url( $f ) . '" style="margin-bottom:12px">'; wp_nonce_field( 'ram' );
		echo '<input type="hidden" name="action" value="ram_token"><button class="button button-primary" name="op" value="generate">Generate new token</button> <button class="button" name="op" value="revoke">Revoke token</button> ';
		echo $s['token_hash'] ? '<em>A token is active (created ' . esc_html( wp_date( 'Y-m-d H:i', $s['created'] ) ) . ').</em>' : '<em>No active token.</em>'; echo '</form>';
		if ( $new ) {
			echo '<div class="notice notice-success inline"><p><strong>Your token is shown only once. Copy what you need now.</strong></p></div>';
			echo '<h3>Option A: one URL (easiest)</h3><p>In Claude: Settings &gt; Connectors &gt; Add custom connector, paste this URL. Treat it like a password.</p>'; self::copybox( 'ram_url', $base . '/' . $new );
			$cfg = wp_json_encode( array( 'mcpServers' => array( 'rashid-agency' => array( 'command' => 'npx', 'args' => array( '-y', 'mcp-remote', $base, '--header', 'Authorization:${AUTH_HEADER}' ), 'env' => array( 'AUTH_HEADER' => 'Bearer ' . $new ) ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			echo '<h3>Option B: Claude Desktop config (needs Node.js)</h3><p>Paste into claude_desktop_config.json (Settings &gt; Developer &gt; Edit config), then File &gt; Exit and reopen Claude.</p>'; self::copybox( 'ram_cfg', $cfg, 12 );
			echo '<h3>Option C: Claude Code</h3>'; self::copybox( 'ram_cc', 'claude mcp add rashid-agency ' . $base . ' --transport http --header "Authorization: Bearer ' . $new . '"', 3 );
		} else {
			echo '<p>Endpoint: <code>' . esc_html( $base ) . '</code>. Generate a token to get ready-to-copy URLs and configs.</p>';
		}
		echo '<h2>2. Your agency profile</h2><p>Claude reads this once per job (it costs very few tokens).</p><form method="post" action="' . esc_url( $f ) . '">'; wp_nonce_field( 'ram' );
		echo '<input type="hidden" name="action" value="ram_save"><table class="form-table">';
		$rows = array( 'brand' => array( 'Business / brand (what you do, audience)', 3 ), 'voice' => array( 'Brand voice (e.g. friendly, professional)', 1 ), 'langs' => array( 'Languages (e.g. reply in Urdu, site content in English and Urdu)', 1 ), 'colors' => array( 'Brand colors and fonts (e.g. green #2E9B35, white)', 1 ), 'rules' => array( 'Your rules (e.g. never publish without asking)', 3 ), 'skills' => array( 'Custom skills. Format: ## name, then instructions on the next lines', 6 ) );
		foreach ( $rows as $k => $r ) { echo '<tr><th>' . esc_html( $r[0] ) . '</th><td><textarea name="' . esc_attr( $k ) . '" rows="' . (int) $r[1] . '" style="width:100%;max-width:700px">' . esc_textarea( $pr[ $k ] ) . '</textarea></td></tr>'; }
		echo '</table><h2>3. Safety switches</h2>';
		echo '<p><label><input type="checkbox" name="readonly" value="1" ' . checked( $s['readonly'], 1, false ) . '> Read-only mode (Claude can look but not change anything)</label></p>';
		echo '<p><label><input type="checkbox" name="files" value="1" ' . checked( $s['files'], 1, false ) . '> Allow Claude to write theme/plugin files (CSS, JS, HTML...). Backed up automatically.</label></p>';
		echo '<p><label><input type="checkbox" name="php" value="1" ' . checked( $s['php'], 1, false ) . '> Also allow PHP files (syntax-checked). Dev sites only.</label></p><p><button class="button button-primary">Save settings</button></p></form>';
		echo '<h2>Recent activity</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Time</th><th>Tool</th><th>Arguments</th><th>OK</th></tr></thead><tbody>';
		foreach ( array_slice( array_reverse( get_option( self::LOG, array() ) ), 0, 20 ) as $l ) { echo '<tr><td>' . esc_html( wp_date( 'm-d H:i:s', $l['t'] ) ) . '</td><td>' . esc_html( $l['tool'] ) . '</td><td><code>' . esc_html( $l['args'] ) . '</code></td><td>' . ( $l['ok'] ? 'yes' : 'no' ) . '</td></tr>'; }
		echo '</tbody></table><p style="margin-top:24px;color:#646970">Rashid Agency MCP v1.0.0 &middot; created by Rashid Ahmad</p></div>';
	}
}
RAM::init();
