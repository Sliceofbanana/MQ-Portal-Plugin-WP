<?php
defined( 'ABSPATH' ) || exit;

/**
 * Behind-the-scenes monitoring for the site owner (shown in wp-admin → MQ Portal → System):
 * - records problems (crashes, failed requests, database errors, emails that didn't send, a late daily job);
 * - writes the activity log for important changes made in the portal;
 * - emails an alert when something breaks (at most one an hour);
 * - answers /wp-json/mq/v1/health for an outside uptime monitor;
 * - health checks for the System page.
 */
class MQP_Monitor {

	const OPT = 'mqp_monitor';

	/** Every table the plugin needs (without the wp_mq_ prefix). */
	const TABLES = array( 'brands', 'branches', 'customers', 'documents', 'document_items', 'payments', 'jobs', 'job_events', 'uploads', 'files', 'orders', 'commissions', 'pos_products', 'pos_sales', 'pos_sale_items', 'printer_readings', 'attendance', 'attendance_breaks', 'audit_log', 'signups', 'errors', 'counters', 'reviews', 'notifications', 'archives' );

	/** Kinds of problems, as shown to the admin. */
	const KINDS = array(
		'crash'      => 'Crash',
		'request'    => 'Failed request',
		'database'   => 'Database',
		'email'      => 'Email',
		'background' => 'Daily job',
		'warning'    => 'PHP warning',
	);

