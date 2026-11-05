<?php
defined( 'ABSPATH' ) || exit;

/** Full table name, e.g. mqp_table( 'jobs' ) → wp_mq_jobs. */
function mqp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'mq_' . $name;
}

/** Plugin version plus a fingerprint of the newest asset file, so any edited file busts browser caches. */
function mqp_asset_version() {
	static $version = null;
	if ( null === $version ) {
		$latest = 0;
		$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( MQP_DIR . 'assets', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$latest = max( $latest, $file->getMTime() );
		}
		$version = MQP_VERSION . '-' . base_convert( (string) $latest, 10, 36 );
	}
	return $version;
}

/** Current site-local time (Settings → General → Timezone) as MySQL datetime. */
function mqp_now() {
	return current_time( 'mysql' );
}

/** Today's date in site time, Y-m-d. */
function mqp_today() {
	return current_time( 'Y-m-d' );
}

/** Converts an ISO-8601 timestamp from a device into site-local MySQL datetime. */
function mqp_local_datetime( $iso ) {
	if ( empty( $iso ) ) {
		return mqp_now();
	}
	try {
		$dt = new DateTimeImmutable( $iso );
		return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	} catch ( Exception $e ) {
		return mqp_now();
	}
}

function mqp_money( $value ) {
	return round( (float) $value, 2 );
}

function mqp_is_uuid( $value ) {
	return is_string( $value ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
}

function mqp_valid_date( $value ) {
	return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
}

/**
 * Visitor IP. REMOTE_ADDR only by default; set MQP_TRUST_PROXY_HEADER to a header name
 * (e.g. 'HTTP_CF_CONNECTING_IP') in wp-config.php when the site sits behind Cloudflare.
 */
function mqp_client_ip() {
	if ( defined( 'MQP_TRUST_PROXY_HEADER' ) && ! empty( $_SERVER[ MQP_TRUST_PROXY_HEADER ] ) ) {
		$ip = trim( explode( ',', wp_unslash( $_SERVER[ MQP_TRUST_PROXY_HEADER ] ) )[0] );
	} else {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	}
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/** Distance in metres between two lat/lng points. */
function mqp_distance_m( $lat1, $lng1, $lat2, $lng2 ) {
	$r    = 6371000;
	$dlat = deg2rad( $lat2 - $lat1 );
	$dlng = deg2rad( $lng2 - $lng1 );
	$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
	return 2 * $r * asin( min( 1, sqrt( $a ) ) );
}

/** Basic user info for API responses. */
function mqp_user_brief( $user_id ) {
	if ( ! $user_id ) {
		return null;
	}
	$u = get_userdata( $user_id );
	if ( $u ) {
		return array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'avatar' => MQP_Avatar::version( $u->ID ) );
	}
	$name = mqp_deleted_name( $user_id );
	return $name ? array( 'id' => (int) $user_id, 'name' => $name, 'avatar' => 0 ) : null;
}

/** "Name (deleted)" for a staff account deleted in the portal, or ''. */
function mqp_deleted_name( $user_id ) {
	$names = (array) get_option( 'mqp_deleted_users', array() );
	return isset( $names[ $user_id ] ) ? $names[ $user_id ] . ' (deleted)' : '';
}

/** Casts numeric-string columns in DB rows for cleaner JSON. */
function mqp_cast_row( $row, $ints = array(), $floats = array() ) {
	if ( ! $row ) {
		return $row;
	}
	$row = (array) $row;
	foreach ( $ints as $k ) {
		if ( array_key_exists( $k, $row ) && null !== $row[ $k ] ) {
			$row[ $k ] = (int) $row[ $k ];
		}
	}
	foreach ( $floats as $k ) {
		if ( array_key_exists( $k, $row ) && null !== $row[ $k ] ) {
			$row[ $k ] = (float) $row[ $k ];
		}
	}
	return $row;
}

/** Writes an entry to the audit log. */
function mqp_log( $event, $data = array() ) {
	global $wpdb;
	$wpdb->insert(
		mqp_table( 'audit_log' ),
		array(
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : get_current_user_id(),
			'username'   => isset( $data['username'] ) ? substr( (string) $data['username'], 0, 60 ) : '',
			'event'      => $event,
			'result'     => isset( $data['result'] ) ? $data['result'] : 'ok',
			'reason'     => isset( $data['reason'] ) ? substr( (string) $data['reason'], 0, 255 ) : '',
			'ip'         => mqp_client_ip(),
			'lat'        => isset( $data['lat'] ) ? $data['lat'] : null,
			'lng'        => isset( $data['lng'] ) ? $data['lng'] : null,
			'created_at' => mqp_now(),
		)
	);
}

/**
 * Clears the website's page cache (LiteSpeed, and WP Super Cache / W3TC when used), so pages
 * show new prices, reviews… right away instead of the cached copy.
 */
function mqp_purge_page_cache() {
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
}
