<?php
defined( 'ABSPATH' ) || exit;

/**
 * POS for walk-in sales at EasyPrint branches. Sales are created on the device
 * (with their own receipt number) and synced; uuid makes re-sends harmless.
 */
class MQP_REST_POS {

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/pos/products', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'products' ),
			'permission_callback' => MQP_REST::can( 'mq_pos' ),
		) );
		register_rest_route( $ns, '/pos/sales', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_sales' ),
				'permission_callback' => MQP_REST::can( 'mq_pos', 'mq_view_dashboard' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_sale_item' ),
				'permission_callback' => MQP_REST::can( 'mq_pos' ),
			),
		) );
		register_rest_route( $ns, '/pos/sales/(?P<id>\d+)/void', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'void_sale' ),
			'permission_callback' => MQP_REST::can( 'mq_pos_void' ),
		) );
		register_rest_route( $ns, '/pos/summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'summary' ),
			'permission_callback' => MQP_REST::can( 'mq_pos', 'mq_view_dashboard' ),
		) );
	}

	public static function products() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT p.* FROM ' . mqp_table( 'pos_products' ) . ' p WHERE p.active = 1 ORDER BY p.category, p.sort, p.name',
			ARRAY_A
		);
		return array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'brand_id', 'sort', 'active' ), array( 'price' ) );
		}, $rows );
	}

	public static function create_sale_item( WP_REST_Request $req ) {
		return self::create_sale( $req->get_json_params() );
	}

	public static function create_sale( array $p ) {
		global $wpdb;
		$table = mqp_table( 'pos_sales' );
		if ( ! mqp_is_uuid( $p['uuid'] ?? '' ) ) {
			return MQP_REST::error( 'Missing sale id.' );
		}
		$uuid = strtolower( $p['uuid'] );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE uuid = %s", $uuid ) );
		if ( $existing ) {
			return self::existing( (int) $existing );
		}
		$override = current_user_can( 'mq_price_override' );
		$flags    = array(); // Things an admin should see: the sale is kept (the receipt was already printed).

		$branch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', (int) ( $p['branch_id'] ?? 0 ) ) );
		if ( ! $branch ) {
			return MQP_REST::error( 'Choose a branch.' );
		}

		$items = array();
		foreach ( (array) ( $p['items'] ?? array() ) as $it ) {
			$name = sanitize_text_field( (string) ( $it['name'] ?? '' ) );
			$qty  = (float) ( $it['qty'] ?? 0 );
			if ( '' === $name || $qty <= 0 ) {
				continue;
			}
			$price   = max( 0, mqp_money( $it['price'] ?? 0 ) );
			$pid     = ! empty( $it['product_id'] ) ? (int) $it['product_id'] : 0;
			if ( ! $override ) {
				$list = $pid ? $wpdb->get_var( $wpdb->prepare( 'SELECT price FROM ' . mqp_table( 'pos_products' ) . ' WHERE id = %d', $pid ) ) : null;
				if ( ! $pid || null === $list ) {
					$flags[] = sprintf( 'custom item "%s" at ₱%s', $name, number_format( $price, 2 ) );
				} elseif ( abs( (float) $list - $price ) > 0.001 ) {
					$flags[] = sprintf( '"%s" at ₱%s (price list ₱%s)', $name, number_format( $price, 2 ), number_format( (float) $list, 2 ) );
				}
			}
			$items[] = array(
				'product_id' => ! empty( $it['product_id'] ) ? (int) $it['product_id'] : null,
				'name'       => $name,
				'qty'        => $qty,
				'price'      => $price,
				'amount'     => mqp_money( $qty * $price ),
			);
		}
		if ( ! $items ) {
			return MQP_REST::error( 'The sale has no items.' );
		}
		$subtotal = mqp_money( array_sum( wp_list_pluck( $items, 'amount' ) ) );
		$discount = current_user_can( 'mq_pos_discount' ) ? min( $subtotal, max( 0, mqp_money( $p['discount'] ?? 0 ) ) ) : 0;
		$total    = mqp_money( $subtotal - $discount );
		$tendered = max( $total, mqp_money( $p['tendered'] ?? $total ) );

		$receipt = sanitize_text_field( (string) ( $p['receipt_no'] ?? '' ) );
		if ( '' === $receipt ) {
			$receipt = $branch->code . '-' . strtoupper( substr( $uuid, 0, 8 ) );
		}
		$receipt = $branch->code . '-' . preg_replace( '/^' . preg_quote( $branch->code, '/' ) . '-/', '', $receipt );

		// The sale time comes from the device: never in the future; more than a week back is flagged.
		$sold_at = mqp_local_datetime( $p['sold_at'] ?? '' );
		if ( strtotime( $sold_at ) > strtotime( mqp_now() ) + 5 * MINUTE_IN_SECONDS ) {
			$sold_at = mqp_now();
		}
		if ( strtotime( $sold_at ) < strtotime( mqp_now() ) - WEEK_IN_SECONDS ) {
			$flags[] = 'dated ' . mysql2date( 'M j, Y g:i A', $sold_at );
		}
		// A cashier sells at their own shop, or the shop they clocked in at.
		$home  = (int) get_user_meta( get_current_user_id(), 'mqp_branch_id', true );
		$shift = MQP_Auth::open_attendance( get_current_user_id() );
		if ( ! current_user_can( 'mq_view_dashboard' ) && $home && (int) $branch->id !== $home && ( ! $shift || (int) $shift['branch_id'] !== (int) $branch->id ) ) {
			$flags[] = 'sold at ' . $branch->name . ' (not their shop)';
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'uuid'       => $uuid,
				'receipt_no' => $receipt,
				'brand_id'   => $branch->brand_id,
				'branch_id'  => $branch->id,
				'device_id'  => substr( sanitize_key( (string) ( $p['device_id'] ?? '' ) ), 0, 20 ),
				'cashier_id' => get_current_user_id(),
				'subtotal'   => $subtotal,
				'discount'   => $discount,
				'total'      => $total,
				'tendered'   => $tendered,
				'change_due' => mqp_money( $tendered - $total ),
				'method'     => sanitize_key( (string) ( $p['method'] ?? 'cash' ) ),
				'reference'  => sanitize_text_field( (string) ( $p['reference'] ?? '' ) ),
				'status'     => 'completed',
				'sold_at'    => $sold_at,
				'synced_at'  => mqp_now(),
			)
		);
		if ( ! $ok ) {
			// The same sale sent twice at the same moment: the first one won, so this is not an error.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE uuid = %s", $uuid ) );
			if ( $existing ) {
				return self::existing( (int) $existing );
			}
			return MQP_REST::error( 'Could not save the sale (duplicate receipt number?).', 409 );
		}
		$id = (int) $wpdb->insert_id;
		// The official number: one continuous series per branch, in the order sales reach the server.
		$brand_code = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . mqp_table( 'brands' ) . ' WHERE id = %d', (int) $branch->brand_id ) );
		MQP_Numbering::assign( $table, 'official_no', $id, function () use ( $brand_code, $branch ) {
			return MQP_Numbering::pos_number( $brand_code, $branch->code );
		} );
		foreach ( $items as $it ) {
			$it['sale_id'] = $id;
			$wpdb->insert( mqp_table( 'pos_sale_items' ), $it );
		}
		$discount_txt = $discount > 0 ? ' · discount ₱' . number_format( $discount, 2 ) : '';
		MQP_Monitor::log( 'pos_sale', $receipt . ' · ₱' . number_format( $total, 2 ) . $discount_txt . ( MQP_REST_Sync::running() ? ' (offline, synced)' : '' ) );
		if ( $flags ) {
			MQP_Monitor::log( 'pos_price_flag', $receipt . ': ' . implode( '; ', $flags ), 'flagged' );
			MQP_Notify::price_flag( $receipt, $flags );
		}
		return self::load( $id );
	}

	/**
	 * The same sale sent again (a retry, or two syncs at once): the first copy is the sale. When both
	 * arrive at the same moment, the first may still be getting its official number: wait for it
	 * briefly so both answers carry the same number.
	 */
	private static function existing( $id ) {
		for ( $i = 0; $i < 30; $i++ ) {
			$sale = self::load( $id );
			if ( is_wp_error( $sale ) || ! empty( $sale['official_no'] ) ) {
				return $sale;
			}
			usleep( 100000 );
		}
		return self::load( $id );
	}

	public static function load( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'pos_sales' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return MQP_REST::error( 'Sale not found.', 404 );
		}
		$sale          = self::format( $row );
		$sale['items'] = array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'sale_id', 'product_id' ), array( 'qty', 'price', 'amount' ) );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'pos_sale_items' ) . ' WHERE sale_id = %d', $id ), ARRAY_A ) );
		return $sale;
	}

	public static function format( $r ) {
		$r              = mqp_cast_row( $r, array( 'id', 'brand_id', 'branch_id', 'cashier_id', 'voided_by' ), array( 'subtotal', 'discount', 'total', 'tendered', 'change_due' ) );
		$r['cashier']   = mqp_user_brief( $r['cashier_id'] );
		$r['voided_by'] = mqp_user_brief( $r['voided_by'] );
		return $r;
	}

	/** Branch staff only see their own branch unless they can view the dashboard. */
	private static function branch_param( WP_REST_Request $req ) {
		$branch = (int) $req->get_param( 'branch_id' );
		if ( ! current_user_can( 'mq_view_dashboard' ) ) {
			$own = (int) get_user_meta( get_current_user_id(), 'mqp_branch_id', true );
			if ( $own ) {
				$branch = $own;
			}
		}
		return $branch;
	}

	public static function list_sales( WP_REST_Request $req ) {
		global $wpdb;
		$date   = mqp_valid_date( $req->get_param( 'date' ) ) ? $req->get_param( 'date' ) : mqp_today();
		$branch = self::branch_param( $req );
		$sql    = 'SELECT * FROM ' . mqp_table( 'pos_sales' ) . ' WHERE sold_at BETWEEN %s AND %s';
		$args   = array( "$date 00:00:00", "$date 23:59:59" );
		if ( $branch ) {
			$sql   .= ' AND branch_id = %d';
			$args[] = $branch;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY sold_at DESC', $args ), ARRAY_A );
		$items = array();
		if ( $rows ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'pos_sale_items' ) . " WHERE sale_id IN ($ids)", ARRAY_A ) as $it ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints only.
				$items[ (int) $it['sale_id'] ][] = mqp_cast_row( $it, array( 'id', 'sale_id', 'product_id' ), array( 'qty', 'price', 'amount' ) );
			}
		}
		$out = array();
		foreach ( $rows as $r ) {
			$sale          = self::format( $r );
			$sale['items'] = $items[ (int) $r['id'] ] ?? array();
			$out[]         = $sale;
		}
		return $out;
	}

	public static function void_sale( WP_REST_Request $req ) {
		global $wpdb;
		$sale = self::load( (int) $req['id'] );
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return MQP_REST::error( 'Please give a reason for voiding.' );
		}
		if ( 'void' !== $sale['status'] ) {
			$wpdb->update(
				mqp_table( 'pos_sales' ),
				array( 'status' => 'void', 'void_reason' => $reason, 'voided_by' => get_current_user_id(), 'voided_at' => mqp_now() ),
				array( 'id' => $sale['id'] )
			);
		}
		return self::load( $sale['id'] );
	}

	/** End-of-day / shift summary for a branch. */
	public static function summary( WP_REST_Request $req ) {
		global $wpdb;
		$date   = mqp_valid_date( $req->get_param( 'date' ) ) ? $req->get_param( 'date' ) : mqp_today();
		$branch = self::branch_param( $req );
		$t      = mqp_table( 'pos_sales' );
		$where  = $wpdb->prepare( 'sold_at BETWEEN %s AND %s', "$date 00:00:00", "$date 23:59:59" ) . ( $branch ? $wpdb->prepare( ' AND branch_id = %d', $branch ) : '' );

		$by_method = $wpdb->get_results( "SELECT method, COUNT(*) AS count, SUM(total) AS total FROM $t WHERE status = 'completed' AND $where GROUP BY method", ARRAY_A );
		$voided    = $wpdb->get_row( "SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM $t WHERE status = 'void' AND $where", ARRAY_A );
		$items     = $wpdb->get_results(
			"SELECT i.name, SUM(i.qty) AS qty, SUM(i.amount) AS amount FROM " . mqp_table( 'pos_sale_items' ) . " i JOIN $t s ON s.id = i.sale_id
			 WHERE s.status = 'completed' AND " . str_replace( array( 'sold_at', 'branch_id' ), array( 's.sold_at', 's.branch_id' ), $where ) . '
			 GROUP BY i.name ORDER BY amount DESC',
			ARRAY_A
		);
		$total = 0;
		$count = 0;
		foreach ( $by_method as &$m ) {
			$m      = mqp_cast_row( $m, array( 'count' ), array( 'total' ) );
			$total += $m['total'];
			$count += $m['count'];
		}
		return array(
			'date'      => $date,
			'branch_id' => $branch,
			'total'     => mqp_money( $total ),
			'count'     => $count,
			'by_method' => $by_method,
			'voided'    => mqp_cast_row( $voided, array( 'count' ), array( 'total' ) ),
			'items'     => array_map( function ( $r ) {
				return mqp_cast_row( $r, array(), array( 'qty', 'amount' ) );
			}, $items ),
		);
	}
}
