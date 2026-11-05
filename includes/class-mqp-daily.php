<?php
defined( 'ABSPATH' ) || exit;

/**
 * Daily check: helps the owner see orders that were not recorded.
 *
 * - Printer counter log: staff write down each printer's total page counter (at opening and
 *   closing). Pages printed by the counter are compared with the pages sold at the POS: a big
 *   gap means prints that were not rung up (or job orders and reprints — ask about it).
 * - The daily report (portal → Admin → Daily check, and emailed to the admins each morning for
 *   the day before): voids, deleted orders, online orders still without a price, job orders
 *   released with a balance, customer uploads nobody handled, and the counter gaps.
 */
class MQP_Daily {

	public static function init() {
		add_action( 'mqp_daily', array( __CLASS__, 'send_report' ) );
	}

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/printer-readings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_readings' ), 'permission_callback' => MQP_REST::can( 'mq_pos', 'mq_production', 'mq_view_dashboard', 'mq_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_reading' ), 'permission_callback' => MQP_REST::can( 'mq_pos', 'mq_production', 'mq_view_dashboard', 'mq_manage' ) ),
		) );
		register_rest_route( $ns, '/admin/daily-check', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'report_route' ),
			'permission_callback' => MQP_REST::can( 'mq_view_dashboard', 'mq_manage' ),
		) );
	}

	/* ---------- Printer counter ---------- */

	private static function reading_row( $r ) {
		$r         = mqp_cast_row( (array) $r, array( 'id', 'branch_id', 'reading', 'user_id' ) );
		$r['user'] = mqp_user_brief( $r['user_id'] );
		return $r;
	}

	public static function list_readings( WP_REST_Request $req ) {
		global $wpdb;
		$branch = (int) $req->get_param( 'branch_id' );
		$where  = $branch ? $wpdb->prepare( 'WHERE branch_id = %d', $branch ) : '';
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'printer_readings' ) . " $where ORDER BY id DESC LIMIT 60", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$names  = $wpdb->get_col( 'SELECT DISTINCT printer FROM ' . mqp_table( 'printer_readings' ) . " $where ORDER BY printer" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'readings' => array_map( array( __CLASS__, 'reading_row' ), $rows ), 'printers' => $names );
	}

	public static function add_reading( WP_REST_Request $req ) {
		global $wpdb;
		$branch  = (int) $req->get_param( 'branch_id' );
		$printer = substr( sanitize_text_field( (string) $req->get_param( 'printer' ) ), 0, 60 );
		$reading = (int) $req->get_param( 'reading' );
		$note    = substr( sanitize_text_field( (string) $req->get_param( 'note' ) ), 0, 255 );
		if ( ! $branch || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', $branch ) ) ) {
			return MQP_REST::error( 'Choose the shop.' );
		}
		if ( '' === $printer || $reading <= 0 ) {
			return MQP_REST::error( 'Enter the printer name and the page counter shown on it.' );
		}
		$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT reading FROM ' . mqp_table( 'printer_readings' ) . ' WHERE branch_id = %d AND printer = %s ORDER BY id DESC LIMIT 1', $branch, $printer ) );
		// A counter only goes up: a lower number is a typo, or the printer was replaced/reset (needs a note).
		if ( $last && $reading < $last && '' === $note ) {
			return MQP_REST::error( sprintf( 'The last counter for %s was %s. If the printer was reset or replaced, write that in the note.', $printer, number_format( $last ) ) );
		}
		$wpdb->insert( mqp_table( 'printer_readings' ), array( 'branch_id' => $branch, 'printer' => $printer, 'reading' => $reading, 'note' => $note, 'user_id' => get_current_user_id(), 'created_at' => mqp_now() ) );
		return self::reading_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'printer_readings' ) . ' WHERE id = %d', $wpdb->insert_id ), ARRAY_A ) );
	}

	/* ---------- Report ---------- */

	public static function report_route( WP_REST_Request $req ) {
		$date = (string) $req->get_param( 'date' );
		return self::report( mqp_valid_date( $date ) ? $date : current_time( 'Y-m-d' ) );
	}

	/** Everything the owner should look at for one day (Y-m-d, shop time). */
	public static function report( $date ) {
		global $wpdb;
		$from = $date . ' 00:00:00';
		$to   = $date . ' 23:59:59';
		$t    = function ( $n ) {
			return mqp_table( $n );
		};
		$branches = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . $t( 'branches' ), ARRAY_A ) as $b ) {
			$branches[ (int) $b['id'] ] = $b['name'];
		}
		$who = function ( $id ) {
			$u = mqp_user_brief( $id );
			return $u ? $u['name'] : '—';
		};

		// Money in.
		$pos = $wpdb->get_results( $wpdb->prepare( 'SELECT branch_id, COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM ' . $t( 'pos_sales' ) . " WHERE status <> 'void' AND sold_at BETWEEN %s AND %s GROUP BY branch_id", $from, $to ), ARRAY_A );
		$pay = $wpdb->get_results( $wpdb->prepare( 'SELECT method, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM ' . $t( 'payments' ) . ' WHERE paid_at BETWEEN %s AND %s GROUP BY method', $from, $to ), ARRAY_A );
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM ' . $t( 'documents' ) . " WHERE type = 'invoice' AND created_at BETWEEN %s AND %s", $from, $to ), ARRAY_A );

		// Things to ask about.
		$pos_voids = array_map( function ( $r ) use ( $who, $branches ) {
			return array( 'what' => 'POS ' . ( $r['official_no'] ? $r['official_no'] : $r['receipt_no'] ), 'amount' => (float) $r['total'], 'shop' => $branches[ (int) $r['branch_id'] ] ?? '', 'by' => $who( $r['voided_by'] ), 'reason' => $r['void_reason'] );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $t( 'pos_sales' ) . " WHERE status = 'void' AND voided_at BETWEEN %s AND %s", $from, $to ), ARRAY_A ) );
		$doc_voids = array_map( function ( $r ) {
			return array( 'what' => $r['number'] . ' · ' . $r['customer_name'], 'amount' => (float) $r['total'], 'reason' => $r['void_reason'] );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT number, customer_name, total, void_reason FROM ' . $t( 'documents' ) . ' WHERE voided_at BETWEEN %s AND %s', $from, $to ), ARRAY_A ) );
		$deleted = array_map( function ( $r ) {
			return array( 'what' => $r['reason'], 'by' => $r['username'] );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT username, reason FROM ' . $t( 'audit_log' ) . " WHERE event = 'order_deleted' AND created_at BETWEEN %s AND %s", $from, $to ), ARRAY_A ) );
		$unpriced = array_map( function ( $r ) {
			return array( 'what' => $r['reference'] . ' · ' . $r['customer_name'], 'since' => $r['created_at'] );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT o.reference, o.customer_name, o.created_at FROM ' . $t( 'orders' ) . ' o JOIN ' . $t( 'documents' ) . " d ON d.id = o.document_id WHERE o.payment_status = 'pending' AND d.total <= 0 AND d.status <> 'void' AND o.created_at < %s ORDER BY o.id", gmdate( 'Y-m-d H:i:s', strtotime( $to ) - DAY_IN_SECONDS ) ), ARRAY_A ) );
		$released = array_map( function ( $r ) {
			return array( 'what' => $r['number'] . ' · ' . $r['customer_name'], 'amount' => round( (float) $r['total'] - (float) $r['amount_paid'], 2 ) );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT j.number, j.customer_name, d.total, d.amount_paid FROM ' . $t( 'jobs' ) . ' j JOIN ' . $t( 'documents' ) . " d ON d.id = j.document_id WHERE j.stage = 'delivered' AND j.delivered_at BETWEEN %s AND %s AND d.total - d.amount_paid > 0.009", $from, $to ), ARRAY_A ) );
		$uploads = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( 'uploads' ) . " WHERE status = 'new' AND created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( $to ) - DAY_IN_SECONDS ) ) );

		// Printer counters vs pages sold at the POS (products whose unit is "page").
		$printers = array();
		$sold     = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT s.branch_id, COALESCE(SUM(i.qty),0) AS pages FROM ' . $t( 'pos_sale_items' ) . ' i JOIN ' . $t( 'pos_sales' ) . ' s ON s.id = i.sale_id JOIN ' . $t( 'pos_products' ) . " p ON p.id = i.product_id WHERE s.status <> 'void' AND p.unit = 'page' AND s.sold_at BETWEEN %s AND %s GROUP BY s.branch_id", $from, $to ), ARRAY_A ) as $r ) {
			$sold[ (int) $r['branch_id'] ] = (int) $r['pages'];
		}
		$pairs = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT branch_id, printer FROM ' . $t( 'printer_readings' ) . ' WHERE created_at <= %s', $to ), ARRAY_A );
		foreach ( $pairs as $p ) {
			$start = $wpdb->get_var( $wpdb->prepare( 'SELECT reading FROM ' . $t( 'printer_readings' ) . ' WHERE branch_id = %d AND printer = %s AND created_at < %s ORDER BY id DESC LIMIT 1', $p['branch_id'], $p['printer'], $from ) );
			if ( null === $start ) { // No reading before the day: the day's first one is the start.
				$start = $wpdb->get_var( $wpdb->prepare( 'SELECT reading FROM ' . $t( 'printer_readings' ) . ' WHERE branch_id = %d AND printer = %s AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 1', $p['branch_id'], $p['printer'], $from, $to ) );
			}
			$end = $wpdb->get_var( $wpdb->prepare( 'SELECT reading FROM ' . $t( 'printer_readings' ) . ' WHERE branch_id = %d AND printer = %s AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 1', $p['branch_id'], $p['printer'], $from, $to ) );
			if ( null === $end ) {
				continue; // Not read that day.
			}
			$printers[] = array( 'shop' => $branches[ (int) $p['branch_id'] ] ?? '', 'branch_id' => (int) $p['branch_id'], 'printer' => $p['printer'], 'start' => (int) $start, 'end' => (int) $end, 'printed' => max( 0, (int) $end - (int) $start ) );
		}
		$by_branch = array();
		foreach ( $printers as $p ) {
			$by_branch[ $p['branch_id'] ] = ( $by_branch[ $p['branch_id'] ] ?? 0 ) + $p['printed'];
		}
		$gaps = array();
		foreach ( $by_branch as $bid => $printed ) {
			$pos_pages = $sold[ $bid ] ?? 0;
			$gaps[]    = array( 'shop' => $branches[ $bid ] ?? '', 'printed' => $printed, 'sold' => $pos_pages, 'gap' => $printed - $pos_pages );
		}
		// Shops that sold at the POS but wrote down no printer counter that day.
		$no_counter = array();
		foreach ( $pos as $r ) {
			if ( ! isset( $by_branch[ (int) $r['branch_id'] ] ) ) {
				$no_counter[] = $branches[ (int) $r['branch_id'] ] ?? '';
			}
		}

		$flags = count( $pos_voids ) + count( $doc_voids ) + count( $deleted ) + count( $unpriced ) + count( $released ) + ( $uploads ? 1 : 0 )
			+ count( array_filter( $gaps, function ( $g ) {
				return $g['gap'] > max( 20, $g['sold'] * 0.1 );
			} ) );

		return array(
			'date'       => $date,
			'flags'      => $flags,
			'pos'        => array_map( function ( $r ) use ( $branches ) {
				return array( 'shop' => $branches[ (int) $r['branch_id'] ] ?? '', 'count' => (int) $r['n'], 'total' => (float) $r['total'] );
			}, $pos ),
			'payments'   => array_map( function ( $r ) {
				return array( 'method' => strtoupper( $r['method'] ), 'count' => (int) $r['n'], 'total' => (float) $r['total'] );
			}, $pay ),
			'invoices'   => array( 'count' => (int) $inv['n'], 'total' => (float) $inv['total'] ),
			'pos_voids'  => $pos_voids,
			'doc_voids'  => $doc_voids,
			'deleted'    => $deleted,
			'unpriced'   => $unpriced,
			'released'   => $released,
			'uploads'    => $uploads,
			'printers'   => $printers,
			'gaps'       => $gaps,
			'no_counter' => $no_counter,
		);
	}

	/** Each morning: yesterday's report to the admin emails (once per day; off in Settings). */
	public static function send_report() {
		if ( ! MQP_Settings::get( 'daily_report' ) ) {
			return;
		}
		$day = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - DAY_IN_SECONDS );
		if ( get_option( 'mqp_daily_report_sent' ) === $day ) {
			return;
		}
		update_option( 'mqp_daily_report_sent', $day, false );
		$r     = self::report( $day );
		$peso  = function ( $v ) {
			return '₱' . number_format( (float) $v, 2 );
		};
		$rows  = array();
		foreach ( $r['pos'] as $p ) {
			$rows[] = array( 'POS ' . $p['shop'] . ' (' . $p['count'] . ' sales)', $peso( $p['total'] ) );
		}
		foreach ( $r['payments'] as $p ) {
			$rows[] = array( 'Payments ' . $p['method'] . ' (' . $p['count'] . ')', $peso( $p['total'] ) );
		}
		$rows[] = array( 'Invoices issued (' . $r['invoices']['count'] . ')', $peso( $r['invoices']['total'] ) );
		$blocks = array( MQP_Mail::table( $rows ) );
		$list   = function ( $title, $items, $fmt ) use ( &$blocks ) {
			if ( $items ) {
				$blocks[] = MQP_Mail::pill( $title . ' (' . count( $items ) . ')', 'warn' );
				$blocks[] = MQP_Mail::table( array_map( $fmt, $items ) );
			}
		};
		$list( 'Voided POS receipts', $r['pos_voids'], function ( $v ) use ( $peso ) {
			return array( $v['what'] . ' · ' . $v['by'] . ' · ' . $v['reason'], $peso( $v['amount'] ) );
		} );
		$list( 'Voided documents', $r['doc_voids'], function ( $v ) use ( $peso ) {
			return array( $v['what'] . ' · ' . $v['reason'], $peso( $v['amount'] ) );
		} );
		$list( 'Deleted online orders', $r['deleted'], function ( $v ) {
			return array( $v['what'], $v['by'] );
		} );
		$list( 'Online orders still without a price (over a day old)', $r['unpriced'], function ( $v ) {
			return array( $v['what'], $v['since'] );
		} );
		$list( 'Job orders released with a balance', $r['released'], function ( $v ) use ( $peso ) {
			return array( $v['what'], $peso( $v['amount'] ) . ' unpaid' );
		} );
		$list( 'Printer counter vs pages sold at the POS', $r['gaps'], function ( $g ) {
			return array( $g['shop'] . ': printed ' . number_format( $g['printed'] ) . ', sold ' . number_format( $g['sold'] ), ( $g['gap'] > 0 ? '+' : '' ) . number_format( $g['gap'] ) );
		} );
		if ( $r['no_counter'] ) {
			$blocks[] = 'No printer counter was written down at: ' . implode( ', ', $r['no_counter'] ) . '.';
		}
		if ( $r['uploads'] ) {
			$blocks[] = $r['uploads'] . ' customer upload(s) are waiting for more than a day.';
		}
		if ( ! $r['flags'] ) {
			$blocks[] = 'Nothing unusual.';
		}
		MQP_Mail::send(
			MQP_Monitor::admin_to(),
			'Daily check ' . $day . ( $r['flags'] ? ' — ' . $r['flags'] . ' to look at' : ' — all clear' ),
			'Daily check for ' . date_i18n( 'l, F j', strtotime( $day ) ),
			$blocks,
			array( 'Open the daily check', MQP_Portal::url() . '#/admin/daily/' . $day )
		);
	}
}
