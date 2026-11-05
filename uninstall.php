<?php
/**
 * Runs when the plugin is deleted in wp-admin → Plugins.
 *
 * By default nothing is removed, so reinstalling brings everything back.
 * Only when "Delete all data when the plugin is deleted" is on
 * (wp-admin → MQ Portal → System → Settings) are the tables, files, settings and roles removed.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mqp_sys = (array) get_option( 'mqp_monitor', array() );
if ( empty( $mqp_sys['delete_data'] ) ) {
	return;
}

global $wpdb;

// Tables (wp_mq_*).
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mq_' ) . '%' ) ) as $mqp_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$mqp_table`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Settings, cached values and per-user data (everything named mqp_*).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mqp\\_%' OR option_name LIKE '\\_transient\\_mqp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mqp\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mqp\\_%'" );

// Private files: customer uploads, job files, payment proofs, profile photos.
$mqp_up  = wp_upload_dir( null, false );
$mqp_dir = trailingslashit( $mqp_up['basedir'] ) . 'mq-portal';
if ( is_dir( $mqp_dir ) ) {
	$mqp_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $mqp_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $mqp_it as $mqp_f ) {
		$mqp_f->isDir() ? rmdir( $mqp_f->getPathname() ) : unlink( $mqp_f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	rmdir( $mqp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

// Portal roles and the portal permissions given to administrators.
require_once __DIR__ . '/includes/class-mqp-roles.php';
MQP_Roles::uninstall();
wp_clear_scheduled_hook( 'mqp_daily' );
