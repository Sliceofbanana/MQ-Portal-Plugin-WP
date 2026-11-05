<?php
defined( 'ABSPATH' ) || exit;

/**
 * Optional own address for the staff portal, e.g. https://app.mqprintsph.com/ instead of
 * https://mqprintsph.com/mq-portal/ (wp-admin → MQ Portal → System → Settings → Portal address).
 *
 * The subdomain must point at the same WordPress folder as the main site. On that address:
 * - "/" is the portal (plus /sw.js and /manifest.json), and the REST API and admin-ajax work;
 * - every link WordPress builds (REST, files, plugin assets) uses the portal address, so the
 *   browser never leaves it and the login cookie belongs to it;
 * - website pages, wp-login.php and wp-admin go back to the main site;
 * - the online order page always stays on the main site.
 * The main site's /mq-portal/ then forwards to the portal address.
 *
 * It only turns on after the plugin has checked that the address really reaches this site,
 * so a half-finished DNS or hosting setup can never lock staff out.
 */
class MQP_Domain {

	const OPT = 'mqp_domain';

	private static $main = null;

	/** Verified portal host (e.g. "app.mqprintsph.com"), or '' when the portal lives at /mq-portal/. */
	public static function host() {
		$d = (array) get_option( self::OPT, array() );
		return ! empty( $d['verified'] ) && ! empty( $d['host'] ) ? (string) $d['host'] : '';
	}

	/** Host typed in Settings, verified or not. */
	public static function wanted() {
		$d = (array) get_option( self::OPT, array() );
		return (string) ( $d['host'] ?? '' );
	}

	/** Main site origin, e.g. "https://mqprintsph.com" (no trailing slash). */
	public static function main_origin() {
		if ( null === self::$main ) {
			$p          = wp_parse_url( (string) get_option( 'home' ) );
			self::$main = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		}
		return self::$main;
	}

	public static function portal_origin( $host = null ) {
		$host = null === $host ? self::host() : $host;
		return $host ? wp_parse_url( self::main_origin(), PHP_URL_SCHEME ) . '://' . $host : '';
	}

