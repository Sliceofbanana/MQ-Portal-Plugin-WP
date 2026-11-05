<?php
defined( 'ABSPATH' ) || exit;

/**
 * Portal notifications: the bell in the top bar.
 *
 * Something happens (a job order moves to the next step, a customer uploads files, an online
 * order or account request comes in…) → a notification is stored for each person it concerns.
 * Who gets what follows their access rights, and everyone can switch types off in
 * My settings → Notifications. Admins and the Owner start with only the admin-level types on,
 * so they are not flooded with every step of every job order.
 * The app checks for new ones every 45 seconds (and when the tab is opened again).
 */
class MQP_Notify {

	/** type => [ label for My settings, permission needed, on by default for admins/owner, group ] */
	const TYPES = array(
		'job_creatives'  => array( 'Job orders sent to Creatives', 'mq_creatives', false, 'Job orders' ),
		'job_production' => array( 'Job orders ready to print (or sent back by QC)', 'mq_production', false, 'Job orders' ),
		'job_qc'         => array( 'Printed job orders waiting for QC', 'mq_qc', false, 'Job orders' ),
		'job_ready'      => array( 'Job orders ready for delivery', 'mq_deliver', false, 'Job orders' ),
		'job_mine'       => array( 'My job orders: assigned to me, sent back, needs editing, ready, notes', 'mq_sales', true, 'Job orders' ),
		'upload_new'     => array( 'New customer uploads', 'mq_sales', false, 'Customers' ),
		'quote_new'      => array( 'Quotation requests from the website (needs pricing, or sent right away)', 'mq_sales', true, 'Customers' ),
		'quote_accepted' => array( 'Quotations the customer accepted online (a job order is opened)', 'mq_sales', true, 'Customers' ),
		'quote_late'     => array( 'Reminder: website quotation requests still not priced after 20 hours', 'mq_view_dashboard', true, 'Customers' ),
		'order_new'      => array( 'New online orders and payments to check', 'mq_sales', true, 'Customers' ),
		'review_new'     => array( 'New website reviews (and private feedback below 4 stars)', 'mq_reviews', true, 'Customers' ),
		'signup_new'     => array( 'New account requests', 'mq_manage', true, 'Admin' ),
		'price_flag'     => array( 'POS sales with a custom or changed price, an old date or another shop (by staff without that permission)', 'mq_price_override', true, 'Admin' ),
		'system_problem' => array( 'Problems on the system (from wp-admin → System)', 'manage_options', true, 'Admin' ),
	);

	const META = 'mqp_notify';

