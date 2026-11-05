<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Our Print Collection" gallery: a photo carousel of finished prints, shown with
 * [mq_print_collection] (only the carousel: the section's heading, text and button stay in
 * Elementor). Arrows, swipe on phones, click to enlarge, optional auto-slide.
 * Managed in wp-admin → MQ Portal → Print collection (WordPress administrators only).
 *
 * Light on storage: each photo is kept as two small WebP files (about 700px for the carousel and
 * 1600px for the enlarged view, roughly 150–250 KB together). The original is not kept, and
 * WordPress doesn't make its usual 5–8 extra sizes, so a phone photo takes about 30–50 times
 * less space than in the Media Library. Photos are uploaded one at a time in the background,
 * so the server's per-upload limits don't get in the way.
 */
class MQP_Gallery {

	const OPT    = 'mqp_gallery';
	const SLUG   = 'mq-portal-gallery';
	const DIR    = 'mq-gallery';
	const MAX    = 300;
	const SMALL  = 700;
	const LARGE  = 1600;
	const MAX_IN = 25; // MB per photo before it is made smaller.
	const SHAPES = array(
		'portrait'  => array( 'Portrait (like now)', '13 / 14' ),
		'square'    => array( 'Square', '1 / 1' ),
		'tall'      => array( 'Tall (4:5)', '4 / 5' ),
		'landscape' => array( 'Landscape (4:3)', '4 / 3' ),
	);

	public static function init() {
		add_shortcode( 'mq_print_collection', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_mqp_gallery', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_mqp_gallery', array( __CLASS__, 'ajax' ) );
	}

