<?php
/**
 * Direct edits (what the owner does by clicking in the Live Studio) and Elementor tree helpers.
 * Every edit makes a snapshot first, so it can be undone for 30 days.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAHM_Direct {

	const EL = '_elementor_data';

	/* ================= Elementor tree ================= */

	public static function is_elementor( $post_id ) {
		$d = get_post_meta( $post_id, self::EL, true );
		return is_string( $d ) && $d !== '' && $d !== '[]';
	}

	public static function el_get( $post_id ) {
		$d = get_post_meta( $post_id, self::EL, true );
		if ( is_array( $d ) ) {
			return $d;
		}
		$t = json_decode( (string) $d, true );
		return is_array( $t ) ? $t : array();
	}

	public static function el_save( $post_id, array $tree, $note = 'Elementor change' ) {
		$undo = RAHM_Store::snap_post( $post_id, array( self::EL ), $note );
		update_post_meta( $post_id, self::EL, wp_slash( wp_json_encode( $tree ) ) );
		wp_update_post( array( 'ID' => $post_id ) );
		self::clear_builder_cache( $post_id );
		RAHM_Ctx::$post = (int) $post_id;
		RAHM_Ctx::$undo = $undo;
		return $undo;
	}

	public static function clear_builder_cache( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		clean_post_cache( $post_id );
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	public static function new_id() {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	}

	/**
	 * Find element $id and let $fn change it.
	 * $fn( $el ) returns: the element, null (delete it), or array( '_list' => array(...) ) to replace it with several.
	 */
	public static function map_tree( array $els, $id, callable $fn, &$found ) {
		$out = array();
		foreach ( $els as $el ) {
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
				$found = true;
				$r     = $fn( $el );
				if ( $r === null ) {
					continue;
				}
				if ( isset( $r['_list'] ) ) {
					foreach ( $r['_list'] as $x ) {
						$out[] = $x;
					}
					continue;
				}
				$out[] = $r;
				continue;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::map_tree( $el['elements'], $id, $fn, $found );
			}
			$out[] = $el;
		}
		return $out;
	}

	/** Short overview of the tree for the AI (ids, types and the visible text/image of each element). */
	public static function summarize( array $els, $depth = 0, $max_depth = 6 ) {
		$out = array();
		foreach ( $els as $el ) {
			$row = array(
				'id'   => isset( $el['id'] ) ? $el['id'] : '',
				'type' => ( isset( $el['elType'] ) ? $el['elType'] : '' ) . ( ! empty( $el['widgetType'] ) ? ':' . $el['widgetType'] : '' ),
			);
			$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			foreach ( array( 'title', 'editor', 'text', 'title_text', 'description_text', 'heading', 'content' ) as $k ) {
				if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
					$row[ $k ] = mb_substr( wp_strip_all_tags( $s[ $k ] ), 0, 80 );
				}
			}
			if ( ! empty( $s['image']['url'] ) ) {
				$row['image'] = $s['image']['url'];
			}
			if ( ! empty( $s['link']['url'] ) ) {
				$row['link'] = $s['link']['url'];
			}
			if ( ! empty( $el['elements'] ) && $depth < $max_depth ) {
				$row['children'] = self::summarize( $el['elements'], $depth + 1, $max_depth );
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Replace $old text with $new inside every string of a settings array. */
	private static function replace_text( $v, $old, $new, &$n ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::replace_text( $x, $old, $new, $n );
			}
			return $v;
		}
		if ( ! is_string( $v ) || $old === '' ) {
			return $v;
		}
		$pat = self::loose_pattern( $old );
		$r   = preg_replace_callback( $pat, function () use ( $new ) { return htmlspecialchars( $new, ENT_NOQUOTES, 'UTF-8' ); }, $v, 1, $c );
		if ( $c ) {
			$n += $c;
			return $r;
		}
		return $v;
	}

	/** Swap an image (matched by file name) for a new one inside a settings array. */
	private static function replace_image( $v, $old_name, $new_url, $new_id, &$n ) {
		if ( is_array( $v ) ) {
			if ( isset( $v['url'] ) && is_string( $v['url'] ) && self::norm_img( $v['url'] ) === $old_name ) {
				$v['url'] = $new_url;
				if ( array_key_exists( 'id', $v ) ) {
					$v['id'] = $new_id ? (int) $new_id : '';
				}
				$n++;
				return $v;
			}
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::replace_image( $x, $old_name, $new_url, $new_id, $n );
			}
		}
		return $v;
	}

	/* ================= Text matching helpers ================= */

	/** File name without size suffix, so photo-300x200.jpg matches photo.jpg. */
	public static function norm_img( $url ) {
		$p = wp_parse_url( (string) $url, PHP_URL_PATH );
		$b = strtolower( basename( (string) $p ) );
		return preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $b );
	}

	/** Regex that finds visible text even when WordPress changed quotes, dashes or entities. */
	public static function loose_pattern( $text ) {
		return '/' . self::loose_body( $text ) . '/u';
	}

	/** The same pattern without delimiters. */
	public static function loose_body( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		$out  = '';
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( $ch === ' ' ) {
				$out .= '(?:\s|&nbsp;|&#160;)+';
			} elseif ( in_array( $ch, array( "'", '’', '‘' ), true ) ) {
				$out .= "(?:'|’|‘|&#8217;|&#8216;|&#039;|&#39;|&apos;)";
			} elseif ( in_array( $ch, array( '"', '“', '”' ), true ) ) {
				$out .= '(?:"|“|”|&quot;|&#8220;|&#8221;|&#034;)';
			} elseif ( in_array( $ch, array( '–', '—', '-' ), true ) ) {
				$out .= '(?:-|–|—|&#8211;|&#8212;|&ndash;|&mdash;)';
			} elseif ( $ch === '&' ) {
				$out .= '(?:&amp;|&#038;|&)';
			} elseif ( $ch === '…' ) {
				$out .= '(?:…|&#8230;|\.\.\.)';
			} else {
				$out .= preg_quote( $ch, '/' );
			}
		}
		return $out;
	}

	/* ================= The edit entry point ================= */

	/**
	 * Apply one direct edit from the Live Studio.
	 * $op: text | image | delete | insert_image
	 * $p : eid (Elementor element id), old, new, src, new_src, new_id, tag, after_html
	 */
	public static function apply( $post_id, $op, array $p ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( 'Page not found.' );
		}
		RAHM_Ctx::gate( 'live', 'edit page #' . $post_id );
		if ( self::is_elementor( $post_id ) && empty( $p['eid'] ) ) {
			throw new Exception( 'This is an Elementor page. Click the text or image itself (not empty space) so I know which element to change.' );
		}
		$msg = ( self::is_elementor( $post_id ) && ! empty( $p['eid'] ) )
			? self::apply_elementor( $post_id, $op, $p )
			: self::apply_classic( $post, $op, $p );
		$undo = RAHM_Ctx::$undo;
		RAHM_Ctx::done( 'studio', $msg, $post_id, $undo );
		return array( 'ok' => true, 'summary' => $msg, 'undo' => $undo );
	}

	private static function apply_elementor( $post_id, $op, array $p ) {
		$tree  = self::el_get( $post_id );
		$found = false;
		$n     = 0;
		switch ( $op ) {
			case 'text':
				$old = (string) ( isset( $p['old'] ) ? $p['old'] : '' );
				$new = (string) ( isset( $p['new'] ) ? $p['new'] : '' );
				$tree = self::map_tree( $tree, $p['eid'], function ( $el ) use ( $old, $new, &$n ) {
					$el['settings'] = self::replace_text( isset( $el['settings'] ) ? $el['settings'] : array(), $old, $new, $n );
					return $el;
				}, $found );
				if ( ! $found ) { throw new Exception( 'That element was not found in the Elementor data. Reload the preview and try again.' ); }
				if ( ! $n ) { throw new Exception( 'I could not find that text inside the selected Elementor element. Select the text widget itself, or ask the AI to change it.' ); }
				$msg = 'Changed text in Elementor element ' . $p['eid'];
				break;
			case 'image':
				$name = self::norm_img( isset( $p['src'] ) ? $p['src'] : '' );
				$tree = self::map_tree( $tree, $p['eid'], function ( $el ) use ( $name, $p, &$n ) {
					$el['settings'] = self::replace_image( isset( $el['settings'] ) ? $el['settings'] : array(), $name, $p['new_src'], isset( $p['new_id'] ) ? $p['new_id'] : 0, $n );
					return $el;
				}, $found );
				if ( ! $found ) { throw new Exception( 'That element was not found in the Elementor data. Reload the preview and try again.' ); }
				if ( ! $n ) { throw new Exception( 'I could not find that image inside the selected element. Select the image widget or its container.' ); }
				$msg = 'Replaced an image in Elementor element ' . $p['eid'];
				break;
			case 'delete':
				$tree = self::map_tree( $tree, $p['eid'], function () { return null; }, $found );
				if ( ! $found ) { throw new Exception( 'That element was not found in the Elementor data.' ); }
				$msg = 'Deleted Elementor element ' . $p['eid'];
				break;
			case 'insert_image':
				$w    = array(
					'id'         => self::new_id(),
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array( 'image' => array( 'url' => $p['new_src'], 'id' => isset( $p['new_id'] ) ? (int) $p['new_id'] : '' ) ),
					'elements'   => array(),
				);
				$tree = self::map_tree( $tree, $p['eid'], function ( $el ) use ( $w ) { return array( '_list' => array( $el, $w ) ); }, $found );
				if ( ! $found ) { throw new Exception( 'That element was not found in the Elementor data.' ); }
				$msg = 'Added an image after Elementor element ' . $p['eid'];
				break;
			default:
				throw new Exception( 'Unknown edit.' );
		}
		self::el_save( $post_id, $tree, $msg );
		return $msg;
	}

	private static function apply_classic( WP_Post $post, $op, array $p ) {
		$c   = $post->post_content;
		$new = $c;
		$msg = '';
		switch ( $op ) {
			case 'text':
				$old = trim( (string) ( isset( $p['old'] ) ? $p['old'] : '' ) );
				$txt = (string) ( isset( $p['new'] ) ? $p['new'] : '' );
				if ( $old === '' ) { throw new Exception( 'Nothing selected.' ); }
				if ( trim( $post->post_title ) === $old ) {
					$undo = RAHM_Store::snap_post( $post->ID, array(), 'Title change' );
					wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_title' => $txt ) ) );
					RAHM_Ctx::$undo = $undo;
					return 'Changed the page title';
				}
				$pat = self::loose_pattern( $old );
				$cnt = preg_match_all( $pat, $c );
				if ( $cnt === 0 ) { throw new Exception( 'This text is not part of the page content. It probably belongs to the theme (header, footer or a widget). Use the Code tab, or ask the AI.' ); }
				if ( $cnt > 1 ) { throw new Exception( 'This text appears ' . $cnt . ' times in the page, so I cannot tell which one you mean. Select a longer piece of text.' ); }
				$new = preg_replace_callback( $pat, function () use ( $txt ) { return htmlspecialchars( $txt, ENT_NOQUOTES, 'UTF-8' ); }, $c, 1 );
				$msg = 'Changed text on the page';
				break;
			case 'image':
				$name = self::norm_img( isset( $p['src'] ) ? $p['src'] : '' );
				$n    = 0;
				$new  = preg_replace_callback( '/https?:\/\/[^"\'\s)>]+\.(?:jpe?g|png|gif|webp|svg|avif)/i', function ( $m ) use ( $name, $p, &$n ) {
					if ( self::norm_img( $m[0] ) === $name ) { $n++; return $p['new_src']; }
					return $m[0];
				}, $c );
				if ( ! $n ) { throw new Exception( 'This image is not part of the page content (it may come from the theme or a widget).' ); }
				$msg = 'Replaced an image on the page';
				break;
			case 'delete':
				$new = self::classic_delete( $c, $p );
				if ( $new === $c ) { throw new Exception( 'I could not find that part in the page content to remove it. It may belong to the theme. Use the Code tab, or ask the AI.' ); }
				$msg = 'Deleted an element from the page';
				break;
			case 'insert_image':
				$img    = "<!-- wp:image -->\n" . '<figure class="wp-block-image"><img src="' . esc_url( $p['new_src'] ) . '" alt="" /></figure>' . "\n<!-- /wp:image -->";
				$anchor = self::classic_anchor( $c, $p );
				$new    = $anchor ? substr( $c, 0, $anchor ) . "\n" . $img . substr( $c, $anchor ) : $c . "\n" . $img;
				$msg    = 'Added an image to the page';
				break;
			default:
				throw new Exception( 'Unknown edit.' );
		}
		$undo = RAHM_Store::snap_post( $post->ID, array(), $msg );
		wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ) );
		RAHM_Ctx::$undo = $undo;
		RAHM_Ctx::$post = $post->ID;
		return $msg;
	}

	/** Remove an element from stored content, including its empty block comment wrapper. */
	private static function classic_delete( $c, array $p ) {
		$tag = isset( $p['tag'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', $p['tag'] ) ) : '';
		if ( $tag === 'img' && ! empty( $p['src'] ) ) {
			$name = preg_quote( pathinfo( self::norm_img( $p['src'] ), PATHINFO_FILENAME ), '/' );
			$c2   = preg_replace( '/<figure\b[^>]*>\s*<img\b[^>]*' . $name . '[^>]*>\s*(?:<figcaption.*?<\/figcaption>\s*)?<\/figure>/is', '', $c, 1, $n );
			if ( ! $n ) {
				$c2 = preg_replace( '/<img\b[^>]*' . $name . '[^>]*>/i', '', $c, 1 );
			}
			return self::tidy_blocks( $c2 );
		}
		$old = trim( (string) ( isset( $p['old'] ) ? $p['old'] : '' ) );
		if ( $tag && $old !== '' ) {
			$pat = '/<' . $tag . '\b[^>]*>\s*' . self::loose_body( $old ) . '\s*<\/' . $tag . '>/iu';
			return self::tidy_blocks( preg_replace( $pat, '', $c, 1 ) );
		}
		return $c;
	}

	private static function tidy_blocks( $c ) {
		return preg_replace( '/<!--\s*wp:([a-z0-9\/-]+)[^>]*?-->\s*<!--\s*\/wp:\1\s*-->\s*/i', '', $c );
	}

	/** Position just after the element that matches the selection (or 0). */
	private static function classic_anchor( $c, array $p ) {
		$tag = isset( $p['tag'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', $p['tag'] ) ) : '';
		$old = trim( (string) ( isset( $p['old'] ) ? $p['old'] : '' ) );
		if ( $tag && $old !== '' ) {
			$pat = '/<' . $tag . '\b[^>]*>\s*' . self::loose_body( $old ) . '\s*<\/' . $tag . '>/iu';
			if ( preg_match( $pat, $c, $m, PREG_OFFSET_CAPTURE ) ) {
				$end = $m[0][1] + strlen( $m[0][0] );
				// If the element sits inside a block comment pair, insert after the closing comment.
				if ( preg_match( '/\G\s*<!--\s*\/wp:[a-z0-9\/-]+\s*-->/', $c, $q, 0, $end ) ) {
					$end += strlen( $q[0] );
				}
				return $end;
			}
		}
		return 0;
	}
}
