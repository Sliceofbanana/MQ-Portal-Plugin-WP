<?php
defined( 'ABSPATH' ) || exit;

class MQP_Settings {

	const OPTION = 'mqp_settings';

	public static function defaults() {
		return array(
			'company_name'        => 'MQ Printing Services',
			'company_address'     => 'Cebu City, Philippines',
			'company_contact'     => 'info@mqprintsph.com',
			'company_tin'         => '',
			'billing_terms'       => "A requirement of 60% downpayment before production starts.\nBalance is payable upon delivery/pick-up.",
			'quotation_terms'     => "This is an estimate only. Final price may change once quantity and design are confirmed.\nValid for 15 days.",
			'invoice_terms'       => 'Thank you for your business!',
			'receipt_footer'      => "Thank you! Come again.\nThis is not an official receipt.",
			'downpayment_percent' => 60,
			'commission_rate'     => 0,     // Default % of invoice total; brands can override.
			// Payment needed before a job order leaves Sales: none | any | downpayment | full.
			'gate_payment'        => 'any',
			// Customers' quotation links stop working this many days after the order is completed,
			// cancelled or the quotation is no longer valid (0 = never).
			'quote_link_days'     => 90,
			// "Get Quotation" on the website offers only MQ Printing Services (the other businesses are not open yet).
			'quote_main_only'     => true,

			'att_window_enabled'  => false,
			'att_window_start'    => '19:00',
			'att_window_end'      => '06:00',
			'att_require_location'=> true,  // Staff must be at a shop (shop Wi-Fi or GPS) to clock in.
			'att_auto_close_hours'=> 16,

			'upload_max_mb'       => 25,
			'upload_types'        => 'pdf,png,jpg,jpeg',
			'upload_rate_per_hour'=> 10,
			'turnstile_site_key'  => '',
			'turnstile_secret'    => '',
			'upload_success_text' => 'Thank you! We received your files. Our team will contact you shortly.',

			'order_enabled'       => true,  // Public order page at /order/.
			'order_notify_email'  => '',    // Staff address told about every new online order (blank = site admin email).
			'online_sales_person' => 0,     // Sales person auto-assigned to online orders (0 = assign manually).
			'order_upload_max_mb' => 100,
			// Order page features: on | soon ("Coming soon", shown but can't be chosen) | off (hidden).
			'feature_campus'      => 'soon',
			'feature_courier'     => 'on',
			'feature_supplies'    => 'on',

			'daily_report'        => true,  // Each morning, email the admins yesterday's daily check (voids, deletes, counter gaps…).
			'signup_enabled'      => true,  // "Request an account" on the portal login screen (admin approves each request).
		);
	}

	public static function all() {
		// Only known keys: settings removed in later versions are ignored.
		$defaults = self::defaults();
		return array_intersect_key( wp_parse_args( get_option( self::OPTION, array() ), $defaults ), $defaults );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Saves known keys only, coerced to the default's type. */
	public static function update( array $values ) {
		$current  = self::all();
		$defaults = self::defaults();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$type = gettype( $defaults[ $key ] );
			if ( 'boolean' === $type ) {
				$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( 'integer' === $type ) {
				$value = max( 0, (int) $value );
			} elseif ( in_array( $key, array( 'att_window_start', 'att_window_end' ), true ) ) {
				$value = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? $value : $current[ $key ];
			} elseif ( 'gate_payment' === $key ) {
				$value = in_array( $value, array( 'none', 'any', 'downpayment', 'full' ), true ) ? $value : $current[ $key ];
			} elseif ( 0 === strpos( $key, 'feature_' ) ) {
				$value = in_array( $value, array( 'on', 'soon', 'off' ), true ) ? $value : $current[ $key ];
			} elseif ( 'order_notify_email' === $key ) {
				$value = sanitize_email( (string) $value );
			} elseif ( 'upload_types' === $key ) {
				$value = implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) ) );
			} else {
				$value = sanitize_textarea_field( (string) $value );
			}
			$current[ $key ] = $value;
		}
		update_option( self::OPTION, $current, false );
		return $current;
	}

	/**
	 * Cloudflare Turnstile is on only when both keys are set: the site key shows the check on the
	 * website, the secret key verifies it. With only one of them, nothing is asked (a check that
	 * can't be shown would block every customer).
	 */
	public static function turnstile_on() {
		$s = self::all();
		return '' !== trim( (string) $s['turnstile_site_key'] ) && '' !== trim( (string) $s['turnstile_secret'] );
	}

	/** Settings safe to send to any logged-in staff (no secrets). */
	public static function public_subset() {
		$all = self::all();
		unset( $all['turnstile_secret'] );
		return $all;
	}
}
