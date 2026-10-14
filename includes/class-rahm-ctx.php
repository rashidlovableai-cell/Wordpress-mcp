<?php
/**
 * Who is acting right now, and the one gate every change passes through.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Ctx {

	/** @var array|null key record */
	public static $key       = null;
	/** mcp | studio | system */
	public static $source    = 'system';
	public static $confirmed = false;
	/** Post currently being changed (for the live feed). */
	public static $post      = 0;
	public static $undo      = 0;

	public static function set( $source, $key = null ) {
		self::$source    = $source;
		self::$key       = $key;
		self::$confirmed = false;
		self::$post      = 0;
		self::$undo      = 0;
	}

	public static function who() {
		if ( self::$source === 'studio' ) {
			return 'Owner';
		}
		return self::$key ? self::$key['label'] : 'System';
	}

	public static function role() {
		if ( self::$source === 'studio' ) {
			return 'owner';
		}
		return self::$key ? self::$key['role'] : 'system';
	}

	/**
	 * Every write goes through here.
	 * read   = nothing changes
	 * draft  = safe work that visitors cannot see
	 * live   = visitors can see it: needs the owner's yes in the chat (confirm=true), then it goes straight to the site
	 * danger = installs, deletes, code: same as live
	 */
	public static function gate( $level, $summary ) {
		if ( $level === 'read' ) {
			return;
		}
		if ( RAHM_Store::settings()['readonly'] ) {
			throw new Exception( 'Read-only mode is ON. The owner can turn it off in Rashid Ahm MCP > Settings.' );
		}
		if ( $level === 'draft' || self::$source === 'studio' ) {
			return;
		}
		if ( ! self::$confirmed ) {
			throw new Exception( 'CONFIRMATION REQUIRED: this will ' . $summary . '. Tell the owner exactly what you will do and ask "Do you want this?". After a clear yes, call again with confirm=true and it goes live right away.' );
		}
	}

	/** Record a finished change in the live feed. */
	public static function done( $tool, $summary, $post = 0, $undo = 0 ) {
		RAHM_Store::log( 'change', $tool, $summary, array( 'post' => (int) ( $post ? $post : self::$post ), 'undo' => (int) ( $undo ? $undo : self::$undo ) ) );
		self::$post = 0;
		self::$undo = 0;
	}
}
