<?php
defined( 'ABSPATH' ) || exit;

/**
 * Job orders: Sales → Creatives → Production → QC → Ready for delivery → Delivered.
 */
class MQP_REST_Jobs {

	const STAGES = array( 'sales', 'creatives', 'production', 'qc', 'ready', 'delivered', 'cancelled' );

	/** action => [ required cap, allowed from-stages, to-stage (null = stays) ] */
	const ACTIONS = array(
		'save_specs'    => array( 'mq_sales', array( 'sales' ), null ),
		'assign'        => array( 'mq_assign_sales', array( 'sales', 'creatives', 'production', 'qc', 'ready' ), null ),
		'to_creatives'  => array( 'mq_sales', array( 'sales' ), 'creatives' ),
		// Basic document printing that needs no checking/editing skips Creatives (owner, 2026-11-05).
		'approve_print' => array( 'mq_sales', array( 'sales' ), 'production' ),
		'needs_editing' => array( 'mq_creatives', array( 'creatives' ), null ),
		'back_to_sales' => array( 'mq_creatives', array( 'creatives' ), 'sales' ),
		'approve_files' => array( 'mq_creatives', array( 'creatives' ), 'production' ),
		'printed'       => array( 'mq_production', array( 'production' ), 'qc' ),
		'qc_approve'    => array( 'mq_qc', array( 'qc' ), 'ready' ),
		'qc_reject'     => array( 'mq_qc', array( 'qc' ), 'production' ),
		'notify'        => array( 'mq_deliver', array( 'ready' ), null ),
		'email_ready'   => array( 'mq_deliver', array( 'ready' ), null ),
		'delivered'     => array( 'mq_deliver', array( 'ready' ), 'delivered' ),
		'priority'      => array( 'mq_sales', array( 'sales', 'creatives', 'production', 'qc', 'ready' ), null ),
		'note'          => array( 'mq_access_portal', array( 'sales', 'creatives', 'production', 'qc', 'ready', 'delivered' ), null ),
	);

