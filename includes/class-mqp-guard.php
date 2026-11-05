<?php
defined( 'ABSPATH' ) || exit;

/**
 * Protects the server from floods through the public forms (customer uploads, online-order
 * files, website reviews): how often one internet connection may send, and how many megabytes
 * may come in, so nobody can fill up the hosting storage.
 *
 * Limits (wp-admin → MQ Portal → System → Settings → Spam limits and storage):
 * - per connection: megabytes per day;
 * - whole website: megabytes per day from all customers together;
 * - the portal's file folder never grows past a set size, and files are refused when the
 *   server's free space runs low.
 * When a limit is reached, the customer gets a friendly message (email the files instead),
 * and the admin is told once (problem log, email alert, portal notification).
 */
class MQP_Guard {

	const DAY_OPT = 'mqp_guard_day'; // array( date => bytes ) for the whole site, today only.

	/** The internet connection of this request (hashed, never stored as is). */
	private static function who() {
		return md5( mqp_client_ip() );
	}

	private static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Per-connection counter: true when $max is reached within $seconds (and then it doesn't count).
	 * $bucket: e.g. 'review', 'review_day'.
	 */
	public static function limited( $bucket, $max, $seconds = HOUR_IN_SECONDS ) {
		$key   = 'mqp_rl_' . $bucket . '_' . self::who();
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $seconds );
		return false;
	}

	/** How many times $bucket was used by this connection (within its time window). */
	public static function count( $bucket ) {
		return (int) get_transient( 'mqp_rl_' . $bucket . '_' . self::who() );
	}

	/** Counts one use of $bucket for this connection; the count resets $seconds after the first. */
	public static function bump( $bucket, $seconds = HOUR_IN_SECONDS ) {
		$key = 'mqp_rl_' . $bucket . '_' . self::who();
		set_transient( $key, self::count( $bucket ) + 1, $seconds );
	}

	/** Megabytes received today from this connection / from everyone. */
	public static function bytes_today( $mine = false ) {
		if ( $mine ) {
			return (int) get_transient( 'mqp_rl_bytes_' . self::who() . '_' . self::today() );
		}
		$day = (array) get_option( self::DAY_OPT, array() );
		return (int) ( $day[ self::today() ] ?? 0 );
	}

	/** Size of the portal's file folder, kept up to date between the hourly recounts. */
	public static function storage_used() {
		return MQP_Monitor::storage_used();
	}

	/**
	 * Whether $bytes more may come in now. WP_Error with a message for the customer when not.
	 */
	public static function allow_bytes( $bytes ) {
		$s     = MQP_Monitor::settings();
		$bytes = max( 0, (int) $bytes );
		$sorry = 'We can\'t accept more files right now. Please try again tomorrow, or email or message us your files.';

		if ( self::bytes_today( true ) + $bytes > (int) $s['limit_mb_ip'] * MB_IN_BYTES ) {
			return new WP_Error( 'mqp_storage', 'You have reached today\'s upload limit. Please try again tomorrow, or email or message us your files.', array( 'status' => 429 ) );
		}
		if ( self::bytes_today() + $bytes > (int) $s['limit_mb_day'] * MB_IN_BYTES ) {
			self::warn( 'Customer uploads paused for today: the daily limit of ' . (int) $s['limit_mb_day'] . ' MB from all customers was reached.' );
			return new WP_Error( 'mqp_storage', $sorry, array( 'status' => 429 ) );
		}
		if ( self::storage_used() + $bytes > (int) $s['storage_max_gb'] * GB_IN_BYTES ) {
			if ( ! wp_next_scheduled( 'mqp_archive_now' ) ) {
				wp_schedule_single_event( time(), 'mqp_archive_now' );
			}
			self::warn( 'Customer uploads stopped: the portal\'s files reached the storage limit of ' . (int) $s['storage_max_gb'] . ' GB.' );
			return new WP_Error( 'mqp_storage', $sorry, array( 'status' => 429 ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( MQP_Files::base_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $free && $free - $bytes < (float) $s['min_free_gb'] * GB_IN_BYTES ) {
			self::warn( 'Customer uploads stopped: the server has less than ' . (int) $s['min_free_gb'] . ' GB of free space left.' );
			return new WP_Error( 'mqp_storage', $sorry, array( 'status' => 429 ) );
		}
		return true;
	}

	/** Counts $bytes that were just saved. */
	public static function add_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		$key   = 'mqp_rl_bytes_' . self::who() . '_' . self::today();
		set_transient( $key, (int) get_transient( $key ) + $bytes, DAY_IN_SECONDS );

		$today = self::today();
		$day   = (array) get_option( self::DAY_OPT, array() );
		update_option( self::DAY_OPT, array( $today => (int) ( $day[ $today ] ?? 0 ) + $bytes ), false );

		$used = get_transient( 'mqp_storage_used' );
		if ( false !== $used ) {
			set_transient( 'mqp_storage_used', (int) $used + $bytes, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Daily: removes the files of customer uploads that were dismissed (not turned into a job
	 * order) and are older than the set number of days. The upload record stays, marked as cleaned.
	 */
	public static function daily() {
		global $wpdb;
		$days = (int) MQP_Monitor::settings()['dismissed_days'];
		if ( $days <= 0 ) {
			return;
		}
		$files = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.id, f.stored_path FROM ' . mqp_table( 'files' ) . ' f JOIN ' . mqp_table( 'uploads' ) . " u ON u.id = f.upload_id
				 WHERE u.status = 'dismissed' AND f.job_id IS NULL AND u.created_at < %s LIMIT 2000",
				wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		foreach ( $files as $f ) {
			$path = MQP_Files::base_dir() . '/' . $f['stored_path'];
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			$wpdb->delete( mqp_table( 'files' ), array( 'id' => (int) $f['id'] ) );
		}
		if ( $files ) {
			delete_transient( 'mqp_storage_used' );
			MQP_Monitor::log( 'system', 'Deleted ' . count( $files ) . ' file(s) of dismissed customer uploads older than ' . $days . ' days' );
		}
	}

	/** Tells the admin (problem log → email alert and portal notification), once per kind of limit. */
	private static function warn( $message ) {
		MQP_Monitor::record( 'background', $message );
	}
}
