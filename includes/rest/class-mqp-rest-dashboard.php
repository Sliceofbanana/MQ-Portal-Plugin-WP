<?php
defined( 'ABSPATH' ) || exit;

/**
 * Owner dashboard: MQ Prints total → per brand → (EasyPrint) per branch → receipts.
 * Sales = completed POS receipts + non-void invoices, by date.
 */
class MQP_REST_Dashboard {

	public static function routes() {
		$ns   = MQP_REST::NS;
		$perm = MQP_REST::can( 'mq_view_dashboard' );
		register_rest_route( $ns, '/dashboard', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/dashboard/branch/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'branch' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/dashboard/departments', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'departments' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/dashboard/brand/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'brand' ),
			'permission_callback' => $perm,
		) );
	}

	private static function range( WP_REST_Request $req ) {
		$from = mqp_valid_date( $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : mqp_today();
		$to   = mqp_valid_date( $req->get_param( 'to' ) ) ? $req->get_param( 'to' ) : $from;
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array( "$from 00:00:00", "$to 23:59:59", $from, $to );
	}

	/** POS figures grouped by $group (brand_id or branch_id). */
	private static function pos_stats( $start, $end, $group ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $group AS gid,
				  SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS pos_total,
				  SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS pos_count,
				  SUM(CASE WHEN status = 'void' THEN total ELSE 0 END) AS void_total,
				  SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS void_count
				 FROM " . mqp_table( 'pos_sales' ) . " WHERE sold_at BETWEEN %s AND %s GROUP BY $group",
				$start,
				$end
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['gid'] ] = mqp_cast_row( $r, array( 'pos_count', 'void_count' ), array( 'pos_total', 'void_total' ) );
		}
		return $out;
	}

	private static function invoice_stats( $start, $end, $group ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $group AS gid, SUM(total) AS invoice_total, COUNT(*) AS invoice_count
				 FROM " . mqp_table( 'documents' ) . " WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s GROUP BY $group",
				$start,
				$end
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['gid'] ] = mqp_cast_row( $r, array( 'invoice_count' ), array( 'invoice_total' ) );
		}
		return $out;
	}

	private static function collected( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.brand_id AS gid, SUM(p.amount) AS collected FROM ' . mqp_table( 'payments' ) . ' p JOIN ' . mqp_table( 'documents' ) . " d ON d.id = p.document_id
				 WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s GROUP BY d.brand_id",
				$start,
				$end
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['gid'] ] = (float) $r['collected'];
		}
		return $out;
	}

	private static function merge( $id, ...$sets ) {
		$row = array( 'pos_total' => 0.0, 'pos_count' => 0, 'void_total' => 0.0, 'void_count' => 0, 'invoice_total' => 0.0, 'invoice_count' => 0 );
		foreach ( $sets as $set ) {
			if ( isset( $set[ $id ] ) ) {
				$row = array_merge( $row, $set[ $id ] );
			}
		}
		unset( $row['gid'] );
		$row['total'] = mqp_money( $row['pos_total'] + $row['invoice_total'] );
		return $row;
	}

	public static function overview( WP_REST_Request $req ) {
		global $wpdb;
		list( $start, $end, $from, $to ) = self::range( $req );

		$brands      = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'brands' ) . ' ORDER BY sort, id' );
		$branches    = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' ORDER BY id' );
		$pos_brand   = self::pos_stats( $start, $end, 'brand_id' );
		$pos_branch  = self::pos_stats( $start, $end, 'branch_id' );
		$inv_brand   = self::invoice_stats( $start, $end, 'brand_id' );
		$inv_branch  = self::invoice_stats( $start, $end, 'branch_id' );
		$collected   = self::collected( $start, $end );

		$out   = array();
		$grand = 0;
		foreach ( $brands as $b ) {
			$row              = self::merge( (int) $b->id, $pos_brand, $inv_brand );
			$row['id']        = (int) $b->id;
			$row['name']      = $b->name;
			$row['code']      = $b->code;
			$row['has_branches'] = (bool) $b->has_branches;
			$row['collected'] = $collected[ (int) $b->id ] ?? 0.0;
			$row['branches']  = array();
			if ( $b->has_branches ) {
				foreach ( $branches as $br ) {
					if ( (int) $br->brand_id !== (int) $b->id ) {
						continue;
					}
					$brow             = self::merge( (int) $br->id, $pos_branch, $inv_branch );
					$brow['id']       = (int) $br->id;
					$brow['name']     = $br->name;
					$row['branches'][] = $brow;
				}
			}
			$grand += $row['total'];
			$out[]  = $row;
		}

		$pipeline = $wpdb->get_results( 'SELECT stage, COUNT(*) AS count FROM ' . mqp_table( 'jobs' ) . " WHERE stage NOT IN ('delivered','cancelled') GROUP BY stage", OBJECT_K );
		$stages   = array();
		foreach ( array( 'sales', 'creatives', 'production', 'qc', 'ready' ) as $s ) {
			$stages[ $s ] = isset( $pipeline[ $s ] ) ? (int) $pipeline[ $s ]->count : 0;
		}

		return array(
			'from'      => $from,
			'to'        => $to,
			'total'     => mqp_money( $grand ),
			'collected' => mqp_money( array_sum( $collected ) ),
			'brands'    => $out,
			'pipeline'  => $stages,
		);
	}

	/** Receipts (and voided receipts) of one branch. */
	public static function branch( WP_REST_Request $req ) {
		global $wpdb;
		list( $start, $end, $from, $to ) = self::range( $req );
		$id     = (int) $req['id'];
		$branch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', $id ) );
		if ( ! $branch ) {
			return MQP_REST::error( 'Branch not found.', 404 );
		}
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'pos_sales' ) . ' WHERE branch_id = %d AND sold_at BETWEEN %s AND %s ORDER BY sold_at DESC', $id, $start, $end ) );
		$receipts = array_map( array( 'MQP_REST_POS', 'load' ), array_map( 'intval', $ids ) );
		$invoices = array_map(
			array( 'MQP_REST_Documents', 'format' ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'documents' ) . " WHERE type = 'invoice' AND branch_id = %d AND created_at BETWEEN %s AND %s ORDER BY id DESC", $id, $start, $end ), ARRAY_A )
		);
		$sub = new WP_REST_Request( 'GET' );
		$sub->set_param( 'date', $from );
		$sub->set_param( 'branch_id', $id );
		return array(
			'branch'   => array( 'id' => (int) $branch->id, 'name' => $branch->name ),
			'from'     => $from,
			'to'       => $to,
			'receipts' => array_values( array_filter( $receipts, function ( $r ) {
				return 'completed' === $r['status'];
			} ) ),
			'voided'   => array_values( array_filter( $receipts, function ( $r ) {
				return 'void' === $r['status'];
			} ) ),
			'invoices' => $invoices,
			'summary'  => $from === $to ? MQP_REST_POS::summary( $sub ) : null,
		);
	}

	/** Completed and upcoming orders of an invoice-based brand. */
	public static function brand( WP_REST_Request $req ) {
		global $wpdb;
		list( $start, $end, $from, $to ) = self::range( $req );
		$id    = (int) $req['id'];
		$brand = MQP_REST::brand( $id );
		if ( ! $brand ) {
			return MQP_REST::error( 'Brand not found.', 404 );
		}
		$jobs  = mqp_table( 'jobs' );
		$docs  = mqp_table( 'documents' );
		$sel   = "SELECT j.*, d.number AS invoice_number, d.total, d.amount_paid, d.status AS invoice_status, d.created_by AS invoice_created_by, 0 AS file_count FROM $jobs j JOIN $docs d ON d.id = j.document_id";

		$completed = $wpdb->get_results( $wpdb->prepare( "$sel WHERE j.brand_id = %d AND j.stage = 'delivered' AND j.delivered_at BETWEEN %s AND %s ORDER BY j.delivered_at DESC", $id, $start, $end ), ARRAY_A );
		$upcoming  = $wpdb->get_results( $wpdb->prepare( "$sel WHERE j.brand_id = %d AND j.stage NOT IN ('delivered','cancelled') ORDER BY j.due_date IS NULL, j.due_date, j.id", $id ), ARRAY_A );
		$invoices  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $docs WHERE type = 'invoice' AND brand_id = %d AND created_at BETWEEN %s AND %s ORDER BY id DESC", $id, $start, $end ), ARRAY_A );

		return array(
			'brand'     => array( 'id' => (int) $brand->id, 'name' => $brand->name ),
			'from'      => $from,
			'to'        => $to,
			'completed' => array_map( array( 'MQP_REST_Jobs', 'format' ), $completed ),
			'upcoming'  => array_map( array( 'MQP_REST_Jobs', 'format' ), $upcoming ),
			'invoices'  => array_map( array( 'MQP_REST_Documents', 'format' ), $invoices ),
		);
	}

	/** Report per department for the selected period. */
	public static function departments( WP_REST_Request $req ) {
		global $wpdb;
		list( $start, $end, $from, $to ) = self::range( $req );
		$docs   = mqp_table( 'documents' );
		$jobs   = mqp_table( 'jobs' );
		$events = mqp_table( 'job_events' );
		$pay    = mqp_table( 'payments' );
		$ps     = mqp_table( 'pos_sales' );
		$com    = mqp_table( 'commissions' );
		$p      = function ( $sql, ...$args ) use ( $wpdb ) {
			return $args ? $wpdb->prepare( $sql, $args ) : $sql;
		};
		$num    = function ( $sql, ...$args ) use ( $wpdb, $p ) {
			return (float) $wpdb->get_var( $p( $sql, ...$args ) );
		};
		$ev     = function ( $action ) use ( $num, $events, $start, $end ) {
			return (int) $num( "SELECT COUNT(*) FROM $events WHERE action = %s AND created_at BETWEEN %s AND %s", $action, $start, $end );
		};
		$today  = mqp_today();

		/* Sales */
		$doc_counts = array();
		foreach ( array( 'quotation', 'billing', 'invoice' ) as $t ) {
			$doc_counts[ $t ] = (int) $num( "SELECT COUNT(*) FROM $docs WHERE type = %s AND status <> 'void' AND created_at BETWEEN %s AND %s", $t, $start, $end );
		}
		$converted_q = (int) $num( "SELECT COUNT(*) FROM $docs q WHERE q.type = 'quotation' AND q.created_at BETWEEN %s AND %s AND EXISTS (SELECT 1 FROM $docs c WHERE c.parent_id = q.id AND c.status <> 'void')", $start, $end );
		$uploads     = $wpdb->get_results( $p( 'SELECT status, COUNT(*) AS n FROM ' . mqp_table( 'uploads' ) . ' WHERE created_at BETWEEN %s AND %s GROUP BY status', $start, $end ), OBJECT_K );
		$by_person   = $wpdb->get_results(
			$p(
				"SELECT COALESCE(d.sales_person_id, 0) AS uid, COUNT(*) AS invoices, SUM(d.total) AS total, SUM(d.amount_paid) AS paid
				 FROM $docs d WHERE d.type = 'invoice' AND d.status <> 'void' AND d.created_at BETWEEN %s AND %s GROUP BY d.sales_person_id ORDER BY total DESC",
				$start,
				$end
			),
			ARRAY_A
		);
		foreach ( $by_person as &$row ) {
			$row          = mqp_cast_row( $row, array( 'uid', 'invoices' ), array( 'total', 'paid' ) );
			$row['name']  = $row['uid'] ? ( mqp_user_brief( $row['uid'] )['name'] ?? '?' ) : 'Unassigned';
			$row['delivered'] = (int) $num( "SELECT COUNT(*) FROM $jobs WHERE sales_person_id = %d AND stage = 'delivered' AND delivered_at BETWEEN %s AND %s", $row['uid'], $start, $end );
		}
		unset( $row );
		$sales = array(
			'quotations'       => $doc_counts['quotation'],
			'billing'          => $doc_counts['billing'],
			'invoices'         => $doc_counts['invoice'],
			'invoiced'         => $num( "SELECT COALESCE(SUM(total),0) FROM $docs WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s", $start, $end ),
			'conversion_rate'  => $doc_counts['quotation'] ? round( $converted_q / $doc_counts['quotation'] * 100 ) : null,
			'uploads_received' => array_sum( array_map( function ( $o ) {
				return (int) $o->n;
			}, $uploads ) ),
			'uploads_converted'=> isset( $uploads['converted'] ) ? (int) $uploads['converted']->n : 0,
			'uploads_waiting'  => (int) $num( 'SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) . " WHERE status = 'new'" ),
			'stuck_in_sales'   => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'sales'" ),
			'by_person'        => $by_person,
		);

		/* Creatives: average time from "sent to creatives" to "approved" for jobs approved in the period */
		$approved = $wpdb->get_results(
			$p(
				"SELECT a.job_id, a.created_at AS approved_at,
				   (SELECT MAX(s.created_at) FROM $events s WHERE s.job_id = a.job_id AND s.action = 'to_creatives' AND s.created_at <= a.created_at) AS sent_at
				 FROM $events a WHERE a.action = 'approve_files' AND a.created_at BETWEEN %s AND %s",
				$start,
				$end
			)
		);
		$mins = array();
		foreach ( $approved as $a ) {
			if ( $a->sent_at ) {
				$mins[] = ( strtotime( $a->approved_at ) - strtotime( $a->sent_at ) ) / 60;
			}
		}
		$creatives = array(
			'received'        => $ev( 'to_creatives' ),
			'approved'        => $ev( 'approve_files' ),
			'edits_requested' => $ev( 'needs_editing' ),
			'designs_attached'=> $ev( 'file_design' ),
			'returned_to_sales' => $ev( 'back_to_sales' ),
			'waiting_now'     => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'creatives'" ),
			'needs_editing_now' => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'creatives' AND needs_editing = 1" ),
			'avg_turnaround_hours' => $mins ? round( array_sum( $mins ) / count( $mins ) / 60, 1 ) : null,
		);

		/* Production & QC */
		$delivered = $wpdb->get_results( $p( "SELECT created_at, delivered_at, due_date FROM $jobs WHERE stage = 'delivered' AND delivered_at BETWEEN %s AND %s", $start, $end ) );
		$days      = array();
		$on_time   = 0;
		$with_due  = 0;
		foreach ( $delivered as $d ) {
			$days[] = ( strtotime( $d->delivered_at ) - strtotime( $d->created_at ) ) / DAY_IN_SECONDS;
			if ( $d->due_date ) {
				$with_due++;
				$on_time += substr( $d->delivered_at, 0, 10 ) <= $d->due_date ? 1 : 0;
			}
		}
		$qc_ok  = $ev( 'qc_approve' );
		$qc_bad = $ev( 'qc_reject' );
		$production = array(
			'printed'          => $ev( 'printed' ),
			'qc_approved'      => $qc_ok,
			'qc_rejected'      => $qc_bad,
			'qc_reject_rate'   => ( $qc_ok + $qc_bad ) ? round( $qc_bad / ( $qc_ok + $qc_bad ) * 100 ) : null,
			'delivered'        => count( $delivered ),
			'on_time_rate'     => $with_due ? round( $on_time / $with_due * 100 ) : null,
			'avg_days_to_deliver' => $days ? round( array_sum( $days ) / count( $days ), 1 ) : null,
			'in_production'    => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'production'" ),
			'in_qc'            => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'qc'" ),
			'ready'            => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE stage = 'ready'" ),
			'overdue'          => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE due_date < %s AND stage NOT IN ('delivered','cancelled')", $today ),
			'rush_open'        => (int) $num( "SELECT COUNT(*) FROM $jobs WHERE priority = 1 AND stage NOT IN ('delivered','cancelled')" ),
		);

		/* POS */
		$pos_total = $num( "SELECT COALESCE(SUM(total),0) FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s", $start, $end );
		$pos_count = (int) $num( "SELECT COUNT(*) FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s", $start, $end );
		$cast      = function ( $rows, $ints, $floats ) {
			return array_map( function ( $r ) use ( $ints, $floats ) {
				return mqp_cast_row( $r, $ints, $floats );
			}, $rows );
		};
		$pos = array(
			'total'      => $pos_total,
			'receipts'   => $pos_count,
			'avg_ticket' => $pos_count ? round( $pos_total / $pos_count, 2 ) : 0,
			'voided'     => (int) $num( "SELECT COUNT(*) FROM $ps WHERE status = 'void' AND sold_at BETWEEN %s AND %s", $start, $end ),
			'voided_total' => $num( "SELECT COALESCE(SUM(total),0) FROM $ps WHERE status = 'void' AND sold_at BETWEEN %s AND %s", $start, $end ),
			'by_branch'  => $cast( $wpdb->get_results( $p( "SELECT b.name, COUNT(s.id) AS receipts, COALESCE(SUM(s.total),0) AS total FROM $ps s JOIN " . mqp_table( 'branches' ) . " b ON b.id = s.branch_id WHERE s.status = 'completed' AND s.sold_at BETWEEN %s AND %s GROUP BY b.id, b.name ORDER BY total DESC", $start, $end ), ARRAY_A ), array( 'receipts' ), array( 'total' ) ),
			'by_method'  => $cast( $wpdb->get_results( $p( "SELECT method, COUNT(*) AS receipts, SUM(total) AS total FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s GROUP BY method ORDER BY total DESC", $start, $end ), ARRAY_A ), array( 'receipts' ), array( 'total' ) ),
			'top_items'  => $cast( $wpdb->get_results( $p( 'SELECT i.name, SUM(i.qty) AS qty, SUM(i.amount) AS amount FROM ' . mqp_table( 'pos_sale_items' ) . " i JOIN $ps s ON s.id = i.sale_id WHERE s.status = 'completed' AND s.sold_at BETWEEN %s AND %s GROUP BY i.name ORDER BY amount DESC LIMIT 5", $start, $end ), ARRAY_A ), array(), array( 'qty', 'amount' ) ),
		);

		/* Attendance */
		$shifts   = $wpdb->get_results( $p( 'SELECT * FROM ' . mqp_table( 'attendance' ) . ' WHERE time_in BETWEEN %s AND %s', $start, $end ), ARRAY_A );
		$people   = array();
		foreach ( $shifts as $s ) {
			$breaks = MQP_Auth::breaks( (int) $s['id'] );
			$uid    = (int) $s['user_id'];
			if ( ! isset( $people[ $uid ] ) ) {
				$people[ $uid ] = array( 'name' => mqp_user_brief( $uid )['name'] ?? '?', 'shifts' => 0, 'worked_minutes' => 0, 'break_minutes' => 0, 'auto_closed' => 0 );
			}
			$people[ $uid ]['shifts']++;
			$people[ $uid ]['worked_minutes'] += MQP_Auth::worked_minutes( $s, $breaks );
			$people[ $uid ]['break_minutes']  += MQP_Auth::break_minutes( $breaks, $s['time_out'] ? $s['time_out'] : mqp_now() );
			$people[ $uid ]['auto_closed']    += (int) $s['auto_closed'];
		}
		usort( $people, function ( $a, $b ) {
			return $b['worked_minutes'] - $a['worked_minutes'];
		} );
		$attendance = array(
			'on_duty'        => MQP_REST_Me::on_duty(),
			'shifts'         => count( $shifts ),
			'worked_minutes' => array_sum( array_column( $people, 'worked_minutes' ) ),
			'blocked_clock_ins' => (int) $num( 'SELECT COUNT(*) FROM ' . mqp_table( 'audit_log' ) . " WHERE event = 'clock_in' AND result = 'blocked' AND created_at BETWEEN %s AND %s", $start, $end ),
			'people'         => array_values( $people ),
		);

		/* Finance */
		$finance = array(
			'invoiced'          => $sales['invoiced'],
			'collected'         => $num( "SELECT COALESCE(SUM(p.amount),0) FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s", $start, $end ),
			'collected_by_method' => $cast( $wpdb->get_results( $p( "SELECT p.method, SUM(p.amount) AS total FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s GROUP BY p.method ORDER BY total DESC", $start, $end ), ARRAY_A ), array(), array( 'total' ) ),
			'pos_cash_in'       => $pos_total,
			'receivables'       => $num( "SELECT COALESCE(SUM(total - amount_paid),0) FROM $docs WHERE type = 'invoice' AND status IN ('unpaid','partial')" ),
			'commission_pending'=> $num( "SELECT COALESCE(SUM(amount),0) FROM $com WHERE status = 'pending'" ),
			'commission_to_pay' => $num( "SELECT COALESCE(SUM(amount),0) FROM $com WHERE status = 'earned'" ),
			'commission_paid'   => $num( "SELECT COALESCE(SUM(amount),0) FROM $com WHERE status = 'paid' AND paid_at BETWEEN %s AND %s", $start, $end ),
		);

		return array(
			'from'       => $from,
			'to'         => $to,
			'sales'      => $sales,
			'creatives'  => $creatives,
			'production' => $production,
			'pos'        => $pos,
			'attendance' => $attendance,
			'finance'    => $finance,
		);
	}
}