	public static function init() {
		add_action( 'mqp_job_action', array( __CLASS__, 'job_action' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'mqp_daily', array( __CLASS__, 'cleanup' ) );
	}

	public static function routes() {
		$ns   = MQP_REST::NS;
		$perm = MQP_REST::can( 'mq_access_portal' );
		register_rest_route( $ns, '/me/notifications', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_mine' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/me/notifications/read', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'mark_read' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/me/notifications/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $perm ),
		) );
	}

	/* ---------- Who gets what ---------- */

	/** Admins and the Owner: only the types marked "on by default for admins" start switched on. */
	private static function is_boss( WP_User $u ) {
		return $u->has_cap( 'manage_options' ) || in_array( 'mq_owner', $u->roles, true );
	}

	/** Whether $u wants $type (their own choice, or the default). */
	public static function wants( WP_User $u, $type ) {
		if ( ! isset( self::TYPES[ $type ] ) || ! $u->has_cap( self::TYPES[ $type ][1] ) ) {
			return false;
		}
		$saved = get_user_meta( $u->ID, self::META, true );
		if ( is_array( $saved ) && array_key_exists( $type, $saved ) ) {
			return (bool) $saved[ $type ];
		}
		return self::is_boss( $u ) ? self::TYPES[ $type ][2] : true;
	}

	/** Active portal users (cached for this request). */
	private static function people() {
		static $people = null;
		if ( null === $people ) {
			$people = array_filter(
				get_users( array( 'role__in' => array_merge( array_keys( MQP_Roles::roles() ), array( 'administrator' ) ) ) ),
				function ( $u ) {
					return $u->has_cap( 'mq_access_portal' ) && ! get_user_meta( $u->ID, 'mqp_disabled', true );
				}
			);
		}
		return $people;
	}

	/**
	 * Sends a notification to everyone who has the type's permission and wants it, except the
	 * person who caused it. $brand_id: sales staff tied to another brand are skipped.
	 */
	public static function to_role( $type, $title, $body = '', $link = '', $brand_id = 0 ) {
		$skip = get_current_user_id();
		foreach ( self::people() as $u ) {
			if ( (int) $u->ID === $skip || ! self::wants( $u, $type ) ) {
				continue;
			}
			$mine = (int) get_user_meta( $u->ID, 'mqp_brand_id', true );
			if ( $brand_id && $mine && $mine !== (int) $brand_id && ! $u->has_cap( 'mq_view_all_sales' ) ) {
				continue;
			}
			self::add( $u->ID, $type, $title, $body, $link );
		}
	}

	/** Sends a notification to one person (if they want this type, and didn't cause it themselves). */
	public static function to_user( $user_id, $type, $title, $body = '', $link = '' ) {
		$u = $user_id ? get_userdata( (int) $user_id ) : null;
		if ( ! $u || (int) $u->ID === get_current_user_id() || get_user_meta( $u->ID, 'mqp_disabled', true ) || ! self::wants( $u, $type ) ) {
			return;
		}
		self::add( $u->ID, $type, $title, $body, $link );
	}

	private static function add( $user_id, $type, $title, $body, $link ) {
		global $wpdb;
		$wpdb->insert(
			mqp_table( 'notifications' ),
			array(
				'user_id'    => (int) $user_id,
				'type'       => $type,
				'title'      => mb_substr( wp_strip_all_tags( $title ), 0, 190 ),
				'body'       => mb_substr( wp_strip_all_tags( $body ), 0, 500 ),
				'link'       => mb_substr( (string) $link, 0, 255 ),
				'created_at' => mqp_now(),
			)
		);
	}

	/* ---------- Events ---------- */

	/** Job order actions (called by MQP_REST_Jobs::action after it is saved). */
	public static function job_action( $job_id, $action, $note, $old ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $job_id ), ARRAY_A );
		if ( ! $job ) {
			return;
		}
		$rush  = $job['priority'] ? 'RUSH · ' : '';
		$name  = $job['number'];
		$about = trim( $job['title'] . ( $job['customer_name'] ? ' · ' . $job['customer_name'] : '' ), ' ·' );
		$link  = 'job:' . (int) $job_id;
		$who   = wp_get_current_user()->display_name;
		$sales = (int) $job['sales_person_id'];
		$with  = function ( $text ) use ( $note, $about ) {
			return $note ? $note . ( $about ? ' — ' . $about : '' ) : $about;
		};

		switch ( $action ) {
			case 'to_creatives':
				self::to_role( 'job_creatives', $rush . "$name is ready for Creatives", $about, $link );
				break;
			case 'approve_files':
				self::to_role( 'job_production', $rush . "$name is ready to print", 'Files approved by ' . $who . ( $about ? ' — ' . $about : '' ), $link );
				break;
			case 'approve_print':
				self::to_role( 'job_production', $rush . "$name is ready to print", 'Basic document printing, approved by ' . $who . ' (no Creatives)' . ( $about ? ' — ' . $about : '' ), $link );
				break;
			case 'qc_reject':
				self::to_role( 'job_production', $rush . "$name was sent back by QC", $with( $note ), $link );
				self::to_user( $sales, 'job_mine', "$name was sent back by QC", $with( $note ), $link );
				break;
			case 'printed':
				self::to_role( 'job_qc', $rush . "$name is printed — ready for QC", $about, $link );
				break;
			case 'qc_approve':
				self::to_role( 'job_ready', $rush . "$name is ready for delivery", $about, $link );
				self::to_user( $sales, 'job_mine', "$name is ready for delivery", 'Passed QC — tell the customer. ' . $about, $link );
				break;
			case 'back_to_sales':
				self::to_user( $sales, 'job_mine', "Creatives sent $name back to you", $with( $note ), $link );
				break;
			case 'needs_editing':
				self::to_user( $sales, 'job_mine', "$name needs editing", $with( $note ), $link );
				break;
			case 'assign':
				self::to_user( $sales, 'job_mine', "$name was assigned to you", 'By ' . $who . ( $about ? ' — ' . $about : '' ), $link );
				break;
			case 'note':
				self::to_user( $sales, 'job_mine', "New note on $name from $who", $with( $note ), $link );
				break;
			case 'priority':
				if ( $job['priority'] ) {
					self::to_user( $sales, 'job_mine', "$name was marked RUSH", 'By ' . $who . ( $about ? ' — ' . $about : '' ), $link );
				}
				break;
		}
	}

	public static function upload_received( $upload_id, $name, $brand_id ) {
		self::to_role( 'upload_new', 'New customer upload from ' . $name, sprintf( 'U-%05d · %s', $upload_id, self::brand( $brand_id ) ), '#/sales/uploads', $brand_id );
	}

	public static function order_received( array $order, $again = false ) {
		self::to_role(
			'order_new',
			( $again ? 'New payment sent for ' : 'New online order ' ) . $order['reference'],
			trim( ( $order['customer_name'] ?? '' ) . ( 'pending' === ( $order['payment_status'] ?? '' ) ? ' · ' . ( $order['details']['summary'] ?? '' ) . ' · check the files and set the price' : ' · ₱' . number_format( (float) ( $order['total'] ?? 0 ), 2 ) . ' · check the payment' ), ' ·' ),
			'#/sales/orders/' . (int) $order['id'],
			(int) ( $order['brand_id'] ?? 0 )
		);
	}

	/** A POS sale an admin should look at (custom price, changed price, old date, other shop). */
	public static function price_flag( $receipt, array $flags ) {
		$u = wp_get_current_user();
		self::to_role( 'price_flag', 'Check POS receipt ' . $receipt, ( $u->exists() ? $u->display_name . ': ' : '' ) . implode( '; ', $flags ), '#/pos' );
	}

	public static function quote_received( array $doc, $pricing ) {
		$title = $pricing ? 'Quotation request ' . $doc['number'] . ' needs pricing' : 'Quotation ' . $doc['number'] . ' sent to ' . $doc['customer_name'];
		$body  = $pricing ? $doc['customer_name'] . ' · ' . $doc['customer_contact'] . ' · they were told you reply within 24 hours' : '₱' . number_format( (float) $doc['total'], 2 ) . ' · fixed prices, emailed automatically';
		self::to_role( 'quote_new', $title, $body, '#/sales/docs/' . (int) $doc['id'], (int) $doc['brand_id'] );
	}

	public static function quote_accepted( array $quote, array $invoice ) {
		self::to_role( 'quote_accepted', $quote['customer_name'] . ' accepted quotation ' . $quote['number'], 'Job order ' . ( $invoice['job_number'] ?? '' ) . ' and invoice ' . $invoice['number'] . ' are open · ₱' . number_format( (float) $invoice['total'], 2 ) . ' · contact them about payment and files', '#/sales/docs/' . (int) $invoice['id'], (int) $invoice['brand_id'] );
	}

	public static function quote_late( array $doc, $hours ) {
		self::to_role( 'quote_late', 'Quotation ' . $doc['number'] . ' still needs pricing', $doc['customer_name'] . ' asked ' . (int) $hours . ' hours ago. The website promises a reply within 24 hours.', '#/sales/docs/' . (int) $doc['id'] );
	}

	public static function signup_received( $name, $role_name ) {
		self::to_role( 'signup_new', 'New account request from ' . $name, $role_name ? 'Position: ' . $role_name : '', '#/admin/requests' );
	}

	public static function review_received( $name, $rating, $private = false ) {
		if ( $private ) {
			self::to_role( 'review_new', "New $rating-star feedback from $name", 'Below 4 stars, so it is kept private (never on the website). Read it in Admin → Reviews → Hidden.', '#/admin/reviews/hidden' );
			return;
		}
		self::to_role( 'review_new', "New $rating-star review from $name", 'Waiting for your approval in Admin → Reviews.', '#/admin/reviews/pending' );
	}

	public static function problem( $kind, $message ) {
		self::to_role( 'system_problem', 'Problem on the system: ' . ( MQP_Monitor::KINDS[ $kind ] ?? $kind ), $message, admin_url( 'admin.php?page=' . MQP_System::SLUG . '&tab=errors' ) );
	}

	private static function brand( $id ) {
		$b = $id ? MQP_REST::brand( (int) $id ) : null;
		return $b ? $b->name : '';
	}

	/* ---------- API for the bell ---------- */

	public static function unread( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'notifications' ) . ' WHERE user_id = %d AND read_at IS NULL', $user_id ) );
	}

	/** Latest 40 notifications and the unread count. */
	public static function list_mine() {
		global $wpdb;
		$uid  = get_current_user_id();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, title, body, link, created_at, read_at FROM ' . mqp_table( 'notifications' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 40', $uid ), ARRAY_A );
		return array(
			'unread' => self::unread( $uid ),
			'items'  => array_map( function ( $r ) {
				return array(
					'id'         => (int) $r['id'],
					'type'       => $r['type'],
					'title'      => $r['title'],
					'body'       => $r['body'],
					'link'       => $r['link'],
					'created_at' => $r['created_at'],
					'read'       => null !== $r['read_at'],
				);
			}, $rows ),
		);
	}

	/** Body: { ids: [1,2] } or { all: true }. */
	public static function mark_read( WP_REST_Request $req ) {
		global $wpdb;
		$uid = get_current_user_id();
		$t   = mqp_table( 'notifications' );
		if ( rest_sanitize_boolean( $req->get_param( 'all' ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND read_at IS NULL", mqp_now(), $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$ids = array_filter( array_map( 'intval', (array) $req->get_param( 'ids' ) ) );
			if ( $ids ) {
				$in = implode( ',', $ids );
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND id IN ($in)", mqp_now(), $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
		return array( 'unread' => self::unread( $uid ) );
	}

	/** The types this person can get, with on/off. */
	public static function get_settings() {
		$u   = wp_get_current_user();
		$out = array();
		foreach ( self::TYPES as $type => $t ) {
			if ( $u->has_cap( $t[1] ) ) {
				$out[] = array( 'type' => $type, 'label' => $t[0], 'group' => $t[3], 'on' => self::wants( $u, $type ) );
			}
		}
		return $out;
	}

	/** Body: { type: true|false, … }. */
	public static function save_settings( WP_REST_Request $req ) {
		$u     = wp_get_current_user();
		$saved = get_user_meta( $u->ID, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( (array) $req->get_json_params() as $type => $on ) {
			if ( isset( self::TYPES[ $type ] ) && $u->has_cap( self::TYPES[ $type ][1] ) ) {
				$saved[ $type ] = rest_sanitize_boolean( $on );
			}
		}
		update_user_meta( $u->ID, self::META, $saved );
		return self::get_settings();
	}

	/** Daily: read ones go after 30 days, all after 90. */
	public static function cleanup() {
		global $wpdb;
		$t = mqp_table( 'notifications' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE (read_at IS NOT NULL AND created_at < %s) OR created_at < %s", wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), wp_date( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