	/** Is this request for the portal address? */
	public static function on_portal_host() {
		$host = self::host();
		if ( ! $host || empty( $_SERVER['HTTP_HOST'] ) ) {
			return false;
		}
		return strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) === strtolower( $host ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** Main-site URL → the same URL on the portal address (only while serving the portal address). */
	public static function swap( $url ) {
		return self::on_portal_host() && is_string( $url ) && 0 === strpos( $url, self::main_origin() )
			? self::portal_origin() . substr( $url, strlen( self::main_origin() ) )
			: $url;
	}

	/** Portal-address URL → the same URL on the main site. */
	public static function to_main( $url ) {
		$portal = self::portal_origin();
		return $portal && is_string( $url ) && 0 === strpos( $url, $portal ) ? self::main_origin() . substr( $url, strlen( $portal ) ) : $url;
	}

	/** Runs as the plugin loads, before any URL is built. */
	public static function boot() {
		add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
			$hosts[] = (string) wp_parse_url( self::main_origin(), PHP_URL_HOST );
			if ( self::host() ) {
				$hosts[] = preg_replace( '/:\d+$/', '', self::host() );
			}
			return array_unique( $hosts );
		} );
		if ( ! self::on_portal_host() ) {
			return;
		}
		foreach ( array( 'home_url', 'site_url', 'content_url', 'plugins_url', 'rest_url', 'admin_url' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'swap' ), 99 );
		}
		add_filter( 'request', array( __CLASS__, 'route' ), 1 );
		add_action( 'init', array( __CLASS__, 'keep_out' ), 0 );
		add_action( 'login_init', array( __CLASS__, 'to_main_site' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'pages_to_main' ), 0 );
	}

	/** On the portal address: "/" is the portal app, "/sw.js" and "/manifest.json" its helpers. */
	public static function route( $vars ) {
		$path = trim( (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$map  = array( '' => 'app', 'sw.js' => 'sw', 'manifest.json' => 'manifest', MQP_Portal::SLUG => 'app' );
		if ( isset( $map[ $path ] ) && ! isset( $vars['rest_route'] ) ) {
			return array( 'mqp_portal' => $map[ $path ] );
		}
		return $vars;
	}

	/** wp-admin (except admin-ajax) belongs to the main site. */
	public static function keep_out() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			self::to_main_site();
		}
	}

	public static function to_main_site() {
		wp_redirect( self::main_origin() . (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.SafeRedirect
		exit;
	}

	/** Anything that isn't the portal (website pages, the order page) opens on the main site. */
	public static function pages_to_main() {
		if ( ! get_query_var( 'mqp_portal' ) ) {
			self::to_main_site();
		}
	}

	/* ---------- Turning it on (System → Settings) ---------- */

	/** Cleans what was typed into a bare host name: "https://App.MQprintsph.com/" → "app.mqprintsph.com". */
	public static function clean( $input ) {
		$input = strtolower( trim( (string) $input ) );
		$input = preg_replace( '#^[a-z]+://#', '', $input );
		$input = preg_replace( '#[/?\#].*$#', '', $input );
		return preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{2,5})?$/', $input ) ? $input : '';
	}

	/**
	 * Checks that https://HOST/ reaches this WordPress site, then turns the address on.
	 *
	 * @return true|string True, or what is wrong in plain words.
	 */
	public static function verify( $host ) {
		$host = self::clean( $host );
		if ( ! $host ) {
			return 'Enter an address like app.mqprintsph.com.';
		}
		if ( wp_parse_url( self::main_origin(), PHP_URL_HOST ) . ( wp_parse_url( self::main_origin(), PHP_URL_PORT ) ? ':' . wp_parse_url( self::main_origin(), PHP_URL_PORT ) : '' ) === $host ) {
			return 'That is the main website address. Use a separate address such as app.mqprintsph.com.';
		}
		update_option( self::OPT, array( 'host' => $host, 'verified' => 0 ), false );

		// The health URL echoes this one-time code only on this site, which proves the address leads here.
		$code = wp_generate_password( 20, false );
		set_transient( 'mqp_domain_probe', $code, 5 * MINUTE_IN_SECONDS );
		$url  = self::portal_origin( $host ) . '/wp-json/' . MQP_REST::NS . '/health?probe=' . rawurlencode( $code );
		$res  = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0 ) );
		delete_transient( 'mqp_domain_probe' );

		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( false !== stripos( $msg, 'resolve' ) ) {
				return "$host does not exist on the internet yet. Add its DNS record (and wait a few minutes), then check again.";
			}
			if ( false !== stripos( $msg, 'SSL' ) || false !== stripos( $msg, 'certificate' ) ) {
				return "$host reaches the server but has no valid SSL certificate yet. Turn on SSL for it in Hostinger, wait a few minutes, then check again.";
			}
			return "Could not open $host: $msg";
		}
		$code_ok = 200 === wp_remote_retrieve_response_code( $res ) && ( json_decode( wp_remote_retrieve_body( $res ), true )['probe'] ?? '' ) === 'ok';
		if ( ! $code_ok ) {
			$status = wp_remote_retrieve_response_code( $res );
			return "$host answers (code $status) but not from this WordPress site. In Hostinger, set the subdomain’s folder to the main site’s folder (public_html), then check again.";
		}
		update_option( self::OPT, array( 'host' => $host, 'verified' => 1, 'since' => time() ), false );
		delete_transient( 'mqp_domain_live' );
		return true;
	}

	/** Health endpoint helper: answers the one-time code from verify(). */
	public static function probe_matches( $code ) {
		$want = get_transient( 'mqp_domain_probe' );
		return $want && is_string( $code ) && hash_equals( $want, $code );
	}

	public static function turn_off() {
		update_option( self::OPT, array( 'host' => self::wanted(), 'verified' => 0 ), false );
	}
}