	/** Choices offered on the Settings tab (value => label). */
	const CHOICES = array(
		'alert_gap'      => array( 15 => '15 minutes', 60 => '1 hour', 360 => '6 hours', 1440 => '1 day' ),
		'activity_days'  => array( 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years' ),
		'problem_days'   => array( 30 => '1 month', 90 => '3 months', 180 => '6 months' ),
		'login_attempts' => array( 3 => '3', 5 => '5', 10 => '10' ),
		'lock_minutes'   => array( 5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour' ),
	);

	private static $recording = false;
	private static $settings  = null;

	/** Called as soon as the plugin loads, so crashes early in a request are still caught. */
	public static function boot() {
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_rest' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maintenance_gate' ), 10, 3 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ) );
		add_action( 'mqp_daily', array( __CLASS__, 'daily' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'shutdown', array( __CLASS__, 'check_daily_job' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		if ( self::settings()['log_warnings'] ) {
			self::watch_warnings();
		}
	}

	public static function defaults() {
		return array(
			// Alerts.
			'alerts'          => 1,
			'email'           => get_option( 'admin_email' ), // Admin emails: one or more addresses, comma-separated.
			'mail_from_name'  => '',                         // Sender name of every email ('' = the company name).
			'alert_gap'       => 60,
			// Logs.
			'activity_days'   => 365,
			'problem_days'    => 90,
			'log_warnings'    => 0,
			// Maintenance: staff can't use the portal; administrators still can.
			'maintenance'     => 0,
			'maintenance_msg' => 'The staff portal is being updated. Please try again in a few minutes.',
			// Login security and spam limits (per IP, per hour).
			'login_attempts'  => 5,
			'lock_minutes'    => 15,
			'limit_orders'    => 15,
			'limit_signups'   => 5,
			'limit_reviews'   => 3,
			// Storage protection for files customers send (uploads, order files, review pictures).
			'limit_mb_ip'     => 300,   // MB per internet connection per day.
			'limit_mb_day'    => 3000,  // MB per day from all customers together.
			'storage_max_gb'  => 20,    // The portal's file folder never grows past this.
			'min_free_gb'     => 2,     // Stop accepting files when the server has less free space.
			'dismissed_days'  => 30,    // Files of dismissed customer uploads are deleted after this many days (0 = keep).
			// Archives of finished job orders' files (System → Files & archives → Archives).
			'archive_auto'       => 1,    // A new archive every week, and when the storage is nearly full.
			'archive_after_days' => 14,   // Job orders delivered/cancelled at least this long ago.
			'archive_max_mb'     => 1024, // One zip at most this big (more files go into the next one).
			'archive_at_percent' => 80,   // "Nearly full": this share of "Portal files at most".
			// Staff sign-in page: photo from the Media Library (0 = the workshop photo that comes with the plugin).
			'login_photo'     => 0,
			// Logos from the Media Library (0 = the one that comes with the plugin). See logo().
			'logo_light'      => 0,
			'logo_color'      => 0,
			'logo_mark'       => 0,
			// Deleting the plugin also deletes its data (off = data is kept).
			'delete_data'     => 0,
		);
	}

	public static function settings() {
		if ( null === self::$settings ) {
			$defaults       = self::defaults();
			self::$settings = array_intersect_key( wp_parse_args( (array) get_option( self::OPT, array() ), $defaults ), $defaults );
		}
		return self::$settings;
	}

	public static function save_settings( array $in ) {
		$d    = self::defaults();
		$pick = function ( $key ) use ( $in, $d ) {
			$v = (int) ( $in[ $key ] ?? $d[ $key ] );
			return isset( self::CHOICES[ $key ][ $v ] ) ? $v : $d[ $key ];
		};
		$num  = function ( $key, $min, $max ) use ( $in, $d ) {
			return isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? max( $min, min( $max, (int) $in[ $key ] ) ) : $d[ $key ];
		};
		$msg  = sanitize_text_field( (string) ( $in['maintenance_msg'] ?? '' ) );
		update_option(
			self::OPT,
			array(
				'alerts'          => empty( $in['alerts'] ) ? 0 : 1,
				'email'           => implode( ', ', MQP_Mail::recipients( (string) ( $in['email'] ?? '' ) ) ),
				'mail_from_name'  => substr( sanitize_text_field( (string) ( $in['mail_from_name'] ?? '' ) ), 0, 80 ),
				'alert_gap'       => $pick( 'alert_gap' ),
				'activity_days'   => $pick( 'activity_days' ),
				'problem_days'    => $pick( 'problem_days' ),
				'log_warnings'    => empty( $in['log_warnings'] ) ? 0 : 1,
				'maintenance'     => empty( $in['maintenance'] ) ? 0 : 1,
				'maintenance_msg' => '' !== $msg ? substr( $msg, 0, 300 ) : $d['maintenance_msg'],
				'login_attempts'  => $pick( 'login_attempts' ),
				'lock_minutes'    => $pick( 'lock_minutes' ),
				'limit_orders'    => $num( 'limit_orders', 1, 500 ),
				'limit_signups'   => $num( 'limit_signups', 1, 100 ),
				'limit_reviews'   => $num( 'limit_reviews', 1, 50 ),
				'limit_mb_ip'     => $num( 'limit_mb_ip', 10, 10000 ),
				'limit_mb_day'    => $num( 'limit_mb_day', 50, 100000 ),
				'storage_max_gb'  => $num( 'storage_max_gb', 1, 2000 ),
				'min_free_gb'     => $num( 'min_free_gb', 0, 500 ),
				'dismissed_days'  => $num( 'dismissed_days', 0, 3650 ),
				'archive_auto'       => empty( $in['archive_auto'] ) ? 0 : 1,
				'archive_after_days' => $num( 'archive_after_days', 0, 3650 ),
				'archive_max_mb'     => $num( 'archive_max_mb', 50, 4096 ),
				'archive_at_percent' => $num( 'archive_at_percent', 10, 100 ),
				'login_photo'     => isset( $in['login_photo'] ) && wp_attachment_is_image( (int) $in['login_photo'] ) ? (int) $in['login_photo'] : 0,
				'logo_light'    => isset( $in['logo_light'] ) && wp_attachment_is_image( (int) $in['logo_light'] ) ? (int) $in['logo_light'] : 0,
				'logo_color'    => isset( $in['logo_color'] ) && wp_attachment_is_image( (int) $in['logo_color'] ) ? (int) $in['logo_color'] : 0,
				'logo_mark'     => isset( $in['logo_mark'] ) && wp_attachment_is_image( (int) $in['logo_mark'] ) ? (int) $in['logo_mark'] : 0,
				'delete_data'     => empty( $in['delete_data'] ) ? 0 : 1,
			),
			false
		);
		self::$settings = null;
	}

	/** Photo for the staff sign-in page: the chosen Media Library image, or the bundled one. */
	public static function login_photo() {
		$id  = (int) self::settings()['login_photo'];
		$src = $id ? wp_get_attachment_image_src( $id, 'large' ) : false;
		return $src ? $src[0] : MQP_URL . 'assets/login-photo.jpg';
	}

	/** The three logos: what each is for, and the file that comes with the plugin. */
	const LOGOS = array(
		'light' => array( 'Logo for dark backgrounds (white)', 'logo-white.webp' ),
		'color' => array( 'Logo for light backgrounds (colour)', 'logo-wide.webp' ),
		'mark'  => array( 'Small icon', 'logo-color.webp' ),
	);

	/**
	 * A logo's address: the image chosen in wp-admin → MQ Portal → System → Settings, else the
	 * plugin's own file with ?ver= so browsers and caches fetch it again after each update.
	 */
	public static function logo( $which ) {
		$which = isset( self::LOGOS[ $which ] ) ? $which : 'light';
		$id    = (int) ( self::settings()[ 'logo_' . $which ] ?? 0 );
		$src   = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
		return $src ? $src[0] : self::default_logo( $which );
	}

	public static function default_logo( $which ) {
		return MQP_URL . 'assets/' . self::LOGOS[ $which ][1] . '?ver=' . rawurlencode( MQP_VERSION );
	}

	/** All three, for the portal app and the print view. */
	public static function logos() {
		return array( 'light' => self::logo( 'light' ), 'color' => self::logo( 'color' ), 'mark' => self::logo( 'mark' ) );
	}

	/* ---------- Maintenance mode ---------- */

	/** Message to show when the portal is closed for this user, or '' when they may use it. */
	public static function maintenance_for( $user = null ) {
		$s    = self::settings();
		$user = $user ? $user : wp_get_current_user();
		if ( empty( $s['maintenance'] ) || ( $user && $user->exists() && user_can( $user, 'manage_options' ) ) ) {
			return '';
		}
		return $s['maintenance_msg'];
	}

	/**
	 * While maintenance is on, portal requests from anyone but WordPress administrators get a 503.
	 * Customer pages (/public/…), logging in/out (login refuses staff itself) and /health keep working.
	 */
	public static function maintenance_gate( $result, $server, $request ) {
		if ( null !== $result || empty( self::settings()['maintenance'] ) ) {
			return $result;
		}
		$route = $request->get_route();
		$base  = '/' . MQP_REST::NS . '/';
		if ( 0 !== strpos( $route, $base ) ) {
			return $result;
		}
		$path = substr( $route, strlen( $base ) - 1 );
		if ( preg_match( '#^/(public/|health$|auth/)#', $path ) ) {
			return $result;
		}
		$msg = self::maintenance_for();
		return $msg ? new WP_Error( 'mqp_maintenance', $msg, array( 'status' => 503 ) ) : $result;
	}

	/** Reminds administrators on every page that staff are locked out. */
	public static function admin_bar( $bar ) {
		if ( ! empty( self::settings()['maintenance'] ) && current_user_can( 'manage_options' ) ) {
			$bar->add_node( array(
				'id'    => 'mqp-maintenance',
				'title' => '<span style="background:#d63638;color:#fff;padding:2px 8px;border-radius:3px">Portal maintenance ON</span>',
				'href'  => admin_url( 'admin.php?page=mq-portal-system&tab=settings' ),
			) );
		}
	}

	/* ---------- Optional: PHP warnings from the plugin ---------- */

	private static function watch_warnings() {
		$previous = null;
		$previous = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			function ( $no, $str, $file, $line ) use ( &$previous ) {
				if ( false !== strpos( wp_normalize_path( (string) $file ), wp_normalize_path( MQP_DIR ) ) ) {
					self::record( 'warning', $str, str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) ) . ':' . $line );
				}
				return $previous ? call_user_func( $previous, $no, $str, $file, $line ) : false;
			},
			E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE
		);
	}

	/* ---------- Recording problems ---------- */

	/**
	 * Saves a problem. The same problem again only bumps its count and marks it unread.
	 *
	 * @param string $kind    One of self::KINDS.
	 * @param string $message What went wrong (one line).
	 * @param string $detail  Where / extra technical detail.
	 */
	public static function record( $kind, $message, $detail = '' ) {
		global $wpdb;
		if ( self::$recording || ! $wpdb || ! function_exists( 'mqp_table' ) ) {
			return;
		}
		self::$recording = true;
		try {
			$message = substr( trim( (string) $message ), 0, 1000 );
			$url     = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$user    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			$now     = mqp_now();
			$hide    = $wpdb->suppress_errors( true );
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . mqp_table( 'errors' ) . ' (hash, kind, message, detail, url, user_id, hits, seen, first_seen, last_seen)
					VALUES (%s, %s, %s, %s, %s, %d, 1, 0, %s, %s)
					ON DUPLICATE KEY UPDATE hits = hits + 1, seen = 0, last_seen = VALUES(last_seen), detail = VALUES(detail), url = VALUES(url), user_id = VALUES(user_id)',
					md5( $kind . '|' . $message ),
					$kind,
					$message,
					substr( (string) $detail, 0, 4000 ),
					substr( sanitize_text_field( $url ), 0, 255 ),
					$user,
					$now,
					$now
				)
			);
			$is_new = 1 === (int) $wpdb->rows_affected;
			$wpdb->suppress_errors( $hide );
			self::alert( $kind, $message, $detail );
			if ( $is_new && ! in_array( $kind, array( 'email', 'warning' ), true ) && class_exists( 'MQP_Notify' ) && did_action( 'init' ) ) {
				MQP_Notify::problem( $kind, $message );
			}
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Never let monitoring break the page.
		}
		self::$recording = false;
	}

	/** Emails the admin about a problem, at most once an hour. */
	private static function alert( $kind, $message, $detail ) {
		$s = self::settings();
		if ( empty( $s['alerts'] ) || ! MQP_Mail::recipients( (string) $s['email'] ) || in_array( $kind, array( 'email', 'warning' ), true ) || get_transient( 'mqp_alert_sent' ) ) {
			return;
		}
		set_transient( 'mqp_alert_sent', 1, (int) $s['alert_gap'] * MINUTE_IN_SECONDS );
		$site = MQP_Mail::sender();
		$body = "Something went wrong in the MQ Portal on $site.\n\n"
			. 'What: ' . ( self::KINDS[ $kind ] ?? $kind ) . "\n"
			. "Problem: $message\n"
			. ( $detail ? 'Where: ' . strtok( $detail, "\n" ) . "\n" : '' )
			. 'When: ' . wp_date( 'M j, Y g:i A' ) . "\n\n"
			. "See all problems:\n" . admin_url( 'admin.php?page=mq-portal-system&tab=errors' ) . "\n\n"
			. 'You get at most one of these emails per ' . ( self::CHOICES['alert_gap'][ (int) $s['alert_gap'] ] ?? '1 hour' ) . ". Change this in wp-admin → MQ Portal → System → Settings.\n";
		wp_mail( MQP_Mail::recipients( (string) $s['email'] ), "MQ Portal problem: $message", $body );
	}

	public static function on_shutdown() {
		$e = error_get_last();
		if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$file = wp_normalize_path( $e['file'] );
		if ( false === strpos( $file, wp_normalize_path( MQP_DIR ) ) && ! self::is_portal_request() ) {
			return; // Another plugin's problem on another page: not ours to report.
		}
		$lines = explode( "\n", (string) $e['message'] );
		$where = str_replace( wp_normalize_path( ABSPATH ), '', $file ) . ':' . $e['line'];
		self::record( 'crash', $lines[0], $where . "\n\n" . str_replace( wp_normalize_path( ABSPATH ), '', implode( "\n", $lines ) ) );
	}

	private static function is_portal_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array( '/' . MQP_REST::NS . '/', 'rest_route=%2F' . str_replace( '/', '%2F', MQP_REST::NS ), 'rest_route=/' . MQP_REST::NS, '/' . MQP_Portal::SLUG . '/', '/' . MQP_Order::SLUG . '/' ) as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function mail_failed( $error ) {
		$data    = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
		self::record( 'email', is_wp_error( $error ) ? $error->get_error_message() : 'An email could not be sent.', $subject ? "Subject: $subject" : '' );
	}

	/** Records failed portal requests and writes the activity log for successful changes. */
	public static function after_rest( $response, $handler, $request ) {
		global $wpdb;
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . MQP_REST::NS . '/' ) ) {
			return $response;
		}
		$path   = substr( $route, strlen( MQP_REST::NS ) + 1 );
		$where  = $request->get_method() . ' ' . $path;
		$status = 200;
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		} elseif ( $response instanceof WP_HTTP_Response ) {
			$status = $response->get_status();
		}
		// "Duplicate entry" is expected: offline sync re-sends are stopped by unique keys.
		if ( $wpdb->last_error && false === stripos( $wpdb->last_error, 'Duplicate entry' ) ) {
			self::record( 'database', $wpdb->last_error, $where );
		}
		// 503 is a deliberate "closed" answer (maintenance mode, ordering turned off), not a breakdown.
		if ( $status >= 500 && 503 !== $status ) {
			self::record( 'request', is_wp_error( $response ) ? $response->get_error_message() : "The server answered $status.", $where );
		}
		if ( $status < 400 && 'GET' !== $request->get_method() ) {
			self::log_activity( $path, $request, $response instanceof WP_HTTP_Response ? $response->get_data() : $response );
		} elseif ( is_wp_error( $response ) ) {
			self::log_blocked( $path, $request, $response, $status );
		}
		return $response;
	}

	/** Customer uploads and orders turned away by the spam limit, the spam check or a bad file. */
	private static function log_blocked( $path, WP_REST_Request $req, WP_Error $error, $status ) {
		$events = array(
			'/public/upload'      => 'upload_blocked',
			'/public/orders'      => 'order_blocked',
			'/public/order-files' => 'order_blocked',
			'/public/review'      => 'review_blocked',
		);
		if ( ! isset( $events[ $path ] ) ) {
			return;
		}
		if ( 429 !== $status && ! in_array( $error->get_error_code(), array( 'mqp_spam', 'mqp_captcha', 'mqp_upload', 'mqp_rate', 'mqp_storage' ), true ) ) {
			return; // Ordinary form mistakes (missing name, no file) are not worth logging.
		}
		$who = sanitize_text_field( (string) ( $req->get_param( 'name' ) ?? ( ( $req->get_param( 'customer' ) ?? array() )['name'] ?? '' ) ) );
		mqp_log( $events[ $path ], array( 'user_id' => 0, 'username' => substr( $who, 0, 60 ), 'result' => 'blocked', 'reason' => $error->get_error_message() ) );
	}

	/* ---------- Activity log ---------- */

	/** Event → label, grouped for the filter on the System page. */
	public static function events() {
		return array(
			'Logins'              => array(
				'login'            => 'Logged in',
				'logout'           => 'Logged out',
				'password_changed' => 'Changed password',
			),
			'Time clock'          => array(
				'clock_in'          => 'Clocked in',
				'clock_out'         => 'Clocked out',
				'clock_break_start' => 'Started a break',
				'clock_break_end'   => 'Ended a break',
			),
			'Accounts & access'   => array(
				'signup'              => 'Account request',
				'user_created'        => 'Created an account',
				'user_updated'        => 'Edited an account',
				'permissions_changed' => 'Changed access rights',
				'user_deleted'        => 'Deleted an account',
				'user_restored'       => 'Restored a deleted account',
				'user_purged'         => 'Removed a deleted account for good',
				'user_delete_refused' => 'Wrong password while deleting an account',
			),
			'Settings'            => array(
				'settings_saved'      => 'Saved portal settings',
				'brands_saved'        => 'Saved brands',
				'shops_saved'         => 'Saved shops',
				'products_saved'      => 'Saved POS products',
				'shop_network'        => 'Registered shop Wi-Fi',
				'order_pricing_saved' => 'Saved order pricing',
			),
			'Sales & payments'    => array(
				'order_verified'   => 'Verified an online order payment',
				'order_rejected'   => 'Rejected an online order payment',
				'order_deleted'    => 'Deleted an online order',
				'payment_recorded' => 'Recorded a payment',
				'document_void'    => 'Voided a document',
				'pos_void'         => 'Voided a POS receipt',
				'commissions_paid' => 'Paid commissions',
				'quote_sent'       => 'Emailed a quotation to a customer',
				'invoice_sent'     => 'Emailed an invoice to a customer',
				'document_created' => 'Made a document',
				'document_changed' => 'Changed a document\'s total',
				'pos_sale'         => 'POS sale',
				'pos_price_flag'   => 'POS sale flagged for a check',
				'pos_flag_checked' => 'Checked flagged POS sales',
			),
			'Files & archives'    => array(
				'archive_made'       => 'Made an archive',
				'archive_downloaded' => 'Downloaded an archive',
				'archive_on_drive'   => 'Archive saved on Google Drive',
				'archive_freed'      => 'Freed up space (archive)',
				'archive_cancelled'  => 'Removed an archive zip',
				'data_reset'         => 'Started fresh (removed test data)',
			),
			'Customers (website)' => array(
				'upload_received' => 'Customer upload received',
				'upload_blocked'  => 'Customer upload blocked',
				'order_placed'    => 'Online order placed',
				'order_blocked'   => 'Online order blocked',
				'review_received' => 'Customer review received',
				'quote_requested' => 'Quotation request (website)',
				'quote_accepted'  => 'Customer accepted a quotation online',
				'customer_erased' => 'Removed a customer\'s personal data (privacy request)',
				'review_blocked'  => 'Customer review blocked',
			),
			'System (wp-admin)'   => array(
				'system' => 'System action',
			),
		);
	}

	public static function event_label( $event ) {
		foreach ( self::events() as $group ) {
			if ( isset( $group[ $event ] ) ) {
				return $group[ $event ];
			}
		}
		return ucfirst( str_replace( '_', ' ', $event ) );
	}

	private static function log_activity( $path, WP_REST_Request $req, $data ) {
		$data  = is_array( $data ) ? $data : array();
		$p     = (array) $req->get_json_params() + (array) $req->get_body_params();
		$num   = $data['number'] ?? ( $data['reference'] ?? '' );
		$event = '';
		$why   = '';

		if ( '/admin/settings' === $path ) {
			$event = 'settings_saved';
		} elseif ( preg_match( '#^/admin/(brands|branches|products)$#', $path, $m ) ) {
			$event = array( 'brands' => 'brands_saved', 'branches' => 'shops_saved', 'products' => 'products_saved' )[ $m[1] ];
		} elseif ( preg_match( '#^/admin/branches/(\d+)/register-ip$#', $path, $m ) ) {
			$event = 'shop_network';
			$why   = 'Shop #' . $m[1];
		} elseif ( '/admin/order-config' === $path ) {
			$event = 'order_pricing_saved';
		} elseif ( '/admin/users' === $path ) {
			$event = empty( $p['id'] ) ? 'user_created' : 'user_updated';
			$roles = MQP_Roles::roles();
			$role  = $data['role'] ?? '';
			$why   = trim( ( $data['name'] ?? '' ) . ' (' . ( $data['username'] ?? '' ) . ')' )
				. ( isset( $roles[ $role ] ) ? ' · ' . $roles[ $role ]['name'] : '' )
				. ( ! empty( $p['password'] ) && ! empty( $p['id'] ) ? ' · password reset' : '' )
				. ( ! empty( $data['disabled'] ) ? ' · disabled' : '' );
		} elseif ( preg_match( '#^/admin/users/(\d+)/caps$#', $path, $m ) ) {
			$event = 'permissions_changed';
			$caps  = MQP_Roles::capabilities();
			$parts = array();
			foreach ( (array) $req->get_json_params() as $cap => $mode ) {
				if ( isset( $caps[ $cap ] ) ) {
					$parts[] = ( 'grant' === $mode ? '+' : ( 'deny' === $mode ? '−' : '·' ) ) . ' ' . $caps[ $cap ][1];
				}
			}
			$why = ( $data['username'] ?? '#' . $m[1] ) . ( $parts ? ': ' . implode( '; ', $parts ) : '' );
		} elseif ( preg_match( '#^/orders/(\d+)/(verify|reject)$#', $path, $m ) ) {
			$event = 'verify' === $m[2] ? 'order_verified' : 'order_rejected';
			$why   = ( $num ? $num : 'Order #' . $m[1] ) . ( 'reject' === $m[2] ? ' · ' . sanitize_text_field( (string) $req->get_param( 'reason' ) ) : '' );
		} elseif ( preg_match( '#^/documents/(\d+)/payments$#', $path, $m ) ) {
			$event = 'payment_recorded';
			$why   = ( $num ? $num : 'Document #' . $m[1] ) . ( isset( $p['amount'] ) ? ' · ₱' . number_format( (float) $p['amount'], 2 ) : '' );
		} elseif ( preg_match( '#^/documents/(\d+)/void$#', $path, $m ) ) {
			$event = 'document_void';
			$why   = ( $num ? $num : 'Document #' . $m[1] ) . ' · ' . sanitize_text_field( (string) $req->get_param( 'reason' ) );
		} elseif ( preg_match( '#^/pos/sales/(\d+)/void$#', $path, $m ) ) {
			$event = 'pos_void';
			$why   = ( $num ? $num : 'Receipt #' . $m[1] ) . ' · ' . sanitize_text_field( (string) $req->get_param( 'reason' ) );
		} elseif ( '/finance/commissions/pay' === $path ) {
			$event = 'commissions_paid';
			$why   = count( array_filter( (array) $req->get_param( 'ids' ) ) ) . ' commission(s)';
		} elseif ( ( '/public/upload' === $path || '/public/orders' === $path ) && $num ) {
			// Customers on the website: no account, so the name they typed is shown as "who".
			$upload  = '/public/upload' === $path;
			$contact = (array) ( $p['contact'] ?? array() );
			$name    = sanitize_text_field( (string) ( $upload ? $req->get_param( 'name' ) : ( $contact['name'] ?? '' ) ) );
			$files   = $upload ? count( array_filter( (array) ( $_FILES['files']['name'] ?? array() ) ) ) : count( (array) ( $p['files'] ?? array() ) ); // phpcs:ignore WordPress.Security
			mqp_log(
				$upload ? 'upload_received' : 'order_placed',
				array(
					'user_id'  => 0,
					'username' => substr( $name, 0, 60 ),
					'reason'   => trim( $num . ' · ' . $files . ' file(s)' . ( $upload ? '' : ' · ₱' . number_format( (float) ( $data['total'] ?? 0 ), 2 ) ), ' ·' ),
				)
			);
			return;
		}
		if ( $event ) {
			self::log( $event, $why );
		}
	}

	/**
	 * Where admin emails go (System → Settings → Emails): new reviews, account requests, problems,
	 * archives, and orders / quotation requests unless the portal has its own address for those.
	 */
	public static function admin_to() {
		$list = MQP_Mail::recipients( (string) self::settings()['email'] );
		return $list ? implode( ', ', $list ) : (string) get_option( 'admin_email' );
	}

	/** Activity entry by the current user. */
	public static function log( $event, $details = '', $result = 'ok' ) {
		$u = wp_get_current_user();
		mqp_log( $event, array( 'username' => $u->exists() ? $u->user_login : '', 'reason' => $details, 'result' => $result ) );
	}

	/* ---------- Daily job ---------- */

	public static function daily() {
		global $wpdb;
		update_option( 'mqp_last_daily', time(), false );
		$s = self::settings();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . mqp_table( 'audit_log' ) . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - $s['activity_days'] * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . mqp_table( 'errors' ) . ' WHERE seen = 1 AND last_seen < %s', wp_date( 'Y-m-d H:i:s', time() - $s['problem_days'] * DAY_IN_SECONDS ) ) );
	}

	/** Every few hours: makes sure the daily job is scheduled and not running late. */
	public static function check_daily_job() {
		if ( get_transient( 'mqp_daily_check' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		set_transient( 'mqp_daily_check', 1, 6 * HOUR_IN_SECONDS );
		if ( ! wp_next_scheduled( 'mqp_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mqp_daily' );
			self::record( 'background', 'The daily job was not scheduled. It has been scheduled again.' );
			return;
		}
		$since = (int) get_option( 'mqp_monitor_since' );
		if ( ! $since ) {
			update_option( 'mqp_monitor_since', time(), false );
			return;
		}
		$last = max( (int) get_option( 'mqp_last_daily' ), $since );
		if ( time() - $last > 2 * DAY_IN_SECONDS ) {
			self::record( 'background', 'The daily job is running late.', 'Last run: ' . ( get_option( 'mqp_last_daily' ) ? wp_date( 'M j, Y g:i A', (int) get_option( 'mqp_last_daily' ) ) : 'never' ) . '. WordPress runs scheduled jobs when someone visits the site; see the System page for a fix.' );
		}
	}

	/* ---------- Health ---------- */

	public static function routes() {
		register_rest_route( MQP_REST::NS, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'health_endpoint' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** For an outside uptime monitor: 200 {status: ok} when the database and tables answer, 503 otherwise. No details. */
	public static function health_endpoint( WP_REST_Request $req ) {
		global $wpdb;
		$hide = $wpdb->suppress_errors( true );
		$ok   = '1' === (string) $wpdb->get_var( 'SELECT 1' ) && ! self::missing_tables();
		$wpdb->suppress_errors( $hide );
		$out = array( 'status' => $ok ? 'ok' : 'error', 'time' => gmdate( 'c' ) );
		// Portal address check (System → Settings): proves this address leads to this site.
		if ( $req->get_param( 'probe' ) && MQP_Domain::probe_matches( (string) $req->get_param( 'probe' ) ) ) {
			$out['probe'] = 'ok';
		}
		$res = new WP_REST_Response( $out, $ok ? 200 : 503 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function missing_tables() {
		global $wpdb;
		$have = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mq_' ) . '%' ) );
		return array_values( array_diff( array_map( 'mqp_table', self::TABLES ), $have ) );
	}

	public static function unread_count() {
		global $wpdb;
		$hide = $wpdb->suppress_errors( true );
		$n    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( 'errors' ) . ' WHERE seen = 0' );
		$wpdb->suppress_errors( $hide );
		return $n;
	}

	/** Size of the plugin's private file folder in bytes (cached for an hour). */
	public static function storage_used() {
		$size = get_transient( 'mqp_storage_used' );
		if ( false !== $size ) {
			return (int) $size;
		}
		$size = 0;
		$dir  = MQP_Files::base_dir();
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$size += $f->getSize();
			}
		}
		set_transient( 'mqp_storage_used', $size, HOUR_IN_SECONDS );
		return $size;
	}

	/** The site calling its own health URL (cached 5 minutes). */
	private static function loopback( $fresh = false ) {
		$cached = get_transient( 'mqp_loopback' );
		if ( false !== $cached && ! $fresh ) {
			return $cached;
		}
		$res = wp_remote_get( rest_url( MQP_REST::NS . '/health' ), array( 'timeout' => 8, 'sslverify' => false ) );
		if ( is_wp_error( $res ) ) {
			$out = array( 'warn', 'The site could not reach itself: ' . $res->get_error_message() . '. Open the health link below in your browser to check by hand.' );
		} else {
			$code = wp_remote_retrieve_response_code( $res );
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$out  = 200 === $code && isset( $body['status'] ) && 'ok' === $body['status']
				? array( 'ok', 'The portal API answers normally.' )
				: array( 'bad', "The portal API answered $code. Staff may see errors in the portal. A security or caching plugin may be blocking /wp-json/." );
		}
		set_transient( 'mqp_loopback', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Health checks for the System page.
	 *
	 * @return array[] Each: [ label, 'ok'|'warn'|'bad', what it means / how to fix ].
	 */
	/** A request to wp-cron.php from the server's cron job (wget/curl), not from a visitor. */
	public static function note_server_cron() {
		if ( ! wp_doing_cron() ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ( '' === $ua || preg_match( '/wget|curl/i', $ua ) ) && time() - (int) get_option( 'mqp_server_cron' ) > 5 * MINUTE_IN_SECONDS ) {
			update_option( 'mqp_server_cron', time(), false );
		}
	}

	/** Hourly job (quotation reminders): remember when it last ran. */
	public static function hourly() {
		MQP_Auth::close_stale_attendance();
		update_option( 'mqp_last_hourly', time(), false );
	}

	public static function checks( $fresh = false ) {
		global $wpdb;
		$out = array();

		$missing = self::missing_tables();
		$out[]   = $missing
			? array( 'Database tables', 'bad', 'Missing: ' . implode( ', ', $missing ) . '. Click “Run setup again”.' )
			: array( 'Database tables', 'ok', count( self::TABLES ) . ' tables present.' );

		$dbv   = (string) get_option( 'mqp_db_version' );
		$out[] = MQP_DB_VERSION === $dbv
			? array( 'Database version', 'ok', "Up to date (version $dbv)." )
			: array( 'Database version', 'warn', "Database is at version $dbv, the plugin expects " . MQP_DB_VERSION . '. Click “Run setup again”.' );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = array( 'Portal and order page links', 'bad', 'Permalinks are set to “Plain”, so /' . MQP_Portal::SLUG . '/ and /' . MQP_Order::SLUG . '/ don’t work. Choose “Post name” in Settings → Permalinks.' );
		} else {
			$rules = (array) get_option( 'rewrite_rules' );
			$ok    = isset( $rules[ '^' . MQP_Portal::SLUG . '/?$' ], $rules[ '^' . MQP_Order::SLUG . '/?$' ] );
			$out[] = $ok
				? array( 'Portal and order page links', 'ok', '/' . MQP_Portal::SLUG . '/ and /' . MQP_Order::SLUG . '/ are active.' )
				: array( 'Portal and order page links', 'bad', 'The links are missing, so these pages show “not found”. Click “Run setup again”.' );
		}

		$lb    = self::loopback( $fresh );
		$out[] = array( 'Portal API', $lb[0], $lb[1] );

		if ( MQP_Domain::host() ) {
			$live = get_transient( 'mqp_domain_live' );
			if ( false === $live || $fresh ) {
				$r    = wp_remote_get( MQP_Domain::portal_origin() . '/wp-json/' . MQP_REST::NS . '/health', array( 'timeout' => 8, 'redirection' => 0 ) );
				$live = ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ? 'ok' : ( is_wp_error( $r ) ? $r->get_error_message() : 'answered ' . wp_remote_retrieve_response_code( $r ) );
				set_transient( 'mqp_domain_live', $live, 5 * MINUTE_IN_SECONDS );
			}
			$out[] = 'ok' === $live
				? array( 'Portal address', 'ok', MQP_Portal::url() . ' works.' )
				: array( 'Portal address', 'bad', MQP_Portal::url() . " is not reaching this site ($live). Staff can’t open the portal. Fix the subdomain in Hostinger, or go back to the main address in Settings." );
		}

		$ts = MQP_Settings::all();
		if ( ( '' !== trim( (string) $ts['turnstile_site_key'] ) ) !== ( '' !== trim( (string) $ts['turnstile_secret'] ) ) ) {
			$out[] = array( 'Spam check (Turnstile)', 'warn', 'Only one of the two Turnstile keys is filled in (portal → Admin → Settings → Customer uploads), so the spam check is off. Add both the Site key and the Secret key from Cloudflare, or clear both.' );
		}

		$tz    = wp_timezone_string();
		$out[] = in_array( $tz, array( 'Asia/Manila', '+08:00' ), true )
			? array( 'Timezone', 'ok', 'Manila (' . $tz . ').' )
			: array( 'Timezone', 'bad', 'WordPress is set to ' . ( $tz ? $tz : 'UTC' ) . ', not Manila, so clock-in times, the clock-in hours and daily sales are 8 hours off. Go to Settings → General → Timezone and choose “Manila”.' );

		$out[] = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME )
			? array( 'HTTPS', 'ok', 'The site uses a secure connection.' )
			: array( 'HTTPS', 'bad', 'The site is not on HTTPS. Phones block GPS (clock-in at the shop) and the offline app without it. Turn on SSL in Hostinger.' );

		$dir   = MQP_Files::base_dir();
		$out[] = ! is_dir( $dir ) || ! wp_is_writable( $dir )
			? array( 'File storage', 'bad', 'The private upload folder is missing or not writable, so uploads fail. Click “Run setup again”, then check folder permissions in Hostinger.' )
			: ( ! file_exists( "$dir/.htaccess" )
				? array( 'File storage', 'warn', 'The folder protection file is missing, so customer files could be opened directly. Click “Run setup again”.' )
				: array( 'File storage', 'ok', size_format( self::storage_used() ) . ' used. Files are protected from direct links.' ) );

		$ms     = MQP_Settings::all();
		$wanted = max( (int) $ms['upload_max_mb'], (int) $ms['order_upload_max_mb'] ) * MB_IN_BYTES;
		$out[]  = $wanted <= wp_max_upload_size()
			? array( 'Upload size', 'ok', 'The server accepts files up to ' . size_format( wp_max_upload_size() ) . '.' )
			: array( 'Upload size', 'bad', 'The portal allows ' . size_format( $wanted ) . ' files but the server only accepts ' . size_format( wp_max_upload_size() ) . ', so big uploads fail. See System → Files & archives.' );

		$out[] = wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) )
			? array( 'Image processing', 'ok', 'Profile photos can be resized.' )
			: array( 'Image processing', 'warn', 'The server cannot resize images, so profile photo uploads fail. Ask Hostinger to enable GD or Imagick for PHP.' );

		$mail_fail = (string) $wpdb->get_var( 'SELECT MAX(last_seen) FROM ' . mqp_table( 'errors' ) . " WHERE kind = 'email' AND last_seen > '" . wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) . "'" );
		if ( $mail_fail ) {
			$out[] = array( 'Email', 'bad', 'An email failed to send on ' . mysql2date( 'M j, Y g:i A', $mail_fail ) . '. Order confirmations and account emails may not arrive. See Problems.' );
		} elseif ( ! has_action( 'phpmailer_init' ) ) {
			$out[] = array( 'Email', 'warn', 'No email (SMTP) plugin found. Order and account emails may land in spam. Install “WP Mail SMTP” and connect your Hostinger mailbox, then send a test email.' );
		} else {
			$out[] = array( 'Email', 'ok', 'No failed emails in the last 7 days.' );
		}

		$last = (int) get_option( 'mqp_last_daily' );
		$next = wp_next_scheduled( 'mqp_daily' );
		if ( ! $next ) {
			$out[] = array( 'Daily job', 'bad', 'Not scheduled. Click “Run setup again”.' );
		} elseif ( $last && time() - $last > 2 * DAY_IN_SECONDS ) {
			$out[] = array( 'Daily job', 'bad', 'Last ran ' . human_time_diff( $last ) . ' ago. It closes forgotten shifts and clears unused uploads. Set up the Hostinger cron job shown on the Settings tab so it runs on time.' );
		} else {
			$out[] = array( 'Daily job', 'ok', ( $last ? 'Last ran ' . human_time_diff( $last ) . ' ago' : 'Has not run yet' ) . '; next run in ' . human_time_diff( $next ) . '.' );
		}

		$server = (int) get_option( 'mqp_server_cron' );
		$hourly = (int) get_option( 'mqp_last_hourly' );
		if ( $server && time() - $server < 2 * HOUR_IN_SECONDS ) {
			$out[] = array( 'Background jobs', 'ok', 'The Hostinger cron job is running (last ' . human_time_diff( $server ) . ' ago), so reminders and daily jobs run on time.' . ( $hourly ? ' Hourly job last ran ' . human_time_diff( $hourly ) . ' ago.' : '' ) );
		} else {
			$out[] = array( 'Background jobs', 'warn', ( $server ? 'The Hostinger cron job stopped (last seen ' . human_time_diff( $server ) . ' ago).' : 'No Hostinger cron job yet.' ) . ' Quotation reminders, the daily cleanup and the weekly featured review only run when someone visits, and cached pages don\'t count, so they can be hours late. Add the cron job shown on the Settings tab.' . ( $hourly ? ' Hourly job last ran ' . human_time_diff( $hourly ) . ' ago.' : '' ) );
		}

		$out[] = version_compare( PHP_VERSION, '8.1', '>=' )
			? array( 'PHP version', 'ok', 'PHP ' . PHP_VERSION . '.' )
			: array( 'PHP version', 'warn', 'PHP ' . PHP_VERSION . ' is old. Choose PHP 8.2 or newer in Hostinger.' );

		$recent = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( 'errors' ) . " WHERE kind <> 'email' AND last_seen > '" . wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) . "'" );
		$out[]  = $recent
			? array( 'Problems in the last 24 hours', 'warn', "$recent problem(s) recorded. See the Problems tab." )
			: array( 'Problems in the last 24 hours', 'ok', 'None.' );

		return $out;
	}

	/** 'ok' | 'warn' | 'bad' for a list of checks. */
	public static function overall( array $checks ) {
		$states = array_column( $checks, 1 );
		return in_array( 'bad', $states, true ) ? 'bad' : ( in_array( 'warn', $states, true ) ? 'warn' : 'ok' );
	}
}
