<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer reviews on the website: [mq_reviews] (carousel + "Leave a Review" pop-up) and
 * POST /mq/v1/public/review. Reviews come from three places:
 * - added by hand in wp-admin → MQ Portal → Reviews (source "manual");
 * - sent by customers with "Leave a Review" (source "website"; waits for approval unless turned off);
 * - Google reviews of the business, fetched daily with the Google Places API (source "google").
 * Only reviews with status "shown" and at least 4 stars appear on the website. Reviews below
 * 4 stars are always kept private (status "hidden"), wherever they come from.
 */
class MQP_Reviews {

	const OPT = 'mqp_reviews';

	/** Reviews below this are never shown on the website. */
	const MIN_STARS = 4;

	/** Website reviews accepted per day from everyone together, and waiting at most. */
	const DAY_MAX     = 50;
	const PENDING_MAX = 300;

	const STATUSES = array(
		'pending' => 'Waiting for approval',
		'shown'   => 'Shown on the website',
		'hidden'  => 'Hidden',
	);

	const SOURCES = array(
		'manual'  => 'Added by you',
		'website' => 'Website form',
		'google'  => 'Google',
	);

	public static function init() {
		add_shortcode( 'mq_reviews', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'mq_featured_review', array( __CLASS__, 'featured_shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function defaults() {
		return array(
			'auto_approve'  => 0,  // Website reviews appear right away (otherwise they wait for approval).
			'notify'        => 1,  // Email the admins about new website reviews.
			'thank'         => 1,  // Thank the customer by email (when they gave one).
			'google_key'    => '',
			'google_place'  => '',
			'google_auto'   => 1,  // New Google reviews are shown right away…
			'google_min'    => 4,  // …when they have at least this many stars.
			'google_rating' => 0,
			'google_count'  => 0,
			'google_url'    => '',
			'google_synced' => 0,
			'google_error'  => '',
			'featured'      => array(), // Weekly lineup (review ids). Empty: every shown 5-star review takes a turn.
			'feat_week'     => '',      // Week of the current featured review (e.g. 2026-W42)…
			'feat_id'       => 0,       // …and its id.
		);
	}

	public static function options() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
	}

	public static function save_options( array $values ) {
		update_option( self::OPT, array_intersect_key( array_merge( self::options(), $values ), self::defaults() ), false );
	}

	public static function routes() {
		register_rest_route(
			'mq/v1',
			'/public/review',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---------- Reading ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'reviews' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( array_keys( self::STATUSES ), 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . mqp_table( 'reviews' ) . ' GROUP BY status', ARRAY_A ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function pending_count() {
		return self::counts()['pending'];
	}

	/** Two letters for the picture circle when there is no photo. */
	public static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( array_filter( $parts ), 0, 2 ) as $p ) {
			$out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return $out ? $out : '?';
	}

	/** Star icons, filled up to $rating. */
	public static function stars( $rating, $size = 18 ) {
		$path = 'M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z';
		$out  = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$out .= '<svg class="' . ( $i <= $rating ? 'is-on' : 'is-off' ) . '" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $path . '"/></svg>';
		}
		return $out;
	}

	/** "3 days ago", "2 months ago"… */
	public static function ago( $datetime ) {
		$ts = strtotime( $datetime );
		return $ts ? sprintf( '%s ago', human_time_diff( $ts, strtotime( mqp_now() ) ) ) : '';
	}

	/* ---------- Saving ---------- */

	/** Adds or updates a review. $data: name, tagline, rating, body, photo, link, status, reviewed_at, source, ext_id, email, ip. */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$row = array();
		foreach ( array( 'source', 'ext_id', 'name', 'tagline', 'photo', 'link', 'body', 'email', 'status', 'ip', 'reviewed_at' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		if ( isset( $data['rating'] ) ) {
			$row['rating'] = max( 1, min( 5, (int) $data['rating'] ) );
		}
		if ( isset( $row['status'] ) && ! isset( self::STATUSES[ $row['status'] ] ) ) {
			$row['status'] = 'pending';
		}
		// Below 4 stars: kept private, never on the website.
		$rating = $row['rating'] ?? ( $id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT rating FROM ' . mqp_table( 'reviews' ) . ' WHERE id = %d', $id ) ) : 5 );
		if ( $rating < self::MIN_STARS && ( ! $id || isset( $row['status'] ) || isset( $row['rating'] ) ) ) {
			$row['status'] = 'hidden';
		}
		if ( $id ) {
			$wpdb->update( mqp_table( 'reviews' ), $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$row += array( 'reviewed_at' => mqp_now(), 'status' => 'pending', 'rating' => 5 );
		$row['created_at'] = mqp_now();
		$wpdb->insert( mqp_table( 'reviews' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$r = self::get( $id );
		if ( $r ) {
			self::delete_photo( $r['photo'] );
			$wpdb->delete( mqp_table( 'reviews' ), array( 'id' => (int) $id ) );
			if ( self::in_lineup( $id ) ) {
				self::set_lineup( $id, false );
			}
		}
	}

	/* ---------- Profile pictures ---------- */

	/** Public folder for review pictures (they are shown on the website). */
	private static function photo_dir() {
		$up = wp_upload_dir();
		return array( $up['basedir'] . '/mq-reviews', $up['baseurl'] . '/mq-reviews' );
	}

	/**
	 * Saves an uploaded picture as a small square JPEG and returns its URL, '' when none was sent,
	 * or WP_Error. Only real JPG, PNG or WebP images up to 3 MB.
	 */
	public static function store_photo( $file ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? 0 ) ) {
			return '';
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'mqp_photo', 'The picture could not be uploaded.' );
		}
		if ( (int) $file['size'] > 3 * MB_IN_BYTES ) {
			return new WP_Error( 'mqp_photo', 'The picture is too large (3 MB at most).' );
		}
		$room = MQP_Guard::allow_bytes( (int) $file['size'] );
		if ( is_wp_error( $room ) ) {
			return new WP_Error( 'mqp_photo', 'We can\'t accept pictures right now. Please send your review without one.' );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		if ( empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'mqp_photo', 'The picture must be a JPG, PNG or WebP image.' );
		}
		list( $dir, $url ) = self::photo_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mqp_photo', 'The picture could not be saved.' );
		}
		$name   = wp_generate_password( 20, false, false ) . '.jpg';
		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'mqp_photo', 'The picture could not be read.' );
		}
		$editor->resize( 240, 240, true );
		$editor->set_quality( 85 );
		$saved = $editor->save( $dir . '/' . $name, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'mqp_photo', 'The picture could not be saved.' );
		}
		MQP_Guard::add_bytes( (int) @filesize( $saved['path'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $url . '/' . basename( $saved['path'] );
	}

	/** Removes a picture saved by store_photo() (pictures from Google are links, nothing to remove). */
	public static function delete_photo( $photo_url ) {
		list( $dir, $url ) = self::photo_dir();
		if ( $photo_url && 0 === strpos( $photo_url, $url . '/' ) ) {
			$file = $dir . '/' . basename( $photo_url );
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/* ---------- "Leave a Review" on the website ---------- */

	public static function submit( WP_REST_Request $req ) {
		$s  = MQP_Settings::all();
		$o  = self::options();
		$ip = mqp_client_ip();

		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return new WP_Error( 'mqp_spam', 'Review rejected.', array( 'status' => 400 ) );
		}
		$consent = MQP_Privacy::check( $req->get_param( 'consent' ) );
		if ( $consent ) {
			return $consent;
		}
		// Limits: per connection per hour and per day, for everyone per day, and how many may wait.
		global $wpdb;
		$lim  = (int) MQP_Monitor::settings()['limit_reviews'];
		$busy = new WP_Error( 'mqp_rate', 'Thank you! You already sent a few reviews. Please try again later.', array( 'status' => 429 ) );
		if ( MQP_Guard::count( 'review' ) >= $lim || MQP_Guard::count( 'review_day' ) >= $lim * 2 ) {
			return $busy;
		}
		$t = mqp_table( 'reviews' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE source = 'website' AND created_at >= %s", wp_date( 'Y-m-d 00:00:00' ) ) ) >= self::DAY_MAX // phpcs:ignore WordPress.DB.PreparedSQL
			|| (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE source = 'website' AND status = 'pending'" ) >= self::PENDING_MAX ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return new WP_Error( 'mqp_rate', 'We are receiving many reviews right now. Please try again tomorrow. Thank you!', array( 'status' => 429 ) );
		}
		if ( MQP_Settings::turnstile_on() ) {
			$resp = wp_remote_post(
				'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				array(
					'body'    => array(
						'secret'   => $s['turnstile_secret'],
						'response' => (string) $req->get_param( 'cf-turnstile-response' ),
						'remoteip' => $ip,
					),
					'timeout' => 10,
				)
			);
			if ( is_wp_error( $resp ) || empty( json_decode( wp_remote_retrieve_body( $resp ), true )['success'] ) ) {
				return new WP_Error( 'mqp_captcha', 'Please complete the verification and try again.', array( 'status' => 400 ) );
			}
		}

		$name   = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$body   = sanitize_textarea_field( (string) $req->get_param( 'body' ) );
		$rating = (int) $req->get_param( 'rating' );
		$email  = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) {
			return new WP_Error( 'mqp_fields', 'Please enter your name.', array( 'status' => 400 ) );
		}
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'mqp_fields', 'Please choose a star rating.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) < 10 ) {
			return new WP_Error( 'mqp_fields', 'Please write a few words about your experience.', array( 'status' => 400 ) );
		}
		$body = mb_substr( $body, 0, 2000 );
		// The same text again (double click, copy-paste spam) is not saved twice.
		$same = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE body = %s AND created_at >= %s LIMIT 1", $body, wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $same ) {
			return new WP_Error( 'mqp_rate', 'This review was already sent. Thank you!', array( 'status' => 409 ) );
		}

		$photo = self::store_photo( $req->get_file_params()['photo'] ?? null );
		if ( is_wp_error( $photo ) ) {
			return new WP_Error( 'mqp_photo', $photo->get_error_message(), array( 'status' => 400 ) );
		}

		// Below 4 stars: private feedback for you, never on the website.
		$private = $rating < self::MIN_STARS;
		$status  = $private ? 'hidden' : ( $o['auto_approve'] ? 'shown' : 'pending' );
		$id     = self::save(
			array(
				'source' => 'website',
				'name'   => $name,
				'rating' => $rating,
				'body'   => $body,
				'email'  => $email,
				'photo'  => $photo,
				'ip'     => $ip,
				'status' => $status,
			)
		);
		MQP_Guard::bump( 'review', HOUR_IN_SECONDS );
		MQP_Guard::bump( 'review_day', DAY_IN_SECONDS );
		if ( 'shown' !== $status ) {
			MQP_Notify::review_received( $name, $rating, $private );
		}
		mqp_log( 'review_received', array( 'user_id' => 0, 'username' => $name, 'reason' => $rating . ' stars' . ( $private ? ' · kept private (below 4 stars)' : ( 'pending' === $status ? ' · waiting for approval' : '' ) ) ) );

		if ( $o['notify'] ) {
			self::email_admins( $id, $name, $rating, $body, $email, $status, $private );
		}
		if ( $o['thank'] && is_email( $email ) ) {
			self::email_thanks( $email, $name, $status, $private );
		}

		return array(
			'ok'      => true,
			'id'      => $id,
			'shown'   => 'shown' === $status,
			'message' => $private
				? 'Thank you for your feedback! We read every message and will use it to do better.'
				: ( 'shown' === $status
					? 'Thank you for your review! It is now on our website.'
					: 'Thank you for your review! It will appear on our website once we have checked it.' ),
		);
	}

	/** The admins' email about a new review: stars, the text, what happens next, one button. */
	private static function email_admins( $id, $name, $rating, $body, $email, $status, $private ) {
		// The portal's Reviews tab (staff never need wp-admin).
		$page = MQP_Portal::url() . '#/admin/reviews/' . ( $private ? 'hidden' : $status );
		if ( $private ) {
			$pill = MQP_Mail::pill( 'Below 4 stars · kept private', 'bad' );
			$next = 'It is never shown on the website. Read it as feedback, and contact the customer if you can.';
			$btn  = array( 'Read the feedback', $page );
		} elseif ( 'pending' === $status ) {
			$pill = MQP_Mail::pill( 'Waiting for your approval', 'warn' );
			$next = 'It appears on the website once you approve it.';
			$btn  = array( 'Approve or hide it', $page );
		} else {
			$pill = MQP_Mail::pill( 'Shown on the website', 'ok' );
			$next = 'It is already on the website (new reviews are shown right away in your settings).';
			$btn  = array( 'Manage reviews', $page );
		}
		$rows = array( array( 'From', $name ), array( 'Received', wp_date( 'M j, Y g:i A' ) ) );
		if ( is_email( $email ) ) {
			$rows[] = array( 'Email (not shown on the website)', $email );
		}
		MQP_Mail::send(
			MQP_Monitor::admin_to(),
			sprintf( 'New %d-star review from %s', $rating, $name ),
			'New review on your website',
			array( $pill, MQP_Mail::stars( $rating ), MQP_Mail::quote( $body ), MQP_Mail::table( $rows ), $next ),
			$btn
		);
	}

	/** Thank-you email to the customer who left the review. */
	private static function email_thanks( $email, $name, $status, $private ) {
		$first = strtok( trim( $name ), ' ' );
		$text  = $private
			? 'Thank you for taking the time to tell us how we did. We read every message, and your feedback helps us do better. If you would like us to contact you about it, just reply to this email.'
			: ( 'shown' === $status
				? 'Thank you for your review! It is now on our website, where it helps other customers choose us.'
				: 'Thank you for your review! We will put it on our website once we have checked it.' );
		$site = MQP_Domain::to_main( home_url( '/' ) );
		MQP_Mail::send( $email, 'Thank you for your review, ' . $first . '!', 'Thank you, ' . $first . '!', array( $text, 'We hope to print for you again soon.' ), array( 'Visit our website', $site ) );
	}

	/* ---------- Google reviews ---------- */

	/**
	 * Fetches the business's Google rating and reviews (Places API; Google gives the 5 most
	 * relevant reviews). New ones are shown right away when they meet the minimum stars
	 * (wp-admin → Reviews → Google), others wait. Reviews already here keep their status.
	 * Returns the number of new reviews or WP_Error.
	 */
	public static function google_sync() {
		global $wpdb;
		$o = self::options();
		if ( ! $o['google_key'] || ! $o['google_place'] ) {
			return new WP_Error( 'mqp_google', 'Add the Google API key and Place ID first.' );
		}
		$res = wp_remote_get(
			'https://places.googleapis.com/v1/places/' . rawurlencode( $o['google_place'] ) . '?languageCode=en',
			array(
				'timeout' => 20,
				'headers' => array(
					'X-Goog-Api-Key'   => $o['google_key'],
					'X-Goog-FieldMask' => 'rating,userRatingCount,googleMapsUri,reviews',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::google_failed( 'Could not reach Google: ' . $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$msg = $data['error']['message'] ?? ( 'Google answered with error ' . wp_remote_retrieve_response_code( $res ) );
			return self::google_failed( 'Google: ' . $msg );
		}

		$new = 0;
		foreach ( (array) ( $data['reviews'] ?? array() ) as $g ) {
			$ext = substr( (string) ( $g['name'] ?? '' ), 0, 191 );
			if ( '' === $ext ) {
				continue;
			}
			$text = (string) ( $g['originalText']['text'] ?? ( $g['text']['text'] ?? '' ) );
			$ts   = strtotime( (string) ( $g['publishTime'] ?? '' ) );
			$row  = array(
				'source'      => 'google',
				'ext_id'      => $ext,
				'name'        => sanitize_text_field( (string) ( $g['authorAttribution']['displayName'] ?? 'Google user' ) ),
				'photo'       => esc_url_raw( (string) ( $g['authorAttribution']['photoUri'] ?? '' ) ),
				'link'        => esc_url_raw( (string) ( $g['authorAttribution']['uri'] ?? '' ) ),
				'rating'      => (int) ( $g['rating'] ?? 5 ),
				'body'        => sanitize_textarea_field( $text ),
				'reviewed_at' => $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : mqp_now(),
			);
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'reviews' ) . ' WHERE ext_id = %s', $ext ) );
			if ( $id ) {
				self::save( $row, $id ); // A rating changed to below 4 stars hides it (see save()).
				continue;
			}
			// Star-only reviews (no text) wait, so the website never shows an empty card.
			$row['status'] = $row['rating'] < self::MIN_STARS ? 'hidden' : ( $o['google_auto'] && $row['rating'] >= max( self::MIN_STARS, (int) $o['google_min'] ) && '' !== trim( $text ) ? 'shown' : 'pending' );
			self::save( $row );
			$new++;
		}
		self::save_options(
			array(
				'google_rating' => (float) ( $data['rating'] ?? 0 ),
				'google_count'  => (int) ( $data['userRatingCount'] ?? 0 ),
				'google_url'    => esc_url_raw( (string) ( $data['googleMapsUri'] ?? '' ) ),
				'google_synced' => time(),
				'google_error'  => '',
			)
		);
		return $new;
	}

	private static function google_failed( $message ) {
		self::save_options( array( 'google_error' => $message, 'google_synced' => time() ) );
		MQP_Monitor::record( 'background', 'Google reviews could not be updated', $message );
		return new WP_Error( 'mqp_google', $message );
	}

	/** Daily job: refresh Google reviews when it is set up, and move on to the next featured review on Mondays. */
	public static function daily() {
		$o = self::options();
		if ( $o['google_key'] && $o['google_place'] ) {
			self::google_sync();
		}
		self::featured();
	}

	/* ---------- Featured review (one per week) ---------- */

	/** This week, Monday to Sunday in the site's time zone, e.g. "2026-W42". */
	public static function week_key() {
		return wp_date( 'o-\WW' );
	}

	/** Review ids that take turns, oldest first: the chosen lineup, or else all shown 5-star reviews (or 4-star when there are none). */
	public static function lineup( &$chosen = null ) {
		global $wpdb;
		$t      = mqp_table( 'reviews' );
		$base   = "status = 'shown' AND rating >= " . (int) self::MIN_STARS;
		$ids    = array_filter( array_map( 'intval', (array) self::options()['featured'] ) );
		$chosen = false;
		if ( $ids ) {
			$rows = $wpdb->get_col( "SELECT id FROM $t WHERE $base AND id IN (" . implode( ',', $ids ) . ') ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $rows ) {
				$chosen = true;
				return array_map( 'intval', $rows );
			}
		}
		$rows = $wpdb->get_col( "SELECT id FROM $t WHERE $base AND rating = 5 ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			$rows = $wpdb->get_col( "SELECT id FROM $t WHERE $base ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return array_map( 'intval', $rows );
	}

	public static function in_lineup( $id ) {
		return in_array( (int) $id, array_map( 'intval', (array) self::options()['featured'] ), true );
	}

	/**
	 * The featured review of this week. It stays the same all week; each new week the next one in
	 * the lineup takes over. If this week's review is hidden or deleted, the next one takes over now.
	 */
	public static function featured() {
		$o    = self::options();
		$line = self::lineup( $chosen );
		if ( ! $line ) {
			return null;
		}
		$week = self::week_key();
		$cur  = (int) $o['feat_id'];
		if ( $o['feat_week'] === $week && $cur ) {
			$r = self::get( $cur );
			if ( $r && 'shown' === $r['status'] && (int) $r['rating'] >= self::MIN_STARS && ( ! $chosen || in_array( $cur, $line, true ) ) ) {
				return $r;
			}
		}
		$next = $line[0];
		foreach ( $line as $id ) {
			if ( $id > $cur ) {
				$next = $id;
				break;
			}
		}
		self::set_featured( $next );
		return self::get( $next );
	}

	/** Makes $id this week's featured review. */
	public static function set_featured( $id ) {
		$o = self::options();
		if ( (int) $o['feat_id'] === (int) $id && $o['feat_week'] === self::week_key() ) {
			return;
		}
		self::save_options( array( 'feat_week' => self::week_key(), 'feat_id' => (int) $id ) );
		self::purge_cache();
	}

	/** Adds or removes a review from the weekly lineup. */
	public static function set_lineup( $id, $on ) {
		$ids = array_values( array_diff( array_map( 'intval', (array) self::options()['featured'] ), array( (int) $id ) ) );
		if ( $on ) {
			$ids[] = (int) $id;
		}
		self::save_options( array( 'featured' => $ids ) );
		self::purge_cache();
	}

	/** Cached website pages would keep last week's review: clear the page cache when it changes. */
	public static function purge_cache() {
		mqp_purge_page_cache();
	}

	/**
	 * [mq_featured_review label="" link="" link_text="See all reviews"]
	 * One review card (quote mark, text, picture, name, the line under the name, stars), for a
	 * section such as "Have questions? Contact us". A new review every week. Transparent around the
	 * card, so it sits on the section's own background; it fills the column it is put in (at most 420px).
	 * label="Review of the week" adds a small label on top. Shows nothing when there is no review yet.
	 */
	public static function featured_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'label' => '', 'link' => '', 'link_text' => 'See all reviews' ), $atts );
		$r    = self::featured();
		if ( ! $r ) {
			return '';
		}
		$label  = in_array( strtolower( (string) $atts['label'] ), array( 'no', '0', 'false', 'off', '' ), true ) ? '' : (string) $atts['label'];
		$quote  = '<svg class="mqr-fquote" viewBox="0 0 48 48" width="46" height="46" aria-hidden="true"><path d="M20 10c-7 2.6-12 9.3-12 17.4V38h12V26h-6c0-5 3-9.3 7.4-11zM40 10c-7 2.6-12 9.3-12 17.4V38h12V26h-6c0-5 3-9.3 7.4-11z" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linejoin="round"/></svg>';
		$under  = '' !== trim( (string) $r['tagline'] ) ? $r['tagline'] : ( 'google' === $r['source'] ? 'Google review' : self::ago( $r['reviewed_at'] ) );
		ob_start();
		?>
		<section class="mqr mqr-feat" aria-label="Featured customer review">
			<figure class="mqr-fcard">
				<?php if ( $label ) : ?>
					<span class="mqr-flabel"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
				<?php echo $quote; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?>
				<blockquote class="mqr-ftext"><?php echo wpautop( esc_html( $r['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></blockquote>
				<figcaption class="mqr-who mqr-fwho">
					<span class="mqr-avatar">
						<?php if ( $r['photo'] ) : ?>
							<img src="<?php echo esc_url( $r['photo'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
						<?php endif; ?>
						<span class="mqr-initials"<?php echo $r['photo'] ? ' hidden' : ''; ?>><?php echo esc_html( self::initials( $r['name'] ) ); ?></span>
					</span>
					<span class="mqr-meta">
						<?php if ( 'google' === $r['source'] && $r['link'] ) : ?>
							<a class="mqr-name" href="<?php echo esc_url( $r['link'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $r['name'] ); ?></a>
						<?php else : ?>
							<strong class="mqr-name"><?php echo esc_html( $r['name'] ); ?></strong>
						<?php endif; ?>
						<span class="mqr-stars" role="img" aria-label="<?php echo esc_attr( (int) $r['rating'] . ' out of 5 stars' ); ?>"><?php echo self::stars( (int) $r['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php if ( $under ) : ?>
							<small><?php echo esc_html( $under ); ?></small>
						<?php endif; ?>
					</span>
				</figcaption>
			</figure>
			<?php if ( $atts['link'] ) : ?>
				<p class="mqr-flink"><a href="<?php echo esc_url( $atts['link'] ); ?>"><?php echo esc_html( $atts['link_text'] ); ?> →</a></p>
			<?php endif; ?>
		</section>
		<?php
		return self::css() . ob_get_clean();
	}

	/** The reviews stylesheet, printed once per page (with whichever review shortcode comes first). */
	private static function css() {
		static $styled = false;
		if ( $styled ) {
			return '';
		}
		$styled = true;
		return '<style id="mqp-reviews-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/reviews.css' ) ) . '</style>'; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------- [mq_reviews] ---------- */

	/**
	 * [mq_reviews limit="12" source="google" button="Leave a Review" summary="yes"]
	 * source: all (default), google, manual or website. button="no" hides the button.
	 */
	public static function shortcode( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'limit' => 12, 'source' => '', 'button' => 'Leave a Review', 'summary' => 'yes' ), $atts );
		$o    = self::options();
		$s    = MQP_Settings::all();

		$where = "status = 'shown' AND rating >= " . (int) self::MIN_STARS;
		if ( isset( self::SOURCES[ $atts['source'] ] ) ) {
			$where .= $wpdb->prepare( ' AND source = %s', $atts['source'] );
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . mqp_table( 'reviews' ) . " WHERE $where ORDER BY reviewed_at DESC, id DESC LIMIT " . max( 1, min( 50, (int) $atts['limit'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$button = in_array( strtolower( (string) $atts['button'] ), array( 'no', '0', 'false', 'off', '' ), true ) ? '' : (string) $atts['button'];
		$google = '<svg class="mqr-g" viewBox="0 0 48 48" width="16" height="16" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
		$quote  = '<svg class="mqr-quote" viewBox="0 0 48 48" width="40" height="40" aria-hidden="true"><path d="M20 10c-7 2.6-12 9.3-12 17.4V38h12V26h-6c0-5 3-9.3 7.4-11zM40 10c-7 2.6-12 9.3-12 17.4V38h12V26h-6c0-5 3-9.3 7.4-11z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>';
		$arrow  = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';

		wp_enqueue_script( 'mqp-reviews', MQP_URL . 'assets/reviews.js', array(), mqp_asset_version(), true );

		ob_start();
		?>
		<section class="mqr" id="mq-reviews" data-endpoint="<?php echo esc_url( rest_url( 'mq/v1/public/review' ) ); ?>" aria-label="Customer reviews">
			<?php if ( 'yes' === $atts['summary'] && $o['google_rating'] && $o['google_count'] ) : ?>
				<a class="mqr-summary" href="<?php echo esc_url( $o['google_url'] ? $o['google_url'] : 'https://www.google.com/maps' ); ?>" target="_blank" rel="noopener">
					<?php echo $google; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?>
					<strong><?php echo esc_html( number_format( (float) $o['google_rating'], 1 ) ); ?></strong>
					<span class="mqr-stars"><?php echo self::stars( (int) round( $o['google_rating'] ), 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span><?php echo esc_html( sprintf( _n( '%s review on Google', '%s reviews on Google', (int) $o['google_count'] ), number_format_i18n( (int) $o['google_count'] ) ) ); ?></span>
				</a>
			<?php endif; ?>

			<?php if ( $rows ) : ?>
			<div class="mqr-stage">
				<button type="button" class="mqr-nav mqr-prev" aria-label="Previous reviews"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				<div class="mqr-track" role="list" tabindex="0">
					<?php foreach ( $rows as $r ) : ?>
					<article class="mqr-card" role="listitem">
						<div class="mqr-top">
							<?php echo $quote; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( 'google' === $r['source'] ) : ?>
								<span class="mqr-src" title="Review on Google"><?php echo $google; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<?php endif; ?>
						</div>
						<div class="mqr-text"><?php echo wpautop( esc_html( $r['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></div>
						<button type="button" class="mqr-more" hidden>Read more</button>
						<footer class="mqr-who">
							<span class="mqr-avatar">
								<?php if ( $r['photo'] ) : ?>
									<img src="<?php echo esc_url( $r['photo'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
								<?php endif; ?>
								<span class="mqr-initials"<?php echo $r['photo'] ? ' hidden' : ''; ?>><?php echo esc_html( self::initials( $r['name'] ) ); ?></span>
							</span>
							<span class="mqr-meta">
								<?php if ( 'google' === $r['source'] && $r['link'] ) : ?>
									<a class="mqr-name" href="<?php echo esc_url( $r['link'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $r['name'] ); ?></a>
								<?php else : ?>
									<strong class="mqr-name"><?php echo esc_html( $r['name'] ); ?></strong>
								<?php endif; ?>
								<span class="mqr-stars" role="img" aria-label="<?php echo esc_attr( (int) $r['rating'] . ' out of 5 stars' ); ?>"><?php echo self::stars( (int) $r['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<small><?php echo esc_html( self::ago( $r['reviewed_at'] ) ); ?></small>
							</span>
						</footer>
					</article>
					<?php endforeach; ?>
				</div>
				<button type="button" class="mqr-nav mqr-next" aria-label="Next reviews"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
			</div>
			<div class="mqr-dots" role="tablist" aria-label="Review pages"></div>
			<?php else : ?>
				<p class="mqr-empty">No reviews yet. Be the first to tell others about us!</p>
			<?php endif; ?>

			<?php if ( $button ) : ?>
			<div class="mqr-cta"><button type="button" class="mqr-open"><?php echo esc_html( $button ); ?></button></div>

			<div class="mqr-modal" role="dialog" aria-modal="true" aria-labelledby="mqr-title" hidden>
				<form class="mqr-box" novalidate enctype="multipart/form-data">
					<button type="button" class="mqr-close" aria-label="Close">&times;</button>
					<div class="mqr-step">
						<h3 class="mqr-title" id="mqr-title">Leave a review</h3>
						<p class="mqr-sub">How was your experience with <?php echo esc_html( $s['company_name'] ? $s['company_name'] : get_bloginfo( 'name' ) ); ?>?</p>
						<div class="mqr-rate" role="radiogroup" aria-label="Your rating">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<button type="button" role="radio" aria-checked="false" data-v="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( $i . ( 1 === $i ? ' star' : ' stars' ) ); ?>"><svg viewBox="0 0 24 24" width="38" height="38" aria-hidden="true"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg></button>
							<?php endfor; ?>
						</div>
						<p class="mqr-rate-label" aria-live="polite">Tap a star to rate</p>
						<input type="hidden" name="rating" value="">
						<label><span>Your name <em>*</em></span><input type="text" name="name" maxlength="80" autocomplete="name" placeholder="e.g. Juan Dela Cruz" required></label>
						<label><span>Your review <em>*</em></span><textarea name="body" rows="4" maxlength="2000" placeholder="What did you order? How were the quality and the service?" required></textarea></label>
						<label><span>Email <small>(optional, never shown)</small></span><input type="email" name="email" maxlength="150" autocomplete="email" placeholder="you@email.com"></label>
						<div class="mqr-photo">
							<span class="mqr-photo-preview" aria-hidden="true"><img alt="" hidden><span class="mqr-photo-icon">+</span></span>
							<label class="mqr-photo-pick"><span>Profile picture <small>(optional)</small></span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small class="mqr-photo-hint">JPG, PNG or WebP, up to 3 MB</small></label>
							<button type="button" class="mqr-photo-remove" hidden>Remove</button>
						</div>
						<input type="text" name="website" class="mqr-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<?php echo MQP_Privacy::checkbox( 'mqr' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
						<?php if ( MQP_Settings::turnstile_on() ) : ?>
							<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></div>
						<?php endif; ?>
						<p class="mqr-msg" role="alert"></p>
						<button type="submit" class="mqr-submit">Send review</button>
					</div>
					<div class="mqr-done" hidden>
						<span class="mqr-done-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
						<strong>Thank you!</strong>
						<p class="mqr-done-text"></p>
						<button type="button" class="mqr-close-done">Close</button>
					</div>
				</form>
			</div>
			<?php endif; ?>
		</section>
		<?php
		$html = ob_get_clean();

		// Printed with the carousel (once per page), so it never depends on a stylesheet a theme or
		// caching plugin might skip — same as [mq_upload].
		$html = self::css() . $html;
		static $inline = false;
		if ( ! $inline && ( did_action( 'wp_print_footer_scripts' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			$inline = true;
			$html  .= '<script src="' . esc_url( MQP_URL . 'assets/reviews.js?ver=' . rawurlencode( mqp_asset_version() ) ) . '" defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources
		}
		return $html;
	}
}
