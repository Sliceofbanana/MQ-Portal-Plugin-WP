<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → System: the site owner's control room, separate from the portal.
 * Problems, Activity log, Security & sessions, Files & archives, Tools and Settings. Health and
 * quick actions are on MQ Portal → Dashboard.
 * WordPress administrators only (manage_options).
 */
class MQP_System {

	const SLUG = 'mq-portal-system';

	const TABS = array(
		'errors'   => 'Problems',
		'activity' => 'Activity log',
		'security' => 'Security & sessions',
		'uploads'  => 'Files & archives',
		'tools'    => 'Tools',
		'settings' => 'Settings',
	);

	/** Parts of the setup that can be exported to a file and imported on another site (e.g. staging → live). */
	const CONFIG_PARTS = array(
		'settings'     => 'Portal settings (company, time clock, POS, uploads)',
		'order_config' => 'Online order pricing and options',
		'brands'       => 'Brands',
		'branches'     => 'Shops / branches (with Wi-Fi and GPS)',
		'pos_products' => 'POS products',
		'system'       => 'System settings on this page',
	);

	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'media_picker' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_mqp_system', array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( __CLASS__, 'old_links' ) );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'old_links' ) ); // The Website page no longer exists: WordPress stops there before admin_init.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
	}

	/** Media Library picker for the sign-in photo (Settings tab only). */
	public static function media_picker( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) || 'settings' !== ( $_GET['tab'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_enqueue_media();
		// One picker for every image setting: a button with data-field (hidden input), data-preview and data-title.
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){var frames={};$(document).on('click','.mqp-media-choose',function(e){e.preventDefault();var b=$(this),k=b.data('field');if(!frames[k]){frames[k]=wp.media({title:b.data('title'),button:{text:'Use this image'},library:{type:'image'},multiple:false});frames[k].on('select',function(){var a=frames[k].state().get('selection').first().toJSON();var u=(b.data('size')==='large'&&a.sizes&&a.sizes.large?a.sizes.large.url:a.url);$('#'+k).val(a.id);$(b.data('preview')).attr('src',u);b.siblings('.mqp-media-reset').prop('hidden',false);});}frames[k].open();});$(document).on('click','.mqp-media-reset',function(e){e.preventDefault();var b=$(this);$('#'+b.data('field')).val(0);$(b.data('preview')).attr('src',b.data('default'));b.prop('hidden',true);});});"
		);
	}

	public static function url( $tab = 'overview', array $args = array() ) {
		if ( 'overview' === $tab ) {
			return MQP_Dev_Admin::url( $args ); // Health and quick actions live on the Dashboard.
		}
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** Links from before 1.18.1 (System → Overview, the Website page) open the Dashboard. */
	public static function old_links() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ( self::SLUG === $page && 'overview' === $tab ) || 'mq-portal-website' === $page ) {
			wp_safe_redirect( MQP_Dev_Admin::url() );
			exit;
		}
	}

	private static function action_url( $op, $tab ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'mqp_system', 'op' => $op, 'tab' => $tab ), admin_url( 'admin-post.php' ) ), 'mqp_system' );
	}

	public static function menu() {
		global $menu;
		$n      = current_user_can( 'manage_options' ) ? MQP_Monitor::unread_count() : 0;
		$bubble = $n ? ' <span class="awaiting-mod count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>' : '';
		add_submenu_page( 'mq-portal', 'MQ Portal', 'Open portal ↗', 'mq_access_portal', 'mq-portal', '__return_null' );
		$hook = add_submenu_page( 'mq-portal', 'MQ Portal System', 'System' . $bubble, 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );

	}

	/* ---------- Buttons (admin-post.php) ---------- */

	public static function handle() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		check_admin_referer( 'mqp_system' );
		$op   = sanitize_key( (string) ( $_REQUEST['op'] ?? '' ) );
		$tab  = sanitize_key( (string) ( $_REQUEST['tab'] ?? 'overview' ) );
		$done = $op;
		$opt  = $wpdb->options;

		switch ( $op ) {
			case 'setup':
				MQP_Install::activate();
				delete_transient( 'mqp_loopback' );
				MQP_Monitor::log( 'system', 'Ran setup again' );
				break;
			case 'daily':
				do_action( 'mqp_daily' );
				MQP_Monitor::log( 'system', 'Ran the daily job by hand' );
				break;
			case 'recheck':
				delete_transient( 'mqp_loopback' );
				delete_transient( 'mqp_storage_used' );
				delete_transient( 'mqp_domain_live' );
				break;
			case 'seen':
				$wpdb->query( 'UPDATE ' . mqp_table( 'errors' ) . ' SET seen = 1' );
				break;
			case 'clear_errors':
				$wpdb->query( 'DELETE FROM ' . mqp_table( 'errors' ) );
				MQP_Monitor::log( 'system', 'Cleared the problem list' );
				break;
			case 'clear_lockouts':
				$wpdb->query( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_mqp\\_fail\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mqp\\_fail\\_%'" );
				MQP_Monitor::log( 'system', 'Unlocked all locked logins' );
				break;
			case 'clear_limits':
				$wpdb->query( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_%mqp\\_rl\\_%' OR option_name LIKE '\\_transient\\_%mqp\\_up\\_%'" );
				MQP_Monitor::log( 'system', 'Cleared order/upload/request limits' );
				break;
			case 'save_settings':
				$was = (int) MQP_Monitor::settings()['maintenance'];
				MQP_Monitor::save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in save_settings().
				$now = (int) MQP_Monitor::settings()['maintenance'];
				MQP_Monitor::log( 'system', $was !== $now ? 'Turned maintenance mode ' . ( $now ? 'ON' : 'off' ) : 'Changed system settings' );
				break;
			case 'domain_check':
				$result = MQP_Domain::verify( sanitize_text_field( wp_unslash( (string) ( $_POST['portal_host'] ?? '' ) ) ) );
				if ( true === $result ) {
					MQP_Monitor::log( 'system', 'Portal address set to ' . MQP_Portal::url() );
				} else {
					set_transient( 'mqp_domain_error_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
					$done = 'domain_failed';
				}
				break;
			case 'domain_off':
				MQP_Domain::turn_off();
				MQP_Monitor::log( 'system', 'Portal address back to ' . MQP_Portal::url() );
				break;
			case 'export_config':
				update_option( 'mqp_last_export', time(), false );
				self::export_config();
				exit;
			case 'import_config':
				$done = self::import_config();
				break;
			case 'signout_user':
				$uid = (int) ( $_REQUEST['user'] ?? 0 );
				$u   = $uid ? get_userdata( $uid ) : null;
				if ( $u && $uid !== get_current_user_id() ) {
					MQP_Session::end_all( $uid );
					MQP_Monitor::log( 'system', 'Signed out ' . $u->display_name . ' on every device' );
				}
				break;
			case 'signout_staff':
				$n = 0;
				foreach ( get_users( array( 'role__in' => array_keys( MQP_Roles::roles() ), 'fields' => 'ID' ) ) as $id ) {
					$u = get_userdata( $id );
					if ( $u && ! in_array( 'administrator', $u->roles, true ) ) {
						MQP_Session::end_all( $id );
						WP_Session_Tokens::get_instance( $id )->destroy_all();
						$n++;
					}
				}
				MQP_Monitor::log( 'system', "Signed out all staff ($n accounts)" );
				break;
			case 'test_email':
				$s    = MQP_Monitor::settings();
				$done = MQP_Mail::send( MQP_Monitor::admin_to(), 'Test email from ' . MQP_Mail::sender(), 'Test email', array( 'This is a test from wp-admin → MQ Portal → System → Settings.', 'If you got this, admin emails (new reviews, account requests, orders, problems) reach you, and they come from "' . MQP_Mail::sender() . '".' ) ) ? 'test_email' : 'test_email_failed';
				break;
			case 'export':
				self::export_activity();
				exit;
			default:
				$done = '';
		}
		wp_safe_redirect( self::url( $tab, $done ? array( 'done' => $done ) : array() ) );
		exit;
	}

	private static function notice() {
		$done = sanitize_key( (string) ( $_GET['done'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = array(
			'setup'             => array( 'success', 'Setup ran again: tables, roles, links, folders and the daily job are refreshed.' ),
			'daily'             => array( 'success', 'The daily job ran.' ),
			'recheck'           => array( 'success', 'Checked again.' ),
			'seen'              => array( 'success', 'All problems marked as read.' ),
			'clear_errors'      => array( 'success', 'The problem list is cleared.' ),
			'clear_lockouts'    => array( 'success', 'All locked logins are unlocked.' ),
			'clear_limits'      => array( 'success', 'Order, upload and account-request limits are cleared.' ),
			'save_settings'     => array( 'success', 'Settings saved.' ),
			'domain_check'      => array( 'success', 'Done: the portal now opens at its own address. Staff sign in there once.' ),
			'domain_off'        => array( 'success', 'The portal is back on the main website address.' ),
			'domain_failed'     => array( 'error', (string) get_transient( 'mqp_domain_error_' . get_current_user_id() ) . ' The portal stays where it was.' ),
			'import_config'     => array( 'success', 'Setup imported. Open the portal to check it.' ),
			'import_bad_file'   => array( 'error', 'That file is not an MQ Portal setup export.' ),
			'import_nothing'    => array( 'error', 'Choose a file and at least one part to import.' ),
			'signout_staff'     => array( 'success', 'All staff are signed out. They need to sign in again (administrators stay signed in).' ),
			'signout_user'      => array( 'success', 'Signed out of the portal on every device. They need to sign in again.' ),
			'test_email'        => array( 'success', 'Test email sent. Check the inbox (and spam folder).' ),
			'test_email_failed' => array( 'error', 'The test email could not be sent. Install an SMTP plugin (e.g. WP Mail SMTP) with your Hostinger mailbox.' ),
		);
		if ( isset( $msgs[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $done ][0] ), esc_html( $msgs[ $done ][1] ) );
		}
	}

	/* ---------- Page ---------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'errors';
		$n   = MQP_Monitor::unread_count();
		echo '<div class="wrap mqp-sys"><h1 class="wp-heading-inline">MQ Portal · System</h1>';
		echo '<hr class="wp-header-end">';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$count = 'errors' === $key && $n ? ' <span class="mqp-count">' . (int) $n . '</span>' : '';
			printf( '<a href="%s" class="nav-tab%s">%s%s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ), $count ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav><div class="mqp-tab">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div></div>';
	}

	private static function pill( $state ) {
		$text = array( 'ok' => 'OK', 'warn' => 'Check', 'bad' => 'Problem' );
		return '<span class="mqp-pill is-' . esc_attr( $state ) . '">' . esc_html( $text[ $state ] ?? $state ) . '</span>';
	}

	private static function button( $op, $tab, $label, $primary = false, $confirm = '' ) {
		printf(
			'<a class="button%s" href="%s"%s>%s</a> ',
			$primary ? ' button-primary' : '',
			esc_url( self::action_url( $op, $tab ) ),
			$confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '',
			esc_html( $label )
		);
	}

	/* Overview */

	/* Problems */

	private static function tab_errors() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'errors' ) . ' ORDER BY seen ASC, last_seen DESC LIMIT 200', ARRAY_A );
		echo '<p class="mqp-intro">Things that went wrong in the portal, the order page or emails. The same problem shows once with a count. You get an email alert when a new one happens (see Settings).</p>';
		if ( ! $rows ) {
			echo '<div class="mqp-card mqp-empty"><span class="dashicons dashicons-yes-alt"></span><p><strong>No problems recorded.</strong></p></div>';
			return;
		}
		echo '<p class="mqp-actions">';
		self::button( 'seen', 'errors', 'Mark all as read' );
		self::button( 'clear_errors', 'errors', 'Clear list', false, 'Remove every problem from the list?' );
		echo '</p><table class="widefat striped mqp-errors"><thead><tr><th>Last seen</th><th>Type</th><th>Problem</th><th>Page / request</th><th>Times</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
			echo '<tr class="' . ( $r['seen'] ? '' : 'is-new' ) . '">';
			echo '<td>' . esc_html( mysql2date( 'M j, g:i A', $r['last_seen'] ) ) . ( $r['seen'] ? '' : ' <span class="mqp-new">New</span>' ) . '</td>';
			echo '<td>' . esc_html( MQP_Monitor::KINDS[ $r['kind'] ] ?? $r['kind'] ) . '</td>';
			echo '<td><strong>' . esc_html( $r['message'] ) . '</strong>';
			if ( $r['detail'] ) {
				echo '<details><summary>Details</summary><pre>' . esc_html( $r['detail'] ) . '</pre></details>';
			}
			echo '</td><td>' . esc_html( $r['url'] ) . ( $user ? '<br><span class="description">' . esc_html( $user->display_name ) . '</span>' : '' ) . '</td>';
			echo '<td>' . (int) $r['hits'] . ( (int) $r['hits'] > 1 ? '<br><span class="description">since ' . esc_html( mysql2date( 'M j', $r['first_seen'] ) ) . '</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$wpdb->query( 'UPDATE ' . mqp_table( 'errors' ) . ' SET seen = 1 WHERE seen = 0' );
	}

	/* Activity log */

	private static function activity_filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$f = array(
			'group'   => sanitize_text_field( wp_unslash( (string) ( $_GET['group'] ?? '' ) ) ),
			'user_id' => (int) ( $_GET['user_id'] ?? 0 ),
			'from'    => sanitize_text_field( (string) ( $_GET['from'] ?? '' ) ),
			'to'      => sanitize_text_field( (string) ( $_GET['to'] ?? '' ) ),
			'q'       => sanitize_text_field( wp_unslash( (string) ( $_GET['q'] ?? '' ) ) ),
			'problem' => ! empty( $_GET['problem'] ),
		);
		// phpcs:enable
		$f['from'] = mqp_valid_date( $f['from'] ) ? $f['from'] : '';
		$f['to']   = mqp_valid_date( $f['to'] ) ? $f['to'] : '';
		return $f;
	}

	/** WHERE clause and its values for the activity filters. */
	private static function activity_where( array $f ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		$groups = MQP_Monitor::events();
		if ( $f['group'] && isset( $groups[ $f['group'] ] ) ) {
			$where[] = 'event IN (' . implode( ',', array_fill( 0, count( $groups[ $f['group'] ] ), '%s' ) ) . ')';
			$args    = array_merge( $args, array_keys( $groups[ $f['group'] ] ) );
		}
		if ( $f['user_id'] ) {
			$where[] = 'user_id = %d';
			$args[]  = $f['user_id'];
		}
		if ( $f['from'] ) {
			$where[] = 'created_at >= %s';
			$args[]  = $f['from'] . ' 00:00:00';
		}
		if ( $f['to'] ) {
			$where[] = 'created_at <= %s';
			$args[]  = $f['to'] . ' 23:59:59';
		}
		if ( $f['q'] ) {
			$like    = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$where[] = '(username LIKE %s OR reason LIKE %s OR ip LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( $f['problem'] ) {
			$where[] = "result <> 'ok'";
		}
		return array( implode( ' AND ', $where ), $args );
	}

	private static function activity_rows( array $f, $limit, $offset = 0 ) {
		global $wpdb;
		list( $where, $args ) = self::activity_where( $f );
		$table = mqp_table( 'audit_log' );
		$prep  = function ( $sql ) use ( $wpdb, $args ) {
			return $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL
		};
		$total = (int) $wpdb->get_var( $prep( "SELECT COUNT(*) FROM $table WHERE $where" ) );
		$rows  = $wpdb->get_results( $prep( "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset ), ARRAY_A );
		return array( $rows, $total );
	}

	private static function who( array $r ) {
		static $cache = array();
		$id = (int) $r['user_id'];
		if ( $id ) {
			if ( ! isset( $cache[ $id ] ) ) {
				$u            = get_userdata( $id );
				$cache[ $id ] = $u ? array( $u->display_name, $u->user_login ) : array( mqp_deleted_name( $id ) ? mqp_deleted_name( $id ) : 'Deleted user #' . $id, '' );
			}
			return $cache[ $id ];
		}
		$customer = in_array( $r['event'], array( 'upload_received', 'upload_blocked', 'order_placed', 'order_blocked' ), true );
		return $r['username'] ? array( $r['username'], $customer ? 'website customer' : 'not signed in' ) : array( $customer ? 'Website customer' : 'Visitor', '' );
	}

	private static function tab_activity() {
		$f    = self::activity_filters();
		$page = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		list( $rows, $total ) = self::activity_rows( $f, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$people = get_users( array( 'role__in' => array_merge( array_keys( MQP_Roles::roles() ), array( 'administrator' ) ), 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) );
		$keep   = MQP_Monitor::settings()['activity_days'];

		echo '<p class="mqp-intro">Who did what in the portal: logins, time clock, account and access changes, settings, payments and voids. Entries older than ' . (int) $keep . ' days are removed automatically.</p>';
		echo '<form method="get" class="mqp-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="activity">';
		echo '<select name="group"><option value="">All activity</option>';
		foreach ( array_keys( MQP_Monitor::events() ) as $g ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $g ), selected( $f['group'], $g, false ), esc_html( $g ) );
		}
		echo '</select><select name="user_id"><option value="0">Everyone</option>';
		foreach ( $people as $p ) {
			printf( '<option value="%d"%s>%s</option>', (int) $p->ID, selected( $f['user_id'], (int) $p->ID, false ), esc_html( $p->display_name ) );
		}
		echo '</select>';
		printf( '<label>From <input type="date" name="from" value="%s"></label><label>To <input type="date" name="to" value="%s"></label>', esc_attr( $f['from'] ), esc_attr( $f['to'] ) );
		printf( '<input type="search" name="q" value="%s" placeholder="Name, detail or IP">', esc_attr( $f['q'] ) );
		printf( '<label class="mqp-check"><input type="checkbox" name="problem" value="1"%s> Failed / blocked only</label>', checked( $f['problem'], true, false ) );
		echo '<button class="button">Filter</button>';
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mqp_system', 'op' => 'export' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), 'mqp_system' );
		echo ' <a class="button" href="' . esc_url( $export ) . '">Export CSV</a>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<div class="mqp-card mqp-empty"><p>No activity matches these filters.</p></div>';
			return;
		}
		echo '<table class="widefat striped mqp-activity"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>IP</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$who = self::who( $r );
			$res = 'ok' === $r['result'] ? '' : ' <span class="mqp-pill is-bad">' . esc_html( ucfirst( $r['result'] ) ) . '</span>';
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y g:i A', $r['created_at'] ) ) . '</td>';
			echo '<td><strong>' . esc_html( $who[0] ) . '</strong>' . ( $who[1] ? '<br><span class="description">' . esc_html( $who[1] ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( MQP_Monitor::event_label( $r['event'] ) ) . $res . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( $r['reason'] ) . '</td><td><code>' . esc_html( $r['ip'] ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' entries</span> ';
			echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div></div>';
		}
	}

	private static function export_activity() {
		list( $rows ) = self::activity_rows( self::activity_filters(), 50000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mq-portal-activity-' . wp_date( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads UTF-8 (₱, ñ) correctly with a BOM.
		fputcsv( $out, array( 'When', 'Name', 'Username', 'What', 'Result', 'Details', 'IP' ) );
		// Cells starting with = + - @ would run as formulas in Excel.
		$safe = function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
		};
		foreach ( $rows as $r ) {
			$who = self::who( $r );
			fputcsv( $out, array_map( $safe, array( $r['created_at'], $who[0], $r['username'] ? $r['username'] : $who[1], MQP_Monitor::event_label( $r['event'] ), $r['result'], $r['reason'], $r['ip'] ) ) );
		}
		fclose( $out );
	}

	/* Security */

	private static function tab_security() {
		global $wpdb;
		$opt    = $wpdb->options;
		$locked = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $opt v JOIN $opt t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(v.option_name, 12))
			WHERE v.option_name LIKE '\\_transient\\_mqp\\_fail\\_%' AND CAST(v.option_value AS UNSIGNED) >= " . (int) MQP_Monitor::settings()['login_attempts'] . " AND CAST(t.option_value AS UNSIGNED) > UNIX_TIMESTAMP()"
		);
		$limits = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $opt WHERE option_name LIKE '\\_transient\\_mqp\\_rl\\_%' OR option_name LIKE '\\_transient\\_mqp\\_up\\_%'" );
		$since  = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$fails  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT username, ip, COUNT(*) AS tries, MAX(created_at) AS last, SUM(result = %s) AS locks FROM ' . mqp_table( 'audit_log' ) . "
				WHERE event = 'login' AND result <> 'ok' AND created_at >= %s GROUP BY username, ip ORDER BY last DESC LIMIT 20",
				'locked',
				$since
			),
			ARRAY_A
		);

		echo '<div class="mqp-grid"><div>';

		echo '<div class="mqp-card"><h2>Logins</h2>';
		echo '<p>' . self::pill( $locked ? 'warn' : 'ok' ) . ' <strong>' . (int) $locked . '</strong> login(s) locked right now. After ' . (int) MQP_Monitor::settings()['login_attempts'] . ' wrong passwords, that username is locked on that device for ' . (int) MQP_Monitor::settings()['lock_minutes'] . ' minutes (change this in Settings).</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $locked ) {
			self::button( 'clear_lockouts', 'security', 'Unlock all now' );
		}
		echo '<h3>Wrong passwords in the last 24 hours</h3>';
		if ( ! $fails ) {
			echo '<p class="description">None.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Username tried</th><th>IP</th><th>Tries</th><th>Last try</th></tr></thead><tbody>';
			foreach ( $fails as $r ) {
				echo '<tr><td>' . esc_html( $r['username'] ) . ( (int) $r['locks'] ? ' <span class="mqp-pill is-bad">Locked</span>' : '' ) . '</td><td><code>' . esc_html( $r['ip'] ) . '</code></td><td>' . (int) $r['tries'] . '</td><td>' . esc_html( mysql2date( 'M j, g:i A', $r['last'] ) ) . '</td></tr>';
			}
			echo '</tbody></table><p class="description">Many tries on one username from an unknown IP can mean someone is guessing passwords.</p>';
		}
		echo '</div>';

		echo '<div class="mqp-card"><h2>Spam protection</h2><p>The order page, file uploads and account requests limit how many times one IP can submit per hour. ';
		echo '<strong>' . (int) $limits . '</strong> IP(s) are being counted right now.</p><p class="description">If a real customer says they are blocked from ordering, clear the limits.</p>';
		self::button( 'clear_limits', 'security', 'Clear limits' );
		echo '</div>';

		echo '<div class="mqp-card"><h2>Sessions</h2>';
		$signed = array();
		foreach ( get_users( array( 'role__in' => array_merge( array_keys( MQP_Roles::roles() ), array( 'administrator' ) ), 'orderby' => 'display_name' ) ) as $u ) {
			$n = MQP_Session::count( $u->ID );
			if ( $n ) {
				$signed[] = array( $u, $n );
			}
		}
		if ( $signed ) {
			echo '<p>Signed in to the portal now. <strong>Sign out</strong> ends that person\'s portal sessions on every device (their wp-admin login stays).</p><table class="widefat striped"><thead><tr><th>Name</th><th>Position</th><th>Devices</th><th></th></tr></thead><tbody>';
			$roles = MQP_Roles::roles();
			foreach ( $signed as $row ) {
				list( $u, $n ) = $row;
				$role = MQP_Roles::user_role( $u );
				echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( in_array( 'administrator', $u->roles, true ) ? 'WP Administrator' : ( $roles[ $role ]['name'] ?? $role ) ) . '</td><td>' . (int) $n . '</td><td>';
				if ( (int) $u->ID === get_current_user_id() ) {
					echo '<span class="description">You</span>';
				} else {
					echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'user', (int) $u->ID, self::action_url( 'signout_user', 'security' ) ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( 'Sign ' . $u->display_name . ' out of the portal on every device?' ) ) . ')">Sign out</a>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nobody is signed in to the portal right now.</p>';
		}
		echo '<p style="margin-top:14px">Or sign every staff member out on every device at once (for example after a phone is lost). Administrators stay signed in.</p>';
		self::button( 'signout_staff', 'security', 'Sign out all staff now', false, 'Sign out every staff member on every device?' );
		echo '</div>';

		echo '</div><div>';

		// Who has the keys.
		$users  = get_users( array( 'role__in' => array_merge( array_keys( MQP_Roles::roles() ), array( 'administrator' ) ), 'orderby' => 'display_name' ) );
		$roles  = MQP_Roles::roles();
		$admins = array();
		$off    = array();
		foreach ( $users as $u ) {
			if ( get_user_meta( $u->ID, 'mqp_disabled', true ) ) {
				$off[] = $u;
			} elseif ( in_array( 'administrator', $u->roles, true ) || $u->has_cap( 'mq_manage' ) ) {
				$admins[] = $u;
			}
		}
		$last_login = function ( $id ) use ( $wpdb ) {
			$t = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . mqp_table( 'audit_log' ) . " WHERE event = 'login' AND result = 'ok' AND user_id = %d", $id ) );
			return $t ? mysql2date( 'M j, Y', $t ) : '—';
		};
		echo '<div class="mqp-card"><h2>Who can change settings and staff</h2><p class="description">WordPress administrators, plus anyone given “Settings, staff and permissions” in the portal. Keep this list short.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Access</th><th>Last portal login</th></tr></thead><tbody>';
		foreach ( $admins as $u ) {
			$is_admin = in_array( 'administrator', $u->roles, true );
			$role     = MQP_Roles::user_role( $u );
			echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><span class="description">' . esc_html( $u->user_login ) . '</span></td>';
			echo '<td>' . ( $is_admin ? 'WordPress administrator (everything)' : esc_html( ( $roles[ $role ]['name'] ?? 'Staff' ) . ' + settings & staff' ) ) . '</td>';
			echo '<td>' . esc_html( $last_login( $u->ID ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="mqp-card"><h2>Disabled accounts</h2>';
		if ( ! $off ) {
			echo '<p class="description">None.</p>';
		} else {
			echo '<ul class="mqp-list">';
			foreach ( $off as $u ) {
				echo '<li>' . esc_html( $u->display_name ) . ' <span class="description">(' . esc_html( $u->user_login ) . ')</span></li>';
			}
			echo '</ul>';
		}
		$pending = MQP_Signup::pending_count();
		echo '<p>' . (int) $pending . ' account request(s) waiting. <a href="' . esc_url( MQP_Portal::url() . '#/admin/requests' ) . '" target="_blank" rel="noopener">Review in the portal</a></p>';
		echo '</div></div></div>';
	}

	/* Settings */

	private static function tab_settings() {
		$s      = MQP_Monitor::settings();
		$c      = MQP_Monitor::CHOICES;
		$health = rest_url( MQP_REST::NS . '/health' );
		$cron   = 'wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
		$select = function ( $key ) use ( $s, $c ) {
			$out = '<select id="mqp-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( $c[ $key ] as $v => $label ) {
				$out .= '<option value="' . (int) $v . '"' . selected( (int) $s[ $key ], (int) $v, false ) . '>' . esc_html( $label ) . '</option>';
			}
			return $out . '</select>';
		};
		$check = function ( $key, $label ) use ( $s ) {
			return '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( (int) $s[ $key ], 1, false ) . '> ' . $label . '</label>';
		};
		$row = function ( $label, $field, $help = '', $for = '' ) {
			echo '<tr><th scope="row">' . ( $for ? '<label for="mqp-' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>' . $field . ( $help ? '<p class="description">' . $help . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};

		echo '<p class="mqp-intro">Technical settings for you as the site administrator. Business settings (company, shops, prices, staff) stay in the portal under Admin → Settings.</p>';

		// Portal address: its own form, because turning it on runs a live check first.
		$live   = MQP_Domain::host();
		$wanted = MQP_Domain::wanted();
		echo '<div class="mqp-settings"><div class="mqp-card' . ( $live ? ' is-live' : '' ) . '"><h2>Portal address</h2>';
		if ( $live ) {
			echo '<p>' . self::pill( 'ok' ) . ' Staff open the portal at <a href="' . esc_url( MQP_Portal::url() ) . '" target="_blank" rel="noopener"><strong>' . esc_html( MQP_Portal::url() ) . '</strong></a>. The old link ' . esc_html( home_url( '/' . MQP_Portal::SLUG . '/' ) ) . ' forwards there.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::button( 'domain_off', 'settings', 'Go back to ' . home_url( '/' . MQP_Portal::SLUG . '/' ), false, 'Move the portal back to the main website address? Staff will need to sign in again.' );
			echo '<p class="description">Staff sign in again once after the address changes (each address keeps its own login).</p>';
		} else {
			echo '<p>Give the portal its own address, like <strong>app.mqprintsph.com</strong>, instead of ' . esc_html( home_url( '/' . MQP_Portal::SLUG . '/' ) ) . '. It stays in the address bar.</p>';
			echo '<ol class="mqp-steps"><li>In Hostinger, create the subdomain (e.g. <code>app</code>) and set its folder to the <strong>same folder as this website</strong> (usually <code>public_html</code>).</li>';
			echo '<li>If the domain’s DNS is not at Hostinger, add an <strong>A record</strong> named <code>app</code> pointing to the Hostinger server IP.</li>';
			echo '<li>Turn on <strong>SSL</strong> for the subdomain in Hostinger.</li>';
			echo '<li>Enter the address below and click <strong>Check and turn on</strong>. Nothing changes for staff unless the check passes.</li></ol>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mqp-inline">';
			wp_nonce_field( 'mqp_system' );
			echo '<input type="hidden" name="action" value="mqp_system"><input type="hidden" name="op" value="domain_check"><input type="hidden" name="tab" value="settings">';
			echo '<input type="text" class="regular-text code" name="portal_host" placeholder="app.mqprintsph.com" value="' . esc_attr( $wanted ) . '" required> <button class="button button-primary">Check and turn on</button></form>';
		}
		echo '</div></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mqp-settings">';
		wp_nonce_field( 'mqp_system' );
		echo '<input type="hidden" name="action" value="mqp_system"><input type="hidden" name="op" value="save_settings"><input type="hidden" name="tab" value="settings">';

		echo '<div class="mqp-card' . ( $s['maintenance'] ? ' is-maint' : '' ) . '"><h2>Maintenance mode</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Close the portal for staff', $check( 'maintenance', '<strong>Maintenance mode</strong>' ), 'Use while updating the plugin or fixing data. Staff see your message and cannot sign in; WordPress administrators keep working. The online order page and customer uploads keep working.' );
		$row( 'Message for staff', '<input id="mqp-maintenance_msg" type="text" class="large-text" name="maintenance_msg" maxlength="300" value="' . esc_attr( $s['maintenance_msg'] ) . '">', '', 'maintenance_msg' );
		echo '</tbody></table></div>';

		echo '<div class="mqp-card" id="emails"><h2>Emails</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Admin emails go to', '<input id="mqp-email" type="text" class="large-text" name="email" value="' . esc_attr( $s['email'] ) . '" placeholder="you@example.com, owner@example.com">', 'One or more addresses, separated by commas. They receive: <strong>new website reviews</strong>, account requests, archives to download, problem alerts, and online orders / quotation requests (unless the portal has its own address for those under Admin → Settings → Online orders).', 'email' );
		$row( 'Sender name', '<input id="mqp-mail_from_name" type="text" class="regular-text" name="mail_from_name" maxlength="80" value="' . esc_attr( $s['mail_from_name'] ) . '" placeholder="' . esc_attr( (string) MQP_Settings::get( 'company_name' ) ) . '">', 'Shown as "From" on every email customers and staff receive, instead of "WordPress". Empty = the company name. The sender address comes from your email plugin (WP Mail SMTP).', 'mail_from_name' );
		$row( 'Problem alerts', $check( 'alerts', 'Email the admins when something breaks' ), 'Crashes, failed requests, database errors and a late daily job. Failed emails and PHP warnings are only listed under Problems.' );
		$row( 'At most one alert every', $select( 'alert_gap' ), 'Stops a burst of the same problem from filling your inbox.', 'alert_gap' );
		echo '</tbody></table></div>';

		echo '<div class="mqp-card"><h2>Login security</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Lock after', $select( 'login_attempts' ) . ' wrong passwords', 'Counted per username and device (IP).', 'login_attempts' );
		$row( 'Lock for', $select( 'lock_minutes' ), 'Unlock early from the Security tab.', 'lock_minutes' );
		echo '</tbody></table></div>';

		$numfield = function ( $key, $min, $max, $unit = '' ) use ( $s ) {
			return '<input id="mqp-' . $key . '" type="number" min="' . (int) $min . '" max="' . (int) $max . '" class="small-text" name="' . $key . '" value="' . (int) $s[ $key ] . '">' . ( $unit ? ' ' . $unit : '' );
		};
		echo '<div class="mqp-card"><h2>Spam limits (per internet connection, per hour)</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Online orders', $numfield( 'limit_orders', 1, 500 ), 'Raise this if many customers order from the same shop Wi-Fi.', 'limit_orders' );
		$row( 'Account requests', $numfield( 'limit_signups', 1, 100 ), 'The upload form limit is in the portal under Admin → Settings → Uploads.', 'limit_signups' );
		$row( 'Website reviews', $numfield( 'limit_reviews', 1, 50 ), 'Also at most twice that per day from one connection, and 50 a day from everyone together.', 'limit_reviews' );
		echo '</tbody></table></div>';

		$used = MQP_Guard::storage_used();
		echo '<div class="mqp-card"><h2>Storage protection</h2><p class="description" style="margin-top:0">For files customers send: the upload form, online-order files and review pictures. When a limit is reached, customers are asked to email their files instead, and you get an alert. Staff uploads in the portal are not limited.</p><table class="form-table" role="presentation"><tbody>';
		$row( 'Per customer connection', $numfield( 'limit_mb_ip', 10, 10000, 'MB per day' ), 'Today from your connection: ' . size_format( MQP_Guard::bytes_today( true ) ) . '.', 'limit_mb_ip' );
		$row( 'All customers together', $numfield( 'limit_mb_day', 50, 100000, 'MB per day' ), 'Today so far: ' . size_format( MQP_Guard::bytes_today() ) . '.', 'limit_mb_day' );
		$row( 'Portal files at most', $numfield( 'storage_max_gb', 1, 2000, 'GB' ), 'Now: ' . size_format( $used ) . '. Check your Hostinger plan\'s disk space and leave room for the website and backups.', 'storage_max_gb' );
		$row( 'Keep free on the server', $numfield( 'min_free_gb', 0, 500, 'GB' ), 'Files are refused when less is free.', 'min_free_gb' );
		$row( 'Dismissed customer uploads', 'Delete their files after ' . $numfield( 'dismissed_days', 0, 3650, 'days' ), '0 = keep forever. Uploads turned into a job order keep their files.', 'dismissed_days' );
		$row( 'Archives', $check( 'archive_auto', 'Make an archive of finished job orders\' files every week, and when the storage is nearly full' ), 'You get an email to download it; nothing is deleted until you click “free up the space”. See Files & archives → Archives.' );
		$row( 'Archive job orders finished', $numfield( 'archive_after_days', 0, 3650, 'days ago or more' ), 'Delivered or cancelled at least this long ago.', 'archive_after_days' );
		$row( 'One archive at most', $numfield( 'archive_max_mb', 50, 4096, 'MB' ), 'Bigger downloads can fail on slow connections; more files go into the next archive.', 'archive_max_mb' );
		$row( 'Storage nearly full at', $numfield( 'archive_at_percent', 10, 100, '%' ), 'Of “Portal files at most”: an archive is made right away.', 'archive_at_percent' );
		echo '</tbody></table></div>';

		// Logos: each previewed on the background it is used on.
		$where = array(
			'light' => 'Portal menu, staff sign-in photo, order page header. Thermal receipts print it in black. Use a white logo with a transparent background (PNG or WebP).',
			'color' => 'Printed invoices, quotations, billing statements and job orders. Use the full-colour logo with a transparent or white background.',
			'mark'  => 'Above the staff sign-in form and on the customer\'s quotation page. A square or upright version of the logo works best.',
		);
		echo '<div class="mqp-card"><h2>Logos</h2><p class="description" style="margin-top:0">Choose or upload your logos, then click Save settings. The portal, the order page and printouts use them right away. Each upload has its own address, so nobody keeps seeing an old logo from their browser\'s memory.</p><table class="form-table" role="presentation"><tbody>';
		foreach ( MQP_Monitor::LOGOS as $key => $logo ) {
			$field = 'logo_' . $key;
			$bg    = 'light' === $key ? '#1e3a8a' : '#fff';
			$row(
				$logo[0],
				'<div class="mqp-photo-pick"><span style="display:inline-flex;align-items:center;justify-content:center;width:240px;height:96px;padding:12px;box-sizing:border-box;border:1px solid #c3c4c7;border-radius:6px;background:' . $bg . '">'
				. '<img id="mqp-' . $field . '-preview" src="' . esc_url( MQP_Monitor::logo( $key ) ) . '" alt="" style="width:auto;height:auto;max-width:100%;max-height:100%;object-fit:contain;border:0;border-radius:0;background:none"></span><div>'
				. '<input type="hidden" id="mqp-' . $field . '" name="' . $field . '" value="' . (int) $s[ $field ] . '">'
				. '<button type="button" class="button mqp-media-choose" data-field="mqp-' . $field . '" data-preview="#mqp-' . $field . '-preview" data-title="' . esc_attr( $logo[0] ) . '">Choose from Media Library</button> '
				. '<button type="button" class="button-link mqp-media-reset" data-field="mqp-' . $field . '" data-preview="#mqp-' . $field . '-preview" data-default="' . esc_url( MQP_Monitor::default_logo( $key ) ) . '"' . ( $s[ $field ] ? '' : ' hidden' ) . '>Use the default</button>'
				. '</div></div>',
				$where[ $key ],
				$field
			);
		}
		echo '</tbody></table></div>';

		$photo = MQP_Monitor::login_photo();
		echo '<div class="mqp-card"><h2>Staff sign-in page</h2><table class="form-table" role="presentation"><tbody>';
		$row(
			'Photo',
			'<div class="mqp-photo-pick"><img id="mqp-login-photo-preview" src="' . esc_url( $photo ) . '" alt=""><div>'
			. '<input type="hidden" id="mqp-login_photo" name="login_photo" value="' . (int) $s['login_photo'] . '">'
			. '<button type="button" class="button mqp-media-choose" data-field="mqp-login_photo" data-preview="#mqp-login-photo-preview" data-size="large" data-title="Photo for the staff sign-in page">Choose from Media Library</button> '
			. '<button type="button" class="button-link mqp-media-reset" data-field="mqp-login_photo" data-preview="#mqp-login-photo-preview" data-default="' . esc_url( MQP_URL . 'assets/login-photo.jpg' ) . '"' . ( $s['login_photo'] ? '' : ' hidden' ) . '>Use the default photo</button>'
			. '</div></div>',
			'Shown beside the sign-in form (on phones, at the top). Pick a wide photo of your shop or work, at least 1200 px. Then click Save settings.',
			'login_photo'
		);
		echo '</tbody></table></div>';

		echo '<div class="mqp-card"><h2>Logs</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Keep activity log', $select( 'activity_days' ), '', 'activity_days' );
		$row( 'Keep read problems', $select( 'problem_days' ), 'Unread problems are always kept.', 'problem_days' );
		$row( 'PHP warnings', $check( 'log_warnings', 'Also list PHP warnings and notices from this plugin under Problems' ), 'For troubleshooting. Turn off when done; warnings never send alert emails.' );
		echo '</tbody></table></div>';

		echo '<div class="mqp-card mqp-danger"><h2>When the plugin is deleted</h2><table class="form-table" role="presentation"><tbody>';
		$row( 'Data', $check( 'delete_data', 'Delete all MQ Portal data when the plugin is deleted' ), '<strong>Leave this off</strong> unless you are removing the portal for good. When on, deleting the plugin in Plugins removes every table (invoices, job orders, attendance, POS sales, orders), all uploaded files and photos, and the portal roles. Deactivating never deletes anything.' );
		echo '</tbody></table></div>';

		echo '<p class="submit"><button class="button button-primary button-large">Save settings</button> ';
		self::button( 'test_email', 'settings', 'Send a test email' );
		echo '</p></form>';

		MQP_GDrive::card(); // Its own form, so after the settings form.

		echo '<div class="mqp-grid mqp-grid-even"><div class="mqp-card"><h2>Make background jobs run on time</h2><p>WordPress only runs scheduled jobs (quotation reminders every hour, the daily cleanup, the weekly featured review) when someone opens an uncached page, so they can be hours late. A Hostinger cron job fixes this:</p>';
		echo '<ol class="mqp-steps"><li>In <strong>Hostinger → Websites → Manage → Advanced → Cron Jobs</strong>, choose <strong>Custom</strong>.</li><li>Paste this command:</li></ol>';
		echo '<input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr( $cron ) . '">';
		echo '<ol class="mqp-steps" start="3"><li>Set it to run <strong>every 15 minutes</strong> (minute <code>*/15</code>, every hour, day, month and weekday) and save.</li></ol>';
		$server = (int) get_option( 'mqp_server_cron' );
		echo '<p>' . ( $server && time() - $server < 2 * HOUR_IN_SECONDS ? '<span class="mqp-pill is-ok">Working</span> Last run ' . esc_html( human_time_diff( $server ) ) . ' ago.' : '<span class="mqp-pill is-bad">Not seen yet</span> This turns green within 15 minutes of adding the job.' ) . '</p></div>';
		echo '<div class="mqp-card"><h2>Know when the whole site is down</h2><p>If the site is down, WordPress can’t warn you. Add a free <strong>UptimeRobot</strong> HTTP(s) monitor (every 5 minutes) for:</p>';
		echo '<input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr( $health ) . '">';
		echo '<p class="description">It answers “ok” when the site and database work, an error otherwise, and shows no private data. <a href="' . esc_url( $health ) . '" target="_blank" rel="noopener">Open it</a></p></div></div>';
	}

	/* Uploads & files */

	private static function tab_uploads() {
		global $wpdb;
		$s       = MQP_Settings::all();
		$server  = wp_max_upload_size();
		$portal  = esc_url( MQP_Portal::url() );
		$files_t = mqp_table( 'files' );
		$ups_t   = mqp_table( 'uploads' );
		$hide    = $wpdb->suppress_errors( true );

		// Limits: the server must allow at least what the portal promises customers.
		$limits = array(
			array( 'Website upload form, per file', $s['upload_max_mb'] * MB_IN_BYTES ),
			array( 'Online order page, per file', $s['order_upload_max_mb'] * MB_IN_BYTES ),
		);
		echo '<div class="mqp-grid"><div>';
		echo '<div class="mqp-card"><h2>Upload limits</h2><table class="widefat striped mqp-checks"><tbody>';
		foreach ( $limits as $l ) {
			$ok = $l[1] <= $server;
			echo '<tr><td class="mqp-c-pill">' . self::pill( $ok ? 'ok' : 'bad' ) . '</td><th scope="row">' . esc_html( $l[0] ) . '</th><td>' . esc_html( size_format( $l[1] ) )
				. ( $ok ? '' : '<br><span class="description">The server only accepts ' . esc_html( size_format( $server ) ) . ', so bigger files fail. In Hostinger → Advanced → PHP Configuration raise <code>upload_max_filesize</code> and <code>post_max_size</code>, or lower this limit in the portal.</span>' )
				. '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<tr><td class="mqp-c-pill">' . self::pill( 'ok' ) . '</td><th scope="row">Server maximum</th><td>' . esc_html( size_format( $server ) ) . ' per request</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><td class="mqp-c-pill">' . self::pill( 'ok' ) . '</td><th scope="row">Allowed file types</th><td>' . esc_html( strtoupper( str_replace( ',', ', ', $s['upload_types'] ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><td class="mqp-c-pill">' . self::pill( 'ok' ) . '</td><th scope="row">Uploads per hour (one IP)</th><td>' . (int) $s['upload_rate_per_hour'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$captcha = $s['turnstile_site_key'] && $s['turnstile_secret'];
		echo '<tr><td class="mqp-c-pill">' . self::pill( $captcha ? 'ok' : 'warn' ) . '</td><th scope="row">Spam check (Cloudflare Turnstile)</th><td>' . ( $captcha ? 'On' : 'Off. Only the hidden bot trap and the hourly limit protect the forms. Add free Turnstile keys in the portal if you get spam.' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table><p class="mqp-actions"><a class="button" href="' . $portal . '#/admin/settings" target="_blank" rel="noopener">Change limits in the portal</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Recent customer uploads from the website form.
		$recent = $wpdb->get_results(
			"SELECT u.id, u.name, u.contact, u.status, u.created_at, b.name AS brand, COUNT(f.id) AS files, COALESCE(SUM(f.size), 0) AS bytes
			FROM $ups_t u LEFT JOIN " . mqp_table( 'brands' ) . " b ON b.id = u.brand_id LEFT JOIN $files_t f ON f.upload_id = u.id
			GROUP BY u.id ORDER BY u.id DESC LIMIT 15",
			ARRAY_A
		);
		echo '<div class="mqp-card"><h2>Latest customer uploads</h2>';
		if ( ! $recent ) {
			echo '<p class="description">No uploads yet. Put <code>[mq_upload]</code> on a website page to receive files.</p>';
		} else {
			echo '<table class="widefat striped mqp-uploads"><thead><tr><th>Received</th><th>Reference</th><th>Customer</th><th>Brand</th><th>Files</th><th>Status</th></tr></thead><tbody>';
			foreach ( $recent as $r ) {
				echo '<tr><td>' . esc_html( mysql2date( 'M j, g:i A', $r['created_at'] ) ) . '</td><td><code>' . esc_html( sprintf( 'U-%05d', $r['id'] ) ) . '</code></td>';
				echo '<td><strong>' . esc_html( $r['name'] ) . '</strong><br><span class="description">' . esc_html( $r['contact'] ) . '</span></td><td>' . esc_html( (string) $r['brand'] ) . '</td>';
				echo '<td>' . (int) $r['files'] . ' · ' . esc_html( size_format( (int) $r['bytes'] ) ) . '</td><td>' . esc_html( ucfirst( $r['status'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="mqp-actions"><a class="button" href="' . $portal . '#/sales/uploads" target="_blank" rel="noopener">Open the uploads inbox in the portal</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '</div><div>';

		// Last 7 days.
		$since  = wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$counts = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT event, COUNT(*) AS n FROM ' . mqp_table( 'audit_log' ) . " WHERE event IN ('upload_received','upload_blocked','order_placed','order_blocked') AND created_at >= %s GROUP BY event", $since ), ARRAY_A ) as $r ) {
			$counts[ $r['event'] ] = (int) $r['n'];
		}
		$blocked = ( $counts['upload_blocked'] ?? 0 ) + ( $counts['order_blocked'] ?? 0 );
		echo '<div class="mqp-card"><h2>Last 7 days</h2><table class="mqp-facts">';
		echo '<tr><th>Customer uploads received</th><td>' . (int) ( $counts['upload_received'] ?? 0 ) . '</td></tr>';
		echo '<tr><th>Online orders placed</th><td>' . (int) ( $counts['order_placed'] ?? 0 ) . '</td></tr>';
		echo '<tr><th>Turned away (spam limit, spam check or bad file)</th><td>' . (int) $blocked . '</td></tr>';
		echo '</table>';
		if ( $blocked ) {
			echo '<p><a href="' . esc_url( self::url( 'activity', array( 'group' => 'Customers (website)', 'problem' => 1 ) ) ) . '">See who was turned away</a> · <a href="' . esc_url( self::action_url( 'clear_limits', 'uploads' ) ) . '">Clear spam limits</a></p>';
		}
		echo '</div>';

		// Storage by type.
		$labels = array(
			'customer'      => 'Customer files (uploads and orders)',
			'design'        => 'Designs from Creatives',
			'proof'         => 'Proofs',
			'reference'     => 'Reference files',
			'payment'       => 'Payment proofs',
			'order_draft'   => 'Unfinished order uploads',
			'payment_draft' => 'Unfinished payment uploads',
		);
		$kinds  = $wpdb->get_results( "SELECT kind, COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM $files_t WHERE archived_at IS NULL GROUP BY kind ORDER BY bytes DESC", ARRAY_A );
		$avatar = 0;
		$adir   = MQP_Files::base_dir() . '/avatars';
		if ( is_dir( $adir ) ) {
			foreach ( new FilesystemIterator( $adir, FilesystemIterator::SKIP_DOTS ) as $f ) {
				$avatar += $f->getSize();
			}
		}
		$wpdb->suppress_errors( $hide );
		echo '<div class="mqp-card"><h2>Storage</h2><table class="widefat striped"><thead><tr><th>Type</th><th>Files</th><th>Size</th></tr></thead><tbody>';
		foreach ( (array) $kinds as $k ) {
			echo '<tr><td>' . esc_html( $labels[ $k['kind'] ] ?? ucfirst( str_replace( '_', ' ', $k['kind'] ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $k['n'] ) ) . '</td><td>' . esc_html( size_format( (int) $k['bytes'] ) ) . '</td></tr>';
		}
		echo '<tr><td>Profile photos</td><td>—</td><td>' . esc_html( size_format( $avatar ) ) . '</td></tr>';
		echo '</tbody><tfoot><tr><th>Total on disk</th><th></th><th>' . esc_html( size_format( MQP_Monitor::storage_used() ) ) . '</th></tr></tfoot></table>';
		echo '<p class="description">Files are kept in <code>wp-content/uploads/mq-portal/</code>, which can’t be opened by a direct link; staff open them through the portal. Unfinished order uploads are removed after a day by the daily job. Hostinger backups include this folder.</p></div>';

		MQP_Archive::card();

		echo '<div class="mqp-card"><h2>Put the forms on the website</h2><p>Every shortcode and button link, with what it does and its options: <a href="' . esc_url( MQP_Shortcodes_Admin::url() ) . '">MQ Portal → Shortcodes</a>.</p></div>';

		echo '</div></div>';
	}

	/* Tools */

	private static function tab_tools() {
		global $wpdb;
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$parts  = function () {
			$out = '';
			foreach ( self::CONFIG_PARTS as $key => $label ) {
				$out .= '<label class="mqp-part"><input type="checkbox" name="parts[]" value="' . esc_attr( $key ) . '"' . ( 'system' === $key ? '' : ' checked' ) . '> ' . esc_html( $label ) . '</label>';
			}
			return $out;
		};

		echo '<div class="mqp-grid"><div>';

		echo '<div class="mqp-card"><h2>Move the setup between sites</h2><p>Export the setup from one site (for example staging) and import it on another (live), instead of using Hostinger’s “Publish to live”, which would overwrite real data. Staff, invoices, job orders, attendance and sales are never included.</p>';
		echo '<h3>Export</h3><form method="post" action="' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'mqp_system' );
		echo '<input type="hidden" name="action" value="mqp_system"><input type="hidden" name="op" value="export_config"><input type="hidden" name="tab" value="tools">';
		echo '<div class="mqp-parts">' . $parts() . '</div><p><button class="button">Download setup file</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h3>Import</h3><form method="post" enctype="multipart/form-data" action="' . $action . '" onsubmit="return confirm(\'Replace these parts of the setup on this site?\')">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'mqp_system' );
		echo '<input type="hidden" name="action" value="mqp_system"><input type="hidden" name="op" value="import_config"><input type="hidden" name="tab" value="tools">';
		echo '<p><input type="file" name="config" accept=".json,application/json" required></p><div class="mqp-parts">' . $parts() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Brands, shops and products are matched by their number (ID): matching ones are updated, new ones are added, nothing is deleted. Secret keys (Turnstile) are never exported.</p>';
		echo '<p><button class="button button-primary">Import</button></p></form></div>';


		echo '</div><div>';

		$hide  = $wpdb->suppress_errors( true );
		$sizes = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s', DB_NAME, $wpdb->esc_like( $wpdb->prefix . 'mq_' ) . '%' ), ARRAY_A ) as $r ) {
			if ( (int) $r['b'] > 0 ) {
				$sizes[ $r['t'] ] = (int) $r['b'];
			}
		}
		echo '<div class="mqp-card"><h2>Database</h2><table class="widefat striped mqp-db"><thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead><tbody>';
		$total_rows = 0;
		foreach ( MQP_Monitor::TABLES as $t ) {
			$name        = mqp_table( $t );
			$rows        = $wpdb->get_var( "SELECT COUNT(*) FROM `$name`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$total_rows += (int) $rows;
			echo '<tr><td><code>' . esc_html( $name ) . '</code></td><td>' . ( null === $rows ? '<span class="mqp-pill is-bad">Missing</span>' : esc_html( number_format_i18n( (int) $rows ) ) ) . '</td><td>' . esc_html( isset( $sizes[ $name ] ) ? size_format( $sizes[ $name ] ) : '—' ) . '</td></tr>';
		}
		$wpdb->suppress_errors( $hide );
		echo '</tbody><tfoot><tr><th>Total</th><th>' . esc_html( number_format_i18n( $total_rows ) ) . '</th><th>' . esc_html( $sizes ? size_format( array_sum( $sizes ) ) : '—' ) . '</th></tr></tfoot></table>';
		echo '<p class="description">Back up the database in Hostinger → Websites → Backups before big changes.</p></div>';
		MQP_Reset::card();

		echo '</div></div>';
	}

	private static function export_config() {
		global $wpdb;
		$parts = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ), array_keys( self::CONFIG_PARTS ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle().
		$data  = array( 'plugin' => 'mq-portal', 'version' => MQP_VERSION, 'exported' => wp_date( 'c' ), 'site' => home_url() );
		foreach ( $parts as $part ) {
			if ( 'settings' === $part ) {
				$data['settings'] = MQP_Settings::all();
				unset( $data['settings']['turnstile_secret'] );
			} elseif ( 'order_config' === $part ) {
				$data['order_config'] = MQP_Order::config();
			} elseif ( 'system' === $part ) {
				$data['system'] = array_diff_key( MQP_Monitor::settings(), array( 'delete_data' => 1, 'maintenance' => 1 ) );
			} else {
				$data[ $part ] = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( $part ) . ' ORDER BY id', ARRAY_A );
			}
		}
		MQP_Monitor::log( 'system', 'Exported the setup (' . implode( ', ', $parts ) . ')' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mq-portal-setup-' . wp_date( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/** Returns the notice code for handle(). */
	private static function import_config() {
		global $wpdb;
		$parts = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ), array_keys( self::CONFIG_PARTS ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle().
		$file  = $_FILES['config'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $parts || ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return 'import_nothing';
		}
		$data = $file['size'] < 2 * MB_IN_BYTES ? json_decode( (string) file_get_contents( $file['tmp_name'] ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || 'mq-portal' !== ( $data['plugin'] ?? '' ) ) {
			return 'import_bad_file';
		}
		$done = array();
		foreach ( $parts as $part ) {
			if ( ! isset( $data[ $part ] ) || ! is_array( $data[ $part ] ) ) {
				continue;
			}
			if ( 'settings' === $part ) {
				unset( $data['settings']['turnstile_secret'] );
				MQP_Settings::update( $data['settings'] );
			} elseif ( 'order_config' === $part ) {
				MQP_Order::save_config( $data['order_config'] );
			} elseif ( 'system' === $part ) {
				$keep = array_intersect_key( MQP_Monitor::settings(), array( 'delete_data' => 1, 'maintenance' => 1 ) );
				MQP_Monitor::save_settings( array_merge( MQP_Monitor::settings(), $data['system'], $keep ) );
			} else {
				$table   = mqp_table( $part );
				$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				foreach ( $data[ $part ] as $row ) {
					if ( is_array( $row ) && ! empty( $row['id'] ) ) {
						$row = array_map(
							function ( $v ) {
								return is_scalar( $v ) || null === $v ? $v : wp_json_encode( $v );
							},
							array_intersect_key( $row, array_flip( $columns ) )
						);
						$wpdb->replace( $table, $row );
					}
				}
			}
			$done[] = $part;
		}
		if ( ! $done ) {
			return 'import_nothing';
		}
		MQP_Monitor::log( 'system', 'Imported the setup (' . implode( ', ', $done ) . ') from ' . sanitize_text_field( (string) ( $data['site'] ?? 'a file' ) ) );
		return 'import_config';
	}

	/* ---------- Dashboard widget ---------- */

	public static function dashboard_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'mqp_status', 'MQ Portal status', array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget() {
		$checks = MQP_Monitor::checks();
		$state  = MQP_Monitor::overall( $checks );
		$unread = MQP_Monitor::unread_count();
		$text   = array( 'ok' => 'Everything is working.', 'warn' => 'Working, with things to look at.', 'bad' => 'Something needs fixing.' )[ $state ];
		echo '<p>' . self::pill( $state ) . ' ' . esc_html( $text . ( $unread ? " $unread new problem(s)." : '' ) ) . ( MQP_Monitor::settings()['maintenance'] ? ' <span class="mqp-pill is-bad">Maintenance on</span>' : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><a class="button button-primary" href="' . esc_url( MQP_Dev_Admin::url() ) . '">Open the MQ Portal dashboard</a></p>';
		echo '<style>' . self::pill_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ---------- Styles ---------- */

	private static function pill_css() {
		return '.mqp-pill{display:inline-block;min-width:54px;text-align:center;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;line-height:18px;text-transform:uppercase;letter-spacing:.03em}'
			. '.mqp-pill.is-ok{background:#edfaef;color:#00701a}.mqp-pill.is-warn{background:#fcf9e8;color:#7a5b00}.mqp-pill.is-bad{background:#fcf0f1;color:#a00}';
	}

	public static function styles() {
		echo '<style>' . self::pill_css() . '
.mqp-sys .mqp-tab{margin-top:20px}
.mqp-sys .mqp-intro{max-width:780px;color:#50575e;margin:0 0 16px}
.mqp-sys .mqp-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;margin:0 0 20px}
.mqp-sys .mqp-card h2{margin:0 0 12px;font-size:15px}
.mqp-sys .mqp-card h3{margin:20px 0 8px;font-size:13px}
.mqp-sys .mqp-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:20px;align-items:start}
.mqp-sys .mqp-grid>div>.mqp-card:last-child{margin-bottom:0}
.mqp-sys .mqp-status{display:flex;gap:14px;align-items:center;padding:16px 20px;border:1px solid #dcdcde;border-left-width:4px;border-radius:4px;background:#fff;margin:0 0 20px}
.mqp-sys .mqp-status strong{font-size:16px}.mqp-sys .mqp-status p{margin:2px 0 0;color:#50575e}
.mqp-sys .mqp-status .dashicons{font-size:32px;width:32px;height:32px}
.mqp-sys .mqp-status.is-ok{border-left-color:#00a32a}.mqp-sys .mqp-status.is-ok .dashicons{color:#00a32a}
.mqp-sys .mqp-status.is-warn{border-left-color:#dba617}.mqp-sys .mqp-status.is-warn .dashicons{color:#dba617}
.mqp-sys .mqp-status.is-bad{border-left-color:#d63638}.mqp-sys .mqp-status.is-bad .dashicons{color:#d63638}
.mqp-sys .mqp-checks th{width:30%;font-weight:600}.mqp-sys .mqp-checks td,.mqp-sys .mqp-checks th{padding:10px;vertical-align:top}
.mqp-sys .mqp-c-pill{width:70px}
.mqp-sys .mqp-actions{margin:14px 0 4px}
.mqp-sys .mqp-facts{width:100%;border-collapse:collapse}
.mqp-sys .mqp-facts th,.mqp-sys .mqp-facts td{text-align:left;padding:7px 0;border-bottom:1px solid #f0f0f1;vertical-align:top;font-weight:400}
.mqp-sys .mqp-facts th{color:#50575e;width:48%;padding-right:12px}.mqp-sys .mqp-facts td{word-break:break-word}
.mqp-sys .mqp-facts tr:last-child th,.mqp-sys .mqp-facts tr:last-child td{border-bottom:0}
.mqp-sys .mqp-count{display:inline-block;background:#d63638;color:#fff;border-radius:9px;font-size:11px;line-height:17px;min-width:17px;padding:0 5px;text-align:center;margin-left:4px}
.mqp-sys .mqp-empty{text-align:center;padding:32px}.mqp-sys .mqp-empty .dashicons{color:#00a32a;font-size:36px;width:36px;height:36px}
.mqp-sys .mqp-errors tr.is-new td{background:#fffdf3}
.mqp-sys .mqp-new{background:#d63638;color:#fff;font-size:10px;border-radius:3px;padding:1px 5px;font-weight:600}
.mqp-sys .mqp-errors pre{white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:10px;max-height:260px;overflow:auto;font-size:12px}
.mqp-sys .mqp-errors summary{cursor:pointer;color:#2271b1;margin-top:4px}
.mqp-sys .mqp-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 14px}
.mqp-sys .mqp-filters label{display:inline-flex;gap:6px;align-items:center}
.mqp-sys .mqp-activity td{vertical-align:top}
.mqp-sys .mqp-list{margin:0 0 12px}
.mqp-sys .mqp-grid-even{grid-template-columns:1fr 1fr}
.mqp-sys .mqp-settings .mqp-card{max-width:980px}
.mqp-sys .mqp-settings .form-table{margin:0}.mqp-sys .mqp-settings .form-table th{width:220px;padding:14px 10px 14px 0}.mqp-sys .mqp-settings .form-table td{padding:12px 10px}
.mqp-sys .mqp-card.is-maint{border-left:4px solid #d63638}
.mqp-sys .mqp-danger{border-color:#f0c4c4}.mqp-sys .mqp-danger h2{color:#a00}
.mqp-sys .mqp-parts{display:grid;gap:6px;margin:8px 0}
.mqp-sys .mqp-version{border-top:1px solid #f0f0f1;padding-top:6px}.mqp-sys .mqp-version h3{margin:10px 0 6px}.mqp-sys .mqp-version ul{list-style:disc;margin:0 0 8px 18px}
.mqp-sys .mqp-db td,.mqp-sys .mqp-db th{padding:6px 10px}
.mqp-sys .mqp-uploads td{vertical-align:top}
.mqp-sys .mqp-card.is-live{border-left:4px solid #00a32a}
.mqp-sys .mqp-steps{margin:8px 0 14px 18px;max-width:760px}.mqp-sys .mqp-steps li{margin-bottom:6px}
.mqp-sys .mqp-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.mqp-sys .mqp-photo-pick{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.mqp-sys .mqp-photo-pick img{width:180px;height:110px;object-fit:cover;border-radius:6px;border:1px solid #dcdcde;background:#f6f7f7}
@media (max-width:1100px){.mqp-sys .mqp-grid,.mqp-sys .mqp-grid-even{grid-template-columns:1fr}}
</style>';
	}
}
