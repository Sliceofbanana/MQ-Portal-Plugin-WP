<?php
/**
 * Public order page (/order/) and order tracking (/order/track/). $config comes from MQP_Order::render().
 *
 * @var array $config
 */
defined( 'ABSPATH' ) || exit;
$assets = MQP_URL . 'assets/';
$ver    = rawurlencode( mqp_asset_version() );
$title  = 'track' === $config['mode'] ? 'Track your order' : 'Order online';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1e3a8a">
<title><?php echo esc_html( $title . ' — ' . $config['company']['name'] ); ?></title>
<meta name="description" content="Upload your files for printing at MQ Printing Services. Pick up at our main office or book a Maxim, Lalamove or Grab Express rider.">
<link rel="icon" href="<?php echo esc_url( $assets . 'icon-64.png?v=' . $ver ); ?>" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="<?php echo esc_url( $assets . 'icon-192.png?v=' . $ver ); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'order.css?v=' . $ver ); ?>">
<script>window.MQO = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script>
<?php if ( $config['turnstile'] ) : ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script src="<?php echo esc_url( $assets . 'order.js?v=' . $ver ); ?>" defer></script>
</head>
<body class="<?php echo $config['embed'] ? 'is-embed' : ''; ?>">
<header class="o-header">
	<div class="o-wrap o-header-in">
		<a class="o-logo" href="<?php echo esc_url( $config['homeUrl'] ); ?>"><img src="<?php echo esc_url( MQP_Monitor::logo( 'light' ) ); ?>" alt="<?php echo esc_attr( $config['company']['name'] ); ?>"></a>
		<nav class="o-nav">
			<a href="<?php echo esc_url( $config['orderUrl'] ); ?>" class="<?php echo 'new' === $config['mode'] ? 'active' : ''; ?>">Order online</a>
			<a href="<?php echo esc_url( $config['homeUrl'] ); ?>" class="o-nav-home">Back to website</a>
		</nav>
	</div>
</header>
<main class="o-wrap" id="o-app"><p class="o-loading">Loading…</p></main>
<footer class="o-footer">
	<div class="o-wrap">
		<strong><?php echo esc_html( $config['company']['name'] ); ?></strong> · <?php echo esc_html( $config['company']['address'] ); ?> · <?php echo esc_html( $config['company']['contact'] ); ?>
	</div>
</footer>
</body>
</html>
