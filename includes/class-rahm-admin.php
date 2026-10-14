<?php
/**
 * WordPress dashboard: Live Studio, Connect (keys), Approvals, Scores, Activity, Settings, Help.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Admin {

	const SLUG = 'rahm-studio';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rahm_keys', array( __CLASS__, 'keys_action' ) );
		add_action( 'admin_post_rahm_settings', array( __CLASS__, 'settings_action' ) );
		add_action( 'admin_post_rahm_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RAHM_FILE ), function ( $l ) {
			array_unshift( $l, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html( RAHM_I18n::t( 'Open Studio' ) ) . '</a>' );
			return $l;
		} );
	}

	/* ---------- Menu ---------- */

	public static function menu() {
		add_menu_page( 'Rashid Ahm MCP', 'Rashid Ahm MCP', 'manage_options', self::SLUG, array( __CLASS__, 'page_studio' ), 'dashicons-groups', 3 );
		$subs = array(
			self::SLUG       => array( 'Live Studio', 'page_studio' ),
			'rahm-connect'   => array( 'Connect AI', 'page_connect' ),
			'rahm-scores'    => array( 'Expert scores', 'page_scores' ),
			'rahm-activity'  => array( 'Activity', 'page_activity' ),
			'rahm-settings'  => array( 'Settings', 'page_settings' ),
			'rahm-help'      => array( 'Help', 'page_help' ),
		);
		foreach ( $subs as $slug => $s ) {
			$title = RAHM_I18n::t( $s[0] );
			add_submenu_page( self::SLUG, 'Rashid Ahm MCP', $title, 'manage_options', $slug, array( __CLASS__, $s[1] ) );
		}
	}

	public static function assets( $hook ) {
		if ( strpos( $hook, 'rahm-' ) === false && strpos( $hook, self::SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'rahm-admin', RAHM_URL . 'assets/admin.css', array(), RAHM_VERSION );
		wp_enqueue_script( 'rahm-admin', RAHM_URL . 'assets/admin.js', array(), RAHM_VERSION, true );
		if ( isset( $_GET['page'] ) && $_GET['page'] === self::SLUG ) { // phpcs:ignore
			wp_enqueue_media();
			wp_enqueue_style( 'rahm-studio', RAHM_URL . 'assets/studio.css', array( 'rahm-admin' ), RAHM_VERSION );
			wp_enqueue_script( 'rahm-studio', RAHM_URL . 'assets/studio.js', array( 'rahm-admin' ), RAHM_VERSION, true );
			wp_localize_script( 'rahm-studio', 'RAHM', array(
				'rest'    => esc_url_raw( rest_url( 'rahm/v1/studio/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'home'    => home_url( '/' ),
				'seq'     => RAHM_Store::current_seq(),
				'i18n'    => self::js_strings(),
			) );
		}
	}

	private static function js_strings() {
		$keys = array( 'Live', 'Offline', 'Last change', 'No changes yet.', 'Edit text', 'Replace image', 'Add image', 'Add image after', 'Delete', 'Cancel', 'Are you sure you want to delete this element?', 'Select an element first.', 'Saved.', 'Choose an image', 'Use this image', 'Click any text or image in the page. Double-click text to type in place.', 'This site address differs from the admin address, so direct editing is blocked by the browser. The preview still refreshes live.', 'The preview cannot be shown.', 'Your site answered with error', 'The page may be password protected, blocked by a security rule or firewall, or the address is wrong. Open it in a new tab to see the real message.', 'Your site does not allow itself to be shown inside another page (X-Frame-Options or frame-ancestors).', 'Open in a new tab', 'just now', 'seconds ago', 'minutes ago', 'hours ago', 'Page', 'Text', 'Image' );
		$o    = array();
		foreach ( $keys as $k ) {
			$o[ $k ] = RAHM_I18n::t( $k );
		}
		return $o;
	}

	/* ---------- Shared bits ---------- */

	private static function t( $s ) {
		return RAHM_I18n::t( $s );
	}

	private static function open( $title, $sub = '' ) {
		$dir = RAHM_I18n::rtl() ? ' dir="rtl"' : '';
		echo '<div class="wrap rahm" ' . $dir . '><div class="rahm-head"><div class="rahm-brand"><span class="rahm-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 18V6l8 7 8-7v12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><h1>' . esc_html( $title ) . '</h1>' . ( $sub ? '<p>' . esc_html( $sub ) . '</p>' : '' ) . '</div></div>';
		echo '<nav class="rahm-nav" aria-label="Rashid Ahm MCP">';
		$items = array( self::SLUG => 'Live Studio', 'rahm-connect' => 'Connect AI', 'rahm-scores' => 'Expert scores', 'rahm-activity' => 'Activity', 'rahm-settings' => 'Settings', 'rahm-help' => 'Help' );
		$cur   = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		foreach ( $items as $slug => $label ) {
			echo '<a class="' . ( $cur === $slug ? 'is-on' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( self::t( $label ) ) . '</a>';
		}
		echo '</nav></div>';
	}

	private static function close() {
		echo '<p class="rahm-foot">' . esc_html( self::t( 'Made from scratch by Rashid Ahmad.' ) ) . ' <a href="mailto:' . esc_attr( RAHM_CONTACT ) . '">' . esc_html( RAHM_CONTACT ) . '</a></p></div>';
	}

	private static function notice() {
		if ( ! empty( $_GET['rahm_msg'] ) ) { // phpcs:ignore
			echo '<div class="rahm-note" role="status">' . esc_html( wp_unslash( $_GET['rahm_msg'] ) ) . '</div>'; // phpcs:ignore
		}
	}

	private static function ago( $t ) {
		return esc_html( human_time_diff( $t, time() ) ) . ' ' . esc_html( self::t( 'ago' ) );
	}

	/* ---------- Live Studio ---------- */

	public static function page_studio() {
		self::open( self::t( 'Live Studio' ), self::t( 'Watch your website change live. Click anything to edit it.' ) );
		?>
		<div class="rst" id="rahm-studio">
			<div class="rst-bar">
				<label class="screen-reader-text" for="rst-page"><?php echo esc_html( self::t( 'Page' ) ); ?></label>
				<select id="rst-page"></select>
				<div class="rst-devices" role="group" aria-label="Device">
					<button type="button" data-w="100%" class="is-on"><?php echo esc_html( self::t( 'Laptop' ) ); ?></button>
					<button type="button" data-w="820px"><?php echo esc_html( self::t( 'Tablet' ) ); ?></button>
					<button type="button" data-w="390px"><?php echo esc_html( self::t( 'Mobile' ) ); ?></button>
				</div>
				<label class="rst-switch"><input type="checkbox" id="rst-edit"><span><?php echo esc_html( self::t( 'Edit mode' ) ); ?></span></label>
				<button type="button" class="button" id="rst-add" hidden><?php echo esc_html( self::t( 'Add image' ) ); ?></button>
				<button type="button" class="button" id="rst-reload"><?php echo esc_html( self::t( 'Reload preview' ) ); ?></button>
				<span class="rst-live" id="rst-live"><i></i><span id="rst-livetxt"><?php echo esc_html( self::t( 'Live' ) ); ?></span></span>
			</div>
			<div class="rst-selbar" id="rst-selbar" hidden>
				<span id="rst-seltag"></span>
				<button type="button" class="button" id="rst-b-text" hidden><?php echo esc_html( self::t( 'Edit text' ) ); ?></button>
				<button type="button" class="button" id="rst-b-replace" hidden><?php echo esc_html( self::t( 'Replace image' ) ); ?></button>
				<button type="button" class="button" id="rst-b-after"><?php echo esc_html( self::t( 'Add image after' ) ); ?></button>
				<button type="button" class="button rst-danger" id="rst-b-del"><?php echo esc_html( self::t( 'Delete' ) ); ?></button>
				<button type="button" class="rst-link" id="rst-b-cancel"><?php echo esc_html( self::t( 'Cancel' ) ); ?></button>
			</div>
			<p class="rst-last" id="rst-last" aria-live="polite"></p>
			<div class="rst-warn" id="rst-warn" role="alert" hidden></div>
			<div class="rst-stage"><div class="rst-frame" id="rst-frame"><iframe id="rst-iframe" title="Preview" src="about:blank"></iframe></div></div>
		</div>
		<?php
		self::close();
	}

	/* ---------- Connect AI (keys) ---------- */

	public static function page_connect() {
		self::open( self::t( 'Connect AI' ), self::t( 'Create a key for each AI account. Each key has a role and an expiry.' ) );
		self::notice();
		$new = get_transient( 'rahm_new_keys_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'rahm_new_keys_' . get_current_user_id() );
			echo '<div class="rahm-card rahm-new"><h2>' . esc_html( self::t( 'Your new keys' ) ) . '</h2><p class="rahm-warn">' . esc_html( self::t( 'Copy each link now. For safety it is shown only once. Paste the link into the AI app as a remote MCP server, then paste the starting command into the chat.' ) ) . '</p>';
			foreach ( $new as $n ) {
				echo '<div class="rahm-key"><div class="rahm-key-top"><strong>' . esc_html( $n['label'] ) . '</strong><span class="rahm-pill">' . esc_html( $n['roleLabel'] ) . '</span><span class="rahm-pill rahm-pill-soft">' . esc_html( $n['expiryLabel'] ) . '</span></div>';
				echo '<label>' . esc_html( self::t( 'MCP link' ) ) . '</label><div class="rahm-copy"><input type="text" readonly value="' . esc_attr( $n['link'] ) . '"><button type="button" class="button" data-copy>' . esc_html( self::t( 'Copy' ) ) . '</button></div>';
				echo '<label>' . esc_html( self::t( 'Starting command (paste in the AI chat)' ) ) . '</label><div class="rahm-copy rahm-copy-area"><textarea readonly rows="5">' . esc_textarea( $n['starter'] ) . '</textarea><button type="button" class="button" data-copy>' . esc_html( self::t( 'Copy' ) ) . '</button></div></div>';
			}
			echo '</div>';
		}
		$roles = RAHM_Auth::roles();
		$exp   = RAHM_Auth::expiries();
		?>
		<div class="rahm-card">
			<h2><?php echo esc_html( self::t( 'Generate keys' ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rahm_keys' ); ?>
				<input type="hidden" name="action" value="rahm_keys"><input type="hidden" name="op" value="generate">
				<p class="rahm-lead"><?php echo esc_html( self::t( 'Tick the roles you need. You can make several keys at once. Choose one supervisor, and workers for the jobs you want to hand out.' ) ); ?></p>
				<div class="rahm-roles">
					<?php foreach ( $roles as $slug => $r ) : ?>
						<label class="rahm-role"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, array( 'supervisor' ), true ) ); ?>><span><strong><?php echo esc_html( self::t( $r['label'] ) ); ?></strong><em><?php echo esc_html( self::t( $r['about'] ) ); ?></em></span></label>
					<?php endforeach; ?>
				</div>
				<div class="rahm-formrow">
					<label for="rahm-exp"><?php echo esc_html( self::t( 'Key expires after' ) ); ?></label>
					<select name="expiry" id="rahm-exp"><?php foreach ( $exp as $k => $e ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, '30d', false ) . '>' . esc_html( self::t( $e['label'] ) ) . '</option>'; } ?></select>
					<label for="rahm-name"><?php echo esc_html( self::t( 'Name (optional)' ) ); ?></label>
					<input type="text" name="name" id="rahm-name" placeholder="<?php echo esc_attr( self::t( 'e.g. ChatGPT account 2' ) ); ?>">
				</div>
				<button type="submit" class="button button-primary button-hero"><?php echo esc_html( self::t( 'Generate keys' ) ); ?></button>
			</form>
		</div>
		<div class="rahm-card">
			<h2><?php echo esc_html( self::t( 'Your keys' ) ); ?></h2>
			<?php
			$keys = array_reverse( RAHM_Auth::keys() );
			if ( ! $keys ) {
				echo '<p>' . esc_html( self::t( 'No keys yet. Generate your first key above.' ) ) . '</p>';
			} else {
				echo '<div class="rahm-table-wrap"><table class="rahm-table"><thead><tr><th>' . esc_html( self::t( 'Name' ) ) . '</th><th>' . esc_html( self::t( 'Role' ) ) . '</th><th>' . esc_html( self::t( 'Status' ) ) . '</th><th>' . esc_html( self::t( 'Expires' ) ) . '</th><th>' . esc_html( self::t( 'Last used' ) ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $keys as $k ) {
					$st = RAHM_Auth::status( $k );
					echo '<tr><td>' . esc_html( $k['label'] ) . '</td><td>' . esc_html( isset( $roles[ $k['role'] ] ) ? self::t( $roles[ $k['role'] ]['label'] ) : $k['role'] ) . '</td><td><span class="rahm-st rahm-st-' . esc_attr( $st ) . '">' . esc_html( self::t( ucfirst( $st ) ) ) . '</span></td><td>' . esc_html( wp_date( 'd M Y, H:i', $k['expires'] ) ) . '</td><td>' . ( $k['last_used'] ? self::ago( $k['last_used'] ) : '-' ) . '</td><td class="rahm-actions">';
					$url = function ( $op ) use ( $k ) { return wp_nonce_url( admin_url( 'admin-post.php?action=rahm_keys&op=' . $op . '&id=' . $k['id'] ), 'rahm_keys' ); };
					if ( $st === 'active' ) { echo '<a href="' . esc_url( $url( 'revoke' ) ) . '">' . esc_html( self::t( 'Turn off' ) ) . '</a> '; }
					echo '<a class="rahm-del" href="' . esc_url( $url( 'delete' ) ) . '" onclick="return confirm(\'' . esc_js( self::t( 'Delete this key?' ) ) . '\')">' . esc_html( self::t( 'Delete' ) ) . '</a></td></tr>';
				}
				echo '</tbody></table></div>';
			}
			?>
		</div>
		<?php
		self::close();
	}

	public static function keys_action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'rahm_keys' );
		$op  = isset( $_REQUEST['op'] ) ? sanitize_key( $_REQUEST['op'] ) : '';
		$msg = '';
		if ( $op === 'generate' ) {
			$roles  = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) $_POST['roles'] ) : array();
			$expiry = isset( $_POST['expiry'] ) ? sanitize_key( $_POST['expiry'] ) : '30d';
			$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$all    = RAHM_Auth::roles();
			$exp    = RAHM_Auth::expiries();
			$out    = array();
			foreach ( array_slice( $roles, 0, 12 ) as $r ) {
				if ( ! isset( $all[ $r ] ) ) { continue; }
				list( $token, $rec ) = RAHM_Auth::generate( $r, count( $roles ) === 1 ? $name : '', $expiry, get_current_user_id() );
				$out[] = array( 'label' => $rec['label'], 'roleLabel' => $all[ $r ]['label'], 'expiryLabel' => isset( $exp[ $expiry ] ) ? $exp[ $expiry ]['label'] : '', 'link' => RAHM_Auth::link( $token ), 'starter' => RAHM_Skills::starter( $r ) );
				RAHM_Ctx::set( 'studio' );
				RAHM_Store::log( 'key', 'keys', 'Created a ' . $all[ $r ]['label'] . ' key', array( 'post' => 0 ) );
				RAHM_Ctx::set( 'system' );
			}
			if ( $out ) { set_transient( 'rahm_new_keys_' . get_current_user_id(), $out, 10 * MINUTE_IN_SECONDS ); }
			$msg = $out ? '' : self::t( 'Choose at least one role.' );
		} elseif ( $op === 'revoke' && ! empty( $_GET['id'] ) ) {
			RAHM_Auth::revoke( sanitize_key( $_GET['id'] ) );
			$msg = self::t( 'Key turned off.' );
		} elseif ( $op === 'delete' && ! empty( $_GET['id'] ) ) {
			RAHM_Auth::delete( sanitize_key( $_GET['id'] ) );
			$msg = self::t( 'Key deleted.' );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'rahm-connect', 'rahm_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Scores ---------- */

	public static function page_scores() {
		self::open( self::t( 'Expert scores' ), self::t( 'Scores out of 100, measured on your real pages, plus each expert\'s written report.' ) );
		$a     = RAHM_Store::audits();
		$roles = array( 'seo' => 'SEO', 'copywriter' => 'Copywriter', 'designer' => 'Designer', 'performance' => 'Speed', 'marketing' => 'Marketing', 'qa' => 'Quality check' );
		echo '<div class="rahm-grid" id="rahm-scores" data-rest="' . esc_url( rest_url( 'rahm/v1/studio/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
		foreach ( $roles as $slug => $label ) {
			$r = isset( $a[ $slug ] ) ? $a[ $slug ] : null;
			$s = $r ? (int) $r['score'] : null;
			$cls = $s === null ? 'none' : ( $s >= 85 ? 'good' : ( $s >= 65 ? 'ok' : 'bad' ) );
			echo '<div class="rahm-card rahm-score rahm-score-' . esc_attr( $cls ) . '" data-role="' . esc_attr( $slug ) . '"><div class="rahm-ring" style="--p:' . (int) $s . '"><span>' . ( $s === null ? '-' : (int) $s ) . '</span></div><h3>' . esc_html( self::t( $label ) ) . '</h3>';
			if ( $r ) {
				echo '<p class="rahm-meta">' . self::ago( $r['t'] ) . ' · ' . esc_html( $r['by'] ) . '</p><ul>';
				foreach ( array_slice( (array) $r['top_fixes'], 0, 4 ) as $f ) { echo '<li>' . esc_html( $f ) . '</li>'; }
				echo '</ul>';
			} else {
				echo '<p class="rahm-meta">' . esc_html( self::t( 'Not measured yet.' ) ) . '</p>';
			}
			echo '<button type="button" class="button" data-run="' . esc_attr( $slug ) . '">' . esc_html( self::t( 'Measure now' ) ) . '</button></div>';
		}
		echo '</div>';
		$reports = RAHM_Store::inbox_all();
		echo '<div class="rahm-card"><h2>' . esc_html( self::t( 'Written reports from the team' ) ) . '</h2>';
		if ( ! $reports ) {
			echo '<p>' . esc_html( self::t( 'No reports yet. Ask an expert AI to review the site and post a report.' ) ) . '</p>';
		}
		foreach ( array_slice( $reports, 0, 12 ) as $i ) {
			echo '<div class="rahm-report"><div class="rahm-key-top"><strong>' . esc_html( $i['title'] ? $i['title'] : self::t( 'Report' ) ) . '</strong><span class="rahm-pill">' . esc_html( $i['from'] ) . '</span>' . ( $i['score'] !== null ? '<span class="rahm-pill rahm-pill-soft">' . (int) $i['score'] . '/100</span>' : '' ) . '</div><div class="rahm-body">' . wp_kses_post( wpautop( $i['body'] ) ) . '</div></div>';
		}
		echo '</div>';
		self::close();
	}

	/* ---------- Activity ---------- */

	public static function page_activity() {
		self::open( self::t( 'Activity' ), self::t( 'Everything the team and you did, newest first. The last 500 actions are kept.' ) );
		$log = array_slice( RAHM_Store::log_all(), 0, 500 );
		echo '<div class="rahm-card"><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rahm_export&fmt=csv' ), 'rahm_export' ) ) . '">' . esc_html( self::t( 'Export CSV' ) ) . '</a> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rahm_export&fmt=json' ), 'rahm_export' ) ) . '">' . esc_html( self::t( 'Export JSON' ) ) . '</a></p>';
		if ( ! $log ) {
			echo '<p>' . esc_html( self::t( 'No activity yet.' ) ) . '</p>';
		} else {
			echo '<div class="rahm-table-wrap"><table class="rahm-table"><thead><tr><th>' . esc_html( self::t( 'When' ) ) . '</th><th>' . esc_html( self::t( 'Who' ) ) . '</th><th>' . esc_html( self::t( 'What' ) ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $log as $e ) {
				echo '<tr class="rahm-k-' . esc_attr( $e['kind'] ) . '"><td>' . esc_html( wp_date( 'd M, H:i', $e['t'] ) ) . '</td><td>' . esc_html( $e['by'] ) . '</td><td>' . esc_html( $e['summary'] ) . '</td><td>';
				if ( ! empty( $e['undo'] ) ) { echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rahm_settings&op=undo&id=' . (int) $e['undo'] ), 'rahm_settings' ) ) . '">' . esc_html( self::t( 'Undo' ) ) . '</a>'; }
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
		self::close();
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'rahm_export' );
		$log = array_reverse( RAHM_Store::log_all() );
		if ( isset( $_GET['fmt'] ) && $_GET['fmt'] === 'json' ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="rashid-ahm-mcp-activity.json"' );
			echo wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			exit;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rashid-ahm-mcp-activity.csv"' );
		$o = fopen( 'php://output', 'w' );
		fputcsv( $o, array( 'time', 'who', 'role', 'tool', 'kind', 'summary' ) );
		foreach ( $log as $e ) {
			$s = (string) $e['summary'];
			if ( preg_match( '/^[=+\-@]/', $s ) ) { $s = "'" . $s; }
			fputcsv( $o, array( wp_date( 'Y-m-d H:i:s', $e['t'] ), $e['by'], $e['role'], $e['tool'], $e['kind'], $s ) );
		}
		exit;
	}

	/* ---------- Settings ---------- */

	public static function page_settings() {
		self::open( self::t( 'Settings' ), self::t( 'Language, safety switches and what the AI should know about your brand.' ) );
		self::notice();
		$s = RAHM_Store::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rahm-form">
			<?php wp_nonce_field( 'rahm_settings' ); ?>
			<input type="hidden" name="action" value="rahm_settings"><input type="hidden" name="op" value="save">
			<div class="rahm-card">
				<h2><?php echo esc_html( self::t( 'Language' ) ); ?></h2>
				<p><label><input type="radio" name="lang" value="en" <?php checked( $s['lang'], 'en' ); ?>> English</label> &nbsp; <label><input type="radio" name="lang" value="ur" <?php checked( $s['lang'], 'ur' ); ?>> اردو</label></p>
			</div>
			<div class="rahm-card">
				<h2><?php echo esc_html( self::t( 'Safety switches' ) ); ?></h2>
				<p class="rahm-lead"><?php echo esc_html( self::t( 'Every change is backed up first and can be undone for 30 days. Anything visitors can see goes live only after you say yes to the AI in the chat.' ) ); ?></p>
				<label class="rahm-check"><input type="checkbox" name="readonly" value="1" <?php checked( $s['readonly'] ); ?>><span><strong><?php echo esc_html( self::t( 'Read-only mode' ) ); ?></strong><em><?php echo esc_html( self::t( 'AI can look at the site but cannot change anything.' ) ); ?></em></span></label>
				<label class="rahm-check"><input type="checkbox" name="files" value="1" <?php checked( $s['files'] ); ?>><span><strong><?php echo esc_html( self::t( 'Allow theme and plugin file edits' ) ); ?></strong><em><?php echo esc_html( self::t( 'CSS, JavaScript, HTML and other files, with backups.' ) ); ?></em></span></label>
				<label class="rahm-check"><input type="checkbox" name="php" value="1" <?php checked( $s['php'] ); ?>><span><strong><?php echo esc_html( self::t( 'Allow PHP file edits' ) ); ?></strong><em><?php echo esc_html( self::t( 'PHP is checked for errors before it is written.' ) ); ?></em></span></label>
				<label class="rahm-check"><input type="checkbox" name="maintenance" value="1" <?php checked( $s['maintenance'] ); ?>><span><strong><?php echo esc_html( self::t( 'Maintenance page' ) ); ?></strong><em><?php echo esc_html( self::t( 'Visitors who are not logged in see a short notice.' ) ); ?></em></span></label>
				<label for="rahm-mm"><?php echo esc_html( self::t( 'Maintenance message' ) ); ?></label>
				<input type="text" id="rahm-mm" name="maintenance_msg" value="<?php echo esc_attr( $s['maintenance_msg'] ); ?>" class="large-text">
			</div>
			<div class="rahm-card">
				<h2><?php echo esc_html( self::t( 'Brand profile (the AI reads this)' ) ); ?></h2>
				<div class="rahm-two">
					<div><label for="rb"><?php echo esc_html( self::t( 'Brand or business' ) ); ?></label><input type="text" id="rb" name="brand" value="<?php echo esc_attr( $s['brand'] ); ?>"></div>
					<div><label for="rv"><?php echo esc_html( self::t( 'Tone of voice' ) ); ?></label><input type="text" id="rv" name="voice" value="<?php echo esc_attr( $s['voice'] ); ?>" placeholder="formal, warm, simple"></div>
					<div><label for="rl"><?php echo esc_html( self::t( 'Languages' ) ); ?></label><input type="text" id="rl" name="langs" value="<?php echo esc_attr( $s['langs'] ); ?>"></div>
					<div><label for="rc"><?php echo esc_html( self::t( 'Brand colors' ) ); ?></label><input type="text" id="rc" name="colors" value="<?php echo esc_attr( $s['colors'] ); ?>" placeholder="#14213D, #B08D57"></div>
				</div>
				<label for="rr"><?php echo esc_html( self::t( 'Rules the team must follow' ) ); ?></label>
				<textarea id="rr" name="rules" rows="4" class="large-text"><?php echo esc_textarea( $s['rules'] ); ?></textarea>
				<label for="rs"><?php echo esc_html( self::t( 'Your own playbooks (optional). Start each with ## name' ) ); ?></label>
				<textarea id="rs" name="skills" rows="4" class="large-text" dir="ltr"><?php echo esc_textarea( $s['skills'] ); ?></textarea>
			</div>
			<button type="submit" class="button button-primary button-hero"><?php echo esc_html( self::t( 'Save settings' ) ); ?></button>
		</form>
		<?php
		self::close();
	}

	public static function settings_action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'rahm_settings' );
		$op  = isset( $_REQUEST['op'] ) ? sanitize_key( $_REQUEST['op'] ) : '';
		$msg = '';
		$to  = 'rahm-settings';
		if ( $op === 'save' ) {
			$in = wp_unslash( $_POST );
			RAHM_Store::save_settings( array(
				'lang'            => isset( $in['lang'] ) && $in['lang'] === 'ur' ? 'ur' : 'en',
				'readonly'        => empty( $in['readonly'] ) ? 0 : 1,
				'files'           => empty( $in['files'] ) ? 0 : 1,
				'php'             => empty( $in['php'] ) ? 0 : 1,
				'maintenance'     => empty( $in['maintenance'] ) ? 0 : 1,
				'maintenance_msg' => sanitize_text_field( isset( $in['maintenance_msg'] ) ? $in['maintenance_msg'] : '' ),
				'brand'           => sanitize_text_field( isset( $in['brand'] ) ? $in['brand'] : '' ),
				'voice'           => sanitize_text_field( isset( $in['voice'] ) ? $in['voice'] : '' ),
				'langs'           => sanitize_text_field( isset( $in['langs'] ) ? $in['langs'] : '' ),
				'colors'          => sanitize_text_field( isset( $in['colors'] ) ? $in['colors'] : '' ),
				'rules'           => sanitize_textarea_field( isset( $in['rules'] ) ? $in['rules'] : '' ),
				'skills'          => sanitize_textarea_field( isset( $in['skills'] ) ? $in['skills'] : '' ),
			) );
			$msg = self::t( 'Settings saved.' );
		} elseif ( $op === 'undo' && ! empty( $_GET['id'] ) ) {
			$to = 'rahm-activity';
			try {
				$r = RAHM_Store::undo( (int) $_GET['id'] );
				RAHM_Ctx::set( 'studio' );
				RAHM_Store::log( 'change', 'history', 'Owner undid change #' . (int) $_GET['id'] . ' (' . $r['name'] . ')' );
				RAHM_Ctx::set( 'system' );
				$msg = self::t( 'Undone.' );
			} catch ( Exception $e ) {
				$msg = $e->getMessage();
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => $to, 'rahm_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Help ---------- */

	public static function page_help() {
		self::open( self::t( 'Help' ), self::t( 'How to connect your AI team in five steps.' ) );
		$steps = array(
			array( 'Generate keys', 'Open Connect AI, tick the roles you need (for example Supervisor, Designer, SEO), choose how long the keys last, and press Generate keys.' ),
			array( 'Paste the link into each AI', 'In Claude, ChatGPT or any MCP app, add a remote MCP server and paste the link of one key. Use a different AI account for each role. If you have only one account, make a Solo key.' ),
			array( 'Paste the starting command', 'Copy the starting command shown under each link, paste it in that AI\'s chat and write your task at the end.' ),
			array( 'Say yes in the chat', 'The AI tells you its plan and asks if you want it. When you say yes, the change goes live on your website right away. Drafts need no yes.' ),
			array( 'Watch it happen', 'Open Live Studio. The preview refreshes by itself when something changes. If you do not like a change, press Undo in Activity (kept for 30 days).' ),
		);
		echo '<div class="rahm-card"><ol class="rahm-steps">';
		foreach ( $steps as $s ) { echo '<li><strong>' . esc_html( self::t( $s[0] ) ) . '</strong><span>' . esc_html( self::t( $s[1] ) ) . '</span></li>'; }
		echo '</ol></div>';
		echo '<div class="rahm-card"><h2>' . esc_html( self::t( 'Need help?' ) ) . '</h2><p>' . esc_html( self::t( 'Rashid Ahm MCP was made from scratch by Rashid Ahmad. If you need help, write to' ) ) . ' <a href="mailto:' . esc_attr( RAHM_CONTACT ) . '">' . esc_html( RAHM_CONTACT ) . '</a>.</p><p class="rahm-meta">' . esc_html( 'Version ' . RAHM_VERSION ) . '</p></div>';
		self::close();
	}
}
