<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sales → Customers: everyone the shop dealt with (documents, website quotations, uploads and
 * online orders) and each customer's history. Sales staff who only see their own documents
 * (no "view all sales") only see their own documents here too.
 */
class MQP_REST_Customers {

	const PER_PAGE = 50;

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/customers/directory', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_items' ),
			'permission_callback' => MQP_REST::can( 'mq_sales' ),
		) );
		register_rest_route( $ns, '/customers/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_item' ),
			'permission_callback' => MQP_REST::can( 'mq_sales' ),
		) );
		// Data Privacy Act: the customer asked to remove their personal data (Owner / admins).
		register_rest_route( $ns, '/customers/(?P<id>\d+)/personal-data', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'erase' ),
			'permission_callback' => MQP_REST::can( 'mq_manage' ),
		) );
	}

	/** Extra condition on documents ($a) for staff who only see their own. */
	private static function own( $a ) {
		global $wpdb;
		if ( MQP_REST_Documents::sees_all() ) {
			return '';
		}
		$uid = get_current_user_id();
		return $wpdb->prepare( " AND ($a.created_by = %d OR $a.sales_person_id = %d OR ($a.created_by = 0 AND $a.sales_person_id IS NULL))", $uid, $uid ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Uploads and orders belong to a customer by the same email or mobile number. */
	private static function match( $email_col, $phone_col, $c = 'c' ) {
		return "(($c.email <> '' AND $email_col = $c.email) OR ($c.contact <> '' AND $phone_col = $c.contact))";
	}

	public static function list_items( WP_REST_Request $req ) {
		global $wpdb;
		$c    = mqp_table( 'customers' );
		$d    = mqp_table( 'documents' );
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$q    = sanitize_text_field( (string) $req->get_param( 'search' ) );
		$where = '1=1';
		if ( '' !== $q ) {
			$like  = '%' . $wpdb->esc_like( $q ) . '%';
			$where = $wpdb->prepare( '(c.name LIKE %s OR c.company LIKE %s OR c.contact LIKE %s OR c.email LIKE %s)', $like, $like, $like, $like );
		}
		$uploads = '(SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) . ' u WHERE ' . self::match( 'u.email', 'u.contact' ) . ')';
		$orders  = '(SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) . ' o WHERE ' . self::match( 'o.customer_email', 'o.customer_phone' ) . ')';
		$sql     = "SELECT c.*, COUNT(d.id) AS docs,
				COALESCE(SUM(CASE WHEN d.type = 'invoice' AND d.status <> 'void' THEN d.total END), 0) AS invoiced,
				MAX(d.source = 'website') AS website,
				GREATEST(c.created_at, COALESCE(MAX(d.created_at), c.created_at)) AS last_at,
				$uploads AS uploads, $orders AS orders
			FROM $c c LEFT JOIN $d d ON d.customer_id = c.id" . self::own( 'd' ) . "
			WHERE $where GROUP BY c.id ORDER BY last_at DESC, c.id DESC LIMIT " . ( self::PER_PAGE + 1 ) . ' OFFSET ' . ( ( $page - 1 ) * self::PER_PAGE );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$more = count( $rows ) > self::PER_PAGE;
		return array(
			'items' => array_map( function ( $r ) {
				$r            = mqp_cast_row( $r, array( 'id', 'docs', 'uploads', 'orders' ), array( 'invoiced' ) );
				$r['website'] = (bool) $r['website'] || $r['uploads'] > 0 || $r['orders'] > 0;
				return $r;
			}, array_slice( $rows, 0, self::PER_PAGE ) ),
			'more'  => $more,
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $c c WHERE $where" ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public static function erase( WP_REST_Request $req ) {
		$res = MQP_Privacy::erase_customer( (int) $req['id'] );
		if ( is_wp_error( $res ) ) {
			return MQP_REST::error( $res->get_error_message(), 404 );
		}
		return array( 'ok' => true, 'removed' => $res );
	}

	public static function get_item( WP_REST_Request $req ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', (int) $req['id'] ), ARRAY_A );
		if ( ! $c ) {
			return MQP_REST::error( 'Customer not found.', 404 );
		}
		$d    = mqp_table( 'documents' );
		$docs = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.type, d.number, d.status, d.total, d.amount_paid, d.title, d.source, d.created_at, j.id AS job_id, j.number AS job_number, j.stage AS job_stage
			 FROM $d d LEFT JOIN " . mqp_table( 'jobs' ) . ' j ON j.id = d.job_id WHERE d.customer_id = %d' . self::own( 'd' ) . ' ORDER BY d.id DESC LIMIT 200',
			$c['id']
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$docs = array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'job_id' ), array( 'total', 'amount_paid' ) );
		}, $docs );

		$cx      = array( 'email' => $c['email'], 'contact' => $c['contact'] );
		$uploads = $wpdb->get_results( $wpdb->prepare(
			'SELECT u.id, u.status, u.notes, u.created_at, u.document_id, (SELECT COUNT(*) FROM ' . mqp_table( 'files' ) . ' f WHERE f.upload_id = u.id) AS file_count
			 FROM ' . mqp_table( 'uploads' ) . " u WHERE (%s <> '' AND u.email = %s) OR (%s <> '' AND u.contact = %s) ORDER BY u.id DESC LIMIT 100",
			$cx['email'], $cx['email'], $cx['contact'], $cx['contact']
		), ARRAY_A );
		$uploads = array_map( function ( $r ) {
			$r              = mqp_cast_row( $r, array( 'id', 'document_id', 'file_count' ) );
			$r['reference'] = sprintf( 'U-%05d', $r['id'] );
			return $r;
		}, $uploads );
		$orders = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, reference, service, total, payment_status, delivery_type, created_at FROM ' . mqp_table( 'orders' ) . " WHERE (%s <> '' AND customer_email = %s) OR (%s <> '' AND customer_phone = %s) ORDER BY id DESC LIMIT 100",
			$cx['email'], $cx['email'], $cx['contact'], $cx['contact']
		), ARRAY_A );
		$orders = array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id' ), array( 'total' ) );
		}, $orders );

		$inv = array_filter( $docs, function ( $r ) {
			return 'invoice' === $r['type'] && 'void' !== $r['status'];
		} );
		$invoiced = mqp_money( array_sum( wp_list_pluck( $inv, 'total' ) ) );
		$paid     = mqp_money( array_sum( wp_list_pluck( $inv, 'amount_paid' ) ) );
		$c        = mqp_cast_row( $c, array( 'id' ) );
		$c['summary'] = array(
			'invoiced'   => $invoiced,
			'paid'       => $paid,
			'balance'    => mqp_money( $invoiced - $paid ),
			'quotations' => count( array_filter( $docs, function ( $r ) {
				return 'quotation' === $r['type'];
			} ) ),
			'jobs'       => count( array_filter( $docs, function ( $r ) {
				return (bool) $r['job_id'];
			} ) ),
		);
		$c['documents'] = $docs;
		$c['uploads']   = $uploads;
		$c['orders']    = $orders;
		return $c;
	}
}
