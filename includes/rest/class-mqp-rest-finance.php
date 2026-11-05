<?php
defined( 'ABSPATH' ) || exit;

/** Commissions, attendance reports and the login/audit log. */
class MQP_REST_Finance {

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/finance/commissions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'commissions' ),
			'permission_callback' => MQP_REST::can( 'mq_finance', 'mq_sales' ),
		) );
		register_rest_route( $ns, '/finance/commissions/pay', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pay' ),
			'permission_callback' => MQP_REST::can( 'mq_finance' ),
		) );
		register_rest_route( $ns, '/finance/attendance', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'attendance' ),
			'permission_callback' => MQP_REST::can( 'mq_finance', 'mq_access_portal' ),
		) );
		register_rest_route( $ns, '/finance/audit', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'audit' ),
			'permission_callback' => MQP_REST::can( 'mq_finance' ),
		) );
	}

	public static function commissions( WP_REST_Request $req ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( in_array( $status, array( 'pending', 'earned', 'paid', 'cancelled' ), true ) ) {
			$where[] = 'c.status = %s';
			$args[]  = $status;
		}
		// Sales staff without finance access only see their own.
		$person = current_user_can( 'mq_finance' ) ? (int) $req->get_param( 'user_id' ) : get_current_user_id();
		if ( $person ) {
			$where[] = 'c.sales_person_id = %d';
			$args[]  = $person;
		}
		if ( mqp_valid_date( $req->get_param( 'from' ) ) ) {
			$where[] = 'c.earned_at >= %s';
			$args[]  = $req->get_param( 'from' ) . ' 00:00:00';
		}
		if ( mqp_valid_date( $req->get_param( 'to' ) ) ) {
			$where[] = 'c.earned_at <= %s';
			$args[]  = $req->get_param( 'to' ) . ' 23:59:59';
		}
		$sql  = 'SELECT c.*, j.number AS job_number, j.title, j.customer_name, j.stage, d.number AS invoice_number, b.name AS brand_name
		         FROM ' . mqp_table( 'commissions' ) . ' c
		         JOIN ' . mqp_table( 'jobs' ) . ' j ON j.id = c.job_id
		         JOIN ' . mqp_table( 'documents' ) . ' d ON d.id = j.document_id
		         JOIN ' . mqp_table( 'brands' ) . ' b ON b.id = j.brand_id
		         WHERE ' . implode( ' AND ', $where ) . ' ORDER BY c.id DESC LIMIT 500';
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return array_map( function ( $r ) {
			$r                 = mqp_cast_row( $r, array( 'id', 'job_id', 'sales_person_id', 'paid_by' ), array( 'base_amount', 'rate', 'amount' ) );
			$r['sales_person'] = mqp_user_brief( $r['sales_person_id'] );
			return $r;
		}, $rows );
	}

	public static function pay( WP_REST_Request $req ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $req->get_param( 'ids' ) ) );
		if ( ! $ids ) {
			return MQP_REST::error( 'Select commissions to mark as paid.' );
		}
		$in = implode( ',', $ids );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . mqp_table( 'commissions' ) . " SET status = 'paid', paid_at = %s, paid_by = %d WHERE status = 'earned' AND id IN ($in)", // $in is int-only.
				mqp_now(),
				get_current_user_id()
			)
		);
		return array( 'ok' => true );
	}

	public static function attendance( WP_REST_Request $req ) {
		global $wpdb;
		$from = mqp_valid_date( $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : gmdate( 'Y-m-d', strtotime( mqp_today() . ' -6 days' ) );
		$to   = mqp_valid_date( $req->get_param( 'to' ) ) ? $req->get_param( 'to' ) : mqp_today();
		$user = current_user_can( 'mq_finance' ) ? (int) $req->get_param( 'user_id' ) : get_current_user_id();
		if ( strtotime( $from ) < strtotime( $to ) - 366 * DAY_IN_SECONDS ) {
			$from = gmdate( 'Y-m-d', strtotime( $to ) - 366 * DAY_IN_SECONDS );
		}

		$sql  = 'SELECT a.*, br.name AS branch_name FROM ' . mqp_table( 'attendance' ) . ' a LEFT JOIN ' . mqp_table( 'branches' ) . ' br ON br.id = a.branch_id WHERE a.time_in BETWEEN %s AND %s';
		$args = array( "$from 00:00:00", "$to 23:59:59" );
		if ( $user ) {
			$sql   .= ' AND a.user_id = %d';
			$args[] = $user;
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY a.time_in DESC', $args ), ARRAY_A );
		$by_att = array();
		if ( $rows ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
			foreach ( $wpdb->get_results( 'SELECT id, attendance_id, break_start, break_end FROM ' . mqp_table( 'attendance_breaks' ) . " WHERE attendance_id IN ($ids) ORDER BY id", ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints only.
				$by_att[ (int) $b['attendance_id'] ][] = array( 'id' => $b['id'], 'break_start' => $b['break_start'], 'break_end' => $b['break_end'] );
			}
		}
		return array_map( function ( $r ) use ( $by_att ) {
			$breaks             = $by_att[ (int) $r['id'] ] ?? array();
			$r                  = mqp_cast_row( $r, array( 'id', 'user_id', 'branch_id', 'in_accuracy', 'auto_closed' ), array( 'in_lat', 'in_lng', 'out_lat', 'out_lng' ) );
			$r['user']          = mqp_user_brief( $r['user_id'] );
			$r['breaks']        = $breaks;
			$r['break_minutes'] = MQP_Auth::break_minutes( $breaks, $r['time_out'] ? $r['time_out'] : mqp_now() );
			$r['worked_minutes']= MQP_Auth::worked_minutes( $r, $breaks );
			// Net hours (breaks excluded); null while the shift is still open.
			$r['hours']         = $r['time_out'] ? round( $r['worked_minutes'] / 60, 2 ) : null;
			return $r;
		}, $rows );
	}

	public static function audit( WP_REST_Request $req ) {
		global $wpdb;
		// Logins and time clock only; the full activity log is in wp-admin → MQ Portal → System.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'audit_log' ) . " WHERE event IN ('login', 'logout') OR event LIKE 'clock\\_%' ORDER BY id DESC LIMIT 300", ARRAY_A );
		return array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'user_id' ), array( 'lat', 'lng' ) );
		}, $rows );
	}
}
