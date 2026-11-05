<?php
defined( 'ABSPATH' ) || exit;

class MQP_Install {

	public static function activate() {
		self::create_tables();
		MQP_Roles::install();
		self::seed();
		MQP_Files::protect_dir();
		MQP_Portal::add_rewrite();
		MQP_Order::add_rewrite();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'mqp_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mqp_daily' );
		}
		update_option( 'mqp_db_version', MQP_DB_VERSION );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mqp_daily' );
		wp_clear_scheduled_hook( 'mqp_hourly' );
		flush_rewrite_rules();
	}

	/**
	 * Runs after a plugin update (uploading a newer zip doesn't fire the activation hook).
	 * Hooked early on 'init'; rewrite rules are flushed later on the same request by MQP_Portal::init().
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'mqp_db_version' ) === MQP_DB_VERSION ) {
			return;
		}
		// Only one request runs the upgrade (two visitors right after an update would both start it).
		if ( ! add_option( 'mqp_upgrading', time(), '', false ) ) {
			if ( time() - (int) get_option( 'mqp_upgrading' ) < 10 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( 'mqp_upgrading', time(), false );
		}
		self::create_tables();
		MQP_Roles::install();
		self::seed();
		MQP_Files::protect_dir();
		if ( ! wp_next_scheduled( 'mqp_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mqp_daily' );
		}
		$old = (int) get_option( 'mqp_db_version' );
		if ( $old && $old < 11 ) {
			global $wpdb;
			// 1.16.0: the website price list and "Get Quotation" show every POS product by default
			// (untick "On the website" to leave one out).
			$wpdb->query( 'UPDATE ' . mqp_table( 'pos_products' ) . ' SET web = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL
			// Customers who only uploaded files so far get a customer record too.
			foreach ( (array) $wpdb->get_results( 'SELECT name, contact, email FROM ' . mqp_table( 'uploads' ) . " WHERE name <> ''" ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL
				MQP_REST::upsert_customer( $u->name, '', $u->contact, $u->email );
			}
		}
		if ( $old && $old < 12 ) {
			global $wpdb;
			// 1.18.0: no commission row when there is no commission (rate 0).
			$wpdb->query( 'DELETE FROM ' . mqp_table( 'commissions' ) . " WHERE status = 'pending' AND ( rate <= 0 OR amount <= 0 )" ); // phpcs:ignore WordPress.DB.PreparedSQL
			// wp-admin is for WordPress administrators only: portal staff lose any wp-admin sign-in
			// and the old "Website reviews" permission.
			foreach ( get_users( array( 'role__in' => array_keys( MQP_Roles::roles() ) ) ) as $u ) {
				if ( in_array( 'administrator', (array) $u->roles, true ) ) {
					continue;
				}
				if ( array_key_exists( 'mq_reviews', $u->caps ) ) {
					$u->remove_cap( 'mq_reviews' );
				}
				if ( class_exists( 'WP_Session_Tokens' ) ) {
					WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
				}
			}
		}
		if ( $old && $old < 15 ) {
			global $wpdb;
			// 1.23.0: POS sales made before get their official number too, in the order they were saved.
			foreach ( (array) $wpdb->get_results( 'SELECT s.id, s.branch_id, b.code AS branch_code, br.code AS brand_code FROM ' . mqp_table( 'pos_sales' ) . ' s JOIN ' . mqp_table( 'branches' ) . ' b ON b.id = s.branch_id JOIN ' . mqp_table( 'brands' ) . " br ON br.id = s.brand_id WHERE s.official_no IS NULL ORDER BY s.id" ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->update( mqp_table( 'pos_sales' ), array( 'official_no' => MQP_Numbering::pos_number( $s->brand_code, $s->branch_code ) ), array( 'id' => (int) $s->id ) );
			}
		}
		if ( $old && $old < 16 ) {
			// 1.24.0: online orders and Get Quotation go to MQ Printing Services.
			self::add_main_brand();
			// The developer's own address was saved as the shop's contact during setup: customers saw
			// it at the bottom of the order page. The shop's address replaces it everywhere it was saved.
			self::replace_email( 'genesis.esdrilonjr@gmail.com', 'info@mqprintsph.com' );
		}
		if ( $old && $old < 17 ) {
			// 1.26.0: online orders take files up to 100 MB each (the owner's request); a saved 50 follows.
			$s = get_option( MQP_Settings::OPTION, array() );
			if ( is_array( $s ) && isset( $s['order_upload_max_mb'] ) && (int) $s['order_upload_max_mb'] <= 50 ) {
				$s['order_upload_max_mb'] = 100;
				update_option( MQP_Settings::OPTION, $s );
			}
		}
		update_option( 'mqp_db_version', MQP_DB_VERSION );
		update_option( 'mqp_flush_rewrite', 1, false );
		delete_option( 'mqp_upgrading' );
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = function ( $n ) {
			return mqp_table( $n );
		};

		$sql = array();

		$sql[] = "CREATE TABLE {$t('brands')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  code varchar(10) NOT NULL,
  has_branches tinyint(1) NOT NULL DEFAULT 0,
  commission_rate decimal(5,2) DEFAULT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) $c;";

		$sql[] = "CREATE TABLE {$t('branches')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brand_id bigint(20) unsigned NOT NULL,
  name varchar(100) NOT NULL,
  code varchar(10) NOT NULL,
  address varchar(255) NOT NULL DEFAULT '',
  lat decimal(10,7) DEFAULT NULL,
  lng decimal(10,7) DEFAULT NULL,
  radius_m int(11) NOT NULL DEFAULT 100,
  allowed_ips text,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY brand_id (brand_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('customers')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  company varchar(150) NOT NULL DEFAULT '',
  contact varchar(100) NOT NULL DEFAULT '',
  email varchar(150) NOT NULL DEFAULT '',
  address varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name),
  KEY contact (contact),
  KEY email (email)
) $c;";

		$sql[] = "CREATE TABLE {$t('documents')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  type varchar(20) NOT NULL,
  number varchar(40) DEFAULT NULL,
  brand_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  customer_id bigint(20) unsigned DEFAULT NULL,
  customer_name varchar(150) NOT NULL DEFAULT '',
  customer_company varchar(150) NOT NULL DEFAULT '',
  customer_contact varchar(150) NOT NULL DEFAULT '',
  customer_address varchar(255) NOT NULL DEFAULT '',
  title varchar(200) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  subtotal decimal(12,2) NOT NULL DEFAULT 0,
  discount decimal(12,2) NOT NULL DEFAULT 0,
  total decimal(12,2) NOT NULL DEFAULT 0,
  amount_paid decimal(12,2) NOT NULL DEFAULT 0,
  terms text,
  notes text,
  valid_until date DEFAULT NULL,
  due_date date DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  upload_id bigint(20) unsigned DEFAULT NULL,
  job_id bigint(20) unsigned DEFAULT NULL,
  sales_person_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  voided_at datetime DEFAULT NULL,
  void_reason varchar(255) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  public_token varchar(40) NOT NULL DEFAULT '',
  version int(10) unsigned NOT NULL DEFAULT 0,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY public_token (public_token),
  UNIQUE KEY number (number),
  KEY type_brand (type,brand_id),
  KEY created_at (created_at),
  KEY parent_id (parent_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('document_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  document_id bigint(20) unsigned NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL,
  specs text,
  qty decimal(12,2) NOT NULL DEFAULT 1,
  unit varchar(20) NOT NULL DEFAULT 'pc',
  unit_price decimal(12,2) NOT NULL DEFAULT 0,
  amount decimal(12,2) NOT NULL DEFAULT 0,
  is_estimate tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY document_id (document_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('payments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  document_id bigint(20) unsigned NOT NULL,
  amount decimal(12,2) NOT NULL,
  method varchar(20) NOT NULL DEFAULT 'cash',
  reference varchar(100) NOT NULL DEFAULT '',
  notes varchar(255) NOT NULL DEFAULT '',
  received_by bigint(20) unsigned NOT NULL DEFAULT 0,
  paid_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY document_id (document_id),
  KEY paid_at (paid_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(40) NOT NULL,
  document_id bigint(20) unsigned NOT NULL,
  brand_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  customer_name varchar(150) NOT NULL DEFAULT '',
  customer_contact varchar(150) NOT NULL DEFAULT '',
  title varchar(200) NOT NULL DEFAULT '',
  specs text,
  specs_complete tinyint(1) NOT NULL DEFAULT 0,
  needs_editing tinyint(1) NOT NULL DEFAULT 0,
  stage varchar(20) NOT NULL DEFAULT 'sales',
  sales_person_id bigint(20) unsigned DEFAULT NULL,
  priority tinyint(1) NOT NULL DEFAULT 0,
  due_date date DEFAULT NULL,
  notified_at datetime DEFAULT NULL,
  notified_via varchar(30) NOT NULL DEFAULT '',
  delivered_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  stage_changed_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY number (number),
  KEY stage (stage),
  KEY document_id (document_id),
  KEY sales_person_id (sales_person_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('job_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  action varchar(40) NOT NULL,
  from_stage varchar(20) NOT NULL DEFAULT '',
  to_stage varchar(20) NOT NULL DEFAULT '',
  note text,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job_id (job_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('uploads')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brand_id bigint(20) unsigned DEFAULT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  name varchar(150) NOT NULL DEFAULT '',
  contact varchar(150) NOT NULL DEFAULT '',
  email varchar(150) NOT NULL DEFAULT '',
  notes text,
  status varchar(20) NOT NULL DEFAULT 'new',
  document_id bigint(20) unsigned DEFAULT NULL,
  handled_by bigint(20) unsigned DEFAULT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at),
  KEY email (email),
  KEY contact (contact)
) $c;";

		$sql[] = "CREATE TABLE {$t('files')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  upload_id bigint(20) unsigned DEFAULT NULL,
  job_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL DEFAULT 'customer',
  original_name varchar(255) NOT NULL,
  stored_path varchar(255) NOT NULL,
  mime varchar(100) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  pages int(11) NOT NULL DEFAULT 0,
  token varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  archive_id bigint(20) unsigned DEFAULT NULL,
  archived_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY upload_id (upload_id),
  KEY job_id (job_id),
  KEY archive_id (archive_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('archives')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL DEFAULT '',
  path varchar(255) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  files int(11) NOT NULL DEFAULT 0,
  jobs int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'ready',
  reason varchar(20) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  downloaded_at datetime DEFAULT NULL,
  freed_at datetime DEFAULT NULL,
  drive_id varchar(100) NOT NULL DEFAULT '',
  drive_link varchar(255) NOT NULL DEFAULT '',
  drive_session text,
  drive_offset bigint(20) unsigned NOT NULL DEFAULT 0,
  drive_at datetime DEFAULT NULL,
  drive_error varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('orders')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reference varchar(30) NOT NULL,
  document_id bigint(20) unsigned DEFAULT NULL,
  job_id bigint(20) unsigned DEFAULT NULL,
  brand_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  service varchar(30) NOT NULL,
  details longtext,
  delivery_type varchar(20) NOT NULL DEFAULT 'pickup',
  delivery_location varchar(255) NOT NULL DEFAULT '',
  customer_name varchar(150) NOT NULL,
  customer_phone varchar(40) NOT NULL DEFAULT '',
  customer_email varchar(150) NOT NULL,
  notes text,
  total decimal(12,2) NOT NULL DEFAULT 0,
  payment_method varchar(20) NOT NULL DEFAULT '',
  payment_reference varchar(100) NOT NULL DEFAULT '',
  payment_proof_id bigint(20) unsigned DEFAULT NULL,
  payment_status varchar(20) NOT NULL DEFAULT 'submitted',
  payment_note varchar(255) NOT NULL DEFAULT '',
  verified_at datetime DEFAULT NULL,
  verified_by bigint(20) unsigned DEFAULT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  KEY customer_email (customer_email),
  KEY customer_phone (customer_phone),
  KEY payment_status (payment_status),
  KEY job_id (job_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('commissions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  sales_person_id bigint(20) unsigned NOT NULL,
  base_amount decimal(12,2) NOT NULL DEFAULT 0,
  rate decimal(5,2) NOT NULL DEFAULT 0,
  amount decimal(12,2) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  earned_at datetime DEFAULT NULL,
  paid_at datetime DEFAULT NULL,
  paid_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_id (job_id),
  KEY sales_person_id (sales_person_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('pos_products')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brand_id bigint(20) unsigned NOT NULL,
  name varchar(150) NOT NULL,
  category varchar(60) NOT NULL DEFAULT '',
  price decimal(12,2) NOT NULL DEFAULT 0,
  unit varchar(20) NOT NULL DEFAULT 'pc',
  sort int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  web tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY brand_id (brand_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('pos_sales')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  receipt_no varchar(40) NOT NULL,
  official_no varchar(40) DEFAULT NULL,
  brand_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned NOT NULL,
  device_id varchar(20) NOT NULL DEFAULT '',
  cashier_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subtotal decimal(12,2) NOT NULL DEFAULT 0,
  discount decimal(12,2) NOT NULL DEFAULT 0,
  total decimal(12,2) NOT NULL DEFAULT 0,
  tendered decimal(12,2) NOT NULL DEFAULT 0,
  change_due decimal(12,2) NOT NULL DEFAULT 0,
  method varchar(20) NOT NULL DEFAULT 'cash',
  reference varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'completed',
  void_reason varchar(255) NOT NULL DEFAULT '',
  voided_by bigint(20) unsigned DEFAULT NULL,
  voided_at datetime DEFAULT NULL,
  sold_at datetime NOT NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY receipt_no (receipt_no),
  UNIQUE KEY official_no (official_no),
  KEY branch_sold (branch_id,sold_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('pos_sale_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sale_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned DEFAULT NULL,
  name varchar(150) NOT NULL,
  qty decimal(12,2) NOT NULL DEFAULT 1,
  price decimal(12,2) NOT NULL DEFAULT 0,
  amount decimal(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY sale_id (sale_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('attendance')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  time_in datetime NOT NULL,
  time_out datetime DEFAULT NULL,
  in_ip varchar(45) NOT NULL DEFAULT '',
  in_lat decimal(10,7) DEFAULT NULL,
  in_lng decimal(10,7) DEFAULT NULL,
  in_accuracy int(11) DEFAULT NULL,
  out_ip varchar(45) NOT NULL DEFAULT '',
  out_lat decimal(10,7) DEFAULT NULL,
  out_lng decimal(10,7) DEFAULT NULL,
  auto_closed tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_time (user_id,time_in)
) $c;";

		$sql[] = "CREATE TABLE {$t('attendance_breaks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attendance_id bigint(20) unsigned NOT NULL,
  break_start datetime NOT NULL,
  break_end datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY attendance_id (attendance_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('audit_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  username varchar(60) NOT NULL DEFAULT '',
  event varchar(40) NOT NULL,
  result varchar(20) NOT NULL DEFAULT 'ok',
  reason varchar(255) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  lat decimal(10,7) DEFAULT NULL,
  lng decimal(10,7) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event (event),
  KEY user_id (user_id)
) $c;";

		// Account requests from the portal login screen. No WordPress user exists until an admin approves.
		$sql[] = "CREATE TABLE {$t('signups')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  username varchar(60) NOT NULL,
  name varchar(190) NOT NULL,
  email varchar(190) NOT NULL,
  phone varchar(40) NOT NULL DEFAULT '',
  role varchar(40) NOT NULL DEFAULT '',
  branch_id bigint(20) unsigned DEFAULT NULL,
  note text,
  pass_hash varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  reason varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned DEFAULT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  decided_at datetime DEFAULT NULL,
  decided_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY username (username),
  KEY email (email)
) $c;";

		// Problems the plugin ran into (wp-admin → MQ Portal → System). Same problem = one row with a hit count.
		$sql[] = "CREATE TABLE {$t('errors')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hash char(32) NOT NULL,
  kind varchar(20) NOT NULL,
  message text NOT NULL,
  detail text,
  url varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 1,
  seen tinyint(1) NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash),
  KEY last_seen (last_seen)
) $c;";

		// Customer reviews shown on the website ([mq_reviews]): added in wp-admin, sent from the website, or from Google.
		$sql[] = "CREATE TABLE {$t('reviews')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(10) NOT NULL DEFAULT 'manual',
  ext_id varchar(191) DEFAULT NULL,
  name varchar(150) NOT NULL,
  tagline varchar(100) NOT NULL DEFAULT '',
  photo varchar(500) NOT NULL DEFAULT '',
  link varchar(500) NOT NULL DEFAULT '',
  rating tinyint(1) unsigned NOT NULL DEFAULT 5,
  body text NOT NULL,
  email varchar(150) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'pending',
  ip varchar(45) NOT NULL DEFAULT '',
  reviewed_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ext_id (ext_id),
  KEY status (status),
  KEY reviewed_at (reviewed_at)
) $c;";

		// Notifications in the portal (the bell). One row per person.
		$sql[] = "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  type varchar(30) NOT NULL,
  title varchar(190) NOT NULL,
  body varchar(500) NOT NULL DEFAULT '',
  link varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  read_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_read (user_id,read_at),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('counters')} (
  name varchar(60) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) $c;";

		$sql[] = "CREATE TABLE {$t('printer_readings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  printer varchar(60) NOT NULL,
  reading bigint(20) unsigned NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY branch_printer (branch_id, printer),
  KEY created_at (created_at)
) $c;";

		dbDelta( $sql );
	}

	/** Swaps one email address for another in the portal's saved settings (company contact, order and admin emails). */
	public static function replace_email( $from, $to ) {
		$swap = function ( $value ) use ( $from, $to ) {
			return str_ireplace( $from, $to, (string) $value );
		};
		$s = get_option( MQP_Settings::OPTION, array() );
		if ( is_array( $s ) ) {
			foreach ( array( 'company_contact', 'order_notify_email' ) as $k ) {
				if ( isset( $s[ $k ] ) ) {
					$s[ $k ] = $swap( $s[ $k ] );
				}
			}
			update_option( MQP_Settings::OPTION, $s, false );
		}
		$m = (array) get_option( MQP_Monitor::OPT, array() );
		// Admin emails left blank fall back to the WordPress admin address, which may be the same one.
		$m['email'] = $swap( isset( $m['email'] ) ? $m['email'] : MQP_Monitor::settings()['email'] );
		update_option( MQP_Monitor::OPT, $m, false );
	}

	/** MQ Printing Services (code MQ), listed first: online orders and Get Quotation use it. */
	public static function add_main_brand() {
		global $wpdb;
		$brands = mqp_table( 'brands' );
		if ( $wpdb->get_var( "SELECT id FROM $brands WHERE code = 'MQ'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}
		$first = (int) $wpdb->get_var( "SELECT MIN(sort) FROM $brands" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->insert( $brands, array( 'name' => 'MQ Printing Services', 'code' => 'MQ', 'has_branches' => 0, 'sort' => $first - 1 ) );
	}

	/** Default brands, an EasyPrint branch and sample POS products (first install only). */
	public static function seed() {
		global $wpdb;
		$brands = mqp_table( 'brands' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $brands" ) > 0 ) {
			return;
		}
		$rows = array(
			array( 'EasyPrint', 'EP', 1 ),
			array( 'Gifts n Crafts', 'GC', 0 ),
			array( 'ID Express Cebu', 'IDX', 0 ),
			array( 'MQ Signature', 'MQS', 0 ),
		);
		foreach ( $rows as $i => $r ) {
			$wpdb->insert( $brands, array( 'name' => $r[0], 'code' => $r[1], 'has_branches' => $r[2], 'sort' => $i ) );
		}
		$ep = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $brands WHERE code = %s", 'EP' ) );
		$wpdb->insert( mqp_table( 'branches' ), array( 'brand_id' => $ep, 'name' => 'EasyPrint Main', 'code' => 'M1', 'address' => 'Cebu City' ) );
		self::add_main_brand(); // Added last so the others keep their ids, but listed first.

		// Sample prices — edit them in Admin → Products.
		$products = array(
			array( 'Piso Print (B&W)', 'Printing', 1, 'page' ),
			array( 'Colored Print', 'Printing', 5, 'page' ),
			array( 'Photocopy (B&W)', 'Photocopy', 2, 'page' ),
			array( 'Lamination (ID size)', 'Lamination', 20, 'pc' ),
			array( 'Lamination (A4)', 'Lamination', 40, 'pc' ),
			array( 'Bookbinding', 'Binding', 100, 'pc' ),
			array( 'Wire Binding', 'Binding', 80, 'pc' ),
			array( 'Rush ID', 'ID', 150, 'set' ),
		);
		foreach ( $products as $i => $p ) {
			$wpdb->insert( mqp_table( 'pos_products' ), array( 'brand_id' => $ep, 'name' => $p[0], 'category' => $p[1], 'price' => $p[2], 'unit' => $p[3], 'sort' => $i ) );
		}
	}
}
