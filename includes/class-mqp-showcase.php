<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Our previous orders": Facebook posts featuring orders, shown on the website with
 * [mq_previous_orders]: a whole section (heading, intro, the post cards and a button to the
 * Facebook page). Managed in wp-admin → MQ Portal → Previous orders (not in the portal):
 * Posts (manage, sort, edit), Add posts, Settings.
 *
 * Paste the post links (any Facebook post address, several at once). The plugin reads each post's
 * picture and caption from Facebook's link preview and keeps a copy of the picture on this site,
 * so the section loads fast and doesn't load Facebook (or its tracking) for visitors. Facebook
 * only gives the start of a caption; paste the full caption in Edit to show all of it.
 * When Facebook won't show a post to the server (it sometimes answers web hosts with a login
 * page), the link is kept, switched off, to add the picture and caption by hand.
 * style="facebook" shows Facebook's own post cards instead (full caption, likes; loads Facebook).
 */
class MQP_Showcase {

	const OPT  = 'mqp_showcase';
	const SLUG = 'mq-portal-showcase';
	const MAX  = 100;
	const TABS = array(
		'posts'    => 'Posts',
		'add'      => '+ Add posts',
		'settings' => 'Settings',
	);

	public static function init() {
		add_shortcode( 'mq_previous_orders', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_mqp_showcase', array( __CLASS__, 'handle' ) );
	}

	public static function defaults() {
		return array(
			'items'    => array(), // [ id, url, post, image, caption, page, on, added, needs ]
			'heading'  => 'Our Previous Orders',
			'intro'    => 'A few of the orders we printed for our customers. There are many more on our Facebook page.',
			'page_url' => '',      // "See more on Facebook" button; empty: the page of the first post.
			'button'   => 'See more on our Facebook page',
			'limit'    => 9,
			'style'    => 'cards', // cards | facebook
		);
	}

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
	}

	/** Saves the settings; with $items_only, only the posts (unsaved settings keep their defaults). */
	private static function save( array $o, $items_only = false ) {
		$keep = $items_only ? array_merge( (array) get_option( self::OPT, array() ), array( 'items' => array_values( $o['items'] ) ) ) : $o;
		update_option( self::OPT, array_intersect_key( $keep, self::defaults() ), false );
		mqp_purge_page_cache();
	}

	/* ---------- Reading a Facebook post ---------- */

	/** Facebook addresses only (the links are fetched by the server). */
	public static function is_facebook( $url ) {
		$p = wp_parse_url( $url );
		return $p && 'https' === ( $p['scheme'] ?? '' ) && preg_match( '/(^|\.)(facebook\.com|fb\.com|fb\.watch)$/i', $p['host'] ?? '' );
	}

