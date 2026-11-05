<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff login and the attendance time clock.
 *
 * Login only opens the portal. Attendance is recorded on the Time Clock page:
 * clock in → (break start → break end)* → clock out. Clocking in needs the person
 * to be at a shop (shop Wi-Fi or GPS) unless they may clock in anywhere, plus the
 * optional time window.
 * wp-login.php and wp-admin are for WordPress administrators only: portal staff (including the
 * Owner) always sign in through the portal.
 */
class MQP_Auth {

	private static $portal_login = false;

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'guard_wp_login' ), 100, 3 );
		add_action( 'admin_init', array( __CLASS__, 'keep_staff_out_of_wp_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
	}

	/** Portal staff: has portal access and is not a WordPress administrator. */
	public static function portal_only( WP_User $user ) {
		return MQP_Roles::is_staff( $user ) && ! in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * wp-login.php (and XML-RPC, application passwords…) is for WordPress administrators only.
	 * Portal staff and disabled accounts get the same answer whether the password was right or
	 * wrong, so these logins can't be used to guess passwords around the portal's lockout.
	 */
	public static function guard_wp_login( $user, $username = '', $password = '' ) {
		if ( self::$portal_login ) {
			return $user;
		}
		$who = $user instanceof WP_User ? $user : null;
		if ( ! $who && is_string( $username ) && '' !== $username ) {
			$who = get_user_by( 'login', $username );
			if ( ! $who && is_email( $username ) ) {
				$who = get_user_by( 'email', $username );
			}
		}
		if ( ! $who instanceof WP_User ) {
			return $user;
		}
		if ( get_user_meta( $who->ID, 'mqp_disabled', true ) || self::portal_only( $who ) ) {
			return new WP_Error( 'mqp_use_portal', 'This sign-in page is for site administrators. Staff sign in at ' . MQP_Portal::url() );
		}
		return $user;
	}

	/** Portal staff never use wp-admin; they are sent to the portal. */
	public static function keep_staff_out_of_wp_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( self::portal_only( wp_get_current_user() ) ) {
			wp_safe_redirect( MQP_Portal::url() );
			exit;
		}
	}

	public static function hide_admin_bar( $show ) {
		if ( is_user_logged_in() && self::portal_only( wp_get_current_user() ) ) {
			return false;
		}
		return $show;
	}

	/** Portal login. Returns WP_User or WP_Error. Does not touch attendance. */
	public static function login( $username, $password ) {
		// Brute-force guard: N wrong passwords per IP + username lock that pair for a while (wp-admin → MQ Portal → System → Settings).
		$sys      = MQP_Monitor::settings();
		$max      = (int) $sys['login_attempts'];
		$minutes  = (int) $sys['lock_minutes'];
		$lock_key = 'mqp_fail_' . md5( mqp_client_ip() . '|' . strtolower( $username ) );
		$fails    = (int) get_transient( $lock_key );
		// Many wrong passwords for one username from different connections also lock it for a while.
		$name_key = 'mqp_fail_name_' . md5( strtolower( $username ) );
		$name_max = $max * 4;
		if ( $fails >= $max || (int) get_transient( $name_key ) >= $name_max ) {
			return new WP_Error( 'mqp_login', "Too many wrong attempts. Try again in $minutes minutes." );
		}

		self::$portal_login = true; // Let guard_wp_login() through for portal logins.
		$user               = wp_authenticate( $username, $password );
		self::$portal_login = false;
		if ( is_wp_error( $user ) ) {
			// Someone who asked for an account that isn't approved yet gets a clear answer.
			if ( ! username_exists( $username ) && ! email_exists( $username ) && MQP_Signup::pending_for( $username ) ) {
				return new WP_Error( 'mqp_login', 'Your account request is still waiting for approval. You will get an email once an admin approves it.' );
			}
			set_transient( $lock_key, $fails + 1, $minutes * MINUTE_IN_SECONDS );
			$by_name = (int) get_transient( $name_key ) + 1;
			set_transient( $name_key, $by_name, $minutes * MINUTE_IN_SECONDS );
			if ( $by_name === $name_max ) {
				MQP_Monitor::record( 'background', 'Login locked for one username: ' . $name_max . ' wrong passwords from different connections. Someone may be guessing a staff password.' );
			}
			$locked = $fails + 1 >= $max;
			mqp_log( 'login', array( 'username' => $username, 'result' => $locked ? 'locked' : 'failed', 'reason' => $locked ? "Wrong password $max times: locked for $minutes minutes" : 'Wrong username or password', 'user_id' => 0 ) );
			return new WP_Error( 'mqp_login', 'Wrong username or password.' );
		}
		if ( get_user_meta( $user->ID, 'mqp_disabled', true ) ) {
			return new WP_Error( 'mqp_login', 'This account has been disabled.' );
		}
		if ( ! $user->has_cap( 'mq_access_portal' ) ) {
			return new WP_Error( 'mqp_login', 'This account has no portal access.' );
		}
		$closed = MQP_Monitor::maintenance_for( $user );
		if ( $closed ) {
			return new WP_Error( 'mqp_login', $closed );
		}

		delete_transient( $lock_key );
		delete_transient( $name_key );
		MQP_Session::start( $user ); // Portal sign-in only: the wp-admin login is left as it is.
		mqp_log( 'login', array( 'user_id' => $user->ID, 'username' => $user->user_login ) );
		return $user;
	}

	/* ---------- Clock-in rules ---------- */

	/**
	 * Clock-in rules: the optional time window, and "be at a shop" (shop Wi-Fi or GPS).
	 * Returns the shop the person is at (array: branch_id, by) — empty when unknown — or WP_Error.
	 */
	public static function check_rules( WP_User $user, array $geo ) {
		$s = MQP_Settings::all();
		if ( ! $user->has_cap( 'mq_bypass_attendance' ) && $s['att_window_enabled'] && ! self::in_window( current_time( 'H:i' ), $s['att_window_start'], $s['att_window_end'] ) ) {
			return new WP_Error( 'mqp_window', sprintf( 'Clock-in is only allowed from %s to %s.', self::fmt_time( $s['att_window_start'] ), self::fmt_time( $s['att_window_end'] ) ) );
		}

		$at = self::locate( $geo );
		if ( $at || ! self::shop_rule_applies( $user ) ) {
			return $at ? $at : array();
		}
		$shops   = self::shops();
		$has_gps = (bool) array_filter( $shops, function ( $b ) {
			return null !== $b->lat;
		} );
		if ( $has_gps && self::has_geo( $geo ) ) {
			$near = self::nearest( $geo, $shops );
			return new WP_Error( 'mqp_geo', sprintf( 'You appear to be %s from the nearest shop (%s). You need to be at the shop to clock in.', $near['dist'] >= 1000 ? round( $near['dist'] / 1000, 1 ) . ' km' : round( $near['dist'] ) . ' m', $near['shop']->name ) );
		}
		return new WP_Error(
			'mqp_location',
			$has_gps
				? 'You need to be at the shop to clock in. Connect to the shop Wi-Fi, or allow location access when your browser asks, then try again.'
				: 'You need to be at the shop to clock in. Connect to the shop Wi-Fi, then try again.'
		);
	}

	/** True when this person must be at a shop to clock in (setting on, not exempt, and a shop has Wi-Fi/GPS set up). */
	public static function shop_rule_applies( WP_User $user ) {
		return MQP_Settings::get( 'att_require_location' ) && ! $user->has_cap( 'mq_clock_anywhere' ) && self::shops();
	}

	/** Active shops (branches) with a Wi-Fi IP and/or GPS position set up. */
	public static function shops() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, name, lat, lng, radius_m, allowed_ips FROM ' . mqp_table( 'branches' ) . " WHERE active = 1 AND ( ( allowed_ips IS NOT NULL AND allowed_ips <> '' ) OR ( lat IS NOT NULL AND lng IS NOT NULL ) ) ORDER BY id" );
	}

	private static function has_geo( array $geo ) {
		return isset( $geo['lat'], $geo['lng'] ) && '' !== $geo['lat'] && '' !== $geo['lng'];
	}

	private static function nearest( array $geo, array $shops ) {
		$best = null;
		foreach ( $shops as $b ) {
			if ( null === $b->lat || null === $b->lng ) {
				continue;
			}
			$d = mqp_distance_m( (float) $geo['lat'], (float) $geo['lng'], (float) $b->lat, (float) $b->lng );
			if ( ! $best || $d < $best['dist'] ) {
				$best = array( 'shop' => $b, 'dist' => $d );
			}
		}
		return $best;
	}

	/**
	 * Which shop the request comes from: its Wi-Fi (public IP) first, then GPS.
	 * Returns array( 'branch_id', 'name', 'by' => 'wifi'|'gps' ) or null.
	 */
	public static function locate( array $geo ) {
		$ip    = mqp_client_ip();
		$shops = self::shops();
		foreach ( $shops as $b ) {
			$ips = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $b->allowed_ips ) ) );
			if ( $ip && in_array( $ip, $ips, true ) ) {
				return array( 'branch_id' => (int) $b->id, 'name' => $b->name, 'by' => 'wifi' );
			}
		}
		if ( self::has_geo( $geo ) ) {
			$near = self::nearest( $geo, $shops );
			// Allow for the phone's reported accuracy, capped so a vague fix can't pass from far away.
			if ( $near && $near['dist'] <= (int) $near['shop']->radius_m + min( 100, (int) ( $geo['accuracy'] ?? 0 ) ) ) {
				return array( 'branch_id' => (int) $near['shop']->id, 'name' => $near['shop']->name, 'by' => 'gps' );
			}
		}
		return null;
	}

	/** True when $now (H:i) is inside [start, end); handles windows that cross midnight. */
	public static function in_window( $now, $start, $end ) {
		if ( $start === $end ) {
			return true;
		}
		if ( $start < $end ) {
			return $now >= $start && $now < $end;
		}
		return $now >= $start || $now < $end;
	}

	private static function fmt_time( $hm ) {
		return gmdate( 'g:i A', strtotime( "2000-01-01 $hm" ) );
	}

	/* ---------- Time clock ---------- */

	public static function open_attendance( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'attendance' ) . ' WHERE user_id = %d AND time_out IS NULL ORDER BY id DESC LIMIT 1', $user_id ), ARRAY_A );
	}

	public static function breaks( $attendance_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, break_start, break_end FROM ' . mqp_table( 'attendance_breaks' ) . ' WHERE attendance_id = %d ORDER BY id', $attendance_id ), ARRAY_A );
	}

	/** Break minutes of a shift (open break counted up to $until). */
	public static function break_minutes( array $breaks, $until ) {
		$total = 0;
		foreach ( $breaks as $b ) {
			$end    = $b['break_end'] ? $b['break_end'] : $until;
			$total += max( 0, strtotime( $end ) - strtotime( $b['break_start'] ) );
		}
		return (int) round( $total / 60 );
	}

	/** Worked minutes of a shift: (out or now) − in − breaks. */
	public static function worked_minutes( array $shift, array $breaks ) {
		$end   = $shift['time_out'] ? $shift['time_out'] : mqp_now();
		$gross = max( 0, strtotime( $end ) - strtotime( $shift['time_in'] ) );
		return max( 0, (int) round( $gross / 60 ) - self::break_minutes( $breaks, $end ) );
	}

	/** Current clock state for a user, for the header pill and the Time Clock page. */
	public static function status( $user_id ) {
		$shift = self::open_attendance( $user_id );
		if ( ! $shift ) {
			return array( 'state' => 'off', 'shift' => null, 'breaks' => array(), 'worked_minutes' => 0, 'break_minutes' => 0 );
		}
		$breaks   = self::breaks( (int) $shift['id'] );
		$on_break = false;
		foreach ( $breaks as $b ) {
			if ( ! $b['break_end'] ) {
				$on_break = $b['break_start'];
			}
		}
		return array(
			'state'          => $on_break ? 'break' : 'working',
			'since'          => $on_break ? $on_break : $shift['time_in'],
			'shift'          => array( 'id' => (int) $shift['id'], 'time_in' => $shift['time_in'], 'branch_id' => $shift['branch_id'] ? (int) $shift['branch_id'] : null ),
			'breaks'         => $breaks,
			'worked_minutes' => self::worked_minutes( $shift, $breaks ),
			'break_minutes'  => self::break_minutes( $breaks, mqp_now() ),
			'now'            => mqp_now(),
		);
	}

	/**
	 * Clock action for the current user: in | break_start | break_end | out.
	 * Returns the new status or WP_Error.
	 */
	public static function clock( WP_User $user, $action, array $geo ) {
		global $wpdb;
		$table = mqp_table( 'attendance' );
		$btab  = mqp_table( 'attendance_breaks' );
		$now   = mqp_now();
		// One clock action per person at a time.
		$lock = 'mqp_clock_' . (int) $user->ID;
		$wpdb->suppress_errors( true );
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // null where the database has no locks (then it just carries on).
		$wpdb->suppress_errors( false );
		if ( '0' === (string) $got ) {
			return new WP_Error( 'mqp_clock', 'Please try again.' );
		}
		$res = self::clock_locked( $user, $action, $geo, $table, $btab, $now );
		if ( '1' === (string) $got ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $res;
	}

	private static function clock_locked( WP_User $user, $action, array $geo, $table, $btab, $now ) {
		global $wpdb;
		$shift = self::open_attendance( $user->ID );
		$lat   = isset( $geo['lat'] ) && '' !== $geo['lat'] && null !== $geo['lat'] ? (float) $geo['lat'] : null;
		$lng   = isset( $geo['lng'] ) && '' !== $geo['lng'] && null !== $geo['lng'] ? (float) $geo['lng'] : null;
		$log   = array( 'user_id' => $user->ID, 'username' => $user->user_login, 'lat' => $lat, 'lng' => $lng );

		switch ( $action ) {
			case 'in':
				if ( $shift ) {
					return new WP_Error( 'mqp_clock', 'You are already clocked in.' );
				}
				$check = self::check_rules( $user, $geo );
				if ( is_wp_error( $check ) ) {
					mqp_log( 'clock_in', $log + array( 'result' => 'blocked', 'reason' => $check->get_error_message() ) );
					return $check;
				}
				// The shift belongs to the shop they clocked in at, else their home branch.
				$branch_id = ! empty( $check['branch_id'] ) ? $check['branch_id'] : (int) get_user_meta( $user->ID, 'mqp_branch_id', true );
				if ( ! empty( $check['by'] ) ) {
					$log['reason'] = 'At ' . $check['name'] . ( 'wifi' === $check['by'] ? ' (shop Wi-Fi)' : ' (GPS)' );
				}
				$wpdb->insert(
					$table,
					array(
						'user_id'     => $user->ID,
						'branch_id'   => $branch_id ? $branch_id : null,
						'time_in'     => $now,
						'in_ip'       => mqp_client_ip(),
						'in_lat'      => $lat,
						'in_lng'      => $lng,
						'in_accuracy' => isset( $geo['accuracy'] ) && '' !== $geo['accuracy'] && null !== $geo['accuracy'] ? (int) $geo['accuracy'] : null,
					)
				);
				break;

			case 'break_start':
				if ( ! $shift ) {
					return new WP_Error( 'mqp_clock', 'Clock in first.' );
				}
				if ( 'break' === self::status( $user->ID )['state'] ) {
					return new WP_Error( 'mqp_clock', 'You are already on break.' );
				}
				$wpdb->insert( $btab, array( 'attendance_id' => $shift['id'], 'break_start' => $now ) );
				break;

			case 'break_end':
				if ( ! $shift || 'break' !== self::status( $user->ID )['state'] ) {
					return new WP_Error( 'mqp_clock', 'You are not on break.' );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $btab SET break_end = %s WHERE attendance_id = %d AND break_end IS NULL", $now, $shift['id'] ) );
				break;

			case 'out':
				if ( ! $shift ) {
					return new WP_Error( 'mqp_clock', 'You are not clocked in.' );
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $btab SET break_end = %s WHERE attendance_id = %d AND break_end IS NULL", $now, $shift['id'] ) );
				$wpdb->update( $table, array( 'time_out' => $now, 'out_ip' => mqp_client_ip(), 'out_lat' => $lat, 'out_lng' => $lng ), array( 'id' => $shift['id'] ) );
				break;

			default:
				return new WP_Error( 'mqp_clock', 'Unknown action.' );
		}
		mqp_log( 'clock_' . $action, $log );
		return self::status( $user->ID );
	}

	/** Daily cron: closes shifts (and their breaks) nobody clocked out of. */
	public static function close_stale_attendance() {
		global $wpdb;
		$hours = max( 1, (int) MQP_Settings::get( 'att_auto_close_hours' ) );
		$table = mqp_table( 'attendance' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, time_in FROM $table WHERE time_out IS NULL AND time_in < %s", gmdate( 'Y-m-d H:i:s', strtotime( mqp_now() ) - $hours * HOUR_IN_SECONDS ) ) );
		foreach ( $rows as $r ) {
			$out = gmdate( 'Y-m-d H:i:s', strtotime( $r->time_in ) + $hours * HOUR_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'attendance_breaks' ) . ' SET break_end = %s WHERE attendance_id = %d AND break_end IS NULL', $out, $r->id ) );
			$wpdb->update( $table, array( 'time_out' => $out, 'auto_closed' => 1 ), array( 'id' => $r->id ) );
		}
	}
}
