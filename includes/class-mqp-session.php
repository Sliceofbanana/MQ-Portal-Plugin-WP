<?php
defined( 'ABSPATH' ) || exit;

/**
 * The portal's own sign-in, separate from wp-admin's.
 *
 * Signing in or out of the portal never touches the WordPress login cookie, so a WordPress
 * administrator stays signed in to wp-admin (and the other way round). On portal requests
 * (the portal page, its REST API under /mq/v1/ and its admin-ajax call) the person is taken
 * from the portal cookie only; everywhere else WordPress works as usual.
 *
 * Cookie: "user_id|token", HttpOnly, SameSite=Lax, ends when the browser closes. The server keeps
 * only a hash of each token (user meta mqp_sessions) with its expiry, so sessions can be ended
 * from the server (sign out all staff, account disabled, password changed).
 * Changes (POST etc.) also need the session's check code (cfg.nonce in the app, X-MQP-Token).
 */
class MQP_Session {

	const COOKIE   = 'mqp_portal_session';
	const MARK     = 'mqp_portal_on'; // Readable by the app: "someone is signed in here" (user id only, no secret).
	const META     = 'mqp_sessions';
	const LIFETIME = 2 * DAY_IN_SECONDS; // Same as a WordPress login without "Remember me".

	/** Session of this request: array( user_id, token hash ) or null. */
	private static $current = null;

