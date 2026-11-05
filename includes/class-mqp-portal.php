<?php
defined( 'ABSPATH' ) || exit;

/**
 * Serves the staff portal at /mq-portal/ as a standalone app (outside the theme),
 * plus its service worker and web manifest so it can be installed and work offline.
 */
class MQP_Portal {

	const SLUG = 'mq-portal';

	public static function init() {
		self::add_rewrite();
		if ( get_option( 'mqp_flush_rewrite' ) ) {
			// Late on init, after every module (portal, order page) has added its rewrite rules.
			add_action( 'init', function () {
				delete_option( 'mqp_flush_rewrite' );
				flush_rewrite_rules();
			}, 99 );
		}
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'mqp_portal';
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'render' ) );
		// Stop WordPress adding a trailing slash to /mq-portal/sw.js and manifest.json.
		add_filter( 'redirect_canonical', function ( $redirect ) {
			return get_query_var( 'mqp_portal' ) ? false : $redirect;
		} );
		add_action( 'wp_ajax_mqp_nonce', array( __CLASS__, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_mqp_nonce', array( __CLASS__, 'ajax_nonce' ) );
		add_shortcode( 'mq_staff_login', array( __CLASS__, 'login_link_shortcode' ) );
		// "MQ Portal" item in wp-admin that opens the portal.
		add_action( 'admin_menu', function () {
			add_menu_page( 'MQ Portal', 'MQ Portal', 'mq_access_portal', 'mq-portal', '__return_null', 'dashicons-store', 3 );
		} );
		add_action( 'admin_init', function () {
			if ( isset( $_GET['page'] ) && 'mq-portal' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				wp_safe_redirect( self::url() );
				exit;
			}
		}, 1 );
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?mqp_portal=app', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/sw\.js$', 'index.php?mqp_portal=sw', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/manifest\.json$', 'index.php?mqp_portal=manifest', 'top' );
	}

	/** Portal address: its own address when one is set up (System → Settings), else /mq-portal/. */
	public static function url() {
		return MQP_Domain::host() ? MQP_Domain::portal_origin() . '/' : home_url( '/' . self::SLUG . '/' );
	}

	/** [mq_staff_login] — small "Staff login" link for the homepage footer. */
	public static function login_link_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'text' => 'Staff Login' ), $atts );
		return '<a class="mqp-staff-login" href="' . esc_url( self::url() ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}

	/** Fresh REST nonce for a portal page that was loaded from the offline cache. */
	public static function ajax_nonce() {
		wp_send_json(
			array(
				'loggedIn' => is_user_logged_in(),
				'nonce'    => MQP_Session::token(),
			)
		);
	}

	public static function render() {
		$mode = get_query_var( 'mqp_portal' );
		if ( ! $mode ) {
			return;
		}
		// With its own address, the main site's /mq-portal/ forwards there (the #page part is kept by the browser).
		if ( 'app' === $mode && MQP_Domain::host() && ! MQP_Domain::on_portal_host() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		if ( 'sw' === $mode ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: ' . wp_parse_url( self::url(), PHP_URL_PATH ) );
			header( 'Cache-Control: no-cache' );
			$sw = file_get_contents( MQP_DIR . 'assets/sw.js' );
			echo str_replace(
				array( '__VERSION__', '__ASSETS__', '__PORTAL__' ),
				array( mqp_asset_version(), esc_url_raw( MQP_URL . 'assets/' ), esc_url_raw( self::url() ) ),
				$sw
			); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		if ( 'manifest' === $mode ) {
			wp_send_json(
				array(
					'name'             => 'MQ Portal',
					'short_name'       => 'MQ Portal',
					'start_url'        => self::url(),
					'scope'            => self::url(),
					'display'          => 'standalone',
					'background_color' => '#1e3a8a',
					'theme_color'      => '#1e3a8a',
					'icons'            => array(
						array( 'src' => MQP_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
						array( 'src' => MQP_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
					),
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		$config = self::config();
		include MQP_DIR . 'templates/portal.php';
		exit;
	}

	/** Boot data for the front-end app. */
	public static function config() {
		global $wpdb;
		$user   = wp_get_current_user();
		$logged = $user->exists() && $user->has_cap( 'mq_access_portal' );

		$config = array(
			'version'   => mqp_asset_version(),
			'restUrl'   => esc_url_raw( rest_url( 'mq/v1/' ) ),
			'ajaxUrl'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'portalUrl' => esc_url_raw( self::url() ),
			'orderUrl'  => esc_url_raw( MQP_Order::url() ),
			'assetsUrl' => esc_url_raw( MQP_URL . 'assets/' ),
			'siteName'  => get_bloginfo( 'name' ),
			// "Back to website" and the photo on the sign-in page (wp-admin → System → Settings).
			'siteUrl'    => esc_url_raw( MQP_Domain::to_main( home_url( '/' ) ) ),
			'loginPhoto' => esc_url_raw( MQP_Monitor::login_photo() ),
			// Logos (wp-admin → System → Settings → Logos): light (white), color, mark.
			'logos'      => array_map( 'esc_url_raw', MQP_Monitor::logos() ),
			'loggedIn'  => $logged,
			// Maintenance message when the portal is closed for this person ('' = open).
			'maintenance' => MQP_Monitor::maintenance_for(),
		);
		if ( current_user_can( 'manage_options' ) && MQP_Monitor::settings()['maintenance'] ) {
			$config['maintenanceOn'] = true;
			$config['systemUrl']     = esc_url_raw( admin_url( 'admin.php?page=mq-portal-system&tab=settings' ) );
		}
		if ( ! $logged ) {
			$config['signup'] = MQP_Signup::public_options();
			return $config;
		}

		$roles               = MQP_Roles::roles();
		$role                = MQP_Roles::user_role( $user );
		MQP_Session::mark();
		$config['nonce']     = MQP_Session::token(); // Check code for changes (sent as X-MQP-Token).
		$config['unread']    = MQP_Notify::unread( $user->ID );
		$config['user']      = array(
			'id'        => $user->ID,
			'name'      => $user->display_name,
			'avatar'    => MQP_Avatar::version( $user->ID ),
			'email'     => $user->user_email,
			'role'      => $role,
			'role_name' => isset( $roles[ $role ] ) ? $roles[ $role ]['name'] : ( 'administrator' === $role ? 'Administrator' : '' ),
			'caps'      => MQP_Roles::current_caps(),
			'branch_id' => (int) get_user_meta( $user->ID, 'mqp_branch_id', true ),
			'brand_id'  => (int) get_user_meta( $user->ID, 'mqp_brand_id', true ),
			'clock'     => MQP_Auth::status( $user->ID ),
			'prefs'     => MQP_REST_Me::preferences( $user->ID ),
		);
		$config['settings']  = MQP_Settings::public_subset();
		$config['brands']    = array_map(
			function ( $r ) {
				return mqp_cast_row( $r, array( 'id', 'has_branches', 'sort', 'active' ), array( 'commission_rate' ) );
			},
			$wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'brands' ) . ' ORDER BY sort, id', ARRAY_A )
		);
		$config['branches']  = array_map(
			function ( $r ) {
				return mqp_cast_row( array( 'id' => $r['id'], 'brand_id' => $r['brand_id'], 'name' => $r['name'], 'code' => $r['code'], 'address' => $r['address'], 'active' => $r['active'] ), array( 'id', 'brand_id', 'active' ) );
			},
			$wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'branches' ) . ' ORDER BY brand_id, id', ARRAY_A )
		);
		$config['salesStaff'] = MQP_REST::sales_staff();
		if ( current_user_can( 'mq_manage' ) ) {
			$config['pendingSignups'] = MQP_Signup::pending_count();
		}
		if ( current_user_can( 'mq_reviews' ) ) {
			$config['pendingReviews'] = MQP_Reviews::pending_count();
		}
		return $config;
	}
}
