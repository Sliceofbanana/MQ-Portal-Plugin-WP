<?php
defined( 'ABSPATH' ) || exit;

/**
 * Offline outbox sync. Devices queue POS sales, new documents and payments while
 * offline and send them here in order once back online. Every op carries a uuid,
 * so sending the same op twice has no effect.
 */
class MQP_REST_Sync {

	/** True while records from an offline device are being saved. */
	private static $running = false;

	public static function running() {
		return self::$running;
	}

	public static function routes() {
		register_rest_route( MQP_REST::NS, '/sync', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sync' ),
			'permission_callback' => MQP_REST::can( 'mq_pos', 'mq_sales' ),
		) );
	}

	public static function sync( WP_REST_Request $req ) {
		$ops     = array_slice( (array) $req->get_param( 'ops' ), 0, 100 );
		$results = array();
		self::$running = true;
		foreach ( $ops as $op ) {
			$op_id   = (string) ( $op['id'] ?? '' );
			$kind    = (string) ( $op['kind'] ?? '' );
			$payload = (array) ( $op['payload'] ?? array() );
			$res     = self::apply( $kind, $payload );
			if ( is_wp_error( $res ) ) {
				$data      = $res->get_error_data();
				$status    = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
				$results[] = array( 'id' => $op_id, 'ok' => false, 'error' => $res->get_error_message(), 'retry' => $status >= 500 );
			} else {
				$results[] = array( 'id' => $op_id, 'ok' => true, 'data' => $res );
			}
		}
		self::$running = false;
		return array( 'results' => $results );
	}

	private static function apply( $kind, array $payload ) {
		global $wpdb;
		switch ( $kind ) {
			case 'pos_sale':
				if ( ! current_user_can( 'mq_pos' ) ) {
					return MQP_REST::error( 'No POS permission.', 403 );
				}
				return MQP_REST_POS::create_sale( $payload );
			case 'document':
				if ( ! current_user_can( 'mq_sales' ) ) {
					return MQP_REST::error( 'No Sales permission.', 403 );
				}
				$doc = MQP_REST_Documents::create( $payload );
				if ( ! is_wp_error( $doc ) ) {
					MQP_Monitor::log( 'document_created', $doc['number'] . ' · ₱' . number_format( (float) $doc['total'], 2 ) . ' (offline, synced)' );
				}
				return $doc;
			case 'payment':
				if ( ! current_user_can( 'mq_record_payments' ) ) {
					return MQP_REST::error( 'No permission to record payments.', 403 );
				}
				$doc_id = (int) ( $payload['document_id'] ?? 0 );
				if ( ! $doc_id && mqp_is_uuid( $payload['document_uuid'] ?? '' ) ) {
					$doc_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'documents' ) . ' WHERE uuid = %s', strtolower( $payload['document_uuid'] ) ) );
				}
				if ( ! $doc_id ) {
					return MQP_REST::error( 'Document for this payment was not found.', 404 );
				}
				return MQP_REST_Documents::add_payment( $doc_id, $payload );
		}
		return MQP_REST::error( 'Unknown operation.' );
	}
}
