<?php
defined( 'ABSPATH' ) || exit;

/**
 * Archives: frees up hosting space by moving the files of finished job orders into a zip that the
 * administrator downloads and keeps on a PC (later: Google Drive).
 *
 * 1. Build: the files of job orders that were delivered or cancelled at least N days ago go into a
 *    zip (one folder per job order, plus a list of everything in it). This happens every week, when
 *    the storage is nearly full, or with "Make an archive now". The files stay usable until step 3.
 * 2. Download: wp-admin → MQ Portal → System → Files & archives → Archives → Download.
 * 3. Free up space: after the download, "I saved it — free up the space" deletes the originals and
 *    the zip from the hosting. The job orders keep the file names, marked "Archived".
 *
 * Nothing is deleted before the zip was downloaded, and only an administrator can do it.
 */
class MQP_Archive {

	const ACTION = 'mqp_archive';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'mqp_archive_now', array( __CLASS__, 'auto' ) );
	}

	public static function dir() {
		return MQP_Files::base_dir() . '/archives';
	}

	/** Display name of an archive, e.g. "MQ-files-2026-10-20-3.zip". */
	public static function name( $id ) {
		global $wpdb;
		$name = $id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . mqp_table( 'archives' ) . ' WHERE id = %d', $id ) ) : '';
		return $name ? $name : '#' . (int) $id;
	}

	public static function settings() {
		$s = MQP_Monitor::settings();
		return array(
			'auto'    => (int) $s['archive_auto'],
			'days'    => max( 0, (int) $s['archive_after_days'] ),
			'max_mb'  => max( 50, (int) $s['archive_max_mb'] ),
			'percent' => max( 10, min( 100, (int) $s['archive_at_percent'] ) ),
		);
	}

	/** Files of finished job orders that can go into an archive. */
	private static function candidates( $limit_bytes ) {
		global $wpdb;
		$cut  = wp_date( 'Y-m-d H:i:s', time() - self::settings()['days'] * DAY_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.*, j.number AS job_number, j.customer_name FROM ' . mqp_table( 'files' ) . ' f JOIN ' . mqp_table( 'jobs' ) . " j ON j.id = f.job_id
				 WHERE f.archive_id IS NULL AND f.archived_at IS NULL AND j.stage IN ('delivered','cancelled') AND j.stage_changed_at <= %s
				 ORDER BY j.id, f.id LIMIT 5000",
				$cut
			),
			ARRAY_A
		);
		$out   = array();
		$total = 0;
		foreach ( $rows as $r ) {
			if ( $out && $total + (int) $r['size'] > $limit_bytes ) {
				break;
			}
			$out[]  = $r;
			$total += (int) $r['size'];
		}
		return $out;
	}

	/** Bytes that could be archived right now. */
	public static function waiting_bytes() {
		global $wpdb;
		$cut = wp_date( 'Y-m-d H:i:s', time() - self::settings()['days'] * DAY_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(f.size), 0) FROM ' . mqp_table( 'files' ) . ' f JOIN ' . mqp_table( 'jobs' ) . " j ON j.id = f.job_id
				 WHERE f.archive_id IS NULL AND f.archived_at IS NULL AND j.stage IN ('delivered','cancelled') AND j.stage_changed_at <= %s",
				$cut
			)
		);
	}

	/**
	 * Makes one zip (up to the size limit). Returns the archive id, 0 when there is nothing to
	 * archive, or WP_Error.
	 */
	public static function build( $reason = 'manual' ) {
		global $wpdb;
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'mqp_archive', 'The server has no zip support (PHP zip extension). Ask Hostinger to enable it.' );
		}
		$files = self::candidates( self::settings()['max_mb'] * MB_IN_BYTES );
		if ( ! $files ) {
			return 0;
		}
		MQP_Files::protect_dir();
		wp_mkdir_p( self::dir() );
		$table = mqp_table( 'archives' );
		$wpdb->insert( $table, array( 'name' => '', 'path' => '', 'status' => 'building', 'reason' => $reason, 'created_at' => mqp_now(), 'created_by' => get_current_user_id() ) );
		$id   = (int) $wpdb->insert_id;
		$name = 'MQ-files-' . wp_date( 'Y-m-d' ) . '-' . $id . '.zip';
		$path = self::dir() . '/' . wp_generate_password( 12, false ) . '-' . $name;

		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$wpdb->update( $table, array( 'status' => 'failed' ), array( 'id' => $id ) );
			return new WP_Error( 'mqp_archive', 'Could not create the zip file in the portal folder.' );
		}
		$csv   = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $csv, array( 'Job order', 'Customer', 'Kind', 'File', 'Size (bytes)', 'Uploaded', 'Path in this zip' ) );
		$added = array();
		$bytes = 0;
		$jobs  = array();
		foreach ( $files as $f ) {
			$src = MQP_Files::base_dir() . '/' . $f['stored_path'];
			if ( ! is_file( $src ) ) {
				continue;
			}
			$folder = sanitize_file_name( $f['job_number'] . ' ' . remove_accents( (string) $f['customer_name'] ) );
			$inside = $folder . '/' . ( 'payment' === $f['kind'] ? 'payment-' : '' ) . (int) $f['id'] . '-' . sanitize_file_name( $f['original_name'] );
			if ( ! $zip->addFile( $src, $inside ) ) {
				continue;
			}
			// Print files are already compressed (PDF, JPG…): storing them is fast and the zip is about as small.
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $inside, ZipArchive::CM_STORE );
			}
			fputcsv( $csv, array( $f['job_number'], $f['customer_name'], $f['kind'], $f['original_name'], (int) $f['size'], $f['created_at'], $inside ) );
			$added[]                    = (int) $f['id'];
			$bytes                     += (int) $f['size'];
			$jobs[ $f['job_number'] ] = true;
		}
		rewind( $csv );
		$zip->addFromString( 'files-in-this-archive.csv', "\xEF\xBB\xBF" . stream_get_contents( $csv ) );
		fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ok = $zip->close();
		if ( ! $ok || ! $added ) {
			wp_delete_file( $path );
			$wpdb->update( $table, array( 'status' => 'failed' ), array( 'id' => $id ) );
			return $added ? new WP_Error( 'mqp_archive', 'The zip file could not be finished (is the disk full?).' ) : 0;
		}
		$in = implode( ',', $added );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'files' ) . " SET archive_id = %d WHERE id IN ($in)", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints only.
		$wpdb->update(
			$table,
			array( 'name' => $name, 'path' => substr( $path, strlen( MQP_Files::base_dir() ) + 1 ), 'size' => (int) filesize( $path ), 'files' => count( $added ), 'jobs' => count( $jobs ), 'status' => 'ready', 'bytes' => $bytes ),
			array( 'id' => $id )
		);
		MQP_Monitor::log( 'archive_made', $name . ' · ' . count( $added ) . ' file(s) of ' . count( $jobs ) . ' job order(s) · ' . size_format( $bytes ) );
		MQP_GDrive::queue(); // Uploaded to Google Drive in the background when it is connected.
		return $id;
	}

	/**
	 * Daily: a new archive every week, or right away when the storage is nearly full. The admin is
	 * emailed to download it.
	 */
	public static function auto() {
		global $wpdb;
		$s = self::settings();
		if ( ! $s['auto'] ) {
			return;
		}
		$last  = (string) $wpdb->get_var( 'SELECT MAX(created_at) FROM ' . mqp_table( 'archives' ) . " WHERE status <> 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$full  = MQP_Monitor::storage_used() >= MQP_Monitor::settings()['storage_max_gb'] * GB_IN_BYTES * $s['percent'] / 100;
		$weekly = ! $last || strtotime( $last ) < strtotime( mqp_now() ) - WEEK_IN_SECONDS + HOUR_IN_SECONDS;
		if ( ! $full && ! $weekly ) {
			return;
		}
		$id = self::build( $full ? 'storage' : 'weekly' );
		if ( is_wp_error( $id ) ) {
			MQP_Monitor::record( 'background', 'The archive of finished job files could not be made: ' . $id->get_error_message() );
			return;
		}
		if ( $id ) {
			$a  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'archives' ) . ' WHERE id = %d', $id ), ARRAY_A );
			$m  = MQP_Monitor::settings();
			$to = MQP_Monitor::admin_to();
			MQP_Mail::send(
				$to,
				'Archive ready to download: ' . $a['name'],
				'An archive is ready to download',
				array(
					( $full ? 'The portal\'s storage is nearly full, so ' : 'This week\'s ' ) . 'files of finished job orders were put into ' . $a['name'] . ' (' . size_format( (int) $a['size'] ) . ', ' . (int) $a['files'] . ' files of ' . (int) $a['jobs'] . ' job orders).',
					'Download it and keep it on a PC, then click "I saved it — free up the space". Until then the files stay on the website.',
				),
				array( 'Open Archives', self::page_url() )
			);
		}
	}

	public static function page_url() {
		return admin_url( 'admin.php?page=mq-portal-system&tab=uploads#archives' );
	}

	private static function action_url( $op, $id = 0 ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'op' => $op, 'id' => (int) $id ), admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/* ---------- wp-admin ---------- */

	public static function handle() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		check_admin_referer( self::ACTION );
		$op    = sanitize_key( (string) ( $_REQUEST['op'] ?? '' ) );
		$id    = (int) ( $_REQUEST['id'] ?? 0 );
		$table = mqp_table( 'archives' );
		$a     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		$msg   = '';

		switch ( $op ) {
			case 'build':
				$res = self::build( 'manual' );
				$msg = is_wp_error( $res ) ? 'error:' . $res->get_error_message() : ( $res ? 'built' : 'nothing' );
				break;

			case 'download':
				if ( ! $a || ! in_array( $a['status'], array( 'ready', 'downloaded' ), true ) ) {
					wp_die( 'This archive is no longer available.', 404 );
				}
				$path = realpath( MQP_Files::base_dir() . '/' . $a['path'] );
				$base = realpath( self::dir() );
				if ( ! $path || ! $base || 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
					wp_die( 'The zip file is missing on the server.', 404 );
				}
				$wpdb->update( $table, array( 'status' => 'downloaded', 'downloaded_at' => mqp_now() ), array( 'id' => $id ) );
				MQP_Monitor::log( 'archive_downloaded', $a['name'] );
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				nocache_headers();
				header( 'Content-Type: application/zip' );
				header( 'Content-Length: ' . filesize( $path ) );
				header( 'Content-Disposition: attachment; filename="' . $a['name'] . '"' );
				header( 'X-Content-Type-Options: nosniff' );
				while ( ob_get_level() ) {
					ob_end_clean();
				}
				readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				exit;

			case 'free':
				$res = self::free_space( $id );
				$msg = is_wp_error( $res ) ? 'error:' . $res->get_error_message() : 'freed';
				break;

			case 'cancel':
				// Not needed after all: the zip goes, the files stay where they are.
				if ( $a && in_array( $a['status'], array( 'ready', 'downloaded' ), true ) ) {
					wp_delete_file( MQP_Files::base_dir() . '/' . $a['path'] );
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'files' ) . ' SET archive_id = NULL WHERE archive_id = %d AND archived_at IS NULL', $id ) );
					$wpdb->update( $table, array( 'status' => 'cancelled' ), array( 'id' => $id ) );
					MQP_Monitor::log( 'archive_cancelled', $a['name'] );
				}
				$msg = 'cancelled';
				break;
		}
		wp_safe_redirect( add_query_arg( 'archive', rawurlencode( $msg ), admin_url( 'admin.php?page=mq-portal-system&tab=uploads' ) ) . '#archives' );
		exit;
	}

	/**
	 * Deletes an archive's originals and its zip from the hosting. Only when a copy is safe: the
	 * zip was downloaded, or Google Drive confirmed an identical copy.
	 */
	public static function free_space( $id ) {
		global $wpdb;
		$table = mqp_table( 'archives' );
		$a     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $a || ! in_array( $a['status'], array( 'ready', 'downloaded' ), true ) ) {
			return new WP_Error( 'mqp_archive', 'This archive is no longer available.' );
		}
		if ( 'downloaded' !== $a['status'] && '' === (string) $a['drive_id'] ) {
			return new WP_Error( 'mqp_archive', 'Download the archive (or let it upload to Google Drive) first, then free up the space.' );
		}
		$files = $wpdb->get_results( $wpdb->prepare( 'SELECT id, stored_path FROM ' . mqp_table( 'files' ) . ' WHERE archive_id = %d AND archived_at IS NULL', $id ), ARRAY_A );
		foreach ( $files as $f ) {
			$src = MQP_Files::base_dir() . '/' . $f['stored_path'];
			if ( is_file( $src ) ) {
				wp_delete_file( $src );
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'files' ) . ' SET archived_at = %s WHERE archive_id = %d', mqp_now(), $id ) );
		wp_delete_file( MQP_Files::base_dir() . '/' . $a['path'] );
		$wpdb->update( $table, array( 'status' => 'freed', 'freed_at' => mqp_now() ), array( 'id' => $id ) );
		delete_transient( 'mqp_storage_used' );
		MQP_Monitor::log( 'archive_freed', $a['name'] . ' · ' . count( $files ) . ' file(s) removed from the hosting · ' . size_format( (int) $a['bytes'] ) . ' freed' . ( $a['drive_id'] ? ' (copy on Google Drive)' : '' ) );
		return true;
	}

	/** The Archives card on System → Files & archives. */
	public static function card() {
		global $wpdb;
		$s      = self::settings();
		$list   = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'archives' ) . " WHERE status <> 'building' ORDER BY id DESC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wait   = self::waiting_bytes();
		$used   = MQP_Monitor::storage_used();
		$max    = MQP_Monitor::settings()['storage_max_gb'] * GB_IN_BYTES;
		$msg    = isset( $_GET['archive'] ) ? sanitize_text_field( wp_unslash( $_GET['archive'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$labels = array(
			'built'     => array( 'success', 'The archive is ready. Download it, keep it on a PC, then free up the space.' ),
			'nothing'   => array( 'info', 'Nothing to archive yet: no finished job orders older than ' . $s['days'] . ' days with files on the website.' ),
			'freed'     => array( 'success', 'Space freed: the archived files were removed from the hosting. Keep the zip safe.' ),
			'cancelled' => array( 'success', 'Archive removed. The files stay on the website.' ),
		);

		echo '<div class="mqp-card" id="archives"><h2>Archives (free up space)</h2>';
		if ( 0 === strpos( $msg, 'error:' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( substr( $msg, 6 ) ) . '</p></div>';
		} elseif ( isset( $labels[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $labels[ $msg ][0] ) . ' inline"><p>' . esc_html( $labels[ $msg ][1] ) . '</p></div>';
		}
		echo '<p>Files of job orders that were <strong>delivered or cancelled more than ' . (int) $s['days'] . ' days ago</strong> go into a zip you download and keep on a PC. After you saved it, <strong>free up the space</strong> to remove them from the hosting. The job orders keep the file names (marked "Archived"), and the zip has a list of every file. Nothing is deleted before you downloaded the zip.</p>';
		echo '<p>Storage: <strong>' . esc_html( size_format( $used ) ) . '</strong> of ' . esc_html( size_format( $max ) ) . ' · ready to archive: <strong>' . esc_html( size_format( $wait ) ) . '</strong> · ';
		echo $s['auto'] ? 'a new archive is made <strong>every week</strong>, and right away when the storage reaches ' . (int) $s['percent'] . '%; you get an email.' : 'automatic archives are <strong>off</strong> (System → Settings).'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</p><p><a class="button button-primary" href="' . esc_url( self::action_url( 'build' ) ) . '">Make an archive now</a></p>';

		if ( $list ) {
			$status = array( 'ready' => 'Ready to download', 'downloaded' => 'Downloaded — free up the space', 'freed' => 'Done (space freed)', 'cancelled' => 'Removed', 'failed' => 'Failed' );
			echo '<table class="widefat striped"><thead><tr><th>Archive</th><th>Made</th><th>Contents</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $list as $a ) {
				echo '<tr><td>' . esc_html( $a['name'] ?: '#' . $a['id'] ) . '</td><td>' . esc_html( mysql2date( 'M j, Y g:i A', $a['created_at'] ) ) . ' <span class="description">(' . esc_html( $a['reason'] ) . ')</span></td>';
				$drive = '';
				if ( $a['drive_id'] ) {
					$drive = '<br><span class="mqp-pill is-ok">On Google Drive</span>' . ( $a['drive_link'] ? ' <a href="' . esc_url( $a['drive_link'] ) . '" target="_blank" rel="noopener">Open</a>' : '' );
				} elseif ( in_array( $a['status'], array( 'ready', 'downloaded' ), true ) && MQP_GDrive::connected() ) {
					$drive = '<br><span class="description">' . ( $a['drive_error'] ? 'Drive: ' . esc_html( $a['drive_error'] ) . ' (tries again)' : ( (int) $a['drive_offset'] ? 'Uploading to Drive: ' . esc_html( size_format( (int) $a['drive_offset'] ) ) . ' of ' . esc_html( size_format( (int) $a['size'] ) ) : 'Waiting to upload to Drive' ) ) . '</span>';
				}
				echo '<td>' . (int) $a['files'] . ' files · ' . (int) $a['jobs'] . ' job orders · ' . esc_html( size_format( (int) $a['size'] ) ) . '</td><td>' . esc_html( $status[ $a['status'] ] ?? $a['status'] ) . $drive . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( in_array( $a['status'], array( 'ready', 'downloaded' ), true ) ) {
					echo '<a class="button" href="' . esc_url( self::action_url( 'download', $a['id'] ) ) . '">Download</a> ';
				}
				if ( 'downloaded' === $a['status'] || ( 'ready' === $a['status'] && $a['drive_id'] ) ) {
					echo '<a class="button button-primary" href="' . esc_url( self::action_url( 'free', $a['id'] ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( 'Is ' . $a['name'] . ' saved on your PC (and opens)? Its ' . (int) $a['files'] . ' files will be deleted from the website. This cannot be undone.' ) ) . ')">I saved it — free up the space</a> ';
				}
				if ( in_array( $a['status'], array( 'ready', 'downloaded' ), true ) ) {
					echo '<a class="button-link" href="' . esc_url( self::action_url( 'cancel', $a['id'] ) ) . '" onclick="return confirm(\'Remove this zip? The files stay on the website.\')">Remove zip</a>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . ( MQP_GDrive::connected() ? 'Google Drive is connected: new archives are uploaded and checked automatically' . ( MQP_GDrive::get()['auto_free'] ? ', then the space is freed' : '' ) . '. ' : 'Google Drive is not connected (<a href="' . esc_url( admin_url( 'admin.php?page=mq-portal-system&tab=settings' ) . '#gdrive' ) . '">connect it</a>): keep the zips on a PC and a second copy, e.g. a USB drive. ' ) . 'Archived files can\'t be opened from the portal; customers\' personal data in archives must be deleted from the PC or Drive copy when someone asks (Data Privacy Act).</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
