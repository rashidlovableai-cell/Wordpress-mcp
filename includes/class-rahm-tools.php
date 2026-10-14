<?php
/**
 * The MCP tools (core set). Every tool returns plain arrays; the server turns them into JSON.
 * Writes always go through RAHM_Ctx::gate(), and take a snapshot first so they can be undone.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Tools {

	private static $tools = null;

	/* ================= Helpers ================= */

	public static function pp( $a ) {
		return isset( $a['p'] ) && is_array( $a['p'] ) ? $a['p'] : array();
	}

	public static function act( $a, array $allowed ) {
		$x = isset( $a['action'] ) ? $a['action'] : '';
		if ( ! in_array( $x, $allowed, true ) ) {
			throw new Exception( 'Unknown action. Use one of: ' . implode( ', ', $allowed ) );
		}
		return $x;
	}

	public static function enc( $v ) {
		return wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function cut( $s, $full, $max = 6000 ) {
		if ( ! is_string( $s ) || $full || mb_strlen( $s ) <= $max ) {
			return $s;
		}
		return mb_substr( $s, 0, $max ) . '...[truncated, use full=true]';
	}

	private static function blocked() {
		return array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'users_can_register', 'default_role', 'wp_user_roles', RAHM_Store::OPT_SETTINGS, RAHM_Auth::OPT, RAHM_Store::OPT_LOG, RAHM_Store::OPT_UNDO, RAHM_Store::OPT_INBOX );
	}

	private static function set_opt( $name, $val ) {
		RAHM_Ctx::$undo = RAHM_Store::snap( 'opt', $name, get_option( $name, RAHM_Store::NONE ) );
		update_option( $name, $val );
	}

	private static function set_mod( $name, $val ) {
		RAHM_Ctx::$undo = RAHM_Store::snap( 'mod', $name, get_theme_mod( $name, RAHM_Store::NONE ) );
		set_theme_mod( $name, $val );
	}

	private static function set_key( $opt, $key, $val ) {
		$o = get_option( $opt, array() );
		if ( ! is_array( $o ) ) {
			throw new Exception( 'Option is not an array; use value without key.' );
		}
		RAHM_Ctx::$undo = RAHM_Store::snap( 'key', $opt . '|' . $key, array_key_exists( $key, $o ) ? $o[ $key ] : RAHM_Store::NONE );
		$o[ $key ]      = $val;
		update_option( $opt, $o );
	}

	/* ================= Running a tool ================= */

	public static function all() {
		if ( self::$tools !== null ) {
			return self::$tools;
		}
		$t = array();
		$d = function ( $name, $desc, $props, $fn ) use ( &$t ) {
			$t[ $name ] = array( 'd' => $desc, 'p' => $props, 'f' => $fn );
		};
		self::define( $d );
		RAHM_Tools_Team::define( $d );
		self::$tools = $t;
		return $t;
	}

	/** Some AI apps send tool names with a prefix (for example mcp__Rashid__rest). Use the part after the last double underscore. */
	public static function normalize( $name ) {
		$name = (string) $name;
		$all  = self::all();
		if ( isset( $all[ $name ] ) ) {
			return $name;
		}
		$pos = strrpos( $name, '__' );
		if ( $pos !== false ) {
			$short = substr( $name, $pos + 2 );
			if ( isset( $all[ $short ] ) ) {
				return $short;
			}
		}
		return $name;
	}

	/** Run one tool with the permission checks and the owner's yes (confirm=true) for anything visitors can see. */
	public static function run( $name, array $args ) {
		$name = self::normalize( $name );
		$t    = self::all();
		if ( ! isset( $t[ $name ] ) ) {
			throw new Exception( 'Unknown tool: ' . $name . '. Available: ' . implode( ', ', array_keys( $t ) ) );
		}
		if ( RAHM_Ctx::$source === 'mcp' && ! RAHM_Auth::role_allows( RAHM_Ctx::role(), $name ) ) {
			throw new Exception( 'Your role (' . RAHM_Ctx::role() . ') cannot use the ' . $name . ' tool.' );
		}
		$prev                = RAHM_Ctx::$confirmed;
		RAHM_Ctx::$confirmed = ! empty( $args['confirm'] );
		try {
			return call_user_func( $t[ $name ]['f'], $args );
		} finally {
			RAHM_Ctx::$confirmed = $prev;
		}
	}

	/* ================= Tool definitions ================= */

	private static function define( $d ) {
		$ap = array( 'action' => 'string', 'p' => 'object|action parameters', 'confirm' => 'boolean|true only after the owner said yes' );

		$d( 'brief', 'CALL FIRST. Site snapshot, your role, brand profile, safety switches, pending approvals and your playbooks.', array(), function () {
			$th = wp_get_theme();
			$s  = RAHM_Store::settings();
			$pl = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $f ) {
				$pl[] = dirname( $f ) === '.' ? $f : dirname( $f );
			}
			$role  = RAHM_Ctx::role();
			$roles = RAHM_Auth::roles();
			return array(
				'role'      => array( 'key' => $role, 'label' => isset( $roles[ $role ] ) ? $roles[ $role ]['label'] : $role ),
				'site'      => array(
					'name' => get_bloginfo( 'name' ), 'tagline' => get_bloginfo( 'description' ), 'url' => home_url(), 'locale' => get_locale(), 'rtl' => is_rtl(),
					'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'theme' => $th->get_stylesheet(), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null,
					'block_theme' => wp_is_block_theme(), 'elementor' => class_exists( '\Elementor\Plugin' ), 'woocommerce' => class_exists( 'WooCommerce' ),
					'plugins' => $pl, 'seo_plugins' => array_keys( array_filter( RAHM_SEO::engines() ) ),
				),
				'profile'   => array_filter(
					array( 'brand' => $s['brand'], 'voice' => $s['voice'], 'languages' => $s['langs'], 'colors' => $s['colors'], 'rules' => $s['rules'] ),
					function ( $v ) { return $v !== ''; }
				),
				'switches'  => array( 'read_only' => (bool) $s['readonly'], 'file_edits' => (bool) $s['files'], 'php_edits' => (bool) $s['php'], 'maintenance' => (bool) $s['maintenance'] ),
				'skills'    => RAHM_Skills::for_role( $role ),
				'rules'     => 'Plan first and ask the owner in the chat. Anything visitors can see needs the owner\'s yes: after a clear yes call again with confirm=true and it goes live right away. Drafts need no confirmation.',
			);
		} );

		$d( 'skill', 'Load team playbooks. No names = the playbooks for your role. Names: workflow, supervisor, designer, elementor, developer, copywriter, seo, marketing, ecommerce, translator, qa, security, performance, strategist.', array( 'names' => 'array|e.g. ["designer","seo"]' ), function ( $a ) {
			$all   = RAHM_Skills::all();
			$names = isset( $a['names'] ) && $a['names'] ? (array) $a['names'] : RAHM_Skills::for_role( RAHM_Ctx::role() );
			$o     = array();
			foreach ( $names as $n ) {
				$o[ $n ] = isset( $all[ $n ] ) ? $all[ $n ] : 'Unknown skill.';
			}
			return $o;
		} );

		$d( 'batch', 'Run up to 15 tool calls in ONE request (faster): {calls:[{tool,args}], stop_on_error:true}.', array( 'calls' => 'array', 'stop_on_error' => 'boolean' ), function ( $a ) {
			$out  = array();
			$stop = ! isset( $a['stop_on_error'] ) || $a['stop_on_error'];
			foreach ( array_slice( (array) ( isset( $a['calls'] ) ? $a['calls'] : array() ), 0, 15 ) as $c ) {
				$n = self::normalize( isset( $c['tool'] ) ? $c['tool'] : '' );
				if ( $n === 'batch' ) {
					$out[] = array( 'tool' => $n, 'ok' => false, 'error' => 'Not allowed' );
					if ( $stop ) { break; }
					continue;
				}
				$args = isset( $c['args'] ) && is_array( $c['args'] ) ? $c['args'] : array();
				try {
					$out[] = array( 'tool' => $n, 'ok' => true, 'result' => self::run( $n, $args ) );
				} catch ( Throwable $e ) {
					$out[] = array( 'tool' => $n, 'ok' => false, 'error' => $e->getMessage() );
					RAHM_Store::log( 'error', $n, $e->getMessage() );
					if ( $stop ) { break; }
				}
			}
			return $out;
		} );

		$d( 'content', 'Posts/pages/any post type. Actions: list{type,status,search,limit,page} | get{id,meta_keys[],full} | save{id?,title,content,excerpt,status(default draft),slug,type,parent,meta{},terms{tax:[names]},featured_image} or save{items:[...]} | delete{id,force}.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'get', 'save', 'delete' ) ) ) {
				case 'list':
					$q = new WP_Query( array( 'post_type' => isset( $p['type'] ) ? $p['type'] : 'any', 'post_status' => isset( $p['status'] ) ? $p['status'] : 'any', 's' => isset( $p['search'] ) ? $p['search'] : '', 'posts_per_page' => min( 50, (int) ( isset( $p['limit'] ) ? $p['limit'] : 20 ) ), 'paged' => max( 1, (int) ( isset( $p['page'] ) ? $p['page'] : 1 ) ), 'no_found_rows' => true ) );
					return array_map( function ( $x ) { return array( 'id' => $x->ID, 'title' => $x->post_title, 'type' => $x->post_type, 'status' => $x->post_status, 'slug' => $x->post_name, 'url' => get_permalink( $x ), 'builder' => RAHM_Direct::is_elementor( $x->ID ) ? 'elementor' : ( has_blocks( $x->post_content ) ? 'blocks' : 'classic' ) ); }, $q->posts );
				case 'get':
					$x = get_post( (int) ( isset( $p['id'] ) ? $p['id'] : 0 ) );
					if ( ! $x ) { throw new Exception( 'Post not found.' ); }
					$full = ! empty( $p['full'] );
					$meta = array();
					foreach ( get_post_meta( $x->ID ) as $k => $v ) {
						if ( $k !== '' && $k[0] !== '_' ) { $meta[ $k ] = self::cut( maybe_unserialize( $v[0] ), $full, 1500 ); }
					}
					foreach ( (array) ( isset( $p['meta_keys'] ) ? $p['meta_keys'] : array() ) as $k ) { $meta[ $k ] = self::cut( get_post_meta( $x->ID, $k, true ), $full ); }
					$terms = array();
					foreach ( get_object_taxonomies( $x->post_type ) as $tax ) { $terms[ $tax ] = wp_get_object_terms( $x->ID, $tax, array( 'fields' => 'names' ) ); }
					$el = RAHM_Direct::is_elementor( $x->ID );
					return array( 'id' => $x->ID, 'type' => $x->post_type, 'status' => $x->post_status, 'title' => $x->post_title, 'slug' => $x->post_name, 'url' => get_permalink( $x ), 'excerpt' => $x->post_excerpt, 'builder' => $el ? 'elementor (use the elementor tool)' : ( has_blocks( $x->post_content ) ? 'blocks' : 'classic' ), 'content' => self::cut( $x->post_content, $full ), 'chars' => mb_strlen( $x->post_content ), 'meta' => $meta, 'terms' => $terms );
				case 'save':
					$items = isset( $p['items'] ) && is_array( $p['items'] ) ? array_slice( $p['items'], 0, 30 ) : array( $p );
					$live  = false;
					foreach ( $items as $x ) {
						$cur = ! empty( $x['id'] ) ? get_post( (int) $x['id'] ) : null;
						$st  = isset( $x['status'] ) ? $x['status'] : ( $cur ? $cur->post_status : 'draft' );
						if ( in_array( $st, array( 'publish', 'future' ), true ) || ( $cur && in_array( $cur->post_status, array( 'publish', 'future' ), true ) ) ) { $live = true; }
					}
					$names = array();
					foreach ( array_slice( $items, 0, 4 ) as $x ) {
						$names[] = '"' . ( isset( $x['title'] ) ? mb_substr( $x['title'], 0, 40 ) : ( ! empty( $x['id'] ) ? get_the_title( (int) $x['id'] ) : 'untitled' ) ) . '"';
					}
					RAHM_Ctx::gate( $live ? 'live' : 'draft', 'save ' . implode( ', ', $names ) . ( count( $items ) > 4 ? ' and ' . ( count( $items ) - 4 ) . ' more' : '' ) . ( $live ? ' (visible to visitors)' : ' as draft' ) );
					return array_map( array( __CLASS__, 'save_post' ), $items );
				case 'delete':
					$id = (int) ( isset( $p['id'] ) ? $p['id'] : 0 );
					RAHM_Ctx::gate( 'danger', 'delete post ' . $id . ( ! empty( $p['force'] ) ? ' PERMANENTLY' : ' (move to trash)' ) );
					$undo = RAHM_Store::snap_post( $id, array(), 'Deleted' );
					$ok   = ! empty( $p['force'] ) ? wp_delete_post( $id, true ) : wp_trash_post( $id );
					RAHM_Ctx::done( 'content', 'Deleted post #' . $id, $id, $undo );
					return array( 'ok' => (bool) $ok, 'undo_id' => $undo );
			}
		} );

		$d( 'media', 'Media library. Actions: list{search,limit} | upload{url,title,alt,filename} or {items:[...]} | alt{id,alt,title,caption} | missing_alt{limit} | optimize{ids?,max_width,quality} compresses big images.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'upload', 'alt', 'missing_alt', 'optimize' ) ) ) {
				case 'list':
					return array_map( function ( $m ) { return array( 'id' => $m->ID, 'title' => $m->post_title, 'url' => wp_get_attachment_url( $m->ID ), 'alt' => get_post_meta( $m->ID, '_wp_attachment_image_alt', true ) ); }, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 's' => isset( $p['search'] ) ? $p['search'] : '', 'numberposts' => min( 50, (int) ( isset( $p['limit'] ) ? $p['limit'] : 20 ) ) ) ) );
				case 'upload':
					RAHM_Ctx::gate( 'draft', 'upload to the media library' );
					$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array( $p );
					$r     = array_map( array( __CLASS__, 'upload_one' ), array_slice( $items, 0, 10 ) );
					RAHM_Ctx::done( 'media', 'Uploaded ' . count( $r ) . ' file(s) to the media library' );
					return $r;
				case 'alt':
					RAHM_Ctx::gate( 'live', 'change image text (alt/title/caption) for #' . (int) $p['id'] );
					$id = (int) $p['id'];
					if ( isset( $p['alt'] ) ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) ); }
					$u = array( 'ID' => $id );
					if ( isset( $p['title'] ) ) { $u['post_title'] = sanitize_text_field( $p['title'] ); }
					if ( isset( $p['caption'] ) ) { $u['post_excerpt'] = sanitize_text_field( $p['caption'] ); }
					if ( count( $u ) > 1 ) { wp_update_post( $u ); }
					RAHM_Ctx::done( 'media', 'Updated image text for #' . $id );
					return array( 'ok' => true );
				case 'missing_alt':
					$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'numberposts' => min( 100, (int) ( isset( $p['limit'] ) ? $p['limit'] : 50 ) ), 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ), array( 'key' => '_wp_attachment_image_alt', 'value' => '' ) ) ) );
					return array_map( function ( $id ) { return array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => wp_get_attachment_url( $id ) ); }, $ids );
				case 'optimize':
					RAHM_Ctx::gate( 'live', 'compress images (originals are kept in the backup folder)' );
					return self::optimize( $p );
			}
		} );

		$d( 'design', 'Look and feel. Actions: info | branding{logo_id,site_icon_id,title,tagline} | css{css,mode:append|replace} (reads if css omitted) | mods{mods{}} | opt{name,key?,value?} | themes | switch{stylesheet} | child{parent,name}. Undo with the history tool.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'info', 'branding', 'css', 'mods', 'opt', 'themes', 'switch', 'child' ) ) ) {
				case 'info':
					$th = wp_get_theme();
					$lg = get_theme_mod( 'custom_logo' );
					return array( 'theme' => $th->get_stylesheet(), 'name' => $th->get( 'Name' ), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null, 'block_theme' => wp_is_block_theme(), 'logo_id' => $lg, 'logo_url' => $lg ? wp_get_attachment_url( $lg ) : null, 'site_icon' => get_option( 'site_icon' ), 'css_chars' => strlen( wp_get_custom_css() ), 'mod_keys' => array_keys( (array) get_theme_mods() ) );
				case 'branding':
					RAHM_Ctx::gate( 'live', 'change the logo, icon, site title or tagline' );
					if ( isset( $p['logo_id'] ) ) { self::set_mod( 'custom_logo', (int) $p['logo_id'] ); }
					if ( isset( $p['site_icon_id'] ) ) { self::set_opt( 'site_icon', (int) $p['site_icon_id'] ); }
					if ( isset( $p['title'] ) ) { self::set_opt( 'blogname', sanitize_text_field( $p['title'] ) ); }
					if ( isset( $p['tagline'] ) ) { self::set_opt( 'blogdescription', sanitize_text_field( $p['tagline'] ) ); }
					RAHM_Ctx::done( 'design', 'Updated site branding' );
					return array( 'ok' => true );
				case 'css':
					if ( ! isset( $p['css'] ) ) { return array( 'css' => wp_get_custom_css() ); }
					RAHM_Ctx::gate( 'live', 'change the site CSS (' . ( isset( $p['mode'] ) && $p['mode'] === 'replace' ? 'replace all' : 'append' ) . ')' );
					$old = wp_get_custom_css();
					$new = ( isset( $p['mode'] ) && $p['mode'] === 'replace' ) ? $p['css'] : $old . "\n" . $p['css'];
					$r   = wp_update_custom_css_post( $new );
					if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); }
					$u = RAHM_Store::snap( 'css', 'Additional CSS', $old );
					RAHM_Ctx::done( 'design', 'Changed the site CSS', 0, $u );
					return array( 'ok' => true, 'undo_id' => $u );
				case 'mods':
					if ( empty( $p['mods'] ) || ! is_array( $p['mods'] ) ) { return get_theme_mods(); }
					RAHM_Ctx::gate( 'live', 'change theme settings: ' . implode( ', ', array_keys( $p['mods'] ) ) );
					foreach ( $p['mods'] as $k => $v ) { self::set_mod( $k, $v ); }
					RAHM_Ctx::done( 'design', 'Changed theme settings' );
					return array( 'ok' => true );
				case 'opt':
					$n = isset( $p['name'] ) ? $p['name'] : '';
					if ( ! array_key_exists( 'value', $p ) ) {
						$v = get_option( $n );
						if ( isset( $p['key'] ) ) { return is_array( $v ) && array_key_exists( $p['key'], $v ) ? $v[ $p['key'] ] : null; }
						return self::cut( is_string( $v ) ? $v : self::enc( $v ), false, 8000 );
					}
					if ( in_array( $n, self::blocked(), true ) ) { throw new Exception( 'This option is blocked.' ); }
					RAHM_Ctx::gate( 'live', 'change the setting ' . $n );
					if ( isset( $p['key'] ) ) { self::set_key( $n, $p['key'], $p['value'] ); } else { self::set_opt( $n, $p['value'] ); }
					RAHM_Ctx::done( 'design', 'Changed setting ' . $n );
					return array( 'ok' => true );
				case 'themes':
					$o = array();
					foreach ( wp_get_themes() as $s => $th ) { $o[] = array( 'stylesheet' => $s, 'name' => $th->get( 'Name' ), 'parent' => $th->parent() ? $th->parent()->get_stylesheet() : null, 'active' => get_stylesheet() === $s ); }
					return $o;
				case 'switch':
					if ( ! wp_get_theme( $p['stylesheet'] )->exists() ) { throw new Exception( 'Theme not found.' ); }
					RAHM_Ctx::gate( 'danger', 'switch the active theme to ' . $p['stylesheet'] . ' (the whole design changes immediately)' );
					$id = RAHM_Store::snap( 'switch', 'Active theme', get_stylesheet() );
					switch_theme( $p['stylesheet'] );
					RAHM_Ctx::done( 'design', 'Switched theme to ' . $p['stylesheet'], 0, $id );
					return array( 'active' => get_stylesheet(), 'undo_id' => $id );
				case 'child':
					return self::child_theme( $p );
			}
		} );

		$d( 'plugins', 'Actions: list | activate{plugin} | deactivate{plugin} | install{type:plugin|theme,slug,activate}. Everything except list needs the owner\'s yes.', $ap, function ( $a ) {
			$p = self::pp( $a );
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			switch ( self::act( $a, array( 'list', 'activate', 'deactivate', 'install' ) ) ) {
				case 'list':
					$o = array();
					foreach ( get_plugins() as $f => $x ) { $o[] = array( 'file' => $f, 'name' => $x['Name'], 'version' => $x['Version'], 'active' => is_plugin_active( $f ) ); }
					return $o;
				case 'activate':
				case 'deactivate':
					if ( $p['plugin'] === plugin_basename( RAHM_FILE ) ) { throw new Exception( 'This plugin cannot change itself.' ); }
					RAHM_Ctx::gate( 'danger', $a['action'] . ' the plugin ' . $p['plugin'] );
					if ( $a['action'] === 'activate' ) {
						$r = activate_plugin( $p['plugin'] );
						if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); }
					} else {
						deactivate_plugins( $p['plugin'] );
					}
					RAHM_Ctx::done( 'plugins', ucfirst( $a['action'] ) . 'd plugin ' . $p['plugin'] );
					return array( 'active' => is_plugin_active( $p['plugin'] ) );
				case 'install':
					RAHM_Ctx::gate( 'danger', 'install the ' . $p['type'] . ' "' . $p['slug'] . '" from WordPress.org' );
					$r = self::install( $p );
					RAHM_Ctx::done( 'plugins', 'Installed ' . $p['type'] . ' ' . $p['slug'] );
					return $r;
			}
		} );

		$d( 'code', 'Theme/plugin files under themes/ plugins/ mu-plugins/ (e.g. themes/astra-child/style.css). Actions: list{path} | read{path,offset,limit} | write{path,content} or {files:[{path,content}]} (backup + PHP syntax check) | backups | restore{backup}.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'read', 'write', 'backups', 'restore' ) ) ) {
				case 'list':
					$full = self::resolve( $p['path'] );
					if ( ! is_dir( $full ) ) { throw new Exception( 'Not a folder.' ); }
					$o = array();
					foreach ( array_diff( scandir( $full ), array( '.', '..' ) ) as $f ) { $o[] = is_dir( "$full/$f" ) ? $f . '/' : $f; }
					return $o;
				case 'read':
					return self::read_file( $p );
				case 'write':
					return self::write_files( $p );
				case 'backups':
					$o = array();
					foreach ( array_diff( scandir( RAHM_Store::dir( 'files' ) ), array( '.', '..', 'index.php' ) ) as $f ) { $o[] = $f; }
					return array_slice( array_reverse( $o ), 0, 30 );
				case 'restore':
					$src = RAHM_Store::dir( 'files' ) . '/' . basename( $p['backup'] );
					if ( ! is_file( $src ) ) { throw new Exception( 'Backup not found.' ); }
					$rel  = rawurldecode( substr( strstr( basename( $p['backup'] ), '__' ), 2 ) );
					$full = self::resolve( $rel );
					RAHM_Ctx::gate( 'danger', 'restore ' . $rel . ' from a backup' );
					$bk = file_exists( $full ) ? RAHM_Store::backup_file( $rel, $full ) : null;
					copy( $src, $full );
					$u = RAHM_Store::snap( 'file', $rel, array( 'rel' => $rel, 'backup' => $bk ) );
					RAHM_Ctx::done( 'code', 'Restored ' . $rel . ' from backup', 0, $u );
					return array( 'restored' => $rel, 'undo_id' => $u );
			}
		} );

		$d( 'rest', 'Call any WordPress REST route (menus, widgets, global styles, WooCommerce /wc/v3, other plugins): p={method,route,params} or p={calls:[...]}. Reading is free; changes need the owner\'s yes.', $ap, function ( $a ) {
			$p     = self::pp( $a );
			$calls = isset( $p['calls'] ) && is_array( $p['calls'] ) ? $p['calls'] : array( $p );
			$out   = array();
			foreach ( array_slice( $calls, 0, 15 ) as $c ) {
				$m     = strtoupper( isset( $c['method'] ) ? $c['method'] : 'GET' );
				$route = '/' . ltrim( isset( $c['route'] ) ? $c['route'] : '', '/' );
				if ( strpos( $route, '/rahm/' ) === 0 ) { throw new Exception( 'This plugin\'s own routes are not available here.' ); }
				if ( $m !== 'GET' ) {
					$danger = $m === 'DELETE' || preg_match( '#^/wp/v2/(users|plugins|themes)#', $route );
					RAHM_Ctx::gate( $danger ? 'danger' : 'live', "$m $route" );
				}
				$req = new WP_REST_Request( $m, $route );
				$pr  = isset( $c['params'] ) && is_array( $c['params'] ) ? $c['params'] : array();
				if ( $m === 'GET' || $m === 'DELETE' ) { $req->set_query_params( $pr ); } else { $req->set_body_params( $pr ); }
				$res  = rest_do_request( $req );
				$data = rest_get_server()->response_to_data( $res, false );
				if ( $res->is_error() || $res->get_status() >= 400 ) { throw new Exception( $route . ': ' . self::enc( $data ) ); }
				if ( $m !== 'GET' ) { RAHM_Ctx::done( 'rest', "$m $route" ); }
				$out[] = $data;
			}
			return count( $out ) === 1 ? $out[0] : $out;
		} );

		$d( 'site', 'Actions: flush (caches, rewrite rules, Elementor CSS) | log{n} (debug.log tail) | maintenance{on:true|false,message} | links{limit} broken link checker.', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'flush', 'log', 'maintenance', 'links' ) ) ) {
				case 'flush':
					RAHM_Ctx::gate( 'draft', 'clear caches' );
					wp_cache_flush();
					flush_rewrite_rules( false );
					RAHM_Direct::clear_builder_cache( 0 );
					RAHM_Ctx::done( 'site', 'Cleared caches' );
					return array( 'ok' => true );
				case 'log':
					$f = WP_CONTENT_DIR . '/debug.log';
					if ( ! is_file( $f ) ) { return array( 'log' => 'No debug.log found (WP_DEBUG_LOG is off).' ); }
					$h = fopen( $f, 'rb' );
					fseek( $h, max( 0, filesize( $f ) - 16384 ) );
					$tail = stream_get_contents( $h );
					fclose( $h );
					return array( 'log' => implode( "\n", array_slice( explode( "\n", trim( $tail ) ), - min( 100, (int) ( isset( $p['n'] ) ? $p['n'] : 40 ) ) ) ) );
				case 'maintenance':
					$on = ! empty( $p['on'] );
					RAHM_Ctx::gate( 'live', $on ? 'turn the maintenance page ON (visitors see a notice)' : 'turn the maintenance page OFF' );
					$n = array( 'maintenance' => $on ? 1 : 0 );
					if ( ! empty( $p['message'] ) ) { $n['maintenance_msg'] = sanitize_text_field( $p['message'] ); }
					RAHM_Store::save_settings( $n );
					RAHM_Ctx::done( 'site', 'Maintenance page ' . ( $on ? 'ON' : 'OFF' ) );
					return array( 'maintenance' => $on );
				case 'links':
					return RAHM_Audit::broken_links( (int) ( isset( $p['limit'] ) ? $p['limit'] : 40 ) );
			}
		} );

		$d( 'history', 'Undo and activity. Actions: list (undo points kept 30 days) | undo{id} | log{n} (recent activity).', $ap, function ( $a ) {
			$p = self::pp( $a );
			switch ( self::act( $a, array( 'list', 'undo', 'log' ) ) ) {
				case 'list':
					return array_map( function ( $e ) { return array( 'id' => $e['id'], 'type' => $e['type'], 'name' => $e['name'], 'by' => $e['by'], 'when' => wp_date( 'm-d H:i', $e['t'] ) ); }, RAHM_Store::undo_list( 30 ) );
				case 'undo':
					RAHM_Ctx::gate( 'live', 'undo change #' . (int) $p['id'] );
					$r = RAHM_Store::undo( $p['id'] );
					RAHM_Ctx::done( 'history', 'Undid change #' . (int) $p['id'] . ' (' . $r['name'] . ')' );
					return $r;
				case 'log':
					return array_map( function ( $e ) { return array( 'seq' => $e['seq'], 'when' => wp_date( 'm-d H:i', $e['t'] ), 'by' => $e['by'], 'tool' => $e['tool'], 'summary' => $e['summary'] ); }, array_slice( RAHM_Store::log_all(), 0, min( 100, (int) ( isset( $p['n'] ) ? $p['n'] : 25 ) ) ) );
			}
		} );
	}

	/* ================= Implementations ================= */

	public static function save_post( $x ) {
		$map  = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'type' => 'post_type', 'parent' => 'post_parent' );
		$d    = array();
		foreach ( $map as $k => $f ) {
			if ( isset( $x[ $k ] ) ) { $d[ $f ] = $x[ $k ]; }
		}
		$meta = isset( $x['meta'] ) && is_array( $x['meta'] ) ? $x['meta'] : array();
		if ( ! empty( $x['id'] ) ) {
			$id0 = (int) $x['id'];
			if ( ! get_post( $id0 ) ) { return array( 'id' => $id0, 'error' => 'Post not found.' ); }
			$undo   = RAHM_Store::snap_post( $id0, array_keys( $meta ), 'Saved' );
			$d['ID'] = $id0;
			$id      = wp_update_post( wp_slash( $d ), true );
			$new     = false;
		} else {
			$d  += array( 'post_status' => 'draft', 'post_type' => 'post' );
			$id  = wp_insert_post( wp_slash( $d ), true );
			$new = true;
			$undo = is_wp_error( $id ) ? 0 : RAHM_Store::snap_new_post( $id );
		}
		if ( is_wp_error( $id ) ) { return array( 'error' => $id->get_error_message() ); }
		foreach ( $meta as $k => $v ) {
			if ( $k === RAHM_Direct::EL && is_array( $v ) ) { $v = wp_json_encode( $v ); }
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
		if ( $meta && isset( $meta[ RAHM_Direct::EL ] ) ) { RAHM_Direct::clear_builder_cache( $id ); }
		if ( ! empty( $x['terms'] ) && is_array( $x['terms'] ) ) {
			foreach ( $x['terms'] as $tax => $names ) { wp_set_object_terms( $id, (array) $names, $tax ); }
		}
		if ( ! empty( $x['featured_image'] ) ) { set_post_thumbnail( $id, (int) $x['featured_image'] ); }
		RAHM_Ctx::done( 'content', ( $new ? 'Created "' : 'Updated "' ) . get_the_title( $id ) . '" (' . get_post_status( $id ) . ')', $id, $undo );
		return array( 'id' => $id, 'status' => get_post_status( $id ), 'url' => get_permalink( $id ), 'undo_id' => $undo );
	}

	private static function upload_one( $x ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( empty( $x['url'] ) || ! preg_match( '#^https?://#i', $x['url'] ) ) { return array( 'error' => 'A valid http(s) url is needed.' ); }
		$tmp = download_url( $x['url'] );
		if ( is_wp_error( $tmp ) ) { return array( 'error' => $tmp->get_error_message() ); }
		$name = sanitize_file_name( ! empty( $x['filename'] ) ? $x['filename'] : basename( (string) wp_parse_url( $x['url'], PHP_URL_PATH ) ) );
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, isset( $x['title'] ) ? $x['title'] : null );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return array( 'error' => $id->get_error_message() ); }
		if ( ! empty( $x['alt'] ) ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $x['alt'] ) ); }
		return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
	}

	/** Shrink large JPEG/PNG/WebP files. The original is copied to the backup folder first. */
	public static function optimize( array $p ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$max = max( 800, (int) ( isset( $p['max_width'] ) ? $p['max_width'] : 2000 ) );
		$q   = min( 95, max( 50, (int) ( isset( $p['quality'] ) ? $p['quality'] : 82 ) ) );
		$ids = isset( $p['ids'] ) ? array_map( 'intval', (array) $p['ids'] ) : get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ), 'numberposts' => 40, 'fields' => 'ids', 'orderby' => 'date' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! is_file( $file ) || filesize( $file ) < 150 * 1024 ) { continue; }
			if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $file ) ) { continue; }
			$before = filesize( $file );
			$ed     = wp_get_image_editor( $file );
			if ( is_wp_error( $ed ) ) { continue; }
			$sz = $ed->get_size();
			if ( $sz['width'] > $max ) { $ed->resize( $max, null ); }
			$ed->set_quality( $q );
			$res = $ed->save( $file . '.rahm-tmp', get_post_mime_type( $id ) );
			if ( is_wp_error( $res ) || empty( $res['path'] ) || ! is_file( $res['path'] ) ) { continue; }
			$tmp = $res['path'];
			if ( filesize( $tmp ) < $before * 0.92 ) {
				$bk = RAHM_Store::dir( 'media' ) . '/' . gmdate( 'Ymd-His' ) . '__' . $id . '__' . basename( $file );
				copy( $file, $bk );
				rename( $tmp, $file );
				wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
				$out[] = array( 'id' => $id, 'before_kb' => (int) round( $before / 1024 ), 'after_kb' => (int) round( filesize( $file ) / 1024 ), 'original_backup' => basename( $bk ) );
			} else {
				@unlink( $tmp );
			}
			if ( count( $out ) >= 10 ) { break; }
		}
		RAHM_Ctx::done( 'media', 'Compressed ' . count( $out ) . ' image(s)' );
		return array( 'compressed' => $out, 'note' => 'Originals are kept in uploads/rahm-backups/media for 30 days.' );
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
		if ( ! $res ) {
			$m = $skin->get_error_messages();
			throw new Exception( $m ? implode( '; ', $m ) : 'Install failed (is the filesystem writable? On Pantheon Dev switch to SFTP mode).' );
		}
		if ( ! empty( $p['activate'] ) && $isp && $up->plugin_info() ) { activate_plugin( $up->plugin_info() ); }
		return array( 'installed' => $p['slug'], 'activated' => ! empty( $p['activate'] ) );
	}

	private static function child_theme( $p ) {
		if ( ! RAHM_Store::settings()['files'] ) { throw new Exception( 'File edits are turned off in Rashid Ahm MCP > Settings.' ); }
		if ( ! wp_get_theme( $p['parent'] )->exists() ) { throw new Exception( 'Parent theme not found.' ); }
		$slug = sanitize_title( $p['name'] );
		$dir  = self::resolve( 'themes/' . $slug );
		if ( file_exists( $dir ) ) { throw new Exception( 'A theme with that folder already exists.' ); }
		RAHM_Ctx::gate( 'danger', 'create the child theme themes/' . $slug );
		wp_mkdir_p( $dir );
		file_put_contents( "$dir/style.css", "/*\nTheme Name: " . sanitize_text_field( $p['name'] ) . "\nTemplate: " . $p['parent'] . "\nAuthor: Rashid Ahm MCP\nVersion: 1.0.0\n*/\n" );
		file_put_contents( "$dir/functions.php", "<?php\nadd_action( 'wp_enqueue_scripts', function () {\n\twp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );\n\twp_enqueue_style( 'child-style', get_stylesheet_uri(), array( 'parent-style' ), wp_get_theme()->get( 'Version' ) );\n} );\n" );
		RAHM_Ctx::done( 'code', 'Created child theme ' . $slug );
		return array( 'created' => 'themes/' . $slug, 'next' => 'design switch to activate it' );
	}

	public static function read_file( array $p ) {
		$full = self::resolve( $p['path'] );
		if ( ! is_file( $full ) || filesize( $full ) > 1048576 ) { throw new Exception( 'Not a readable file under 1 MB.' ); }
		$c   = file_get_contents( $full );
		$off = max( 0, (int) ( isset( $p['offset'] ) ? $p['offset'] : 0 ) );
		$lim = min( 60000, (int) ( isset( $p['limit'] ) ? $p['limit'] : 8000 ) );
		return array( 'path' => $p['path'], 'chars' => mb_strlen( $c ), 'offset' => $off, 'content' => mb_substr( $c, $off, $lim ), 'more' => mb_strlen( $c ) > $off + $lim );
	}

	/** Write one or more theme/plugin files. PHP is syntax-checked first; existing files are backed up. */
	public static function write_files( array $p ) {
		$s = RAHM_Store::settings();
		if ( ! $s['files'] ) { throw new Exception( 'File edits are turned off. The owner can turn them on in Rashid Ahm MCP > Settings.' ); }
		$files = isset( $p['files'] ) && is_array( $p['files'] ) ? $p['files'] : array( $p );
		$plan  = array();
		foreach ( array_slice( $files, 0, 15 ) as $f ) {
			if ( ! isset( $f['path'], $f['content'] ) ) { throw new Exception( 'Each file needs path and content.' ); }
			$full = self::resolve( $f['path'] );
			$ext  = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'twig', 'xml' ), true ) ) { throw new Exception( 'File type not allowed: ' . $f['path'] ); }
			if ( $ext === 'php' ) {
				if ( ! $s['php'] ) { throw new Exception( 'PHP edits are turned off. The owner can turn them on in Rashid Ahm MCP > Settings.' ); }
				try {
					token_get_all( $f['content'], TOKEN_PARSE );
				} catch ( ParseError $e ) {
					throw new Exception( 'PHP syntax error in ' . $f['path'] . ', nothing was written: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')' );
				}
			}
			$plan[] = array( $f['path'], $full, $f['content'] );
		}
		RAHM_Ctx::gate( 'danger', 'write ' . count( $plan ) . ' file(s): ' . implode( ', ', array_column( $plan, 0 ) ) . ' (existing files are backed up first)' );
		$out = array();
		foreach ( $plan as $x ) {
			$bk = file_exists( $x[1] ) ? RAHM_Store::backup_file( $x[0], $x[1] ) : null;
			wp_mkdir_p( dirname( $x[1] ) );
			if ( file_put_contents( $x[1], $x[2], LOCK_EX ) === false ) { throw new Exception( 'Could not write ' . $x[0] . ' (read-only filesystem? On Pantheon Dev switch to SFTP mode).' ); }
			$u     = RAHM_Store::snap( 'file', $x[0], array( 'rel' => $x[0], 'backup' => $bk ) );
			$out[] = array( 'path' => $x[0], 'bytes' => strlen( $x[2] ), 'backup' => $bk, 'undo_id' => $u );
			RAHM_Ctx::done( 'code', 'Wrote ' . $x[0], 0, $u );
		}
		return $out;
	}

	/** Turn "themes/x/style.css" into a safe absolute path. Only themes, plugins and mu-plugins; never this plugin. */
	public static function resolve( $rel ) {
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
		if ( strpos( $chk, $base ) !== 0 ) { throw new Exception( 'Path escapes the allowed folders.' ); }
		$self = realpath( RAHM_DIR );
		if ( $self && strpos( $chk . '/', $self . '/' ) === 0 ) { throw new Exception( "This plugin's own files cannot be changed from here." ); }
		return $full;
	}
}
