<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Ask for a review" (1.33.0): after a job order is delivered, or an invoice without a job order is
 * fully paid, the customer gets a personal link to rate the order: /order/review/?t=<token>.
 *
 * - With an email on file, the link is emailed once, after a delay (wp-admin → Reviews → Settings),
 *   and never more than once every 60 days to the same address.
 * - In the portal, a delivered job order shows the link and a ready-made message staff copy to
 *   Messenger or Viber.
 * - The review lands in Reviews like a "Leave a Review" one: 4–5 stars go on the website (right away
 *   or after approval), below 4 stars stay private. One review per link.
 */
class MQP_Review_Requests {

	const DELAYS   = array( 0 => 'Right away', 2 => '2 hours later', 6 => '6 hours later', 24 => '1 day later', 48 => '2 days later', 72 => '3 days later' );
	const GAP_DAYS = 60; // At most one review email per customer address in this many days.

	const STATUSES = array(
		'manual'   => 'Not emailed (copy the message)',
		'waiting'  => 'Email scheduled',
		'sent'     => 'Email sent',
		'failed'   => 'Email failed',
		'reviewed' => 'Review received',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'mqp_job_action', array( __CLASS__, 'on_job_action' ), 10, 2 );
		add_action( 'mqp_payment_added', array( __CLASS__, 'on_payment' ) );
		add_action( 'mqp_hourly', array( __CLASS__, 'send_due' ) );
	}

	public static function rewrite() {
		add_rewrite_rule( '^' . MQP_Order::SLUG . '/review/?$', 'index.php?mqp_order=review', 'top' );
	}

	public static function routes() {
		register_rest_route(
			MQP_REST::NS,
			'/public/review-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---------- Settings (stored with the other review options) ---------- */

	public static function default_message() {
		return 'Hi {name}! Thank you for choosing MQ Prints. How did your order {order} turn out? We would love a quick review, it only takes a minute: {link} Daghang salamat!';
	}

	public static function message( array $r ) {
		$tpl   = trim( (string) MQP_Reviews::options()['ask_message'] );
		$tpl   = '' !== $tpl ? $tpl : self::default_message();
		$first = strtok( trim( (string) $r['customer_name'] ), ' ' );
		return strtr(
			$tpl,
			array(
				'{name}'  => $first ? $first : 'there',
				'{order}' => '' !== $r['reference'] ? $r['reference'] : 'with us',
				'{link}'  => self::link( $r['token'] ),
			)
		);
	}

	public static function link( $token ) {
		return add_query_arg( 't', $token, MQP_Order::url( 'review/' ) );
	}

	/* ---------- Creating requests ---------- */

	private static function get( $field, $value ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'review_requests' ) . " WHERE $field = %s", $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function by_ref( $kind, $ref_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'review_requests' ) . ' WHERE kind = %s AND ref_id = %d', $kind, $ref_id ), ARRAY_A );
	}

	/** True when this address already got a review email in the last 60 days (or one is scheduled). */
	private static function asked_recently( $email ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'review_requests' ) . " WHERE email = %s AND ( status = 'waiting' OR sent_at >= %s ) LIMIT 1", $email, wp_date( 'Y-m-d H:i:s', time() - self::GAP_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * One request per job order / invoice. $auto: schedule the email (when switched on and allowed).
	 *
	 * @return array|null The request row.
	 */
	private static function create( $kind, $ref_id, $doc_id, $reference, $name, $email, $phone, $auto ) {
		global $wpdb;
		$have = self::by_ref( $kind, $ref_id );
		if ( $have ) {
			return $have;
		}
		$o      = MQP_Reviews::options();
		$email  = is_email( $email ) ? strtolower( trim( $email ) ) : '';
		$status = 'manual';
		$send   = null;
		if ( $auto && $o['ask_on'] && $email && ! self::asked_recently( $email ) ) {
			$status = 'waiting';
			$send   = wp_date( 'Y-m-d H:i:s', time() + max( 0, (int) $o['ask_delay'] ) * HOUR_IN_SECONDS );
		}
		$wpdb->insert(
			mqp_table( 'review_requests' ),
			array(
				'token'         => bin2hex( random_bytes( 16 ) ),
				'kind'          => $kind,
				'ref_id'        => (int) $ref_id,
				'document_id'   => $doc_id ? (int) $doc_id : null,
				'reference'     => substr( (string) $reference, 0, 60 ),
				'customer_name' => substr( trim( (string) $name ), 0, 150 ),
				'email'         => $email,
				'phone'         => substr( trim( (string) $phone ), 0, 40 ),
				'status'        => $status,
				'send_at'       => $send,
				'created_at'    => mqp_now(),
			)
		);
		$row = self::by_ref( $kind, $ref_id );
		// "Right away": don't wait for the hourly job.
		if ( $row && 'waiting' === $row['status'] && strtotime( $row['send_at'] ) <= strtotime( mqp_now() ) ) {
			self::send( $row );
			$row = self::by_ref( $kind, $ref_id );
		}
		return $row;
	}

	private static function create_for_job( $job_id, $auto ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT id, number, document_id, customer_name FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $job_id ), ARRAY_A );
		if ( ! $job ) {
			return null;
		}
		$c   = MQP_REST_Jobs::contact( (int) $job['id'], (int) $job['document_id'] );
		$inv = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT number FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $job['document_id'] ) );
		$ref = '' !== $c['reference'] ? $c['reference'] : ( '' !== $inv ? $inv : $job['number'] );
		return self::create( 'job', (int) $job['id'], (int) $job['document_id'], $ref, '' !== $c['name'] ? $c['name'] : $job['customer_name'], $c['email'], $c['phone'], $auto );
	}

	/** Job order marked as delivered. */
	public static function on_job_action( $job_id, $action ) {
		if ( 'delivered' === $action ) {
			self::create_for_job( (int) $job_id, true );
		}
	}

	/** A payment was recorded: an invoice without a job order that is now fully paid. */
	public static function on_payment( $doc_id ) {
		global $wpdb;
		if ( ! MQP_Reviews::options()['ask_paid'] ) {
			return;
		}
		$doc = $wpdb->get_row( $wpdb->prepare( 'SELECT id, type, number, status, job_id, customer_id, customer_name, customer_contact FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $doc_id ), ARRAY_A );
		if ( ! $doc || 'invoice' !== $doc['type'] || 'paid' !== $doc['status'] || $doc['job_id'] ) {
			return; // Job orders are asked when they are delivered.
		}
		$contact = trim( (string) $doc['customer_contact'] );
		$email   = is_email( $contact ) ? $contact : ( $doc['customer_id'] ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', $doc['customer_id'] ) ) : '' );
		self::create( 'invoice', (int) $doc['id'], (int) $doc['id'], (string) $doc['number'], (string) $doc['customer_name'], $email, is_email( $contact ) ? '' : $contact, true );
	}

	/** For the portal: the delivered job's link, the message to copy, and the email status. */
	public static function for_job( $job_id ) {
		$r = self::by_ref( 'job', $job_id );
		if ( ! $r ) {
			$r = self::create_for_job( $job_id, false ); // Delivered before 1.33.0: a link to copy, no email.
		}
		if ( ! $r ) {
			return null;
		}
		$rating = 0;
		if ( $r['review_id'] ) {
			$rev    = MQP_Reviews::get( (int) $r['review_id'] );
			$rating = $rev ? (int) $rev['rating'] : 0;
		}
		return array(
			'link'        => self::link( $r['token'] ),
			'message'     => self::message( $r ),
			'status'      => $r['status'],
			'label'       => self::STATUSES[ $r['status'] ] ?? $r['status'],
			'email'       => $r['email'],
			'send_at'     => $r['send_at'],
			'sent_at'     => $r['sent_at'],
			'opened_at'   => $r['opened_at'],
			'reviewed_at' => $r['reviewed_at'],
			'rating'      => $rating,
		);
	}

	/* ---------- Emails ---------- */

	/** Hourly: sends the review emails that are due. */
	public static function send_due() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'review_requests' ) . " WHERE status = 'waiting' AND send_at <= %s ORDER BY send_at LIMIT 25", mqp_now() ), ARRAY_A );
		foreach ( $rows as $r ) {
			self::send( $r );
		}
	}

	private static function send( array $r ) {
		global $wpdb;
		if ( ! MQP_Reviews::options()['ask_on'] || ! is_email( $r['email'] ) ) {
			$wpdb->update( mqp_table( 'review_requests' ), array( 'status' => 'manual', 'send_at' => null ), array( 'id' => $r['id'], 'status' => 'waiting' ) );
			return;
		}
		// Claim it first so two runs at the same moment never send it twice.
		if ( ! $wpdb->update( mqp_table( 'review_requests' ), array( 'status' => 'sent', 'sent_at' => mqp_now() ), array( 'id' => $r['id'], 'status' => 'waiting' ) ) ) {
			return;
		}
		$first   = strtok( trim( (string) $r['customer_name'] ), ' ' );
		$company = MQP_Mail::sender();
		$ok      = MQP_Mail::send(
			$r['email'],
			( $first ? $first . ', how' : 'How' ) . ' did we do with your order?',
			'How was your order?',
			array(
				'Hi ' . ( $first ? $first : 'there' ) . ',',
				'Thank you for choosing ' . $company . '!' . ( '' !== $r['reference'] ? ' Your order ' . $r['reference'] . ' is complete.' : '' ) . ' Could you tell us how it went? It takes less than a minute, and your review helps other customers choose us.',
				MQP_Mail::stars( 5 ),
				'Just tap the button, pick your stars and write a few words.',
			),
			array( 'Rate your order', self::link( $r['token'] ) )
		);
		if ( ! $ok ) {
			$wpdb->update( mqp_table( 'review_requests' ), array( 'status' => 'failed' ), array( 'id' => $r['id'] ) );
		}
	}

	/* ---------- The customer's review page ---------- */

	public static function submit( WP_REST_Request $req ) {
		global $wpdb;
		$token = strtolower( (string) $req->get_param( 't' ) );
		$r     = preg_match( '/^[a-f0-9]{32}$/', $token ) ? self::get( 'token', $token ) : null;
		if ( ! $r ) {
			return new WP_Error( 'mqp_link', 'This review link is not valid. Please use the link we sent you.', array( 'status' => 404 ) );
		}
		if ( $r['review_id'] ) {
			return new WP_Error( 'mqp_done', 'You already sent a review for this order. Thank you!', array( 'status' => 409 ) );
		}
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return new WP_Error( 'mqp_spam', 'Review rejected.', array( 'status' => 400 ) );
		}
		$consent = MQP_Privacy::check( $req->get_param( 'consent' ) );
		if ( $consent ) {
			return $consent;
		}
		$name   = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$body   = sanitize_textarea_field( (string) $req->get_param( 'body' ) );
		$rating = (int) $req->get_param( 'rating' );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) {
			return new WP_Error( 'mqp_fields', 'Please enter your name.', array( 'status' => 400 ) );
		}
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'mqp_fields', 'Please choose a star rating.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $body ) < 10 ) {
			return new WP_Error( 'mqp_fields', 'Please write a few words about your experience.', array( 'status' => 400 ) );
		}
		$body  = mb_substr( $body, 0, 2000 );
		$photo = MQP_Reviews::store_photo( $req->get_file_params()['photo'] ?? null );
		if ( is_wp_error( $photo ) ) {
			return new WP_Error( 'mqp_photo', $photo->get_error_message(), array( 'status' => 400 ) );
		}
		// Claim the link first: a double click never makes two reviews.
		if ( ! $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'review_requests' ) . " SET status = 'reviewed', reviewed_at = %s, review_id = 0 WHERE id = %d AND review_id IS NULL", mqp_now(), $r['id'] ) ) ) {
			return new WP_Error( 'mqp_done', 'You already sent a review for this order. Thank you!', array( 'status' => 409 ) );
		}

		$o       = MQP_Reviews::options();
		$private = $rating < MQP_Reviews::MIN_STARS;
		$status  = $private ? 'hidden' : ( $o['auto_approve'] ? 'shown' : 'pending' );
		$id      = MQP_Reviews::save(
			array(
				'source'  => 'website',
				'name'    => $name,
				'tagline' => 'Verified customer',
				'rating'  => $rating,
				'body'    => $body,
				'email'   => $r['email'],
				'photo'   => $photo,
				'ip'      => mqp_client_ip(),
				'status'  => $status,
			)
		);
		$wpdb->update( mqp_table( 'review_requests' ), array( 'review_id' => $id ), array( 'id' => $r['id'] ) );

		if ( 'shown' !== $status ) {
			MQP_Notify::review_received( $name, $rating, $private );
		}
		mqp_log( 'review_received', array( 'user_id' => 0, 'username' => $name, 'reason' => $rating . ' stars from a review link' . ( '' !== $r['reference'] ? ' (order ' . $r['reference'] . ')' : '' ) . ( $private ? ' · kept private (below 4 stars)' : ( 'pending' === $status ? ' · waiting for approval' : '' ) ) ) );
		if ( $o['notify'] ) {
			MQP_Reviews::email_admins( $id, $name, $rating, $body, $r['email'], $status, $private );
		}
		if ( $o['thank'] && is_email( $r['email'] ) ) {
			MQP_Reviews::email_thanks( $r['email'], $name, $status, $private );
		}
		if ( 'shown' === $status ) {
			MQP_Reviews::purge_cache();
		}
		return array(
			'ok'      => true,
			'message' => $private
				? 'Thank you for your feedback! We read every message and will use it to do better.'
				: ( 'shown' === $status
					? 'Thank you for your review! It is now on our website.'
					: 'Thank you for your review! It will appear on our website once we have checked it.' ),
		);
	}

	public static function render() {
		if ( 'review' !== get_query_var( 'mqp_order' ) ) {
			return;
		}
		global $wpdb;
		$token = isset( $_GET['t'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['t'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$r     = preg_match( '/^[a-f0-9]{32}$/', $token ) ? self::get( 'token', $token ) : null;
		if ( $r && ! $r['opened_at'] ) {
			$wpdb->update( mqp_table( 'review_requests' ), array( 'opened_at' => mqp_now() ), array( 'id' => $r['id'] ) );
		}
		$state = ! $r ? 'invalid' : ( $r['review_id'] ? 'done' : 'form' );
		$site  = MQP_Domain::to_main( home_url( '/' ) );
		$name  = get_bloginfo( 'name' );
		nocache_headers();
		status_header( 'invalid' === $state ? 404 : 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Rate your order · <?php echo esc_html( $name ); ?></title>
<link rel="icon" href="<?php echo esc_url( MQP_Monitor::logo( 'mark' ) ); ?>">
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f7f9;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#18181b}
.rr{max-width:520px;margin:0 auto;padding:28px 16px 48px}
.rr-logo{display:block;margin:0 auto 22px;height:44px;width:auto}
.rr-card{background:#fff;border:1px solid #e7e8ec;border-radius:20px;padding:28px 24px;box-shadow:0 8px 30px rgba(16,24,40,.06)}
h1{margin:0 0 6px;font-size:24px;letter-spacing:-.02em;color:#1e3a8a;text-align:center}
.rr-sub{margin:0 0 22px;color:#52525b;text-align:center;line-height:1.5}
.rr-stars{display:flex;justify-content:center;gap:6px;margin:0 0 6px}
.rr-stars button{background:none;border:0;padding:2px;cursor:pointer;font-size:42px;line-height:1;color:#d4d4d8;transition:transform .1s,color .1s}
.rr-stars button.is-on{color:#f5a524}.rr-stars button:hover{transform:scale(1.12)}
.rr-stars button:focus-visible{outline:2px solid #f88a35;border-radius:8px}
.rr-word{text-align:center;color:#71717a;min-height:20px;margin:0 0 18px;font-weight:600}
label{display:block;font-weight:600;font-size:14px;margin:0 0 6px}
input[type=text],textarea{width:100%;border:1px solid #d4d6dc;border-radius:12px;padding:12px 14px;font:inherit;font-size:16px;margin:0 0 16px;background:#fff}
input[type=text]:focus,textarea:focus{outline:0;border-color:#f88a35;box-shadow:0 0 0 3px rgba(248,138,53,.2)}
textarea{min-height:120px;resize:vertical}
.rr-photo{margin:0 0 16px;font-size:14px;color:#52525b}.rr-photo input{margin-top:6px;font-size:14px}
.rr-consent{display:flex;gap:10px;align-items:flex-start;font-weight:400;font-size:13px;color:#52525b;line-height:1.45;margin:0 0 18px}.rr-consent input{margin-top:3px;flex:none}
.rr-consent a{color:#1e3a8a}
.rr-hp{position:absolute;left:-9999px}
.rr-btn{display:block;width:100%;border:0;border-radius:12px;background:#f88a35;color:#fff;font:inherit;font-weight:700;font-size:16px;padding:14px;cursor:pointer;text-align:center;text-decoration:none}
.rr-btn:hover{background:#ea7a22}.rr-btn[disabled]{opacity:.6;cursor:wait}
.rr-err{background:#fef2f2;color:#b91c1c;border-radius:10px;padding:10px 12px;margin:0 0 14px;font-size:14px}
.rr-done{text-align:center}.rr-done .rr-big{font-size:52px;margin:0 0 8px}
.rr-foot{text-align:center;color:#a1a1aa;font-size:12px;margin-top:18px}
</style></head><body><main class="rr">
<a href="<?php echo esc_url( $site ); ?>"><img class="rr-logo" src="<?php echo esc_url( MQP_Monitor::logo( 'color' ) ); ?>" alt="<?php echo esc_attr( $name ); ?>"></a>
<div class="rr-card">
		<?php if ( 'invalid' === $state ) : ?>
	<div class="rr-done"><div class="rr-big">🔗</div><h1>This link doesn't work</h1><p class="rr-sub">Please use the review link we sent you, or message us and we'll send a new one.</p><a class="rr-btn" href="<?php echo esc_url( $site ); ?>">Visit our website</a></div>
		<?php elseif ( 'done' === $state ) : ?>
	<div class="rr-done"><div class="rr-big">🧡</div><h1>Thank you!</h1><p class="rr-sub">You already sent a review for this order. We really appreciate it.</p><a class="rr-btn" href="<?php echo esc_url( $site ); ?>">Visit our website</a></div>
		<?php else : ?>
	<form id="rr-form" novalidate>
		<h1>How was your order?</h1>
		<p class="rr-sub"><?php echo esc_html( '' !== $r['reference'] ? 'Order ' . $r['reference'] . ' · ' : '' ); ?>Your review helps other customers choose us.</p>
		<div class="rr-stars" role="radiogroup" aria-label="Your rating">
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?><button type="button" role="radio" aria-checked="false" aria-label="<?php echo (int) $i; ?> star<?php echo 1 === $i ? '' : 's'; ?>" data-v="<?php echo (int) $i; ?>">★</button><?php endfor; ?>
		</div>
		<p class="rr-word" id="rr-word">Tap a star</p>
		<label for="rr-body">Tell us about it</label>
		<textarea id="rr-body" name="body" maxlength="2000" placeholder="What did you order, and how did it turn out?" required></textarea>
		<label for="rr-name">Your name (shown with your review)</label>
		<input type="text" id="rr-name" name="name" maxlength="80" value="<?php echo esc_attr( $r['customer_name'] ); ?>" required>
		<div class="rr-photo">Add a photo of your order (optional)<br><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
		<input class="rr-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
		<?php echo MQP_Privacy::checkbox( 'rr' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="rr-err" id="rr-err" hidden></div>
		<button class="rr-btn" type="submit">Send my review</button>
	</form>
	<div class="rr-done" id="rr-thanks" hidden><div class="rr-big">🧡</div><h1>Thank you!</h1><p class="rr-sub" id="rr-msg"></p><a class="rr-btn" href="<?php echo esc_url( $site ); ?>">Visit our website</a></div>
		<?php endif; ?>
</div>
<p class="rr-foot"><?php echo esc_html( $name ); ?></p>
</main>
		<?php if ( 'form' === $state ) : ?>
<script>
(function () {
	var form = document.getElementById('rr-form'), stars = form.querySelectorAll('.rr-stars button'), word = document.getElementById('rr-word'), err = document.getElementById('rr-err'), rating = 0;
	var words = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent!'];
	function paint(n) { stars.forEach(function (b) { var on = +b.dataset.v <= n; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', +b.dataset.v === rating ? 'true' : 'false'); }); word.textContent = n ? words[n] : 'Tap a star'; }
	stars.forEach(function (b) {
		b.addEventListener('click', function () { rating = +b.dataset.v; paint(rating); });
		b.addEventListener('mouseenter', function () { paint(+b.dataset.v); });
		b.addEventListener('mouseleave', function () { paint(rating); });
	});
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		err.hidden = true;
		var fail = function (m) { err.textContent = m; err.hidden = false; };
		if (!rating) return fail('Please choose a star rating.');
		if (form.body.value.trim().length < 10) return fail('Please write a few words about your experience.');
		if (form.name.value.trim().length < 2) return fail('Please enter your name.');
		if (!form.consent.checked) return fail('Please tick the box to agree to how we use your details.');
		var fd = new FormData(form), btn = form.querySelector('.rr-btn');
		fd.append('rating', rating); fd.append('t', <?php echo wp_json_encode( $token ); ?>); fd.set('consent', '1');
		btn.disabled = true; btn.textContent = 'Sending…';
		fetch(<?php echo wp_json_encode( MQP_Domain::to_main( rest_url( MQP_REST::NS . '/public/review-request' ) ) ); ?>, { method: 'POST', body: fd })
			.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
			.then(function (x) {
				if (!x.ok) throw new Error(x.d && x.d.message ? x.d.message : 'Something went wrong. Please try again.');
				document.getElementById('rr-msg').textContent = x.d.message;
				form.hidden = true; document.getElementById('rr-thanks').hidden = false;
			})
			.catch(function (e2) { fail(e2.message); btn.disabled = false; btn.textContent = 'Send my review'; });
	});
})();
</script>
		<?php endif; ?>
</body></html>
		<?php
		exit;
	}

	/* ---------- Report for wp-admin → Reviews → Settings ---------- */

	public static function counts() {
		global $wpdb;
		$hide = $wpdb->suppress_errors( true );
		$row  = (array) $wpdb->get_row( 'SELECT COUNT(*) AS total, SUM(sent_at IS NOT NULL) AS sent, SUM(opened_at IS NOT NULL) AS opened, SUM(review_id IS NOT NULL) AS reviewed, SUM(status = \'waiting\') AS waiting FROM ' . mqp_table( 'review_requests' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( $hide );
		return array_map( 'intval', $row + array( 'total' => 0, 'sent' => 0, 'opened' => 0, 'reviewed' => 0, 'waiting' => 0 ) );
	}
}
