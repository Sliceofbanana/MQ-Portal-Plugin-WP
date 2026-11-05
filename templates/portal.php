<?php
/**
 * Staff portal shell. $config is provided by MQP_Portal::render().
 *
 * @var array $config
 */
defined( 'ABSPATH' ) || exit;
$assets = MQP_URL . 'assets/';
$ver    = rawurlencode( mqp_asset_version() );

// Import map: every module URL gets ?v=VERSION so browsers never mix old and new files after an update.
$imports = array();
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( MQP_DIR . 'assets/js', FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( 'js' === $file->getExtension() ) {
		$rel             = str_replace( '\\', '/', substr( $file->getPathname(), strlen( MQP_DIR . 'assets/' ) ) );
		$imports[ $assets . $rel ] = $assets . $rel . '?v=' . $ver;
	}
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1e3a8a">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<title>MQ Portal</title>
<link rel="manifest" href="<?php echo esc_url( MQP_Portal::url() . 'manifest.json' ); ?>">
<link rel="icon" href="<?php echo esc_url( $assets . 'icon-64.png?v=' . $ver ); ?>" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="<?php echo esc_url( $assets . 'icon-192.png?v=' . $ver ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'portal.css?v=' . $ver ); ?>">
<script type="importmap"><?php echo wp_json_encode( array( 'imports' => $imports ), JSON_UNESCAPED_SLASHES ); ?></script>
<script>window.MQP = <?php echo wp_json_encode( $config ); ?>;</script>
<script type="module" src="<?php echo esc_url( $assets . 'js/app.js?v=' . $ver ); ?>"></script>
</head>
<body>
<div id="app"><div class="boot">Loading MQ Portal…</div></div>
<div id="print-root"></div>
</body>
</html>
