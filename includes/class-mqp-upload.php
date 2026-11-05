<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public customer file upload: [mq_upload] shortcode + POST /mq/v1/public/upload.
 *
 * While online ordering is on (portal → Admin → Settings → Online orders), "Upload Document"
 * IS the order: [mq_upload] shows the big upload box, and choosing files (or any #mq-upload
 * button) opens the order steps in a pop-up — notes per file, no price, pay later (MQP_Order).
 * Only when online ordering is off does the older "send files, we contact you" form below
 * come back. Its uploads land in the Sales tab's Uploads inbox.
 */
class MQP_Upload {

	public static function init() {
		add_shortcode( 'mq_upload', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_popup' ), 5 );
	}

	/** True once a form was printed on this page. */
	private static $rendered = false;

	/**
	 * Every website page gets the pop-up (hidden), so any button that links to #mq-upload, or has
	 * the CSS class mq-upload-open (header, menu, hero…), opens the upload box and form in a pop-up.
	 * Skipped when the page already has [mq_upload]: buttons then open that form's pop-up.
	 * Turn off with: add_filter( 'mqp_upload_popup_everywhere', '__return_false' );
	 */
	public static function footer_popup() {
		if ( self::uses_order() ) {
			return; // #mq-upload buttons open the order pop-up (MQP_Order::footer_popup()).
		}
		if ( self::$rendered || is_admin() || is_feed() || is_embed() || ! apply_filters( 'mqp_upload_popup_everywhere', true ) ) {
			return;
		}
		echo self::shortcode( array( 'box' => 'no' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in shortcode().
	}

	public static function routes() {
		register_rest_route(
			'mq/v1',
			'/public/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * [mq_upload brand="EP" branch="M1" text="Start Uploading PDF Files" title="Your details" box="no"]
	 * brand/branch are codes; ?branch=CODE in the URL also works (e.g. per-branch QR codes).
	 * Customers first see one big upload box; the details form appears once files are chosen.
	 * box="no": no box on the page, only the pop-up that buttons linking to #mq-upload open.
	 */
	/** "Upload Document" opens the order steps (online ordering is on). */
	public static function uses_order() {
		return (bool) MQP_Settings::get( 'order_enabled' ) && apply_filters( 'mqp_upload_opens_order', true );
	}

	public static function shortcode( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'brand' => '', 'branch' => '', 'text' => '', 'title' => 'Almost done! Tell us about your order', 'popup_title' => 'Upload your files', 'box' => 'yes' ), $atts );
		$box  = ! in_array( strtolower( (string) $atts['box'] ), array( 'no', '0', 'false', 'off' ), true );
		if ( self::uses_order() ) {
			return $box ? self::order_box( $atts ) : '';
		}
		$first = ! self::$rendered;
		self::$rendered = true;
		$s    = MQP_Settings::all();

		$brands   = $wpdb->get_results( 'SELECT id, name, code, has_branches FROM ' . mqp_table( 'brands' ) . ' WHERE active = 1 ORDER BY sort, id' );
		$branches = $wpdb->get_results( 'SELECT id, brand_id, name, code FROM ' . mqp_table( 'branches' ) . ' WHERE active = 1 ORDER BY id' );

		$branch_code = isset( $_GET['branch'] ) ? sanitize_text_field( wp_unslash( $_GET['branch'] ) ) : $atts['branch']; // phpcs:ignore WordPress.Security.NonceVerification
		$preset_branch = 0;
		$preset_brand  = 0;
		foreach ( $branches as $b ) {
			if ( $branch_code && 0 === strcasecmp( $b->code, $branch_code ) ) {
				$preset_branch = (int) $b->id;
				$preset_brand  = (int) $b->brand_id;
			}
		}
		foreach ( $brands as $b ) {
			if ( ! $preset_brand && $atts['brand'] && 0 === strcasecmp( $b->code, $atts['brand'] ) ) {
				$preset_brand = (int) $b->id;
			}
		}
		if ( ! $preset_brand && $brands ) {
			$preset_brand = (int) $brands[0]->id;
		}

		wp_enqueue_script( 'mqp-upload', MQP_URL . 'assets/upload.js', array(), mqp_asset_version(), true );
		if ( MQP_Settings::turnstile_on() && $box ) { // The pop-up-only form loads the spam check when it opens.
			wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
		}

		$types  = self::allowed();
		$accept = implode( ',', array_map( function ( $e ) {
			return '.' . $e;
		}, $types ) );
		// Button text: "Start Uploading PDF Files" when only PDFs are accepted.
		$text = '' !== $atts['text'] ? $atts['text'] : ( array( 'pdf' ) === $types ? 'Start Uploading PDF Files' : 'Start Uploading Files' );
		$hint = strtoupper( implode( ', ', $types ) ) . ' · up to ' . (int) $s['upload_max_mb'] . ' MB each · or drag files here';

		// Result of a plain (no-JavaScript) submit, after handle() sent the visitor back here.
		$done = '';
		if ( isset( $_GET['mqp_upload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$done = 'ok' === $_GET['mqp_upload'] // phpcs:ignore WordPress.Security.NonceVerification
				? array( 'is-ok', $s['upload_success_text'] . ' Reference: ' . sanitize_text_field( wp_unslash( (string) ( $_GET['mqp_upload_ref'] ?? '' ) ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
				: array( 'is-error', self::error_text( sanitize_key( wp_unslash( (string) $_GET['mqp_upload'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$icon = '<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M7 8l5-5 5 5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>';

		ob_start();
		?>
		<form class="mqp-upload mqp-up<?php echo $box ? '' : ' is-popup-only'; ?>"<?php echo $first ? ' id="mqp-upload"' : ''; ?><?php echo $box ? '' : ' hidden'; ?> novalidate method="post" enctype="multipart/form-data"
			action="<?php echo esc_url( rest_url( 'mq/v1/public/upload' ) ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'mq/v1/public/upload' ) ); ?>"
			data-max="<?php echo (int) $s['upload_max_mb'] * MB_IN_BYTES; ?>"
			data-types="<?php echo esc_attr( implode( ',', $types ) ); ?>"
			data-success="<?php echo esc_attr( $s['upload_success_text'] ); ?>"
			data-title="<?php echo esc_attr( $atts['title'] ); ?>"
			data-popup-title="<?php echo esc_attr( $atts['popup_title'] ); ?>">

			<?php if ( $box ) : /* Step 1: the big orange box. Click or drop files on it. */ ?>
			<label class="mqp-drop mqp-drop-main">
				<input type="file" name="files[]" multiple accept="<?php echo esc_attr( $accept ); ?>">
				<span class="mqp-drop-inner">
					<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?>
					<strong><?php echo esc_html( $text ); ?></strong>
					<small><?php echo esc_html( $hint ); ?></small>
				</span>
			</label>
			<?php endif; ?>

			<?php
			/*
			 * Steps 2 and 3 open in a pop-up once files are chosen: chosen files + details form,
			 * then the reference number. Without JavaScript they simply show under the box.
			 */
			?>
			<div class="mqp-modal mqp-up" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
			<div class="mqp-modal-box">
			<button type="button" class="mqp-close" aria-label="Close">&times;</button>
			<p class="mqp-details-title"><?php echo esc_html( $atts['title'] ); ?></p>
			<?php /* The same upload box inside the pop-up, for buttons that open it before any file is chosen. */ ?>
			<label class="mqp-drop mqp-drop-mini">
				<input type="file" multiple accept="<?php echo esc_attr( $accept ); ?>" aria-label="<?php echo esc_attr( $text ); ?>">
				<span class="mqp-drop-inner">
					<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?>
					<strong><?php echo esc_html( $text ); ?></strong>
					<small><?php echo esc_html( $hint ); ?></small>
				</span>
			</label>
			<div class="mqp-picked" hidden>
				<div class="mqp-picked-head">
					<strong class="mqp-picked-count"></strong>
					<button type="button" class="mqp-add">+ Add more files</button>
				</div>
				<ul class="mqp-file-list"></ul>
			</div>

			<div class="mqp-details">
				<div class="mqp-row">
					<label><span>Your name <em>*</em></span><input type="text" name="name" required maxlength="150" autocomplete="name" placeholder="Juan Dela Cruz"></label>
					<label><span>Mobile number <em>*</em></span><input type="tel" name="contact" required maxlength="40" autocomplete="tel" placeholder="09XX XXX XXXX"></label>
				</div>
				<label><span>Email <small>(optional)</small></span><input type="email" name="email" maxlength="150" autocomplete="email" placeholder="you@email.com"></label>
				<div class="mqp-row">
					<label><span>Brand</span>
						<select name="brand_id" class="mqp-brand">
							<?php foreach ( $brands as $b ) : ?>
								<option value="<?php echo (int) $b->id; ?>" data-branches="<?php echo (int) $b->has_branches; ?>" <?php selected( $preset_brand, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="mqp-branch-wrap"><span>Branch</span>
						<select name="branch_id" class="mqp-branch">
							<?php foreach ( $branches as $b ) : ?>
								<option value="<?php echo (int) $b->id; ?>" data-brand="<?php echo (int) $b->brand_id; ?>" <?php selected( $preset_branch, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label><span>Instructions</span><textarea name="notes" rows="3" maxlength="2000" placeholder="Size, number of copies, paper type, color or black &amp; white, when you need it…"></textarea></label>
				<input type="text" name="website" class="mqp-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<?php echo MQP_Privacy::checkbox( 'mqp' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				<?php if ( MQP_Settings::turnstile_on() ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></div>
				<?php endif; ?>
				<button type="submit" class="mqp-submit">Upload files</button>
				<div class="mqp-progress" hidden><div></div></div>
			</div>

			<p class="mqp-msg<?php echo $done ? ' ' . esc_attr( $done[0] ) : ''; ?>" role="status"><?php echo $done ? esc_html( $done[1] ) : ''; ?></p>

			<?php /* Step 3: thank you. */ ?>
			<div class="mqp-sent" hidden>
				<span class="mqp-sent-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
				<strong>Files received!</strong>
				<p class="mqp-sent-text"></p>
				<p class="mqp-sent-ref"></p>
				<small>Keep this number. We will contact you on the mobile number you gave.</small>
				<button type="button" class="mqp-again">Done</button>
			</div>
			</div>
			</div>
		</form>
		<?php
		$html = ob_get_clean();

		// The look is printed with the form (once per page), so it never depends on a stylesheet
		// that a theme, page builder or caching plugin might skip.
		static $styled = false;
		if ( ! $styled ) {
			$styled = true;
			$html   = '<style id="mqp-upload-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/upload.css' ) ) . '</style>' . $html; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// Rendered after the page's scripts were printed (pop-ups, page builders loading content later):
		// bring the script along, since the enqueue above came too late.
		static $inline = false;
		if ( ! $inline && ( did_action( 'wp_print_footer_scripts' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			$inline = true;
			$html  .= '<script src="' . esc_url( MQP_URL . 'assets/upload.js?ver=' . rawurlencode( mqp_asset_version() ) ) . '" defer></script>' // phpcs:ignore WordPress.WP.EnqueuedResources
				. ( MQP_Settings::turnstile_on() ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' : '' ); // phpcs:ignore WordPress.WP.EnqueuedResources
		}
		return $html;
	}

	/**
	 * The big upload box that starts an order: the chosen or dropped files are handed to the
	 * order steps in the pop-up (files and notes → pick up or courier → contact → review).
	 */
	private static function order_box( array $atts ) {
		$s     = MQP_Settings::all();
		$first = ! self::$rendered;
		self::$rendered = true;
		$text  = '' !== $atts['text'] ? $atts['text'] : 'Start Uploading Files';
		// The file types as one italic line of text (owner's wording, 2026-11-05).
		$hint  = array( '✔️ PDF format for documents', '✔️ JPEG / PNG format for images', '✔️ JPEG / PNG / PSD format for HD designs' );
		$url   = MQP_Order::url();
		$icon  = '<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M7 8l5-5 5 5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>';
		MQP_Order::need_popup();
		ob_start();
		?>
		<div class="mqp-upload mqp-up mqp-order-start"<?php echo $first ? ' id="mqp-upload"' : ''; ?> data-order="<?php echo esc_url( $url ); ?>">
			<label class="mqp-drop mqp-drop-main">
				<input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.psd" aria-label="<?php echo esc_attr( $text ); ?>">
				<span class="mqp-drop-inner">
					<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?>
					<strong><?php echo esc_html( $text ); ?></strong>
					<small class="mqp-drop-types"><em><?php echo esc_html( implode( '  ', $hint ) ); ?></em></small>
				</span>
			</label>
			<noscript><p class="mqp-msg"><a href="<?php echo esc_url( $url ); ?>">Start your order</a></p></noscript>
		</div>
		<?php
		$html = ob_get_clean();
		static $styled = false;
		if ( ! $styled ) {
			$styled = true;
			$html   = '<style id="mqp-upload-css">' . wp_strip_all_tags( (string) file_get_contents( MQP_DIR . 'assets/upload.css' ) ) . '</style>' . $html; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $html;
	}

	/** Friendly text for a failed plain (no-JavaScript) submit. Only fixed texts, never text from the link. */
	private static function error_text( $code ) {
		$texts = array(
			'mqp_spam'    => 'Upload rejected.',
			'mqp_rate'    => 'Too many uploads. Please try again later or email us your files.',
			'mqp_captcha' => 'Please complete the verification and try again.',
			'mqp_fields'  => 'Please enter your name and mobile number, choose a brand and at least one file.',
			'mqp_upload'  => 'One of the files was not accepted (file type or size). Please check and try again.',
			'mqp_storage' => 'We can\'t accept more files right now. Please try again tomorrow, or email or message us your files.',
			'mqp_consent' => 'Please tick the box to agree to how we use your details (Privacy Policy).',
		);
		return $texts[ $code ] ?? 'Upload failed. Please try again or email us your files.';
	}

	private static function allowed() {
		return MQP_Files::allowed_extensions();
	}

	/**
	 * The form posts here with JavaScript (answer: JSON) or, if scripts were blocked, as a normal
	 * form (answer: back to the page with the result in the link).
	 */
	public static function handle( WP_REST_Request $req ) {
		$res = self::process( $req );
		if ( false === strpos( (string) $req->get_header( 'accept' ), 'text/html' ) ) {
			return $res;
		}
		$back = wp_validate_redirect( (string) $req->get_header( 'referer' ), home_url( '/' ) );
		$back = remove_query_arg( array( 'mqp_upload', 'mqp_upload_ref' ), strtok( $back, '#' ) );
		$back = is_wp_error( $res )
			? add_query_arg( 'mqp_upload', $res->get_error_code(), $back )
			: add_query_arg( array( 'mqp_upload' => 'ok', 'mqp_upload_ref' => $res['reference'] ), $back );
		$out = new WP_REST_Response( is_wp_error( $res ) ? array( 'ok' => false ) : $res, 303 );
		$out->header( 'Location', $back . '#mqp-upload' );
		return $out;
	}

	private static function process( WP_REST_Request $req ) {
		global $wpdb;
		$s = MQP_Settings::all();

		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return new WP_Error( 'mqp_spam', 'Upload rejected.', array( 'status' => 400 ) );
		}
		$consent = MQP_Privacy::check( $req->get_param( 'consent' ) );
		if ( $consent ) {
			return $consent;
		}

		$ip    = mqp_client_ip();
		$key   = 'mqp_up_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= (int) $s['upload_rate_per_hour'] ) {
			return new WP_Error( 'mqp_rate', 'Too many uploads. Please try again later or email us your files.', array( 'status' => 429 ) );
		}

		if ( MQP_Settings::turnstile_on() ) {
			$resp = wp_remote_post(
				'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				array(
					'body'    => array(
						'secret'   => $s['turnstile_secret'],
						'response' => (string) $req->get_param( 'cf-turnstile-response' ),
						'remoteip' => $ip,
					),
					'timeout' => 10,
				)
			);
			$ok = ! is_wp_error( $resp ) && ! empty( json_decode( wp_remote_retrieve_body( $resp ), true )['success'] );
			if ( ! $ok ) {
				return new WP_Error( 'mqp_captcha', 'Please complete the verification and try again.', array( 'status' => 400 ) );
			}
		}

		$name    = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$contact = sanitize_text_field( (string) $req->get_param( 'contact' ) );
		$email   = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( '' === $name || '' === $contact ) {
			return new WP_Error( 'mqp_fields', 'Please enter your name and mobile number.', array( 'status' => 400 ) );
		}

		$brand_id = (int) $req->get_param( 'brand_id' );
		$brand    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'brands' ) . ' WHERE id = %d AND active = 1', $brand_id ) );
		if ( ! $brand ) {
			return new WP_Error( 'mqp_fields', 'Please choose a brand.', array( 'status' => 400 ) );
		}
		$branch_id = null;
		if ( $brand->has_branches ) {
			$branch_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'branches' ) . ' WHERE id = %d AND brand_id = %d AND active = 1', (int) $req->get_param( 'branch_id' ), $brand->id ) );
			$branch_id = $branch_id ? $branch_id : null;
		}

		$files = MQP_Files::normalize( $req->get_file_params()['files'] ?? array() );
		if ( ! $files ) {
			return new WP_Error( 'mqp_fields', 'Please choose at least one file.', array( 'status' => 400 ) );
		}
		if ( count( $files ) > 20 ) {
			return new WP_Error( 'mqp_fields', 'Please upload at most 20 files at a time.', array( 'status' => 400 ) );
		}
		// Storage protection: daily megabytes per connection and for the whole site, storage cap.
		$incoming = array_sum( array_map( function ( $f ) {
			return (int) ( $f['size'] ?? 0 );
		}, $files ) );
		$room     = MQP_Guard::allow_bytes( $incoming );
		if ( is_wp_error( $room ) ) {
			return $room;
		}

		$stored = array();
		foreach ( $files as $f ) {
			$res = MQP_Files::store( $f, self::allowed(), (int) $s['upload_max_mb'] * MB_IN_BYTES );
			if ( is_wp_error( $res ) ) {
				foreach ( $stored as $done ) {
					wp_delete_file( MQP_Files::base_dir() . '/' . $done['stored_path'] );
				}
				return new WP_Error( 'mqp_upload', $res->get_error_message(), array( 'status' => 400 ) );
			}
			$stored[] = $res;
		}

		$wpdb->insert(
			mqp_table( 'uploads' ),
			array(
				'brand_id'   => (int) $brand->id,
				'branch_id'  => $branch_id,
				'name'       => $name,
				'contact'    => $contact,
				'email'      => $email,
				'notes'      => sanitize_textarea_field( (string) $req->get_param( 'notes' ) ),
				'ip'         => $ip,
				'created_at' => mqp_now(),
			)
		);
		$upload_id = (int) $wpdb->insert_id;
		MQP_REST::upsert_customer( $name, '', $contact, $email );
		foreach ( $stored as $row ) {
			$row['upload_id'] = $upload_id;
			$row['kind']      = 'customer';
			MQP_Files::insert( $row );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		MQP_Guard::add_bytes( array_sum( wp_list_pluck( $stored, 'size' ) ) );
		MQP_Notify::upload_received( $upload_id, $name, (int) $brand->id );

		return array(
			'ok'        => true,
			'reference' => sprintf( 'U-%05d', $upload_id ),
			'message'   => $s['upload_success_text'],
		);
	}
}
