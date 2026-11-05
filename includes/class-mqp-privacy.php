<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data Privacy Act (RA 10173): consent on every form that collects personal data, and removing
 * a customer's personal data when they ask.
 *
 * - The upload form, Get Quotation, the online order page and "Leave a Review" show a required
 *   "I agree…" box with a link to the Privacy Policy (WordPress → Settings → Privacy), and the
 *   server refuses requests without it.
 * - Sales → Customers → (customer) → "Remove personal data", or WordPress's own Tools → Erase
 *   Personal Data (by email), removes their contact details, files and reviews. Invoices keep the
 *   name, number and amounts, which the BIR requires the business to keep.
 */
class MQP_Privacy {

	const REMOVED = 'Removed customer';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/** The Privacy Policy page: the one chosen in WordPress → Settings → Privacy, else the published /privacy-policy/ page. */
	public static function url() {
		$url = (string) get_privacy_policy_url();
		if ( '' === $url ) {
			$page = get_page_by_path( 'privacy-policy' );
			if ( $page && 'publish' === $page->post_status ) {
				$url = (string) get_permalink( $page );
			}
		}
		return $url;
	}

	/** The consent sentence; $link_html true: "Privacy Policy" is a link (when one is set). */
	public static function text( $link_html = true ) {
		$s    = MQP_Settings::all();
		$who  = $s['company_name'] ? $s['company_name'] : get_bloginfo( 'name' );
		$url  = self::url();
		$name = $link_html && $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Privacy Policy</a>' : 'Privacy Policy';
		return 'I agree that ' . esc_html( $who ) . ' uses my details to handle my request, as explained in the ' . $name . '.';
	}

	/** Required checkbox for a form; $class is the form's own prefix (mqp, mqq, mqr). */
	public static function checkbox( $class ) {
		return '<label class="' . esc_attr( $class ) . '-consent"><input type="checkbox" name="consent" value="1" required> <span>' . self::text() . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/** null when the customer agreed, else the error to return. */
	public static function check( $value ) {
		if ( rest_sanitize_boolean( $value ) ) {
			return null;
		}
		return new WP_Error( 'mqp_consent', 'Please tick the box to agree to how we use your details (Privacy Policy).', array( 'status' => 400 ) );
	}

	/* ---------- Removing a customer's personal data ---------- */

	/**
	 * Removes the personal data of customer $id (and of uploads, orders and reviews with the same
	 * email or mobile). Returns counts of what was changed.
	 */
	public static function erase_customer( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $c ) {
			return new WP_Error( 'mqp_privacy', 'Customer not found.' );
		}
		$out = self::erase_contact( $c['email'], $c['contact'], array( (int) $c['id'] ) );
		$out['archived'] = array_values( self::$archives );
		mqp_log( 'customer_erased', array( 'reason' => 'Customer #' . (int) $c['id'] . ' · ' . $out['documents'] . ' documents, ' . $out['uploads'] . ' uploads, ' . $out['orders'] . ' orders, ' . $out['files'] . ' files, ' . $out['reviews'] . ' reviews' ) );
		return $out;
	}

