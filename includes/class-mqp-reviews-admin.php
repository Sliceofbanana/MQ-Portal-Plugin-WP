<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → Reviews: the reviews shown on the website by [mq_reviews].
 * At a glance (waiting, shown, average rating, Google), a filterable list with bulk actions,
 * add/edit by hand, Google reviews, settings and emails, and how to put reviews on the website.
 * WordPress administrators only.
 */
class MQP_Reviews_Admin {

	const SLUG = 'mq-portal-reviews';
	const CAP  = 'manage_options'; // wp-admin is for WordPress administrators only (1.18).

	const TABS = array(
		'list'     => 'Reviews',
		'edit'     => '+ Add a review',
		'google'   => 'Google reviews',
		'settings' => 'Settings & emails',
		'help'     => 'On the website',
	);

	const PER_PAGE = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_mqp_reviews', array( __CLASS__, 'handle' ) );
	}

	public static function url( $tab = 'list', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( $op, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mqp_reviews', 'op' => $op ), $args ), admin_url( 'admin-post.php' ) ), 'mqp_reviews' );
	}

	public static function menu() {
		$n      = current_user_can( self::CAP ) ? MQP_Reviews::pending_count() : 0;
		$bubble = $n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . $n . '</span></span>' : '';
		$hook   = add_submenu_page( 'mq-portal', 'Website reviews', 'Reviews' . $bubble, self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
	}

	/* ---------- Actions ---------- */

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'You do not have permission to manage reviews.', 403 );
		}
		check_admin_referer( 'mqp_reviews' );
		$op   = sanitize_key( (string) ( $_REQUEST['op'] ?? '' ) );
		$id   = (int) ( $_REQUEST['id'] ?? 0 );
		$back = self::url( 'list', array_filter( array( 'status' => sanitize_key( (string) ( $_REQUEST['status'] ?? '' ) ) ) ) );
		$done = '';

		switch ( $op ) {
			case 'show':
			case 'hide':
				$r = MQP_Reviews::get( $id );
				if ( $r && 'show' === $op && (int) $r['rating'] < MQP_Reviews::MIN_STARS ) {
					$done = 'private';
					break;
				}
				if ( $r ) {
					MQP_Reviews::save( array( 'status' => 'show' === $op ? 'shown' : 'hidden' ), $id );
					MQP_Monitor::log( 'system', ( 'show' === $op ? 'Showed' : 'Hid' ) . ' the review of ' . $r['name'] );
					$done = 'show' === $op ? 'shown' : 'hidden';
				}
				break;
			case 'feature':
				$r = MQP_Reviews::get( $id );
				if ( $r && ( 'shown' !== $r['status'] || (int) $r['rating'] < MQP_Reviews::MIN_STARS ) ) {
					$done = 'feat_bad';
					break;
				}
				if ( $r ) {
					// With a chosen lineup, this week's review must be in it.
					if ( array_filter( (array) MQP_Reviews::options()['featured'] ) && ! MQP_Reviews::in_lineup( $id ) ) {
						MQP_Reviews::set_lineup( $id, true );
					}
					MQP_Reviews::set_featured( $id );
					MQP_Monitor::log( 'system', 'Featured the review of ' . $r['name'] . ' this week' );
					$done = 'featured';
				}
				break;
			case 'lineup_add':
			case 'lineup_remove':
				$r = MQP_Reviews::get( $id );
				if ( $r && 'lineup_add' === $op && ( 'shown' !== $r['status'] || (int) $r['rating'] < MQP_Reviews::MIN_STARS ) ) {
					$done = 'feat_bad';
					break;
				}
				if ( $r ) {
					MQP_Reviews::set_lineup( $id, 'lineup_add' === $op );
					MQP_Monitor::log( 'system', ( 'lineup_add' === $op ? 'Added' : 'Removed' ) . ' the review of ' . $r['name'] . ( 'lineup_add' === $op ? ' to' : ' from' ) . ' the featured lineup' );
					$done = $op;
				}
				break;
			case 'delete':
				$r = MQP_Reviews::get( $id );
				if ( $r ) {
					MQP_Reviews::delete( $id );
					MQP_Monitor::log( 'system', 'Deleted the review of ' . $r['name'] );
					$done = 'deleted';
				}
				break;
			case 'bulk':
				// Several reviews at once: approve and show, hide, or delete.
				$ids   = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
				$what  = sanitize_key( (string) ( $_POST['bulk'] ?? '' ) );
				$n     = 0;
				$skip  = 0;
				foreach ( $ids as $rid ) {
					$r = MQP_Reviews::get( $rid );
					if ( ! $r ) {
						continue;
					}
					if ( 'show' === $what ) {
						if ( (int) $r['rating'] < MQP_Reviews::MIN_STARS ) {
							$skip++;
							continue;
						}
						MQP_Reviews::save( array( 'status' => 'shown' ), $rid );
					} elseif ( 'hide' === $what ) {
						MQP_Reviews::save( array( 'status' => 'hidden' ), $rid );
					} elseif ( 'delete' === $what ) {
						MQP_Reviews::delete( $rid );
					} else {
						continue;
					}
					$n++;
				}
				if ( $n ) {
					MQP_Monitor::log( 'system', array( 'show' => 'Showed', 'hide' => 'Hid', 'delete' => 'Deleted' )[ $what ] . " $n review(s)" );
				}
				$back = add_query_arg( array( 'n' => $n, 'skip' => $skip ), $back );
				$done = $n ? 'bulk_' . $what : ( $skip ? 'private' : 'bulk_none' );
				break;
			case 'save':
				$res = self::save_review( $id );
				if ( is_wp_error( $res ) ) {
					set_transient( 'mqp_review_error_' . get_current_user_id(), $res->get_error_message(), 60 );
					wp_safe_redirect( self::url( 'edit', array_filter( array( 'id' => $id, 'done' => 'error' ) ) ) );
					exit;
				}
				$done = $id ? 'updated' : 'added';
				break;
			case 'google_save':
				$o = MQP_Reviews::options();
				$key = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['google_key'] ?? '' ) ) ) );
				MQP_Reviews::save_options(
					array(
						'google_key'   => '' === $key || '••••••••' === $key ? ( '' === $key ? '' : $o['google_key'] ) : $key,
						'google_place' => trim( sanitize_text_field( wp_unslash( (string) ( $_POST['google_place'] ?? '' ) ) ) ),
						'google_auto'  => empty( $_POST['google_auto'] ) ? 0 : 1,
						'google_min'   => max( MQP_Reviews::MIN_STARS, min( 5, (int) ( $_POST['google_min'] ?? 4 ) ) ),
					)
				);
				MQP_Monitor::log( 'system', 'Saved the Google reviews settings' );
				// Saving with a key and Place ID fetches the reviews straight away.
				$o = MQP_Reviews::options();
				if ( $o['google_key'] && $o['google_place'] ) {
					$n    = MQP_Reviews::google_sync();
					$done = is_wp_error( $n ) ? 'google_failed' : 'google_ok';
				} else {
					MQP_Reviews::save_options( array( 'google_error' => '' ) );
					$done = 'saved';
				}
				wp_safe_redirect( self::url( 'google', array( 'done' => $done ) ) );
				exit;
			case 'google_sync':
				$n = MQP_Reviews::google_sync();
				MQP_Monitor::log( 'system', 'Fetched Google reviews' . ( is_wp_error( $n ) ? ' (failed)' : '' ), is_wp_error( $n ) ? 'failed' : 'ok' );
				wp_safe_redirect( self::url( 'google', array( 'done' => is_wp_error( $n ) ? 'google_failed' : 'google_ok' ) ) );
				exit;
			case 'settings_save':
				MQP_Reviews::save_options(
					array(
						'auto_approve' => empty( $_POST['auto_approve'] ) ? 0 : 1,
						'notify'       => empty( $_POST['notify'] ) ? 0 : 1,
						'thank'        => empty( $_POST['thank'] ) ? 0 : 1,
					)
				);
				MQP_Monitor::log( 'system', 'Saved the reviews settings' );
				wp_safe_redirect( self::url( 'settings', array( 'done' => 'saved' ) ) );
				exit;
		}
		wp_safe_redirect( add_query_arg( 'done', $done, $back ) );
		exit;
	}

	/** Add or edit form → database. */
	private static function save_review( $id ) {
		$old = $id ? MQP_Reviews::get( $id ) : null;
		if ( $id && ! $old ) {
			return new WP_Error( 'mqp_review', 'That review no longer exists.' );
		}
		$status = sanitize_key( (string) ( $_POST['status'] ?? 'shown' ) );
		$data   = array(
			'status'  => isset( MQP_Reviews::STATUSES[ $status ] ) ? $status : 'shown',
			'tagline' => mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['tagline'] ?? '' ) ) ), 0, 100 ),
		);

		// Google reviews are the reviewer's own words: only their status can change here.
		if ( ! $old || 'google' !== $old['source'] ) {
			$name = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
			$body = sanitize_textarea_field( wp_unslash( (string) ( $_POST['body'] ?? '' ) ) );
			if ( '' === $name || '' === trim( $body ) ) {
				return new WP_Error( 'mqp_review', 'Please enter the name and the review text.' );
			}
			$date = sanitize_text_field( (string) ( $_POST['reviewed_at'] ?? '' ) );
			$data += array(
				'name'        => mb_substr( $name, 0, 150 ),
				'body'        => mb_substr( $body, 0, 5000 ),
				'rating'      => (int) ( $_POST['rating'] ?? 5 ),
				'reviewed_at' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date . ' 12:00:00' : ( $old ? $old['reviewed_at'] : mqp_now() ),
			);
			$photo = MQP_Reviews::store_photo( $_FILES['photo'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $photo ) ) {
				return $photo;
			}
			if ( $photo || ! empty( $_POST['remove_photo'] ) ) {
				if ( $old ) {
					MQP_Reviews::delete_photo( $old['photo'] );
				}
				$data['photo'] = $photo;
			}
			if ( ! $old ) {
				$data['source'] = 'manual';
			}
		}
		$saved = MQP_Reviews::save( $data, $id );
		MQP_Monitor::log( 'system', ( $id ? 'Edited' : 'Added' ) . ' the review of ' . ( $data['name'] ?? $old['name'] ) );
		return $saved;
	}

	/* ---------- Page ---------- */

	public static function render() {
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'list' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'list';
		$id  = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$waiting = MQP_Reviews::pending_count();
		echo '<div class="wrap mqp-sys mqp-rv"><h1 class="wp-heading-inline">Website reviews</h1> <a class="page-title-action" href="' . esc_url( self::url( 'edit' ) ) . '">+ Add a review</a><hr class="wp-header-end">';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $k => $label ) {
			$label = 'edit' === $k && $id && 'edit' === $tab ? 'Edit review' : $label;
			$count = 'list' === $k && $waiting ? ' <span class="mqp-count">' . (int) $waiting . '</span>' : '';
			printf( '<a href="%s" class="nav-tab%s">%s%s</a>', esc_url( self::url( $k ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ), $count ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav><div class="mqp-tab">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div></div>';
	}

	private static function notice() {
		$done = sanitize_key( (string) ( $_GET['done'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$o    = MQP_Reviews::options();
		$msgs = array(
			'shown'         => array( 'success', 'The review is shown on the website.' ),
			'hidden'        => array( 'success', 'The review is hidden from the website.' ),
			'deleted'       => array( 'success', 'The review was deleted.' ),
			'featured'      => array( 'success', 'This review is now the featured review of the week. Next Monday the next one in the lineup takes over.' ),
			'lineup_add'    => array( 'success', 'Added to the featured lineup. Only reviews in the lineup take turns now.' ),
			'lineup_remove' => array( 'success', 'Removed from the featured lineup.' ),
			'feat_bad'      => array( 'warning', 'Only reviews shown on the website (4 or 5 stars) can be featured.' ),
			'private'       => array( 'warning', 'Reviews below 4 stars are kept private and can\'t be shown on the website.' ),
			'added'         => array( 'success', 'Review added.' ),
			'updated'       => array( 'success', 'Review saved.' ),
			'saved'         => array( 'success', 'Settings saved.' ),
			'google_ok'     => array( 'success', 'Google reviews updated.' ),
			'google_failed' => array( 'error', $o['google_error'] ? $o['google_error'] : 'Google reviews could not be fetched.' ),
			'bulk_show'     => array( 'success', (int) ( $_GET['n'] ?? 0 ) . ' review(s) shown on the website.' . ( (int) ( $_GET['skip'] ?? 0 ) ? ' ' . (int) $_GET['skip'] . ' below 4 stars stayed private.' : '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'bulk_hide'     => array( 'success', (int) ( $_GET['n'] ?? 0 ) . ' review(s) hidden.' ), // phpcs:ignore WordPress.Security.NonceVerification
			'bulk_delete'   => array( 'success', (int) ( $_GET['n'] ?? 0 ) . ' review(s) deleted.' ), // phpcs:ignore WordPress.Security.NonceVerification
			'bulk_none'     => array( 'warning', 'Tick at least one review and choose what to do.' ),
		);
		if ( 'error' === $done ) {
			$err = get_transient( 'mqp_review_error_' . get_current_user_id() );
			delete_transient( 'mqp_review_error_' . get_current_user_id() );
			$msgs['error'] = array( 'error', $err ? $err : 'The review could not be saved.' );
		}
		if ( isset( $msgs[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $done ][0] ), esc_html( $msgs[ $done ][1] ) );
		}
	}

	private static function avatar( array $r ) {
		return $r['photo']
			? '<img class="mqp-rv-pic" src="' . esc_url( $r['photo'] ) . '" alt="" referrerpolicy="no-referrer">'
			: '<span class="mqp-rv-pic is-initials">' . esc_html( MQP_Reviews::initials( $r['name'] ) ) . '</span>';
	}

	/* Reviews list */

	private static function tab_list() {
		global $wpdb;
		$t      = mqp_table( 'reviews' );
		$counts = MQP_Reviews::counts();
		$all    = array_sum( $counts );
		$status = sanitize_key( (string) ( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'all' !== $status && ! isset( MQP_Reviews::STATUSES[ $status ] ) ) {
			$status = $counts['pending'] ? 'pending' : 'shown';
		}
		$q      = sanitize_text_field( wp_unslash( (string) ( $_GET['q'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$source = sanitize_key( (string) ( $_GET['source'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$stars  = (int) ( $_GET['stars'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$where = array( '1=1' );
		$args  = array();
		if ( 'all' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( isset( MQP_Reviews::SOURCES[ $source ] ) ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}
		if ( $stars >= 1 && $stars <= 5 ) {
			$where[] = 'rating = %d';
			$args[]  = $stars;
		}
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(name LIKE %s OR body LIKE %s OR email LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$sql_where = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql_where", $args ) : "SELECT COUNT(*) FROM $t WHERE $sql_where" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql       = "SELECT * FROM $t WHERE $sql_where ORDER BY reviewed_at DESC, id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ( ( $page - 1 ) * self::PER_PAGE );
		$rows      = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$avg       = $wpdb->get_row( "SELECT AVG(rating) AS a, COUNT(*) AS n FROM $t WHERE status = 'shown'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$o         = MQP_Reviews::options();
		$feat      = MQP_Reviews::featured();
		$fid       = $feat ? (int) $feat['id'] : 0;
		MQP_Reviews::lineup( $chosen );

		// At a glance.
		$tile = function ( $label, $value, $sub, $link = '', $tone = '' ) {
			$inner = '<span>' . esc_html( $label ) . '</span><strong>' . $value . '</strong><small>' . esc_html( $sub ) . '</small>';
			return $link ? '<a class="mqp-rv-tile' . ( $tone ? ' is-' . $tone : '' ) . '" href="' . esc_url( $link ) . '">' . $inner . '</a>' : '<div class="mqp-rv-tile">' . $inner . '</div>';
		};
		echo '<div class="mqp-rv-tiles">';
		echo $tile( 'Waiting for approval', (int) $counts['pending'], $counts['pending'] ? 'Approve or hide them' : 'Nothing waiting', self::url( 'list', array( 'status' => 'pending' ) ), $counts['pending'] ? 'warn' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'On the website', (int) $counts['shown'], 'Reviews shown with [mq_reviews]', self::url( 'list', array( 'status' => 'shown' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Average on the website', $avg && (int) $avg['n'] ? number_format( (float) $avg['a'], 1 ) . ' <em>★</em>' : '—', (int) ( $avg['n'] ?? 0 ) . ' shown review(s)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Google', $o['google_rating'] ? number_format( (float) $o['google_rating'], 1 ) . ' <em>★</em>' : '—', $o['google_rating'] ? (int) $o['google_count'] . ' reviews on Google' : 'Not connected', self::url( 'google' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Private feedback', (int) $counts['hidden'], 'Hidden (incl. below 4 stars)', self::url( 'list', array( 'status' => 'hidden' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		// Featured review of the week (compact).
		echo '<div class="mqp-card mqp-rv-feat"><div class="mqp-rv-feat-row"><strong>★ Featured this week:</strong> ';
		if ( $feat ) {
			echo esc_html( $feat['name'] ) . ( $feat['tagline'] ? ' · ' . esc_html( $feat['tagline'] ) : '' ) . ' ' . MQP_Reviews::stars( (int) $feat['rating'], 13 ) . ' <span class="description">“' . esc_html( wp_trim_words( $feat['body'], 16 ) ) . '”</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo '<span class="description">none yet: show a 4- or 5-star review first.</span>';
		}
		echo '</div><p class="description">' . ( $chosen ? 'Changes every Monday between the reviews in your featured lineup.' : 'Changes every Monday between all 5-star reviews on the website; use "Add to lineup" to choose.' ) . ' <code>[mq_featured_review]</code> · <a href="' . esc_url( self::url( 'help' ) ) . '">How to use</a></p></div>';

		// Filters.
		echo '<ul class="mqp-rv-pills">';
		foreach ( array( 'pending' => 'Waiting', 'shown' => 'On the website', 'hidden' => 'Hidden', 'all' => 'All' ) as $k => $label ) {
			$n = 'all' === $k ? $all : (int) $counts[ $k ];
			printf( '<li><a href="%s" class="%s">%s <span>%d</span></a></li>', esc_url( self::url( 'list', array( 'status' => $k ) ) ), $k === $status ? 'is-on' : '', esc_html( $label ), (int) $n );
		}
		echo '</ul>';
		echo '<form class="mqp-rv-filters" method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="list"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Search name, text or email…">';
		echo '<select name="source"><option value="">Any source</option>';
		foreach ( MQP_Reviews::SOURCES as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $source, $k, false ), esc_html( $label ) );
		}
		echo '</select><select name="stars"><option value="0">Any stars</option>';
		for ( $i = 5; $i >= 1; $i-- ) {
			printf( '<option value="%1$d"%2$s>%1$d stars</option>', (int) $i, selected( $stars, $i, false ) );
		}
		echo '</select><button class="button">Filter</button>';
		if ( '' !== $q || $source || $stars ) {
			echo ' <a href="' . esc_url( self::url( 'list', array( 'status' => $status ) ) ) . '">Clear</a>';
		}
		echo '<span class="mqp-rv-total">' . (int) $total . ' review(s)</span></form>';

		if ( ! $rows ) {
			$empty = array(
				'pending' => 'Nothing waiting. New reviews from "Leave a Review" show up here first.',
				'shown'   => 'No reviews on the website yet. Add one, or connect your Google reviews.',
				'hidden'  => 'No hidden reviews. Reviews below 4 stars land here automatically.',
				'all'     => 'No reviews yet.',
			);
			echo '<div class="mqp-card mqp-empty"><p>' . esc_html( '' !== $q || $source || $stars ? 'No reviews match these filters.' : $empty[ $status ] ) . '</p></div>';
			return;
		}

		// Bulk actions: the checkboxes of the cards belong to this form.
		echo '<form id="mqp-rv-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="var s=this.bulk.value;if(!s){alert(\'Choose what to do with the ticked reviews.\');return false;}return s!==\'delete\'||confirm(\'Delete the ticked reviews for good?\');">';
		wp_nonce_field( 'mqp_reviews' );
		echo '<input type="hidden" name="action" value="mqp_reviews"><input type="hidden" name="op" value="bulk"><input type="hidden" name="status" value="' . esc_attr( 'all' === $status ? '' : $status ) . '">';
		echo '<div class="mqp-rv-bulkbar"><label><input type="checkbox" class="mqp-rv-all"> Select all on this page</label><select name="bulk"><option value="">With the ticked reviews…</option><option value="show">Approve / show on the website</option><option value="hide">Hide</option><option value="delete">Delete</option></select><button class="button">Apply</button></div></form>';

		foreach ( $rows as $r ) {
			$args = array( 'id' => $r['id'], 'status' => 'all' === $status ? '' : $status );
			$low  = (int) $r['rating'] < MQP_Reviews::MIN_STARS;
			echo '<div class="mqp-card mqp-rv-row is-' . esc_attr( $r['status'] ) . '">';
			echo '<input type="checkbox" class="mqp-rv-pick" name="ids[]" value="' . (int) $r['id'] . '" form="mqp-rv-bulk" aria-label="' . esc_attr( 'Select the review of ' . $r['name'] ) . '">';
			echo self::avatar( $r ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="mqp-rv-main"><div class="mqp-rv-head"><strong>' . esc_html( $r['name'] ) . '</strong>';
			echo '<span class="mqp-rv-stars" aria-label="' . esc_attr( $r['rating'] . ' out of 5 stars' ) . '">' . MQP_Reviews::stars( (int) $r['rating'], 15 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="mqp-rv-status is-' . esc_attr( $r['status'] ) . '">' . esc_html( array( 'pending' => 'Waiting', 'shown' => 'On the website', 'hidden' => 'Hidden' )[ $r['status'] ] ?? $r['status'] ) . '</span>';
			echo '<span class="mqp-rv-src is-' . esc_attr( $r['source'] ) . '">' . esc_html( MQP_Reviews::SOURCES[ $r['source'] ] ?? $r['source'] ) . '</span>';
			echo '<span class="description">' . esc_html( mysql2date( 'M j, Y', $r['reviewed_at'] ) ) . '</span>';
			if ( $low ) {
				echo '<span class="mqp-rv-private">Below 4 stars · private</span>';
			}
			if ( (int) $r['id'] === $fid ) {
				echo '<span class="mqp-rv-featured">★ Featured this week</span>';
			}
			if ( MQP_Reviews::in_lineup( $r['id'] ) ) {
				echo '<span class="mqp-rv-lineup">In featured lineup</span>';
			}
			echo '</div>';
			if ( $r['tagline'] ) {
				echo '<div class="description">' . esc_html( $r['tagline'] ) . '</div>';
			}
			echo '<div class="mqp-rv-body">' . wpautop( esc_html( $r['body'] ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $r['email'] ) {
				echo '<p class="description">Email (never shown): <a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a></p>';
			}
			echo '<p class="mqp-inline mqp-rv-actions">';
			if ( 'shown' !== $r['status'] && ! $low ) {
				echo '<a class="button button-primary" href="' . esc_url( self::action_url( 'show', $args ) ) . '">' . ( 'pending' === $r['status'] ? 'Approve and show' : 'Show on website' ) . '</a>';
			}
			if ( 'hidden' !== $r['status'] ) {
				echo '<a class="button" href="' . esc_url( self::action_url( 'hide', $args ) ) . '">Hide</a>';
			}
			if ( 'shown' === $r['status'] && ! $low ) {
				if ( (int) $r['id'] !== $fid ) {
					echo '<a class="button" href="' . esc_url( self::action_url( 'feature', $args ) ) . '">Feature this week</a>';
				}
				$in = MQP_Reviews::in_lineup( $r['id'] );
				echo '<a class="button" href="' . esc_url( self::action_url( $in ? 'lineup_remove' : 'lineup_add', $args ) ) . '">' . ( $in ? 'Remove from lineup' : 'Add to lineup' ) . '</a>';
			}
			echo '<a class="button" href="' . esc_url( self::url( 'edit', array( 'id' => $r['id'] ) ) ) . '">Edit</a>';
			echo '<a class="button-link-delete mqp-rv-del" href="' . esc_url( self::action_url( 'delete', $args ) ) . '" onclick="return confirm(\'Delete this review for good?\')">Delete</a>';
			echo '</p></div></div>';
		}

		// Pages.
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="mqp-rv-pages">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$link = self::url( 'list', array_filter( array( 'status' => $status, 'q' => $q, 'source' => $source, 'stars' => $stars, 'paged' => $p ) ) );
				echo $p === $page ? '<span class="button button-primary">' . (int) $p . '</span> ' : '<a class="button" href="' . esc_url( $link ) . '">' . (int) $p . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</p>';
		}
		echo '<script>(function(){var a=document.querySelector(".mqp-rv-all");if(a)a.addEventListener("change",function(){document.querySelectorAll(".mqp-rv-pick").forEach(function(c){c.checked=a.checked;});});})();</script>';
	}

	/* On the website (help) */

	private static function tab_help() {
		echo '<div class="mqp-grid mqp-grid-even"><div>';
		self::help_card();
		echo '</div></div>';
	}

	private static function help_card() {
		$o = MQP_Reviews::options();
		echo '<div class="mqp-card"><h2>Show reviews on the website</h2>';
		echo '<p>Add a <strong>Shortcode</strong> widget (Elementor) or block where the reviews should go, with:</p><p><code>[mq_reviews]</code></p>';
		echo '<p>It shows the cards with arrows and dots (swipe on phones) and the <strong>Leave a Review</strong> button, which opens a form in a pop-up.</p>';
		echo '<h3>Options</h3><table class="mqp-facts">';
		echo '<tr><th><code>limit="12"</code></th><td>How many reviews (newest first).</td></tr>';
		echo '<tr><th><code>source="google"</code></th><td>Only Google reviews (or <code>manual</code>, <code>website</code>).</td></tr>';
		echo '<tr><th><code>button="no"</code></th><td>No "Leave a Review" button. Or change its text: <code>button="Write a review"</code>.</td></tr>';
		echo '<tr><th><code>summary="no"</code></th><td>Hide the Google rating line above the cards.</td></tr>';
		echo '</table>';
		echo '<p class="description">Remove the old review carousel widget from the page so the reviews don\'t show twice.' . ( current_user_can( 'manage_options' ) ? ' All shortcodes: <a href="' . esc_url( MQP_Shortcodes_Admin::url() ) . '">MQ Portal → Shortcodes</a>.' : '' ) . '</p></div>';
		echo '<div class="mqp-card"><h2>Featured review (one per week)</h2>';
		echo '<p>For a section that shows <strong>one</strong> review card (like "Have questions? Contact us"), put a Shortcode widget in that column with:</p><p><code>[mq_featured_review]</code></p>';
		echo '<p>The card shows the text, the picture (or initials), the name and stars exactly like the other review cards, and the <strong>Line under the name</strong> (set it with Edit, e.g. "2026 BAR passer").</p>';
		echo '<p>It changes by itself every Monday. Choose which reviews take turns with <strong>Add to featured lineup</strong>; without a lineup, all 5-star reviews take turns.</p>';
		echo '<table class="mqp-facts">';
		echo '<tr><th><code>label="Review of the week"</code></th><td>Adds a small label on top of the card.</td></tr>';
		echo '<tr><th><code>link="/about-us/"</code></th><td>Adds a "See all reviews →" link below (change the words with <code>link_text="…"</code>).</td></tr>';
		echo '</table></div>';
		echo '<div class="mqp-card"><h2>Google</h2>';
		if ( $o['google_key'] && $o['google_place'] ) {
			echo '<p>' . ( $o['google_error'] ? '<span class="mqp-pill is-bad">Problem</span> ' . esc_html( $o['google_error'] ) : '<span class="mqp-pill is-ok">Connected</span> ' . esc_html( $o['google_rating'] ? number_format( (float) $o['google_rating'], 1 ) . ' ★ from ' . (int) $o['google_count'] . ' reviews' : '' ) ) . '</p>';
			echo '<p class="description">Updated ' . esc_html( $o['google_synced'] ? human_time_diff( (int) $o['google_synced'] ) . ' ago' : 'never' ) . '. Checked again every day.</p>';
		} else {
			echo '<p>Not connected. <a href="' . esc_url( self::url( 'google' ) ) . '">Connect your Google reviews</a>.</p>';
		}
		echo '</div>';
	}

	/* Add / edit */

	private static function tab_edit() {
		$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$r  = $id ? MQP_Reviews::get( $id ) : null;
		if ( $id && ! $r ) {
			echo '<div class="notice notice-error"><p>That review no longer exists.</p></div>';
			return;
		}
		$google = $r && 'google' === $r['source'];
		$r      = $r ? $r : array( 'name' => '', 'tagline' => '', 'rating' => 5, 'body' => '', 'photo' => '', 'status' => 'shown', 'reviewed_at' => mqp_now(), 'source' => 'manual' );

		echo '<div class="mqp-grid"><div><form class="mqp-card mqp-settings" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mqp_reviews' );
		echo '<input type="hidden" name="action" value="mqp_reviews"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<h2>' . ( $id ? 'Edit review' : 'Add a review' ) . '</h2>';
		if ( $google ) {
			echo '<p class="description">This review comes from Google. Its text, stars and picture always match Google; you can only show or hide it here.</p>';
		}
		$dis = $google ? ' disabled' : '';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="rv-name">Customer name</label></th><td><input id="rv-name" name="name" class="regular-text" maxlength="150" required value="' . esc_attr( $r['name'] ) . '"' . $dis . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th><label for="rv-tagline">Line under the name</label></th><td><input id="rv-tagline" name="tagline" class="regular-text" maxlength="100" value="' . esc_attr( $r['tagline'] ) . '" placeholder="e.g. 2026 BAR passer, Teacher, Business owner"><p class="description">Optional. Shown on the featured review card (<code>[mq_featured_review]</code>).</p></td></tr>';
		echo '<tr><th>Stars</th><td><fieldset class="mqp-rv-rate">';
		for ( $i = 5; $i >= 1; $i-- ) {
			printf( '<label><input type="radio" name="rating" value="%1$d"%2$s%3$s> %4$s</label>', $i, checked( (int) $r['rating'], $i, false ), $dis, str_repeat( '★', $i ) . str_repeat( '☆', 5 - $i ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th><label for="rv-body">Review</label></th><td><textarea id="rv-body" name="body" rows="6" class="large-text" maxlength="5000" required' . $dis . '>' . esc_textarea( $r['body'] ) . '</textarea></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th><label for="rv-date">Date</label></th><td><input id="rv-date" type="date" name="reviewed_at" value="' . esc_attr( substr( $r['reviewed_at'], 0, 10 ) ) . '"' . $dis . '><p class="description">Shown as "… ago" on the card. Newest reviews come first.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Profile picture</th><td><div class="mqp-inline">' . self::avatar( $r ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $google ) {
			echo '<input type="file" name="photo" accept="image/jpeg,image/png,image/webp">';
			if ( $r['photo'] ) {
				echo '<label><input type="checkbox" name="remove_photo" value="1"> Remove picture</label>';
			}
		}
		echo '</div>' . ( $google ? '' : '<p class="description">Optional. JPG, PNG or WebP. Without a picture, the card shows the initials.</p>' ) . '</td></tr>';
		echo '<tr><th>On the website</th><td><select name="status">';
		foreach ( MQP_Reviews::STATUSES as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $r['status'], $k, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">Reviews below 4 stars are always kept private (Hidden), whatever you choose here.</p></td></tr></table>';
		echo '<p><button class="button button-primary">' . ( $id ? 'Save review' : 'Add review' ) . '</button> <a class="button" href="' . esc_url( self::url( 'list' ) ) . '">Cancel</a></p>';
		echo '</form></div><div><div class="mqp-card"><h2>Tips</h2><p><strong>Line under the name</strong> shows on the featured review card, e.g. "2026 BAR passer".</p><p>Reviews below 4 stars are always kept private.</p><p>Copy reviews from Facebook or messages here, with the customer\'s permission.</p><p><a href="' . esc_url( self::url( 'help' ) ) . '">How to put reviews on the website →</a></p></div></div></div>';
	}

	/* Google */

	private static function tab_google() {
		$o = MQP_Reviews::options();
		echo '<div class="mqp-grid"><div><form class="mqp-card mqp-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mqp_reviews' );
		echo '<input type="hidden" name="action" value="mqp_reviews"><input type="hidden" name="op" value="google_save">';
		echo '<h2>Connect your Google reviews</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="g-key">Google API key</label></th><td><input id="g-key" name="google_key" class="regular-text" autocomplete="off" value="' . esc_attr( $o['google_key'] ? '••••••••' : '' ) . '"><p class="description">Kept on this site only. Clear the box to remove it.</p></td></tr>';
		echo '<tr><th><label for="g-place">Place ID</label></th><td><input id="g-place" name="google_place" class="regular-text" value="' . esc_attr( $o['google_place'] ) . '" placeholder="ChIJ…"></td></tr>';
		echo '<tr><th>New Google reviews</th><td><label><input type="checkbox" name="google_auto" value="1"' . checked( $o['google_auto'], 1, false ) . '> Show on the website right away when they have at least</label> ';
		echo '<select name="google_min">';
		for ( $i = 5; $i >= MQP_Reviews::MIN_STARS; $i-- ) {
			printf( '<option value="%1$d"%2$s>%1$d stars</option>', $i, selected( (int) $o['google_min'], $i, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</select><p class="description">Others wait under Reviews → Waiting for approval. Reviews with stars only (no text) always wait. Google reviews below 4 stars are kept private.</p></td></tr>';
		echo '</table><p class="mqp-inline"><button class="button button-primary">Save and fetch reviews</button>';
		if ( $o['google_key'] && $o['google_place'] ) {
			echo '<a class="button" href="' . esc_url( self::action_url( 'google_sync' ) ) . '">Fetch now</a>';
		}
		echo '</p></form>';

		echo '<div class="mqp-card"><h2>Status</h2><table class="mqp-facts">';
		echo '<tr><th>Connection</th><td>' . ( ! $o['google_key'] || ! $o['google_place'] ? 'Not set up' : ( $o['google_error'] ? '<span class="mqp-pill is-bad">Problem</span> ' . esc_html( $o['google_error'] ) : '<span class="mqp-pill is-ok">Working</span>' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Google rating</th><td>' . esc_html( $o['google_rating'] ? number_format( (float) $o['google_rating'], 1 ) . ' ★ (' . (int) $o['google_count'] . ' reviews)' : '—' ) . '</td></tr>';
		echo '<tr><th>Last update</th><td>' . esc_html( $o['google_synced'] ? wp_date( 'M j, Y g:i A', (int) $o['google_synced'] ) : 'Never' ) . '</td></tr>';
		echo '</table></div></div><div>';

		echo '<div class="mqp-card"><h2>How to set it up (about 10 minutes)</h2><ol class="mqp-steps">';
		echo '<li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> and create a project (e.g. "MQ Website"). Google asks for a billing account; the few requests this makes each day stay inside Google\'s free monthly allowance.</li>';
		echo '<li>Open <strong>APIs &amp; Services → Library</strong>, search for <strong>Places API (New)</strong> and click <strong>Enable</strong>.</li>';
		echo '<li>Open <strong>APIs &amp; Services → Credentials → Create credentials → API key</strong>. Under <strong>API restrictions</strong>, choose <strong>Restrict key</strong> and tick only <strong>Places API (New)</strong>. Copy the key into the box on the left.</li>';
		echo '<li>Find your <strong>Place ID</strong> with Google\'s <a href="https://developers.google.com/maps/documentation/places/web-service/place-id#find-id" target="_blank" rel="noopener">Place ID finder</a>: type your shop\'s name as it appears on Google Maps and copy the ID (it starts with <code>ChIJ</code>).</li>';
		echo '<li>Click <strong>Save and fetch reviews</strong>.</li></ol>';
		echo '<p class="description">Google gives its 5 most relevant reviews of the business at a time. New ones are added every day and stay here, so the list grows over time. Your overall Google rating shows above the cards.</p></div>';
		echo '</div></div>';
	}

	/* Settings */

	private static function tab_settings() {
		$o = MQP_Reviews::options();
		echo '<form class="mqp-card mqp-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mqp_reviews' );
		echo '<input type="hidden" name="action" value="mqp_reviews"><input type="hidden" name="op" value="settings_save">';
		echo '<h2>"Leave a Review" on the website</h2><table class="form-table" role="presentation">';
		echo '<tr><th>New reviews</th><td><label><input type="checkbox" name="auto_approve" value="1"' . checked( $o['auto_approve'], 1, false ) . '> Show on the website right away</label><p class="description">Off (recommended): they wait under Reviews → Waiting for approval until you approve them.</p></td></tr>';
		echo '<tr><th>Emails</th><td><label><input type="checkbox" name="notify" value="1"' . checked( $o['notify'], 1, false ) . '> Email the admins when a customer sends a review</label><p class="description">Sent to <strong>' . esc_html( MQP_Monitor::admin_to() ) . '</strong>: change who gets admin emails in <a href="' . esc_url( MQP_System::url( 'settings' ) . '#emails' ) . '">System → Settings → Emails</a>.</p>';
		echo '<p><label><input type="checkbox" name="thank" value="1"' . checked( $o['thank'], 1, false ) . '> Send the customer a thank-you email</label></p><p class="description">Only when they gave their email. From "' . esc_html( MQP_Mail::sender() ) . '".</p></td></tr>';
		$lim = (int) MQP_Monitor::settings()['limit_reviews'];
		echo '<tr><th>Spam protection</th><td>At most ' . $lim . ' reviews per hour and ' . ( 2 * $lim ) . ' per day from the same internet connection, ' . MQP_Reviews::DAY_MAX . ' per day from everyone together, ' . MQP_Reviews::PENDING_MAX . ' waiting at most, the same text only once, pictures at most 3 MB (saved as a small 240 px picture), a hidden spam trap, and the Cloudflare Turnstile check when it is set up. Change the per-hour limit in System → Settings → Spam limits.</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Below 4 stars</th><td>Never shown on the website. They are kept under Reviews → Hidden so you can read the feedback.</td></tr>';
		echo '</table><p><button class="button button-primary">Save settings</button></p></form>';
	}

	public static function styles() {
		echo '<style>
.mqp-rv .mqp-rv-row{display:flex;gap:16px;align-items:flex-start;max-width:none}
.mqp-rv .mqp-rv-row.is-pending{border-left:4px solid #dba617}
.mqp-rv .mqp-rv-pic{flex:none;width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #f88a35;padding:2px;background:#fff}
.mqp-rv .mqp-rv-pic.is-initials{display:grid;place-items:center;background:#1e3a8a;color:#fff;font-weight:700;font-size:16px;box-sizing:border-box}
.mqp-rv .mqp-rv-main{flex:1;min-width:0}
.mqp-rv .mqp-rv-head{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center}
.mqp-rv .mqp-rv-head strong{font-size:14px}
.mqp-rv .mqp-rv-stars{display:inline-flex;gap:1px;line-height:0}
.mqp-rv .mqp-rv-stars svg{fill:#e5e7eb}.mqp-rv .mqp-rv-stars svg.is-on{fill:#f5a524}
.mqp-rv .mqp-rv-src{font-size:11px;border-radius:3px;padding:1px 6px;background:#f0f0f1;color:#50575e}
.mqp-rv .mqp-rv-src.is-google{background:#e8f0fe;color:#1a56db}.mqp-rv .mqp-rv-src.is-website{background:#fff4e5;color:#a35200}
.mqp-rv .mqp-rv-body{margin:6px 0 0;color:#1d2327;overflow-wrap:anywhere}.mqp-rv .mqp-rv-body p{margin:0 0 6px}
.mqp-rv .mqp-rv-actions{margin:10px 0 0}
.mqp-rv .mqp-rv-del{margin-left:6px}
.mqp-rv .mqp-rv-private{font-size:11px;border-radius:3px;padding:1px 6px;background:#fcf0f1;color:#a00}
.mqp-rv .mqp-rv-filter{float:none;margin:0 0 8px}
.mqp-rv .mqp-rv-featured{font-size:11px;border-radius:3px;padding:1px 6px;background:#fff4e5;color:#a35200;font-weight:600}
.mqp-rv .mqp-rv-lineup{font-size:11px;border-radius:3px;padding:1px 6px;background:#eef2ff;color:#1e3a8a}
.mqp-rv .mqp-rv-feat{border-left:4px solid #f88a35;max-width:none}.mqp-rv .mqp-rv-feat h2{margin-top:0}
.mqp-rv .mqp-rv-feat svg{fill:#e5e7eb;vertical-align:-2px}.mqp-rv .mqp-rv-feat svg.is-on{fill:#f5a524}
.mqp-rv .mqp-rv-rate{display:grid;gap:4px}.mqp-rv .mqp-rv-rate label{color:#f5a524;font-size:16px;letter-spacing:1px}
.mqp-rv .mqp-rv-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:14px 0}
.mqp-rv .mqp-rv-tile{display:flex;flex-direction:column;gap:2px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #c3c4c7;border-radius:6px;color:#1d2327;text-decoration:none}
.mqp-rv a.mqp-rv-tile:hover{border-color:#2271b1}.mqp-rv .mqp-rv-tile.is-warn{border-left-color:#dba617}
.mqp-rv .mqp-rv-tile span{font-weight:600;color:#50575e}.mqp-rv .mqp-rv-tile strong{font-size:22px;line-height:1.25}.mqp-rv .mqp-rv-tile strong em{font-style:normal;color:#f5a524}.mqp-rv .mqp-rv-tile small{color:#646970}
.mqp-rv .mqp-rv-feat{padding:12px 16px}.mqp-rv .mqp-rv-feat p{margin:6px 0 0}
.mqp-rv .mqp-rv-pills{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0 10px;padding:0}
.mqp-rv .mqp-rv-pills a{display:inline-flex;gap:6px;align-items:center;padding:6px 12px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;text-decoration:none;color:#1d2327}
.mqp-rv .mqp-rv-pills a span{background:#f0f0f1;border-radius:999px;padding:0 7px;font-size:12px}
.mqp-rv .mqp-rv-pills a.is-on{background:#1e3a8a;border-color:#1e3a8a;color:#fff}.mqp-rv .mqp-rv-pills a.is-on span{background:rgba(255,255,255,.2)}
.mqp-rv .mqp-rv-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px}.mqp-rv .mqp-rv-filters input[type=search]{min-width:240px}
.mqp-rv .mqp-rv-total{margin-left:auto;color:#646970}
.mqp-rv .mqp-rv-bulkbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 10px;padding:8px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px}
.mqp-rv .mqp-rv-pick{margin-top:18px}
.mqp-rv .mqp-rv-row.is-hidden{opacity:.85}
.mqp-rv .mqp-rv-status{font-size:11px;border-radius:3px;padding:1px 6px;font-weight:600}
.mqp-rv .mqp-rv-status.is-pending{background:#fcf9e8;color:#8a6d00}.mqp-rv .mqp-rv-status.is-shown{background:#e7f6ec;color:#0a7a32}.mqp-rv .mqp-rv-status.is-hidden{background:#f0f0f1;color:#50575e}
.mqp-rv .mqp-rv-pages{margin:14px 0}
.mqp-rv .nav-tab .mqp-count{display:inline-block;margin-left:4px;padding:0 6px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:17px}
</style>';
	}
}
