<?php
defined( 'ABSPATH' ) || exit;

/** Staff side of online orders: inbox, payment verification, and the price list editor. */
class MQP_REST_Orders {

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/orders', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_items' ),
			'permission_callback' => MQP_REST::can( 'mq_sales', 'mq_view_dashboard' ),
		) );
		register_rest_route( $ns, '/orders/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_item' ),
				'permission_callback' => MQP_REST::can( 'mq_sales', 'mq_view_dashboard' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_item' ),
				'permission_callback' => MQP_REST::can( 'mq_manage' ), // Admins and the Owner.
			),
		) );
		register_rest_route( $ns, '/orders/(?P<id>\d+)/verify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'verify' ),
			'permission_callback' => MQP_REST::can( 'mq_record_payments' ),
		) );
		register_rest_route( $ns, '/orders/(?P<id>\d+)/reject', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reject' ),
			'permission_callback' => MQP_REST::can( 'mq_record_payments' ),
		) );
		register_rest_route( $ns, '/orders/(?P<id>\d+)/resend', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'resend' ),
			'permission_callback' => MQP_REST::can( 'mq_sales' ),
		) );
		register_rest_route( $ns, '/admin/order-config', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_config' ), 'permission_callback' => MQP_REST::can( 'mq_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_config' ), 'permission_callback' => MQP_REST::can( 'mq_manage' ) ),
		) );
	}

	private static function format( array $o ) {
		$o['status']        = MQP_Order::customer_status( $o );
		$o['proof']         = null;
		if ( $o['payment_proof_id'] ) {
			global $wpdb;
			$row        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE id = %d', $o['payment_proof_id'] ), ARRAY_A );
			$o['proof'] = $row ? MQP_Files::format( $row ) : null;
		}
		$o['verified_by'] = mqp_user_brief( $o['verified_by'] );
		unset( $o['ip'] );
		return $o;
	}

	public static function list_items( WP_REST_Request $req ) {
		global $wpdb;
		$where  = array( '1=1' );
		$args   = array();
		$status = sanitize_key( (string) $req->get_param( 'payment_status' ) );
		if ( in_array( $status, array( 'pending', 'submitted', 'verified', 'rejected' ), true ) ) {
			$where[] = 'payment_status = %s';
			$args[]  = $status;
		}
		$search = sanitize_text_field( (string) $req->get_param( 'search' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR payment_reference LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$sql = 'SELECT id FROM ' . mqp_table( 'orders' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200';
		$ids = $wpdb->get_col( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = self::format( MQP_Order::load( (int) $id ) );
		}
		return $out;
	}

	public static function get_item( WP_REST_Request $req ) {
		$o = MQP_Order::load( (int) $req['id'] );
		return $o ? self::format( $o ) : MQP_REST::error( 'Order not found.', 404 );
	}

	public static function delete_item( WP_REST_Request $req ) {
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return MQP_REST::error( 'Give a reason for deleting this order.' );
		}
		return MQP_Order::delete( (int) $req['id'], $reason );
	}

	public static function verify( WP_REST_Request $req ) {
		$o = MQP_Order::verify( (int) $req['id'] );
		return is_wp_error( $o ) ? $o : self::format( $o );
	}

	public static function reject( WP_REST_Request $req ) {
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return MQP_REST::error( 'Tell the customer what is wrong with the payment.' );
		}
		$o = MQP_Order::reject( (int) $req['id'], $reason );
		return is_wp_error( $o ) ? $o : self::format( $o );
	}

	public static function resend( WP_REST_Request $req ) {
		$o = MQP_Order::load( (int) $req['id'] );
		if ( ! $o ) {
			return MQP_REST::error( 'Order not found.', 404 );
		}
		MQP_Order::email_received( $o );
		return array( 'ok' => true );
	}

	public static function get_config() {
		return MQP_Order::config();
	}

	public static function save_config( WP_REST_Request $req ) {
		return MQP_Order::save_config( (array) $req->get_json_params() );
	}
}
