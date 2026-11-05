<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → System → Tools → "Start fresh": removes test data (made while trying the
 * system on the live site) and keeps the setup: settings, brands, shops, POS products, order
 * prices, staff accounts and their access rights. Test staff accounts ("mock accounts") can be
 * removed too, each one ticked by hand (never the WordPress administrators or yourself).
 *
 * WordPress administrators only, with "DELETE" typed to confirm. It can't be undone, so the card
 * asks for a Hostinger backup first. One line stays in the activity log saying what was removed.
 */
class MQP_Reset {

	const ACTION = 'mqp_reset';

	/** What can be removed: key => [label, tables]. */
	public static function parts() {
		return array(
			'sales'      => array( 'Quotations, billing statements, invoices, payments, job orders, commissions and online orders (numbering starts again at 1)', array( 'documents', 'document_items', 'payments', 'jobs', 'job_events', 'commissions', 'orders' ) ),
			'customers'  => array( 'Customers list', array( 'customers' ) ),
			'uploads'    => array( 'Customer uploads and every stored file (job files, payment screenshots, archives)', array( 'uploads', 'files', 'archives' ) ),
			'pos'        => array( 'POS sales (receipts) and printer counters', array( 'pos_sales', 'pos_sale_items', 'printer_readings' ) ),
			'attendance' => array( 'Attendance (clock-ins and breaks)', array( 'attendance', 'attendance_breaks' ) ),
			'reviews'    => array( 'Reviews (website, added by hand and Google) and their pictures', array( 'reviews' ) ),
			'signups'    => array( 'Account requests', array( 'signups' ) ),
			'logs'       => array( 'Notifications, activity log and problems', array( 'notifications', 'audit_log', 'errors' ) ),
		);
	}

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function handle() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only a WordPress administrator can do this.', 403 );
		}
		check_admin_referer( self::ACTION );
		$back = admin_url( 'admin.php?page=mq-portal-system&tab=tools' ) . '#reset';
		if ( 'DELETE' !== trim( (string) wp_unslash( $_POST['confirm'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'reset', 'confirm', $back ) );
			exit;
		}
		$chosen = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ), array_keys( self::parts() ) );
		$staff  = array_filter( array_map( 'intval', (array) ( $_POST['staff'] ?? array() ) ) );
		if ( ! $chosen && ! $staff ) {
			wp_safe_redirect( add_query_arg( 'reset', 'none', $back ) );
			exit;
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$done  = array();
		$parts = self::parts();
		foreach ( $chosen as $key ) {
			if ( 'reviews' === $key ) {
				foreach ( (array) $wpdb->get_col( 'SELECT photo FROM ' . mqp_table( 'reviews' ) . " WHERE photo <> ''" ) as $photo ) { // phpcs:ignore WordPress.DB.PreparedSQL
					MQP_Reviews::delete_photo( $photo );
				}
				MQP_Reviews::save_options( array( 'featured' => array(), 'feat_id' => 0, 'feat_week' => '', 'google_rating' => 0, 'google_count' => 0, 'google_synced' => 0 ) );
			}
			if ( 'uploads' === $key ) {
				self::delete_files();
			}
			$n = 0;
			foreach ( $parts[ $key ][1] as $t ) {
				$n += (int) $wpdb->query( 'DELETE FROM ' . mqp_table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			if ( 'sales' === $key ) {
				$wpdb->query( 'DELETE FROM ' . mqp_table( 'counters' ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- invoice, quotation and job numbers start again at 1.
				delete_option( 'mqp_quote_reminded' );
			}
			if ( 'logs' === $key ) {
				delete_option( 'mqp_flags_checked' );
			}
			$done[] = $key . ' (' . $n . ')';
		}
		// Test staff accounts, removed for good (their name stays on anything they did that is kept).
		$gone = array();
		foreach ( $staff as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u || ! self::removable( $u ) ) {
				continue;
			}
			$gone[] = $u->user_login;
			MQP_REST_Admin::remove_for_good( $u );
		}
		if ( $gone ) {
			$done[] = 'staff accounts (' . implode( ', ', $gone ) . ')';
		}
		// Rate limits and cached counts from the tests.
		$opt = $wpdb->options;
		$wpdb->query( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_%mqp\\_rl\\_%' OR option_name LIKE '\\_transient\\_%mqp\\_up\\_%' OR option_name LIKE '\\_transient\\_%mqp\\_fail\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		delete_transient( 'mqp_storage_used' );
		delete_option( MQP_Guard::DAY_OPT );
		mqp_purge_page_cache();
		MQP_Monitor::log( 'data_reset', 'Started fresh: removed ' . implode( ', ', $done ) );
		wp_safe_redirect( add_query_arg( 'reset', 'done', $back ) );
		exit;
	}

	/** Portal staff accounts Start fresh may remove: not WordPress administrators, not yourself. */
	private static function removable( WP_User $u ) {
		return (int) $u->ID !== get_current_user_id()
			&& ! in_array( 'administrator', (array) $u->roles, true )
			&& isset( MQP_Roles::roles()[ MQP_Roles::user_role( $u ) ] );
	}

	/** Every stored portal file except profile photos and the folder's protection files. */
	private static function delete_files() {
		$base = MQP_Files::base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $base ) + 1 ) );
			if ( 0 === strpos( $rel, 'avatars' ) || in_array( $rel, array( '.htaccess', 'index.php' ), true ) ) {
				continue;
			}
			// Files only: the (empty) folders stay, so the next upload can save straight away.
			if ( ! $f->isDir() ) {
				wp_delete_file( $f->getPathname() );
			}
		}
	}

	/** The card on System → Tools. */
	public static function card() {
		global $wpdb;
		$msg  = isset( $_GET['reset'] ) ? sanitize_key( wp_unslash( $_GET['reset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$text = array(
			'done'    => array( 'success', 'Done: the chosen data is removed. Settings, shops, products and staff stay as they were.' ),
			'confirm' => array( 'error', 'Nothing was removed: type DELETE (in capitals) in the box to confirm.' ),
			'none'    => array( 'error', 'Nothing was removed: tick at least one kind of data or account.' ),
		);
		echo '<div class="mqp-card mqp-danger" id="reset"><h2>Start fresh (remove test data)</h2>';
		if ( isset( $text[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $text[ $msg ][0] ) . ' inline"><p>' . esc_html( $text[ $msg ][1] ) . '</p></div>';
		}
		echo '<p>For when you tried the system on the live site and want to start for real. <strong>This cannot be undone.</strong> Take a backup first (Hostinger → Websites → Backups).</p>';
		echo '<p><strong>Always kept:</strong> portal settings, company details, brands, shops (with Wi-Fi and GPS), POS products, online order prices, and these System settings. Staff accounts are kept unless you tick them below.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Remove the ticked data for good?\')">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><div class="mqp-parts">';
		$hide = $wpdb->suppress_errors( true );
		foreach ( self::parts() as $key => $p ) {
			$n = 0;
			foreach ( $p[1] as $t ) {
				$n += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			// Reviews are often real ones (from Google or added by hand): tick them only on purpose.
			$tick = 'reviews' === $key ? '' : ' checked';
			echo '<label class="mqp-part"><input type="checkbox" name="parts[]" value="' . esc_attr( $key ) . '"' . $tick . '> ' . esc_html( $p[0] ) . ( 'reviews' === $key ? ' <strong>— not ticked: real reviews would be lost</strong>' : '' ) . ' <span class="description">(' . esc_html( number_format_i18n( $n ) ) . ')</span></label>';
		}
		$wpdb->suppress_errors( $hide );
		echo '</div>';
		// Test ("mock") staff accounts: listed one by one, none ticked.
		$people = array_filter( get_users( array( 'role__in' => array_keys( MQP_Roles::roles() ), 'orderby' => 'display_name' ) ), array( __CLASS__, 'removable' ) );
		echo '<h3 class="mqp-reset-sub">Test staff accounts</h3>';
		if ( $people ) {
			echo '<p class="description">Tick only the accounts made for testing. They are removed for good (no 30-day restore), and their name stays on anything they did that you keep. The WordPress administrators and your own account are never listed.</p><div class="mqp-parts mqp-reset-staff">';
			foreach ( $people as $u ) {
				$role    = MQP_Roles::roles()[ MQP_Roles::user_role( $u ) ]['name'];
				$deleted = (int) get_user_meta( $u->ID, 'mqp_deleted_at', true );
				echo '<label class="mqp-part"><input type="checkbox" name="staff[]" value="' . (int) $u->ID . '"> <strong>' . esc_html( $u->display_name ) . '</strong> <span class="description">' . esc_html( $u->user_login . ' · ' . $role . ( $deleted ? ' · already deleted' : '' ) ) . '</span></label>';
			}
			echo '</div>';
		} else {
			echo '<p class="description">No staff accounts other than the administrators.</p>';
		}
		echo '<p><label>Type <code>DELETE</code> to confirm: <input type="text" name="confirm" autocomplete="off" class="regular-text" style="max-width:160px"></label></p>';
		echo '<p><button class="button button-link-delete">Remove the ticked data</button></p></form></div>';
	}
}
