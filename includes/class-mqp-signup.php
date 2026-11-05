<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff account requests.
 *
 * New staff ask for an account from the portal login screen. Nothing is created in WordPress
 * until an admin approves the request; then the account is made with the password the person
 * chose (only its hash is kept while waiting). A denied request never becomes an account.
 */
class MQP_Signup {

	/** Positions staff may ask for. The admin can change it when approving. */
	const REQUESTABLE = array( 'mq_sales_staff', 'mq_artist', 'mq_production', 'mq_qc_officer', 'mq_cashier', 'mq_finance_ops' );

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/public/signup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/admin/signups', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_requests' ),
			'permission_callback' => MQP_REST::can( 'mq_manage' ),
		) );
		register_rest_route( $ns, '/admin/signups/(?P<id>\d+)/approve', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'approve' ),
			'permission_callback' => MQP_REST::can( 'mq_manage' ),
		) );
		register_rest_route( $ns, '/admin/signups/(?P<id>\d+)/deny', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'deny' ),
			'permission_callback' => MQP_REST::can( 'mq_manage' ),
		) );
	}

	/** What the login screen needs to show the request form (positions and shops; no secrets). */
	public static function public_options() {
		global $wpdb;
		$roles = MQP_Roles::roles();
		return array(
			'enabled'   => (bool) MQP_Settings::get( 'signup_enabled' ),
			'positions' => array_map( function ( $slug ) use ( $roles ) {
				return array( 'value' => $slug, 'label' => preg_replace( '/^MQ /', '', $roles[ $slug ]['name'] ) );
			}, self::REQUESTABLE ),
			'branches'  => array_map( function ( $b ) {
				return array( 'value' => (int) $b->id, 'label' => $b->name );
			}, $wpdb->get_results( 'SELECT id, name FROM ' . mqp_table( 'branches' ) . ' WHERE active = 1 ORDER BY brand_id, id' ) ),
		);
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( 'signups' ) . " WHERE status = 'pending'" );
	}

	/** Pending request for a username or email, if any (used by the login screen message). */
	public static function pending_for( $login ) {
		global $wpdb;
		$login = trim( (string) $login );
		if ( '' === $login ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'signups' ) . " WHERE status = 'pending' AND ( username = %s OR email = %s ) LIMIT 1", sanitize_user( $login, true ), $login ) );
	}

	/** Simple per-IP limiter (same idea as the order page). */
	private static function limited( $max ) {
		$key   = 'mqp_rl_signup_' . md5( mqp_client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function submit( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! MQP_Settings::get( 'signup_enabled' ) ) {
			return MQP_REST::error( 'Account requests are closed. Please ask an admin to create your account.', 403 );
		}
		$p = (array) $req->get_json_params();
		if ( ! empty( $p['website'] ) ) { // Honeypot: real people never fill this in.
			return MQP_REST::error( 'Request rejected.' );
		}
		if ( self::limited( (int) MQP_Monitor::settings()['limit_signups'] ) ) {
			return MQP_REST::error( 'Too many requests from your connection. Please try again later.', 429 );
		}

		$name     = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$username = sanitize_user( (string) ( $p['username'] ?? '' ), true );
		$email    = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$phone    = sanitize_text_field( (string) ( $p['phone'] ?? '' ) );
		$role     = in_array( $p['role'] ?? '', self::REQUESTABLE, true ) ? $p['role'] : '';
		$branch   = (int) ( $p['branch_id'] ?? 0 );
		$note     = sanitize_textarea_field( (string) ( $p['note'] ?? '' ) );
		$password = (string) ( $p['password'] ?? '' );

		if ( strlen( $name ) < 2 || ! is_email( $email ) ) {
			return MQP_REST::error( 'Enter your full name and a valid email address.' );
		}
		if ( strlen( $username ) < 3 || $username !== (string) ( $p['username'] ?? '' ) ) {
			return MQP_REST::error( 'Choose a username of at least 3 letters or numbers (no spaces).' );
		}
		if ( strlen( $password ) < 8 ) {
			return MQP_REST::error( 'Choose a password of at least 8 characters.' );
		}
		if ( ! $role ) {
			return MQP_REST::error( 'Choose your position.' );
		}
		if ( username_exists( $username ) ) {
			return MQP_REST::error( 'That username is already taken. Please choose another.' );
		}
		if ( email_exists( $email ) ) {
			return MQP_REST::error( 'An account with that email already exists. Use "Log in", or ask an admin.' );
		}
		$table = mqp_table( 'signups' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE status = 'pending' AND ( username = %s OR email = %s )", $username, $email ) ) ) {
			return MQP_REST::error( 'A request with that username or email is already waiting for approval.' );
		}

		$wpdb->insert( $table, array(
			'username'   => $username,
			'name'       => substr( $name, 0, 190 ),
			'email'      => $email,
			'phone'      => substr( $phone, 0, 40 ),
			'role'       => $role,
			'branch_id'  => $branch ? $branch : null,
			'note'       => $note,
			'pass_hash'  => wp_hash_password( $password ),
			'status'     => 'pending',
			'ip'         => mqp_client_ip(),
			'created_at' => mqp_now(),
		) );
		mqp_log( 'signup', array( 'username' => $username, 'reason' => 'Account requested' ) );
		self::email_admin( $wpdb->insert_id );
		$roles = MQP_Roles::roles();
		MQP_Notify::signup_received( $name, isset( $roles[ $role ] ) ? $roles[ $role ]['name'] : '' );
		return array( 'ok' => true );
	}

	public static function list_requests( WP_REST_Request $req ) {
		global $wpdb;
		$status = in_array( $req->get_param( 'status' ), array( 'pending', 'approved', 'denied' ), true ) ? $req->get_param( 'status' ) : 'pending';
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'signups' ) . ' WHERE status = %s ORDER BY id DESC LIMIT 200', $status ), ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	private static function format( array $r ) {
		unset( $r['pass_hash'] );
		$r              = mqp_cast_row( $r, array( 'id', 'branch_id', 'user_id', 'decided_by' ) );
		$r['decided_by'] = $r['decided_by'] ? mqp_user_brief( $r['decided_by'] ) : null;
		if ( 'pending' === $r['status'] ) {
			$r['email_used']    = (bool) email_exists( $r['email'] );
			$r['username_used'] = (bool) username_exists( $r['username'] );
		}
		return $r;
	}

	private static function load( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'signups' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
	}

	/** Creates the account. Body (optional): role, branch_id, brand_id — what the admin picked. */
	public static function approve( WP_REST_Request $req ) {
		global $wpdb;
		$s = self::load( $req['id'] );
		if ( ! $s ) {
			return MQP_REST::error( 'Request not found.', 404 );
		}
		if ( 'pending' !== $s['status'] ) {
			return MQP_REST::error( 'This request was already ' . $s['status'] . '.' );
		}
		$role = sanitize_key( (string) ( $req->get_param( 'role' ) ?: $s['role'] ) );
		if ( ! isset( MQP_Roles::roles()[ $role ] ) ) {
			return MQP_REST::error( 'Choose a position.' );
		}
		if ( 'mq_owner' === $role && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can create Owner accounts.', 403 );
		}
		if ( username_exists( $s['username'] ) || email_exists( $s['email'] ) ) {
			return MQP_REST::error( 'An account with this username or email already exists. Deny this request instead.' );
		}

		// Claim the request first so two admins approving at once can't create two accounts.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'signups' ) . " SET status = 'approved', decided_at = %s, decided_by = %d WHERE id = %d AND status = 'pending'", mqp_now(), get_current_user_id(), $s['id'] ) );
		if ( ! $claimed ) {
			return MQP_REST::error( 'This request was already handled.' );
		}
		$user_id = wp_insert_user( array(
			'user_login'   => $s['username'],
			'user_email'   => $s['email'],
			'display_name' => $s['name'],
			'first_name'   => strtok( $s['name'], ' ' ),
			'user_pass'    => wp_generate_password( 32 ), // Replaced by the hash of their own password below.
			'role'         => $role,
		) );
		if ( is_wp_error( $user_id ) ) {
			$wpdb->update( mqp_table( 'signups' ), array( 'status' => 'pending', 'decided_at' => null, 'decided_by' => null ), array( 'id' => $s['id'] ) );
			return MQP_REST::error( $user_id->get_error_message() );
		}
		// Use the password they chose when requesting (stored only as a hash).
		$wpdb->update( $wpdb->users, array( 'user_pass' => $s['pass_hash'] ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );

		$branch = (int) ( null !== $req->get_param( 'branch_id' ) ? $req->get_param( 'branch_id' ) : $s['branch_id'] );
		update_user_meta( $user_id, 'mqp_branch_id', $branch );
		update_user_meta( $user_id, 'mqp_brand_id', (int) $req->get_param( 'brand_id' ) );
		if ( $s['phone'] ) {
			update_user_meta( $user_id, 'mqp_phone', $s['phone'] );
		}
		$wpdb->update( mqp_table( 'signups' ), array( 'user_id' => $user_id, 'pass_hash' => '', 'role' => $role ), array( 'id' => $s['id'] ) );
		mqp_log( 'signup', array( 'user_id' => $user_id, 'username' => $s['username'], 'reason' => 'Approved' ) );

		$roles = MQP_Roles::roles();
		MQP_Mail::send(
			$s['email'],
			'Your MQ Prints staff account is ready',
			'Welcome, ' . strtok( $s['name'], ' ' ) . '!',
			array(
				'Your staff portal account was approved. Log in with the username and password you chose.',
				MQP_Mail::table( array(
					array( 'Username', $s['username'], true ),
					array( 'Position', $roles[ $role ]['name'] ),
				) ),
			),
			array( 'Open the staff portal', MQP_Portal::url() )
		);
		return self::format( self::load( $s['id'] ) ) + array( 'new_user_id' => (int) $user_id );
	}

	public static function deny( WP_REST_Request $req ) {
		global $wpdb;
		$s = self::load( $req['id'] );
		if ( ! $s ) {
			return MQP_REST::error( 'Request not found.', 404 );
		}
		$reason  = substr( sanitize_text_field( (string) $req->get_param( 'reason' ) ), 0, 255 );
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'signups' ) . " SET status = 'denied', reason = %s, pass_hash = '', decided_at = %s, decided_by = %d WHERE id = %d AND status = 'pending'", $reason, mqp_now(), get_current_user_id(), $s['id'] ) );
		if ( ! $claimed ) {
			return MQP_REST::error( 'This request was already ' . $s['status'] . '.' );
		}
		mqp_log( 'signup', array( 'username' => $s['username'], 'reason' => 'Denied' . ( $reason ? ': ' . $reason : '' ) ) );
		MQP_Mail::send(
			$s['email'],
			'Your MQ Prints staff account request',
			'Account request not approved',
			array_filter( array(
				'Your request for a staff portal account was not approved, so no account was created.',
				$reason ? 'Reason: ' . $reason : '',
				'If you think this is a mistake, please talk to your manager.',
			) )
		);
		return self::format( self::load( $s['id'] ) );
	}

	private static function email_admin( $id ) {
		$s = self::load( $id );
		if ( ! $s ) {
			return;
		}
		$roles = MQP_Roles::roles();
		MQP_Mail::send(
			MQP_Monitor::admin_to(),
			'New staff account request: ' . $s['name'],
			'New staff account request',
			array(
				'Someone asked for a staff portal account. No account exists until you approve it.',
				MQP_Mail::table( array(
					array( 'Name', $s['name'], true ),
					array( 'Username', $s['username'] ),
					array( 'Email', $s['email'] ),
					array( 'Mobile', $s['phone'] ? $s['phone'] : '—' ),
					array( 'Position asked for', $roles[ $s['role'] ]['name'] ?? $s['role'] ),
				) ),
			),
			array( 'Review the request', MQP_Portal::url() . '#/admin/requests' )
		);
	}
}
