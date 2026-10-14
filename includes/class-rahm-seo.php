<?php
/**
 * SEO: works with Yoast or Rank Math if installed, otherwise uses its own fields and prints
 * the meta description, canonical, Open Graph and robots tags in the page head.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_SEO {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 20 );
	}

	public static function engines() {
		return array(
			'yoast'     => defined( 'WPSEO_VERSION' ),
			'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
		);
	}

	public static function builtin_active() {
		$e = self::engines();
		return ! $e['yoast'] && ! $e['rank_math'];
	}

	private static function map() {
		return array(
			'yoast'     => array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical', 'og_image' => '_yoast_wpseo_opengraph-image' ),
			'rank_math' => array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url', 'og_image' => 'rank_math_facebook_image' ),
			'builtin'   => array( 'title' => '_rahm_seo_title', 'description' => '_rahm_seo_desc', 'focus_keyword' => '_rahm_seo_kw', 'canonical' => '_rahm_seo_canonical', 'og_image' => '_rahm_seo_og' ),
		);
	}

	public static function read( $id ) {
		$e   = self::engines();
		$map = self::map();
		$o   = array();
		foreach ( $map as $eng => $keys ) {
			if ( $eng !== 'builtin' && empty( $e[ $eng ] ) ) { continue; }
			if ( $eng === 'builtin' && ! self::builtin_active() ) { continue; }
			foreach ( $keys as $f => $mk ) { $o[ $eng ][ $f ] = (string) get_post_meta( $id, $mk, true ); }
			if ( $eng === 'builtin' ) { $o[ $eng ]['noindex'] = (bool) get_post_meta( $id, '_rahm_seo_noindex', true ); }
		}
		return $o;
	}

	/** Write SEO fields. Returns the engines that were updated. */
	public static function write( $id, array $a ) {
		$e    = self::engines();
		$map  = self::map();
		$used = array();
		$keys = array();
		foreach ( $map as $eng => $fields ) {
			foreach ( $fields as $mk ) { $keys[] = $mk; }
		}
		$keys[] = '_rahm_seo_noindex';
		$undo   = RAHM_Store::snap_post( $id, $keys, 'SEO change' );
		foreach ( $map as $eng => $fields ) {
			if ( $eng !== 'builtin' && empty( $e[ $eng ] ) ) { continue; }
			if ( $eng === 'builtin' && ! self::builtin_active() ) { continue; }
			foreach ( $fields as $f => $mk ) {
				if ( ! isset( $a[ $f ] ) ) { continue; }
				$val = in_array( $f, array( 'canonical', 'og_image' ), true ) ? esc_url_raw( $a[ $f ] ) : sanitize_text_field( $a[ $f ] );
				update_post_meta( $id, $mk, $val );
			}
			if ( isset( $a['noindex'] ) ) {
				if ( $eng === 'yoast' ) { update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', $a['noindex'] ? '1' : '2' ); }
				if ( $eng === 'rank_math' ) { update_post_meta( $id, 'rank_math_robots', $a['noindex'] ? array( 'noindex' ) : array( 'index' ) ); }
				if ( $eng === 'builtin' ) { update_post_meta( $id, '_rahm_seo_noindex', $a['noindex'] ? 1 : 0 ); }
			}
			$used[] = $eng;
		}
		RAHM_Ctx::$post = (int) $id;
		RAHM_Ctx::$undo = $undo;
		return $used;
	}

	/** Effective title/description for a post, whichever engine holds them. */
	public static function effective( $post ) {
		$title = '';
		$desc  = '';
		foreach ( self::read( $post->ID ) as $v ) {
			if ( ! $title && ! empty( $v['title'] ) ) { $title = $v['title']; }
			if ( ! $desc && ! empty( $v['description'] ) ) { $desc = $v['description']; }
		}
		return array( $title ? $title : $post->post_title, $desc );
	}

	/** Issues for one post: list of array( weight, message ). */
	public static function check_post( WP_Post $p ) {
		list( $title, $desc ) = self::effective( $p );
		$c   = (string) $p->post_content;
		$els = RAHM_Direct::is_elementor( $p->ID ) ? wp_json_encode( RAHM_Direct::el_get( $p->ID ) ) : '';
		$all = $c . ' ' . $els;
		$i   = array();
		$tl  = mb_strlen( $title );
		if ( $tl > 60 ) { $i[] = array( 8, "SEO title is $tl characters (max 60)" ); }
		if ( $tl < 20 ) { $i[] = array( 6, "SEO title is very short ($tl characters)" ); }
		$dl = mb_strlen( $desc );
		if ( $dl === 0 ) { $i[] = array( 15, 'No meta description' ); } elseif ( $dl < 120 || $dl > 160 ) { $i[] = array( 7, "Meta description should be 120-160 characters (now $dl)" ); }
		if ( preg_match_all( '/<h1\b/i', $c ) > 1 ) { $i[] = array( 8, 'More than one H1 in the content' ); }
		$na = preg_match_all( '/<img\b(?![^>]*\balt=["\'][^"\']+["\'])[^>]*>/i', $c );
		if ( $na ) { $i[] = array( min( 15, 4 * $na ), "$na image(s) without alt text" ); }
		$w = str_word_count( wp_strip_all_tags( $c ) );
		if ( $w < 300 && ! $els ) { $i[] = array( 8, "Thin content ($w words)" ); }
		$links = self::links( $c . ' ' . $els );
		if ( ! count( $links['internal'] ) ) { $i[] = array( 6, 'No internal links to other pages' ); }
		if ( strlen( $p->post_name ) > 60 ) { $i[] = array( 4, 'Slug is long (use short, clear words)' ); }
		if ( preg_match( '/^[a-z0-9]+-[a-z0-9]+-\d+$/', $p->post_name ) && (int) preg_replace( '/\D/', '', $p->post_name ) > 0 && preg_match( '/-\d+$/', $p->post_name ) ) { $i[] = array( 2, 'Slug ends with a number (duplicate slug?)' ); }
		return $i;
	}

	public static function score( array $issues ) {
		$s = 100;
		foreach ( $issues as $x ) { $s -= $x[0]; }
		return max( 0, $s );
	}

	/** Internal/external links found in HTML (also works on Elementor JSON). */
	public static function links( $html ) {
		$html = str_replace( '\\/', '/', (string) $html );
		$out  = array( 'internal' => array(), 'external' => array() );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$h = html_entity_decode( $x[1] );
				if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $h ) ) { continue; }
				$rel = preg_match( '/rel=["\']([^"\']*)["\']/i', $x[0], $r ) ? $r[1] : '';
				$row = array( 'href' => $h, 'text' => mb_substr( trim( wp_strip_all_tags( $x[2] ) ), 0, 60 ), 'rel' => $rel );
				$hh  = wp_parse_url( $h, PHP_URL_HOST );
				if ( ! $hh || $hh === $host ) { $out['internal'][] = $row; } else { $out['external'][] = $row; }
			}
		}
		return $out;
	}

	/* ---------- Built-in head tags (only when no SEO plugin is active) ---------- */

	public static function title( $t ) {
		if ( ! self::builtin_active() || ! is_singular() ) { return $t; }
		$v = get_post_meta( get_queried_object_id(), '_rahm_seo_title', true );
		return $v ? $v : $t;
	}

	public static function head() {
		if ( ! self::builtin_active() || ! is_singular() ) { return; }
		$id    = get_queried_object_id();
		$desc  = (string) get_post_meta( $id, '_rahm_seo_desc', true );
		$title = (string) get_post_meta( $id, '_rahm_seo_title', true );
		$canon = (string) get_post_meta( $id, '_rahm_seo_canonical', true );
		$og    = (string) get_post_meta( $id, '_rahm_seo_og', true );
		if ( ! $og && has_post_thumbnail( $id ) ) { $og = (string) get_the_post_thumbnail_url( $id, 'large' ); }
		$title = $title ? $title : get_the_title( $id );
		if ( $desc ) { echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n"; }
		if ( get_post_meta( $id, '_rahm_seo_noindex', true ) ) { echo "<meta name=\"robots\" content=\"noindex,follow\">\n"; }
		if ( ! function_exists( 'rel_canonical' ) || $canon ) { echo '<link rel="canonical" href="' . esc_url( $canon ? $canon : get_permalink( $id ) ) . "\">\n"; }
		echo '<meta property="og:type" content="article">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
		if ( $desc ) { echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n"; }
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $id ) ) . "\">\n";
		if ( $og ) { echo '<meta property="og:image" content="' . esc_url( $og ) . "\">\n"; }
		echo '<meta name="twitter:card" content="' . ( $og ? 'summary_large_image' : 'summary' ) . "\">\n";
	}
}
