<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared REST helpers plus auth, uploads inbox, customers and file download routes.
 * Every route lives under /wp-json/mq/v1/.
 */
class MQP_REST {

	const NS = 'mq/v1';

	public static function register_all() {
		self::routes();
		MQP_REST_Documents::routes();
		MQP_REST_Jobs::routes();
		MQP_REST_POS::routes();
		MQP_REST_Customers::routes();
		MQP_REST_Dashboard::routes();
		MQP_REST_Finance::routes();
		MQP_REST_Admin::routes();
		MQP_REST_Reviews::routes();
		MQP_REST_Sync::routes();
		MQP_REST_Me::routes();
		MQP_REST_Orders::routes();
		MQP_Daily::routes();
		MQP_Avatar::routes();
		MQP_Signup::routes();
	}

	/** permission_callback requiring one (or any of several) capabilities. */
	public static function can( ...$caps ) {
		return function () use ( $caps ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'mqp_auth', 'Please log in again.', array( 'status' => 401 ) );
			}
			if ( get_user_meta( get_current_user_id(), 'mqp_disabled', true ) ) {
				return new WP_Error( 'mqp_auth', 'Account disabled.', array( 'status' => 403 ) );
			}
			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}
			return new WP_Error( 'mqp_forbidden', 'You do not have permission to do that.', array( 'status' => 403 ) );
		};
	}

	public static function error( $message, $status = 400, $code = 'mqp_error' ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function brand( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'brands' ) . ' WHERE id = %d', $id ) );
	}

	/** Users who can hold a sales assignment. */
	public static function sales_staff() {
		$users = get_users(
			array(
				'role__in' => array( 'mq_sales_staff', 'mq_finance_ops', 'mq_owner', 'administrator' ),
				'fields'   => array( 'ID', 'display_name' ),
				'orderby'  => 'display_name',
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			if ( ! get_user_meta( $u->ID, 'mqp_disabled', true ) ) {
				$out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'brand_id' => (int) get_user_meta( $u->ID, 'mqp_brand_id', true ) );
			}
		}
		return $out;
	}

	private static function routes() {
		register_rest_route( self::NS, '/auth/login', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'login' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/auth/logout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'logout' ),
			'permission_callback' => self::can( 'mq_access_portal' ),
		) );
		register_rest_route( self::NS, '/uploads', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_uploads' ),
			'permission_callback' => self::can( 'mq_sales' ),
		) );
		register_rest_route( self::NS, '/uploads/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_upload' ),
				'permission_callback' => self::can( 'mq_sales' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_upload' ),
				'permission_callback' => self::can( 'mq_sales' ),
			),
		) );
		register_rest_route( self::NS, '/customers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'customers' ),
			'permission_callback' => self::can( 'mq_sales', 'mq_pos' ),
		) );
		register_rest_route( self::NS, '/files/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download' ),
			'permission_callback' => self::can( 'mq_sales', 'mq_creatives', 'mq_production', 'mq_qc', 'mq_view_dashboard' ),
		) );
	}

	public static function login( WP_REST_Request $req ) {
		$user = MQP_Auth::login( (string) $req->get_param( 'username' ), (string) $req->get_param( 'password' ) );
		if ( is_wp_error( $user ) ) {
			return self::error( $user->get_error_message(), 403 );
		}
		return array( 'ok' => true );
	}

	/** Logs out of the portal (wp-admin stays signed in). Pass clock_out=1 to also clock out of an open shift. */
	public static function logout( WP_REST_Request $req ) {
		if ( rest_sanitize_boolean( $req->get_param( 'clock_out' ) ) && MQP_Session::checked() && MQP_Auth::open_attendance( get_current_user_id() ) ) {
			MQP_Auth::clock( wp_get_current_user(), 'out', array() );
		}
		mqp_log( 'logout', array( 'user_id' => get_current_user_id() ) );
		MQP_Session::end();
		return array( 'ok' => true );
	}

	public static function list_uploads( WP_REST_Request $req ) {
		global $wpdb;
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$status = in_array( $status, array( 'new', 'converted', 'dismissed' ), true ) ? $status : 'new';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.*, (SELECT COUNT(*) FROM ' . mqp_table( 'files' ) . ' f WHERE f.upload_id = u.id) AS file_count
				 FROM ' . mqp_table( 'uploads' ) . ' u WHERE u.status = %s ORDER BY u.id DESC LIMIT 200',
				$status
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_upload' ), $rows );
	}

	public static function format_upload( $r ) {
		$r              = mqp_cast_row( $r, array( 'id', 'brand_id', 'branch_id', 'document_id', 'handled_by', 'file_count' ) );
		$r['reference'] = sprintf( 'U-%05d', $r['id'] );
		$r['handled_by'] = mqp_user_brief( $r['handled_by'] );
		unset( $r['ip'] );
		return $r;
	}

	public static function get_upload( WP_REST_Request $req ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'uploads' ) . ' WHERE id = %d', (int) $req['id'] ), ARRAY_A );
		if ( ! $row ) {
			return self::error( 'Upload not found.', 404 );
		}
		$out          = self::format_upload( $row );
		$out['files'] = MQP_Files::for_upload( (int) $row['id'] );
		return $out;
	}

	/** Dismiss / restore an upload. Conversion happens when a document is created with upload_id. */
	public static function update_upload( WP_REST_Request $req ) {
		global $wpdb;
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'new', 'dismissed' ), true ) ) {
			return self::error( 'Invalid status.' );
		}
		$wpdb->update( mqp_table( 'uploads' ), array( 'status' => $status, 'handled_by' => get_current_user_id() ), array( 'id' => (int) $req['id'] ) );
		return self::get_upload( $req );
	}

	public static function customers( WP_REST_Request $req ) {
		global $wpdb;
		$q   = '%' . $wpdb->esc_like( sanitize_text_field( (string) $req->get_param( 'search' ) ) ) . '%';
		$c   = mqp_table( 'customers' );
		$own = '';
		if ( ! MQP_REST_Documents::sees_all() ) {
			// Sales staff who only see their own documents only find their own customers.
			$uid = get_current_user_id();
			$own = $wpdb->prepare( ' AND c.id IN (SELECT d.customer_id FROM ' . mqp_table( 'documents' ) . ' d WHERE d.customer_id IS NOT NULL AND (d.created_by = %d OR d.sales_person_id = %d OR (d.created_by = 0 AND d.sales_person_id IS NULL)))', $uid, $uid ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT c.* FROM $c c WHERE (c.name LIKE %s OR c.company LIKE %s OR c.contact LIKE %s)$own ORDER BY c.name LIMIT 20", $q, $q, $q ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$full = current_user_can( 'mq_sales' );
		return array_map( function ( $r ) use ( $full ) {
			$r = mqp_cast_row( $r, array( 'id' ) );
			// The POS only needs who the customer is; contact details stay with Sales.
			return $full ? $r : array( 'id' => $r['id'], 'name' => $r['name'], 'company' => $r['company'], 'contact' => $r['contact'] );
		}, $rows );
	}

	/** Finds or creates a customer from document fields. */
	public static function upsert_customer( $name, $company, $contact, $email = '', $address = '' ) {
		global $wpdb;
		if ( '' === $name ) {
			return null;
		}
		$table = mqp_table( 'customers' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE name = %s AND contact = %s LIMIT 1", $name, $contact ) );
		if ( $id ) {
			$wpdb->update( $table, array_filter( array( 'company' => $company, 'email' => $email, 'address' => $address ) ), array( 'id' => $id ) );
			return (int) $id;
		}
		$wpdb->insert( $table, array( 'name' => $name, 'company' => $company, 'contact' => $contact, 'email' => $email, 'address' => $address, 'created_at' => mqp_now() ) );
		return (int) $wpdb->insert_id;
	}

	public static function download( WP_REST_Request $req ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE id = %d', (int) $req['id'] ), ARRAY_A );
		if ( ! $row || ! MQP_Files::can_open( $row ) ) {
			return self::error( 'File not found.', 404 ); // Same answer as a missing file: IDs can't be probed.
		}
		if ( ! empty( $row['archived_at'] ) ) {
			return self::error( 'This file was moved to archive ' . MQP_Archive::name( (int) $row['archive_id'] ) . ' on ' . mysql2date( 'M j, Y', $row['archived_at'] ) . ' to free up space. Ask the admin for it.', 410, 'mqp_archived' );
		}
		// Only formats browsers render safely are shown inline; everything else downloads.
		$inline = $req->get_param( 'inline' ) && in_array( $row['mime'], array( 'application/pdf', 'image/png', 'image/jpeg' ), true );
		MQP_Files::stream( $row, $inline );
	}
}