	/** Every Facebook link in pasted text: one per line, with spaces or commas, or inside other text. */
	public static function links( $text ) {
		preg_match_all( '#https?://[^\s,<>"\']+#i', (string) $text, $m );
		$out = array();
		foreach ( $m[0] as $u ) {
			$u = preg_replace( '/#.*$/', '', rtrim( $u, '.);]' ) ); // "#" and what follows mean nothing to Facebook.
			$u = preg_replace( '#^http://#i', 'https://', $u );
			$u = preg_replace( '#^https://(m|web|mbasic|touch)\.facebook\.com/#i', 'https://www.facebook.com/', $u );
			if ( self::is_facebook( $u ) ) {
				$out[] = esc_url_raw( $u );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Reads the post's address, picture, caption and page from Facebook's link preview.
	 * Returns [ post, image(url on this site), caption, page ] or WP_Error (with what Facebook answered).
	 */
	public static function fetch( $url ) {
		if ( ! self::is_facebook( $url ) ) {
			return new WP_Error( 'mqp_fb', 'That is not a Facebook link.' );
		}
		// Facebook gives the link preview (picture and caption) to link-preview readers; some web
		// hosts get a login page for one and the preview for another, so a few are tried.
		$agents = array(
			'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
			'facebookexternalhit/1.1',
			'Mozilla/5.0 (compatible; Twitterbot/1.0)',
			'WhatsApp/2.23.20.0',
		);
		$why = '';
		foreach ( $agents as $agent ) {
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 5,
					'user-agent'  => $agent,
					'headers'     => array( 'Accept-Language' => 'en-US,en;q=0.8', 'Accept' => 'text/html' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				$why = 'the connection failed (' . $res->get_error_message() . ')';
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$html = (string) wp_remote_retrieve_body( $res );
			$got  = self::parse( $html );
			if ( $got ) {
				return $got;
			}
			if ( 200 !== $code ) {
				$why = 'Facebook answered with error ' . $code;
			} elseif ( preg_match( '/<title>([^<]*)<\/title>/i', $html, $t ) && preg_match( '/log ?in|sign ?up|security check/i', $t[1] ) ) {
				$why = 'Facebook asked the website\'s server to log in ("' . trim( html_entity_decode( $t[1] ) ) . '")';
			} else {
				$why = 'Facebook gave no preview of this post';
			}
		}
		return new WP_Error( 'mqp_fb', $why . '. The post may still be public: Facebook sometimes refuses web hosts.' );
	}

	/** The preview in Facebook's page, or null. */
	private static function parse( $html ) {
		$meta = function ( $key ) use ( $html ) {
			if ( preg_match( '/<meta[^>]+(?:property|name)="' . preg_quote( $key, '/' ) . '"[^>]+content="([^"]*)"/i', $html, $m ) ) {
				return html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			return '';
		};
		$post  = $meta( 'og:url' );
		$image = $meta( 'og:image' );
		$text  = $meta( 'og:description' );
		$text  = '' !== $text ? $text : $meta( 'description' );
		if ( ! $post || ! self::is_facebook( $post ) || ( ! $image && ! $text ) || preg_match( '#/login|/checkpoint#', $post ) ) {
			return null;
		}
		$page = '';
		if ( preg_match( '#^https://(?:www\.|m\.)?facebook\.com/([A-Za-z0-9.\-]+)/(?:posts|videos|photos|reel)/#', $post, $m ) && ! in_array( strtolower( $m[1] ), array( 'share', 'story.php', 'permalink.php', 'watch' ), true ) ) {
			$page = 'https://www.facebook.com/' . $m[1];
		}
		return array(
			'post'    => esc_url_raw( $post ),
			'image'   => $image ? self::keep_image( $image ) : '',
			'caption' => sanitize_textarea_field( $text ),
			'page'    => $page,
		);
	}

	/** Facebook's picture links expire: keep a copy here. */
	private static function keep_image( $src ) {
		$host = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( ! preg_match( '/(^|\.)(fbcdn\.net|fbsbx\.com|facebook\.com)$/i', $host ) ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Facebook's "lookaside" picture links only answer link-preview readers.
		$tmp = wp_tempnam( 'mqp-fb.jpg' );
		$res = wp_safe_remote_get(
			$src,
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'user-agent'  => 'facebookexternalhit/1.1',
				'stream'      => true,
				'filename'    => $tmp,
				'limit_response_size' => 15 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			wp_delete_file( $tmp );
			return '';
		}
		$out = self::save_image( $tmp );
		wp_delete_file( $tmp );
		return is_wp_error( $out ) ? '' : $out;
	}

	/** A picture file → a small copy in uploads/mq-showcase (800px at most). URL or WP_Error. */
	private static function save_image( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return new WP_Error( 'mqp_fb', 'The picture must be a JPG, PNG or WebP.' );
		}
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'mq-showcase';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mqp_fb', 'The picture could not be saved.' );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'mqp_fb', 'The picture could not be read.' );
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$editor->resize( 800, 800, false );
		$editor->set_quality( 80 );
		$saved = $editor->save( $dir . '/' . wp_generate_password( 16, false, false ) . ( $webp ? '.webp' : '.jpg' ), $webp ? 'image/webp' : 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'mqp_fb', 'The picture could not be saved.' );
		}
		MQP_Guard::add_bytes( (int) @filesize( $saved['path'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return trailingslashit( $up['baseurl'] ) . 'mq-showcase/' . basename( $saved['path'] );
	}

	private static function delete_image( $image ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['baseurl'] ) . 'mq-showcase/';
		if ( $image && 0 === strpos( $image, $base ) ) {
			$file = trailingslashit( $up['basedir'] ) . 'mq-showcase/' . basename( $image );
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/** A picture sent with the form (Edit or Add by hand): URL, '' (none sent) or WP_Error. */
	private static function uploaded_image( $field ) {
		$f = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $f || UPLOAD_ERR_NO_FILE === (int) $f['error'] ) {
			return '';
		}
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'mqp_fb', 'The picture was not uploaded (too large, or the connection stopped).' );
		}
		if ( (int) $f['size'] > 25 * MB_IN_BYTES ) {
			return new WP_Error( 'mqp_fb', 'The picture is larger than 25 MB.' );
		}
		return self::save_image( $f['tmp_name'] );
	}

	/* ---------- wp-admin → MQ Portal → Previous orders ---------- */

	public static function menu() {
		$hook = add_submenu_page( 'mq-portal', 'Our previous orders', 'Previous orders', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function find( $o, $id ) {
		foreach ( $o['items'] as $i => $it ) {
			if ( $it['id'] === $id ) {
				return $i;
			}
		}
		return null;
	}

	private static function fail( $msgs ) {
		set_transient( 'mqp_showcase_err_' . get_current_user_id(), (array) $msgs, 300 );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		check_admin_referer( 'mqp_showcase' );
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$o    = self::options();
		$op   = sanitize_key( (string) ( $_POST['op'] ?? '' ) );
		$id   = sanitize_key( (string) ( $_POST['id'] ?? '' ) );
		$back = array();

		switch ( $op ) {
			case 'add':
				$links = self::links( wp_unslash( (string) ( $_POST['links'] ?? '' ) ) );
				$have  = array_merge( wp_list_pluck( $o['items'], 'post' ), wp_list_pluck( $o['items'], 'url' ) );
				$added = 0;
				$kept  = 0;
				$bad   = array();
				if ( ! $links ) {
					self::fail( 'No Facebook links found in what you pasted.' );
					$back = array( 'tab' => 'add' );
					break;
				}
				foreach ( $links as $link ) {
					if ( count( $o['items'] ) >= self::MAX ) {
						$bad[] = 'Only ' . self::MAX . ' posts can be kept. Remove some first.';
						break;
					}
					if ( in_array( $link, $have, true ) ) {
						$bad[] = $link . ': already in the list.';
						continue;
					}
					$got = self::fetch( $link );
					if ( ! is_wp_error( $got ) && in_array( $got['post'], $have, true ) ) {
						self::delete_image( $got['image'] );
						$bad[] = $link . ': already in the list.';
						continue;
					}
					$new = array( 'id' => strtolower( wp_generate_password( 8, false, false ) ), 'url' => $link, 'added' => mqp_now() );
					if ( is_wp_error( $got ) ) {
						// Kept, switched off, to finish by hand.
						$new += array( 'post' => $link, 'image' => '', 'caption' => '', 'page' => '', 'on' => 0, 'needs' => 1 );
						$bad[] = $link . ': ' . $got->get_error_message() . ' It was kept (switched off): open Edit to add the picture and caption.';
						$kept++;
					} else {
						// Without a picture it waits (switched off) for one to be added in Edit.
						$new += $got + array( 'on' => $got['image'] ? 1 : 0, 'needs' => $got['image'] ? 0 : 1 );
						if ( $got['image'] ) {
							$added++;
						} else {
							$bad[] = $link . ': the caption was read, but the picture could not be saved. It was kept (switched off): open Edit to add the picture.';
							$kept++;
						}
					}
					$have[]       = $new['post'];
					$have[]       = $link;
					$o['items'][] = $new;
				}
				self::save( $o, true );
				if ( $bad ) {
					self::fail( $bad );
				}
				MQP_Monitor::log( 'system', "Added $added Facebook post(s) to Previous orders" . ( $kept ? " ($kept to finish by hand)" : '' ) );
				$back = array( 'done' => 'added', 'n' => $added, 'k' => $kept );
				break;

			case 'manual':
				// By hand: the link, a picture and the caption.
				$links = self::links( wp_unslash( (string) ( $_POST['link'] ?? '' ) ) );
				$img   = self::uploaded_image( 'picture' );
				if ( ! $links || is_wp_error( $img ) || ! $img ) {
					if ( $img && ! is_wp_error( $img ) ) {
						self::delete_image( $img );
					}
					self::fail( ! $links ? 'Paste the Facebook link of the post.' : ( is_wp_error( $img ) ? $img->get_error_message() : 'Choose the picture of the post.' ) );
					$back = array( 'tab' => 'add' );
					break;
				}
				$o['items'][] = array(
					'id'      => strtolower( wp_generate_password( 8, false, false ) ),
					'url'     => $links[0],
					'post'    => $links[0],
					'image'   => $img,
					'caption' => mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $_POST['caption'] ?? '' ) ) ), 0, 5000 ),
					'page'    => '',
					'on'      => 1,
					'needs'   => 0,
					'added'   => mqp_now(),
				);
				self::save( $o, true );
				MQP_Monitor::log( 'system', 'Added a Facebook post by hand to Previous orders' );
				$back = array( 'done' => 'added', 'n' => 1 );
				break;

			case 'edit':
				$at = self::find( $o, $id );
				if ( null === $at ) {
					break;
				}
				$it = $o['items'][ $at ];
				$do = sanitize_key( (string) ( $_POST['do'] ?? 'save' ) );
				if ( 'remove' === $do ) {
					self::delete_image( $it['image'] );
					unset( $o['items'][ $at ] );
					self::save( $o, true );
					$back = array( 'done' => 'removed' );
					break;
				}
				if ( 'refresh' === $do ) {
					$got = self::fetch( $it['url'] );
					if ( is_wp_error( $got ) ) {
						self::fail( $got->get_error_message() );
						$back = array( 'tab' => 'edit', 'id' => $id );
						break;
					}
					if ( $got['image'] ) {
						self::delete_image( $it['image'] );
						$it['image'] = $got['image'];
					}
					$it['post']  = $got['post'];
					$it['page']  = $got['page'];
					$it['needs'] = 0;
					if ( '' === trim( (string) $it['caption'] ) || ! empty( $_POST['caption_too'] ) ) {
						$it['caption'] = $got['caption'];
					}
					$o['items'][ $at ] = $it;
					self::save( $o, true );
					$back = array( 'tab' => 'edit', 'id' => $id, 'done' => 'refreshed' );
					break;
				}
				$img = self::uploaded_image( 'picture' );
				if ( is_wp_error( $img ) ) {
					self::fail( $img->get_error_message() );
					$back = array( 'tab' => 'edit', 'id' => $id );
					break;
				}
				if ( $img ) {
					self::delete_image( $it['image'] );
					$it['image'] = $img;
				}
				$link = self::links( wp_unslash( (string) ( $_POST['link'] ?? '' ) ) );
				if ( $link && $link[0] !== $it['post'] ) {
					$it['post'] = $link[0];
				}
				$it['caption']     = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $_POST['caption'] ?? '' ) ) ), 0, 5000 );
				$it['on']          = empty( $_POST['on'] ) ? 0 : 1;
				$it['needs']       = $it['image'] ? 0 : 1;
				$o['items'][ $at ] = $it;
				self::save( $o, true );
				$back = array( 'done' => 'saved' );
				break;

			case 'bulk':
				$ids  = array_map( 'sanitize_key', (array) ( $_POST['ids'] ?? array() ) );
				$what = sanitize_key( (string) ( $_POST['bulk'] ?? '' ) );
				$n    = 0;
				foreach ( $o['items'] as $i => $it ) {
					if ( ! in_array( $it['id'], $ids, true ) ) {
						continue;
					}
					if ( 'delete' === $what ) {
						self::delete_image( $it['image'] );
						unset( $o['items'][ $i ] );
					} elseif ( 'show' === $what || 'hide' === $what ) {
						$o['items'][ $i ]['on'] = 'show' === $what ? 1 : 0;
					} else {
						continue;
					}
					$n++;
				}
				self::save( $o, true );
				$back = array( 'done' => 'bulk_' . $what, 'n' => $n );
				break;

			case 'order':
				$want = array_map( 'sanitize_key', explode( ',', (string) ( $_POST['order'] ?? '' ) ) );
				$by   = array();
				foreach ( $o['items'] as $it ) {
					$by[ $it['id'] ] = $it;
				}
				$new = array();
				foreach ( $want as $wid ) {
					if ( isset( $by[ $wid ] ) ) {
						$new[] = $by[ $wid ];
						unset( $by[ $wid ] );
					}
				}
				$o['items'] = array_merge( $new, array_values( $by ) );
				self::save( $o, true );
				$back = array( 'done' => 'moved' );
				break;

			case 'settings':
				$page          = esc_url_raw( trim( (string) wp_unslash( $_POST['page_url'] ?? '' ) ) );
				$o['page_url'] = '' === $page || self::is_facebook( $page ) ? $page : $o['page_url'];
				$o['heading']  = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['heading'] ?? '' ) ) ), 0, 120 );
				$o['intro']    = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $_POST['intro'] ?? '' ) ) ), 0, 400 );
				$o['button']   = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['button'] ?? '' ) ) ), 0, 60 );
				$o['limit']    = max( 1, min( self::MAX, (int) ( $_POST['limit'] ?? 9 ) ) );
				$o['style']    = 'facebook' === ( $_POST['style'] ?? '' ) ? 'facebook' : 'cards';
				self::save( $o );
				MQP_Monitor::log( 'system', 'Saved the Previous orders settings' );
				$back = array( 'tab' => 'settings', 'done' => 'settings' );
				break;
		}
		wp_safe_redirect( self::url( $back ) );
		exit;
	}

	private static function form( $op, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'mqp_showcase' );
		echo '<input type="hidden" name="action" value="mqp_showcase"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	}

	public static function render() {
		$o     = self::options();
		$tab   = sanitize_key( (string) ( $_GET['tab'] ?? 'posts' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab   = isset( self::TABS[ $tab ] ) || 'edit' === $tab ? $tab : 'posts';
		$done  = sanitize_key( (string) ( $_GET['done'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$n     = (int) ( $_GET['n'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$k     = (int) ( $_GET['k'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$needs = count( array_filter( wp_list_pluck( $o['items'], 'needs' ) ) );
		$msg   = array(
			'added'       => sprintf( _n( '%d post added.', '%d posts added.', $n ), $n ) . ( $k ? sprintf( ' %d kept to finish by hand (see below).', $k ) : '' ),
			'removed'     => 'Post removed.',
			'moved'       => 'Order saved.',
			'saved'       => 'Saved.',
			'refreshed'   => 'Picture and link read again from Facebook.',
			'settings'    => 'Settings saved.',
			'bulk_show'   => sprintf( '%d shown on the website.', $n ),
			'bulk_hide'   => sprintf( '%d hidden.', $n ),
			'bulk_delete' => sprintf( '%d removed.', $n ),
		);
		echo '<div class="wrap mqp-wrap mqp-po"><h1>Our previous orders</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$count = 'posts' === $key ? ' <span class="mqp-po-tabcount">' . count( $o['items'] ) . '</span>' : '';
			echo '<a class="nav-tab' . ( $tab === $key || ( 'edit' === $tab && 'posts' === $key ) ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . $count . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav>';
		if ( isset( $msg[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg[ $done ] ) . '</p></div>';
		}
		$errs = get_transient( 'mqp_showcase_err_' . get_current_user_id() );
		if ( $errs ) {
			delete_transient( 'mqp_showcase_err_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><ul class="mqp-po-errs">';
			foreach ( (array) $errs as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( 'add' === $tab ) {
			self::tab_add();
		} elseif ( 'settings' === $tab ) {
			self::tab_settings( $o );
		} elseif ( 'edit' === $tab ) {
			self::tab_edit( $o, sanitize_key( (string) ( $_GET['id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::tab_posts( $o, $needs );
		}
		echo '</div>';
	}

	private static function tab_posts( $o, $needs ) {
		$on = count( array_filter( wp_list_pluck( $o['items'], 'on' ) ) );
		echo '<p class="description">Shown on the website with <code>[mq_previous_orders]</code>: the first ' . (int) $o['limit'] . ' posts that are switched on (change the number in Settings). ' . (int) $on . ' of ' . count( $o['items'] ) . ' are switched on.</p>';
		if ( $needs ) {
			echo '<div class="notice notice-info inline"><p>' . (int) $needs . ' post' . ( 1 === $needs ? '' : 's' ) . ' could not be read from Facebook: open <strong>Edit</strong> to add the picture and caption (or try <strong>Read from Facebook</strong> again later).</p></div>';
		}
		if ( ! $o['items'] ) {
			echo '<div class="mqp-card"><p>No posts yet. <a class="button button-primary" href="' . esc_url( self::url( array( 'tab' => 'add' ) ) ) . '">+ Add posts</a></p></div>';
			return;
		}
		self::form( 'bulk', ' id="mqp-po-bulk" onsubmit="var s=this.bulk.value;if(!s){alert(\'Choose what to do with the ticked posts.\');return false;}return s!==\'delete\'||confirm(\'Remove the ticked posts?\');"' );
		echo '<div class="mqp-po-bar"><label><input type="checkbox" id="mqp-po-all"> Select all</label><select name="bulk"><option value="">With the ticked posts…</option><option value="show">Show on the website</option><option value="hide">Hide</option><option value="delete">Remove</option></select><button class="button">Apply</button><span class="description">Drag the posts to change the order.</span></div></form>';
		self::form( 'order', ' id="mqp-po-order"' );
		echo '<input type="hidden" name="order" value=""><div class="mqp-po-grid" id="mqp-po-grid">';
		foreach ( array_values( $o['items'] ) as $i => $it ) {
			$cls = ( $it['on'] ? '' : ' is-off' ) . ( ! empty( $it['needs'] ) ? ' is-needs' : '' );
			echo '<div class="mqp-po-tile' . esc_attr( $cls ) . '" draggable="true" data-id="' . esc_attr( $it['id'] ) . '">';
			echo '<label class="mqp-po-pick"><input type="checkbox" name="ids[]" value="' . esc_attr( $it['id'] ) . '" form="mqp-po-bulk" aria-label="Select this post"></label>';
			echo '<span class="mqp-po-num">' . (int) ( $i + 1 ) . '</span>';
			echo $it['image'] ? '<img src="' . esc_url( $it['image'] ) . '" alt="" loading="lazy" draggable="false">' : '<div class="mqp-po-noimg">No picture yet</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="mqp-po-tags">' . ( ! empty( $it['needs'] ) ? '<span class="is-needs">Needs a picture</span>' : '' ) . ( $it['on'] ? ( $i < $o['limit'] ? '<span class="is-on">On the website</span>' : '<span>On, not in the first ' . (int) $o['limit'] . '</span>' ) : '<span>Hidden</span>' ) . '</div>';
			echo '<p class="mqp-po-snip">' . esc_html( wp_trim_words( (string) $it['caption'], 16 ) ?: '(no caption)' ) . '</p>';
			echo '<p class="mqp-po-acts"><a class="button button-small" href="' . esc_url( self::url( array( 'tab' => 'edit', 'id' => $it['id'] ) ) ) . '">Edit</a> <a class="button button-small" href="' . esc_url( $it['post'] ) . '" target="_blank" rel="noopener">Facebook ↗</a></p>';
			echo '</div>';
		}
		echo '</div><p class="mqp-po-save"><button class="button button-primary">Save order</button></p></form>';
		?>
		<script>
		(function () {
			var grid = document.getElementById('mqp-po-grid'), drag = null;
			if (!grid) return;
			grid.addEventListener('dragstart', function (e) { drag = e.target.closest('.mqp-po-tile'); if (drag) { drag.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; } });
			grid.addEventListener('dragend', function () { if (drag) drag.classList.remove('is-drag'); drag = null; });
			grid.addEventListener('dragover', function (e) {
				e.preventDefault();
				var t = e.target.closest('.mqp-po-tile');
				if (!drag || !t || t === drag) return;
				var r = t.getBoundingClientRect();
				grid.insertBefore(drag, (e.clientX - r.left) > r.width / 2 ? t.nextSibling : t);
			});
			document.getElementById('mqp-po-order').addEventListener('submit', function () {
				this.order.value = [].map.call(grid.querySelectorAll('.mqp-po-tile'), function (t) { return t.dataset.id; }).join(',');
			});
			document.getElementById('mqp-po-all').addEventListener('change', function () {
				var on = this.checked;
				grid.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = on; });
			});
		})();
		</script>
		<?php
		self::help();
	}

	private static function tab_add() {
		echo '<div class="mqp-po-top"><div class="mqp-card"><h2>Paste Facebook links</h2>';
		self::form( 'add', ' onsubmit="var b=this.querySelector(\'button\');b.disabled=true;b.textContent=\'Reading the posts from Facebook…\'"' );
		echo '<p>Paste one or many links: one per line, with spaces or commas between them, or a whole message with links in it. Any post address works (Share → Copy link, or the address bar). The post must be public.</p>';
		echo '<textarea name="links" rows="8" class="large-text code" placeholder="https://www.facebook.com/share/p/…&#10;https://www.facebook.com/share/p/…" required></textarea>';
		echo '<p><button class="button button-primary">Add posts</button></p>';
		echo '<p class="description">The picture and the start of the caption are read from Facebook. If Facebook won\'t show a post to the website, it is still kept (switched off) so you can add its picture and caption in Edit.</p></form></div>';

		echo '<div class="mqp-card"><h2>Add one by hand</h2>';
		self::form( 'manual', ' enctype="multipart/form-data"' );
		echo '<p class="description">When Facebook can\'t be read: save the post\'s picture from Facebook, then add it here.</p>';
		echo '<p><label>Facebook link<br><input type="url" name="link" class="large-text" required placeholder="https://www.facebook.com/…"></label></p>';
		echo '<p><label>Picture<br><input type="file" name="picture" accept="image/jpeg,image/png,image/webp" required></label></p>';
		echo '<p><label>Caption<br><textarea name="caption" rows="5" class="large-text"></textarea></label></p>';
		echo '<p><button class="button">Add post</button></p></form></div></div>';
	}

	private static function tab_edit( $o, $id ) {
		$at = self::find( $o, $id );
		if ( null === $at ) {
			echo '<div class="mqp-card"><p>That post is no longer in the list. <a href="' . esc_url( self::url() ) . '">Back to the posts</a></p></div>';
			return;
		}
		$it = $o['items'][ $at ];
		echo '<p><a href="' . esc_url( self::url() ) . '">← Back to the posts</a></p>';
		echo '<div class="mqp-po-edit"><div class="mqp-card">';
		echo $it['image'] ? '<img src="' . esc_url( $it['image'] ) . '" alt="" class="mqp-po-big">' : '<div class="mqp-po-noimg mqp-po-big">No picture yet</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::form( 'edit' );
		echo '<input type="hidden" name="id" value="' . esc_attr( $it['id'] ) . '">';
		echo '<p><button class="button" name="do" value="refresh">Read from Facebook again</button></p>';
		echo '<p><label><input type="checkbox" name="caption_too" value="1"> Also replace the caption with Facebook\'s</label></p>';
		echo '<p><button class="button button-link-delete" name="do" value="remove" onclick="return confirm(\'Remove this post?\')">Remove this post</button></p>';
		echo '</form></div><div class="mqp-card">';
		self::form( 'edit', ' enctype="multipart/form-data"' );
		echo '<input type="hidden" name="id" value="' . esc_attr( $it['id'] ) . '"><input type="hidden" name="do" value="save">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>Show</th><td><label><input type="checkbox" name="on" value="1"' . checked( $it['on'], 1, false ) . '> Show on the website</label></td></tr>';
		echo '<tr><th><label for="po-cap">Caption</label></th><td><textarea id="po-cap" name="caption" rows="10" class="large-text">' . esc_textarea( $it['caption'] ) . '</textarea>';
		if ( preg_match( '/(\.\.\.|…)$/u', trim( (string) $it['caption'] ) ) ) {
			echo '<p class="description">Facebook only gives the start of the caption. Paste the full caption here to show all of it.</p>';
		}
		echo '</td></tr>';
		echo '<tr><th><label for="po-pic">' . ( $it['image'] ? 'Change the picture' : 'Picture' ) . '</label></th><td><input id="po-pic" type="file" name="picture" accept="image/jpeg,image/png,image/webp"><p class="description">Made small for the web (800px). Leave empty to keep the current one.</p></td></tr>';
		echo '<tr><th><label for="po-link">Facebook link</label></th><td><input id="po-link" type="url" name="link" class="large-text" value="' . esc_attr( $it['post'] ) . '"><p class="description"><a href="' . esc_url( $it['post'] ) . '" target="_blank" rel="noopener">Open on Facebook ↗</a></p></td></tr>';
		echo '</table><p><button class="button button-primary">Save</button></p></form></div></div>';
	}

	private static function tab_settings( $o ) {
		echo '<div class="mqp-card">';
		self::form( 'settings' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="po-heading">Heading</label></th><td><input id="po-heading" name="heading" class="regular-text" value="' . esc_attr( $o['heading'] ) . '"><p class="description">Empty: no heading (when the page has its own).</p></td></tr>';
		echo '<tr><th><label for="po-intro">Text under the heading</label></th><td><textarea id="po-intro" name="intro" rows="2" class="large-text">' . esc_textarea( $o['intro'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="po-limit">Show</label></th><td><input id="po-limit" type="number" min="1" max="' . (int) self::MAX . '" name="limit" value="' . (int) $o['limit'] . '" class="small-text"> posts (the first ones in the list that are switched on)</td></tr>';
		echo '<tr><th>Look</th><td><label><input type="radio" name="style" value="cards"' . checked( 'cards', $o['style'], false ) . '> Our own cards: picture and caption, fast, no Facebook tracking (recommended)</label><br><label><input type="radio" name="style" value="facebook"' . checked( 'facebook', $o['style'], false ) . '> Facebook\'s post cards: the full caption and likes, but slower and it loads Facebook on the page</label></td></tr>';
		echo '<tr><th><label for="po-page">Facebook page</label></th><td><input id="po-page" type="url" name="page_url" class="regular-text" value="' . esc_attr( $o['page_url'] ) . '" placeholder="' . esc_attr( self::page_url( $o ) ?: 'https://www.facebook.com/yourpage' ) . '"><p class="description">For the button under the posts. Empty: the page of the posts.</p></td></tr>';
		echo '<tr><th><label for="po-button">Button text</label></th><td><input id="po-button" name="button" class="regular-text" value="' . esc_attr( $o['button'] ) . '"><p class="description">Empty: no button.</p></td></tr>';
		echo '</table><p><button class="button button-primary">Save settings</button></p></form></div>';
		self::help();
	}

	private static function help() {
		echo '<div class="mqp-card"><h2>On the website</h2><p>Add a <strong>Shortcode</strong> widget (Elementor) or block in the "Our previous orders" section with:</p><p><code>[mq_previous_orders]</code></p>';
		echo '<p>It is a whole section: the heading and text, the posts, and the button to your Facebook page. Put it in an empty, full-width section.</p>';
		echo '<p class="description">Options: <code>limit="6"</code> (how many), <code>style="facebook"</code> (Facebook\'s own cards), <code>button="no"</code> (no button), <code>heading="no"</code> / <code>intro="no"</code> (when the page already has them).</p></div>';
	}

	public static function styles() {
		echo '<style>
		.mqp-po .nav-tab-wrapper{margin-bottom:16px}
		.mqp-po-tabcount{display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#dcdcde;font-size:11px;line-height:18px;text-align:center}
		.mqp-po-errs{margin:.5em 0;list-style:disc;padding-left:20px}
		.mqp-po-top,.mqp-po-edit{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;align-items:start}
		.mqp-po-edit{grid-template-columns:minmax(260px,340px) 1fr}
		.mqp-po-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px}
		.mqp-po-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
		.mqp-po-tile{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px;cursor:grab}
		.mqp-po-tile.is-drag{opacity:.4}
		.mqp-po-tile.is-off img{opacity:.5}
		.mqp-po-tile.is-needs{border-color:#dba617}
		.mqp-po-tile img,.mqp-po-noimg{display:block;width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;background:#f0f0f1}
		.mqp-po-noimg{display:flex;align-items:center;justify-content:center;color:#646970;font-size:12px}
		.mqp-po-pick{position:absolute;top:12px;left:12px;z-index:1;background:#fff;border-radius:3px;padding:2px 3px;line-height:1}
		.mqp-po-num{position:absolute;top:12px;right:12px;z-index:1;background:#1d2327;color:#fff;font-size:11px;font-weight:600;padding:1px 7px;border-radius:9px}
		.mqp-po-tags{display:flex;flex-wrap:wrap;gap:4px;margin:8px 0 4px}
		.mqp-po-tags span{font-size:11px;padding:1px 7px;border-radius:9px;background:#f0f0f1;color:#50575e}
		.mqp-po-tags .is-on{background:#e7f6ec;color:#1a7f37}
		.mqp-po-tags .is-needs{background:#fcf3d9;color:#8a6100}
		.mqp-po-snip{margin:0 0 8px;font-size:12px;color:#3c434a;flex:1}
		.mqp-po-acts{margin:0}
		.mqp-po-save{position:sticky;bottom:0;background:#f0f0f1;padding:10px 0;margin:12px 0 16px}
		.mqp-po-big{width:100%;height:auto;aspect-ratio:1;object-fit:cover;border-radius:6px;margin-bottom:8px}
		@media (max-width:782px){.mqp-po-edit{grid-template-columns:1fr}}
		</style>';
	}

	/* ---------- [mq_previous_orders] ---------- */

	/** The Facebook page for the button: the setting, or the page of the first post. */
	private static function page_url( $o ) {
		if ( $o['page_url'] ) {
			return $o['page_url'];
		}
		foreach ( $o['items'] as $it ) {
			if ( ! empty( $it['page'] ) ) {
				return $it['page'];
			}
		}
		return '';
	}

	public static function shortcode( $atts ) {
		$o    = self::options();
		$atts = shortcode_atts( array( 'limit' => $o['limit'], 'style' => $o['style'], 'button' => $o['button'], 'heading' => $o['heading'], 'intro' => $o['intro'] ), $atts );
		$show = array_slice( array_values( array_filter( $o['items'], function ( $it ) {
			return ! empty( $it['on'] );
		} ) ), 0, max( 1, (int) $atts['limit'] ) );
		if ( ! $show ) {
			return current_user_can( 'manage_options' ) ? '<p><em>[mq_previous_orders]: no posts yet. Add them in wp-admin → MQ Portal → Previous orders.</em></p>' : '';
		}
		$fb   = 'facebook' === $atts['style'];
		$page = self::page_url( $o );
		$out  = self::css() . '<section class="mqpo' . ( $fb ? ' mqpo-fb' : '' ) . '">';
		$head = 'no' === strtolower( (string) $atts['heading'] ) ? '' : trim( (string) $atts['heading'] );
		$intro = 'no' === strtolower( (string) $atts['intro'] ) ? '' : trim( (string) $atts['intro'] );
		if ( $head || $intro ) {
			$out .= '<header class="mqpo-head">' . ( $head ? '<h2 class="mqpo-heading">' . esc_html( $head ) . '</h2>' : '' ) . ( $intro ? '<p class="mqpo-intro">' . esc_html( $intro ) . '</p>' : '' ) . '</header>';
		}
		$out .= '<div class="mqpo-grid">';
		foreach ( $show as $it ) {
			if ( $fb ) {
				// Facebook's own card; a plain link stays if Facebook is blocked.
				$out .= '<div class="mqpo-fbpost"><div class="fb-post" data-href="' . esc_url( $it['post'] ) . '" data-width="500" data-show-text="true"><blockquote cite="' . esc_url( $it['post'] ) . '" class="fb-xfbml-parse-ignore"><a href="' . esc_url( $it['post'] ) . '" target="_blank" rel="noopener">View this post on Facebook</a></blockquote></div></div>';
				continue;
			}
			$cap   = trim( (string) $it['caption'] );
			$out  .= '<article class="mqpo-card">';
			$out  .= '<a class="mqpo-pic" href="' . esc_url( $it['post'] ) . '" target="_blank" rel="noopener" aria-label="View this order on Facebook">';
			$out  .= $it['image'] ? '<img src="' . esc_url( $it['image'] ) . '" alt="' . esc_attr( wp_trim_words( $cap, 12, '' ) ) . '" loading="lazy" decoding="async">' : '<span class="mqpo-nopic"></span>';
			$out  .= '</a><div class="mqpo-body">';
			if ( '' !== $cap ) {
				$out .= '<p class="mqpo-cap">' . nl2br( esc_html( $cap ) ) . '</p><button type="button" class="mqpo-more" hidden>Read more</button>';
			}
			$out .= '<a class="mqpo-link" href="' . esc_url( $it['post'] ) . '" target="_blank" rel="noopener">View on Facebook <span aria-hidden="true">↗</span></a></div></article>';
		}
		$out .= '</div>';
		if ( $page && '' !== trim( (string) $atts['button'] ) && 'no' !== strtolower( (string) $atts['button'] ) ) {
			$out .= '<p class="mqpo-cta"><a class="mqpo-btn" href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( $atts['button'] ) . '</a></p>';
		}
		$out .= '</section>' . self::script( $fb );
		return $out;
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="mqp-previous-orders-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/previous-orders.css' ) ) . '</style>'; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function script( $fb ) {
		static $done = array();
		$key = $fb ? 'fb' : 'cards';
		if ( isset( $done[ $key ] ) ) {
			return '';
		}
		$done[ $key ] = true;
		if ( $fb ) {
			// Facebook's script only loads when the section comes into view.
			return "<script>(function(){var s=document.querySelector('.mqpo-fb');if(!s)return;var go=function(){if(window.FB){FB.XFBML.parse();return;}var j=document.createElement('script');j.async=true;j.defer=true;j.crossOrigin='anonymous';j.src='https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v19.0';document.body.appendChild(j);};if(!('IntersectionObserver' in window))return go();var io=new IntersectionObserver(function(e){if(e[0].isIntersecting){io.disconnect();go();}},{rootMargin:'300px'});io.observe(s);})();</script>";
		}
		// "Read more" only where the caption is cut off.
		return "<script>(function(){var f=function(){document.querySelectorAll('.mqpo-card').forEach(function(c){var p=c.querySelector('.mqpo-cap'),b=c.querySelector('.mqpo-more');if(!p||!b||b.dataset.ok)return;b.dataset.ok=1;if(p.scrollHeight>p.clientHeight+2){b.hidden=false;b.onclick=function(){var o=c.classList.toggle('is-open');b.textContent=o?'Show less':'Read more';};}});};if(document.readyState!=='loading')f();else document.addEventListener('DOMContentLoaded',f);window.addEventListener('load',f);})();</script>";
	}
}
