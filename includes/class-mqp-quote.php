<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Get Quotation" on the website: [mq_quote] and POST /mq/v1/public/quote.
 *
 * Customers pick items with a fixed price (POS products ticked "Website quotation") and/or
 * describe custom work, then give their name, email and phone. A real quotation is saved in the
 * portal (Sales → Invoice Manager) with their contact details:
 * - only fixed-price items → the quotation is ready at once: shown on screen and emailed;
 * - any custom item → status "Needs pricing"; the customer is told the team gets back to them
 *   within 24 hours, Sales are notified, add the prices and click "Email to customer".
 * Customers open their quotation with a private link (/?mq_quote=…), which can be printed.
 * There they can click "Accept quotation": it becomes an invoice with a job order (Sales are
 * notified), and the same link then shows how far their order is (printing, ready…).
 * [mq_prices] shows the price list (POS products marked "On the website") on any page.
 */
class MQP_Quote {

	const VALID_DAYS = 15;
	const MAX_ITEMS  = 30;
	const MAX_FILES  = 5;

	/** Hours after which an unpriced request reminds the owner (the website promises 24). */
	const REMIND_HOURS = 20;

	/** True once the pop-up was printed on this page. */
	private static $rendered = false;

	public static function init() {
		add_shortcode( 'mq_quote', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'mq_prices', array( __CLASS__, 'prices_shortcode' ) );
		add_action( 'mqp_hourly', array( __CLASS__, 'remind' ) );
		add_action( 'mqp_job_stage_changed', array( __CLASS__, 'on_stage_changed' ), 10, 3 );
		if ( ! wp_next_scheduled( 'mqp_hourly' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'mqp_hourly' );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'public_view' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'footer_popup' ), 6 );
	}

	public static function routes() {
		register_rest_route( 'mq/v1', '/public/quote', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'mq/v1', '/public/quote/accept', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'accept' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'mq/v1', '/documents/(?P<id>\d+)/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'send' ),
			'permission_callback' => MQP_REST::can( 'mq_sales' ),
		) );
	}

	/* ---------- Price list ---------- */

	/** Fixed-price items for the website, grouped by brand: brand_id => [ items ]. */
	public static function catalog() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, brand_id, name, category, price, unit FROM ' . mqp_table( 'pos_products' ) . ' WHERE active = 1 AND web = 1 ORDER BY category, sort, name', ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['brand_id'] ][] = array(
				'id'       => (int) $r['id'],
				'name'     => $r['name'],
				'category' => $r['category'],
				'price'    => (float) $r['price'],
				'unit'     => $r['unit'],
			);
		}
		return $out;
	}

	/**
	 * [mq_prices brand="EP" category="Printing, Binding" button="Get a quotation" note="no"]
	 * The live price list: POS products marked "On the website", grouped by category. Changing a
	 * price in the portal changes it here. button="no" hides the button (it opens "Get Quotation").
	 */
	public static function prices_shortcode( $atts ) {
		global $wpdb;
		$atts  = shortcode_atts( array( 'brand' => '', 'category' => '', 'button' => 'Get a quotation', 'note' => 'yes' ), $atts );
		$where = 'p.active = 1 AND p.web = 1 AND b.active = 1';
		$args  = array();
		if ( '' !== trim( $atts['brand'] ) ) {
			$where .= ' AND b.code = %s';
			$args[] = trim( $atts['brand'] );
		}
		$cats = array_values( array_filter( array_map( 'trim', explode( ',', (string) $atts['category'] ) ) ) );
		if ( $cats ) {
			$where .= ' AND p.category IN (' . implode( ',', array_fill( 0, count( $cats ), '%s' ) ) . ')';
			$args   = array_merge( $args, $cats );
		}
		$sql  = 'SELECT p.name, p.category, p.price, p.unit, b.name AS brand FROM ' . mqp_table( 'pos_products' ) . ' p JOIN ' . mqp_table( 'brands' ) . " b ON b.id = p.brand_id WHERE $where ORDER BY p.category, p.sort, p.name";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return '';
		}
		$groups = array();
		$brands = array();
		foreach ( $rows as $r ) {
			$groups[ '' !== $r['category'] ? $r['category'] : 'Other' ][] = $r;
			$brands[ $r['brand'] ] = true;
		}
		$many   = count( $brands ) > 1;
		$button = in_array( strtolower( (string) $atts['button'] ), array( 'no', '0', 'false', 'off', '' ), true ) ? '' : (string) $atts['button'];
		ob_start();
		?>
		<section class="mqpl" aria-label="Price list">
			<div class="mqpl-grid">
				<?php foreach ( $groups as $cat => $items ) : ?>
					<div class="mqpl-cat">
						<h3><?php echo esc_html( $cat ); ?></h3>
						<ul>
							<?php foreach ( $items as $i ) : ?>
								<li>
									<span class="mqpl-name"><?php echo esc_html( $i['name'] ); ?><?php echo $many ? '<small>' . esc_html( $i['brand'] ) . '</small>' : ''; ?></span>
									<span class="mqpl-price"><strong>₱<?php echo esc_html( number_format( (float) $i['price'], 2 ) ); ?></strong><small>/ <?php echo esc_html( $i['unit'] ); ?></small></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( 'no' !== strtolower( (string) $atts['note'] ) ) : ?>
				<p class="mqpl-note">Prices are per item and may change. Need something custom? Ask for a quotation and we reply within 24 hours.</p>
			<?php endif; ?>
			<?php if ( $button ) : ?>
				<p class="mqpl-cta"><a class="mqpl-btn mq-quote-open" href="#mq-quote"><?php echo esc_html( $button ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
		static $styled = false;
		$css = '';
		if ( ! $styled ) {
			$styled = true;
			$css    = '<style id="mqp-prices-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/prices.css' ) ) . '</style>'; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $css . ob_get_clean();
	}

	/* ---------- Pop-up on the website ---------- */

	/** Every page gets the hidden pop-up, so a "Get Quotation" button linking to #mq-quote opens it. */
	public static function footer_popup() {
		if ( self::$rendered || is_admin() || is_feed() || is_embed() || ! apply_filters( 'mqp_quote_popup_everywhere', true ) ) {
			return;
		}
		echo self::shortcode( array( 'button' => 'no' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in shortcode().
	}

	/**
	 * Businesses customers can ask a quotation from: MQ Printing Services first. While the
	 * setting "Get Quotation: MQ Printing Services only" is on, the other businesses are hidden.
	 */
	public static function brands() {
		global $wpdb;
		$brands = $wpdb->get_results( 'SELECT id, name, code FROM ' . mqp_table( 'brands' ) . " WHERE active = 1 ORDER BY code = 'MQ' DESC, sort, id", ARRAY_A );
		if ( MQP_Settings::get( 'quote_main_only' ) ) {
			$main = array_values( array_filter( $brands, function ( $b ) {
				return 'MQ' === $b['code'];
			} ) );
			return $main ? $main : $brands;
		}
		return $brands;
	}

	/**
	 * [mq_quote button="Get Quotation" brand="EP"]
	 * button="no": only the pop-up (open it with a link to #mq-quote or the CSS class mq-quote-open).
	 */
	public static function shortcode( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'button' => 'Get Quotation', 'brand' => '' ), $atts );
		if ( self::$rendered ) {
			// One pop-up per page; extra shortcodes only add another button.
			return in_array( strtolower( (string) $atts['button'] ), array( 'no', '0', 'false', 'off', '' ), true ) ? ''
				: '<p class="mqq-cta"><button type="button" class="mqq-open mq-quote-open">' . esc_html( $atts['button'] ) . '</button></p>';
		}
		self::$rendered = true;
		$s      = MQP_Settings::all();
		$brands = self::brands();
		$preset = 0;
		foreach ( $brands as $b ) {
			if ( $atts['brand'] && 0 === strcasecmp( $b['code'], $atts['brand'] ) ) {
				$preset = (int) $b['id'];
			}
		}
		$config = array(
			'endpoint' => rest_url( 'mq/v1/public/quote' ),
			'brands'   => array_map( function ( $b ) {
				return array( 'id' => (int) $b['id'], 'name' => $b['name'] );
			}, $brands ),
			'brand'    => $preset,
			'catalog'  => (object) self::catalog(),
			'types'    => MQP_Files::allowed_extensions(),
			'maxMb'    => (int) $s['upload_max_mb'],
			'maxFiles' => self::MAX_FILES,
		);
		$button = in_array( strtolower( (string) $atts['button'] ), array( 'no', '0', 'false', 'off', '' ), true ) ? '' : (string) $atts['button'];

		wp_enqueue_script( 'mqp-quote', MQP_URL . 'assets/quote.js', array(), mqp_asset_version(), true );
		ob_start();
		?>
		<div class="mqq" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"<?php echo $button ? '' : ' hidden'; ?>>
			<?php if ( $button ) : ?>
				<p class="mqq-cta"><button type="button" class="mqq-open mq-quote-open"><?php echo esc_html( $button ); ?></button></p>
			<?php endif; ?>
			<div class="mqq-modal" role="dialog" aria-modal="true" aria-labelledby="mqq-title" hidden>
				<form class="mqq-box" novalidate>
					<button type="button" class="mqq-close" aria-label="Close">&times;</button>
					<div class="mqq-steps" aria-hidden="true"><span class="is-on">1 · What you need</span><span>2 · Your details</span><span>3 · Quotation</span></div>

					<section class="mqq-step" data-step="1">
						<h3 class="mqq-title" id="mqq-title">Get a quotation</h3>
						<p class="mqq-sub">Items with a set price are quoted right away. For anything custom, describe it and our team prices it for you.</p>
						<label class="mqq-brand-wrap"><span>Shop / brand</span><select name="brand_id" class="mqq-brand"></select></label>
						<div class="mqq-catalog">
							<input type="search" class="mqq-search" placeholder="Search items…" aria-label="Search items">
							<div class="mqq-list" role="list"></div>
						</div>
						<div class="mqq-custom">
							<div class="mqq-custom-head"><strong>Custom or other work</strong><small>Sizes, materials, designs… anything not in the list.</small></div>
							<div class="mqq-custom-list"></div>
							<button type="button" class="mqq-add-custom">+ Describe a custom item</button>
							<label class="mqq-files"><span>Files or sample designs <small>(optional, up to <?php echo (int) self::MAX_FILES; ?>)</small></span><input type="file" name="files[]" multiple></label>
						</div>
						<div class="mqq-sum"><span class="mqq-sum-text">Nothing added yet</span><button type="button" class="mqq-next">Next: your details</button></div>
					</section>

					<section class="mqq-step" data-step="2" hidden>
						<h3 class="mqq-title">Your details</h3>
						<p class="mqq-sub">So we can send your quotation and contact you about your order.</p>
						<label><span>Full name <em>*</em></span><input type="text" name="name" maxlength="150" autocomplete="name" required></label>
						<div class="mqq-row">
							<label><span>Email <em>*</em></span><input type="email" name="email" maxlength="150" autocomplete="email" required></label>
							<label><span>Mobile number <em>*</em></span><input type="tel" name="phone" maxlength="40" autocomplete="tel" placeholder="09XX XXX XXXX" required></label>
						</div>
						<div class="mqq-row">
							<label><span>Company / school <small>(optional)</small></span><input type="text" name="company" maxlength="150" autocomplete="organization"></label>
							<label><span>Needed by <small>(optional)</small></span><input type="date" name="needed_by"></label>
						</div>
						<label><span>Anything else? <small>(optional)</small></span><textarea name="notes" rows="2" maxlength="1500"></textarea></label>
						<input type="text" name="website" class="mqq-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<?php echo MQP_Privacy::checkbox( 'mqq' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
						<?php if ( MQP_Settings::turnstile_on() ) : ?>
							<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></div>
						<?php endif; ?>
						<p class="mqq-msg" role="alert"></p>
						<div class="mqq-actions"><button type="button" class="mqq-back">Back</button><button type="submit" class="mqq-submit">Get my quotation</button></div>
					</section>

					<section class="mqq-step mqq-done" data-step="3" hidden></section>
				</form>
			</div>
		</div>
		<?php
		$html = ob_get_clean();
		$html = '<style id="mqp-quote-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/quote.css' ) ) . '</style>' . $html; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( did_action( 'wp_print_footer_scripts' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$html .= '<script src="' . esc_url( MQP_URL . 'assets/quote.js?ver=' . rawurlencode( mqp_asset_version() ) ) . '" defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources
		}
		return $html;
	}

	/* ---------- Request from the website ---------- */

	public static function submit( WP_REST_Request $req ) {
		global $wpdb;
		$s  = MQP_Settings::all();
		$ip = mqp_client_ip();
		$p  = $req->get_body_params();

		if ( '' !== (string) ( $p['website'] ?? '' ) ) {
			return new WP_Error( 'mqp_spam', 'Request rejected.', array( 'status' => 400 ) );
		}
		$consent = MQP_Privacy::check( $p['consent'] ?? '' );
		if ( $consent ) {
			return $consent;
		}
		if ( MQP_Guard::count( 'quote' ) >= 5 || MQP_Guard::count( 'quote_day' ) >= 15 ) {
			return new WP_Error( 'mqp_rate', 'You already sent several quotation requests. Please try again later, or message us directly.', array( 'status' => 429 ) );
		}
		if ( MQP_Settings::turnstile_on() ) {
			$resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
				'body'    => array( 'secret' => $s['turnstile_secret'], 'response' => (string) ( $p['cf-turnstile-response'] ?? '' ), 'remoteip' => $ip ),
				'timeout' => 10,
			) );
			if ( is_wp_error( $resp ) || empty( json_decode( wp_remote_retrieve_body( $resp ), true )['success'] ) ) {
				return new WP_Error( 'mqp_captcha', 'Please complete the verification and try again.', array( 'status' => 400 ) );
			}
		}

		$name    = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$phone   = sanitize_text_field( (string) ( $p['phone'] ?? '' ) );
		$company = sanitize_text_field( (string) ( $p['company'] ?? '' ) );
		$notes   = sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) );
		$needed  = mqp_valid_date( $p['needed_by'] ?? '' ) ? $p['needed_by'] : '';
		if ( mb_strlen( $name ) < 2 ) {
			return new WP_Error( 'mqp_fields', 'Please enter your name.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'mqp_fields', 'Please enter a valid email address — we send your quotation there.', array( 'status' => 400 ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 7 ) {
			return new WP_Error( 'mqp_fields', 'Please enter your mobile number.', array( 'status' => 400 ) );
		}
		$brand = MQP_REST::brand( (int) ( $p['brand_id'] ?? 0 ) );
		if ( ! $brand || ! $brand->active || ! in_array( (int) $brand->id, array_map( 'intval', array_column( self::brands(), 'id' ) ), true ) ) {
			return new WP_Error( 'mqp_fields', 'Please choose a shop.', array( 'status' => 400 ) );
		}

		// Items: fixed prices always come from the price list here, never from the browser.
		$items   = array();
		$catalog = array();
		foreach ( self::catalog()[ (int) $brand->id ] ?? array() as $c ) {
			$catalog[ $c['id'] ] = $c;
		}
		foreach ( (array) json_decode( (string) ( $p['fixed'] ?? '[]' ), true ) as $f ) {
			$id  = (int) ( $f['id'] ?? 0 );
			$qty = (int) ( $f['qty'] ?? 0 );
			if ( isset( $catalog[ $id ] ) && $qty > 0 ) {
				$items[] = array( 'description' => $catalog[ $id ]['name'], 'qty' => min( $qty, 100000 ), 'unit' => $catalog[ $id ]['unit'], 'unit_price' => $catalog[ $id ]['price'] );
			}
		}
		$custom = 0;
		foreach ( (array) json_decode( (string) ( $p['custom'] ?? '[]' ), true ) as $c ) {
			$what = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
			if ( '' === $what ) {
				continue;
			}
			$items[] = array(
				'description' => mb_substr( $what, 0, 200 ),
				'specs'       => mb_substr( sanitize_textarea_field( (string) ( $c['details'] ?? '' ) ), 0, 1500 ),
				'qty'         => max( 1, min( 100000, (int) ( $c['qty'] ?? 1 ) ) ),
				'unit'        => 'pc',
				'unit_price'  => 0,
				'is_estimate' => 1,
			);
			$custom++;
		}
		if ( ! $items ) {
			return new WP_Error( 'mqp_fields', 'Please add at least one item, or describe what you need.', array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error( 'mqp_fields', 'Please send at most ' . self::MAX_ITEMS . ' items per request.', array( 'status' => 400 ) );
		}

		// Optional files (custom work): same rules and storage protection as the upload form.
		$upload_id = null;
		$files     = MQP_Files::normalize( $req->get_file_params()['files'] ?? array() );
		if ( $files ) {
			if ( count( $files ) > self::MAX_FILES ) {
				return new WP_Error( 'mqp_fields', 'Please attach at most ' . self::MAX_FILES . ' files.', array( 'status' => 400 ) );
			}
			$room = MQP_Guard::allow_bytes( array_sum( wp_list_pluck( $files, 'size' ) ) );
			if ( is_wp_error( $room ) ) {
				return $room;
			}
			$stored = array();
			foreach ( $files as $f ) {
				$res = MQP_Files::store( $f, MQP_Files::allowed_extensions(), (int) $s['upload_max_mb'] * MB_IN_BYTES );
				if ( is_wp_error( $res ) ) {
					foreach ( $stored as $done ) {
						wp_delete_file( MQP_Files::base_dir() . '/' . $done['stored_path'] );
					}
					return new WP_Error( 'mqp_upload', $res->get_error_message(), array( 'status' => 400 ) );
				}
				$stored[] = $res;
			}
			$wpdb->insert( mqp_table( 'uploads' ), array(
				'brand_id' => (int) $brand->id, 'name' => $name, 'contact' => $phone, 'email' => $email,
				'notes' => 'Files sent with a quotation request.', 'status' => 'converted', 'ip' => $ip, 'created_at' => mqp_now(),
			) );
			$upload_id = (int) $wpdb->insert_id;
			foreach ( $stored as $row ) {
				$row['upload_id'] = $upload_id;
				$row['kind']      = 'customer';
				MQP_Files::insert( $row );
			}
			MQP_Guard::add_bytes( array_sum( wp_list_pluck( $stored, 'size' ) ) );
		}

		$doc = MQP_REST_Documents::create( array(
			'type'        => 'quotation',
			'brand_id'    => (int) $brand->id,
			'title'       => 'Website quotation request',
			'customer'    => array( 'name' => $name, 'company' => $company, 'contact' => $phone, 'email' => $email ),
			'items'       => $items,
			'notes'       => trim( ( $needed ? 'Needed by ' . $needed . ".\n" : '' ) . $notes ),
			'valid_until' => wp_date( 'Y-m-d', time() + self::VALID_DAYS * DAY_IN_SECONDS ),
			'upload_id'   => $upload_id,
		), true ); // Made by the website itself (prices from the server).
		if ( is_wp_error( $doc ) ) {
			return new WP_Error( 'mqp_quote', 'Your request could not be saved. Please try again or message us.', array( 'status' => 500 ) );
		}
		$pricing = $custom > 0;
		$token   = wp_generate_password( 32, false, false );
		$wpdb->update( mqp_table( 'documents' ), array( 'public_token' => $token, 'source' => 'website', 'status' => $pricing ? 'pricing' : 'open' ), array( 'id' => (int) $doc['id'] ) );
		if ( $upload_id ) {
			$wpdb->update( mqp_table( 'uploads' ), array( 'document_id' => (int) $doc['id'] ), array( 'id' => $upload_id ) );
		}
		MQP_Guard::bump( 'quote', HOUR_IN_SECONDS );
		MQP_Guard::bump( 'quote_day', DAY_IN_SECONDS );

		$doc['status'] = $pricing ? 'pricing' : 'open';
		$link = self::view_url( $token );
		self::email_customer( $doc, $email, $link, $pricing );
		MQP_Notify::quote_received( $doc, $pricing );
		if ( $pricing ) {
			$to = $s['order_notify_email'] ? $s['order_notify_email'] : MQP_Monitor::admin_to();
			MQP_Mail::send( $to, 'Quotation request ' . $doc['number'] . ' needs pricing', 'New quotation request',
				array( $name . ' (' . $phone . ', ' . $email . ') asked for a quotation with ' . $custom . ' custom item(s). Add the prices in the portal and click "Email to customer". They were told you reply within 24 hours.' ),
				array( 'Open in the portal', MQP_Portal::url() . '#/sales/docs/' . (int) $doc['id'] ) );
		}
		mqp_log( 'quote_requested', array( 'user_id' => 0, 'username' => $name, 'reason' => $doc['number'] . ( $pricing ? ' · needs pricing' : ' · ' . number_format( (float) $doc['total'], 2 ) ) ) );

		return array(
			'ok'      => true,
			'number'  => $doc['number'],
			'pricing' => $pricing,
			'total'   => (float) $doc['total'],
			'items'   => array_map( function ( $i ) {
				return array( 'description' => $i['description'], 'qty' => (float) $i['qty'], 'unit' => $i['unit'], 'unit_price' => (float) $i['unit_price'], 'amount' => (float) $i['amount'], 'estimate' => (bool) $i['is_estimate'] );
			}, $doc['items'] ),
			'valid_until' => $doc['valid_until'],
			'view_url'    => $link,
			'email'       => $email,
		);
	}

	public static function view_url( $token ) {
		return add_query_arg( 'mq_quote', rawurlencode( $token ), MQP_Domain::to_main( home_url( '/' ) ) );
	}

	private static function email_customer( array $doc, $email, $link, $pricing ) {
		$first = strtok( $doc['customer_name'], ' ' );
		if ( $pricing ) {
			MQP_Mail::send( $email, 'We received your quotation request ' . $doc['number'], 'Thank you, ' . $first . '!',
				array( 'We received your quotation request ' . $doc['number'] . '. Our team is pricing the custom items and will get back to you within 24 hours with your quotation.', self::items_block( $doc ) ),
				array( 'View your request', $link ) );
			return;
		}
		MQP_Mail::send( $email, 'Your quotation ' . $doc['number'], 'Your quotation is ready',
			array( 'Hi ' . $first . ', here is your quotation ' . $doc['number'] . ', valid until ' . mysql2date( 'F j, Y', $doc['valid_until'] ) . '.', self::items_block( $doc ), 'Reply to this email or message us to place your order.' ),
			array( 'View or print your quotation', $link ) );
	}

	private static function items_block( array $doc ) {
		$rows    = array();
		$waiting = false;
		foreach ( $doc['items'] as $i ) {
			$open     = $i['is_estimate'] && 0.0 === (float) $i['unit_price'];
			$waiting  = $waiting || $open;
			$rows[]   = array( $i['description'] . ' × ' . (float) $i['qty'] . ' ' . $i['unit'], $open ? 'To be priced' : '₱' . number_format( (float) $i['amount'], 2 ) );
		}
		$rows[] = array( $waiting ? 'Total so far' : 'Total', '₱' . number_format( (float) $doc['total'], 2 ) . ( $waiting ? ' + custom items' : '' ), true );
		return MQP_Mail::table( $rows );
	}

	/* ---------- Staff: send the priced quotation ---------- */

	public static function send( WP_REST_Request $req ) {
		global $wpdb;
		$doc = MQP_REST_Documents::load( (int) $req['id'] );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$invoice = 'invoice' === $doc['type'];
		if ( ! in_array( $doc['type'], array( 'quotation', 'invoice' ), true ) || in_array( $doc['status'], array( 'void', 'converted' ), true ) ) {
			return MQP_REST::error( 'Only open quotations and invoices can be emailed.' );
		}
		$word  = $invoice ? 'invoice' : 'quotation';
		$email = $doc['customer_id'] ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', $doc['customer_id'] ) ) : '';
		if ( ! is_email( $email ) ) {
			return MQP_REST::error( 'This customer has no email address. Add it to the customer, or send the ' . $word . ' another way.' );
		}
		foreach ( $doc['items'] as $i ) {
			if ( ( $i['is_estimate'] || $invoice ) && 0.0 === (float) $i['unit_price'] ) {
				return MQP_REST::error( '"' . $i['description'] . '" has no price yet. Edit the ' . $word . ' and add its price first.' );
			}
		}
		// Invoices (e.g. an online order once priced): the updated invoice with the QR codes.
		if ( $invoice ) {
			MQP_Order::email_invoice( $doc, $email );
			mqp_log( 'invoice_sent', array( 'reason' => $doc['number'] . ' → ' . $email ) );
			$out            = MQP_REST_Documents::load( (int) $doc['id'] );
			$out['sent_to'] = $email;
			return $out;
		}
		$token = $doc['public_token'] ? $doc['public_token'] : wp_generate_password( 32, false, false );
		$wpdb->update( mqp_table( 'documents' ), array( 'public_token' => $token, 'status' => 'open', 'updated_at' => mqp_now() ), array( 'id' => (int) $doc['id'] ) );
		$doc['status'] = 'open';
		self::email_customer( $doc, $email, self::view_url( $token ), false );
		mqp_log( 'quote_sent', array( 'reason' => $doc['number'] . ' → ' . $email ) );
		$out             = MQP_REST_Documents::load( (int) $doc['id'] );
		$out['sent_to']  = $email;
		$out['view_url'] = self::view_url( $token );
		return $out;
	}

	/* ---------- The customer accepts online ---------- */

	/** Why a quotation can't be accepted online ('' when it can). */
	public static function cannot_accept( array $doc ) {
		if ( 'quotation' !== $doc['type'] ) {
			return 'This is not a quotation.';
		}
		if ( 'converted' === $doc['status'] ) {
			return 'This quotation was already accepted.';
		}
		if ( 'open' !== $doc['status'] ) {
			return 'pricing' === $doc['status'] ? 'Our team is still pricing this quotation.' : 'This quotation is no longer valid. Please contact us for an updated one.';
		}
		if ( $doc['valid_until'] && $doc['valid_until'] < wp_date( 'Y-m-d' ) ) {
			return 'This quotation expired on ' . mysql2date( 'F j, Y', $doc['valid_until'] ) . '. Please contact us for an updated one.';
		}
		foreach ( $doc['items'] as $i ) {
			if ( $i['is_estimate'] && 0.0 === (float) $i['unit_price'] ) {
				return 'Our team is still pricing this quotation.';
			}
		}
		if ( (float) $doc['total'] <= 0 ) {
			return 'Please contact us to place this order.';
		}
		return '';
	}

	/**
	 * Whether a customer's quotation link has run out: "Quotation links expire" days (Settings)
	 * after the order was completed or cancelled, or after the quotation stopped being valid.
	 */
	public static function link_expired( array $doc ) {
		$days = (int) MQP_Settings::get( 'quote_link_days' );
		if ( $days <= 0 ) {
			return false;
		}
		$since = null;
		if ( 'converted' === $doc['status'] ) {
			global $wpdb;
			$inv = self::order_of( $doc );
			$job = $inv && $inv['job_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT stage, stage_changed_at FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', (int) $inv['job_id'] ), ARRAY_A ) : null;
			if ( $job && in_array( $job['stage'], array( 'delivered', 'cancelled' ), true ) ) {
				$since = $job['stage_changed_at'];
			}
		} elseif ( 'void' === $doc['status'] ) {
			$since = $doc['voided_at'] ?: $doc['updated_at'];
		} elseif ( $doc['valid_until'] ) {
			$since = $doc['valid_until'] . ' 23:59:59';
		}
		return $since && strtotime( $since ) + $days * DAY_IN_SECONDS < strtotime( mqp_now() );
	}

	/** POST /public/quote/accept { token } — the quotation becomes an invoice with a job order. */
	public static function accept( WP_REST_Request $req ) {
		global $wpdb;
		if ( MQP_Guard::limited( 'quote_accept', 10 ) ) {
			return new WP_Error( 'mqp_rate', 'Too many tries. Please try again later, or message us.', array( 'status' => 429 ) );
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'token' ) );
		$id    = strlen( $token ) >= 20 ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'documents' ) . " WHERE public_token = %s AND type = 'quotation'", $token ) ) : 0;
		if ( ! $id ) {
			return new WP_Error( 'mqp_quote', 'This quotation link is not valid.', array( 'status' => 404 ) );
		}
		$doc = MQP_REST_Documents::load( $id, false );
		if ( self::link_expired( $doc ) ) {
			return new WP_Error( 'mqp_quote', 'This quotation link has expired. Please contact us for an updated quotation.', array( 'status' => 410 ) );
		}
		$why = self::cannot_accept( $doc );
		if ( $why ) {
			return new WP_Error( 'mqp_quote', $why, array( 'status' => 409 ) );
		}
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . mqp_table( 'documents' ) . " SET status = 'accepting' WHERE id = %d AND status = 'open'", $id ) );
		if ( ! $claimed ) {
			return new WP_Error( 'mqp_quote', 'This quotation was already accepted.', array( 'status' => 409 ) );
		}
		$inv = MQP_REST_Documents::convert( $id, 'invoice', false );
		if ( is_wp_error( $inv ) ) {
			$wpdb->update( mqp_table( 'documents' ), array( 'status' => 'open' ), array( 'id' => $id ) );
			return new WP_Error( 'mqp_quote', 'Your order could not be saved. Please try again or message us.', array( 'status' => 500 ) );
		}
		$wpdb->update( mqp_table( 'documents' ), array( 'source' => 'website' ), array( 'id' => (int) $inv['id'] ) );
		if ( $inv['job_id'] ) {
			MQP_REST_Jobs::event( (int) $inv['job_id'], 'note', '', '', 'The customer accepted quotation ' . $doc['number'] . ' online.' );
		}
		MQP_Notify::quote_accepted( $doc, $inv );
		$link  = self::view_url( $token );
		$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', (int) $doc['customer_id'] ) );
		if ( is_email( $email ) ) {
			MQP_Mail::send( $email, 'Order confirmed — quotation ' . $doc['number'], 'Thank you for your order!',
				array( 'Hi ' . strtok( $doc['customer_name'], ' ' ) . ', you accepted quotation ' . $doc['number'] . ' (₱' . number_format( (float) $doc['total'], 2 ) . '). Our team will contact you shortly about payment and your files.', 'Use the link below any time to see how far your order is.' ),
				array( 'Track my order', $link ) );
		}
		$s  = MQP_Settings::all();
		$to = $s['order_notify_email'] ? $s['order_notify_email'] : MQP_Monitor::admin_to();
		MQP_Mail::send( $to, $doc['customer_name'] . ' accepted quotation ' . $doc['number'], 'Quotation accepted online',
			array( $doc['customer_name'] . ' (' . $doc['customer_contact'] . ') accepted quotation ' . $doc['number'] . '. Invoice ' . $inv['number'] . ' and job order ' . ( $inv['job_number'] ?? '' ) . ' are open in the Sales stage. Contact them about payment and files.' ),
			array( 'Open in the portal', MQP_Portal::url() . '#/sales/docs/' . (int) $inv['id'] ) );
		mqp_log( 'quote_accepted', array( 'user_id' => 0, 'username' => $doc['customer_name'], 'reason' => $doc['number'] . ' → ' . $inv['number'] ) );
		return array( 'ok' => true, 'number' => $inv['number'] );
	}

	/** The invoice (and its job order) made from a quotation, also through a billing statement. */
	public static function order_of( array $doc ) {
		global $wpdb;
		$d    = mqp_table( 'documents' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $d WHERE parent_id = %d AND status <> 'void' ORDER BY id", $doc['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			if ( 'invoice' === $r['type'] ) {
				return $r;
			}
		}
		foreach ( $rows as $r ) {
			$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $d WHERE parent_id = %d AND type = 'invoice' AND status <> 'void' ORDER BY id LIMIT 1", $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $inv ) {
				return $inv;
			}
		}
		return null;
	}

	/** Customer-facing progress of an accepted quotation, or null. */
	public static function progress( array $doc ) {
		global $wpdb;
		$inv = self::order_of( $doc );
		if ( ! $inv ) {
			return null;
		}
		$job   = $inv['job_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT number, stage FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $inv['job_id'] ), ARRAY_A ) : null;
		$stage = $job ? $job['stage'] : 'sales';
		$order = array( 'accepted', 'paid', 'printing', 'qc', 'ready', 'done' );
		$at    = (float) $inv['amount_paid'] > 0 ? 'paid' : 'accepted';
		$map   = array( 'creatives' => 'printing', 'production' => 'printing', 'qc' => 'qc', 'ready' => 'ready', 'delivered' => 'done' );
		if ( isset( $map[ $stage ] ) ) {
			$at = $map[ $stage ];
		}
		$labels = array( 'Order placed', 'Payment received', 'Printing', 'Quality check', 'Ready for pick-up / delivery', 'Completed' );
		$idx    = array_search( $at, $order, true );
		$steps  = array();
		foreach ( $order as $i => $k ) {
			$steps[] = array( 'label' => $labels[ $i ], 'done' => $i < $idx || ( $i === $idx && 'done' === $at ), 'current' => $i === $idx && 'done' !== $at );
		}
		$text = array(
			'accepted' => 'We received your order. Our team will contact you about payment and your files.',
			'paid'     => 'Payment received — your order is in the queue.',
			'printing' => 'Your order is being prepared and printed.',
			'qc'       => 'Your order is printed and in quality check.',
			'ready'    => 'Your order is ready! We will contact you for pick-up or delivery.',
			'done'     => 'Completed — thank you for choosing us!',
		)[ $at ];
		if ( 'cancelled' === $stage ) {
			$text = 'This order was cancelled. Please contact us if you have questions.';
		}
		return array(
			'key'       => 'cancelled' === $stage ? 'cancelled' : $at,
			'text'      => $text,
			'steps'     => $steps,
			'invoice'   => $inv['number'],
			'job'       => $job ? $job['number'] : '',
			'total'     => (float) $inv['total'],
			'paid'      => (float) $inv['amount_paid'],
			'placed_at' => $inv['created_at'],
		);
	}

	/** Emails customers of accepted website quotations when their job order is ready or completed. */
	public static function on_stage_changed( $job_id, $from, $to ) {
		global $wpdb;
		if ( ! in_array( $to, array( 'ready', 'delivered' ), true ) ) {
			return;
		}
		$d = mqp_table( 'documents' );
		$q = $wpdb->get_row( $wpdb->prepare( "SELECT q.* FROM $d i JOIN $d q ON q.id = i.parent_id WHERE i.job_id = %d AND q.type = 'quotation' AND q.source = 'website' AND q.public_token <> '' LIMIT 1", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $q ) {
			$q = $wpdb->get_row( $wpdb->prepare( "SELECT q.* FROM $d i JOIN $d b ON b.id = i.parent_id JOIN $d q ON q.id = b.parent_id WHERE i.job_id = %d AND q.type = 'quotation' AND q.source = 'website' AND q.public_token <> '' LIMIT 1", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$email = $q ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . mqp_table( 'customers' ) . ' WHERE id = %d', (int) $q['customer_id'] ) ) : '';
		if ( ! is_email( $email ) ) {
			return;
		}
		$first = strtok( $q['customer_name'], ' ' );
		if ( 'ready' === $to ) {
			MQP_Mail::send( $email, 'Your order is ready — quotation ' . $q['number'], 'Your order is ready!', array( 'Hi ' . $first . ', good news: your order from quotation ' . $q['number'] . ' is ready. We will contact you about pick-up or delivery.' ), array( 'See my order', self::view_url( $q['public_token'] ) ) );
		} else {
			MQP_Mail::send( $email, 'Order completed — quotation ' . $q['number'], 'Order completed', array( 'Hi ' . $first . ', your order from quotation ' . $q['number'] . ' is complete. Thank you for choosing us!' ), array( 'See my order', self::view_url( $q['public_token'] ) ) );
		}
	}

	/** Hourly: requests still "Needs pricing" after 20 hours remind the owner (once each). */
	public static function remind() {
		global $wpdb;
		$done = array_map( 'intval', (array) get_option( 'mqp_quote_reminded', array() ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . mqp_table( 'documents' ) . " WHERE type = 'quotation' AND source = 'website' AND status = 'pricing' AND created_at <= %s AND created_at >= %s ORDER BY id",
			wp_date( 'Y-m-d H:i:s', time() - self::REMIND_HOURS * HOUR_IN_SECONDS ),
			wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
		), ARRAY_A );
		$late = array();
		foreach ( $rows as $r ) {
			if ( in_array( (int) $r['id'], $done, true ) ) {
				continue;
			}
			$hours = (int) floor( ( time() - strtotime( get_gmt_from_date( $r['created_at'] ) . ' UTC' ) ) / HOUR_IN_SECONDS );
			MQP_Notify::quote_late( $r, $hours );
			$late[] = $r['number'] . ' — ' . $r['customer_name'] . ' (' . $r['customer_contact'] . '), ' . $hours . ' hours ago';
			$done[] = (int) $r['id'];
		}
		if ( $late ) {
			$s  = MQP_Settings::all();
			$to = $s['order_notify_email'] ? $s['order_notify_email'] : MQP_Monitor::admin_to();
			MQP_Mail::send( $to, count( $late ) . ' quotation request(s) still need pricing', 'Quotation requests waiting',
				array_merge( array( 'These website quotation requests are not priced yet. The customers were told you reply within 24 hours:' ), $late ),
				array( 'Open the portal', MQP_Portal::url() . '#/sales/docs' ) );
			update_option( 'mqp_quote_reminded', array_slice( $done, -300 ), false );
		}
	}

	/** Website quotation requests waiting for prices, and how many are past the reminder time. */
	public static function pricing_counts() {
		global $wpdb;
		$t = mqp_table( 'documents' );
		return array(
			'waiting' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE type = 'quotation' AND status = 'pricing'" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'late'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE type = 'quotation' AND status = 'pricing' AND created_at <= %s", wp_date( 'Y-m-d H:i:s', time() - self::REMIND_HOURS * HOUR_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/* ---------- The customer's page: /?mq_quote=… ---------- */

	public static function public_view() {
		if ( empty( $_GET['mq_quote'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['mq_quote'] ) ); // phpcs:ignore WordPress.Security
		$row   = strlen( $token ) >= 20 ? $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'documents' ) . " WHERE public_token = %s AND type = 'quotation'", $token ) ) : null;
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'MQ Portal: customer quotation' );
		if ( ! $row ) {
			status_header( 404 );
			wp_die( 'This quotation link is not valid. Please check the link in your email, or contact us.', 'Quotation not found', array( 'response' => 404 ) );
		}
		$doc = MQP_REST_Documents::load( (int) $row->id, false );
		if ( self::link_expired( $doc ) ) {
			status_header( 410 );
			wp_die( 'This quotation link has expired. Please contact us if you need a copy of your quotation or invoice.', 'Link expired', array( 'response' => 410 ) );
		}
		$s   = MQP_Settings::all();
		include MQP_DIR . 'templates/quote.php';
		exit;
	}
}