	public static function defaults() {
		return array(
			'items'    => array(), // [ id, s (carousel file), l (large file), w, h, bytes, alt, on, added ]
			'per_view' => 4,          // photos side by side on computers (fewer on tablets and phones)
			'shape'    => 'portrait',
			'autoplay' => 0,          // seconds between slides; 0 = only with the arrows
			'limit'    => 0,          // 0 = all photos that are switched on
		);
	}

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
	}

	/** Saves the settings; with $items_only, only the photos (unsaved settings keep their defaults). */
	private static function save( array $o, $items_only = false ) {
		$keep = $items_only ? array_merge( (array) get_option( self::OPT, array() ), array( 'items' => array_values( $o['items'] ) ) ) : $o;
		update_option( self::OPT, array_intersect_key( $keep, self::defaults() ), false );
		mqp_purge_page_cache();
	}

	private static function dir() {
		$up = wp_upload_dir();
		return array( trailingslashit( $up['basedir'] ) . self::DIR, trailingslashit( $up['baseurl'] ) . self::DIR );
	}

	/* ---------- Making the small copies ---------- */

	/** One picture file → a gallery item (or WP_Error). */
	public static function process( $path, $alt = '' ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return new WP_Error( 'mqp_gallery', 'must be a JPG, PNG or WebP picture (iPhone HEIC photos: share or save them as JPG first).' );
		}
		list( $dir, $url ) = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mqp_gallery', 'could not be saved (the uploads folder is not writable).' );
		}
		$webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$ext  = $webp ? 'webp' : 'jpg';
		$mime = $webp ? 'image/webp' : 'image/jpeg';
		$id   = strtolower( wp_generate_password( 10, false, false ) );
		$out  = array();
		foreach ( array( 's' => self::SMALL, 'l' => self::LARGE ) as $key => $size ) {
			$ed = wp_get_image_editor( $path );
			if ( is_wp_error( $ed ) ) {
				return new WP_Error( 'mqp_gallery', 'could not be read.' );
			}
			if ( method_exists( $ed, 'maybe_exif_rotate' ) ) {
				$ed->maybe_exif_rotate(); // Phone photos taken sideways.
			}
			$ed->resize( $size, $size, false );
			$ed->set_quality( 's' === $key ? 78 : 80 );
			$saved = $ed->save( "$dir/$id-$key.$ext", $mime );
			if ( is_wp_error( $saved ) ) {
				foreach ( $out as $f ) {
					wp_delete_file( $f['path'] );
				}
				return new WP_Error( 'mqp_gallery', 'could not be saved.' );
			}
			$out[ $key ] = $saved;
		}
		$bytes = (int) @filesize( $out['s']['path'] ) + (int) @filesize( $out['l']['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		MQP_Guard::add_bytes( $bytes );
		return array(
			'id'    => $id,
			's'     => $url . '/' . basename( $out['s']['path'] ),
			'l'     => $url . '/' . basename( $out['l']['path'] ),
			'w'     => (int) $out['l']['width'],
			'h'     => (int) $out['l']['height'],
			'bytes' => $bytes,
			'alt'   => mb_substr( sanitize_text_field( $alt ), 0, 150 ),
			'on'    => 1,
			'added' => mqp_now(),
		);
	}

	private static function remove_files( $it ) {
		list( $dir, $url ) = self::dir();
		foreach ( array( 's', 'l' ) as $k ) {
			if ( ! empty( $it[ $k ] ) && 0 === strpos( $it[ $k ], $url . '/' ) ) {
				$f = $dir . '/' . basename( $it[ $k ] );
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
	}

	/** Adds a processed photo at the top. Returns the item or WP_Error. */
	private static function add_file( $path, $name, $alt = '' ) {
		$o = self::options();
		if ( count( $o['items'] ) >= self::MAX ) {
			return new WP_Error( 'mqp_gallery', 'The collection is full (' . self::MAX . ' photos). Delete some first.' );
		}
		$it = self::process( $path, $alt );
		if ( is_wp_error( $it ) ) {
			return new WP_Error( 'mqp_gallery', $name . ' ' . $it->get_error_message() );
		}
		$o = self::options(); // Read again: other photos may have been added meanwhile.
		array_unshift( $o['items'], $it );
		self::save( $o, true );
		return $it;
	}

	/* ---------- wp-admin → MQ Portal → Print collection ---------- */

	public static function menu() {
		$hook = add_submenu_page( 'mq-portal', 'Our print collection', 'Print collection', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
		add_action( "load-$hook", 'wp_enqueue_media' );
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * The page's background actions (JSON): upload (one photo), item (show / hide / delete /
	 * describe), order (after dragging) and bulk (several photos).
	 */
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Only a WordPress administrator can do this.' ), 403 );
		}
		check_ajax_referer( 'mqp_gallery' );
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$op = sanitize_key( (string) ( $_POST['op'] ?? '' ) );

		if ( 'upload' === $op ) {
			$f = $_FILES['photo'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
				wp_send_json_error( array( 'message' => 'was not uploaded (too large for the server, or the connection stopped).' ) );
			}
			if ( (int) $f['size'] > self::MAX_IN * MB_IN_BYTES ) {
				wp_send_json_error( array( 'message' => 'is larger than ' . self::MAX_IN . ' MB.' ) );
			}
			$it = self::add_file( $f['tmp_name'], '' );
			if ( is_wp_error( $it ) ) {
				wp_send_json_error( array( 'message' => preg_replace( '/^\s+/', '', $it->get_error_message() ) ) );
			}
			wp_send_json_success( array( 'item' => $it ) );
		}

		$o = self::options();
		if ( 'stats' === $op ) {
			wp_send_json_success( array( 'stats' => self::stats( $o ) ) );
		}
		if ( 'item' === $op ) {
			$id   = sanitize_key( (string) ( $_POST['id'] ?? '' ) );
			$what = sanitize_key( (string) ( $_POST['what'] ?? '' ) );
			foreach ( $o['items'] as $i => $it ) {
				if ( $it['id'] !== $id ) {
					continue;
				}
				if ( 'delete' === $what ) {
					self::remove_files( $it );
					unset( $o['items'][ $i ] );
				} elseif ( 'show' === $what || 'hide' === $what ) {
					$o['items'][ $i ]['on'] = 'show' === $what ? 1 : 0;
				} elseif ( 'alt' === $what ) {
					$o['items'][ $i ]['alt'] = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['alt'] ?? '' ) ) ), 0, 150 );
				}
				self::save( $o, true );
				wp_send_json_success( array( 'stats' => self::stats( self::options() ) ) );
			}
			wp_send_json_error( array( 'message' => 'That photo is no longer in the collection. Reload the page.' ) );
		}

		if ( 'order' === $op ) {
			$want = array_map( 'sanitize_key', explode( ',', (string) ( $_POST['order'] ?? '' ) ) );
			$by   = array();
			foreach ( $o['items'] as $it ) {
				$by[ $it['id'] ] = $it;
			}
			$new = array();
			foreach ( $want as $id ) {
				if ( isset( $by[ $id ] ) ) {
					$new[] = $by[ $id ];
					unset( $by[ $id ] );
				}
			}
			$o['items'] = array_merge( $new, array_values( $by ) );
			self::save( $o, true );
			wp_send_json_success();
		}

		if ( 'bulk' === $op ) {
			$ids  = array_map( 'sanitize_key', (array) ( $_POST['ids'] ?? array() ) );
			$what = sanitize_key( (string) ( $_POST['what'] ?? '' ) );
			$n    = 0;
			foreach ( $o['items'] as $i => $it ) {
				if ( ! in_array( $it['id'], $ids, true ) ) {
					continue;
				}
				if ( 'delete' === $what ) {
					self::remove_files( $it );
					unset( $o['items'][ $i ] );
				} elseif ( 'show' === $what || 'hide' === $what ) {
					$o['items'][ $i ]['on'] = 'show' === $what ? 1 : 0;
				} else {
					continue;
				}
				$n++;
			}
			self::save( $o, true );
			if ( $n && 'delete' === $what ) {
				MQP_Monitor::log( 'system', "Deleted $n photo(s) from the print collection" );
			}
			wp_send_json_success( array( 'n' => $n, 'stats' => self::stats( self::options() ) ) );
		}
		wp_send_json_error( array( 'message' => 'Unknown action.' ) );
	}

	/** Copy from the Media Library, and the settings (normal form posts). */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		check_admin_referer( 'mqp_gallery' );
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$op   = sanitize_key( (string) ( $_POST['op'] ?? '' ) );
		$back = array();
		$bad  = array();
		if ( 'import' === $op ) {
			$added = 0;
			// Newest first: go through the picked ones backwards so they keep their picked order on top.
			$ids = array_reverse( array_slice( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['ids'] ?? '' ) ) ) ), 0, 100 ) );
			foreach ( $ids as $aid ) {
				$path = get_attached_file( $aid );
				if ( ! $path || ! is_file( $path ) || ! wp_attachment_is_image( $aid ) ) {
					$bad[] = 'Media item ' . $aid . ': not a picture.';
					continue;
				}
				$it = self::add_file( $path, basename( $path ), (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
				if ( is_wp_error( $it ) ) {
					$bad[] = $it->get_error_message();
					continue;
				}
				$added++;
			}
			MQP_Monitor::log( 'system', "Copied $added photo(s) from the Media Library to the print collection" );
			$back = array( 'done' => 'imported', 'n' => $added );
		} elseif ( 'settings' === $op ) {
			$o             = self::options();
			$o['per_view'] = max( 2, min( 6, (int) ( $_POST['per_view'] ?? 4 ) ) );
			$shape         = sanitize_key( (string) ( $_POST['shape'] ?? '' ) );
			$o['shape']    = isset( self::SHAPES[ $shape ] ) ? $shape : 'portrait';
			$o['autoplay'] = in_array( (int) ( $_POST['autoplay'] ?? 0 ), array( 0, 3, 4, 5, 7, 10 ), true ) ? (int) $_POST['autoplay'] : 0;
			$o['limit']    = max( 0, min( self::MAX, (int) ( $_POST['limit'] ?? 0 ) ) );
			self::save( $o );
			$back = array( 'done' => 'settings' );
		}
		if ( $bad ) {
			set_transient( 'mqp_gallery_err_' . get_current_user_id(), $bad, 300 );
		}
		wp_safe_redirect( self::url( $back ) );
		exit;
	}

	/** Numbers for the tiles at the top. */
	private static function stats( $o ) {
		$total = array_sum( wp_list_pluck( $o['items'], 'bytes' ) );
		$count = count( $o['items'] );
		return array(
			'count'  => $count,
			'shown'  => count( array_filter( wp_list_pluck( $o['items'], 'on' ) ) ),
			'bytes'  => size_format( $total, 1 ),
			// A phone photo in the Media Library: the original (about 4 MB) plus WordPress's extra sizes (about 2 MB).
			'saved'  => size_format( max( 0, $count * 6 * MB_IN_BYTES - $total ), 0 ),
		);
	}

	public static function render() {
		$o     = self::options();
		$st    = self::stats( $o );
		$done  = sanitize_key( (string) ( $_GET['done'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$n     = (int) ( $_GET['n'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$nonce = wp_create_nonce( 'mqp_gallery' );
		$msg   = array(
			'imported' => sprintf( _n( '%d photo copied from the Media Library.', '%d photos copied from the Media Library.', $n ), $n ) . ' The originals are still in Media → Library: once the old gallery is removed from the page, delete them there to free their space.',
			'settings' => 'Gallery settings saved.',
		);
		$form = function ( $op, $extra = '' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			wp_nonce_field( 'mqp_gallery' );
			echo '<input type="hidden" name="action" value="mqp_gallery"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		};
		$icon = function ( $name, $size = 18 ) {
			$paths = array(
				'upload' => '<path d="M12 16V4m0 0l-5 5m5-5l5 5M4 17v2a1 1 0 001 1h14a1 1 0 001-1v-2"/>',
				'images' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/>',
				'eye'    => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
				'eyeoff' => '<path d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0112 5c6.4 0 10 7 10 7a17.6 17.6 0 01-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.6 9.6 0 004.4-1.1M9.9 9.9a3 3 0 004.2 4.2"/>',
				'trash'  => '<path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/>',
				'zoom'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3M11 8v6M8 11h6"/>',
				'copy'   => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/>',
				'drive'  => '<path d="M4 15h16M6 19h12a2 2 0 002-2l-2.5-9.5A2 2 0 0015.6 6H8.4a2 2 0 00-1.9 1.5L4 17a2 2 0 002 2z"/>',
				'grip'   => '<circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/>',
			);
			return '<svg class="mqg-i" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
		};

		echo '<div class="wrap mqp-wrap mqg">';
		echo '<div class="mqg-head"><div><h1>Print collection</h1><p>Photos of finished prints for the "Our Print Collection" carousel on the website.</p></div>';
		echo '<div class="mqg-code"><span>Shortcode</span><code id="mqg-sc">[mq_print_collection]</code><button type="button" class="button" id="mqg-copy">' . $icon( 'copy', 15 ) . ' Copy</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( isset( $msg[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg[ $done ] ) . '</p></div>';
		}
		$errs = get_transient( 'mqp_gallery_err_' . get_current_user_id() );
		if ( $errs ) {
			delete_transient( 'mqp_gallery_err_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p><strong>Some photos were not added:</strong></p><ul>';
			foreach ( (array) $errs as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}

		// At a glance
		echo '<div class="mqg-stats">';
		echo '<div class="mqg-stat"><span class="mqg-stat-ic is-blue">' . $icon( 'images', 20 ) . '</span><div><strong data-stat="count">' . (int) $st['count'] . '</strong><span>Photos</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="mqg-stat"><span class="mqg-stat-ic is-green">' . $icon( 'eye', 20 ) . '</span><div><strong data-stat="shown">' . (int) $st['shown'] . '</strong><span>On the website</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="mqg-stat"><span class="mqg-stat-ic is-orange">' . $icon( 'drive', 20 ) . '</span><div><strong data-stat="bytes">' . esc_html( $st['bytes'] ) . '</strong><span>Storage used</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="mqg-stat"><span class="mqg-stat-ic is-teal">' . $icon( 'upload', 20 ) . '</span><div><strong data-stat="saved">~' . esc_html( $st['saved'] ) . '</strong><span>Saved vs. the Media Library</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		echo '<div class="mqg-layout"><div class="mqg-main">';

		// Upload
		echo '<div class="mqg-card"><div class="mqg-drop" id="mqg-drop" tabindex="0" role="button" aria-label="Add photos: drop them here or press Enter to choose">';
		echo '<span class="mqg-drop-ic">' . $icon( 'upload', 30 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<strong>Drop photos here</strong><span>or <u>choose them from this computer or phone</u></span>';
		echo '<small>JPG, PNG or WebP, straight from the phone is fine, as many as you like. New photos go first.</small>';
		echo '<input type="file" id="mqg-file" accept="image/jpeg,image/png,image/webp" multiple hidden></div>';
		echo '<div class="mqg-progress" id="mqg-progress" hidden><div class="mqg-bar"><span></span></div><p></p><ul></ul></div>';
		$form( 'import', ' id="mqg-import" class="mqg-import"' );
		echo '<input type="hidden" name="ids" value=""><button type="button" class="button" id="mqg-pick">' . $icon( 'images', 15 ) . ' Copy from the Media Library…</button><span class="description">For the photos of the current gallery: copied small, the originals stay in the Media Library.</span></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		// Photos
		$hidden = $st['count'] - $st['shown'];
		echo '<div class="mqg-card"><div class="mqg-toolbar">';
		echo '<div class="mqg-filter" role="group" aria-label="Show"><button type="button" class="is-active" data-f="all">All <span data-stat="count">' . (int) $st['count'] . '</span></button><button type="button" data-f="on">On the website <span data-stat="shown">' . (int) $st['shown'] . '</span></button><button type="button" data-f="off">Hidden <span data-stat="hidden">' . (int) $hidden . '</span></button></div>';
		echo '<div class="mqg-bulk" id="mqg-bulk" hidden><strong></strong><button type="button" class="button" data-bulk="show">Show</button><button type="button" class="button" data-bulk="hide">Hide</button><button type="button" class="button mqg-danger" data-bulk="delete">Delete</button><button type="button" class="button-link" data-bulk="none">Clear</button></div>';
		echo '<span class="mqg-hint">' . $icon( 'grip', 15 ) . ' Drag to reorder: saved by itself</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
		echo '<div class="mqg-grid" id="mqg-grid">';
		foreach ( $o['items'] as $i => $it ) {
			self::tile( $it, $i, $icon );
		}
		echo '</div>';
		echo '<div class="mqg-empty" id="mqg-empty"' . ( $o['items'] ? ' hidden' : '' ) . '>' . $icon( 'images', 40 ) . '<p><strong>No photos yet.</strong><br>Drop some photos above to start the collection.</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div>';

		// Side: settings and how to use
		echo '<aside class="mqg-side"><div class="mqg-card"><h2>Gallery settings</h2>';
		$form( 'settings' );
		echo '<label class="mqg-field"><span>Photos side by side</span><select name="per_view">';
		foreach ( array( 2, 3, 4, 5, 6 ) as $v ) {
			echo '<option value="' . (int) $v . '"' . selected( $o['per_view'], $v, false ) . '>' . (int) $v . ' on computers</option>';
		}
		echo '</select><small>Tablets show up to 3, phones 1 (with the next one peeking).</small></label>';
		echo '<label class="mqg-field"><span>Photo shape</span><select name="shape">';
		foreach ( self::SHAPES as $k => $s ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $o['shape'], $k, false ) . '>' . esc_html( $s[0] ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="mqg-field"><span>Slide by itself</span><select name="autoplay">';
		foreach ( array( 0 => 'No, only with the arrows', 3 => 'Every 3 seconds', 4 => 'Every 4 seconds', 5 => 'Every 5 seconds', 7 => 'Every 7 seconds', 10 => 'Every 10 seconds' ) as $v => $l ) {
			echo '<option value="' . (int) $v . '"' . selected( $o['autoplay'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><small>Stops while the visitor points at or touches the photos.</small></label>';
		echo '<label class="mqg-field"><span>How many photos</span><input type="number" name="limit" min="0" max="' . (int) self::MAX . '" value="' . (int) $o['limit'] . '"><small>0 = all photos on the website.</small></label>';
		echo '<p><button class="button button-primary">Save settings</button></p></form></div>';

		echo '<div class="mqg-card mqg-help"><h2>On the website</h2><ol>';
		echo '<li>In Elementor, open the "Our Print Collection" section.</li>';
		echo '<li>Keep its heading, text and "Upload Document" button.</li>';
		echo '<li>Replace the old gallery or carousel widget with a <strong>Shortcode</strong> widget containing <code>[mq_print_collection]</code>.</li>';
		echo '<li>Delete the old gallery photos in Media → Library to free their space.</li></ol>';
		echo '<p class="description">Options: <code>per_view="3"</code>, <code>shape="square"</code>, <code>autoplay="5"</code>, <code>limit="12"</code>.</p></div>';
		echo '</aside></div>';

		// Large view
		echo '<div class="mqg-lightbox" id="mqg-lightbox" hidden><button type="button" aria-label="Close">×</button><img alt=""></div>';
		?>
		<script>
		(function () {
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var grid = document.getElementById('mqg-grid'), empty = document.getElementById('mqg-empty');
			var drop = document.getElementById('mqg-drop'), file = document.getElementById('mqg-file');
			var prog = document.getElementById('mqg-progress'), bulk = document.getElementById('mqg-bulk');
			var tpl = <?php echo wp_json_encode( self::tile_template( $icon ) ); ?>;

			function post(data, files) {
				var fd = new FormData();
				fd.append('action', 'mqp_gallery'); fd.append('_ajax_nonce', nonce);
				Object.keys(data).forEach(function (k) { [].concat(data[k]).forEach(function (v) { fd.append(Array.isArray(data[k]) ? k + '[]' : k, v); }); });
				if (files) Object.keys(files).forEach(function (k) { fd.append(k, files[k]); });
				return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { success: false, data: { message: 'the connection stopped.' } }; });
			}
			function stats(s) {
				if (!s) return;
				s.hidden = s.count - s.shown;
				document.querySelectorAll('[data-stat]').forEach(function (el) { var k = el.dataset.stat; if (k in s) el.textContent = (k === 'saved' ? '~' : '') + s[k]; });
			}
			function toast(text, bad) {
				var t = document.createElement('div'); t.className = 'mqg-toast' + (bad ? ' is-bad' : ''); t.textContent = text;
				document.body.appendChild(t); setTimeout(function () { t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 300); }, 2600);
			}
			function numbers() {
				[].forEach.call(grid.querySelectorAll('.mqg-tile'), function (t, i) { t.querySelector('.mqg-num').textContent = i + 1; });
				empty.hidden = !!grid.children.length;
			}

			/* Upload: one photo at a time, with progress */
			drop.addEventListener('click', function () { file.click(); });
			drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } });
			['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types, 'Files') > -1) { e.preventDefault(); drop.classList.add('is-over'); } }); });
			['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
			drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) { e.preventDefault(); upload([].slice.call(e.dataTransfer.files)); } });
			file.addEventListener('change', function () { upload([].slice.call(file.files)); file.value = ''; });
			var busy = false;
			async function upload(files) {
				files = files.filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp|heic)$/i.test(f.name); });
				if (!files.length || busy) return;
				busy = true; prog.hidden = false;
				var bar = prog.querySelector('.mqg-bar span'), txt = prog.querySelector('p'), list = prog.querySelector('ul');
				list.innerHTML = ''; var ok = 0, last = null;
				for (var i = 0; i < files.length; i++) {
					txt.textContent = 'Making photo ' + (i + 1) + ' of ' + files.length + ' small for the web…';
					bar.style.width = Math.round(i / files.length * 100) + '%';
					var r = await post({ op: 'upload' }, { photo: files[i] });
					if (r && r.success) {
						ok++;
						var t = tile(r.data.item);
						// Keep the upload order: each new one after the previous new one.
						if (last && last.parentNode) last.after(t); else grid.prepend(t);
						last = t;
					} else {
						var li = document.createElement('li'); li.textContent = files[i].name + ' ' + ((r && r.data && r.data.message) || 'could not be added.'); list.appendChild(li);
					}
				}
				bar.style.width = '100%';
				txt.textContent = ok + ' of ' + files.length + ' photo' + (files.length === 1 ? '' : 's') + ' added.' + (list.children.length ? ' Not added:' : '');
				numbers(); refreshStats(); busy = false;
				if (!list.children.length) setTimeout(function () { prog.hidden = true; }, 2500);
			}
			function refreshStats() { post({ op: 'stats' }).then(function (r) { if (r.success) stats(r.data.stats); }); }

			function tile(it) {
				var d = document.createElement('div'); d.innerHTML = tpl.trim(); var t = d.firstChild;
				t.dataset.id = it.id; t.dataset.large = it.l;
				t.querySelector('img').src = it.s;
				t.querySelector('.mqg-alt').value = it.alt || '';
				t.querySelector('.mqg-size').textContent = Math.max(1, Math.round(it.bytes / 1024)) + ' KB';
				if (!it.on) t.classList.add('is-off');
				return t;
			}

			/* Per photo: show / hide, enlarge, delete, description */
			grid.addEventListener('click', function (e) {
				var b = e.target.closest('[data-act]'); if (!b) return;
				var t = b.closest('.mqg-tile'), id = t.dataset.id, act = b.dataset.act;
				if (act === 'zoom') return zoom(t.dataset.large);
				if (act === 'toggle') {
					var off = !t.classList.contains('is-off');
					t.classList.toggle('is-off', off);
					post({ op: 'item', id: id, what: off ? 'hide' : 'show' }).then(function (r) { if (!r.success) { t.classList.toggle('is-off', !off); toast(r.data.message, true); } else { stats(r.data.stats); toast(off ? 'Hidden from the website' : 'Shown on the website'); } });
				}
				if (act === 'delete') {
					if (!confirm('Delete this photo for good?')) return;
					t.classList.add('is-going');
					post({ op: 'item', id: id, what: 'delete' }).then(function (r) { if (r.success) { t.remove(); numbers(); stats(r.data.stats); toast('Photo deleted'); } else { t.classList.remove('is-going'); toast(r.data.message, true); } });
				}
			});
			grid.addEventListener('change', function (e) {
				if (e.target.classList.contains('mqg-alt')) {
					var t = e.target.closest('.mqg-tile');
					post({ op: 'item', id: t.dataset.id, what: 'alt', alt: e.target.value }).then(function (r) { toast(r.success ? 'Description saved' : r.data.message, !r.success); });
				}
				if (e.target.classList.contains('mqg-check')) selection();
			});

			/* Several at once */
			function picked() { return [].map.call(grid.querySelectorAll('.mqg-check:checked'), function (c) { return c.closest('.mqg-tile').dataset.id; }); }
			function selection() {
				var ids = picked(); bulk.hidden = !ids.length;
				bulk.querySelector('strong').textContent = ids.length + ' selected';
				grid.classList.toggle('is-picking', ids.length > 0);
				[].forEach.call(grid.querySelectorAll('.mqg-tile'), function (t) { t.classList.toggle('is-picked', t.querySelector('.mqg-check').checked); });
			}
			bulk.addEventListener('click', function (e) {
				var b = e.target.closest('[data-bulk]'); if (!b) return;
				var what = b.dataset.bulk, ids = picked();
				if (what === 'none') { grid.querySelectorAll('.mqg-check').forEach(function (c) { c.checked = false; }); return selection(); }
				if (what === 'delete' && !confirm('Delete ' + ids.length + ' photo' + (ids.length === 1 ? '' : 's') + ' for good?')) return;
				post({ op: 'bulk', what: what, ids: ids }).then(function (r) {
					if (!r.success) return toast(r.data.message, true);
					ids.forEach(function (id) { var t = grid.querySelector('[data-id="' + id + '"]'); if (!t) return; if (what === 'delete') t.remove(); else { t.classList.toggle('is-off', what === 'hide'); t.querySelector('.mqg-check').checked = false; } });
					numbers(); selection(); stats(r.data.stats);
					toast(r.data.n + ' ' + (what === 'delete' ? 'deleted' : what === 'hide' ? 'hidden' : 'shown on the website'));
				});
			});

			/* Filter */
			document.querySelector('.mqg-filter').addEventListener('click', function (e) {
				var b = e.target.closest('button'); if (!b) return;
				this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
				grid.dataset.f = b.dataset.f;
			});

			/* Drag to reorder (saved by itself) */
			var drag = null;
			grid.addEventListener('dragstart', function (e) { drag = e.target.closest('.mqg-tile'); if (drag) { drag.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', drag.dataset.id); } });
			grid.addEventListener('dragover', function (e) {
				if (!drag) return; e.preventDefault();
				var t = e.target.closest('.mqg-tile'); if (!t || t === drag) return;
				var r = t.getBoundingClientRect();
				grid.insertBefore(drag, (e.clientX - r.left) > r.width / 2 ? t.nextSibling : t);
			});
			grid.addEventListener('dragend', function () {
				if (!drag) return; drag.classList.remove('is-drag'); drag = null; numbers();
				post({ op: 'order', order: [].map.call(grid.querySelectorAll('.mqg-tile'), function (t) { return t.dataset.id; }).join(',') }).then(function (r) { toast(r.success ? 'Order saved' : 'The order could not be saved', !r.success); });
			});

			/* Large view */
			var box = document.getElementById('mqg-lightbox');
			function zoom(src) { box.querySelector('img').src = src; box.hidden = false; }
			box.addEventListener('click', function () { box.hidden = true; });
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape') box.hidden = true; });

			/* Copy the shortcode */
			document.getElementById('mqg-copy').addEventListener('click', function () {
				var t = document.getElementById('mqg-sc').textContent;
				(navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { toast('Shortcode copied'); }, function () { toast('Select the shortcode and copy it'); });
			});

			/* Copy from the Media Library */
			var pick = document.getElementById('mqg-pick');
			if (pick && window.wp && wp.media) {
				var frame;
				pick.addEventListener('click', function () {
					frame = frame || wp.media({ title: 'Copy photos into the print collection', library: { type: 'image' }, multiple: true, button: { text: 'Copy these photos' } });
					frame.off('select').on('select', function () {
						var f = document.getElementById('mqg-import');
						f.ids.value = frame.state().get('selection').map(function (a) { return a.id; }).join(',');
						if (f.ids.value) { pick.disabled = true; pick.textContent = 'Copying the photos…'; f.submit(); }
					});
					frame.open();
				});
			}
		})();
		</script>
		<?php
		echo '</div>';
	}

	/** One photo tile in wp-admin. */
	private static function tile( $it, $i, $icon ) {
		echo '<div class="mqg-tile' . ( $it['on'] ? '' : ' is-off' ) . '" draggable="true" data-id="' . esc_attr( $it['id'] ) . '" data-large="' . esc_url( $it['l'] ) . '">';
		echo self::tile_inner( $icon, $i + 1, $it['s'], $it['alt'], size_format( (int) $it['bytes'], 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	/** The same tile, empty, for photos added without reloading the page. */
	private static function tile_template( $icon ) {
		return '<div class="mqg-tile" draggable="true">' . self::tile_inner( $icon, '', '', '', '' ) . '</div>';
	}

	private static function tile_inner( $icon, $num, $src, $alt, $size ) {
		return '<div class="mqg-pic"><img src="' . esc_url( $src ) . '" alt="" loading="lazy" draggable="false">'
			. '<label class="mqg-pick" title="Select"><input type="checkbox" class="mqg-check" aria-label="Select this photo"></label>'
			. '<span class="mqg-num">' . esc_html( (string) $num ) . '</span>'
			. '<span class="mqg-off">Hidden</span>'
			. '<div class="mqg-acts">'
			. '<button type="button" data-act="zoom" title="See it large" aria-label="See it large">' . $icon( 'zoom', 17 ) . '</button>'
			. '<button type="button" data-act="toggle" class="mqg-toggle" title="Show or hide on the website" aria-label="Show or hide on the website"><span class="is-shown">' . $icon( 'eye', 17 ) . '</span><span class="is-hidden">' . $icon( 'eyeoff', 17 ) . '</span></button>'
			. '<button type="button" data-act="delete" class="mqg-del" title="Delete" aria-label="Delete">' . $icon( 'trash', 17 ) . '</button>'
			. '</div></div>'
			. '<div class="mqg-meta"><input type="text" class="mqg-alt" value="' . esc_attr( $alt ) . '" placeholder="Add a description…" maxlength="150" aria-label="Description"><span class="mqg-size">' . esc_html( $size ) . '</span></div>';
	}

	public static function styles() {
		echo '<style>
		.mqg{--mqg-blue:#1e3a8a;--mqg-orange:#f88a35;--mqg-line:#e2e4e9;--mqg-muted:#646970;max-width:1500px}
		.mqg [hidden]{display:none!important}
		.mqg .mqg-i{display:inline-block;vertical-align:middle;flex-shrink:0}
		.mqg-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 24px;margin:6px 0 18px}
		.mqg-head h1{margin:0 0 4px;font-size:24px;font-weight:700}
		.mqg-head p{margin:0;color:var(--mqg-muted);font-size:14px}
		.mqg-code{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--mqg-line);border-radius:10px;padding:6px 6px 6px 12px}
		.mqg-code span{font-size:12px;color:var(--mqg-muted);text-transform:uppercase;letter-spacing:.04em}
		.mqg-code code{background:#f6f7f9;border-radius:6px;padding:4px 8px;font-size:13px}
		.mqg-code .button{display:inline-flex;align-items:center;gap:4px}
		.mqg-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:16px}
		.mqg-stat{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--mqg-line);border-radius:12px;padding:14px 16px}
		.mqg-stat strong{display:block;font-size:22px;line-height:1.1;color:#1d2327}
		.mqg-stat span:not(.mqg-stat-ic){font-size:12px;color:var(--mqg-muted)}
		.mqg-stat-ic{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px}
		.mqg-stat-ic.is-blue{background:#e8edfb;color:var(--mqg-blue)}.mqg-stat-ic.is-green{background:#e7f6ec;color:#1a7f37}.mqg-stat-ic.is-orange{background:#fdf0e5;color:#c25f10}.mqg-stat-ic.is-teal{background:#e3f5f5;color:#11777a}
		.mqg-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
		.mqg-card{background:#fff;border:1px solid var(--mqg-line);border-radius:12px;padding:16px;margin-bottom:16px}
		.mqg-card h2{margin:0 0 12px;font-size:15px}
		.mqg-drop{display:flex;flex-direction:column;align-items:center;gap:4px;padding:28px 16px;border:2px dashed #c3c8d1;border-radius:12px;background:#fafbfc;text-align:center;cursor:pointer;transition:border-color .15s,background .15s}
		.mqg-drop:hover,.mqg-drop:focus-visible,.mqg-drop.is-over{border-color:var(--mqg-orange);background:#fff8f2;outline:0}
		.mqg-drop-ic{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#fdf0e5;color:#c25f10;margin-bottom:6px}
		.mqg-drop strong{font-size:16px;color:#1d2327}
		.mqg-drop u{color:var(--mqg-blue)}
		.mqg-drop small{color:var(--mqg-muted);margin-top:4px}
		.mqg-progress{margin-top:12px}
		.mqg-bar{height:8px;border-radius:8px;background:#eef0f3;overflow:hidden}
		.mqg-bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--mqg-blue),var(--mqg-orange));transition:width .3s}
		.mqg-progress p{margin:8px 0 4px;font-weight:600}
		.mqg-progress ul{margin:0;padding-left:18px;list-style:disc;color:#b32d2e}
		.mqg-import{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-top:14px}
		.mqg-import .button{display:inline-flex;align-items:center;gap:6px}
		.mqg-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;margin-bottom:14px}
		.mqg-filter{display:inline-flex;background:#f0f1f4;border-radius:999px;padding:3px}
		.mqg-filter button{border:0;background:none;border-radius:999px;padding:5px 12px;font-size:13px;cursor:pointer;color:#3c434a}
		.mqg-filter button span{display:inline-block;min-width:18px;margin-left:4px;padding:0 6px;border-radius:9px;background:rgba(0,0,0,.07);font-size:11px;line-height:18px}
		.mqg-filter button.is-active{background:#fff;color:#1d2327;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.12)}
		.mqg-bulk{display:flex;flex-wrap:wrap;align-items:center;gap:6px;background:#eef3ff;border-radius:8px;padding:4px 8px}
		.mqg-bulk strong{margin-right:4px}
		.mqg .mqg-danger{color:#b32d2e;border-color:#e2a2a2}
		.mqg-hint{margin-left:auto;display:inline-flex;align-items:center;gap:4px;color:var(--mqg-muted);font-size:12px}
		.mqg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px}
		.mqg-grid[data-f=on] .mqg-tile.is-off,.mqg-grid[data-f=off] .mqg-tile:not(.is-off){display:none}
		.mqg-tile{background:#fff;border:1px solid var(--mqg-line);border-radius:10px;overflow:hidden;cursor:grab;transition:box-shadow .15s,transform .15s,opacity .2s}
		.mqg-tile:hover{box-shadow:0 6px 18px rgba(17,24,39,.1)}
		.mqg-tile.is-drag{opacity:.35;transform:scale(.97)}
		.mqg-tile.is-going{opacity:.3;pointer-events:none}
		.mqg-tile.is-picked{border-color:var(--mqg-blue);box-shadow:0 0 0 2px var(--mqg-blue)}
		.mqg-pic{position:relative;aspect-ratio:13/14;background:#f0f1f4}
		.mqg-pic img{display:block;width:100%;height:100%;object-fit:cover}
		.mqg-tile.is-off .mqg-pic img{opacity:.4;filter:grayscale(.6)}
		.mqg-pick{position:absolute;top:8px;left:8px;display:flex;background:rgba(255,255,255,.95);border-radius:5px;padding:3px;opacity:0;transition:opacity .15s}
		.mqg-pick input{margin:0}
		.mqg-tile:hover .mqg-pick,.mqg-grid.is-picking .mqg-pick,.mqg-tile:focus-within .mqg-pick{opacity:1}
		.mqg-num{position:absolute;top:8px;right:8px;min-width:22px;padding:0 6px;border-radius:11px;background:rgba(17,24,39,.75);color:#fff;font-size:11px;font-weight:600;line-height:22px;text-align:center}
		.mqg-off{display:none;position:absolute;left:8px;bottom:8px;padding:2px 8px;border-radius:5px;background:#1d2327;color:#fff;font-size:11px}
		.mqg-tile.is-off .mqg-off{display:inline-block}
		.mqg-acts{position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:flex-end;gap:6px;padding:28px 8px 8px;background:linear-gradient(transparent,rgba(17,24,39,.55));opacity:0;transition:opacity .15s}
		.mqg-tile:hover .mqg-acts,.mqg-tile:focus-within .mqg-acts{opacity:1}
		.mqg-acts button{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:0;border-radius:8px;background:rgba(255,255,255,.95);color:#1d2327;cursor:pointer}
		.mqg-acts button:hover{background:#fff;color:var(--mqg-blue)}
		.mqg-acts .mqg-del:hover{color:#b32d2e}
		.mqg-toggle .is-hidden,.mqg-tile.is-off .mqg-toggle .is-shown{display:none}
		.mqg-tile.is-off .mqg-toggle .is-hidden{display:inline-flex}
		.mqg-meta{display:flex;align-items:center;gap:6px;padding:8px}
		.mqg-alt{flex:1;min-width:0;border:1px solid transparent!important;background:#f6f7f9!important;border-radius:6px!important;font-size:12px!important;padding:3px 7px!important;min-height:28px!important}
		.mqg-alt:focus{background:#fff!important;border-color:var(--mqg-blue)!important;box-shadow:none!important}
		.mqg-size{font-size:11px;color:var(--mqg-muted);white-space:nowrap}
		.mqg-empty{text-align:center;color:var(--mqg-muted);padding:36px 12px}
		.mqg-side .mqg-field{display:block;margin-bottom:12px}
		.mqg-side .mqg-field span{display:block;font-weight:600;margin-bottom:4px}
		.mqg-side .mqg-field select,.mqg-side .mqg-field input{width:100%;max-width:none}
		.mqg-side .mqg-field small{display:block;color:var(--mqg-muted);margin-top:3px}
		.mqg-help ol{margin:0 0 10px 18px}.mqg-help li{margin-bottom:6px}
		.mqg-lightbox{position:fixed;inset:0;z-index:100100;display:flex;align-items:center;justify-content:center;background:rgba(10,14,22,.88);padding:40px;cursor:zoom-out}
		.mqg-lightbox[hidden]{display:none}
		.mqg-lightbox img{max-width:100%;max-height:100%;border-radius:6px}
		.mqg-lightbox button{position:absolute;top:14px;right:18px;border:0;background:none;color:#fff;font-size:32px;cursor:pointer}
		.mqg-toast{position:fixed;left:50%;bottom:28px;z-index:100200;transform:translateX(-50%);background:#1d2327;color:#fff;padding:9px 16px;border-radius:8px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.2);transition:opacity .3s}
		.mqg-toast.is-bad{background:#b32d2e}.mqg-toast.is-out{opacity:0}
		@media (max-width:1100px){.mqg-layout{grid-template-columns:1fr}}
		@media (max-width:600px){.mqg-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.mqg-hint{display:none}.mqg-acts,.mqg-pick{opacity:1}}
		</style>';
	}

	/* ---------- [mq_print_collection] ---------- */

	/**
	 * [mq_print_collection per_view="4" shape="portrait" autoplay="0" limit="0"]
	 * Only the photo carousel: the section's heading, text and button stay on the page.
	 */
	public static function shortcode( $atts ) {
		$o    = self::options();
		$atts = shortcode_atts( array( 'per_view' => $o['per_view'], 'shape' => $o['shape'], 'autoplay' => $o['autoplay'], 'limit' => $o['limit'] ), $atts );
		$show = array_values( array_filter( $o['items'], function ( $it ) {
			return ! empty( $it['on'] );
		} ) );
		if ( (int) $atts['limit'] > 0 ) {
			$show = array_slice( $show, 0, (int) $atts['limit'] );
		}
		if ( ! $show ) {
			return current_user_can( 'manage_options' ) ? '<p><em>[mq_print_collection]: no photos yet. Add them in wp-admin → MQ Portal → Print collection.</em></p>' : '';
		}
		$per   = max( 2, min( 6, (int) $atts['per_view'] ) );
		$shape = self::SHAPES[ $atts['shape'] ][1] ?? self::SHAPES['portrait'][1];
		$auto  = max( 0, min( 30, (int) $atts['autoplay'] ) );
		$style = '--mqpc-per:' . $per . ';--mqpc-per-md:' . min( 3, $per ) . ';--mqpc-per-sm:' . min( 2, $per ) . ';--mqpc-shape:' . $shape;
		$arrow = function ( $dir ) {
			$d = 'prev' === $dir ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
			return '<button type="button" class="mqpc-arrow mqpc-' . $dir . '" aria-label="' . ( 'prev' === $dir ? 'Previous photos' : 'Next photos' ) . '" hidden><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
		};
		$out  = self::css() . '<div class="mqpc" style="' . esc_attr( $style ) . '"' . ( $auto ? ' data-autoplay="' . (int) $auto . '"' : '' ) . '><div class="mqpc-stage">' . $arrow( 'prev' );
		$out .= '<div class="mqpc-track" tabindex="0" aria-label="Print collection photos">';
		foreach ( $show as $i => $it ) {
			$alt  = $it['alt'] ? $it['alt'] : 'Print by MQ Prints, photo ' . ( $i + 1 );
			$out .= '<a class="mqpc-item" href="' . esc_url( $it['l'] ) . '"' . ( $it['alt'] ? ' data-caption="' . esc_attr( $it['alt'] ) . '"' : '' ) . '><img src="' . esc_url( $it['s'] ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async"></a>';
		}
		$out .= '</div>' . $arrow( 'next' ) . '</div></div>' . self::script();
		return $out;
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="mqp-print-collection-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/print-collection.css' ) ) . '</style>'; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<script>' . (string) file_get_contents( MQP_DIR . 'assets/print-collection.js' ) . '</script>'; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
