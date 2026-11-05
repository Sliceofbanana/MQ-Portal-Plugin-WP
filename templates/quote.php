<?php
/**
 * The customer's quotation page (/?mq_quote=…): view, print, accept, and (once accepted) see how
 * far the order is. Variables: $doc, $s.
 */
defined( 'ABSPATH' ) || exit;

$pricing  = 'pricing' === $doc['status'];
$progress = 'converted' === $doc['status'] ? MQP_Quote::progress( $doc ) : null;
$blocked  = MQP_Quote::cannot_accept( $doc );
$expired  = 'open' === $doc['status'] && $doc['valid_until'] && $doc['valid_until'] < wp_date( 'Y-m-d' );
$money   = function ( $v ) {
	return '₱' . number_format( (float) $v, 2 );
};
$site    = MQP_Domain::to_main( home_url( '/' ) );
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 'Quotation ' . $doc['number'] . ' — ' . $s['company_name'] ); ?></title>
<link rel="icon" href="<?php echo esc_url( MQP_URL . 'assets/icon-64.png' ); ?>" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
:root { --navy: #1e3a8a; --orange: #f88a35; --text: #111827; --muted: #6b7280; --line: #e5e7eb; }
* { box-sizing: border-box; }
body { margin: 0; background: #f2f3f4; color: var(--text); font: 15px/1.55 Inter, Arial, sans-serif; }
.bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; max-width: 860px; margin: 0 auto; padding: 18px 16px; }
.bar a, .bar button { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--text); font: inherit; font-weight: 600; font-size: 14px; text-decoration: none; cursor: pointer; }
.bar button { background: var(--navy); border-color: var(--navy); color: #fff; }
.sheet { max-width: 860px; margin: 0 auto 40px; background: #fff; border-radius: 14px; padding: 40px; box-shadow: 0 10px 30px rgba(17, 24, 39, .08); }
.head { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; padding-bottom: 24px; border-bottom: 3px solid var(--navy); }
.co { display: flex; gap: 14px; align-items: center; }
.co img { height: 56px; width: auto; }
.co strong { display: block; font-size: 18px; }
.co span { display: block; color: var(--muted); font-size: 13px; }
.doc { text-align: right; }
.doc h1 { margin: 0; font-size: 26px; letter-spacing: .08em; color: var(--navy); }
.doc div { color: var(--muted); font-size: 13px; }
.doc b { color: var(--text); }
.notice { margin: 24px 0 0; padding: 14px 16px; border-radius: 10px; background: #fff4e5; color: #8a4b0f; border: 1px solid #fbd9b0; font-weight: 500; }
.to { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin: 24px 0; }
.to h2 { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 10px 8px; border-bottom: 2px solid var(--line); }
td { padding: 12px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
td small { display: block; color: var(--muted); white-space: pre-line; }
.num { text-align: right; white-space: nowrap; }
.tbp { color: #b45309; font-weight: 600; }
.totals { margin: 16px 0 0 auto; width: min(320px, 100%); }
.totals div { display: flex; justify-content: space-between; padding: 6px 8px; }
.totals .grand { border-top: 2px solid var(--navy); margin-top: 6px; padding-top: 10px; font-size: 18px; font-weight: 800; color: var(--navy); }
.terms { margin-top: 28px; padding-top: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; white-space: pre-line; }
.terms h2 { margin: 0 0 6px; font-size: 13px; color: var(--text); }
.track { margin: 24px 0 0; padding: 20px; border-radius: 12px; background: #f5f7ff; border: 1px solid #dfe5fb; }
.track h2 { margin: 0 0 4px; font-size: 18px; color: var(--navy); }
.track p { margin: 0; color: #374151; }
.track small { color: var(--muted); }
.steps { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px; margin: 18px 0 4px; padding: 0; list-style: none; counter-reset: s; }
.steps li { position: relative; text-align: center; font-size: 12px; font-weight: 600; color: var(--muted); }
.steps li::before { counter-increment: s; content: counter(s); display: grid; place-items: center; width: 30px; height: 30px; margin: 0 auto 6px; border-radius: 50%; background: #e5e7eb; color: #6b7280; font-weight: 700; position: relative; z-index: 1; }
.steps li::after { content: ''; position: absolute; top: 15px; left: calc(-50% + 15px); right: calc(50% + 15px); height: 3px; background: #e5e7eb; }
.steps li:first-child::after { display: none; }
.steps li.done, .steps li.now { color: var(--text); }
.steps li.done::before { content: '✓'; background: #16a34a; color: #fff; }
.steps li.done::after, .steps li.now::after { background: #16a34a; }
.steps li.now::before { background: var(--orange); color: #fff; box-shadow: 0 0 0 5px rgba(248, 138, 53, .2); }
.track.is-cancelled { background: #fef2f2; border-color: #fecaca; }
.accept { margin: 28px 0 0; padding: 20px; border-radius: 12px; background: #fff7ed; border: 1px solid #fed7aa; display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.accept h2 { margin: 0 0 2px; font-size: 17px; }
.accept p { margin: 0; color: #4b5563; font-size: 14px; }
.accept button { padding: 13px 26px; border: 0; border-radius: 10px; background: linear-gradient(180deg, #fb9a4b, #ea7620); color: #fff; font: inherit; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 8px 20px rgba(234, 118, 32, .3); }
.accept button:disabled { opacity: .6; cursor: default; }
.accept .err { flex-basis: 100%; color: #b91c1c; font-weight: 600; }
@media (max-width: 640px) { .steps { grid-template-columns: 1fr; gap: 10px; } .steps li { display: flex; align-items: center; gap: 10px; text-align: left; font-size: 14px; } .steps li::before { margin: 0; flex: none; } .steps li::after { display: none; } .accept button { width: 100%; } }
@media print { .accept { display: none; } }
@media (max-width: 640px) { .sheet { padding: 22px 16px; border-radius: 0; } .doc { text-align: left; } th:nth-child(3), td:nth-child(3) { display: none; } }
@media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; } .notice { border: 1px solid #ccc; } }
</style>
</head>
<body>
<div class="bar">
	<a href="<?php echo esc_url( $site ); ?>">← <?php echo esc_html( $s['company_name'] ); ?></a>
	<?php if ( ! $pricing ) : ?><button type="button" onclick="window.print()">Print / save as PDF</button><?php endif; ?>
</div>
<main class="sheet">
	<header class="head">
		<div class="co">
			<img src="<?php echo esc_url( MQP_URL . 'assets/logo-color.webp' ); ?>" alt="">
			<div><strong><?php echo esc_html( $s['company_name'] ); ?></strong><span><?php echo esc_html( $s['company_address'] ); ?></span><span><?php echo esc_html( $s['company_contact'] ); ?></span></div>
		</div>
		<div class="doc">
			<h1>QUOTATION</h1>
			<?php if ( $progress ) : ?><div><b style="color:#16a34a">Accepted</b></div><?php endif; ?>
			<div>No. <b><?php echo esc_html( $doc['number'] ); ?></b></div>
			<div>Date: <?php echo esc_html( mysql2date( 'F j, Y', $doc['created_at'] ) ); ?></div>
			<?php if ( $doc['valid_until'] && ! $pricing ) : ?><div>Valid until: <?php echo esc_html( mysql2date( 'F j, Y', $doc['valid_until'] ) ); ?></div><?php endif; ?>
		</div>
	</header>

	<?php if ( $pricing ) : ?>
		<p class="notice">Thank you! Our team is pricing the custom items and will get back to you within 24 hours. We will email you when your quotation is ready.</p>
	<?php elseif ( 'void' === $doc['status'] ) : ?>
		<p class="notice">This quotation is no longer valid. Please contact us for an updated one.</p>
	<?php elseif ( $expired ) : ?>
		<p class="notice"><?php echo esc_html( $blocked ); ?></p>
	<?php endif; ?>

	<?php if ( $progress ) : ?>
		<section class="track<?php echo 'cancelled' === $progress['key'] ? ' is-cancelled' : ''; ?>" aria-label="Your order">
			<h2>Your order<?php echo $progress['job'] ? ' · ' . esc_html( $progress['job'] ) : ''; ?></h2>
			<p><?php echo esc_html( $progress['text'] ); ?></p>
			<small>Placed <?php echo esc_html( mysql2date( 'F j, Y', $progress['placed_at'] ) ); ?> · Invoice <?php echo esc_html( $progress['invoice'] ); ?><?php echo $progress['paid'] > 0 ? ' · Paid ' . esc_html( $money( $progress['paid'] ) ) . ' of ' . esc_html( $money( $progress['total'] ) ) : ''; ?></small>
			<?php if ( 'cancelled' !== $progress['key'] ) : ?>
				<ol class="steps">
					<?php foreach ( $progress['steps'] as $st ) : ?>
						<li class="<?php echo $st['done'] ? 'done' : ( $st['current'] ? 'now' : '' ); ?>"><?php echo esc_html( $st['label'] ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="to">
		<div><h2>Prepared for</h2><strong><?php echo esc_html( $doc['customer_name'] ); ?></strong><?php echo $doc['customer_company'] ? '<div>' . esc_html( $doc['customer_company'] ) . '</div>' : ''; ?><div><?php echo esc_html( $doc['customer_contact'] ); ?></div></div>
		<?php if ( $doc['notes'] ) : ?><div><h2>Notes</h2><div style="white-space:pre-line"><?php echo esc_html( $doc['notes'] ); ?></div></div><?php endif; ?>
	</section>

	<table>
		<thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
		<tbody>
		<?php foreach ( $doc['items'] as $i ) : $open = $i['is_estimate'] && 0.0 === (float) $i['unit_price']; ?>
			<tr>
				<td><?php echo esc_html( $i['description'] ); ?><?php echo $i['specs'] ? '<small>' . esc_html( $i['specs'] ) . '</small>' : ''; ?></td>
				<td class="num"><?php echo esc_html( (float) $i['qty'] . ' ' . $i['unit'] ); ?></td>
				<td class="num"><?php echo $open ? '<span class="tbp">To be priced</span>' : esc_html( $money( $i['unit_price'] ) ); ?></td>
				<td class="num"><?php echo $open ? '<span class="tbp">—</span>' : esc_html( $money( $i['amount'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="totals">
		<div><span>Subtotal</span><span><?php echo esc_html( $money( $doc['subtotal'] ) ); ?></span></div>
		<?php if ( (float) $doc['discount'] ) : ?><div><span>Discount</span><span>−<?php echo esc_html( $money( $doc['discount'] ) ); ?></span></div><?php endif; ?>
		<div class="grand"><span><?php echo $pricing ? 'Total so far' : 'Total'; ?></span><span><?php echo esc_html( $money( $doc['total'] ) ); ?></span></div>
	</div>
	<?php if ( ! $blocked ) : ?>
		<form class="accept" id="accept">
			<div><h2>Ready to order?</h2><p>Accept this quotation and we start on your order. Our team will contact you about payment and your files.</p></div>
			<button type="submit">Accept quotation</button>
			<p class="err" role="alert" hidden></p>
		</form>
		<script>
		(function () {
			var f = document.getElementById('accept');
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!window.confirm(<?php echo wp_json_encode( 'Accept quotation ' . $doc['number'] . ' for ' . $money( $doc['total'] ) . '?' ); ?>)) return;
				var b = f.querySelector('button'), err = f.querySelector('.err');
				b.disabled = true; b.textContent = 'Sending…'; err.hidden = true;
				var body = new FormData(); body.append('token', <?php echo wp_json_encode( $doc['public_token'] ); ?>);
				fetch(<?php echo wp_json_encode( rest_url( 'mq/v1/public/quote/accept' ) ); ?>, { method: 'POST', body: body, credentials: 'omit' })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
					.then(function (res) {
						if (res.ok) { location.reload(); return; }
						throw new Error(res.j && res.j.message ? res.j.message : 'Something went wrong. Please try again.');
					})
					.catch(function (x) { err.textContent = x.message || 'Something went wrong. Please try again.'; err.hidden = false; b.disabled = false; b.textContent = 'Accept quotation'; });
			});
		})();
		</script>
	<?php endif; ?>
	<?php if ( $doc['terms'] && ! $pricing ) : ?>
		<div class="terms"><h2>Terms</h2><?php echo esc_html( $doc['terms'] ); ?></div>
	<?php endif; ?>
</main>
</body>
</html>