	public static function routes() {
		$ns   = MQP_REST::NS;
		$read = MQP_REST::can( 'mq_sales', 'mq_creatives', 'mq_production', 'mq_qc', 'mq_view_dashboard' );
		register_rest_route( $ns, '/jobs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_items' ),
			'permission_callback' => $read,
		) );
		register_rest_route( $ns, '/jobs/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_item' ),
			'permission_callback' => $read,
		) );
		register_rest_route( $ns, '/jobs/(?P<id>\d+)/action', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'action' ),
			'permission_callback' => MQP_REST::can( 'mq_access_portal' ),
		) );
		register_rest_route( $ns, '/jobs/(?P<id>\d+)/files', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_files' ),
			'permission_callback' => MQP_REST::can( 'mq_sales', 'mq_creatives', 'mq_production', 'mq_qc' ),
		) );
		register_rest_route( $ns, '/board', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'board' ),
			'permission_callback' => MQP_REST::can( 'mq_view_board', 'mq_production', 'mq_qc', 'mq_view_dashboard' ),
		) );
	}

	/** Sales-only staff (no other department, no "view all") only see their own job orders. */
	private static function restricted_to_own() {
		foreach ( array( 'mq_view_all_sales', 'mq_view_dashboard', 'mq_creatives', 'mq_production', 'mq_qc' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return false;
			}
		}
		return true;
	}

	private static function base_select() {
		return 'SELECT j.*, d.number AS invoice_number, d.total, d.amount_paid, d.status AS invoice_status, d.created_by AS invoice_created_by,
		        (SELECT COUNT(*) FROM ' . mqp_table( 'files' ) . ' f WHERE f.job_id = j.id) AS file_count
		        FROM ' . mqp_table( 'jobs' ) . ' j JOIN ' . mqp_table( 'documents' ) . ' d ON d.id = j.document_id';
	}

	public static function list_items( WP_REST_Request $req ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();

		$stages = array_filter( array_map( 'sanitize_key', explode( ',', (string) $req->get_param( 'stage' ) ) ) );
		$stages = array_intersect( $stages, self::STAGES );
		if ( $stages ) {
			$where[] = 'j.stage IN (' . implode( ',', array_fill( 0, count( $stages ), '%s' ) ) . ')';
			$args    = array_merge( $args, array_values( $stages ) );
		}
		if ( $req->get_param( 'brand_id' ) ) {
			$where[] = 'j.brand_id = %d';
			$args[]  = (int) $req->get_param( 'brand_id' );
		}
		$search = sanitize_text_field( (string) $req->get_param( 'search' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(j.number LIKE %s OR d.number LIKE %s OR j.customer_name LIKE %s OR j.title LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		if ( $req->get_param( 'mine' ) || self::restricted_to_own() ) {
			$where[] = '(j.sales_person_id = %d OR d.created_by = %d OR (d.created_by = 0 AND j.sales_person_id IS NULL))';
			array_push( $args, get_current_user_id(), get_current_user_id() );
		}
		$offset = ( max( 1, (int) $req->get_param( 'page' ) ) - 1 ) * 300;
		$sql    = self::base_select() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY j.priority DESC, j.due_date IS NULL, j.due_date, j.id DESC LIMIT 300 OFFSET ' . (int) $offset;
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	/** Creatives and Production don't see prices. */
	public static function sees_prices() {
		return current_user_can( 'mq_sales' ) || current_user_can( 'mq_view_dashboard' );
	}

	public static function format( $r ) {
		$r                 = mqp_cast_row( $r, array( 'id', 'document_id', 'brand_id', 'branch_id', 'specs_complete', 'needs_editing', 'sales_person_id', 'priority', 'created_by', 'invoice_created_by', 'file_count' ), array( 'total', 'amount_paid' ) );
		$r['balance']      = mqp_money( $r['total'] - $r['amount_paid'] );
		$r['sales_person'] = mqp_user_brief( $r['sales_person_id'] );
		$r['gate']         = self::gate( $r );
		if ( ! self::sees_prices() ) {
			unset( $r['total'], $r['amount_paid'], $r['balance'] );
		}
		return $r;
	}

	/**
	 * Payment needed before a job order leaves Sales (portal → Settings → Company & documents):
	 * none, any payment, the downpayment (e.g. 60%) or the full amount.
	 */
	public static function payment_ok( $total, $paid ) {
		$total = (float) $total;
		$paid  = (float) $paid;
		switch ( MQP_Settings::get( 'gate_payment' ) ) {
			case 'none':
				return true;
			case 'downpayment':
				return $paid + 0.005 >= mqp_money( $total * (float) MQP_Settings::get( 'downpayment_percent' ) / 100 ) && ( $paid > 0 || $total <= 0 );
			case 'full':
				return $paid + 0.005 >= $total;
			default:
				return $paid > 0;
		}
	}

	/** Conditions for leaving the Sales stage. */
	public static function gate( $job ) {
		return array(
			'paid'         => self::payment_ok( $job['total'] ?? 0, $job['amount_paid'] ?? 0 ),
			'specs'        => (bool) $job['specs_complete'],
			'sales_person' => (bool) $job['sales_person_id'],
		);
	}

	public static function load( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( self::base_select() . ' WHERE j.id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return MQP_REST::error( 'Job order not found.', 404 );
		}
		$uid = get_current_user_id();
		if ( self::restricted_to_own() && (int) $row['sales_person_id'] !== $uid && (int) $row['invoice_created_by'] !== $uid && ! ( 0 === (int) $row['invoice_created_by'] && ! $row['sales_person_id'] ) ) {
			return MQP_REST::error( 'You can only open your own job orders.', 403 );
		}
		$job           = self::format( $row );
		$job['items']  = array_map(
			function ( $r ) {
				return mqp_cast_row( $r, array( 'id' ), array( 'qty', 'unit_price', 'amount' ) );
			},
			$wpdb->get_results( $wpdb->prepare( 'SELECT id, description, specs, qty, unit, unit_price, amount FROM ' . mqp_table( 'document_items' ) . ' WHERE document_id = %d ORDER BY sort, id', $row['document_id'] ), ARRAY_A )
		);
		$job['files']  = MQP_Files::for_job( $id );
		$job['events'] = array_map(
			function ( $e ) {
				$e         = mqp_cast_row( $e, array( 'id', 'job_id' ) );
				$e['user'] = mqp_user_brief( (int) $e['user_id'] );
				unset( $e['user_id'] );
				return $e;
			},
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'job_events' ) . ' WHERE job_id = %d ORDER BY id DESC', $id ), ARRAY_A )
		);
		$commission = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'commissions' ) . ' WHERE job_id = %d', $id ), ARRAY_A );
		$job['commission'] = $commission && ( current_user_can( 'mq_finance' ) || (int) $commission['sales_person_id'] === $uid )
			? mqp_cast_row( $commission, array( 'id', 'job_id', 'sales_person_id' ), array( 'base_amount', 'rate', 'amount' ) )
			: null;
		if ( 'ready' === $job['stage'] && current_user_can( 'mq_deliver' ) ) {
			// How to reach the customer, and a ready-made "order ready" message staff copy or email.
			$job['contact']       = self::contact( $id, (int) $row['document_id'] );
			$job['ready_message'] = MQP_Order::ready_message( '' !== $job['contact']['name'] ? $job['contact']['name'] : $job['customer_name'], $job['number'] );
		}
		if ( ! self::sees_prices() ) {
			// Creatives/production don't need prices.
			foreach ( $job['items'] as &$it ) {
				unset( $it['unit_price'], $it['amount'] );
			}
		}
		return $job;
	}

	/**
	 * The customer's name, phone, email and how they get the order: from the online order when
	 * there is one, else from the invoice and its customer record.
	 */
	public static function contact( $job_id, $document_id ) {
		global $wpdb;
		$doc   = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id, customer_name, customer_contact FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $document_id ), ARRAY_A );
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT id, customer_name, customer_phone, customer_email, delivery_type, delivery_location FROM ' . mqp_table( 'orders' ) . ' WHERE job_id = %d ORDER BY id DESC LIMIT 1', $job_id ), ARRAY_A );
		$email = $doc && $doc['customer_id'] ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', $doc['customer_id'] ) ) : '';
		$out   = array(
			'name'     => trim( (string) ( $doc['customer_name'] ?? '' ) ),
			'phone'    => trim( (string) ( $doc['customer_contact'] ?? '' ) ),
			'email'    => trim( $email ),
			'delivery' => '',
			'online'   => (bool) $order,
		);
		if ( $order ) {
			$out['name']  = '' !== $out['name'] ? $out['name'] : trim( $order['customer_name'] );
			$out['phone'] = '' !== trim( $order['customer_phone'] ) ? trim( $order['customer_phone'] ) : $out['phone'];
			$out['email'] = '' !== trim( $order['customer_email'] ) ? trim( $order['customer_email'] ) : $out['email'];
			$labels       = array( 'pickup' => 'Pick up', 'courier' => 'Courier', 'campus' => 'Campus delivery' );
			$type         = $labels[ $order['delivery_type'] ] ?? '';
			$where        = 'courier' === $order['delivery_type'] ? preg_replace( '/\s*—\s*pin:.*$/u', '', (string) $order['delivery_location'] ) : (string) $order['delivery_location'];
			$out['delivery'] = trim( $type . ( '' !== $where && 'pickup' !== $order['delivery_type'] ? ': ' . $where : '' ) );
		}
		if ( ! is_email( $out['email'] ) ) {
			$out['email'] = '';
		}
		return $out;
	}

	public static function get_item( WP_REST_Request $req ) {
		return self::load( (int) $req['id'] );
	}

	public static function event( $job_id, $action, $from = '', $to = '', $note = '' ) {
		global $wpdb;
		$wpdb->insert(
			mqp_table( 'job_events' ),
			array(
				'job_id'     => $job_id,
				'action'     => $action,
				'from_stage' => $from,
				'to_stage'   => $to,
				'note'       => $note,
				'user_id'    => get_current_user_id(),
				'created_at' => mqp_now(),
			)
		);
	}

	/** Items of an invoice as a plain-text spec sheet. */
	private static function specs_from_items( $doc_id ) {
		global $wpdb;
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT description, specs, qty, unit FROM ' . mqp_table( 'document_items' ) . ' WHERE document_id = %d ORDER BY sort, id', $doc_id ) );
		$lines = array();
		foreach ( $items as $it ) {
			$lines[] = sprintf( '• %s × %s %s%s', $it->description, (string) (float) $it->qty, $it->unit, $it->specs ? "\n  " . str_replace( "\n", "\n  ", $it->specs ) : '' );
		}
		return implode( "\n", $lines );
	}

	public static function create_from_invoice( $doc_id ) {
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $doc_id ) );
		if ( ! $doc || $doc->job_id ) {
			return;
		}
		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT description FROM ' . mqp_table( 'document_items' ) . ' WHERE document_id = %d ORDER BY sort, id LIMIT 1', $doc_id ) );
		$table = mqp_table( 'jobs' );
		$now   = mqp_now();
		$wpdb->insert(
			$table,
			array(
				'number'           => 'TMP-' . wp_generate_uuid4(),
				'document_id'      => $doc_id,
				'brand_id'         => $doc->brand_id,
				'branch_id'        => $doc->branch_id,
				'customer_name'    => $doc->customer_company ? $doc->customer_name . ' — ' . $doc->customer_company : $doc->customer_name,
				'customer_contact' => $doc->customer_contact,
				'title'            => $doc->title ? $doc->title : (string) $first,
				'specs'            => self::specs_from_items( $doc_id ),
				'stage'            => 'sales',
				'sales_person_id'  => $doc->sales_person_id,
				'due_date'         => $doc->due_date,
				'created_by'       => get_current_user_id(),
				'created_at'       => $now,
				'stage_changed_at' => $now,
				'updated_at'       => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;
		MQP_Numbering::assign( $table, 'number', $job_id, array( 'MQP_Numbering', 'job_number' ) );
		$wpdb->update( mqp_table( 'documents' ), array( 'job_id' => $job_id ), array( 'id' => $doc_id ) );
		if ( $doc->upload_id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'files' ) . ' SET job_id = %d WHERE upload_id = %d AND job_id IS NULL', $job_id, $doc->upload_id ) );
		}
		self::event( $job_id, 'created', '', 'sales', 'From invoice ' . $doc->number );
		if ( $doc->sales_person_id ) {
			self::upsert_commission( $job_id );
		}
	}

	/** Keeps customer/title/commission in step after the invoice is edited. */
	public static function sync_from_invoice( $job_id ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $job_id ) );
		$doc = $job ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $job->document_id ) ) : null;
		if ( ! $doc ) {
			return;
		}
		$data = array(
			'customer_name'    => $doc->customer_company ? $doc->customer_name . ' — ' . $doc->customer_company : $doc->customer_name,
			'customer_contact' => $doc->customer_contact,
			'due_date'         => $doc->due_date,
			'updated_at'       => mqp_now(),
		);
		if ( 'sales' === $job->stage ) {
			$data['specs'] = self::specs_from_items( $doc->id );
			if ( $doc->title ) {
				$data['title'] = $doc->title;
			}
		}
		$wpdb->update( mqp_table( 'jobs' ), $data, array( 'id' => $job_id ) );
		self::upsert_commission( $job_id );
	}

	public static function set_sales_person( $job_id, $user_id ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $job_id ) );
		$wpdb->update( mqp_table( 'jobs' ), array( 'sales_person_id' => $user_id, 'updated_at' => mqp_now() ), array( 'id' => $job_id ) );
		$wpdb->update( mqp_table( 'documents' ), array( 'sales_person_id' => $user_id ), array( 'id' => $job->document_id ) );
		self::upsert_commission( $job_id );
	}

	/** Creates/updates the commission row; amounts are only recalculated while not yet paid. */
	public static function upsert_commission( $job_id ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT j.*, d.total FROM ' . mqp_table( 'jobs' ) . ' j JOIN ' . mqp_table( 'documents' ) . ' d ON d.id = j.document_id WHERE j.id = %d', $job_id ) );
		if ( ! $job || ! $job->sales_person_id ) {
			return;
		}
		$table    = mqp_table( 'commissions' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE job_id = %d", $job_id ) );
		if ( $existing && 'paid' === $existing->status ) {
			return;
		}
		$brand = MQP_REST::brand( $job->brand_id );
		$rate  = null !== $brand->commission_rate ? (float) $brand->commission_rate : (float) MQP_Settings::get( 'commission_rate' );
		if ( $rate <= 0 || (float) $job->total <= 0 ) {
			// No commission for this brand: no row (an unpaid one left from before is removed).
			if ( $existing ) {
				$wpdb->delete( $table, array( 'id' => $existing->id ) );
			}
			return;
		}
		$data  = array(
			'sales_person_id' => $job->sales_person_id,
			'base_amount'     => $job->total,
			'rate'            => $rate,
			'amount'          => mqp_money( $job->total * $rate / 100 ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
		} else {
			$data['job_id'] = $job_id;
			$data['status'] = 'pending';
			$wpdb->insert( $table, $data );
		}
	}

	/** A commission is earned once the job order is delivered and its invoice is fully paid. */
	public static function maybe_earn( $job_id ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT j.stage, d.total, d.amount_paid FROM ' . mqp_table( 'jobs' ) . ' j JOIN ' . mqp_table( 'documents' ) . ' d ON d.id = j.document_id WHERE j.id = %d', $job_id ) );
		if ( ! $job || 'delivered' !== $job->stage || (float) $job->amount_paid + 0.005 < (float) $job->total ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'commissions' ) . " SET status = 'earned', earned_at = %s WHERE job_id = %d AND status = 'pending'", mqp_now(), $job_id ) );
	}

	public static function cancel( $job_id, $note ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $job_id ) );
		if ( ! $job || 'cancelled' === $job->stage ) {
			return;
		}
		$wpdb->update( mqp_table( 'jobs' ), array( 'stage' => 'cancelled', 'stage_changed_at' => mqp_now(), 'updated_at' => mqp_now() ), array( 'id' => $job_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'commissions' ) . " SET status = 'cancelled' WHERE job_id = %d AND status <> 'paid'", $job_id ) );
		self::event( $job_id, 'cancelled', $job->stage, 'cancelled', $note );
	}

	public static function action( WP_REST_Request $req ) {
		global $wpdb;
		$id     = (int) $req['id'];
		$action = sanitize_key( (string) $req->get_param( 'action' ) );
		$note   = sanitize_textarea_field( (string) $req->get_param( 'note' ) );
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return MQP_REST::error( 'Unknown action.' );
		}
		list( $cap, $from_stages, $to ) = self::ACTIONS[ $action ];
		if ( ! current_user_can( $cap ) ) {
			return MQP_REST::error( 'You do not have permission to do that.', 403 );
		}
		$job = self::load( $id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! in_array( $job['stage'], $from_stages, true ) ) {
			return MQP_REST::error( 'This job order is no longer at that step. Refresh and try again.', 409 );
		}

		$table  = mqp_table( 'jobs' );
		$update = array( 'updated_at' => mqp_now() );

		switch ( $action ) {
			case 'save_specs':
				$update['specs']          = sanitize_textarea_field( (string) $req->get_param( 'specs' ) );
				$update['specs_complete'] = rest_sanitize_boolean( $req->get_param( 'specs_complete' ) ) ? 1 : 0;
				$due                      = $req->get_param( 'due_date' );
				$update['due_date']       = mqp_valid_date( $due ) ? $due : null;
				$note                     = $update['specs_complete'] ? 'Specifications marked complete' : 'Specifications updated';
				break;
			case 'assign':
				$uid  = (int) $req->get_param( 'sales_person_id' );
				$user = get_userdata( $uid );
				if ( ! $user || ! $user->has_cap( 'mq_sales' ) ) {
					return MQP_REST::error( 'Choose a sales person.' );
				}
				self::set_sales_person( $id, $uid );
				$note = 'Sales person: ' . $user->display_name;
				break;
			case 'to_creatives':
			case 'approve_print':
				$fresh = $wpdb->get_row( $wpdb->prepare( self::base_select() . ' WHERE j.id = %d', $id ), ARRAY_A );
				$gate  = self::gate( mqp_cast_row( $fresh, array( 'specs_complete', 'sales_person_id' ), array( 'amount_paid' ) ) );
				$missing = array();
				// The checklist items Sales tick themselves.
				if ( 'to_creatives' === $action && ! rest_sanitize_boolean( $req->get_param( 'needs_review' ) ) ) {
					$missing[] = 'tick "The file requires review/checking/editing" (if it does not, use Approve for Printing)';
				}
				if ( 'approve_print' === $action ) {
					if ( ! rest_sanitize_boolean( $req->get_param( 'basic_document' ) ) ) {
						$missing[] = 'tick "Basic document printing only"';
					}
					if ( ! rest_sanitize_boolean( $req->get_param( 'no_creatives' ) ) ) {
						$missing[] = 'tick "No need for Creatives to review/check/edit"';
					}
				}
				if ( ! $gate['paid'] ) {
					$rule      = MQP_Settings::get( 'gate_payment' );
					$missing[] = 'full' === $rule ? 'the invoice is not fully paid' : ( 'downpayment' === $rule ? 'the ' . (float) MQP_Settings::get( 'downpayment_percent' ) . '% downpayment has not been paid' : 'payment has not been recorded' );
				}
				if ( ! $gate['specs'] ) {
					$missing[] = 'specifications are not marked complete';
				}
				if ( ! $gate['sales_person'] ) {
					$missing[] = 'no sales person is assigned';
				}
				if ( $missing ) {
					return MQP_REST::error( ( 'approve_print' === $action ? 'Cannot approve for printing yet: ' : 'Cannot send to Creatives yet: ' ) . implode( ', ', $missing ) . '.', 409 );
				}
				if ( 'approve_print' === $action ) {
					$update['needs_editing'] = 0;
					$note                    = 'Basic document printing — Creatives skipped' . ( '' !== $note ? ". $note" : '' );
				} else {
					$note = 'File requires review/checking/editing' . ( '' !== $note ? ". $note" : '' );
				}
				break;
			case 'needs_editing':
				if ( '' === $note ) {
					return MQP_REST::error( 'Describe what needs editing.' );
				}
				$update['needs_editing'] = 1;
				break;
			case 'back_to_sales':
				if ( '' === $note ) {
					return MQP_REST::error( 'Tell Sales what is missing.' );
				}
				$update['specs_complete'] = 0;
				break;
			case 'approve_files':
				$update['needs_editing'] = 0;
				break;
			case 'qc_reject':
				if ( '' === $note ) {
					return MQP_REST::error( 'Describe the QC issue.' );
				}
				break;
			case 'notify':
				$via                    = sanitize_text_field( (string) $req->get_param( 'via' ) );
				$update['notified_at']  = mqp_now();
				$update['notified_via'] = $via;
				$note                   = trim( 'Customer notified via ' . $via . ( $note ? " — $note" : '' ) );
				break;
			case 'email_ready':
				// Emails the "order ready" message (as edited by staff) and records it as the notification.
				$to      = $job['contact']['email'] ?? '';
				$message = trim( sanitize_textarea_field( (string) $req->get_param( 'message' ) ) );
				if ( '' === $to ) {
					return MQP_REST::error( 'This customer has no email address on file.' );
				}
				if ( '' === $message ) {
					$message = $job['ready_message'];
				}
				$order  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'orders' ) . ' WHERE job_id = %d ORDER BY id DESC LIMIT 1', $id ) );
				$order  = $order ? MQP_Order::load( (int) $order ) : null;
				$button = $order ? array( 'Track my order', MQP_Order::track_link( $order ) ) : null;
				if ( ! MQP_Mail::send( $to, 'Your order ' . $job['number'] . ' is ready', 'Your order is ready', array( $message ), $button ) ) {
					return MQP_REST::error( 'The email could not be sent. Check the site\'s email settings, or notify the customer another way.', 500 );
				}
				$update['notified_at']  = mqp_now();
				$update['notified_via'] = 'Email';
				$note                   = 'Customer notified via Email (sent from the portal to ' . $to . ")\n" . $message;
				break;
			case 'delivered':
				$update['delivered_at'] = mqp_now();
				break;
			case 'priority':
				$update['priority'] = $job['priority'] ? 0 : 1;
				$note               = $update['priority'] ? 'Marked as RUSH' : 'Rush removed';
				break;
			case 'note':
				if ( '' === $note ) {
					return MQP_REST::error( 'Write a note.' );
				}
				break;
		}

		if ( $to ) {
			$update['stage']            = $to;
			$update['stage_changed_at'] = mqp_now();
			// Only from the stage we checked: a second click at the same moment changes nothing.
			if ( ! $wpdb->update( $table, $update, array( 'id' => $id, 'stage' => $job['stage'] ) ) ) {
				return MQP_REST::error( 'This job order is no longer at that step. Refresh and try again.', 409 );
			}
		} else {
			$wpdb->update( $table, $update, array( 'id' => $id ) );
		}
		self::event( $id, $action, $job['stage'], $to ? $to : '', $note );
		if ( $to ) {
			do_action( 'mqp_job_stage_changed', $id, $job['stage'], $to );
		}
		do_action( 'mqp_job_action', $id, $action, $note, $job ); // Notifications (MQP_Notify).

		if ( 'delivered' === $action ) {
			self::upsert_commission( $id );
			self::maybe_earn( $id );
		}
		return self::load( $id );
	}

	public static function upload_files( WP_REST_Request $req ) {
		$job = self::load( (int) $req['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$kind  = sanitize_key( (string) $req->get_param( 'kind' ) );
		$kind  = in_array( $kind, array( 'design', 'proof', 'reference', 'customer' ), true ) ? $kind : 'reference';
		$files = MQP_Files::normalize( $req->get_file_params()['files'] ?? array() );
		if ( ! $files ) {
			return MQP_REST::error( 'Choose a file.' );
		}
		$max   = max( (int) MQP_Settings::get( 'upload_max_mb' ), (int) floor( wp_max_upload_size() / MB_IN_BYTES ) ) * MB_IN_BYTES;
		$names = array();
		foreach ( $files as $f ) {
			$row = MQP_Files::store( $f, MQP_Files::staff_extensions(), $max );
			if ( is_wp_error( $row ) ) {
				return MQP_REST::error( $row->get_error_message() );
			}
			$row['job_id']      = $job['id'];
			$row['kind']        = $kind;
			$row['uploaded_by'] = get_current_user_id();
			MQP_Files::insert( $row );
			$names[] = $row['original_name'];
		}
		self::event( $job['id'], 'file_' . $kind, '', '', implode( ', ', $names ) );
		return self::load( $job['id'] );
	}

	/** Production monitor: everything between Creatives and Ready. */
	public static function board() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT j.id, j.number, j.title, j.customer_name, j.stage, j.priority, j.due_date, j.needs_editing, j.stage_changed_at, j.brand_id
			 FROM " . mqp_table( 'jobs' ) . " j WHERE j.stage IN ('creatives','production','qc','ready')
			 ORDER BY j.priority DESC, j.due_date IS NULL, j.due_date, j.id",
			ARRAY_A
		);
		return array(
			'generated_at' => mqp_now(),
			'jobs'         => array_map( function ( $r ) {
				return mqp_cast_row( $r, array( 'id', 'priority', 'needs_editing', 'brand_id' ) );
			}, $rows ),
		);
	}
}
