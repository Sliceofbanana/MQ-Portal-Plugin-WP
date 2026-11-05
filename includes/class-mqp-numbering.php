<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sequential numbers such as EP-INV-2026-00012 or JO-2026-00034.
 * The columns holding these numbers are UNIQUE, and assign() retries on a clash,
 * so two simultaneous requests can never end up with the same number.
 */
class MQP_Numbering {

	const DOC_CODES = array(
		'quotation' => 'Q',
		'billing'   => 'BS',
		'invoice'   => 'INV',
	);

	/**
	 * The next number of a series, safe when many devices ask at the same moment: each caller
	 * claims a number with a compare-and-swap (only one can move the counter from n to n+1), so
	 * two documents never get the same number and none is skipped. Works on MySQL/MariaDB and
	 * SQLite. Returns 0 only if the database keeps refusing (then the caller retries or fails).
	 */
	public static function next( $name ) {
		global $wpdb;
		$table = mqp_table( 'counters' );
		// Start the series at 0 if it is new ("already exists" is fine).
		$wpdb->suppress_errors( true );
		$wpdb->insert( $table, array( 'name' => $name, 'value' => 0 ) );
		$wpdb->suppress_errors( false );
		for ( $i = 0; $i < 200; $i++ ) {
			$cur = (int) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM $table WHERE name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$won = $wpdb->query( $wpdb->prepare( "UPDATE $table SET value = %d WHERE name = %s AND value = %d", $cur + 1, $name, $cur ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 1 === (int) $won ) {
				return $cur + 1;
			}
			usleep( wp_rand( 2000, 15000 ) ); // Someone else took that number: try the next one.
		}
		return 0;
	}

	/**
	 * EP-M1-INV-2026-00001: one continuous series per brand, branch, type and year. Brands without
	 * branches (or documents without a branch, e.g. from the website) use the brand's own series:
	 * GC-INV-2026-00001.
	 */
	public static function document_number( $type, $brand_code, $branch_code = '' ) {
		$year   = current_time( 'Y' );
		$code   = self::DOC_CODES[ $type ];
		$branch = $branch_code ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $branch_code ) ) : '';
		$seq    = self::next( $branch ? "doc_{$brand_code}_{$branch}_{$code}_{$year}" : "doc_{$brand_code}_{$code}_{$year}" );
		return $branch ? sprintf( '%s-%s-%s-%s-%05d', $brand_code, $branch, $code, $year, $seq ) : sprintf( '%s-%s-%s-%05d', $brand_code, $code, $year, $seq );
	}

	/** POS sales: EP-M1-POS-2026-000001, one continuous series per branch and year, given by the server. */
	public static function pos_number( $brand_code, $branch_code ) {
		$year   = current_time( 'Y' );
		$branch = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $branch_code ) );
		return sprintf( '%s-%s-POS-%s-%06d', $brand_code, $branch, $year, self::next( "pos_{$brand_code}_{$branch}_{$year}" ) );
	}

	public static function job_number() {
		$year = current_time( 'Y' );
		return sprintf( 'JO-%s-%05d', $year, self::next( "job_$year" ) );
	}

	/**
	 * Writes a freshly generated number into $table.$column for row $id, retrying on a clash.
	 *
	 * @param callable $generate Returns a new candidate number.
	 */
	public static function assign( $table, $column, $id, callable $generate ) {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$number = $generate();
			$wpdb->suppress_errors( true );
			$ok = $wpdb->update( $table, array( $column => $number ), array( 'id' => $id ) );
			$wpdb->suppress_errors( false );
			if ( false !== $ok ) {
				return $number;
			}
		}
		return null;
	}
}
