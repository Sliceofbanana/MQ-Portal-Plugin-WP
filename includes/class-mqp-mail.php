<?php
defined( 'ABSPATH' ) || exit;

/**
 * Branded HTML emails (sent with wp_mail, so an SMTP plugin such as WP Mail SMTP
 * configured with the Hostinger mailbox improves deliverability).
 */
class MQP_Mail {

	public static function init() {
		// Emails from this site never come from "WordPress": the company name is the sender.
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
	}

	/** Sender name (wp-admin → MQ Portal → System → Settings → Emails), else the company name. */
	public static function sender() {
		$name = trim( (string) ( MQP_Monitor::settings()['mail_from_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = (string) MQP_Settings::get( 'company_name' );
		}
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	public static function from_name( $name ) {
		return ( '' === trim( (string) $name ) || 'WordPress' === $name ) ? self::sender() : $name;
	}

	/** Valid addresses from "a@x.com, b@y.com" or an array. */
	public static function recipients( $to ) {
		$list = is_array( $to ) ? $to : preg_split( '/[\s,;]+/', (string) $to );
		return array_values( array_unique( array_filter( array_map( 'sanitize_email', $list ), 'is_email' ) ) );
	}

	/**
	 * @param string $to      Recipient.
	 * @param string $subject Subject line.
	 * @param string $heading Big heading in the email.
	 * @param array  $blocks  Paragraphs (plain text, escaped here) or ['html' => '...'] for trusted HTML.
	 * @param array  $button  Optional [label, url].
	 */
	public static function send( $to, $subject, $heading, array $blocks, $button = null ) {
		$to = self::recipients( $to );
		if ( ! $to ) {
			return false;
		}
		$s     = MQP_Settings::all();
		$body  = '';
		foreach ( $blocks as $b ) {
			$body .= is_array( $b ) ? $b['html'] : '<p style="margin:0 0 14px;line-height:1.55">' . nl2br( esc_html( $b ) ) . '</p>';
		}
		if ( $button ) {
			$body .= '<p style="margin:22px 0"><a href="' . esc_url( $button[1] ) . '" style="background:#f88a35;color:#fff;text-decoration:none;padding:12px 22px;border-radius:5px;font-weight:600;display:inline-block">' . esc_html( $button[0] ) . '</a></p>';
		}
		$html = '<!doctype html><html><body style="margin:0;background:#f2f3f4;font-family:Inter,Arial,sans-serif;color:#111827">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f3f4;padding:24px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:16px;overflow:hidden">'
			. '<tr><td style="background:#1e3a8a;padding:20px 24px;color:#fff;font-size:20px;font-weight:800;letter-spacing:.04em">MQ PRINTS<span style="display:block;font-size:11px;font-weight:500;letter-spacing:.12em;color:#f88a35">' . esc_html( strtoupper( $s['company_name'] ) ) . '</span></td></tr>'
			. '<tr><td style="padding:26px 24px 8px"><h1 style="margin:0 0 16px;font-size:22px;color:#1e3a8a">' . esc_html( $heading ) . '</h1>' . $body . '</td></tr>'
			. '<tr><td style="padding:16px 24px 24px;color:#545454;font-size:12px;border-top:1px solid #e1e4ea">'
			. esc_html( $s['company_name'] ) . ' · ' . esc_html( $s['company_address'] ) . '<br>' . esc_html( $s['company_contact'] ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, $subject, $html, $headers );
	}

	/** Gold stars for an email (★ characters work in every mail app). */
	public static function stars( $n ) {
		$n = max( 0, min( 5, (int) $n ) );
		return array( 'html' => '<p style="margin:0 0 12px;font-size:22px;letter-spacing:2px;line-height:1"><span style="color:#f5a524">' . str_repeat( '★', $n ) . '</span><span style="color:#d1d5db">' . str_repeat( '★', 5 - $n ) . '</span> <span style="font-size:14px;letter-spacing:0;color:#545454;vertical-align:3px">' . $n . ' / 5</span></p>' );
	}

	/** A quoted block, e.g. the review text. */
	public static function quote( $text ) {
		return array( 'html' => '<div style="margin:0 0 16px;padding:14px 16px;background:#f8fafc;border-left:4px solid #f88a35;border-radius:6px;font-size:15px;line-height:1.6;color:#1f2937">' . nl2br( esc_html( $text ) ) . '</div>' );
	}

	/** A small coloured label: tone ok | warn | bad | info. */
	public static function pill( $text, $tone = 'info' ) {
		$c = array( 'ok' => array( '#e7f6ec', '#0a7a32' ), 'warn' => array( '#fff4e5', '#a35200' ), 'bad' => array( '#fdecec', '#b42318' ), 'info' => array( '#eef2ff', '#1e3a8a' ) )[ $tone ] ?? array( '#eef2ff', '#1e3a8a' );
		return array( 'html' => '<p style="margin:0 0 14px"><span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' . $c[0] . ';color:' . $c[1] . ';font-size:12px;font-weight:700">' . esc_html( $text ) . '</span></p>' );
	}

	/** A simple two-column table for order summaries. */
	public static function table( array $rows ) {
		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;font-size:14px">';
		foreach ( $rows as $r ) {
			$bold = ! empty( $r[2] );
			$out .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eef0f4;color:#545454">' . esc_html( $r[0] ) . '</td>'
				. '<td align="right" style="padding:7px 0;border-bottom:1px solid #eef0f4;' . ( $bold ? 'font-weight:700;color:#1e3a8a' : '' ) . '">' . esc_html( $r[1] ) . '</td></tr>';
		}
		return array( 'html' => $out . '</table>' );
	}
}
