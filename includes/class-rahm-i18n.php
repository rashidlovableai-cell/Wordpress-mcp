<?php
/**
 * English and Urdu labels for the dashboard. English text is the key.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_I18n {

	private static $ur = null;

	public static function lang() {
		return RAHM_Store::settings()['lang'] === 'ur' ? 'ur' : 'en';
	}

	public static function rtl() {
		return self::lang() === 'ur';
	}

	public static function map() {
		if ( self::$ur === null ) {
			self::$ur = include RAHM_DIR . 'includes/ur.php';
		}
		return self::$ur;
	}

	public static function t( $en ) {
		if ( self::lang() !== 'ur' ) {
			return $en;
		}
		$m = self::map();
		return isset( $m[ $en ] ) ? $m[ $en ] : $en;
	}

	public static function e( $en ) {
		echo esc_html( self::t( $en ) );
	}
}
