<?php
/**
 * Storage: settings, activity log, undo snapshots (30 days), team notes/reports and audit scores.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Store {

	const OPT_SETTINGS = 'rahm_settings';
	const OPT_LOG      = 'rahm_log';
	const OPT_UNDO     = 'rahm_undo';
	const OPT_INBOX    = 'rahm_inbox';
	const OPT_AUDITS   = 'rahm_audits';
	const OPT_SEQ      = 'rahm_seq';
	const NONE         = '__none__';
	const UNDO_DAYS    = 30;
	const LOG_MAX      = 500;

	public static function init() {
		add_action( 'rahm_daily', array( __CLASS__, 'purge' ) );
		if ( ! wp_next_scheduled( 'rahm_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rahm_daily' );
		}
	}

	public static function activate() {
		self::dir();
		if ( ! wp_next_scheduled( 'rahm_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rahm_daily' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'rahm_daily' );
	}

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'lang'             => 'en',
			'readonly'         => 0,
			'files'            => 1,
			'php'              => 1,
			'maintenance'      => 0,
			'maintenance_msg'  => 'We are making improvements and will be back shortly.',
			'brand'            => '',
			'voice'            => '',
			'langs'            => 'English, Urdu',
			'colors'           => '',
			'rules'            => '',
			'skills'           => '',
			'owner'            => 0,
		);
	}

	public static function settings() {
		return wp_parse_args( get_option( self::OPT_SETTINGS, array() ), self::defaults() );
	}

	public static function save_settings( array $new ) {
		update_option( self::OPT_SETTINGS, array_merge( self::settings(), $new ), false );
	}

	/* ---------- Sequence + activity log ---------- */

	public static function next_seq() {
		$n = (int) get_option( self::OPT_SEQ, 0 ) + 1;
		update_option( self::OPT_SEQ, $n, false );
		return $n;
	}

	public static function current_seq() {
		return (int) get_option( self::OPT_SEQ, 0 );
	}

	/**
	 * Record one event. $kind: change | read | error | key | queued | approval
	 */
	public static function log( $kind, $tool, $summary, $extra = array() ) {
		$entry = array_merge(
			array(
				'seq'     => self::next_seq(),
				't'       => time(),
				'kind'    => $kind,
				'tool'    => $tool,
				'summary' => mb_substr( (string) $summary, 0, 240 ),
				'by'      => RAHM_Ctx::who(),
				'role'    => RAHM_Ctx::role(),
				'post'    => 0,
				'undo'    => 0,
			),
			$extra
		);
		$log   = get_option( self::OPT_LOG, array() );
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::OPT_LOG, $log, false );
		return $entry;
	}

	public static function log_all() {
		return array_reverse( (array) get_option( self::OPT_LOG, array() ) );
	}

	public static function log_since( $seq ) {
		$out = array();
		foreach ( (array) get_option( self::OPT_LOG, array() ) as $e ) {
			if ( (int) $e['seq'] > (int) $seq ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/* ---------- Backup folder ---------- */

	public static function dir( $sub = '' ) {
		$u    = wp_upload_dir();
		$base = $u['basedir'] . '/rahm-backups';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			@file_put_contents( $base . '/.htaccess', "Deny from all\n" );
			@file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" );
		}
		if ( $sub ) {
			$p = $base . '/' . $sub;
			if ( ! is_dir( $p ) ) {
				wp_mkdir_p( $p );
				@file_put_contents( $p . '/index.php', "<?php // Silence is golden.\n" );
			}
			return $p;
		}
		return $base;
	}

	/* ---------- Undo snapshots ---------- */

	/**
	 * Save the OLD state before a change so it can be undone for 30 days.
	 * Returns the undo id.
	 */
	public static function snap( $type, $name, $old, $note = '' ) {
		$id   = self::next_seq();
		$file = 'u' . $id . '.dat';
		@file_put_contents( self::dir( 'undo' ) . '/' . $file, serialize( $old ) ); // phpcs:ignore
		$idx   = get_option( self::OPT_UNDO, array() );
		$idx[] = array(
			'id'   => $id,
			't'    => time(),
			'type' => $type,
			'name' => mb_substr( (string) $name, 0, 160 ),
			'note' => mb_substr( (string) $note, 0, 160 ),
			'file' => $file,
			'by'   => RAHM_Ctx::who(),
		);
		update_option( self::OPT_UNDO, $idx, false );
		return $id;
	}

	public static function undo_list( $limit = 60 ) {
		return array_slice( array_reverse( (array) get_option( self::OPT_UNDO, array() ) ), 0, $limit );
	}

	public static function undo( $id ) {
		foreach ( (array) get_option( self::OPT_UNDO, array() ) as $e ) {
			if ( (int) $e['id'] !== (int) $id ) {
				continue;
			}
			$path = self::dir( 'undo' ) . '/' . $e['file'];
			if ( ! is_file( $path ) ) {
				throw new Exception( 'The saved copy for this change is gone, so it cannot be undone.' );
			}
			$old = unserialize( file_get_contents( $path ), array( 'allowed_classes' => false ) ); // phpcs:ignore
			self::restore( $e['type'], $e['name'], $old );
			return array( 'undone' => (int) $id, 'type' => $e['type'], 'name' => $e['name'] );
		}
		throw new Exception( 'Undo id not found (changes are kept for ' . self::UNDO_DAYS . ' days).' );
	}

	private static function restore( $type, $name, $old ) {
		switch ( $type ) {
			case 'opt':
				$old === self::NONE ? delete_option( $name ) : update_option( $name, $old );
				break;
			case 'mod':
				$old === self::NONE ? remove_theme_mod( $name ) : set_theme_mod( $name, $old );
				break;
			case 'key':
				list( $opt, $key ) = explode( '|', $name, 2 );
				$o                 = get_option( $opt, array() );
				if ( $old === self::NONE ) {
					unset( $o[ $key ] );
				} else {
					$o[ $key ] = $old;
				}
				update_option( $opt, $o );
				break;
			case 'css':
				wp_update_custom_css_post( $old );
				break;
			case 'switch':
				switch_theme( $old );
				break;
			case 'post':
				self::restore_post( $old );
				break;
			case 'file':
				self::restore_file( $old );
				break;
			default:
				throw new Exception( 'Unknown undo type.' );
		}
	}

	private static function restore_post( $old ) {
		$id = (int) $old['id'];
		if ( ! empty( $old['created'] ) ) {
			wp_trash_post( $id );
			return;
		}
		wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $old['fields'] ) ) );
		foreach ( (array) $old['meta'] as $k => $v ) {
			if ( $v === self::NONE ) {
				delete_post_meta( $id, $k );
			} else {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
		}
		RAHM_Direct::clear_builder_cache( $id );
	}

	private static function restore_file( $old ) {
		$full = RAHM_Tools::resolve( $old['rel'] );
		if ( $old['backup'] === null ) {
			if ( file_exists( $full ) ) {
				@unlink( $full );
			}
			return;
		}
		$src = self::dir( 'files' ) . '/' . $old['backup'];
		if ( ! is_file( $src ) ) {
			throw new Exception( 'The backup file is missing.' );
		}
		copy( $src, $full );
	}

	/** Snapshot a post (fields + the meta keys we are about to touch). */
	public static function snap_post( $post_id, array $meta_keys = array(), $note = '' ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return 0;
		}
		$meta = array();
		foreach ( $meta_keys as $k ) {
			$meta[ $k ] = metadata_exists( 'post', $post_id, $k ) ? get_post_meta( $post_id, $k, true ) : self::NONE;
		}
		$old = array(
			'id'     => (int) $post_id,
			'fields' => array(
				'post_title'   => $p->post_title,
				'post_content' => $p->post_content,
				'post_excerpt' => $p->post_excerpt,
				'post_status'  => $p->post_status,
				'post_name'    => $p->post_name,
				'post_parent'  => $p->post_parent,
			),
			'meta'   => $meta,
		);
		return self::snap( 'post', '#' . $post_id . ' ' . $p->post_title, $old, $note );
	}

	public static function snap_new_post( $post_id ) {
		return self::snap( 'post', '#' . $post_id . ' (new)', array( 'id' => (int) $post_id, 'created' => true ), 'created' );
	}

	/** Copy a theme/plugin file into the backup folder. Returns backup file name. */
	public static function backup_file( $rel, $full ) {
		$name = gmdate( 'Ymd-His' ) . '__' . rawurlencode( $rel );
		copy( $full, self::dir( 'files' ) . '/' . $name );
		return $name;
	}

	/** Daily cleanup: remove undo copies older than 30 days. */
	public static function purge() {
		$cut = time() - self::UNDO_DAYS * DAY_IN_SECONDS;
		$idx = (array) get_option( self::OPT_UNDO, array() );
		$keep = array();
		foreach ( $idx as $e ) {
			if ( (int) $e['t'] < $cut ) {
				@unlink( self::dir( 'undo' ) . '/' . $e['file'] );
			} else {
				$keep[] = $e;
			}
		}
		update_option( self::OPT_UNDO, $keep, false );
		foreach ( array( 'files', 'media' ) as $sub ) {
			foreach ( (array) glob( self::dir( $sub ) . '/*' ) as $f ) {
				if ( is_file( $f ) && basename( $f ) !== 'index.php' && filemtime( $f ) < $cut ) {
					@unlink( $f );
				}
			}
		}
	}

	/* ---------- Team notes and reports ---------- */

	public static function inbox_all() {
		return array_reverse( (array) get_option( self::OPT_INBOX, array() ) );
	}

	public static function inbox_get( $id ) {
		foreach ( (array) get_option( self::OPT_INBOX, array() ) as $i ) {
			if ( (int) $i['id'] === (int) $id ) {
				return $i;
			}
		}
		return null;
	}

	public static function inbox_add( array $item ) {
		$box  = (array) get_option( self::OPT_INBOX, array() );
		$item = array_merge(
			array(
				'id'     => self::next_seq(),
				't'      => time(),
				'type'   => 'note',
				'status' => 'done',
				'from'   => RAHM_Ctx::who(),
				'role'   => RAHM_Ctx::role(),
				'title'  => '',
				'body'   => '',
				'tool'   => '',
				'args'   => array(),
				'score'  => null,
				'result' => '',
			),
			$item
		);
		$box[] = $item;
		if ( count( $box ) > 200 ) {
			$resolved = 0;
			foreach ( $box as $k => $i ) {
				if ( $resolved < count( $box ) - 200 ) {
					unset( $box[ $k ] );
					$resolved++;
				}
			}
			$box = array_values( $box );
		}
		update_option( self::OPT_INBOX, $box, false );
		return $item;
	}

	public static function inbox_update( $id, array $fields ) {
		$box = (array) get_option( self::OPT_INBOX, array() );
		foreach ( $box as $k => $i ) {
			if ( (int) $i['id'] === (int) $id ) {
				$box[ $k ] = array_merge( $i, $fields );
				update_option( self::OPT_INBOX, $box, false );
				return $box[ $k ];
			}
		}
		return null;
	}

	/* ---------- Audit scores ---------- */

	public static function audits() {
		return (array) get_option( self::OPT_AUDITS, array() );
	}

	public static function save_audit( $role, array $result ) {
		$a          = self::audits();
		$a[ $role ] = array_merge( $result, array( 't' => time(), 'by' => RAHM_Ctx::who() ) );
		update_option( self::OPT_AUDITS, $a, false );
	}
}
