<?php
defined( 'ABSPATH' ) || exit;

/**
 * Everything about the logged-in person: time clock, personal home dashboard,
 * profile and settings (password, preferences).
 */
class MQP_REST_Me {

	const PREFS = array(
		'landing'    => array( 'home', 'clock', 'sales', 'creatives', 'production', 'pos', 'admin' ),
		'auto_print' => array( true, false ),
		'density'    => array( 'comfortable', 'compact' ),
	);

	public static function routes() {
		$ns   = MQP_REST::NS;
		$perm = MQP_REST::can( 'mq_access_portal' );
		register_rest_route( $ns, '/me/clock', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'clock_status' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clock' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, '/me/home', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'home' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/me/profile', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'profile' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_profile' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, '/me/password', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'password' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/me/preferences', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_preferences' ),
			'permission_callback' => $perm,
		) );
	}

	/* ---------- Time clock ---------- */

	public static function clock_status() {
		$uid    = get_current_user_id();
		$status = MQP_Auth::status( $uid );
		$status['week']  = self::week( $uid );
		$status['rules'] = self::rules_for( wp_get_current_user() );
		return $status;
	}

	public static function clock( WP_REST_Request $req ) {
		$res = MQP_Auth::clock(
			wp_get_current_user(),
			sanitize_key( (string) $req->get_param( 'action' ) ),
			array(
				'lat'      => $req->get_param( 'lat' ),
				'lng'      => $req->get_param( 'lng' ),
				'accuracy' => $req->get_param( 'accuracy' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return MQP_REST::error( $res->get_error_message(), 403 );
		}
		return self::clock_status();
	}

	/** What the Time Clock page should tell this person about the rules. */
	private static function rules_for( WP_User $user ) {
		$s         = MQP_Settings::all();
		$any_hours = $user->has_cap( 'mq_bypass_attendance' );
		$shop_only = MQP_Auth::shop_rule_applies( $user );
		$at        = $shop_only ? MQP_Auth::locate( array() ) : null; // Shop Wi-Fi check (no GPS yet).
		$has_gps   = $shop_only && array_filter( MQP_Auth::shops(), function ( $b ) {
			return null !== $b->lat;
		} );
		return array(
			'any_hours' => $any_hours,
			'window'    => ! $any_hours && $s['att_window_enabled'] ? array( $s['att_window_start'], $s['att_window_end'] ) : null,
			'shop_only' => (bool) $shop_only,
			'at_shop'   => $at ? $at['name'] : null,
			// The page asks the browser for its location only when Wi-Fi didn't already place them at a shop.
			'needs_gps' => $shop_only && ! $at && (bool) $has_gps,
		);
	}

	/** Last 7 days of worked minutes for a user, oldest first. */
	public static function week( $user_id ) {
		global $wpdb;
		$from  = gmdate( 'Y-m-d', strtotime( mqp_today() . ' -6 days' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'attendance' ) . ' WHERE user_id = %d AND time_in >= %s ORDER BY time_in', $user_id, "$from 00:00:00" ), ARRAY_A );
		$days  = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$d          = gmdate( 'Y-m-d', strtotime( mqp_today() . " -$i days" ) );
			$days[ $d ] = array( 'date' => $d, 'worked_minutes' => 0, 'break_minutes' => 0, 'shifts' => array() );
		}
		foreach ( $rows as $r ) {
			$d = substr( $r['time_in'], 0, 10 );
			if ( ! isset( $days[ $d ] ) ) {
				continue;
			}
			$breaks                         = MQP_Auth::breaks( (int) $r['id'] );
			$worked                         = MQP_Auth::worked_minutes( $r, $breaks );
			$brk                            = MQP_Auth::break_minutes( $breaks, $r['time_out'] ? $r['time_out'] : mqp_now() );
			$days[ $d ]['worked_minutes']  += $worked;
			$days[ $d ]['break_minutes']   += $brk;
			$days[ $d ]['shifts'][]         = array( 'time_in' => $r['time_in'], 'time_out' => $r['time_out'], 'auto_closed' => (int) $r['auto_closed'], 'breaks' => $breaks );
		}
		return array_values( $days );
	}

	/* ---------- Home dashboard (sections depend on the person's permissions) ---------- */

	public static function home() {
		global $wpdb;
		$uid   = get_current_user_id();
		$today = mqp_today();
		$month = substr( $today, 0, 7 ) . '-01';
		$day   = array( "$today 00:00:00", "$today 23:59:59" );
		$jobs  = mqp_table( 'jobs' );
		$docs  = mqp_table( 'documents' );
		$out   = array( 'today' => $today, 'clock' => MQP_Auth::status( $uid ), 'week' => self::week( $uid ) );

		$job_list = function ( $where, $args = array(), $limit = 6 ) use ( $wpdb, $jobs ) {
			$sql = "SELECT j.id, j.number, j.title, j.customer_name, j.stage, j.priority, j.due_date, j.needs_editing FROM $jobs j WHERE $where ORDER BY j.priority DESC, j.due_date IS NULL, j.due_date, j.id LIMIT $limit";
			return array_map( function ( $r ) {
				return mqp_cast_row( $r, array( 'id', 'priority', 'needs_editing' ) );
			}, $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) );
		};
		$count = function ( $sql, $args = array() ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		};
		$sum = function ( $sql, $args = array() ) use ( $wpdb ) {
			return (float) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		};

		if ( current_user_can( 'mq_sales' ) ) {
			$mine = '(d.created_by = %d OR d.sales_person_id = %d)';
			$out['sales'] = array(
				'sales_today'      => $sum( "SELECT COALESCE(SUM(d.total),0) FROM $docs d WHERE d.type = 'invoice' AND d.status <> 'void' AND $mine AND d.created_at BETWEEN %s AND %s", array( $uid, $uid, $day[0], $day[1] ) ),
				'sales_month'      => $sum( "SELECT COALESCE(SUM(d.total),0) FROM $docs d WHERE d.type = 'invoice' AND d.status <> 'void' AND $mine AND d.created_at >= %s", array( $uid, $uid, "$month 00:00:00" ) ),
				'open_quotations'  => $count( "SELECT COUNT(*) FROM $docs d WHERE d.type IN ('quotation','billing') AND d.status = 'open' AND $mine", array( $uid, $uid ) ),
				'unpaid_balance'   => $sum( "SELECT COALESCE(SUM(d.total - d.amount_paid),0) FROM $docs d WHERE d.type = 'invoice' AND d.status IN ('unpaid','partial') AND $mine", array( $uid, $uid ) ),
				'new_uploads'      => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) . " WHERE status = 'new'" ),
				'orders_to_verify' => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) . " WHERE payment_status = 'submitted'" ),
				'orders_to_price'  => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) . ' o JOIN ' . $docs . " d ON d.id = o.document_id WHERE o.payment_status = 'pending' AND d.total <= 0 AND d.status <> 'void'" ),
				'jobs'             => $job_list( "j.stage NOT IN ('delivered','cancelled') AND (j.sales_person_id = %d OR j.created_by = %d)", array( $uid, $uid ) ),
				'blocked_jobs'     => $count( "SELECT COUNT(*) FROM $jobs j JOIN $docs d ON d.id = j.document_id WHERE j.stage = 'sales' AND (j.sales_person_id = %d OR d.created_by = %d)", array( $uid, $uid ) ),
				'commission_pending' => $sum( 'SELECT COALESCE(SUM(amount),0) FROM ' . mqp_table( 'commissions' ) . " WHERE sales_person_id = %d AND status = 'pending'", array( $uid ) ),
				'commission_earned'  => $sum( 'SELECT COALESCE(SUM(amount),0) FROM ' . mqp_table( 'commissions' ) . " WHERE sales_person_id = %d AND status = 'earned'", array( $uid ) ),
			);
		}

		if ( current_user_can( 'mq_creatives' ) ) {
			$out['creatives'] = array(
				'waiting'       => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'creatives'" ),
				'needs_editing' => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'creatives' AND needs_editing = 1" ),
				'approved_today'=> $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'job_events' ) . " WHERE action = 'approve_files' AND created_at BETWEEN %s AND %s", $day ),
				'jobs'          => $job_list( "j.stage = 'creatives'" ),
			);
		}

		if ( current_user_can( 'mq_production' ) || current_user_can( 'mq_qc' ) || current_user_can( 'mq_view_board' ) ) {
			$out['production'] = array(
				'production'    => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'production'" ),
				'qc'            => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'qc'" ),
				'ready'         => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'ready'" ),
				'rush'          => $count( "SELECT COUNT(*) FROM $jobs WHERE priority = 1 AND stage IN ('creatives','production','qc')" ),
				'overdue'       => $count( "SELECT COUNT(*) FROM $jobs WHERE due_date < %s AND stage NOT IN ('delivered','cancelled')", array( $today ) ),
				'delivered_today' => $count( "SELECT COUNT(*) FROM $jobs WHERE stage = 'delivered' AND delivered_at BETWEEN %s AND %s", $day ),
				'jobs'          => $job_list( "j.stage IN ('production','qc')" ),
			);
		}

		if ( current_user_can( 'mq_pos' ) ) {
			$branch = (int) get_user_meta( $uid, 'mqp_branch_id', true );
			$ps     = mqp_table( 'pos_sales' );
			$bw     = $branch ? $wpdb->prepare( ' AND branch_id = %d', $branch ) : '';
			$out['pos'] = array(
				'branch'        => $branch ? MQP_REST_Me::branch_name( $branch ) : 'All branches',
				'total_today'   => $sum( "SELECT COALESCE(SUM(total),0) FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s$bw", $day ),
				'receipts_today'=> $count( "SELECT COUNT(*) FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s$bw", $day ),
				'my_total'      => $sum( "SELECT COALESCE(SUM(total),0) FROM $ps WHERE status = 'completed' AND cashier_id = %d AND sold_at BETWEEN %s AND %s", array( $uid, $day[0], $day[1] ) ),
				'voided_today'  => $count( "SELECT COUNT(*) FROM $ps WHERE status = 'void' AND sold_at BETWEEN %s AND %s$bw", $day ),
			);
		}

		if ( current_user_can( 'mq_view_dashboard' ) || current_user_can( 'mq_finance' ) ) {
			$out['company'] = array(
				'overdue'  => $job_list( "j.due_date < %s AND j.stage NOT IN ('delivered','cancelled')", array( $today ), 8 ),
				'pipeline' => $wpdb->get_results( "SELECT stage, COUNT(*) AS count FROM $jobs WHERE stage NOT IN ('delivered','cancelled') GROUP BY stage", OBJECT_K ),
			);
			if ( current_user_can( 'mq_view_dashboard' ) ) {
				$req = new WP_REST_Request( 'GET' );
				$ov  = MQP_REST_Dashboard::overview( $req );
				$out['company']['sales_today']     = $ov['total'];
				$out['company']['collected_today'] = $ov['collected'];
				$out['company']['brands']          = array_map( function ( $b ) {
					return array( 'name' => $b['name'], 'total' => $b['total'] );
				}, $ov['brands'] );
			}
			if ( current_user_can( 'mq_finance' ) ) {
				$out['company']['commissions_to_pay'] = $sum( 'SELECT COALESCE(SUM(amount),0) FROM ' . mqp_table( 'commissions' ) . " WHERE status = 'earned'" );
				$out['company']['receivables']        = $sum( "SELECT COALESCE(SUM(total - amount_paid),0) FROM $docs WHERE type = 'invoice' AND status IN ('unpaid','partial')" );
			}
		}

		// Quotation requests from the website still waiting for prices (shown first on the dashboard).
		if ( current_user_can( 'mq_sales' ) || current_user_can( 'mq_view_dashboard' ) ) {
			$qw   = "d.type = 'quotation' AND d.status = 'pricing'";
			$qa   = array();
			if ( ! MQP_REST_Documents::sees_all() ) {
				$qw  .= ' AND (d.created_by = %d OR d.sales_person_id = %d OR (d.created_by = 0 AND d.sales_person_id IS NULL))';
				$qa   = array( $uid, $uid );
			}
			$late = wp_date( 'Y-m-d H:i:s', time() - MQP_Quote::REMIND_HOURS * HOUR_IN_SECONDS );
			$out['quote_requests'] = array(
				'count' => $count( "SELECT COUNT(*) FROM $docs d WHERE $qw", $qa ),
				'late'  => $count( "SELECT COUNT(*) FROM $docs d WHERE $qw AND d.created_at <= %s", array_merge( $qa, array( $late ) ) ),
				'items' => array_map( function ( $r ) use ( $late ) {
					$r            = mqp_cast_row( $r, array( 'id' ) );
					$r['is_late'] = $r['created_at'] <= $late;
					return $r;
				}, $wpdb->get_results( $wpdb->prepare( "SELECT d.id, d.number, d.customer_name, d.customer_company, d.title, d.created_at FROM $docs d WHERE $qw ORDER BY d.created_at ASC, d.id ASC LIMIT %d", array_merge( $qa, array( 6 ) ) ), ARRAY_A ) ),
			);
		}

		// Recent job activity this person is likely to care about.
		$where = '1=1';
		$args  = array();
		if ( ! current_user_can( 'mq_view_dashboard' ) && ! current_user_can( 'mq_view_all_sales' ) && ! current_user_can( 'mq_creatives' ) && ! current_user_can( 'mq_production' ) && ! current_user_can( 'mq_qc' ) ) {
			$where = '(j.sales_person_id = %d OR j.created_by = %d)';
			$args  = array( $uid, $uid );
		}
		if ( current_user_can( 'mq_sales' ) || current_user_can( 'mq_creatives' ) || current_user_can( 'mq_production' ) || current_user_can( 'mq_qc' ) || current_user_can( 'mq_view_dashboard' ) ) {
			$sql             = 'SELECT e.action, e.to_stage, e.note, e.created_at, e.user_id, j.id AS job_id, j.number FROM ' . mqp_table( 'job_events' ) . " e JOIN $jobs j ON j.id = e.job_id WHERE $where ORDER BY e.id DESC LIMIT 8";
			$out['activity'] = array_map( function ( $e ) {
				$e         = mqp_cast_row( $e, array( 'job_id' ) );
				$e['user'] = mqp_user_brief( (int) $e['user_id'] );
				unset( $e['user_id'] );
				return $e;
			}, $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) );
		}
		return $out;
	}

	public static function branch_name( $id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', $id ) );
	}

	/** Staff currently clocked in, with break state. */
	public static function on_duty() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT a.*, br.name AS branch_name FROM ' . mqp_table( 'attendance' ) . ' a LEFT JOIN ' . mqp_table( 'branches' ) . ' br ON br.id = a.branch_id WHERE a.time_out IS NULL ORDER BY a.time_in',
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$breaks   = MQP_Auth::breaks( (int) $r['id'] );
			$on_break = false;
			foreach ( $breaks as $b ) {
				$on_break = $on_break || ! $b['break_end'];
			}
			$out[] = array(
				'user'           => mqp_user_brief( (int) $r['user_id'] ),
				'branch'         => $r['branch_name'],
				'time_in'        => $r['time_in'],
				'state'          => $on_break ? 'break' : 'working',
				'worked_minutes' => MQP_Auth::worked_minutes( $r, $breaks ),
			);
		}
		return $out;
	}

	/* ---------- Profile & settings ---------- */

	public static function profile() {
		$u     = get_userdata( get_current_user_id() ); // Fresh copy: the cached current user is stale right after an update.
		$roles = MQP_Roles::roles();
		$role  = MQP_Roles::user_role( $u );
		$caps  = array();
		foreach ( MQP_Roles::capabilities() as $cap => $def ) {
			if ( current_user_can( $cap ) ) {
				$caps[] = array( 'group' => $def[0], 'label' => $def[1] );
			}
		}
		$branch = (int) get_user_meta( $u->ID, 'mqp_branch_id', true );
		$brand  = (int) get_user_meta( $u->ID, 'mqp_brand_id', true );
		return array(
			'id'          => $u->ID,
			'username'    => $u->user_login,
			'name'        => $u->display_name,
			'avatar'      => MQP_Avatar::version( $u->ID ),
			'first_name'  => $u->first_name,
			'last_name'   => $u->last_name,
			'email'       => $u->user_email,
			'phone'       => (string) get_user_meta( $u->ID, 'mqp_phone', true ),
			'role'        => $role,
			'role_name'   => isset( $roles[ $role ] ) ? $roles[ $role ]['name'] : ( 'administrator' === $role ? 'WordPress Administrator' : '—' ),
			'branch'      => $branch ? self::branch_name( $branch ) : null,
			'brand'       => $brand ? ( MQP_REST::brand( $brand )->name ?? null ) : null,
			'member_since'=> $u->user_registered,
			'permissions' => $caps,
			'preferences' => self::preferences( $u->ID ),
		);
	}

	public static function save_profile( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$first = sanitize_text_field( (string) $req->get_param( 'first_name' ) );
		$last  = sanitize_text_field( (string) $req->get_param( 'last_name' ) );
		$name  = trim( "$first $last" );
		$data  = array(
			'ID'         => $uid,
			'first_name' => $first,
			'last_name'  => $last,
		);
		if ( '' !== $name ) {
			$data['display_name'] = $name;
		}
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return MQP_REST::error( 'Enter a valid email address.' );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $uid ) {
				return MQP_REST::error( 'That email is used by another account.' );
			}
			$data['user_email'] = $email;
		}
		$res = wp_update_user( $data );
		if ( is_wp_error( $res ) ) {
			return MQP_REST::error( $res->get_error_message() );
		}
		update_user_meta( $uid, 'mqp_phone', sanitize_text_field( (string) $req->get_param( 'phone' ) ) );
		return self::profile();
	}

	public static function password( WP_REST_Request $req ) {
		$user    = wp_get_current_user();
		$current = (string) $req->get_param( 'current' );
		$new     = (string) $req->get_param( 'new' );
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return MQP_REST::error( 'Your current password is incorrect.', 403 );
		}
		if ( strlen( $new ) < 8 ) {
			return MQP_REST::error( 'The new password must be at least 8 characters.' );
		}
		wp_set_password( $new, $user->ID ); // Ends this person's other portal sessions; this one stays signed in.
		mqp_log( 'password_changed', array( 'user_id' => $user->ID, 'username' => $user->user_login ) );
		return array( 'ok' => true );
	}

	public static function preferences( $uid ) {
		$saved = get_user_meta( $uid, 'mqp_prefs', true );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'landing'    => $saved['landing'] ?? 'home',
			'auto_print' => array_key_exists( 'auto_print', $saved ) ? (bool) $saved['auto_print'] : true,
			'density'    => $saved['density'] ?? 'comfortable',
		);
	}

	public static function save_preferences( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$prefs = self::preferences( $uid );
		foreach ( self::PREFS as $key => $allowed ) {
			$val = $req->get_param( $key );
			if ( null === $val ) {
				continue;
			}
			if ( 'auto_print' === $key ) {
				$val = rest_sanitize_boolean( $val );
			}
			if ( in_array( $val, $allowed, true ) ) {
				$prefs[ $key ] = $val;
			}
		}
		update_user_meta( $uid, 'mqp_prefs', $prefs );
		return $prefs;
	}
}
