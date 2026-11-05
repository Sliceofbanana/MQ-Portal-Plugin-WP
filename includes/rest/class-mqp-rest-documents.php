<?php
defined( 'ABSPATH' ) || exit;

/**
 * Invoice Manager: quotations/estimates, billing statements and invoices.
 * An invoice is a sale; creating one opens a job order in the Sales stage.
 */
class MQP_REST_Documents {

	const TYPES = array( 'quotation', 'billing', 'invoice' );

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/documents', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_items' ),
				'permission_callback' => MQP_REST::can( 'mq_sales', 'mq_view_dashboard' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_item' ),
				'permission_callback' => MQP_REST::can( 'mq_sales' ),
			),
		) );
		register_rest_route( $ns, '/documents/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_item' ),
				'permission_callback' => MQP_REST::can( 'mq_sales', 'mq_view_dashboard' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => MQP_REST::can( 'mq_sales' ),
			),
		) );
		register_rest_route( $ns, '/documents/(?P<id>\d+)/convert', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'convert_item' ),
			'permission_callback' => MQP_REST::can( 'mq_sales' ),
		) );
		register_rest_route( $ns, '/documents/(?P<id>\d+)/payments', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'payment_item' ),
			'permission_callback' => MQP_REST::can( 'mq_record_payments' ),
		) );
		register_rest_route( $ns, '/documents/(?P<id>\d+)/void', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'void_item' ),
			'permission_callback' => MQP_REST::can( 'mq_void_documents' ),
		) );
	}

	/* ---------- REST callbacks ---------- */

	public static function list_items( WP_REST_Request $req ) {
		global $wpdb;
		$d     = mqp_table( 'documents' );
		$where = array( '1=1' );
		$args  = array();

		$type = sanitize_key( (string) $req->get_param( 'type' ) );
		if ( in_array( $type, self::TYPES, true ) ) {
			$where[] = 'd.type = %s';
			$args[]  = $type;
		}
		if ( $req->get_param( 'brand_id' ) ) {
			$where[] = 'd.brand_id = %d';
			$args[]  = (int) $req->get_param( 'brand_id' );
		}
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( $status ) {
			$where[] = 'd.status = %s';
			$args[]  = $status;
		}
		if ( mqp_valid_date( $req->get_param( 'from' ) ) ) {
			$where[] = 'd.created_at >= %s';
			$args[]  = $req->get_param( 'from' ) . ' 00:00:00';
		}
		if ( mqp_valid_date( $req->get_param( 'to' ) ) ) {
			$where[] = 'd.created_at <= %s';
			$args[]  = $req->get_param( 'to' ) . ' 23:59:59';
		}
		$search = sanitize_text_field( (string) $req->get_param( 'search' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(d.number LIKE %s OR d.customer_name LIKE %s OR d.customer_company LIKE %s OR d.title LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		if ( ! self::sees_all() ) {
			$where[] = '(d.created_by = %d OR d.sales_person_id = %d OR (d.created_by = 0 AND d.sales_person_id IS NULL))';
			array_push( $args, get_current_user_id(), get_current_user_id() );
		}

		$offset = ( max( 1, (int) $req->get_param( 'page' ) ) - 1 ) * 300;
		$sql    = "SELECT d.*, j.number AS job_number, j.stage AS job_stage FROM $d d
		         LEFT JOIN " . mqp_table( 'jobs' ) . ' j ON j.id = d.job_id
		         WHERE ' . implode( ' AND ', $where ) . ' ORDER BY d.id DESC LIMIT 300 OFFSET ' . (int) $offset;
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function get_item( WP_REST_Request $req ) {
		$doc = self::load( (int) $req['id'] );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		return $doc;
	}

	public static function create_item( WP_REST_Request $req ) {
		$doc = self::create( (array) $req->get_json_params() );
		if ( ! is_wp_error( $doc ) ) {
			MQP_Monitor::log( 'document_created', $doc['number'] . ' · ₱' . number_format( (float) $doc['total'], 2 ) );
		}
		return $doc;
	}

	public static function update_item( WP_REST_Request $req ) {
		return self::update( (int) $req['id'], $req->get_json_params() );
	}

	public static function convert_item( WP_REST_Request $req ) {
		return self::convert( (int) $req['id'], sanitize_key( (string) $req->get_param( 'to' ) ) );
	}

	public static function payment_item( WP_REST_Request $req ) {
		return self::add_payment( (int) $req['id'], $req->get_json_params() );
	}

	public static function void_item( WP_REST_Request $req ) {
		global $wpdb;
		$doc = self::load( (int) $req['id'] );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'void' === $doc['status'] ) {
			return $doc;
		}
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( '' === $reason ) {
			return MQP_REST::error( 'Please give a reason for voiding.' );
		}
		$wpdb->update( mqp_table( 'documents' ), array( 'status' => 'void', 'voided_at' => mqp_now(), 'void_reason' => $reason, 'updated_at' => mqp_now() ), array( 'id' => $doc['id'] ) );
		self::bump( $doc['id'] );
		if ( $doc['job_id'] ) {
			MQP_REST_Jobs::cancel( $doc['job_id'], 'Invoice voided: ' . $reason );
		}
		return self::load( $doc['id'] );
	}

	/* ---------- Core operations (also used by offline sync) ---------- */

	/** Discounts, custom prices and changing an invoice after a payment are for admins. */
	public static function can_override() {
		return current_user_can( 'mq_price_override' );
	}

	public static function sees_all() {
		return current_user_can( 'mq_view_all_sales' ) || current_user_can( 'mq_view_dashboard' );
	}

	/** Loads a document the current user is allowed to see. */
	public static function load( $id, $check_access = true ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT d.*, j.number AS job_number, j.stage AS job_stage FROM ' . mqp_table( 'documents' ) . ' d
				 LEFT JOIN ' . mqp_table( 'jobs' ) . ' j ON j.id = d.job_id WHERE d.id = %d',
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return MQP_REST::error( 'Document not found.', 404 );
		}
		$uid = get_current_user_id();
		// Online orders (created_by 0) are visible to all sales staff until someone is assigned.
		if ( $check_access && ! self::sees_all() && (int) $row['created_by'] !== $uid && (int) $row['sales_person_id'] !== $uid && ! ( 0 === (int) $row['created_by'] && ! $row['sales_person_id'] ) ) {
			return MQP_REST::error( 'You can only open your own documents.', 403 );
		}
		$doc             = self::format( $row );
		$doc['items']    = array_map(
			function ( $r ) {
				return mqp_cast_row( $r, array( 'id', 'document_id', 'sort', 'is_estimate' ), array( 'qty', 'unit_price', 'amount' ) );
			},
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'document_items' ) . ' WHERE document_id = %d ORDER BY sort, id', $id ), ARRAY_A )
		);
		$doc['payments'] = array_map(
			function ( $r ) {
				$r                = mqp_cast_row( $r, array( 'id', 'document_id' ), array( 'amount' ) );
				$r['received_by'] = mqp_user_brief( (int) $r['received_by'] );
				return $r;
			},
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'payments' ) . ' WHERE document_id = %d ORDER BY paid_at', $id ), ARRAY_A )
		);
		$doc['children'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, number, status FROM ' . mqp_table( 'documents' ) . ' WHERE parent_id = %d', $id ), ARRAY_A );
		$doc['customer_email'] = $row['customer_id'] ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', $row['customer_id'] ) ) : '';
		$doc['parent']   = $row['parent_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, type, number FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $row['parent_id'] ), ARRAY_A ) : null;
		return $doc;
	}

	public static function format( $row ) {
		$row               = mqp_cast_row( $row, array( 'id', 'brand_id', 'branch_id', 'customer_id', 'parent_id', 'upload_id', 'job_id', 'sales_person_id', 'created_by', 'version', 'updated_by' ), array( 'subtotal', 'discount', 'total', 'amount_paid' ) );
		$row['balance']    = mqp_money( $row['total'] - $row['amount_paid'] );
		$row['created_by'] = mqp_user_brief( $row['created_by'] );
		$row['sales_person'] = mqp_user_brief( $row['sales_person_id'] );
		return $row;
	}

	/** Validates and normalises incoming document data. */
	private static function clean( array $p ) {
		$items = array();
		foreach ( (array) ( $p['items'] ?? array() ) as $i => $it ) {
			$desc = sanitize_text_field( (string) ( $it['description'] ?? '' ) );
			if ( '' === $desc ) {
				continue;
			}
			$qty   = max( 0, (float) ( $it['qty'] ?? 1 ) );
			$price = max( 0, (float) ( $it['unit_price'] ?? 0 ) );
			$items[] = array(
				'sort'        => $i,
				'description' => $desc,
				'specs'       => sanitize_textarea_field( (string) ( $it['specs'] ?? '' ) ),
				'qty'         => $qty,
				'unit'        => sanitize_text_field( (string) ( $it['unit'] ?? 'pc' ) ),
				'unit_price'  => mqp_money( $price ),
				'amount'      => mqp_money( $qty * $price ),
				'is_estimate' => empty( $it['is_estimate'] ) ? 0 : 1,
			);
		}
		$subtotal = mqp_money( array_sum( wp_list_pluck( $items, 'amount' ) ) );
		$discount = min( $subtotal, max( 0, mqp_money( $p['discount'] ?? 0 ) ) );
		$c        = (array) ( $p['customer'] ?? array() );

		return array(
			'fields' => array(
				'branch_id'        => ! empty( $p['branch_id'] ) ? (int) $p['branch_id'] : null,
				'customer_name'    => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
				'customer_company' => sanitize_text_field( (string) ( $c['company'] ?? '' ) ),
				'customer_contact' => sanitize_text_field( (string) ( $c['contact'] ?? '' ) ),
				'customer_address' => sanitize_text_field( (string) ( $c['address'] ?? '' ) ),
				'title'            => sanitize_text_field( (string) ( $p['title'] ?? '' ) ),
				'subtotal'         => $subtotal,
				'discount'         => $discount,
				'total'            => mqp_money( $subtotal - $discount ),
				'terms'            => sanitize_textarea_field( (string) ( $p['terms'] ?? '' ) ),
				'notes'            => sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ),
				'valid_until'      => mqp_valid_date( $p['valid_until'] ?? '' ) ? $p['valid_until'] : null,
				'due_date'         => mqp_valid_date( $p['due_date'] ?? '' ) ? $p['due_date'] : null,
			),
			'email'  => sanitize_email( (string) ( $c['email'] ?? '' ) ),
			'items'  => $items,
		);
	}

	private static function save_items( $doc_id, array $items ) {
		global $wpdb;
		$table = mqp_table( 'document_items' );
		$wpdb->delete( $table, array( 'document_id' => $doc_id ) );
		foreach ( $items as $it ) {
			$it['document_id'] = $doc_id;
			$wpdb->insert( $table, $it );
		}
	}

	/**
	 * Creates a document. Idempotent on payload uuid (offline devices may retry).
	 */
	public static function create( array $p, $trusted = false ) {
		global $wpdb;
		$table = mqp_table( 'documents' );
		$type  = sanitize_key( (string) ( $p['type'] ?? '' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return MQP_REST::error( 'Unknown document type.' );
		}
		$uuid = mqp_is_uuid( $p['uuid'] ?? '' ) ? strtolower( $p['uuid'] ) : wp_generate_uuid4();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE uuid = %s", $uuid ) );
		if ( $existing ) {
			return self::load( (int) $existing, ! $trusted ); // A re-send: only for someone who may see it.
		}

		$brand = MQP_REST::brand( (int) ( $p['brand_id'] ?? 0 ) );
		if ( ! $brand ) {
			return MQP_REST::error( 'Please choose a brand.' );
		}
		$clean = self::clean( $p );
		if ( ! $clean['items'] ) {
			return MQP_REST::error( 'Add at least one item.' );
		}
		if ( ! $trusted && $clean['fields']['discount'] > 0 && ! self::can_override() ) {
			return MQP_REST::error( 'Only an admin can give a discount.', 403 );
		}
		// Links to another document or a customer upload must be ones this person may use.
		if ( ! $trusted && ! empty( $p['parent_id'] ) && is_wp_error( self::load( (int) $p['parent_id'] ) ) ) {
			return MQP_REST::error( 'The linked document was not found.', 404 );
		}
		if ( ! $trusted && ! empty( $p['upload_id'] ) && ( ! current_user_can( 'mq_sales' ) || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'uploads' ) . " WHERE id = %d AND status = 'new'", (int) $p['upload_id'] ) ) ) ) {
			return MQP_REST::error( 'This customer upload was already handled.', 409 );
		}
		if ( '' === $clean['fields']['customer_name'] ) {
			return MQP_REST::error( 'Customer name is required.' );
		}
		if ( '' === $clean['fields']['terms'] ) {
			$clean['fields']['terms'] = MQP_Settings::get( 'quotation' === $type ? 'quotation_terms' : ( 'billing' === $type ? 'billing_terms' : 'invoice_terms' ) );
		}

		$sales_person = null;
		if ( ! empty( $p['sales_person_id'] ) && current_user_can( 'mq_assign_sales' ) ) {
			$sales_person = (int) $p['sales_person_id'];
		}

		$f = $clean['fields'];
		$now = ! empty( $p['created_at'] ) ? mqp_local_datetime( $p['created_at'] ) : mqp_now();
		$row = array_merge(
			$f,
			array(
				'uuid'            => $uuid,
				'type'            => $type,
				'brand_id'        => (int) $brand->id,
				'customer_id'     => MQP_REST::upsert_customer( $f['customer_name'], $f['customer_company'], $f['customer_contact'], $clean['email'], $f['customer_address'] ),
				'status'          => 'invoice' === $type ? 'unpaid' : 'open',
				'parent_id'       => ! empty( $p['parent_id'] ) ? (int) $p['parent_id'] : null,
				'upload_id'       => ! empty( $p['upload_id'] ) ? (int) $p['upload_id'] : null,
				'sales_person_id' => $sales_person,
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => mqp_now(),
			)
		);
		if ( ! $brand->has_branches ) {
			$row['branch_id'] = null;
		}
		$wpdb->insert( $table, $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return MQP_REST::error( 'Could not save the document.', 500 );
		}
		// Numbered per branch (EP-M1-INV-2026-00001) when the brand has branches and one is chosen.
		$branch_code = ! empty( $row['branch_id'] ) ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', (int) $row['branch_id'] ) ) : '';
		MQP_Numbering::assign( $table, 'number', $id, function () use ( $type, $brand, $branch_code ) {
			return MQP_Numbering::document_number( $type, $brand->code, $branch_code );
		} );
		self::save_items( $id, $clean['items'] );

		if ( $row['upload_id'] ) {
			$wpdb->update( mqp_table( 'uploads' ), array( 'status' => 'converted', 'document_id' => $id, 'handled_by' => get_current_user_id() ), array( 'id' => $row['upload_id'] ) );
		}
		if ( 'invoice' === $type ) {
			MQP_REST_Jobs::create_from_invoice( $id );
		}
		return self::load( $id, false );
	}

	public static function update( $id, array $p ) {
		global $wpdb;
		$doc = self::load( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( in_array( $doc['status'], array( 'void', 'converted' ), true ) ) {
			return MQP_REST::error( 'This document can no longer be edited.' );
		}
		if ( 'invoice' === $doc['type'] && $doc['amount_paid'] > 0 && ! self::can_override() ) {
			return MQP_REST::error( 'A payment was recorded on this invoice, so only an admin can change it.', 403 );
		}
		// Someone else saved (or added a payment) since this was opened: don't overwrite their change.
		if ( isset( $p['version'] ) && (int) $p['version'] !== (int) $doc['version'] ) {
			return self::conflict( $doc );
		}
		$clean = self::clean( $p );
		if ( ! $clean['items'] ) {
			return MQP_REST::error( 'Add at least one item.' );
		}
		if ( abs( $clean['fields']['discount'] - (float) $doc['discount'] ) > 0.001 && ! self::can_override() ) {
			return MQP_REST::error( 'Only an admin can give or change a discount.', 403 );
		}
		$f = $clean['fields'];
		$brand = MQP_REST::brand( $doc['brand_id'] );
		if ( ! $brand->has_branches ) {
			$f['branch_id'] = null;
		}
		$f['customer_id'] = MQP_REST::upsert_customer( $f['customer_name'], $f['customer_company'], $f['customer_contact'], $clean['email'], $f['customer_address'] );
		$f['updated_at']  = mqp_now();
		if ( 'invoice' === $doc['type'] ) {
			$f['status'] = self::payment_status( $f['total'], $doc['amount_paid'] );
		}
		// Saved only if nobody else saved in the meantime (the version moves on with every change).
		$f['version']    = (int) $doc['version'] + 1;
		$f['updated_by'] = get_current_user_id();
		$won             = $wpdb->update( mqp_table( 'documents' ), $f, array( 'id' => $id, 'version' => (int) $doc['version'] ) );
		if ( 1 !== (int) $won ) {
			return self::conflict( self::load( $id, false ) );
		}
		self::save_items( $id, $clean['items'] );
		if ( $doc['job_id'] ) {
			MQP_REST_Jobs::sync_from_invoice( $doc['job_id'] );
		}
		if ( abs( $f['total'] - (float) $doc['total'] ) > 0.001 ) {
			MQP_Monitor::log( 'document_changed', $doc['number'] . ' · total ₱' . number_format( (float) $doc['total'], 2 ) . ' → ₱' . number_format( $f['total'], 2 ) . ( $doc['amount_paid'] > 0 ? ' · after a payment' : '' ) );
		}
		return self::load( $id );
	}

	/** Every change to a document moves its version on, so an older copy can't overwrite it. */
	public static function bump( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'documents' ) . ' SET version = version + 1, updated_by = %d WHERE id = %d', get_current_user_id(), (int) $id ) );
	}

	/** "Maria changed this at 2:14 PM": the save is refused (409) and nothing is lost. */
	private static function conflict( $doc ) {
		$who  = ! is_wp_error( $doc ) && ! empty( $doc['updated_by'] ) ? mqp_user_brief( $doc['updated_by'] ) : null;
		$when = ! is_wp_error( $doc ) ? mysql2date( 'g:i A', $doc['updated_at'] ) : '';
		$mine = $who && (int) $who['id'] === get_current_user_id();
		return new WP_Error( 'mqp_conflict', $mine ? 'You saved this document at ' . $when . ' in another window or device. Reload to see that version, then make your change again.' : ( $who ? $who['name'] : 'Someone else' ) . ' changed this document at ' . $when . ' while you were editing it. Reload to see their changes, then make yours again.', array( 'status' => 409 ) );
	}

	/** quotation → billing/invoice, billing → invoice. Returns the new document. */
	public static function convert( $id, $to, $check_access = true ) {
		global $wpdb;
		$doc = self::load( $id, $check_access );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$allowed = array( 'quotation' => array( 'billing', 'invoice' ), 'billing' => array( 'invoice' ) );
		if ( empty( $allowed[ $doc['type'] ] ) || ! in_array( $to, $allowed[ $doc['type'] ], true ) ) {
			return MQP_REST::error( 'This conversion is not allowed.' );
		}
		if ( 'void' === $doc['status'] ) {
			return MQP_REST::error( 'Voided documents cannot be converted.' );
		}
		foreach ( $doc['children'] as $child ) {
			if ( $child['type'] === $to && 'void' !== $child['status'] ) {
				return self::load( (int) $child['id'], $check_access );
			}
		}
		// Claim it: only one request at a time turns this document into another.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'documents' ) . " SET status = 'converting' WHERE id = %d AND status = %s", $doc['id'], $doc['status'] ) );
		if ( ! $claimed ) {
			$fresh = self::load( $id, false );
			foreach ( is_wp_error( $fresh ) ? array() : $fresh['children'] as $child ) {
				if ( $child['type'] === $to && 'void' !== $child['status'] ) {
					return self::load( (int) $child['id'], $check_access );
				}
			}
			return MQP_REST::error( 'This document is being converted right now. Refresh in a moment.', 409 );
		}
		$new = self::create(
			array(
				'type'            => $to,
				'brand_id'        => $doc['brand_id'],
				'branch_id'       => $doc['branch_id'],
				'customer'        => array(
					'name'    => $doc['customer_name'],
					'company' => $doc['customer_company'],
					'contact' => $doc['customer_contact'],
					'address' => $doc['customer_address'],
				),
				'title'           => $doc['title'],
				'items'           => $doc['items'],
				'discount'        => $doc['discount'],
				'notes'           => $doc['notes'],
				'due_date'        => $doc['due_date'],
				'parent_id'       => $doc['id'],
				'upload_id'       => $doc['upload_id'],
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			$wpdb->update( mqp_table( 'documents' ), array( 'status' => $doc['status'] ), array( 'id' => $doc['id'] ) );
			return $new;
		}
		if ( $doc['sales_person_id'] ) {
			$wpdb->update( mqp_table( 'documents' ), array( 'sales_person_id' => $doc['sales_person_id'] ), array( 'id' => $new['id'] ) );
			if ( $new['job_id'] ) {
				MQP_REST_Jobs::set_sales_person( $new['job_id'], $doc['sales_person_id'] );
			}
		}
		$wpdb->update( mqp_table( 'documents' ), array( 'status' => 'converted', 'updated_at' => mqp_now() ), array( 'id' => $doc['id'] ) );
		self::bump( $doc['id'] );
		return self::load( $new['id'], $check_access );
	}

	public static function payment_status( $total, $paid ) {
		if ( $paid <= 0 ) {
			return 'unpaid';
		}
		return $paid + 0.001 >= $total ? 'paid' : 'partial';
	}

	/**
	 * Records a payment. Paying a quotation or billing statement converts it to an
	 * invoice first, because "an invoice is issued once the downpayment is made".
	 */
	public static function add_payment( $id, array $p ) {
		global $wpdb;
		$uuid = mqp_is_uuid( $p['uuid'] ?? '' ) ? strtolower( $p['uuid'] ) : wp_generate_uuid4();
		$dupe = $wpdb->get_var( $wpdb->prepare( 'SELECT document_id FROM ' . mqp_table( 'payments' ) . ' WHERE uuid = %s', $uuid ) );
		if ( $dupe ) {
			return self::load( (int) $dupe, false );
		}

		$doc = self::load( $id );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( 'void' === $doc['status'] ) {
			return MQP_REST::error( 'Cannot record a payment on a voided document.' );
		}
		if ( 'invoice' !== $doc['type'] ) {
			$doc = self::convert( $doc['id'], 'invoice' );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
		}

		$amount = mqp_money( $p['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return MQP_REST::error( 'Enter the amount paid.' );
		}
		$method  = sanitize_key( (string) ( $p['method'] ?? 'cash' ) );
		$paid_at = ! empty( $p['paid_at'] ) ? mqp_local_datetime( $p['paid_at'] ) : mqp_now();
		if ( strtotime( $paid_at ) > strtotime( mqp_now() ) + 5 * MINUTE_IN_SECONDS ) {
			$paid_at = mqp_now();
		}
		if ( strtotime( $paid_at ) < strtotime( mqp_now() ) - WEEK_IN_SECONDS && ! self::can_override() ) {
			return MQP_REST::error( 'Payments older than a week can only be recorded by an admin.', 403 );
		}
		$wpdb->insert(
			mqp_table( 'payments' ),
			array(
				'uuid'        => $uuid,
				'document_id' => $doc['id'],
				'amount'      => $amount,
				'method'      => $method ? $method : 'cash',
				'reference'   => sanitize_text_field( (string) ( $p['reference'] ?? '' ) ),
				'notes'       => sanitize_text_field( (string) ( $p['notes'] ?? '' ) ),
				'received_by' => get_current_user_id(),
				'paid_at'     => $paid_at,
				'created_at'  => mqp_now(),
			)
		);
		$paid = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . mqp_table( 'payments' ) . ' WHERE document_id = %d', $doc['id'] ) );
		$wpdb->update(
			mqp_table( 'documents' ),
			array( 'amount_paid' => $paid, 'status' => self::payment_status( $doc['total'], $paid ), 'updated_at' => mqp_now() ),
			array( 'id' => $doc['id'] )
		);
		self::bump( $doc['id'] );
		if ( $doc['job_id'] ) {
			MQP_REST_Jobs::event( $doc['job_id'], 'payment', '', '', sprintf( '₱%s received (%s)', number_format( $amount, 2 ), $method ) );
			MQP_REST_Jobs::upsert_commission( (int) $doc['job_id'] );
			MQP_REST_Jobs::maybe_earn( (int) $doc['job_id'] );
		}
		if ( MQP_REST_Sync::running() ) { // Payments through the website's REST path are logged by MQP_Monitor.
			MQP_Monitor::log( 'payment_recorded', $doc['number'] . ' · ₱' . number_format( $amount, 2 ) . ' · ' . $method . ' (offline, synced)' );
		}
		return self::load( $doc['id'], false );
	}
}
