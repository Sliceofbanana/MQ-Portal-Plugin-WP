<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → Dashboard: the one page for the system admin.
 * Health (every check), what is waiting (website requests, uploads, reviews, POS sales to check),
 * this week, storage and archives, backups, background jobs, recent problems and activity, what's
 * new, this site's updates, data counts, an environment report and every quick action.
 * (Before 1.18.1 this was split over Dashboard, System → Overview and Website.)
 */
class MQP_Dev_Admin {

	const SLUG    = 'mq-portal-dashboard';
	const LOG     = 'mqp_version_log';
	const CHECKED = 'mqp_flags_checked'; // Activity-log ids of POS price flags an admin has checked.

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 ); // After System's "Open portal" item (else WordPress adds a stray "MQ Portal" link); position 0 still puts it first.
		add_action( 'admin_init', array( __CLASS__, 'note_version' ) );
		add_action( 'admin_post_mqp_dev', array( __CLASS__, 'handle' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( $op, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mqp_dev', 'op' => $op ), $args ), admin_url( 'admin-post.php' ) ), 'mqp_dev' );
	}

	public static function menu() {
		global $menu;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hook = add_submenu_page( 'mq-portal', 'MQ Portal dashboard', 'Dashboard', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 0 );
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
		// One count on the "MQ Portal" menu: everything waiting plus new problems.
		$w     = self::waiting();
		$total = $w['pricing'] + $w['uploads'] + $w['reviews'] + $w['flags'] + MQP_Monitor::unread_count();
		if ( $total ) {
			foreach ( (array) $menu as $i => $item ) {
				if ( isset( $item[2] ) && 'mq-portal' === $item[2] ) {
					$menu[ $i ][0] .= ' <span class="awaiting-mod count-' . (int) $total . '"><span class="pending-count">' . (int) $total . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				}
			}
		}
	}

	/** Remembers each new version installed on this site (from → to, when). */
	public static function note_version() {
		$log  = (array) get_option( self::LOG, array() );
		$last = $log ? end( $log ) : null;
		if ( $last && MQP_VERSION === $last['to'] ) {
			return;
		}
		$log[] = array( 'from' => $last ? $last['to'] : '', 'to' => MQP_VERSION, 'at' => time(), 'by' => wp_get_current_user()->user_login );
		update_option( self::LOG, array_slice( $log, -40 ), false );
	}

	/** Things waiting: website requests to price, new uploads, reviews to approve, POS sales to check. */
	public static function waiting() {
		global $wpdb;
		$hide = $wpdb->suppress_errors( true );
		$p    = MQP_Quote::pricing_counts();
		$out  = array(
			'pricing' => (int) $p['waiting'],
			'late'    => (int) $p['late'],
			'uploads' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) . " WHERE status = 'new'" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'reviews' => (int) MQP_Reviews::pending_count(),
			'flags'   => count( self::flags() ),
		);
		$wpdb->suppress_errors( $hide );
		return $out;
	}

	/** POS sales flagged in the last 30 days (custom/changed price, old date, other shop) not checked yet. */
	public static function flags() {
		global $wpdb;
		$done = array_map( 'intval', (array) get_option( self::CHECKED, array() ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, username, reason, created_at FROM ' . mqp_table( 'audit_log' ) . " WHERE event = 'pos_price_flag' AND created_at >= %s ORDER BY id DESC LIMIT 100", wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), ARRAY_A );
		return array_values( array_filter( $rows, function ( $r ) use ( $done ) {
			return ! in_array( (int) $r['id'], $done, true );
		} ) );
	}

	/** Last time a copy of the data left the site: an archive downloaded / on Drive, or the setup exported. */
	public static function backups() {
		global $wpdb;
		$hide    = $wpdb->suppress_errors( true );
		$archive = (string) $wpdb->get_var( 'SELECT GREATEST(COALESCE(MAX(downloaded_at), \'1970-01-01\'), COALESCE(MAX(drive_at), \'1970-01-01\')) FROM ' . mqp_table( 'archives' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( $hide );
		$log     = (array) get_option( self::LOG, array() );
		return array(
			'archive' => $archive && '1970-01-01' !== substr( $archive, 0, 10 ) ? strtotime( $archive ) : 0,
			'export'  => (int) get_option( 'mqp_last_export' ),
			'since'   => $log ? (int) $log[0]['at'] : time(),
		);
	}

	/* ---------- Actions ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only administrators can do this.', 403 );
		}
		check_admin_referer( 'mqp_dev' );
		global $wpdb;
		$op   = sanitize_key( (string) ( $_GET['op'] ?? '' ) );
		$done = $op;
		switch ( $op ) {
			case 'recheck':
				delete_transient( 'mqp_loopback' );
				delete_transient( 'mqp_storage_used' );
				delete_transient( 'mqp_domain_live' );
				break;
			case 'daily':
				do_action( 'mqp_daily' );
				MQP_Monitor::log( 'system', 'Ran the daily job by hand' );
				break;
			case 'hourly':
				do_action( 'mqp_hourly' );
				MQP_Monitor::log( 'system', 'Ran the hourly job by hand' );
				break;
			case 'purge':
				mqp_purge_page_cache();
				MQP_Monitor::log( 'system', 'Cleared the website page cache' );
				break;
			case 'limits':
				$opt = $wpdb->options;
				$wpdb->query( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_%mqp\\_rl\\_%' OR option_name LIKE '\\_transient\\_%mqp\\_up\\_%' OR option_name LIKE '\\_transient\\_%mqp\\_rv\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
				MQP_Monitor::log( 'system', 'Cleared the spam limits (orders, uploads, quotations, reviews)' );
				break;
			case 'setup':
				MQP_Install::activate();
				delete_transient( 'mqp_loopback' );
				MQP_Monitor::log( 'system', 'Ran setup again' );
				break;
			case 'seen':
				update_user_meta( get_current_user_id(), 'mqp_seen_version', MQP_VERSION );
				break;
			case 'flag_ok':
				// A flagged POS sale was looked at: it leaves the list (the activity log keeps it).
				$ids  = array_filter( array_map( 'intval', explode( ',', (string) ( $_GET['ids'] ?? '' ) ) ) );
				$have = array_map( 'intval', (array) get_option( self::CHECKED, array() ) );
				update_option( self::CHECKED, array_slice( array_values( array_unique( array_merge( $have, $ids ) ) ), -1000 ), false );
				MQP_Monitor::log( 'pos_flag_checked', count( $ids ) . ' flagged POS sale(s) checked' );
				break;
			default:
				$done = '';
		}
		wp_safe_redirect( self::url( $done ? array( 'done' => $done ) : array() ) );
		exit;
	}

	/* ---------- Page ---------- */

	public static function render() {
		global $wpdb;
		$log      = include MQP_DIR . 'includes/changelog.php';
		$seen     = (string) get_user_meta( get_current_user_id(), 'mqp_seen_version', true );
		$checks   = MQP_Monitor::checks();
		$overall  = MQP_Monitor::overall( $checks );
		$bad      = array_filter( $checks, function ( $c ) {
			return 'ok' !== $c[1];
		} );
		$released = isset( $log[ MQP_VERSION ] ) ? $log[ MQP_VERSION ]['date'] : '';
		$history  = array_reverse( (array) get_option( self::LOG, array() ) );
		$portal   = MQP_Portal::url();
		$w        = self::waiting();

		echo '<div class="wrap mqp-sys mqp-dev"><h1 class="wp-heading-inline">MQ Portal dashboard</h1> <a class="page-title-action" href="' . esc_url( $portal ) . '" target="_blank" rel="noopener">Open portal ↗</a><hr class="wp-header-end">';
		$msgs = array(
			'recheck' => 'Checked again.',
			'daily'   => 'The daily job ran.',
			'hourly'  => 'The hourly job ran (quotation reminders, forgotten shifts).',
			'purge'   => 'The website page cache is cleared.',
			'limits'  => 'Spam limits cleared: everyone can send orders, uploads, quotations and reviews again.',
			'setup'   => 'Setup ran again: tables, roles, links, folders and scheduled jobs are refreshed.',
			'flag_ok' => 'Marked as checked.',
		);
		$done = sanitize_key( (string) ( $_GET['done'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $msgs[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $done ] ) . '</p></div>';
		}

		// Top: health and what is waiting.
		$problems = MQP_Monitor::unread_count();
		$tile     = function ( $label, $value, $sub, $link = '', $tone = '', $blank = false ) {
			$inner = '<span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong><small>' . esc_html( $sub ) . '</small>';
			return $link
				? '<a class="mqp-dev-tile' . ( $tone ? ' is-' . $tone : '' ) . '" href="' . esc_url( $link ) . '"' . ( $blank ? ' target="_blank" rel="noopener"' : '' ) . '>' . $inner . '</a>'
				: '<div class="mqp-dev-tile' . ( $tone ? ' is-' . $tone : '' ) . '">' . $inner . '</div>';
		};
		echo '<div class="mqp-dev-tiles">';
		echo $tile( 'Health', array( 'ok' => 'All good', 'warn' => count( $bad ) . ' to look at', 'bad' => 'Needs fixing' )[ $overall ], count( $checks ) . ' checks · see below', '#health', $overall ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'New problems', $problems, 'Errors and failed emails', MQP_System::url( 'errors' ), $problems ? 'bad' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Quotations to price', $w['pricing'], $w['late'] ? $w['late'] . ' past 20 hours: reply soon' : 'Promised a reply within 24 hours', $portal . '#/sales/docs', $w['late'] ? 'bad' : ( $w['pricing'] ? 'warn' : '' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'New customer uploads', $w['uploads'], 'Not turned into a quotation yet', $portal . '#/sales/uploads', $w['uploads'] ? 'warn' : '', true ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Reviews to approve', $w['reviews'], 'Sent with "Leave a Review"', admin_url( 'admin.php?page=' . MQP_Reviews_Admin::SLUG . '&status=pending' ), $w['reviews'] ? 'warn' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'POS sales to check', $w['flags'], 'Custom price, old date or other shop', '#flags', $w['flags'] ? 'warn' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		echo '<div class="mqp-grid"><div>';

		// Health: every check, with its fix.
		echo '<div class="mqp-card" id="health"><div class="mqp-dev-head"><h2>Health</h2><span>' . '<a class="button button-small" href="' . esc_url( self::action_url( 'recheck' ) ) . '">Check again</a></span></div>';
		echo '<table class="widefat striped mqp-checks"><tbody>';
		foreach ( $checks as $c ) {
			echo '<tr><td class="mqp-c-pill"><span class="mqp-pill is-' . esc_attr( $c[1] ) . '">' . esc_html( array( 'ok' => 'OK', 'warn' => 'Check', 'bad' => 'Problem' )[ $c[1] ] ?? $c[1] ) . '</span></td><th scope="row">' . esc_html( $c[0] ) . '</th><td>' . esc_html( $c[2] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		// POS sales flagged for an admin.
		$flags = self::flags();
		echo '<div class="mqp-card" id="flags"><div class="mqp-dev-head"><h2>POS sales to check</h2>' . ( $flags ? '<a class="button button-small" href="' . esc_url( self::action_url( 'flag_ok', array( 'ids' => implode( ',', wp_list_pluck( $flags, 'id' ) ) ) ) ) . '">Mark all as checked</a>' : '' ) . '</div>';
		echo '<p class="description">Sales by staff without the "custom prices" permission that had a custom or changed price, an old date or another shop (last 30 days). The sale is kept; check it with the cashier.</p>';
		if ( $flags ) {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
			foreach ( array_slice( $flags, 0, 20 ) as $f ) {
				echo '<tr><td>' . esc_html( mysql2date( 'M j, g:i A', $f['created_at'] ) ) . '</td><td>' . esc_html( $f['username'] ) . '</td><td>' . esc_html( $f['reason'] ) . '</td><td><a href="' . esc_url( self::action_url( 'flag_ok', array( 'ids' => (int) $f['id'] ) ) ) . '">Checked</a></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nothing to check.</p>';
		}
		echo '</div>';

		// This week on the website, and the latest requests and uploads.
		$d      = mqp_table( 'documents' );
		$monday = wp_date( 'Y-m-d 00:00:00', strtotime( 'monday this week', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$hide   = $wpdb->suppress_errors( true );
		$week   = array(
			'Quotation requests' => array( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $d WHERE type = 'quotation' AND source = 'website' AND created_at >= %s", $monday ) ), $portal . '#/sales/docs' ), // phpcs:ignore WordPress.DB.PreparedSQL
			'Accepted online'    => array( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $d WHERE type = 'invoice' AND source = 'website' AND created_at >= %s", $monday ) ), $portal . '#/sales/jobs' ), // phpcs:ignore WordPress.DB.PreparedSQL
			'Customer uploads'   => array( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) . ' WHERE created_at >= %s', $monday ) ), $portal . '#/sales/uploads' ),
			'Online orders'      => array( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) . ' WHERE created_at >= %s', $monday ) ), $portal . '#/sales/orders' ),
			'New reviews'        => array( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mqp_table( 'reviews' ) . " WHERE source = 'website' AND created_at >= %s", $monday ) ), admin_url( 'admin.php?page=' . MQP_Reviews_Admin::SLUG ) ),
		);
		$quotes = $wpdb->get_results( "SELECT id, number, status, customer_name, total, created_at FROM $d WHERE type = 'quotation' AND source = 'website' ORDER BY id DESC LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ups    = $wpdb->get_results( 'SELECT u.id, u.name, u.status, u.created_at, (SELECT COUNT(*) FROM ' . mqp_table( 'files' ) . ' f WHERE f.upload_id = u.id) AS files FROM ' . mqp_table( 'uploads' ) . " u WHERE u.notes <> 'Files sent with a quotation request.' ORDER BY u.id DESC LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( $hide );
		echo '<div class="mqp-card"><h2>This week on the website <span class="description">(since Monday)</span></h2><table class="mqp-facts">';
		foreach ( $week as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td><a href="' . esc_url( $v[1] ) . '"' . ( 0 === strpos( $v[1], $portal ) ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( number_format_i18n( $v[0] ) ) . '</a></td></tr>';
		}
		echo '</table>';
		if ( $quotes ) {
			$st = array( 'pricing' => array( 'Needs pricing', 'warn' ), 'open' => array( 'Sent', 'ok' ), 'converted' => array( 'Accepted', 'ok' ), 'void' => array( 'Void', 'bad' ) );
			echo '<h3>Latest quotation requests</h3><table class="widefat striped"><tbody>';
			foreach ( $quotes as $r ) {
				$s = $st[ $r['status'] ] ?? array( ucfirst( $r['status'] ), '' );
				echo '<tr><td><a href="' . esc_url( $portal . '#/sales/docs/' . (int) $r['id'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $r['number'] ) . '</strong></a></td><td>' . esc_html( $r['customer_name'] ) . '</td><td>' . esc_html( mysql2date( 'M j, g:i A', $r['created_at'] ) ) . '</td><td><span class="mqp-pill is-' . esc_attr( $s[1] ) . '">' . esc_html( $s[0] ) . '</span></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $ups ) {
			echo '<h3>Latest customer uploads</h3><table class="widefat striped"><tbody>';
			foreach ( $ups as $u ) {
				echo '<tr><td>' . esc_html( sprintf( 'U-%05d', $u['id'] ) ) . '</td><td>' . esc_html( $u['name'] ) . '</td><td>' . esc_html( mysql2date( 'M j, g:i A', $u['created_at'] ) ) . '</td><td>' . (int) $u['files'] . ' file(s) · ' . esc_html( $u['status'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// Recent problems and activity.
		$errs = $wpdb->get_results( 'SELECT kind, message, hits, seen, last_seen FROM ' . mqp_table( 'errors' ) . ' ORDER BY last_seen DESC LIMIT 6', ARRAY_A );
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>Recent problems</h2><a href="' . esc_url( MQP_System::url( 'errors' ) ) . '">All problems →</a></div>';
		if ( $errs ) {
			echo '<table class="widefat striped"><thead><tr><th>Last seen</th><th>Type</th><th>Problem</th><th>Times</th></tr></thead><tbody>';
			foreach ( $errs as $e ) {
				echo '<tr' . ( $e['seen'] ? '' : ' class="mqp-dev-unseen"' ) . '><td>' . esc_html( mysql2date( 'M j, g:i A', $e['last_seen'] ) ) . '</td><td>' . esc_html( MQP_Monitor::KINDS[ $e['kind'] ] ?? $e['kind'] ) . '</td><td>' . esc_html( wp_trim_words( $e['message'], 18 ) ) . '</td><td>' . (int) $e['hits'] . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No problems recorded.</p>';
		}
		echo '</div>';

		$labels = array();
		foreach ( MQP_Monitor::events() as $group ) {
			$labels += $group;
		}
		$acts = $wpdb->get_results( 'SELECT username, event, result, reason, created_at FROM ' . mqp_table( 'audit_log' ) . ' ORDER BY id DESC LIMIT 12', ARRAY_A );
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>Recent activity</h2><a href="' . esc_url( MQP_System::url( 'activity' ) ) . '">All activity →</a></div>';
		if ( $acts ) {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>';
			foreach ( $acts as $a ) {
				echo '<tr><td>' . esc_html( mysql2date( 'M j, g:i A', $a['created_at'] ) ) . '</td><td>' . esc_html( $a['username'] ? $a['username'] : '—' ) . '</td><td>' . esc_html( ( $labels[ $a['event'] ] ?? $a['event'] ) . ( $a['reason'] ? ' · ' . wp_trim_words( $a['reason'], 14 ) : '' ) ) . ( 'ok' !== $a['result'] ? ' <span class="mqp-pill is-bad">' . esc_html( $a['result'] ) . '</span>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nothing yet.</p>';
		}
		echo '</div>';

		// What's new.
		$new = array();
		foreach ( $log as $v => $entry ) {
			if ( $seen && version_compare( (string) $v, $seen, '<=' ) ) {
				break;
			}
			$new[] = $v;
		}
		echo '<div class="mqp-card" id="history"><div class="mqp-dev-head"><h2>What\'s new</h2>';
		if ( $seen && $new ) {
			echo '<a class="button button-small" href="' . esc_url( self::action_url( 'seen' ) ) . '">Mark as read</a>';
		}
		echo '</div>';
		echo '<p class="description">' . ( ! $seen ? 'Every version of the plugin, newest first.' : ( $new ? count( $new ) . ' version(s) since you last looked (' . esc_html( $seen ) . ').' : 'You have read everything up to ' . esc_html( $seen ) . '.' ) ) . '</p>';
		echo '<input type="search" class="mqp-dev-filter" placeholder="Search the changes…" aria-label="Search the changes">';
		$i = 0;
		foreach ( $log as $v => $entry ) {
			$is_new  = in_array( $v, $new, true ) && $seen;
			$current = MQP_VERSION === (string) $v;
			echo '<details class="mqp-dev-ver' . ( $is_new ? ' is-new' : '' ) . '"' . ( $i < 2 || $is_new ? ' open' : '' ) . '><summary><strong>' . esc_html( $v ) . '</strong> <span class="description">' . esc_html( $entry['date'] ? mysql2date( 'M j, Y', $entry['date'] ) : '' ) . '</span>' . ( $current ? ' <span class="mqp-pill is-ok">Installed</span>' : '' ) . ( $is_new ? ' <span class="mqp-pill is-warn">New</span>' : '' ) . ' <span class="description">· ' . count( $entry['items'] ) . ' change(s)</span></summary><ul>';
			foreach ( $entry['items'] as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul></details>';
			$i++;
		}
		echo '</div>';
		if ( ! $seen ) {
			update_user_meta( get_current_user_id(), 'mqp_seen_version', MQP_VERSION );
		}

		echo '</div><div>';

		// Quick actions: the only place for them.
		echo '<div class="mqp-card"><h2>Quick actions</h2><div class="mqp-dev-actions">';
		$btn = function ( $op, $label, $help, $confirm = '' ) {
			echo '<div><a class="button" href="' . esc_url( self::action_url( $op ) ) . '"' . ( $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>' . esc_html( $label ) . '</a><small>' . esc_html( $help ) . '</small></div>';
		};
		$btn( 'purge', 'Clear page cache', 'LiteSpeed and other caches: the website shows the latest prices, reviews and forms.' );
		$btn( 'hourly', 'Run hourly job', 'Quotation reminders and forgotten shifts.' );
		$btn( 'daily', 'Run daily job', 'Google reviews, featured review, cleanups, weekly archive.' );
		$btn( 'limits', 'Clear spam limits', 'When a real customer was blocked by the per-hour limits.' );
		$btn( 'setup', 'Run setup again', 'Fixes most "page not found" and missing-table problems. Never deletes data.', 'Run setup again? Nothing is deleted.' );
		echo '</div></div>';

		// Storage, archives and backups.
		$m     = MQP_Monitor::settings();
		$used  = MQP_Guard::storage_used();
		$cap   = (int) $m['storage_max_gb'] * GB_IN_BYTES;
		$pct   = $cap ? min( 100, (int) round( $used / $cap * 100 ) ) : 0;
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( MQP_Files::base_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$hide  = $wpdb->suppress_errors( true );
		$ready = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mqp_table( 'archives' ) . " WHERE status IN ('ready','downloaded')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( $hide );
		$b     = self::backups();
		$last  = max( $b['archive'], $b['export'] );
		$stale = time() - max( $last, $b['since'] ) > 30 * DAY_IN_SECONDS;
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>Storage &amp; backups</h2><a href="' . esc_url( MQP_Archive::page_url() ) . '">Archives →</a></div>';
		echo '<div class="mqp-ws-bar' . ( $pct >= 90 ? ' is-bad' : ( $pct >= 70 ? ' is-warn' : '' ) ) . '"><span style="width:' . (int) $pct . '%"></span></div>';
		echo '<p><strong>' . esc_html( size_format( $used, 1 ) ) . '</strong> of ' . esc_html( size_format( $cap ) ) . ' (' . (int) $pct . '%) · free on the server: ' . esc_html( false !== $free ? size_format( $free, 1 ) : 'unknown' ) . '</p>';
		echo '<table class="mqp-facts">';
		echo '<tr><th>Archives to download</th><td>' . ( $ready ? '<span class="mqp-pill is-warn">' . (int) $ready . '</span> <a href="' . esc_url( MQP_Archive::page_url() ) . '">Download</a>' : 'None' ) . '</td></tr>';
		echo '<tr><th>Ready to archive</th><td>' . esc_html( size_format( MQP_Archive::waiting_bytes() ) ) . ' of finished job orders</td></tr>';
		echo '<tr><th>Google Drive</th><td>' . ( MQP_GDrive::connected() ? '<span class="mqp-pill is-ok">Connected</span>' : '<a href="' . esc_url( MQP_System::url( 'settings' ) . '#gdrive' ) . '">Not connected</a>' ) . '</td></tr>';
		echo '<tr><th>Last copy off the site</th><td>' . ( $stale ? '<span class="mqp-pill is-warn">Check</span> ' : '' ) . esc_html( $last ? human_time_diff( $last ) . ' ago (' . ( $b['archive'] >= $b['export'] ? 'archive' : 'setup export' ) . ')' : 'never' ) . '</td></tr>';
		echo '</table>';
		if ( $stale ) {
			echo '<p class="description">Over 30 days without a copy leaving the site. Download the waiting archives, export the setup (System → Tools), and check that Hostinger backups are on (hPanel → Backups); the plugin can\'t see those.</p>';
		}
		echo '</div>';

		// Right now and background jobs.
		$count  = function ( $sql ) use ( $wpdb ) {
			$hide = $wpdb->suppress_errors( true );
			$n    = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->suppress_errors( $hide );
			return number_format_i18n( $n );
		};
		$server = (int) get_option( 'mqp_server_cron' );
		$cron   = $server && time() - $server < 2 * HOUR_IN_SECONDS;
		echo '<div class="mqp-card"><h2>Right now</h2><table class="mqp-facts">';
		foreach ( array(
			'Job orders in progress'   => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'jobs' ) . " WHERE stage NOT IN ('delivered','cancelled')" ),
			'Online orders to verify'  => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) . " WHERE payment_status = 'submitted'" ),
			'Clocked in'               => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'attendance' ) . ' WHERE time_out IS NULL' ),
			'Account requests waiting' => number_format_i18n( MQP_Signup::pending_count() ),
		) as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		foreach ( array( 'mqp_hourly' => array( 'Hourly job', (int) get_option( 'mqp_last_hourly' ) ), 'mqp_daily' => array( 'Daily job', (int) get_option( 'mqp_last_daily' ) ) ) as $hook => $j ) {
			$next = wp_next_scheduled( $hook );
			echo '<tr><th>' . esc_html( $j[0] ) . '</th><td>last ' . esc_html( $j[1] ? human_time_diff( $j[1] ) . ' ago' : 'not yet' ) . ' · next ' . esc_html( $next ? ( $next > time() ? 'in ' . human_time_diff( $next ) : 'due now' ) : 'not scheduled' ) . '</td></tr>';
		}
		echo '<tr><th>Server cron</th><td>' . ( $cron ? '<span class="mqp-pill is-ok">Working</span> ' . esc_html( human_time_diff( $server ) ) . ' ago' : '<span class="mqp-pill is-warn">Not set up</span> <a href="' . esc_url( MQP_System::url( 'settings' ) ) . '">How to</a>' ) . '</td></tr>';
		echo '</table></div>';

		// Updates on this site.
		echo '<div class="mqp-card"><h2>Updates on this site</h2><p>Installed: <strong>' . esc_html( MQP_VERSION ) . '</strong> ' . esc_html( $released ? '(released ' . mysql2date( 'M j, Y', $released ) . ')' : '' ) . ' · database ' . esc_html( (string) get_option( 'mqp_db_version' ) ) . '</p>';
		if ( $history ) {
			echo '<table class="mqp-facts">';
			foreach ( array_slice( $history, 0, 6 ) as $h ) {
				echo '<tr><th>' . esc_html( ( $h['from'] ? $h['from'] . ' → ' : '' ) . $h['to'] ) . '</th><td>' . esc_html( wp_date( 'M j, Y g:i A', (int) $h['at'] ) . ( $h['by'] ? ' · ' . $h['by'] : '' ) ) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '</div>';

		// Data.
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>Data</h2><a href="' . esc_url( MQP_System::url( 'tools' ) ) . '">Tables →</a></div><table class="mqp-facts">';
		foreach ( array(
			'Documents'        => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'documents' ) ),
			'Job orders'       => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'jobs' ) ),
			'Customers'        => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'customers' ) ),
			'Online orders'    => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'orders' ) ),
			'Customer uploads' => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'uploads' ) ),
			'POS sales'        => $count( 'SELECT COUNT(*) FROM ' . mqp_table( 'pos_sales' ) ),
			'Staff accounts'   => number_format_i18n( count( get_users( array( 'role__in' => array_keys( MQP_Roles::roles() ), 'fields' => 'ID' ) ) ) ),
		) as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</table></div>';

		// Environment report.
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>Environment</h2><button type="button" class="button button-small mqp-dev-copy">Copy report</button></div>';
		echo '<p class="description">Paste this when asking for help: versions, settings and server details (no passwords or keys).</p>';
		echo '<textarea class="mqp-dev-report large-text code" rows="14" readonly>' . esc_textarea( self::environment() ) . '</textarea></div>';

		// Links.
		echo '<div class="mqp-card"><h2>Links</h2><table class="mqp-facts">';
		foreach ( array( 'Portal' => $portal, 'Order page' => MQP_Order::url(), 'Health check' => rest_url( 'mq/v1/health' ) ) as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td><a href="' . esc_url( $v ) . '" target="_blank" rel="noopener"><code>' . esc_html( $v ) . '</code></a></td></tr>';
		}
		echo '</table></div>';

		echo '</div></div></div>';
		?>
		<script>
		(function () {
			var f = document.querySelector('.mqp-dev-filter');
			if (f) f.addEventListener('input', function () {
				var q = f.value.trim().toLowerCase();
				document.querySelectorAll('.mqp-dev-ver').forEach(function (d) {
					var hit = false;
					d.querySelectorAll('li').forEach(function (li) { var m = !q || li.textContent.toLowerCase().indexOf(q) !== -1; li.hidden = !m; hit = hit || m; });
					d.hidden = !hit;
					if (q && hit) d.open = true;
				});
			});
			var c = document.querySelector('.mqp-dev-copy');
			if (c) c.addEventListener('click', function () {
				var t = document.querySelector('.mqp-dev-report');
				var ok = function () { c.textContent = 'Copied'; setTimeout(function () { c.textContent = 'Copy report'; }, 1500); };
				if (navigator.clipboard) navigator.clipboard.writeText(t.value).then(ok); else { t.select(); document.execCommand('copy'); ok(); }
			});
		})();
		</script>
		<?php
	}

	/** Plain-text environment report (no secrets). */
	private static function environment() {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		$all     = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			$plugins[] = ( $all[ $p ]['Name'] ?? $p ) . ' ' . ( $all[ $p ]['Version'] ?? '' );
		}
		$theme = wp_get_theme();
		$s     = MQP_Settings::all();
		$m     = MQP_Monitor::settings();
		$yes   = function ( $v ) {
			return $v ? 'yes' : 'no';
		};
		$proxy = array();
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_HCDN_CLIENT_IP' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$proxy[] = $h;
			}
		}
		$lines = array(
			'MQ Portal ' . MQP_VERSION . ' (database ' . get_option( 'mqp_db_version' ) . ')',
			'Site: ' . home_url() . ' · portal: ' . MQP_Portal::url(),
			'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ' · ' . $wpdb->db_server_info(),
			'Server: ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '?' ) . ' · HTTPS ' . $yes( is_ssl() ) . ' · zip ' . $yes( class_exists( 'ZipArchive' ) ),
			'Memory limit: ' . ini_get( 'memory_limit' ) . ' · max upload ' . size_format( wp_max_upload_size() ) . ' · max execution ' . ini_get( 'max_execution_time' ) . 's',
			'Timezone: ' . wp_timezone_string() . ' · now ' . wp_date( 'Y-m-d H:i' ),
			'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'Active plugins: ' . implode( ', ', $plugins ),
			'WP_DEBUG ' . $yes( defined( 'WP_DEBUG' ) && WP_DEBUG ) . ' · DISABLE_WP_CRON ' . $yes( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) . ' · object cache ' . $yes( wp_using_ext_object_cache() ),
			'Proxy/CDN headers seen: ' . ( $proxy ? implode( ', ', $proxy ) : 'none' ) . ' · MQP_TRUST_PROXY_HEADER ' . ( defined( 'MQP_TRUST_PROXY_HEADER' ) ? MQP_TRUST_PROXY_HEADER : 'not set' ),
			'Online ordering ' . $yes( $s['order_enabled'] ) . ' · Turnstile ' . $yes( $s['turnstile_site_key'] && $s['turnstile_secret'] ) . ' · SMTP plugin ' . $yes( has_action( 'phpmailer_init' ) ) . ' · maintenance ' . $yes( $m['maintenance'] ),
			'Archives auto ' . $yes( $m['archive_auto'] ) . ' · Google Drive ' . ( MQP_GDrive::connected() ? 'connected' : 'not connected' ),
			'Privacy policy: ' . ( MQP_Privacy::url() ? MQP_Privacy::url() : 'not found' ),
			'Server cron: ' . ( get_option( 'mqp_server_cron' ) ? 'last ' . human_time_diff( (int) get_option( 'mqp_server_cron' ) ) . ' ago' : 'never seen' ),
		);
		return implode( "\n", $lines );
	}

	public static function styles() {
		echo '<style>
.mqp-dev .mqp-dev-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin:14px 0 16px}
.mqp-dev .mqp-dev-tile{display:flex;flex-direction:column;gap:2px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #c3c4c7;border-radius:6px;color:#1d2327;text-decoration:none}
.mqp-dev a.mqp-dev-tile:hover{border-color:#2271b1;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.mqp-dev .mqp-dev-tile span{font-weight:600;color:#50575e}.mqp-dev .mqp-dev-tile strong{font-size:22px;line-height:1.25}.mqp-dev .mqp-dev-tile small{color:#646970}
.mqp-dev .mqp-dev-tile.is-ok{border-left-color:#00a32a}.mqp-dev .mqp-dev-tile.is-warn{border-left-color:#dba617}.mqp-dev .mqp-dev-tile.is-bad{border-left-color:#d63638}
.mqp-dev .mqp-dev-head{display:flex;justify-content:space-between;align-items:center;gap:10px}.mqp-dev .mqp-dev-head h2{margin:0}
.mqp-dev .mqp-card h3{margin:16px 0 6px;font-size:13px}
.mqp-dev .mqp-dev-filter{width:100%;margin:8px 0 10px}
.mqp-dev .mqp-dev-ver{border-top:1px solid #f0f0f1;padding:8px 0}
.mqp-dev .mqp-dev-ver summary{cursor:pointer}
.mqp-dev .mqp-dev-ver ul{margin:8px 0 4px 18px;list-style:disc}.mqp-dev .mqp-dev-ver li{margin:0 0 5px}
.mqp-dev .mqp-dev-ver.is-new{background:#fffbea;margin:0 -12px;padding:8px 12px;border-radius:4px}
.mqp-dev .mqp-pill.is-warn{background:#fcf9e8;color:#8a6d00}
.mqp-dev .mqp-dev-unseen td{font-weight:600}
.mqp-dev .mqp-dev-actions{display:grid;gap:10px}.mqp-dev .mqp-dev-actions div{display:flex;gap:10px;align-items:center}.mqp-dev .mqp-dev-actions .button{flex:none;min-width:150px;text-align:center}.mqp-dev .mqp-dev-actions small{color:#646970}
.mqp-dev .mqp-dev-report{font-size:12px;white-space:pre}
.mqp-dev .mqp-ws-bar{height:12px;border-radius:6px;background:#f0f0f1;overflow:hidden;margin:4px 0 10px}
.mqp-dev .mqp-ws-bar span{display:block;height:100%;background:#2271b1}.mqp-dev .mqp-ws-bar.is-warn span{background:#dba617}.mqp-dev .mqp-ws-bar.is-bad span{background:#d63638}
</style>';
	}
}
