<?php
/**
 * Page checks and expert scores. Scores come from real measurements of your own pages,
 * so they are honest numbers, not guesses.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Audit {

	private static function same_site( $url ) {
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private static function fetch( $url, $mobile = false ) {
		$ua = $mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 RahmMCP-QA' : 'Mozilla/5.0 RahmMCP-QA';
		return wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'user-agent' => $ua, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	}

	/** Open a page like a visitor and list problems as array( weight, message ). */
	public static function analyze( $url, $mobile = false, $keep_html = false ) {
		if ( ! self::same_site( $url ) ) {
			return array( 'url' => $url, 'error' => 'Only pages of this site can be checked.' );
		}
		$t = microtime( true );
		$r = self::fetch( $url, $mobile );
		if ( is_wp_error( $r ) ) {
			return array( 'url' => $url, 'error' => $r->get_error_message() );
		}
		$ms   = (int) round( ( microtime( true ) - $t ) * 1000 );
		$b    = wp_remote_retrieve_body( $r );
		$code = (int) wp_remote_retrieve_response_code( $r );
		$kb   = (int) round( strlen( $b ) / 1024 );
		$i    = array();
		if ( $code !== 200 ) { $i[] = array( 40, "HTTP $code" ); }
		if ( $ms > 2500 ) { $i[] = array( 12, "Slow ({$ms} ms)" ); } elseif ( $ms > 1500 ) { $i[] = array( 6, "Could be faster ({$ms} ms)" ); }
		if ( $kb > 2000 ) { $i[] = array( 10, "Heavy HTML ({$kb} KB)" ); } elseif ( $kb > 500 ) { $i[] = array( 4, "Large HTML ({$kb} KB)" ); }
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $b, $m ) ) {
			if ( mb_strlen( trim( wp_strip_all_tags( $m[1] ) ) ) > 60 ) { $i[] = array( 4, 'Title over 60 characters' ); }
		} else { $i[] = array( 10, 'No <title>' ); }
		if ( ! preg_match( '/<meta[^>]+name=["\']description["\']/i', $b ) ) { $i[] = array( 8, 'No meta description' ); }
		if ( ! preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $b ) ) { $i[] = array( 15, 'No viewport meta (not mobile-ready)' ); }
		if ( ! preg_match( '/<html[^>]+lang=/i', $b ) ) { $i[] = array( 4, 'No lang attribute' ); }
		$h1 = preg_match_all( '/<h1\b/i', $b );
		if ( $h1 !== 1 ) { $i[] = array( 6, "H1 count is $h1 (should be 1)" ); }
		$na = preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $b );
		if ( $na ) { $i[] = array( min( 12, 3 * $na ), "$na image(s) without alt" ); }
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', $b ) ) { $i[] = array( 15, 'Page is noindex' ); }
		if ( preg_match( '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)\s*[^<\n]{0,100}/', $b, $m ) ) { $i[] = array( 25, 'PHP message: ' . trim( $m[0] ) ); }
		if ( strpos( $url, 'https://' ) === 0 && ( $mc = preg_match_all( '/(?:src|href)=["\']http:\/\/[^"\']+/i', $b ) ) ) { $i[] = array( 8, "$mc mixed-content link(s)" ); }
		$scripts = preg_match_all( '/<script\b[^>]*\bsrc=/i', $b );
		$styles  = preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\']/i', $b );
		if ( $scripts > 20 ) { $i[] = array( 6, "$scripts script files (too many)" ); }
		if ( $styles > 15 ) { $i[] = array( 5, "$styles stylesheet files (too many)" ); }
		$imgs = preg_match_all( '/<img\b/i', $b );
		$lazy = preg_match_all( '/<img\b[^>]*loading=["\']lazy["\']/i', $b );
		if ( $imgs > 4 && $lazy < $imgs - 3 ) { $i[] = array( 5, 'Images below the fold are not lazy-loaded' ); }
		$nodim = preg_match_all( '/<img\b(?![^>]*\bwidth=)[^>]*>/i', $b );
		$res   = array(
			'url' => $url, 'status' => $code, 'ms' => $ms, 'kb' => $kb, 'issues' => $i,
			'm'   => array( 'scripts' => $scripts, 'styles' => $styles, 'imgs' => $imgs, 'lazy' => $lazy, 'img_no_size' => $nodim, 'og' => (bool) preg_match( '/property=["\']og:title/i', $b ), 'canonical' => (bool) preg_match( '/rel=["\']canonical/i', $b ), 'tel' => (bool) preg_match( '/href=["\'](tel:|https?:\/\/wa\.me|https?:\/\/api\.whatsapp)/i', $b ), 'mail' => (bool) preg_match( '/href=["\']mailto:/i', $b ), 'form' => (bool) preg_match( '/<form\b/i', $b ), 'cta' => (bool) preg_match( '/<(a|button)\b[^>]*>[^<]{0,40}(contact|call|buy|book|order|get started|start|apply|enrol|enroll|register|shop|subscribe|quote|learn more|اب|رابطہ|ابھی)[^<]{0,30}<\/(a|button)>/iu', $b ) || (bool) preg_match( '/class=["\'][^"\']*(btn|button|elementor-button)/i', $b ) ),
		);
		if ( $keep_html ) { $res['html'] = $b; }
		return $res;
	}

	/** Title, description, canonical, robots and social tags exactly as visitors' browsers and search engines see them. */
	public static function head_tags( $url ) {
		if ( ! self::same_site( $url ) ) { throw new Exception( 'Only pages of this site can be checked.' ); }
		$r = self::fetch( $url );
		if ( is_wp_error( $r ) ) { throw new Exception( $r->get_error_message() ); }
		$b = wp_remote_retrieve_body( $r );
		$h = preg_match( '/<head\b.*?<\/head>/is', $b, $m ) ? $m[0] : $b;
		$o = array( 'url' => $url, 'status' => (int) wp_remote_retrieve_response_code( $r ), 'title' => preg_match( '/<title[^>]*>(.*?)<\/title>/is', $h, $x ) ? trim( html_entity_decode( wp_strip_all_tags( $x[1] ), ENT_QUOTES, 'UTF-8' ) ) : '', 'tags' => array() );
		if ( preg_match_all( '/<(meta|link)\b[^>]*>/i', $h, $all ) ) {
			foreach ( $all[0] as $tag ) {
				if ( preg_match( '/(name|property)=["\'](description|robots|keywords|og:[^"\']+|twitter:[^"\']+|viewport)["\']|rel=["\'](canonical|alternate|icon|shortlink)["\']/i', $tag ) ) { $o['tags'][] = mb_substr( $tag, 0, 300 ); }
			}
		}
		return $o;
	}

	/** Which pages link to which: finds orphan pages (no internal links pointing to them). */
	public static function link_map() {
		$posts   = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 200 ) );
		$front   = (int) get_option( 'page_on_front' );
		$inbound = array();
		$outgo   = array();
		foreach ( $posts as $p ) { $inbound[ $p->ID ] = 0; }
		foreach ( $posts as $p ) {
			$src = $p->post_content . ' ' . ( RAHM_Direct::is_elementor( $p->ID ) ? wp_json_encode( RAHM_Direct::el_get( $p->ID ) ) : '' );
			$l   = RAHM_SEO::links( $src );
			$seen = array();
			foreach ( array_slice( $l['internal'], 0, 60 ) as $x ) {
				$h   = $x['href'];
				$abs = strpos( $h, 'http' ) === 0 ? $h : home_url( '/' . ltrim( $h, '/' ) );
				$tid = url_to_postid( $abs );
				if ( $tid && $tid !== $p->ID && isset( $inbound[ $tid ] ) && empty( $seen[ $tid ] ) ) { $inbound[ $tid ]++; $seen[ $tid ] = 1; }
			}
			$outgo[ $p->ID ] = count( $seen );
		}
		$orphans = array();
		$dead    = array();
		foreach ( $posts as $p ) {
			if ( $inbound[ $p->ID ] === 0 && $p->ID !== $front ) { $orphans[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p ) ); }
			if ( $outgo[ $p->ID ] === 0 ) { $dead[] = array( 'id' => $p->ID, 'title' => $p->post_title ); }
		}
		arsort( $inbound );
		$top = array();
		foreach ( array_slice( $inbound, 0, 8, true ) as $id => $n ) { $top[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'inbound_links' => $n ); }
		return array( 'pages' => count( $posts ), 'orphans_no_links_in' => array_slice( $orphans, 0, 40 ), 'no_links_out' => array_slice( $dead, 0, 40 ), 'most_linked' => $top, 'tip' => 'Link every orphan page from a relevant strong page, using descriptive anchor text.' );
	}

	/** Broken link checker for links found in published pages. */
	public static function broken_links( $limit = 40 ) {
		$limit = max( 5, min( 80, $limit ) );
		$posts = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 20 ) );
		$seen  = array();
		$bad   = array();
		foreach ( $posts as $p ) {
			$src = $p->post_content . ' ' . ( RAHM_Direct::is_elementor( $p->ID ) ? wp_json_encode( RAHM_Direct::el_get( $p->ID ) ) : '' );
			$l   = RAHM_SEO::links( $src );
			foreach ( array_merge( $l['internal'], $l['external'] ) as $x ) {
				$h   = $x['href'];
				$abs = strpos( $h, 'http' ) === 0 ? $h : home_url( '/' . ltrim( $h, '/' ) );
				if ( isset( $seen[ $abs ] ) ) { continue; }
				if ( count( $seen ) >= $limit ) { break 2; }
				$r  = wp_remote_head( $abs, array( 'timeout' => 6, 'redirection' => 3, 'user-agent' => 'RahmMCP-LinkCheck' ) );
				$st = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
				if ( in_array( $st, array( 403, 405, 501 ), true ) ) {
					$r  = wp_remote_get( $abs, array( 'timeout' => 6, 'redirection' => 3, 'user-agent' => 'RahmMCP-LinkCheck' ) );
					$st = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
				}
				$seen[ $abs ] = $st;
				if ( $st === 0 || $st >= 400 ) { $bad[] = array( 'link' => $abs, 'status' => $st ? $st : 'no answer', 'found_on' => get_permalink( $p ), 'page_id' => $p->ID ); }
			}
		}
		return array( 'checked' => count( $seen ), 'broken' => $bad, 'note' => 'Checked up to ' . $limit . ' unique links from the latest 20 pages.' );
	}

	/* ================= Expert scores ================= */

	public static function run( $role, $limit = 6 ) {
		$role = in_array( $role, array( 'seo', 'copywriter', 'designer', 'performance', 'marketing', 'qa' ), true ) ? $role : 'qa';
		$limit = max( 1, min( 10, $limit ) );
		$ids   = array();
		$front = (int) get_option( 'page_on_front' );
		if ( $front ) { $ids[] = $front; }
		foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => $limit, 'fields' => 'ids' ) ) as $id ) {
			if ( ! in_array( $id, $ids, true ) ) { $ids[] = $id; }
		}
		$urls = $ids ? array_map( 'get_permalink', array_slice( $ids, 0, $limit ) ) : array( home_url( '/' ) );
		if ( ! $front ) { array_unshift( $urls, home_url( '/' ) ); $urls = array_slice( array_unique( $urls ), 0, $limit ); }
		$pages = array();
		$count = array();
		foreach ( $urls as $u ) {
			$a = self::analyze( $u, in_array( $role, array( 'designer', 'marketing', 'qa' ), true ), true );
			if ( isset( $a['error'] ) ) { $pages[] = array( 'url' => $u, 'score' => 0, 'issues' => array( $a['error'] ) ); continue; }
			$issues = self::issues_for( $role, $a, url_to_postid( $u ) );
			$s      = max( 0, 100 - array_sum( array_column( $issues, 0 ) ) );
			foreach ( $issues as $x ) { $count[ $x[1] ] = ( isset( $count[ $x[1] ] ) ? $count[ $x[1] ] : 0 ) + 1; }
			$pages[] = array( 'url' => $u, 'score' => $s, 'ms' => $a['ms'], 'issues' => $issues ? array_column( $issues, 1 ) : array( 'OK' ) );
		}
		$avg = $pages ? (int) round( array_sum( array_column( $pages, 'score' ) ) / count( $pages ) ) : 0;
		arsort( $count );
		return array( 'role' => $role, 'score' => $avg, 'pages' => $pages, 'top_fixes' => array_slice( array_keys( $count ), 0, 6 ), 'basis' => 'Measured on ' . count( $pages ) . ' page(s) of this site. A senior human review can add more.' );
	}

	private static function issues_for( $role, array $a, $post_id ) {
		$m = $a['m'];
		$i = array();
		switch ( $role ) {
			case 'performance':
				foreach ( $a['issues'] as $x ) { if ( preg_match( '/Slow|faster|Heavy|Large HTML|script files|stylesheet files|lazy/i', $x[1] ) ) { $i[] = $x; } }
				if ( $m['img_no_size'] > 3 ) { $i[] = array( 5, 'Images without width/height cause layout shifts' ); }
				break;
			case 'designer':
				foreach ( $a['issues'] as $x ) { if ( preg_match( '/viewport|lang|H1|alt|mixed|PHP|HTTP/i', $x[1] ) ) { $i[] = $x; } }
				if ( $m['img_no_size'] > 3 ) { $i[] = array( 5, 'Images without width/height cause layout shifts' ); }
				if ( ! get_option( 'site_icon' ) ) { $i[] = array( 4, 'No site icon (favicon) set' ); }
				if ( ! get_theme_mod( 'custom_logo' ) ) { $i[] = array( 3, 'No logo set in the theme' ); }
				break;
			case 'seo':
				$post = $post_id ? get_post( $post_id ) : null;
				if ( $post ) { $i = RAHM_SEO::check_post( $post ); }
				foreach ( $a['issues'] as $x ) { if ( preg_match( '/noindex|HTTP|No <title>/i', $x[1] ) ) { $i[] = $x; } }
				if ( ! $m['canonical'] ) { $i[] = array( 4, 'No canonical link tag' ); }
				if ( ! $m['og'] ) { $i[] = array( 4, 'No Open Graph tags (poor link previews)' ); }
				break;
			case 'copywriter':
				$post = $post_id ? get_post( $post_id ) : null;
				$txt  = $post ? wp_strip_all_tags( $post->post_content ) : wp_strip_all_tags( preg_replace( '/<(script|style)\b.*?<\/\1>/is', '', $a['html'] ) );
				$i    = self::copy_issues( $txt, $a['html'] );
				break;
			case 'marketing':
				if ( ! $m['cta'] ) { $i[] = array( 15, 'No clear call-to-action button found' ); }
				if ( ! $m['tel'] && ! $m['mail'] && ! $m['form'] ) { $i[] = array( 15, 'No easy way to contact you (phone, WhatsApp, email or form)' ); }
				if ( ! $m['og'] ) { $i[] = array( 6, 'No Open Graph tags (poor link previews on social media)' ); }
				foreach ( $a['issues'] as $x ) { if ( preg_match( '/meta description|<title>|viewport|Slow/i', $x[1] ) ) { $i[] = $x; } }
				break;
			default:
				$i = $a['issues'];
		}
		return $i;
	}

	private static function copy_issues( $txt, $html ) {
		$i     = array();
		$words = str_word_count( $txt );
		if ( $words < 120 ) { $i[] = array( 12, "Very little text ($words words)" ); }
		$sent = preg_split( '/[.!?۔]+\s+/u', trim( $txt ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $sent ) {
			$avg = $words / max( 1, count( $sent ) );
			if ( $avg > 26 ) { $i[] = array( 10, 'Sentences are long (average ' . (int) $avg . ' words); aim for under 20' ); }
		}
		if ( $words > 400 && substr_count( strtolower( $html ), '<h2' ) < 2 ) { $i[] = array( 10, 'Long text without enough H2 sub-headings' ); }
		if ( preg_match( '/lorem ipsum|sample page|hello world!?\s|just another wordpress site/i', $txt ) ) { $i[] = array( 20, 'Placeholder text still on the page' ); }
		if ( ! preg_match( '/<(a|button)\b[^>]*>[^<]{0,40}(contact|call|buy|book|order|start|apply|enrol|register|shop|learn|رابطہ)/iu', $html ) ) { $i[] = array( 8, 'No call-to-action wording' ); }
		return $i;
	}
}
