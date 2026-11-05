<?php
defined( 'ABSPATH' ) || exit;

/**
 * Portal → Admin → Reviews: approve, hide, add, edit and delete website reviews without wp-admin.
 * Needs the "mq_reviews" right (Owner by default; give it to others in Admin → Staff).
 * Same rules as wp-admin → MQ Portal → Reviews: reviews below 4 stars are always private, and
 * Google reviews are the reviewer's own words (only their status and tagline can change).
 */
class MQP_REST_Reviews {

	const PER_PAGE = 20;

	public static function routes() {
		$ns   = MQP_REST::NS;
		$perm = MQP_REST::can( 'mq_reviews' );
		register_rest_route( $ns, '/reviews', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, '/reviews/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'bulk' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( $ns, '/reviews/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'remove' ), 'permission_callback' => $perm ),
		) );
	}

	/** One review for the portal (never the sender's IP address). */
	public static function format( array $r ) {
		return array(
			'id'          => (int) $r['id'],
			'source'      => $r['source'],
			'name'        => $r['name'],
			'tagline'     => (string) $r['tagline'],
			'photo'       => (string) $r['photo'],
			'link'        => (string) $r['link'],
			'body'        => (string) $r['body'],
			'email'       => (string) $r['email'],
			'status'      => $r['status'],
			'rating'      => (int) $r['rating'],
			'reviewed_at' => $r['reviewed_at'],
			'created_at'  => $r['created_at'],
			'in_lineup'   => MQP_Reviews::in_lineup( $r['id'] ),
		);
	}

	public static function index( WP_REST_Request $req ) {
		global $wpdb;
		$t      = mqp_table( 'reviews' );
		$status = sanitize_key( (string) $req['status'] );
		$where  = array( '1=1' );
		$args   = array();
		if ( isset( MQP_Reviews::STATUSES[ $status ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		$source = sanitize_key( (string) $req['source'] );
		if ( isset( MQP_Reviews::SOURCES[ $source ] ) ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}
		$stars = (int) $req['stars'];
		if ( $stars >= 1 && $stars <= 5 ) {
			$where[] = 'rating = %d';
			$args[]  = $stars;
		}
		$q = trim( sanitize_text_field( (string) $req['q'] ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '( name LIKE %s OR body LIKE %s OR email LIKE %s )';
			array_push( $args, $like, $like, $like );
		}
		$sql   = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql", $args ) : "SELECT COUNT(*) FROM $t WHERE $sql" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$page  = max( 1, (int) $req['page'] );
		$off   = ( $page - 1 ) * self::PER_PAGE;
		$list  = "SELECT * FROM $t WHERE $sql ORDER BY ( status = 'pending' ) DESC, reviewed_at DESC, id DESC LIMIT " . self::PER_PAGE . " OFFSET $off";
		$rows  = $wpdb->get_results( $args ? $wpdb->prepare( $list, $args ) : $list, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$o     = MQP_Reviews::options();
		$avg   = $wpdb->get_var( "SELECT AVG(rating) FROM $t WHERE status = 'shown'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array(
			'rows'     => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			'counts'   => MQP_Reviews::counts(),
			'private'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE rating < %d", MQP_Reviews::MIN_STARS ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'average'  => null === $avg ? null : round( (float) $avg, 1 ),
			'google'   => array( 'rating' => (float) $o['google_rating'], 'count' => (int) $o['google_count'] ),
			'featured' => (int) $o['feat_id'],
		);
	}

	public static function create( WP_REST_Request $req ) {
		return self::save( $req, 0 );
	}

	public static function update( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$r  = MQP_Reviews::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'mqp_review', 'That review no longer exists.', array( 'status' => 404 ) );
		}
		$p = self::params( $req );
		// Only the status (approve / hide) or the featured lineup.
		if ( array_keys( $p ) === array( 'status' ) || array_keys( $p ) === array( 'lineup' ) ) {
			if ( isset( $p['lineup'] ) ) {
				$on = ! empty( $p['lineup'] );
				if ( $on && ( 'shown' !== $r['status'] || (int) $r['rating'] < MQP_Reviews::MIN_STARS ) ) {
					return new WP_Error( 'mqp_review', 'Only reviews shown on the website (4 stars or more) can be featured.', array( 'status' => 400 ) );
				}
				MQP_Reviews::set_lineup( $id, $on );
				MQP_Monitor::log( 'system', ( $on ? 'Added' : 'Removed' ) . ' the review of ' . $r['name'] . ( $on ? ' to' : ' from' ) . ' the featured lineup (portal)' );
				return self::format( MQP_Reviews::get( $id ) );
			}
			$status = sanitize_key( (string) $p['status'] );
			if ( ! isset( MQP_Reviews::STATUSES[ $status ] ) ) {
				return new WP_Error( 'mqp_review', 'Unknown status.', array( 'status' => 400 ) );
			}
			if ( 'shown' === $status && (int) $r['rating'] < MQP_Reviews::MIN_STARS ) {
				return new WP_Error( 'mqp_review', 'Reviews below 4 stars are kept private and can\'t be shown on the website.', array( 'status' => 400 ) );
			}
			MQP_Reviews::save( array( 'status' => $status ), $id );
			MQP_Reviews::purge_cache();
			MQP_Monitor::log( 'system', ( 'shown' === $status ? 'Showed' : ( 'hidden' === $status ? 'Hid' : 'Set to waiting' ) ) . ' the review of ' . $r['name'] . ' (portal)' );
			return self::format( MQP_Reviews::get( $id ) );
		}
		return self::save( $req, $id );
	}

	/** Add or edit (JSON or multipart with a "photo" file). */
	private static function save( WP_REST_Request $req, $id ) {
		$p      = self::params( $req );
		$old    = $id ? MQP_Reviews::get( $id ) : null;
		$status = sanitize_key( (string) ( $p['status'] ?? ( $old ? $old['status'] : 'shown' ) ) );
		$data   = array(
			'status'  => isset( MQP_Reviews::STATUSES[ $status ] ) ? $status : 'shown',
			'tagline' => mb_substr( sanitize_text_field( (string) ( $p['tagline'] ?? ( $old ? $old['tagline'] : '' ) ) ), 0, 100 ),
		);
		if ( ! $old || 'google' !== $old['source'] ) {
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			$body = sanitize_textarea_field( (string) ( $p['body'] ?? '' ) );
			if ( '' === $name || '' === trim( $body ) ) {
				return new WP_Error( 'mqp_review', 'Please enter the name and the review text.', array( 'status' => 400 ) );
			}
			$date  = sanitize_text_field( (string) ( $p['reviewed_at'] ?? '' ) );
			$data += array(
				'name'        => mb_substr( $name, 0, 150 ),
				'body'        => mb_substr( $body, 0, 5000 ),
				'rating'      => max( 1, min( 5, (int) ( $p['rating'] ?? 5 ) ) ),
				'reviewed_at' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date <= wp_date( 'Y-m-d' ) ? $date . ' 12:00:00' : ( $old ? $old['reviewed_at'] : mqp_now() ),
			);
			$files = $req->get_file_params();
			$photo = MQP_Reviews::store_photo( $files['photo'] ?? null );
			if ( is_wp_error( $photo ) ) {
				$photo->add_data( array( 'status' => 400 ) );
				return $photo;
			}
			if ( $photo || ! empty( $p['remove_photo'] ) ) {
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
		MQP_Reviews::purge_cache();
		MQP_Monitor::log( 'system', ( $id ? 'Edited' : 'Added' ) . ' the review of ' . ( $data['name'] ?? $old['name'] ) . ' (portal)' );
		$row = MQP_Reviews::get( $saved );
		$out = self::format( $row );
		// Below 4 stars: saved, but kept private.
		$out['kept_private'] = 'shown' === $data['status'] && 'hidden' === $row['status'];
		return $out;
	}

	public static function remove( WP_REST_Request $req ) {
		$r = MQP_Reviews::get( (int) $req['id'] );
		if ( ! $r ) {
			return new WP_Error( 'mqp_review', 'That review no longer exists.', array( 'status' => 404 ) );
		}
		MQP_Reviews::delete( (int) $r['id'] );
		MQP_Reviews::purge_cache();
		MQP_Monitor::log( 'system', 'Deleted the review of ' . $r['name'] . ' (portal)' );
		return array( 'deleted' => true );
	}

	/** Several at once: {ids: [..], op: show | hide | delete}. */
	public static function bulk( WP_REST_Request $req ) {
		$p    = (array) $req->get_json_params();
		$op   = sanitize_key( (string) ( $p['op'] ?? '' ) );
		$ids  = array_slice( array_unique( array_filter( array_map( 'intval', (array) ( $p['ids'] ?? array() ) ) ) ), 0, 200 );
		$verb = array( 'show' => 'Showed', 'hide' => 'Hid', 'delete' => 'Deleted' );
		if ( ! isset( $verb[ $op ] ) ) {
			return new WP_Error( 'mqp_review', 'Unknown action.', array( 'status' => 400 ) );
		}
		$n    = 0;
		$skip = 0;
		foreach ( $ids as $id ) {
			$r = MQP_Reviews::get( $id );
			if ( ! $r ) {
				continue;
			}
			if ( 'show' === $op ) {
				if ( (int) $r['rating'] < MQP_Reviews::MIN_STARS ) {
					$skip++;
					continue;
				}
				MQP_Reviews::save( array( 'status' => 'shown' ), $id );
			} elseif ( 'hide' === $op ) {
				MQP_Reviews::save( array( 'status' => 'hidden' ), $id );
			} else {
				MQP_Reviews::delete( $id );
			}
			$n++;
		}
		if ( $n ) {
			MQP_Reviews::purge_cache();
			MQP_Monitor::log( 'system', $verb[ $op ] . " $n review(s) (portal)" );
		}
		return array( 'done' => $n, 'skipped' => $skip );
	}

	/** JSON body or form fields. */
	private static function params( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		$p    = is_array( $json ) && $json ? $json : $req->get_body_params();
		return array_intersect_key( (array) $p, array_flip( array( 'name', 'tagline', 'rating', 'body', 'status', 'reviewed_at', 'remove_photo', 'lineup' ) ) );
	}
}
