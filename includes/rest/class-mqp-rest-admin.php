<?php
defined( 'ABSPATH' ) || exit;

/** Settings, brands, branches, POS products, staff accounts and per-user permissions. */
class MQP_REST_Admin {

	public static function routes() {
		$ns   = MQP_REST::NS;
		$perm = MQP_REST::can( 'mq_manage' );

		register_rest_route( $ns, '/admin/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $perm ),
		) );
		foreach ( array( 'brands', 'branches', 'products' ) as $type ) {
			register_rest_route( $ns, "/admin/$type", array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, "list_$type" ), 'permission_callback' => $perm ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, "save_$type" ), 'permission_callback' => $perm ),
			) );
		}
		register_rest_route( $ns, '/admin/branches/(?P<id>\d+)/register-ip', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'register_ip' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/users', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_users' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_user' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_user' ),
			'permission_callback' => $perm,
		) );
		// Safe delete: what the account still has open, restore within 30 days, delete for good.
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/impact', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'delete_impact' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/restore', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'restore_user' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/purge', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'purge_user' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/signout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'signout_user' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/users/(?P<id>\d+)/caps', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_caps' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/admin/roles', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'roles' ),
			'permission_callback' => $perm,
		) );
	}

	public static function get_settings() {
		$s = MQP_Settings::all();
		$s['turnstile_secret'] = $s['turnstile_secret'] ? '••••••••' : '';
		$s['server_max_upload_mb'] = (int) floor( wp_max_upload_size() / MB_IN_BYTES );
		$s['your_ip']              = mqp_client_ip();
		$s['shops_set_up']         = count( MQP_Auth::shops() );
		return $s;
	}

	public static function save_settings( WP_REST_Request $req ) {
		$values = (array) $req->get_json_params();
		if ( isset( $values['turnstile_secret'] ) && '••••••••' === $values['turnstile_secret'] ) {
			unset( $values['turnstile_secret'] );
		}
		$before = MQP_Settings::turnstile_on() . '|' . MQP_Settings::get( 'turnstile_site_key' );
		MQP_Settings::update( $values );
		if ( MQP_Settings::turnstile_on() . '|' . MQP_Settings::get( 'turnstile_site_key' ) !== $before ) {
			mqp_purge_page_cache();
		}
		return self::get_settings();
	}

	/* ---- Brands ---- */

	public static function list_brands() {
		global $wpdb;
		return array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'has_branches', 'sort', 'active' ), array( 'commission_rate' ) );
		}, $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'brands' ) . ' ORDER BY sort, id', ARRAY_A ) );
	}

	public static function save_brands( WP_REST_Request $req ) {
		global $wpdb;
		$p    = $req->get_json_params();
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $p['code'] ?? '' ) ) );
		if ( '' === $name || '' === $code ) {
			return MQP_REST::error( 'Name and code are required.' );
		}
		$rate = isset( $p['commission_rate'] ) && '' !== $p['commission_rate'] && null !== $p['commission_rate'] ? (float) $p['commission_rate'] : null;
		$data = array(
			'name'            => $name,
			'code'            => substr( $code, 0, 10 ),
			'has_branches'    => empty( $p['has_branches'] ) ? 0 : 1,
			'commission_rate' => $rate,
			'sort'            => (int) ( $p['sort'] ?? 0 ),
			'active'          => isset( $p['active'] ) && ! $p['active'] ? 0 : 1,
		);
		$wpdb->suppress_errors( true );
		$ok = ! empty( $p['id'] ) ? $wpdb->update( mqp_table( 'brands' ), $data, array( 'id' => (int) $p['id'] ) ) : $wpdb->insert( mqp_table( 'brands' ), $data );
		$wpdb->suppress_errors( false );
		if ( false === $ok ) {
			return MQP_REST::error( 'Could not save. Is the code already used?' );
		}
		return self::list_brands();
	}

	/* ---- Branches ---- */

	public static function list_branches() {
		global $wpdb;
		return array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'brand_id', 'radius_m', 'active' ), array( 'lat', 'lng' ) );
		}, $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' ORDER BY brand_id, id', ARRAY_A ) );
	}

	public static function save_branches( WP_REST_Request $req ) {
		global $wpdb;
		$p     = $req->get_json_params();
		$name  = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $p['code'] ?? '' ) ) );
		$brand = MQP_REST::brand( (int) ( $p['brand_id'] ?? 0 ) );
		if ( '' === $name || '' === $code || ! $brand ) {
			return MQP_REST::error( 'Brand, name and code are required.' );
		}
		$ips = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( $p['allowed_ips'] ?? '' ) ) as $ip ) {
			if ( filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ) {
				$ips[] = trim( $ip );
			}
		}
		$coord = function ( $v, $max ) {
			return isset( $v ) && '' !== $v && null !== $v ? max( -$max, min( $max, (float) $v ) ) : null;
		};
		$data = array(
			'brand_id'    => (int) $brand->id,
			'name'        => $name,
			'code'        => substr( $code, 0, 10 ),
			'address'     => sanitize_text_field( (string) ( $p['address'] ?? '' ) ),
			'lat'         => $coord( $p['lat'] ?? null, 90 ),
			'lng'         => $coord( $p['lng'] ?? null, 180 ),
			'radius_m'    => max( 10, (int) ( $p['radius_m'] ?? 100 ) ),
			'allowed_ips' => implode( "\n", array_unique( $ips ) ),
			'active'      => isset( $p['active'] ) && ! $p['active'] ? 0 : 1,
		);
		if ( ! empty( $p['id'] ) ) {
			$wpdb->update( mqp_table( 'branches' ), $data, array( 'id' => (int) $p['id'] ) );
		} else {
			$wpdb->insert( mqp_table( 'branches' ), $data );
		}
		return self::list_branches();
	}

	/** "Register this network": adds the admin's current internet IP to the shop's Wi-Fi list. */
	public static function register_ip( WP_REST_Request $req ) {
		global $wpdb;
		$branch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d', (int) $req['id'] ) );
		$ip     = mqp_client_ip();
		if ( ! $branch || ! $ip ) {
			return MQP_REST::error( 'Branch or IP not found.' );
		}
		$ips   = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $branch->allowed_ips ) ) );
		$ips[] = $ip;
		$wpdb->update( mqp_table( 'branches' ), array( 'allowed_ips' => implode( "\n", array_unique( $ips ) ) ), array( 'id' => $branch->id ) );
		return self::list_branches();
	}

	/* ---- POS products ---- */

	public static function list_products() {
		global $wpdb;
		return array_map( function ( $r ) {
			return mqp_cast_row( $r, array( 'id', 'brand_id', 'sort', 'active', 'web' ), array( 'price' ) );
		}, $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'pos_products' ) . ' ORDER BY category, sort, name', ARRAY_A ) );
	}

	public static function save_products( WP_REST_Request $req ) {
		global $wpdb;
		$p    = $req->get_json_params();
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		if ( '' === $name ) {
			return MQP_REST::error( 'Product name is required.' );
		}
		$brand_id = (int) ( $p['brand_id'] ?? 0 );
		if ( ! $brand_id ) {
			$brand_id = (int) $wpdb->get_var( 'SELECT id FROM ' . mqp_table( 'brands' ) . ' WHERE has_branches = 1 ORDER BY sort, id LIMIT 1' );
		}
		$data = array(
			'brand_id' => $brand_id,
			'name'     => $name,
			'category' => sanitize_text_field( (string) ( $p['category'] ?? '' ) ),
			'price'    => max( 0, mqp_money( $p['price'] ?? 0 ) ),
			'unit'     => sanitize_text_field( (string) ( $p['unit'] ?? 'pc' ) ),
			'sort'     => (int) ( $p['sort'] ?? 0 ),
			'active'   => isset( $p['active'] ) && ! $p['active'] ? 0 : 1,
			'web'      => empty( $p['web'] ) ? 0 : 1, // On the website: [mq_prices] and instant price in "Get Quotation".
		);
		if ( ! empty( $p['id'] ) ) {
			$wpdb->update( mqp_table( 'pos_products' ), $data, array( 'id' => (int) $p['id'] ) );
		} else {
			$wpdb->insert( mqp_table( 'pos_products' ), $data );
		}
		// The website's price list and the Get Quotation pop-up show the new price right away.
		mqp_purge_page_cache();
		return self::list_products();
	}

	/* ---- Staff ---- */

	public static function roles() {
		$roles = array();
		foreach ( MQP_Roles::roles() as $slug => $r ) {
			$roles[] = array( 'slug' => $slug, 'name' => $r['name'], 'caps' => $r['caps'] );
		}
		$caps = array();
		foreach ( MQP_Roles::capabilities() as $cap => $def ) {
			$caps[] = array( 'cap' => $cap, 'group' => $def[0], 'label' => $def[1] );
		}
		return array( 'roles' => $roles, 'capabilities' => $caps );
	}

	private static function format_user( WP_User $u ) {
		return array(
			'id'           => (int) $u->ID,
			'username'     => $u->user_login,
			'name'         => $u->display_name,
			'avatar'       => MQP_Avatar::version( $u->ID ),
			'email'        => $u->user_email,
			'role'         => MQP_Roles::user_role( $u ),
			'branch_id'    => (int) get_user_meta( $u->ID, 'mqp_branch_id', true ),
			'brand_id'     => (int) get_user_meta( $u->ID, 'mqp_brand_id', true ),
			'disabled'     => (bool) get_user_meta( $u->ID, 'mqp_disabled', true ),
			'overrides'    => MQP_Roles::overrides( $u ),
			'effective'    => array_values( array_filter( array_keys( MQP_Roles::capabilities() ), array( $u, 'has_cap' ) ) ),
			'is_wp_admin'  => in_array( 'administrator', $u->roles, true ),
			'sessions'     => MQP_Session::count( $u->ID ),
			'deleted_at'   => self::deleted_at( $u->ID ) ? wp_date( 'Y-m-d H:i:s', self::deleted_at( $u->ID ) ) : null,
			'purge_at'     => self::deleted_at( $u->ID ) ? wp_date( 'Y-m-d', self::deleted_at( $u->ID ) + self::KEEP_DAYS * DAY_IN_SECONDS ) : null,
		);
	}

	public static function list_users() {
		$users = get_users( array( 'role__in' => array_merge( array_keys( MQP_Roles::roles() ), array( 'administrator' ) ), 'orderby' => 'display_name' ) );
		return array_map( array( __CLASS__, 'format_user' ), $users );
	}

	/* ---- Safe delete ---- */

	/** Days a deleted account can still be restored before it is removed for good. */
	const KEEP_DAYS = 30;

	/** When the account was deleted (timestamp), or 0. */
	public static function deleted_at( $uid ) {
		return (int) get_user_meta( $uid, 'mqp_deleted_at', true );
	}

	/** Why this account can't be deleted by the current user, or ''. */
	private static function cannot_delete( WP_User $user ) {
		if ( (int) $user->ID === get_current_user_id() ) {
			return 'You cannot delete your own account.';
		}
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return 'WordPress administrator accounts can only be deleted in wp-admin → Users.';
		}
		$role = MQP_Roles::user_role( $user );
		if ( ! isset( MQP_Roles::roles()[ $role ] ) ) {
			return 'This is not a portal staff account.';
		}
		if ( 'mq_owner' === $role ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return 'Only a WordPress administrator can delete an Owner account.';
			}
			$others = array_filter( get_users( array( 'role' => 'mq_owner', 'exclude' => array( $user->ID ) ) ), function ( $o ) {
				return ! get_user_meta( $o->ID, 'mqp_disabled', true ) && ! self::deleted_at( $o->ID );
			} );
			if ( ! $others ) {
				return 'This is the only active Owner. Make someone else Owner first.';
			}
		}
		return '';
	}

	/** What the person still has open, so nothing is dropped by mistake. */
	public static function delete_impact( WP_REST_Request $req ) {
		global $wpdb;
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user ) {
			return MQP_REST::error( 'User not found.', 404 );
		}
		$id    = (int) $user->ID;
		$jobs  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'jobs' ) . " WHERE sales_person_id = %d AND stage NOT IN ('delivered','cancelled')", $id ) );
		$docs  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'documents' ) . " WHERE sales_person_id = %d AND status = 'open'", $id ) );
		$comm  = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM ' . mqp_table( 'commissions' ) . " WHERE sales_person_id = %d AND status IN ('pending','earned')", $id ), ARRAY_A );
		$shift = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'attendance' ) . ' WHERE user_id = %d AND time_out IS NULL LIMIT 1', $id ) );
		$why   = self::cannot_delete( $user );
		// Who can take over their open job orders and quotations: active staff who do sales.
		$takers = array();
		foreach ( get_users( array( 'role__in' => array_keys( MQP_Roles::roles() ), 'exclude' => array( $id ), 'orderby' => 'display_name' ) ) as $o ) {
			if ( $o->has_cap( 'mq_sales' ) && ! get_user_meta( $o->ID, 'mqp_disabled', true ) && ! self::deleted_at( $o->ID ) ) {
				$takers[] = array( 'id' => (int) $o->ID, 'name' => $o->display_name );
			}
		}
		return array(
			'can_delete'  => '' === $why,
			'reason'      => $why,
			'username'    => $user->user_login,
			'open_jobs'   => $jobs,
			'open_docs'   => $docs,
			'commissions' => array( 'count' => (int) $comm['n'], 'total' => (float) $comm['total'] ),
			'open_shift'  => $shift,
			'sessions'    => MQP_Session::count( $id ),
			'takers'      => $takers,
			'keep_days'   => self::KEEP_DAYS,
			'locked'      => self::pw_locked(),
		);
	}

	/** Wrong passwords on delete: at most 5 in 15 minutes. */
	private static function pw_locked() {
		return (int) get_transient( 'mqp_delpw_' . get_current_user_id() ) >= 5;
	}

	/** Checks "type their username" and the admin's own password. WP_Error or true. */
	private static function confirm_delete( WP_REST_Request $req, WP_User $user ) {
		$p = (array) $req->get_json_params();
		if ( self::pw_locked() ) {
			return MQP_REST::error( 'Too many wrong passwords. Try again in 15 minutes.', 429 );
		}
		if ( strtolower( trim( (string) ( $p['confirm'] ?? '' ) ) ) !== strtolower( $user->user_login ) ) {
			return MQP_REST::error( 'Type their username exactly to confirm.' );
		}
		$me = wp_get_current_user();
		if ( ! wp_check_password( (string) ( $p['password'] ?? '' ), $me->user_pass, $me->ID ) ) {
			$key = 'mqp_delpw_' . $me->ID;
			set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
			mqp_log( 'user_delete_refused', array( 'reason' => 'Wrong password while deleting ' . $user->user_login ), 'fail' );
			return MQP_REST::error( 'That is not your password.', 403 );
		}
		delete_transient( 'mqp_delpw_' . $me->ID );
		return true;
	}

	/** Emails the admins about account deletions (security). */
	private static function alert_admins( $subject, $line ) {
		$me = wp_get_current_user();
		MQP_Mail::send(
			MQP_Monitor::admin_to(),
			$subject,
			$subject,
			array( $line, MQP_Mail::table( array( array( 'Done by', $me->display_name . ' (' . $me->user_login . ')' ), array( 'When', wp_date( 'M j, Y g:i A' ) ) ) ), 'If this was not expected, restore the account in the portal: Admin → Staff → Deleted.' ),
			array( 'Open Staff', MQP_Portal::url() . '#/admin/staff' )
		);
	}

	public static function restore_user( WP_REST_Request $req ) {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user || ! self::deleted_at( $user->ID ) ) {
			return MQP_REST::error( 'This account is not deleted.', 404 );
		}
		if ( 'mq_owner' === MQP_Roles::user_role( $user ) && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can restore an Owner account.', 403 );
		}
		update_user_meta( $user->ID, 'mqp_disabled', (int) get_user_meta( $user->ID, 'mqp_was_disabled', true ) );
		delete_user_meta( $user->ID, 'mqp_deleted_at' );
		delete_user_meta( $user->ID, 'mqp_deleted_by' );
		delete_user_meta( $user->ID, 'mqp_was_disabled' );
		mqp_log( 'user_restored', array( 'reason' => $user->display_name . ' (' . $user->user_login . ')' ) );
		self::alert_admins( 'Staff account restored: ' . $user->display_name, $user->display_name . ' (' . $user->user_login . ') was restored and can sign in again' . ( get_user_meta( $user->ID, 'mqp_disabled', true ) ? ' once switched to Active.' : '.' ) );
		return self::format_user( get_userdata( $user->ID ) );
	}

	/** Deletes for good now (WordPress administrators only), with the same confirmation. */
	public static function purge_user( WP_REST_Request $req ) {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user || ! self::deleted_at( $user->ID ) ) {
			return MQP_REST::error( 'Delete the account first; it can be removed for good from the Deleted list.', 400 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can delete an account for good. It is removed by itself after ' . self::KEEP_DAYS . ' days.', 403 );
		}
		$ok = self::confirm_delete( $req, $user );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::purge( $user );
		return array( 'ok' => true );
	}

	/** For Start fresh (wp-admin → System → Tools): removes a test staff account for good. */
	public static function remove_for_good( WP_User $user ) {
		self::purge( $user );
	}

	/** Removes the WordPress account; their name stays on their past work. */
	private static function purge( WP_User $user ) {
		$names              = (array) get_option( 'mqp_deleted_users', array() );
		$names[ $user->ID ] = $user->display_name;
		update_option( 'mqp_deleted_users', $names, false );
		MQP_Session::end_all( $user->ID );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
		mqp_log( 'user_purged', array( 'reason' => $user->display_name . ' (' . $user->user_login . ')' ) );
	}

	/** Daily: accounts deleted more than 30 days ago are removed for good. */
	public static function purge_deleted() {
		foreach ( get_users( array( 'meta_key' => 'mqp_deleted_at', 'meta_compare' => 'EXISTS' ) ) as $u ) {
			$at = self::deleted_at( $u->ID );
			if ( $at && time() - $at > self::KEEP_DAYS * DAY_IN_SECONDS && ! in_array( 'administrator', $u->roles, true ) ) {
				self::purge( $u );
			}
		}
	}

	/** Creates or updates a staff account. Only WP administrators can touch administrator accounts. */
	public static function save_user( WP_REST_Request $req ) {
		$p    = $req->get_json_params();
		$role = sanitize_key( (string) ( $p['role'] ?? '' ) );
		if ( ! isset( MQP_Roles::roles()[ $role ] ) ) {
			return MQP_REST::error( 'Choose a role.' );
		}
		if ( 'mq_owner' === $role && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can create Owner accounts.', 403 );
		}
		$id = (int) ( $p['id'] ?? 0 );

		if ( $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				return MQP_REST::error( 'User not found.', 404 );
			}
			if ( in_array( 'administrator', $user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
				return MQP_REST::error( 'Only a WordPress administrator can edit this account.', 403 );
			}
			if ( self::deleted_at( $id ) ) {
				return MQP_REST::error( 'This account is deleted. Restore it first.', 409 );
			}
			$current = MQP_Roles::user_role( $user );
			if ( ! in_array( 'administrator', $user->roles, true ) && ! isset( MQP_Roles::roles()[ $current ] ) ) {
				return MQP_REST::error( 'This is not a portal staff account. Manage it in wp-admin → Users.', 403 );
			}
			if ( 'mq_owner' === $current && ! current_user_can( 'manage_options' ) ) {
				return MQP_REST::error( 'Only a WordPress administrator can change an Owner account.', 403 );
			}
			if ( ! empty( $p['password'] ) && strlen( (string) $p['password'] ) < 8 ) {
				return MQP_REST::error( 'Use a password of at least 8 characters.' );
			}
			$data = array(
				'ID'           => $id,
				'display_name' => sanitize_text_field( (string) ( $p['name'] ?? $user->display_name ) ),
				'user_email'   => sanitize_email( (string) ( $p['email'] ?? $user->user_email ) ),
			);
			if ( ! empty( $p['password'] ) ) {
				$data['user_pass'] = (string) $p['password'];
			}
			$res = wp_update_user( $data );
			if ( is_wp_error( $res ) ) {
				return MQP_REST::error( $res->get_error_message() );
			}
			if ( ! in_array( 'administrator', $user->roles, true ) ) {
				$user->set_role( $role );
			}
		} else {
			$username = sanitize_user( (string) ( $p['username'] ?? '' ), true );
			if ( '' === $username || empty( $p['password'] ) || strlen( (string) $p['password'] ) < 8 ) {
				return MQP_REST::error( 'Username and a password of at least 8 characters are required.' );
			}
			$id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => (string) $p['password'],
					'user_email'   => sanitize_email( (string) ( $p['email'] ?? '' ) ),
					'display_name' => sanitize_text_field( (string) ( $p['name'] ?? $username ) ),
					'role'         => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				return MQP_REST::error( $id->get_error_message() );
			}
		}
		update_user_meta( $id, 'mqp_branch_id', (int) ( $p['branch_id'] ?? 0 ) );
		update_user_meta( $id, 'mqp_brand_id', (int) ( $p['brand_id'] ?? 0 ) );
		if ( $id !== get_current_user_id() ) {
			update_user_meta( $id, 'mqp_disabled', empty( $p['disabled'] ) ? 0 : 1 );
			if ( ! empty( $p['disabled'] ) ) {
				MQP_Session::end_all( $id );
				if ( class_exists( 'WP_Session_Tokens' ) ) {
					WP_Session_Tokens::get_instance( $id )->destroy_all();
				}
			}
		}
		return self::format_user( get_userdata( $id ) );
	}

	/**
	 * Deletes a staff account for good. Their past work (job orders, sales, attendance, logs) stays
	 * and shows their name with "(deleted)". Not for your own account or WordPress administrators.
	 */
	/** Signs one person out of the portal on every device (their wp-admin login is not touched). */
	public static function signout_user( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return MQP_REST::error( 'User not found.', 404 );
		}
		if ( $id === get_current_user_id() ) {
			return MQP_REST::error( 'Use "Log out" in the menu to sign yourself out.', 403 );
		}
		if ( ( in_array( 'administrator', $user->roles, true ) || 'mq_owner' === MQP_Roles::user_role( $user ) ) && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can sign out an administrator or an Owner.', 403 );
		}
		$n = MQP_Session::count( $id );
		MQP_Session::end_all( $id );
		MQP_Monitor::log( 'system', 'Signed out ' . $user->display_name . ' on every device' );
		return array( 'ok' => true, 'devices' => $n );
	}

	/**
	 * Deletes a staff account safely. Body: { confirm: their username, password: your own password,
	 * handover: user id to take over their open job orders and quotations (0 = leave them) }.
	 * The account goes to Staff → Deleted: signed out and blocked at once, restorable for 30 days,
	 * then removed for good by the daily job. The admins are emailed.
	 */
	public static function delete_user( WP_REST_Request $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return MQP_REST::error( 'User not found.', 404 );
		}
		$why = self::cannot_delete( $user );
		if ( $why ) {
			return MQP_REST::error( $why, 403 );
		}
		if ( self::deleted_at( $id ) ) {
			return MQP_REST::error( 'This account is already deleted.', 409 );
		}
		$ok = self::confirm_delete( $req, $user );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$p    = (array) $req->get_json_params();
		$to   = (int) ( $p['handover'] ?? 0 );
		$moved = 0;
		if ( $to ) {
			$taker = get_userdata( $to );
			if ( ! $taker || $to === $id || ! $taker->has_cap( 'mq_sales' ) || get_user_meta( $to, 'mqp_disabled', true ) || self::deleted_at( $to ) ) {
				return MQP_REST::error( 'Choose an active sales person to take over their work.' );
			}
			$moved += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'jobs' ) . " SET sales_person_id = %d WHERE sales_person_id = %d AND stage NOT IN ('delivered','cancelled')", $to, $id ) );
			$moved += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'documents' ) . " SET sales_person_id = %d WHERE sales_person_id = %d AND status = 'open'", $to, $id ) );
		}

		// An open shift ends now, so attendance reports stay complete.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'attendance' ) . ' SET time_out = %s, auto_closed = 1 WHERE user_id = %d AND time_out IS NULL', mqp_now(), $id ) );
		update_user_meta( $id, 'mqp_was_disabled', (int) get_user_meta( $id, 'mqp_disabled', true ) );
		update_user_meta( $id, 'mqp_disabled', 1 );
		update_user_meta( $id, 'mqp_deleted_at', time() );
		update_user_meta( $id, 'mqp_deleted_by', get_current_user_id() );
		MQP_Session::end_all( $id );
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $id )->destroy_all();
		}
		$roles = MQP_Roles::roles();
		$role  = MQP_Roles::user_role( $user );
		mqp_log( 'user_deleted', array( 'reason' => $user->display_name . ' (' . $user->user_login . ') · ' . $roles[ $role ]['name'] . ( $moved ? " · $moved open item(s) handed over to " . get_userdata( $to )->display_name : '' ) ) );
		self::alert_admins( 'Staff account deleted: ' . $user->display_name, $user->display_name . ' (' . $user->user_login . ', ' . $roles[ $role ]['name'] . ') was deleted. They are signed out and cannot sign in. The account can be restored for ' . self::KEEP_DAYS . ' days, then it is removed for good.' );
		return array( 'ok' => true, 'id' => $id, 'moved' => $moved, 'purge_at' => wp_date( 'Y-m-d', time() + self::KEEP_DAYS * DAY_IN_SECONDS ) );
	}

	/** Body: { cap: 'grant' | 'deny' | 'default' } for any number of portal caps. */
	public static function save_caps( WP_REST_Request $req ) {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user ) {
			return MQP_REST::error( 'User not found.', 404 );
		}
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return MQP_REST::error( 'Administrator permissions cannot be changed here.', 403 );
		}
		$role = MQP_Roles::user_role( $user );
		if ( ! isset( MQP_Roles::roles()[ $role ] ) ) {
			return MQP_REST::error( 'This is not a portal staff account.', 403 );
		}
		if ( 'mq_owner' === $role && ! current_user_can( 'manage_options' ) ) {
			return MQP_REST::error( 'Only a WordPress administrator can change an Owner account.', 403 );
		}
		if ( (int) $user->ID === get_current_user_id() ) {
			return MQP_REST::error( 'You cannot change your own permissions.', 403 );
		}
		$known = MQP_Roles::capabilities();
		foreach ( (array) $req->get_json_params() as $cap => $mode ) {
			if ( ! isset( $known[ $cap ] ) ) {
				continue;
			}
			if ( 'mq_manage' === $cap && ! current_user_can( 'manage_options' ) ) {
				continue; // Only WP admins can hand out settings access.
			}
			if ( 'grant' === $mode ) {
				$user->add_cap( $cap, true );
			} elseif ( 'deny' === $mode ) {
				$user->add_cap( $cap, false );
			} else {
				$user->remove_cap( $cap );
			}
		}
		return self::format_user( get_userdata( $user->ID ) );
	}
}