	/** True when this request carried the right check code. */
	private static $checked = false;

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_user' ), 99 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'check_token' ), 90 );
		// Password changed or reset anywhere: end that person's other portal sessions.
		add_action( 'wp_set_password', array( __CLASS__, 'password_changed' ), 10, 2 );
		add_action( 'profile_update', array( __CLASS__, 'profile_updated' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'no_cache' ), 0 );
	}

	/**
	 * Portal pages and answers are personal, so caching plugins and the host's cache must never keep
	 * them. LiteSpeed Cache only skips pages of people signed in to WordPress, and portal staff
	 * aren't (they use the portal's own sign-in), so tell it directly.
	 */
	public static function no_cache() {
		if ( ! self::is_portal_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'MQ Portal: personal page' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/** The portal page, its REST API or its admin-ajax call (decided from the URL, before WordPress parses it). */
	public static function is_portal_request() {
		if ( MQP_Domain::on_portal_host() ) {
			return true;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$route  = isset( $_GET['rest_route'] ) ? strtolower( (string) wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security
		$prefix = '/' . strtolower( trim( rest_get_url_prefix(), '/' ) ) . '/';
		$lower  = strtolower( rawurldecode( $path ) );
		$at     = strpos( $lower, $prefix . 'mq/v1/' );
		if ( false !== $at ) {
			$route = substr( $lower, $at + strlen( $prefix ) - 1 );
		}
		$route = preg_replace( '#/+#', '/', $route );
		if ( 0 === strpos( $route, '/mq/v1/' ) ) {
			// Customer pages (upload, order) and the uptime check don't use the portal sign-in.
			return 0 !== strpos( $route, '/mq/v1/public/' ) && 0 !== strpos( $route, '/mq/v1/health' );
		}

		if ( '/admin-ajax.php' === substr( $path, -15 ) ) {
			return isset( $_REQUEST['action'] ) && 'mqp_nonce' === $_REQUEST['action']; // phpcs:ignore WordPress.Security
		}

		$portal = (string) wp_parse_url( MQP_Portal::url(), PHP_URL_PATH );
		if ( '' !== trim( $portal, '/' ) && untrailingslashit( $path ) === untrailingslashit( $portal ) ) {
			return true;
		}
		// ?mqp_portal=app is the portal page itself (plain links), never wp-admin or another script.
		if ( false !== strpos( $path, '/wp-admin/' ) || ( '.php' === substr( $path, -4 ) && 'index.php' !== basename( $path ) ) ) {
			return false;
		}
		return isset( $_GET['mqp_portal'] ) && 'app' === $_GET['mqp_portal']; // phpcs:ignore WordPress.Security
	}

	/** On portal requests the person comes from the portal cookie, never from the wp-admin login. */
	public static function determine_user( $user_id ) {
		if ( ! self::is_portal_request() ) {
			return $user_id;
		}
		// WordPress's own check (X-WP-Nonce) is for its login cookie; check_token() takes over here.
		$GLOBALS['wp_rest_auth_cookie'] = false;
		$s = self::read();
		return $s ? $s[0] : 0;
	}

	/** Valid session from the cookie: array( user_id, hash ) or null. */
	private static function read() {
		if ( null !== self::$current ) {
			return self::$current ?: null;
		}
		self::$current = false;
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '/^(\d+)\|([A-Za-z0-9]{40,64})$/', $raw, $m ) ) {
			return null;
		}
		$uid  = (int) $m[1];
		$hash = hash( 'sha256', $m[2] );
		$all  = self::all( $uid );
		if ( empty( $all[ $hash ] ) || (int) $all[ $hash ]['exp'] < time() ) {
			return null;
		}
		self::$current = array( $uid, $hash );
		return self::$current;
	}

	private static function all( $uid ) {
		$all = get_user_meta( $uid, self::META, true );
		return is_array( $all ) ? $all : array();
	}

	/** Keeps only sessions that have not run out. */
	private static function save( $uid, array $all ) {
		$now = time();
		$all = array_filter( $all, function ( $s ) use ( $now ) {
			return (int) $s['exp'] > $now;
		} );
		if ( $all ) {
			update_user_meta( $uid, self::META, $all );
		} else {
			delete_user_meta( $uid, self::META );
		}
	}

	/** Signs this browser in to the portal as $user. */
	public static function start( WP_User $user ) {
		$token = wp_generate_password( 48, false, false );
		$hash  = hash( 'sha256', $token );
		$all   = self::all( $user->ID );
		$all[ $hash ] = array(
			'exp'   => time() + self::LIFETIME,
			'login' => time(),
			'ip'    => mqp_client_ip(),
			'ua'    => substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 200 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		self::save( $user->ID, $all );
		self::cookie( $user->ID . '|' . $token, 0 );
		self::cookie( (string) $user->ID, 0, self::MARK );
		self::$current = array( (int) $user->ID, $hash );
		wp_set_current_user( $user->ID );
	}

	/** Sets the readable "signed in here" cookie for this session when it is missing (sessions from before 1.18). */
	public static function mark() {
		$s = self::read();
		if ( $s && ( $_COOKIE[ self::MARK ] ?? '' ) !== (string) $s[0] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::cookie( (string) $s[0], 0, self::MARK );
		}
	}

	/** Signs this browser out of the portal only. wp-admin stays signed in. */
	public static function end() {
		$s = self::read();
		if ( $s ) {
			$all = self::all( $s[0] );
			unset( $all[ $s[1] ] );
			self::save( $s[0], $all );
		}
		self::cookie( '', time() - YEAR_IN_SECONDS );
		self::cookie( '', time() - YEAR_IN_SECONDS, self::MARK );
		self::$current = false;
		wp_set_current_user( 0 );
	}

	/** Ends every portal session of a person (all devices), optionally keeping this browser's. */
	public static function end_all( $uid, $keep_current = false ) {
		$keep = array();
		$s    = self::read();
		if ( $keep_current && $s && (int) $s[0] === (int) $uid ) {
			$all  = self::all( $uid );
			$keep = isset( $all[ $s[1] ] ) ? array( $s[1] => $all[ $s[1] ] ) : array();
		}
		self::save( $uid, $keep );
	}

	/** Number of open portal sessions (devices) of a person. */
	public static function count( $uid ) {
		$now = time();
		return count( array_filter( self::all( $uid ), function ( $s ) use ( $now ) {
			return (int) $s['exp'] > $now;
		} ) );
	}

	private static function cookie( $value, $expires, $name = self::COOKIE ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => MQP_Domain::on_portal_host() ? '' : ( COOKIE_DOMAIN ? COOKIE_DOMAIN : '' ),
				'secure'   => is_ssl(),
				'httponly' => self::COOKIE === $name,
				'samesite' => 'Lax',
			)
		);
	}

	/** Check code for changes, tied to this session (the app sends it as X-MQP-Token). */
	public static function token() {
		$s = self::read();
		return $s ? substr( hash_hmac( 'sha256', 'mqp-portal|' . $s[1], wp_salt( 'nonce' ) ), 0, 32 ) : '';
	}

	/** Portal changes (POST, DELETE…) must carry this session's check code. */
	public static function check_token( $result ) {
		if ( null !== $result || ! self::is_portal_request() || ! self::read() ) {
			return $result;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== strpos( $uri, 'mq/v1/auth/login' ) ) {
			return $result; // Signing in again (e.g. as someone else) needs no check code.
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}
		$sent = isset( $_SERVER['HTTP_X_MQP_TOKEN'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_MQP_TOKEN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::$checked = '' !== $sent && hash_equals( self::token(), $sent );
		if ( ! self::$checked && false !== strpos( $uri, 'mq/v1/auth/logout' ) ) {
			return $result; // Signing out always works (even from an outdated page); see checked().
		}
		if ( ! self::$checked ) {
			// Same code as WordPress's, so the app fetches a fresh one and tries again.
			return new WP_Error( 'rest_cookie_invalid_nonce', 'Your session check expired. Please try again.', array( 'status' => 403 ) );
		}
		return $result;
	}

	/** Whether this change carried the session's check code (sign-out without it can't clock out). */
	public static function checked() {
		return self::$checked;
	}

	/** Password changed (portal, wp-admin or "lost password"): other portal sessions end. */
	public static function password_changed( $password, $uid ) {
		self::end_all( (int) $uid, true );
	}

	public static function profile_updated( $uid, $old, $data = array() ) {
		if ( isset( $data['user_pass'] ) && $old instanceof WP_User && $old->user_pass !== $data['user_pass'] ) {
			self::end_all( (int) $uid, true );
		}
	}
}