	/** Same, found by email (WordPress's Erase Personal Data tool) and/or mobile number. */
	public static function erase_contact( $email, $phone = '', array $customer_ids = array() ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		$phone = trim( (string) $phone );
		$c     = mqp_table( 'customers' );
		$d     = mqp_table( 'documents' );
		$out   = array( 'documents' => 0, 'uploads' => 0, 'orders' => 0, 'files' => 0, 'reviews' => 0 );

		if ( $email ) {
			$customer_ids = array_merge( $customer_ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $c WHERE LOWER(email) = %s", $email ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$customer_ids = array_values( array_unique( array_filter( $customer_ids ) ) );

		// Documents: contact details go; invoices keep the name (BIR), others get "Removed customer".
		if ( $customer_ids ) {
			$in   = implode( ',', $customer_ids );
			$docs = $wpdb->get_results( "SELECT id, type, job_id FROM $d WHERE customer_id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $docs as $doc ) {
				$row = array( 'customer_id' => null, 'customer_contact' => '', 'customer_address' => '', 'public_token' => '', 'updated_at' => mqp_now() );
				if ( 'invoice' !== $doc['type'] ) {
					$row['customer_name']    = self::REMOVED;
					$row['customer_company'] = '';
				}
				$wpdb->update( $d, $row, array( 'id' => (int) $doc['id'] ) );
				if ( $doc['job_id'] ) {
					$wpdb->update( mqp_table( 'jobs' ), array( 'customer_contact' => '' ), array( 'id' => (int) $doc['job_id'] ) );
				}
				$out['documents']++;
			}
			$wpdb->query( "DELETE FROM $c WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Uploads: their files are deleted (files already attached to a job order stay with it).
		$where = self::where( 'email', 'contact', $email, $phone );
		if ( $where ) {
			foreach ( $wpdb->get_col( 'SELECT id FROM ' . mqp_table( 'uploads' ) . " WHERE $where" ) as $uid ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$out['files'] += self::delete_files( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE upload_id = %d AND job_id IS NULL', $uid ), ARRAY_A ) );
				$wpdb->update( mqp_table( 'uploads' ), array( 'name' => self::REMOVED, 'contact' => '', 'email' => '', 'notes' => '', 'ip' => '' ), array( 'id' => (int) $uid ) );
				$out['uploads']++;
			}
		}

		// Online orders: contact, address and payment proof go; the order and its amounts stay.
		$where = self::where( 'customer_email', 'customer_phone', $email, $phone );
		if ( $where ) {
			foreach ( $wpdb->get_results( 'SELECT id, payment_proof_id FROM ' . mqp_table( 'orders' ) . " WHERE $where", ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $o['payment_proof_id'] ) {
					$out['files'] += self::delete_files( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE id = %d', $o['payment_proof_id'] ), ARRAY_A ) );
				}
				$wpdb->update( mqp_table( 'orders' ), array( 'customer_name' => self::REMOVED, 'customer_phone' => '', 'customer_email' => '', 'delivery_location' => '', 'notes' => '', 'ip' => '', 'payment_proof_id' => null ), array( 'id' => (int) $o['id'] ) );
				$out['orders']++;
			}
		}

		// Reviews they sent with this email.
		if ( $email ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'reviews' ) . ' WHERE LOWER(email) = %s', $email ) ) as $rid ) {
				MQP_Reviews::delete( (int) $rid );
				$out['reviews']++;
			}
		}
		return $out;
	}

	private static function where( $email_col, $phone_col, $email, $phone ) {
		global $wpdb;
		$parts = array();
		if ( $email ) {
			$parts[] = $wpdb->prepare( "LOWER($email_col) = %s", $email ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $phone ) {
			$parts[] = $wpdb->prepare( "$phone_col = %s", $phone ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '';
	}

	/** Archives (zips on the admin's PC) that still hold files of the customer being erased. */
	private static $archives = array();

	private static function delete_files( array $rows ) {
		global $wpdb;
		foreach ( $rows as $f ) {
			if ( ! empty( $f['archived_at'] ) ) {
				self::$archives[ (int) $f['archive_id'] ] = MQP_Archive::name( (int) $f['archive_id'] );
			}
			wp_delete_file( MQP_Files::base_dir() . '/' . $f['stored_path'] );
			$wpdb->delete( mqp_table( 'files' ), array( 'id' => (int) $f['id'] ) );
		}
		return count( $rows );
	}

	/* ---------- WordPress → Tools → Erase Personal Data ---------- */

	public static function register_eraser( $erasers ) {
		$erasers['mq-portal'] = array(
			'eraser_friendly_name' => 'MQ Portal (customers, uploads, orders, reviews)',
			'callback'             => array( __CLASS__, 'wp_eraser' ),
		);
		return $erasers;
	}

	public static function wp_eraser( $email, $page = 1 ) {
		$n = array_sum( self::erase_contact( $email ) );
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => $n ? array( 'MQ Portal: contact details, files and reviews removed. Invoices keep the name and amounts (BIR records).' ) : array(),
			'done'           => true,
		);
	}
}
