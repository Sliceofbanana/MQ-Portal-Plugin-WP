<?php
/**
 * Plugin Name:       MQ Portal
 * Description:       Staff portal for MQ Printing Services: online ordering (/order/), customer file uploads, Invoice Manager (quotations, billing statements, invoices), job order workflow (Sales → Creatives → Production & QC → Delivery), POS, time clock and the owner dashboard.
 * Version:           1.27.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Genesis Esdrilon Jr
 * Text Domain:       mq-portal
 */

defined( 'ABSPATH' ) || exit;

define( 'MQP_VERSION', '1.27.0' );
define( 'MQP_FILE', __FILE__ );
define( 'MQP_DIR', plugin_dir_path( __FILE__ ) );
require_once MQP_DIR . 'includes/class-mqp-domain.php';
// On the portal's own address (e.g. app.mqprintsph.com) assets load from that address too.
define( 'MQP_URL', MQP_Domain::swap( plugin_dir_url( __FILE__ ) ) );
define( 'MQP_DB_VERSION', '17' ); // 17: no table change — order upload limit 50 → 100 MB. 16: printer_readings table, MQ Printing Services brand. 15: documents.version/updated_by, pos_sales.official_no (additive).

require_once MQP_DIR . 'includes/helpers.php';
require_once MQP_DIR . 'includes/class-mqp-roles.php';
require_once MQP_DIR . 'includes/class-mqp-settings.php';
require_once MQP_DIR . 'includes/class-mqp-install.php';
require_once MQP_DIR . 'includes/class-mqp-files.php';
require_once MQP_DIR . 'includes/class-mqp-numbering.php';
require_once MQP_DIR . 'includes/class-mqp-auth.php';
require_once MQP_DIR . 'includes/class-mqp-session.php';
require_once MQP_DIR . 'includes/class-mqp-portal.php';
require_once MQP_DIR . 'includes/class-mqp-upload.php';
require_once MQP_DIR . 'includes/class-mqp-mail.php';
require_once MQP_DIR . 'includes/class-mqp-order.php';
require_once MQP_DIR . 'includes/class-mqp-avatar.php';
require_once MQP_DIR . 'includes/class-mqp-signup.php';
require_once MQP_DIR . 'includes/class-mqp-reviews.php';
require_once MQP_DIR . 'includes/class-mqp-showcase.php';
require_once MQP_DIR . 'includes/class-mqp-gallery.php';
require_once MQP_DIR . 'includes/class-mqp-reviews-admin.php';
require_once MQP_DIR . 'includes/class-mqp-notify.php';
require_once MQP_DIR . 'includes/class-mqp-guard.php';
require_once MQP_DIR . 'includes/class-mqp-quote.php';
require_once MQP_DIR . 'includes/class-mqp-privacy.php';
require_once MQP_DIR . 'includes/class-mqp-monitor.php';
require_once MQP_DIR . 'includes/class-mqp-system.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-documents.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-jobs.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-pos.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-customers.php';
require_once MQP_DIR . 'includes/class-mqp-website-admin.php';
require_once MQP_DIR . 'includes/class-mqp-shortcodes-admin.php';
require_once MQP_DIR . 'includes/class-mqp-dev-admin.php';
require_once MQP_DIR . 'includes/class-mqp-archive.php';
require_once MQP_DIR . 'includes/class-mqp-gdrive.php';
require_once MQP_DIR . 'includes/class-mqp-reset.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-dashboard.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-finance.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-admin.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-reviews.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-sync.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-me.php';
require_once MQP_DIR . 'includes/rest/class-mqp-rest-orders.php';
require_once MQP_DIR . 'includes/class-mqp-daily.php';
require_once MQP_DIR . 'includes/class-mqp-updater.php';

register_activation_hook( __FILE__, array( 'MQP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MQP_Install', 'deactivate' ) );

add_action( 'init', array( 'MQP_Install', 'maybe_upgrade' ), 1 );
add_action( 'init', array( 'MQP_Portal', 'init' ) );
add_action( 'init', array( 'MQP_Upload', 'init' ) );
add_action( 'init', array( 'MQP_Order', 'init' ) );
add_action( 'init', array( 'MQP_Reviews', 'init' ) );
add_action( 'init', array( 'MQP_Notify', 'init' ) );
add_action( 'init', array( 'MQP_Quote', 'init' ) );
add_action( 'init', array( 'MQP_Privacy', 'init' ) );
add_action( 'init', array( 'MQP_Monitor', 'note_server_cron' ), 0 );
add_action( 'mqp_hourly', array( 'MQP_Monitor', 'hourly' ) );
add_action( 'delete_user', array( 'MQP_Avatar', 'remove' ) );
add_action( 'init', array( 'MQP_Auth', 'init' ) );
add_action( 'rest_api_init', array( 'MQP_REST', 'register_all' ) );
add_action( 'mqp_daily', array( 'MQP_Auth', 'close_stale_attendance' ) );
add_action( 'mqp_daily', array( 'MQP_Reviews', 'daily' ) );
add_action( 'mqp_daily', array( 'MQP_Guard', 'daily' ) );
add_action( 'mqp_daily', array( 'MQP_REST_Admin', 'purge_deleted' ) ); // Staff deleted more than 30 days ago go for good.
add_action( 'mqp_daily', array( 'MQP_Archive', 'auto' ), 20 ); // After the cleanups: a weekly archive of finished job files.
MQP_Archive::init();
MQP_Mail::init();
MQP_Daily::init();
MQP_Updater::init(); // Updates from GitHub releases (wp-admin → Plugins → Update now).
MQP_Reset::init();
MQP_GDrive::init();
MQP_Showcase::init();
MQP_Gallery::init();

// Problem log, activity log, alerts and /health (see wp-admin → MQ Portal → System).
MQP_Monitor::boot();
MQP_Domain::boot();
MQP_Session::init(); // The portal's own sign-in, separate from wp-admin's.
if ( is_admin() ) {
	MQP_System::init();
	MQP_Reviews_Admin::init();
	MQP_Website_Admin::init();
	MQP_Shortcodes_Admin::init();
	MQP_Dev_Admin::init();
}
