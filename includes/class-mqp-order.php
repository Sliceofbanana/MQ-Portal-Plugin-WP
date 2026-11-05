<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public online ordering for MQ Printing Services.
 *
 * - Customer page at /order/ (and /order/track/), linked from the website with [mq_order_button].
 * - No customer account: the order number is emailed to the customer and used to follow up
 *   on the Facebook page chat.
 * - No price on the page and no payment needed right away: customers upload files (PDF for
 *   documents, PSD for shirts, tarpaulins and stickers) with a note each, and staff set the
 *   price on the invoice. The payment QR codes are shown so they can save them and pay later.
 * - Each order becomes an Invoice (items at ₱0, to be priced) + Job order in the portal.
 */
class MQP_Order {

	const CONFIG_OPTION = 'mqp_order_config';
	const SLUG          = 'order';

	/* ---------- Price list (editable in Admin → Settings → Online orders) ---------- */

	public static function default_config() {
		$qr = MQP_URL . 'assets/order/';
		return array(
			'paper_sizes'   => array(
				array( 'id' => 'short', 'name' => 'Short (8.5×11)' ),
				array( 'id' => 'a4', 'name' => 'A4' ),
				array( 'id' => 'long', 'name' => 'Long (8.5×13)' ),
				array( 'id' => 'a3', 'name' => 'A3' ),
			),
			'print_modes'   => array(
				array( 'id' => 'black', 'name' => 'Black & White' ),
				array( 'id' => 'partial', 'name' => 'Partial Color' ),
				array( 'id' => 'full', 'name' => 'Full Color' ),
				array( 'id' => 'borderless', 'name' => 'Borderless' ),
			),
			// Price per page: [paper][mode].
			'print_prices'  => array(
				'short' => array( 'black' => 1.75, 'partial' => 2.75, 'full' => 8.5, 'borderless' => 17 ),
				'a4'    => array( 'black' => 2, 'partial' => 3, 'full' => 9, 'borderless' => 18 ),
				'long'  => array( 'black' => 2.5, 'partial' => 4, 'full' => 10, 'borderless' => 20 ),
				'a3'    => array( 'black' => 10, 'partial' => 15, 'full' => 20, 'borderless' => 30 ),
			),
			// Binding per copy, by total pages: first tier whose max_pages ≥ pages, else the last price.
			'binding'       => array(
				array( 'id' => 'book-soft', 'name' => 'Book bind (soft cover)', 'tiers' => array( array( 150, 300 ), array( 300, 350 ), array( 0, 400 ) ) ),
				array( 'id' => 'book-hard', 'name' => 'Book bind (hard cover)', 'tiers' => array( array( 150, 400 ), array( 300, 450 ), array( 0, 500 ) ) ),
				array( 'id' => 'wire-soft', 'name' => 'Wire bind (soft cover)', 'tiers' => array( array( 50, 60 ), array( 100, 80 ), array( 0, 80 ) ) ),
				array( 'id' => 'wire-hard', 'name' => 'Wire bind (hard cover)', 'tiers' => array( array( 50, 110 ), array( 100, 130 ), array( 0, 130 ) ) ),
			),
			'rush_id'       => array(
				array( 'id' => '1x1-basic', 'name' => '1x1 Basic ID', 'copies' => 4, 'price' => 50, 'turnaround' => '30 minutes' ),
				array( 'id' => '1x1-rush', 'name' => '1x1 Rush ID', 'copies' => 4, 'price' => 70, 'turnaround' => '15 minutes' ),
				array( 'id' => '2x2-basic', 'name' => '2x2 Basic ID', 'copies' => 4, 'price' => 60, 'turnaround' => '30 minutes' ),
				array( 'id' => '2x2-rush', 'name' => '2x2 Rush ID', 'copies' => 4, 'price' => 80, 'turnaround' => '15 minutes' ),
				array( 'id' => 'passport-basic', 'name' => 'Passport Size Basic', 'copies' => 4, 'price' => 70, 'turnaround' => '30 minutes' ),
				array( 'id' => 'passport-rush', 'name' => 'Passport Size Rush', 'copies' => 4, 'price' => 90, 'turnaround' => '15 minutes' ),
			),
			'supplies'      => array(
				array( 'id' => 'bond-short', 'name' => 'Bond paper Short', 'unit' => '1 pack (100 pcs)', 'price' => 75 ),
				array( 'id' => 'bond-a4', 'name' => 'Bond paper A4', 'unit' => '1 pack (100 pcs)', 'price' => 80 ),
				array( 'id' => 'bond-long', 'name' => 'Bond paper Long', 'unit' => '1 pack (100 pcs)', 'price' => 85 ),
				array( 'id' => 'board-a4-250gsm', 'name' => 'Board paper 250 GSM A4', 'unit' => '1 pack (20 pcs)', 'price' => 80 ),
				array( 'id' => 'folder-short', 'name' => 'Folder white Short', 'unit' => '1 pc', 'price' => 18 ),
				array( 'id' => 'folder-long', 'name' => 'Folder white Long', 'unit' => '1 pc', 'price' => 22 ),
				array( 'id' => 'fastener', 'name' => 'Fastener', 'unit' => '1 pc', 'price' => 8 ),
				array( 'id' => 'envelope-short', 'name' => 'Envelope brown Short', 'unit' => '1 pc', 'price' => 20 ),
				array( 'id' => 'envelope-long', 'name' => 'Envelope brown Long', 'unit' => '1 pc', 'price' => 25 ),
			),
			'campus_fee'    => 10,
			'campuses'      => array(
				'University of San Carlos - Main Campus',
				'University of San Carlos - Talamban Campus',
				'Cebu Institute of Technology - Main',
				'University of Cebu - Main',
				'Cebu Normal University',
				'University of San Jose Recoletos',
				'Southwestern University - Urgello',
				'University of the Philippines Cebu',
			),
			'courier_note'  => 'We message you on Messenger when your order is ready; then you book the rider yourself. The delivery fee is paid to the rider.',
			// Last line on the Pick up and courier choices on the order page (owner, 2026-11-06).
			'pickup_hint'   => 'Message us via Facebook Page for pick up time.',
			'courier_hint'  => 'Inform the rider to call 09205177884 when arrived at our location.',
			// Message staff copy when an order is ready. {name} = customer, {order} = job order number.
			'ready_message' => 'Hi {name}! This is MQ Prints. Your order {order} is now ready for delivery. Daghang salamat!',
			// Pick up: one place for now (more can come back later as a list).
			'pickup'        => array(
				'name'    => 'MQ Printing Services Main Office',
				'address' => '',
				'map'     => 'MQ Printing Services', // Google Maps search words, or a full Google Maps link.
			),
			// Where customers pin the pick-up point in each courier app.
			'couriers'      => array(
				array( 'id' => 'maxim', 'name' => 'Maxim', 'pin' => 'MQ Printing Services' ),
				array( 'id' => 'lalamove', 'name' => 'Lalamove', 'pin' => 'Paks Sinabaw, Cebu City' ),
				array( 'id' => 'grab', 'name' => 'Grab Express', 'pin' => 'Angel Pharmacy - V. Rama' ),
			),
			'note_guide'    => 'No. of copies or quantity, colored or black & white, size, paper, binding, when you need it…',
			'messenger_url' => '', // Facebook page chat, e.g. https://m.me/mqprintsph (follow-ups use the order number).
			'payment'       => array(
				array( 'id' => 'gcash', 'name' => 'GCash', 'account_name' => '', 'account_number' => '', 'qr' => $qr . 'gcash-qr.webp' ),
				array( 'id' => 'unionbank', 'name' => 'UnionBank', 'account_name' => '', 'account_number' => '', 'qr' => $qr . 'unionbank-qr.webp' ),
			),
			'notice'        => 'PDF for documents · JPEG / PNG for images · JPEG / PNG / PSD for HD designs. We confirm the price with you before printing.',
		);
	}

	/** What customers can upload: each kind with its file types (fixed, not saved with the settings). */
	public static function kinds() {
		// Documents are PDF; images JPEG / PNG; HD designs JPEG / PNG / PSD (owner, 2026-11-05).
		$any = array( 'pdf', 'jpg', 'jpeg', 'png', 'psd' );
		return array(
			array( 'id' => 'document', 'name' => 'Document and Certificate Printing', 'ext' => array( 'pdf' ) ),
			array( 'id' => 'garment', 'name' => 'Shirts and Other Garments Printing', 'ext' => $any ),
			array( 'id' => 'giveaway', 'name' => 'Customized Giveaways', 'ext' => $any ),
			array( 'id' => 'cards', 'name' => 'Cards and Invitations', 'ext' => $any ),
			array( 'id' => 'bind', 'name' => 'Print and Bind', 'ext' => array( 'pdf' ) ),
			array( 'id' => 'brochure', 'name' => 'Brochures and Flyers Printing', 'ext' => $any ),
			array( 'id' => 'sticker', 'name' => 'Sticker Printing', 'ext' => $any ),
			array( 'id' => 'packaging', 'name' => 'Product Packaging Printing', 'ext' => $any ),
			array( 'id' => 'other', 'name' => 'Others', 'ext' => $any, 'specify' => true ),
		);
	}

	/** Texts of earlier versions: a saved copy still equal to one of them follows the new default. */
	const OLD_TEXTS = array(
		'courier_note' => 'You book the Maxim/Lalamove rider; the delivery fee is paid to the rider.',
		'notice'       => array(
			'Online orders support PDF files for documents and PNG/JPEG for images. For other printing needs, please message us.',
			'Documents: PDF files (we count the pages). Shirts, tarpaulins and stickers: PSD files. We confirm the price with you before printing.',
		),
	);

	public static function config() {
		$def   = self::default_config();
		$saved = get_option( self::CONFIG_OPTION, array() );
		$cfg   = is_array( $saved ) && $saved ? array_merge( $def, $saved ) : $def;
		foreach ( self::OLD_TEXTS as $k => $old ) {
			if ( in_array( $cfg[ $k ], (array) $old, true ) ) {
				$cfg[ $k ] = $def[ $k ];
			}
		}
		$cfg['kinds'] = self::kinds();
		return $cfg;
	}

	/** Validates an edited price list from the admin screen. Unknown keys are dropped. */
	public static function save_config( array $in ) {
		$def = self::default_config();
		$cfg = self::config();
		$num = function ( $v ) {
			return max( 0, round( (float) $v, 2 ) );
		};
		$txt = function ( $v, $len = 150 ) {
			return substr( sanitize_text_field( (string) $v ), 0, $len );
		};
		// Rows added on the admin screen have no id yet, so it is made from the name.
		$id  = function ( $v ) {
			return substr( sanitize_key( str_replace( ' ', '-', (string) $v ) ), 0, 40 );
		};

		if ( isset( $in['print_prices'] ) && is_array( $in['print_prices'] ) ) {
			foreach ( $def['paper_sizes'] as $p ) {
				foreach ( $def['print_modes'] as $m ) {
					if ( isset( $in['print_prices'][ $p['id'] ][ $m['id'] ] ) ) {
						$cfg['print_prices'][ $p['id'] ][ $m['id'] ] = $num( $in['print_prices'][ $p['id'] ][ $m['id'] ] );
					}
				}
			}
		}
		if ( isset( $in['binding'] ) && is_array( $in['binding'] ) ) {
			$cfg['binding'] = array();
			foreach ( $in['binding'] as $b ) {
				if ( empty( $b['name'] ) ) {
					continue;
				}
				$tiers = array();
				foreach ( (array) ( $b['tiers'] ?? array() ) as $t ) {
					$tiers[] = array( max( 0, (int) ( $t[0] ?? 0 ) ), $num( $t[1] ?? 0 ) );
				}
				$cfg['binding'][] = array( 'id' => $id( ! empty( $b['id'] ) ? $b['id'] : $b['name'] ), 'name' => $txt( $b['name'] ), 'tiers' => $tiers ? $tiers : array( array( 0, 0 ) ) );
			}
		}
		if ( isset( $in['rush_id'] ) && is_array( $in['rush_id'] ) ) {
			$cfg['rush_id'] = array();
			foreach ( $in['rush_id'] as $r ) {
				if ( ! empty( $r['name'] ) ) {
					$cfg['rush_id'][] = array( 'id' => $id( ! empty( $r['id'] ) ? $r['id'] : $r['name'] ), 'name' => $txt( $r['name'] ), 'copies' => max( 1, (int) ( $r['copies'] ?? 1 ) ), 'price' => $num( $r['price'] ?? 0 ), 'turnaround' => $txt( $r['turnaround'] ?? '', 40 ) );
				}
			}
		}
		if ( isset( $in['supplies'] ) && is_array( $in['supplies'] ) ) {
			$cfg['supplies'] = array();
			foreach ( $in['supplies'] as $s ) {
				if ( ! empty( $s['name'] ) ) {
					$cfg['supplies'][] = array( 'id' => $id( ! empty( $s['id'] ) ? $s['id'] : $s['name'] ), 'name' => $txt( $s['name'] ), 'unit' => $txt( $s['unit'] ?? '', 40 ), 'price' => $num( $s['price'] ?? 0 ) );
				}
			}
		}
		if ( isset( $in['campus_fee'] ) ) {
			$cfg['campus_fee'] = $num( $in['campus_fee'] );
		}
		if ( isset( $in['campuses'] ) ) {
			$list            = is_array( $in['campuses'] ) ? $in['campuses'] : preg_split( '/\r?\n/', (string) $in['campuses'] );
			$cfg['campuses'] = array_values( array_filter( array_map( $txt, $list ) ) );
		}
		if ( isset( $in['payment'] ) && is_array( $in['payment'] ) ) {
			$cfg['payment'] = array();
			foreach ( $in['payment'] as $p ) {
				if ( ! empty( $p['name'] ) ) {
					$cfg['payment'][] = array(
						'id'             => $id( ! empty( $p['id'] ) ? $p['id'] : $p['name'] ),
						'name'           => $txt( $p['name'], 40 ),
						'account_name'   => $txt( $p['account_name'] ?? '' ),
						'account_number' => $txt( $p['account_number'] ?? '', 60 ),
						'qr'             => esc_url_raw( (string) ( $p['qr'] ?? '' ) ),
					);
				}
			}
		}
		foreach ( array( 'courier_note', 'notice', 'note_guide', 'pickup_hint', 'courier_hint', 'ready_message' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$cfg[ $k ] = sanitize_textarea_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $in['messenger_url'] ) ) {
			$cfg['messenger_url'] = esc_url_raw( (string) $in['messenger_url'] );
		}
		if ( isset( $in['pickup'] ) && is_array( $in['pickup'] ) ) {
			$name          = $txt( $in['pickup']['name'] ?? '' );
			$cfg['pickup'] = array(
				'name'    => '' !== $name ? $name : $def['pickup']['name'],
				'address' => $txt( $in['pickup']['address'] ?? '', 255 ),
				'map'     => $txt( $in['pickup']['map'] ?? '', 500 ),
			);
		}
		if ( isset( $in['couriers'] ) && is_array( $in['couriers'] ) ) {
			$cfg['couriers'] = array();
			foreach ( $in['couriers'] as $c ) {
				if ( ! empty( $c['name'] ) ) {
					$cfg['couriers'][] = array( 'id' => $id( ! empty( $c['id'] ) ? $c['id'] : $c['name'] ), 'name' => $txt( $c['name'], 40 ), 'pin' => $txt( $c['pin'] ?? '' ) );
				}
			}
		}
		unset( $cfg['kinds'] );
		update_option( self::CONFIG_OPTION, $cfg, false );
		$cfg['kinds'] = self::kinds();
		return $cfg;
	}

	/* ---------- Hooks ---------- */

	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?mqp_order=new', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/track/?$', 'index.php?mqp_order=track', 'top' );
	}

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'footer_popup' ), 5 );
		self::add_rewrite();
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'mqp_order';
			return $vars;
		} );
		add_filter( 'redirect_canonical', function ( $redirect ) {
			return get_query_var( 'mqp_order' ) ? false : $redirect;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'mq_order', array( __CLASS__, 'button_shortcode' ) );
		add_shortcode( 'mq_order_button', array( __CLASS__, 'button_shortcode' ) );
		add_action( 'mqp_job_stage_changed', array( __CLASS__, 'on_stage_changed' ), 10, 3 );
		add_action( 'mqp_daily', array( __CLASS__, 'clean_drafts' ) );
	}

	public static function url( $path = '' ) {
		// Always the main website, even when built while serving the portal's own address.
		return MQP_Domain::to_main( home_url( '/' . self::SLUG . '/' . $path ) );
	}

	/**
	 * [mq_order text="Order online" track="no" popup="yes"] — a button on the website.
	 * By default it opens the order steps in a popup over the current page (the page stays
	 * underneath). popup="no" links to the full /order/ page instead. [mq_order_button] is the same.
	 */
	public static function button_shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'text' => '', 'track' => 'no', 'popup' => 'yes' ), $atts );
		$track = 'yes' === $atts['track'];
		$text  = '' !== $atts['text'] ? $atts['text'] : ( $track ? 'Track my order' : 'Order online' );
		$href  = $track ? self::url( 'track/' ) : self::url();
		if ( 'no' === $atts['popup'] ) {
			return '<a class="mqp-order-button" href="' . esc_url( $href ) . '">' . esc_html( $text ) . '</a>' . self::button_style();
		}
		self::need_popup();
		// The link still works without JavaScript; the script turns it into a popup.
		return '<a class="mqp-order-button" href="' . esc_url( $href ) . '" data-mqo-popup="' . esc_url( add_query_arg( 'embed', '1', $href ) ) . '">' . esc_html( $text ) . '</a>' . self::button_style();
	}

	private static function button_style() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style>.mqp-order-button{display:inline-block;background:#f88a35;color:#fff!important;padding:12px 24px;border-radius:5px;font:600 15px/1.2 Inter,system-ui,sans-serif;text-decoration:none!important;border:0;cursor:pointer}.mqp-order-button:hover{filter:brightness(1.06)}</style>';
	}

	/** Prints the order pop-up in this page's footer (once). */
	public static function need_popup() {
		if ( ! has_action( 'wp_footer', array( __CLASS__, 'popup_script' ) ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'popup_script' ) );
		}
		if ( did_action( 'wp_footer' ) && ! did_action( 'mqp_order_popup_printed' ) ) {
			self::popup_script(); // Content built after the footer (pop-ups, page builders).
		}
	}

	/** Every website page: "Upload Document" buttons (#mq-upload) open the order pop-up. */
	public static function footer_popup() {
		if ( is_admin() || is_feed() || is_embed() || get_query_var( 'mqp_order' ) || ! MQP_Upload::uses_order() ) {
			return;
		}
		self::need_popup();
	}

	/**
	 * Popup that hosts the order page in an iframe, printed once in the footer. Opened by
	 * [mq_order_button], by any link to #mq-upload or element with the class mq-upload-open, and
	 * by the [mq_upload] box (which hands the chosen files over to the order steps).
	 */
	public static function popup_script() {
		if ( did_action( 'mqp_order_popup_printed' ) ) {
			return;
		}
		do_action( 'mqp_order_popup_printed' );
		?>
<style>
.mqo-pop,.mqo-pop *{box-sizing:border-box!important}
.mqo-pop{position:fixed!important;inset:0!important;z-index:2147483000!important;margin:0!important;background:rgba(10,14,20,.55)!important;display:flex!important;align-items:center;justify-content:center;padding:16px!important;overflow:hidden!important;-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
.mqo-pop[hidden]{display:none!important}
.mqo-pop-box{position:relative!important;width:min(1120px,100%)!important;max-width:100%!important;height:min(920px,100%)!important;margin:0!important;padding:0!important;background:#f2f3f4!important;border-radius:14px!important;overflow:hidden!important;box-shadow:0 24px 60px rgba(0,0,0,.32)!important}
.mqo-pop iframe{display:block!important;width:100%!important;max-width:100%!important;height:100%!important;margin:0!important;padding:0!important;border:0!important;background:#f2f3f4!important}
.mqo-pop-x{position:absolute!important;top:10px!important;right:10px!important;z-index:2!important;display:grid!important;place-items:center!important;width:38px!important;height:38px!important;min-width:0!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:#fff!important;color:#374151!important;font:400 26px/1 system-ui,sans-serif!important;text-transform:none!important;letter-spacing:0!important;box-shadow:0 2px 10px rgba(0,0,0,.18)!important;cursor:pointer}
.mqo-pop-x:hover,.mqo-pop-x:focus-visible{background:#e5e7eb!important;color:#111827!important}
html.mqo-lock,html.mqo-lock body{overflow:hidden!important}
@media (max-width:700px){.mqo-pop{padding:0!important}.mqo-pop-box{width:100%!important;height:100%!important;border-radius:0!important}.mqo-pop-x{top:calc(8px + env(safe-area-inset-top))!important;right:8px!important}}
@supports (height:100dvh){.mqo-pop{height:100dvh!important}}
</style>
<script>
(function () {
  if (window.mqoOpen) return;
  var ORDER = <?php echo wp_json_encode( add_query_arg( 'embed', '1', self::url() ) ); ?>;
  var pop, frame, dirty = false, lastFocus = null, current = '', loaded = false, pending = null;
  function build() {
    pop = document.createElement('div');
    pop.className = 'mqo-pop';
    pop.hidden = true;
    pop.innerHTML = '<div class="mqo-pop-box" role="dialog" aria-modal="true" aria-label="Order online">' +
      '<button type="button" class="mqo-pop-x" aria-label="Close">&times;</button>' +
      '<iframe title="Order online"></iframe></div>';
    document.body.appendChild(pop);
    frame = pop.querySelector('iframe');
    frame.addEventListener('load', function () { loaded = true; if (pending) { pending(); pending = null; } });
    pop.querySelector('.mqo-pop-x').addEventListener('click', close);
    pop.addEventListener('click', function (e) { if (e.target === pop) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) close(); });
    window.addEventListener('message', function (e) {
      if (e.origin === location.origin && e.data && e.data.mqo === 'state') dirty = !!e.data.dirty;
    });
  }
  // files: chosen in the [mq_upload] box; handed to the order steps once the page in the pop-up is ready.
  function open(url, files) {
    if (!pop) build();
    url = url || ORDER;
    // Keep an unfinished order when the same popup is reopened.
    if (url !== current) { loaded = false; frame.src = url; current = url; dirty = false; }
    if (files && files.length) {
      var list = Array.prototype.slice.call(files);
      var send = function () { frame.contentWindow.postMessage({ mqo: 'files', files: list }, location.origin); };
      if (loaded) send(); else pending = send;
    }
    lastFocus = document.activeElement;
    pop.hidden = false;
    document.documentElement.classList.add('mqo-lock');
    pop.querySelector('.mqo-pop-x').focus();
  }
  function close() {
    if (dirty && !window.confirm('Your order is not finished yet. Close it? You can reopen it from this page to continue.')) return;
    pop.hidden = true;
    document.documentElement.classList.remove('mqo-lock');
    if (lastFocus) lastFocus.focus();
  }
  window.mqoOpen = open;
  document.addEventListener('click', function (e) {
    if (!e.target.closest || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var a = e.target.closest('a[data-mqo-popup]');
    if (a) { e.preventDefault(); open(a.getAttribute('data-mqo-popup')); return; }
    // "Upload Document" buttons: a link to #mq-upload, or the CSS class mq-upload-open (Elementor).
    var up = e.target.closest('a[href$="#mq-upload"], .mq-upload-open');
    if (up && !e.target.closest('.mqp-order-start')) { e.preventDefault(); open(ORDER); }
  }, true);
  // The [mq_upload] box: files chosen or dropped start the order with them.
  function start(box, files) { if (files && files.length) open(ORDER, files); }
  document.addEventListener('change', function (e) {
    var box = e.target.closest && e.target.closest('.mqp-order-start');
    if (box && e.target.type === 'file') { start(box, e.target.files); e.target.value = ''; }
  });
  ['dragenter', 'dragover'].forEach(function (ev) {
    document.addEventListener(ev, function (e) { var d = e.target.closest && e.target.closest('.mqp-order-start .mqp-drop'); if (d) { e.preventDefault(); d.classList.add('is-over'); } });
  });
  document.addEventListener('dragleave', function (e) { var d = e.target.closest && e.target.closest('.mqp-order-start .mqp-drop'); if (d) d.classList.remove('is-over'); });
  document.addEventListener('drop', function (e) {
    var d = e.target.closest && e.target.closest('.mqp-order-start .mqp-drop');
    if (!d) return;
    e.preventDefault(); d.classList.remove('is-over');
    start(d, e.dataTransfer.files);
  });
  // Opened from another page with a link to …#mq-upload.
  if (location.hash === '#mq-upload') { document.addEventListener('DOMContentLoaded', function () { open(ORDER); }); }
})();
</script>
		<?php
	}

	public static function render() {
		$mode = get_query_var( 'mqp_order' );
		if ( ! $mode ) {
			return;
		}
		if ( ! MQP_Settings::get( 'order_enabled' ) ) {
			wp_die( 'Online ordering is not available right now.', 'Online ordering', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		$s      = MQP_Settings::all();
		// ?embed=1: shown inside the website's [mq_order] popup — no page header/footer, links stay in the popup.
		$embed  = ! empty( $_GET['embed'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$link   = function ( $url ) use ( $embed ) {
			return esc_url_raw( $embed ? add_query_arg( 'embed', '1', $url ) : $url );
		};
		$config = array(
			'mode'      => 'track' === $mode ? 'track' : 'new',
			'embed'     => $embed,
			'restUrl'   => esc_url_raw( rest_url( 'mq/v1/public/' ) ),
			'orderUrl'  => $link( self::url() ),
			'trackUrl'  => $link( self::url( 'track/' ) ),
			'homeUrl'   => esc_url_raw( home_url( '/' ) ),
			'assetsUrl' => esc_url_raw( MQP_URL . 'assets/' ),
			'company'   => array( 'name' => $s['company_name'], 'contact' => $s['company_contact'], 'address' => $s['company_address'] ),
			'maxBytes'  => self::max_bytes(),
			'turnstile' => MQP_Settings::turnstile_on() ? $s['turnstile_site_key'] : '',
			'consent'   => MQP_Privacy::text(),
			'prices'    => self::public_config(),
		);
		include MQP_DIR . 'templates/order.php';
		exit;
	}

	/** The "order ready" message for staff to copy, with the customer's name and order number filled in. */
	public static function ready_message( $name, $number ) {
		$tpl = (string) self::config()['ready_message'];
		if ( '' === trim( $tpl ) ) {
			$tpl = self::default_config()['ready_message'];
		}
		return strtr( $tpl, array( '{name}' => (string) $name, '{order}' => (string) $number ) );
	}

	/** Choices for the order page (no secrets). */
	public static function public_config() {
		$cfg             = self::config();
		$cfg['map_embed'] = self::map_embed( $cfg['pickup'] );
		$cfg['map_link']  = self::map_link( $cfg['pickup'] );
		$cfg['features']  = self::features();
		return $cfg;
	}

	/** Order page features and their state: on | soon (shown as "Coming soon") | off (hidden). */
	public static function features() {
		$out = array();
		foreach ( array( 'campus', 'courier', 'supplies' ) as $f ) {
			$out[ $f ] = (string) MQP_Settings::get( 'feature_' . $f );
		}
		return $out;
	}

	/** Google Maps link for the pick-up place: the saved link, or a search for its words. */
	public static function map_link( array $pickup ) {
		$map = trim( (string) ( $pickup['map'] ?? '' ) );
		if ( preg_match( '#^https?://#i', $map ) ) {
			return $map;
		}
		$q = '' !== $map ? $map : trim( $pickup['name'] . ' ' . $pickup['address'] );
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $q );
	}

	/** Map shown on the page (no API key needed). A pasted link is shown by its place name or address. */
	public static function map_embed( array $pickup ) {
		$map = trim( (string) ( $pickup['map'] ?? '' ) );
		if ( preg_match( '#/embed\?#', $map ) ) {
			return $map; // Google Maps → Share → Embed a map → the src link.
		}
		$q = '' !== $map && ! preg_match( '#^https?://#i', $map ) ? $map : trim( $pickup['name'] . ' ' . $pickup['address'] );
		return 'https://www.google.com/maps?output=embed&q=' . rawurlencode( $q );
	}

	/** The brand online orders go to: MQ Printing Services, else the first brand. */
	public static function brand_id() {
		global $wpdb;
		$id = (int) $wpdb->get_var( 'SELECT id FROM ' . mqp_table( 'brands' ) . " WHERE code = 'MQ' AND active = 1" );
		return $id ? $id : (int) $wpdb->get_var( 'SELECT id FROM ' . mqp_table( 'brands' ) . ' WHERE active = 1 ORDER BY sort, id LIMIT 1' );
	}

	public static function max_bytes() {
		return min( (int) MQP_Settings::get( 'order_upload_max_mb' ) * MB_IN_BYTES, (int) wp_max_upload_size() );
	}

	/* ---------- Helpers ---------- */

	private static function find( array $list, $id ) {
		foreach ( $list as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}


	/** Best-effort PDF page count (0 when unknown, e.g. compressed object streams). */
	public static function pdf_pages( $path ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size <= 0 || $size > 300 * MB_IN_BYTES ) {
			return 0; // Unknown: the customer is asked to enter the number of pages.
		}
		$data = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $data ) {
			return 0;
		}
		$count = self::pdf_count( $data );
		if ( $count ) {
			return $count;
		}
		// Newer PDFs keep the page list inside compressed "object streams": unpack those and look again.
		if ( function_exists( 'gzuncompress' ) && preg_match_all( '/<<[^>]*\/Type\s*\/ObjStm[^>]*>>\s*stream\r?\n/s', $data, $m, PREG_OFFSET_CAPTURE ) ) {
			$found = 0;
			foreach ( $m[0] as $hit ) {
				$start = $hit[1] + strlen( $hit[0] );
				$end   = strpos( $data, 'endstream', $start );
				if ( false === $end || $end - $start > 50 * MB_IN_BYTES ) {
					continue;
				}
				$raw = @gzuncompress( substr( $data, $start, $end - $start ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $raw ) {
					$raw = @gzinflate( substr( $data, $start + 2, $end - $start - 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				if ( $raw ) {
					$found = max( $found, self::pdf_count( $raw ) );
				}
			}
			return $found;
		}
		return 0;
	}

	/** Pages in a piece of PDF text: the largest page-tree /Count, else the number of /Type /Page objects. */
	private static function pdf_count( $data ) {
		if ( preg_match_all( '/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $data, $m ) || preg_match_all( '/\/Count\s+(\d+)[^>]*?\/Type\s*\/Pages\b/s', $data, $m ) ) {
			return (int) max( $m[1] );
		}
		return (int) preg_match_all( '/\/Type\s*\/Page\b(?!s)/', $data );
	}

	/* ---------- Public REST ---------- */

	public static function routes() {
		$ns = MQP_REST::NS;
		register_rest_route( $ns, '/public/order-files', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_file' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/public/orders', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/public/orders/track', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'track' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/public/orders/payment', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'resubmit_payment' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Simple per-IP limiter. */
	private static function limited( $bucket, $max ) {
		$key   = 'mqp_rl_' . $bucket . '_' . md5( mqp_client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/** Uploads one file before the order is placed; returns a token that proves ownership at submit. */
	public static function upload_file( WP_REST_Request $req ) {
		if ( ! MQP_Settings::get( 'order_enabled' ) ) {
			return MQP_REST::error( 'Online ordering is closed.', 503 );
		}
		if ( self::limited( 'file', 80 ) ) {
			return MQP_REST::error( 'Too many uploads. Please try again later.', 429 );
		}
		$purpose = 'payment' === $req->get_param( 'purpose' ) ? 'payment' : 'order';
		$file    = $req->get_file_params()['file'] ?? null;
		if ( ! $file ) {
			return MQP_REST::error( 'Choose a file.' );
		}
		$allowed = 'payment' === $purpose ? array( 'png', 'jpg', 'jpeg', 'webp', 'pdf' ) : array_values( array_unique( array_merge( ...array_column( self::kinds(), 'ext' ) ) ) );
		$room    = MQP_Guard::allow_bytes( (int) ( $file['size'] ?? 0 ) );
		if ( is_wp_error( $room ) ) {
			return MQP_REST::error( $room->get_error_message(), (int) $room->get_error_data()['status'], 'mqp_storage' );
		}
		$row     = MQP_Files::store( $file, $allowed, self::max_bytes() );
		if ( is_wp_error( $row ) ) {
			return MQP_REST::error( $row->get_error_message() );
		}
		MQP_Guard::add_bytes( (int) $row['size'] );
		$ext          = strtolower( pathinfo( $row['original_name'], PATHINFO_EXTENSION ) );
		$row['pages'] = 'pdf' === $ext ? self::pdf_pages( MQP_Files::base_dir() . '/' . $row['stored_path'] ) : 1;
		$row['kind']  = $purpose . '_draft';
		$row['token'] = wp_generate_password( 32, false );
		$id           = MQP_Files::insert( $row );
		return array( 'id' => $id, 'token' => $row['token'], 'name' => $row['original_name'], 'size' => $row['size'], 'pages' => $row['pages'] );
	}

	/** Loads a not-yet-used draft file the browser proved it uploaded. */
	private static function claim_draft( $ref, $kind ) {
		global $wpdb;
		if ( empty( $ref['id'] ) || empty( $ref['token'] ) ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'files' ) . ' WHERE id = %d AND token = %s AND kind = %s AND job_id IS NULL', (int) $ref['id'], (string) $ref['token'], $kind ),
			ARRAY_A
		);
	}

	private static function new_reference() {
		global $wpdb;
		do {
			$ref = 'MQ-' . current_time( 'ymd' ) . '-' . strtoupper( substr( str_replace( array( '0', 'O', '1', 'I' ), '', wp_generate_password( 12, false ) ), 0, 4 ) );
		} while ( strlen( $ref ) < 14 || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'orders' ) . ' WHERE reference = %s', $ref ) ) );
		return $ref;
	}

	public static function submit( WP_REST_Request $req ) {
		global $wpdb;
		$s = MQP_Settings::all();
		if ( ! $s['order_enabled'] ) {
			return MQP_REST::error( 'Online ordering is closed.', 503 );
		}
		$p = (array) $req->get_json_params();
		if ( ! empty( $p['website'] ) ) {
			return MQP_REST::error( 'Order rejected.' );
		}
		if ( self::limited( 'order', (int) MQP_Monitor::settings()['limit_orders'] ) ) {
			return MQP_REST::error( 'Too many orders from your connection. Please try again later or message us.', 429 );
		}
		if ( MQP_Settings::turnstile_on() ) {
			$resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
				'body'    => array( 'secret' => $s['turnstile_secret'], 'response' => (string) ( $p['turnstile'] ?? '' ), 'remoteip' => mqp_client_ip() ),
				'timeout' => 10,
			) );
			if ( is_wp_error( $resp ) || empty( json_decode( wp_remote_retrieve_body( $resp ), true )['success'] ) ) {
				return MQP_REST::error( 'Please complete the verification and try again.' );
			}
		}

		// Contact
		$c     = (array) ( $p['contact'] ?? array() );
		$name  = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $c['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $c['email'] ?? '' ) );
		if ( '' === $name || '' === $phone || ! is_email( $email ) ) {
			return MQP_REST::error( 'Enter your name, mobile number and a valid email address.' );
		}
		$consent = MQP_Privacy::check( $c['consent'] ?? '' );
		if ( $consent ) {
			return $consent;
		}

		// Files: each with what it is for (document, shirt…) and the customer's note.
		$cfg   = self::config();
		$files = array();
		$pages = 0;
		foreach ( array_slice( (array) ( $p['files'] ?? array() ), 0, 30 ) as $ref ) {
			$ref = (array) $ref;
			$f   = self::claim_draft( $ref, 'order_draft' );
			if ( ! $f ) {
				return MQP_REST::error( 'One of your files could not be found. Please upload it again.' );
			}
			$ext  = strtolower( pathinfo( $f['original_name'], PATHINFO_EXTENSION ) );
			$kind = self::find( $cfg['kinds'], (string) ( $ref['kind'] ?? '' ) );
			if ( ! $kind || ! in_array( $ext, $kind['ext'], true ) ) {
				// The kind must fit the file (e.g. Print and Bind takes PDF only): else the first kind that does.
				foreach ( $cfg['kinds'] as $k ) {
					if ( in_array( $ext, $k['ext'], true ) ) {
						$kind = $k;
						break;
					}
				}
			}
			// "Others: please specify" — the customer's words become the kind's name.
			if ( ! empty( $kind['specify'] ) ) {
				$what = substr( sanitize_text_field( (string) ( $ref['specify'] ?? '' ) ), 0, 80 );
				if ( '' === $what ) {
					return MQP_REST::error( 'Please specify what ' . $f['original_name'] . ' is for.' );
				}
				$kind['name'] = 'Others: ' . $what;
			}
			// Use the larger of our own count and what the customer's browser counted (staff double-check before printing).
			$fpages  = 'pdf' === $ext ? max( (int) $f['pages'], max( 1, min( 5000, (int) ( $ref['pages'] ?? 1 ) ) ) ) : 0;
			$pages  += $fpages;
			$files[] = array(
				'row'   => $f,
				'kind'  => $kind,
				'pages' => $fpages,
				'note'  => substr( sanitize_textarea_field( (string) ( $ref['note'] ?? '' ) ), 0, 1000 ),
			);
		}
		if ( ! $files ) {
			return MQP_REST::error( 'Upload at least one file.' );
		}
		$features = self::features();
		$supplies = array();
		foreach ( 'on' === $features['supplies'] ? (array) ( $p['supplies'] ?? array() ) : array() as $sid => $qty ) {
			$qty = max( 0, min( 100, (int) $qty ) );
			$sup = self::find( $cfg['supplies'], (string) $sid );
			if ( $sup && $qty ) {
				$supplies[] = array( 'name' => $sup['name'], 'unit' => $sup['unit'], 'qty' => $qty );
			}
		}

		// Getting the order: pick up at the main office, a courier the customer books, or campus delivery.
		$delivery = (array) ( $p['delivery'] ?? array() );
		$dtype    = in_array( $delivery['type'] ?? '', array( 'courier', 'campus' ), true ) ? $delivery['type'] : 'pickup';
		if ( 'pickup' !== $dtype && 'on' !== $features[ $dtype ] ) {
			return MQP_REST::error( 'campus' === $dtype ? 'Campus delivery is coming soon. Please choose pick up for now.' : 'Courier delivery is not available right now. Please choose pick up.' );
		}
		if ( 'campus' === $dtype ) {
			if ( ! in_array( (string) ( $delivery['location'] ?? '' ), $cfg['campuses'], true ) ) {
				return MQP_REST::error( 'Choose your campus.' );
			}
			$location = (string) $delivery['location'];
		} elseif ( 'courier' === $dtype ) {
			$courier = self::find( $cfg['couriers'], (string) ( $delivery['courier'] ?? '' ) );
			if ( ! $courier ) {
				return MQP_REST::error( 'Choose Maxim, Lalamove or Grab Express.' );
			}
			$location = $courier['name'] . ( '' !== $courier['pin'] ? ' — pin: ' . $courier['pin'] : '' );
		} else {
			$location = $cfg['pickup']['name'];
		}

		$brand_id  = self::brand_id();
		$reference = self::new_reference();
		$notes     = substr( sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ), 0, 2000 );
		$dlabel    = array( 'pickup' => 'Pick up at ' . $location, 'campus' => 'Campus delivery: ' . $location, 'courier' => "Customer books the rider when the order is ready:\n" . $location )[ $dtype ];

		// Invoice lines at ₱0: staff set the prices (the customer is told the price on Facebook).
		$items   = array();
		$summary = array();
		foreach ( $files as $f ) {
			$specs   = array_filter( array( $f['pages'] ? sprintf( '%d page%s', $f['pages'], 1 === $f['pages'] ? '' : 's' ) : '', $f['note'] ) );
			$items[] = array( 'description' => $f['kind']['name'] . ' — ' . $f['row']['original_name'], 'specs' => implode( "\n", $specs ), 'qty' => 1, 'unit' => 'file', 'unit_price' => 0, 'is_estimate' => 1 );
		}
		foreach ( $supplies as $sup ) {
			$items[] = array( 'description' => $sup['name'], 'specs' => $sup['unit'], 'qty' => $sup['qty'], 'unit' => 'pc', 'unit_price' => 0, 'is_estimate' => 1 );
		}
		// Kind names are long now ("Shirts and Other Garments Printing"): count the files instead.
		$summary[] = count( $files ) . ' file' . ( 1 === count( $files ) ? '' : 's' );
		if ( $pages ) {
			$summary[] = $pages . ' page' . ( 1 === $pages ? '' : 's' );
		}
		if ( $supplies ) {
			$summary[] = count( $supplies ) . ' office suppl' . ( 1 === count( $supplies ) ? 'y' : 'ies' );
		}

		// Invoice (= the sale) + its job order, through the same code the Invoice Manager uses.
		$doc = MQP_REST_Documents::create(
			array(
				'uuid'     => wp_generate_uuid4(),
				'type'     => 'invoice',
				'brand_id' => $brand_id,
				'customer' => array( 'name' => $name, 'contact' => $phone, 'email' => $email ),
				'title'    => 'Online order ' . $reference,
				'items'    => $items,
				'notes'    => trim( "Online order $reference — price to be set\n" . $dlabel . ( $notes ? "\nCustomer note: $notes" : '' ) ),
			),
			true // Made by the order page itself.
		);
		if ( is_wp_error( $doc ) ) {
			return MQP_REST::error( 'We could not save your order. Please try again.', 500 );
		}
		$job_id = (int) $doc['job_id'];

		// Specs: each file with its note, then how the customer gets the order.
		$specs = array();
		foreach ( $files as $i => $f ) {
			$specs[] = sprintf( '%d. %s — %s%s', $i + 1, $f['kind']['name'], $f['row']['original_name'], $f['pages'] ? ' (' . $f['pages'] . ' pages)' : '' ) . ( '' !== $f['note'] ? "\n   " . str_replace( "\n", "\n   ", $f['note'] ) : '' );
		}
		foreach ( $supplies as $sup ) {
			$specs[] = sprintf( '+ %s × %d (%s)', $sup['name'], $sup['qty'], $sup['unit'] );
		}
		$wpdb->update(
			mqp_table( 'jobs' ),
			array( 'specs' => implode( "\n", $specs ) . "\n\n" . $dlabel . ( $notes ? "\nCustomer note: $notes" : '' ), 'specs_complete' => 1 ),
			array( 'id' => $job_id )
		);
		foreach ( $files as $f ) {
			$wpdb->update( mqp_table( 'files' ), array( 'job_id' => $job_id, 'kind' => 'customer', 'token' => '', 'pages' => $f['pages'] ), array( 'id' => $f['row']['id'] ) );
		}
		if ( $s['online_sales_person'] ) {
			MQP_REST_Jobs::set_sales_person( $job_id, (int) $s['online_sales_person'] );
		}

		$wpdb->insert(
			mqp_table( 'orders' ),
			array(
				'reference'         => $reference,
				'document_id'       => (int) $doc['id'],
				'job_id'            => $job_id,
				'brand_id'          => $brand_id,
				'branch_id'         => null,
				'service'           => 'printing',
				'details'           => wp_json_encode(
					array(
						'files'    => array_map( function ( $f ) {
							return array( 'name' => $f['row']['original_name'], 'kind' => $f['kind']['name'], 'pages' => $f['pages'], 'note' => $f['note'] );
						}, $files ),
						'supplies' => $supplies,
						'pages'    => $pages,
						'summary'  => implode( ' · ', $summary ),
					)
				),
				'delivery_type'     => $dtype,
				'delivery_location' => substr( $location, 0, 255 ),
				'customer_name'     => $name,
				'customer_phone'    => $phone,
				'customer_email'    => $email,
				'notes'             => $notes,
				'total'             => 0,
				'payment_status'    => 'pending', // Not paid yet: the customer pays once staff tell them the price.
				'ip'                => mqp_client_ip(),
				'created_at'        => mqp_now(),
			)
		);
		$order_id = (int) $wpdb->insert_id;
		MQP_REST_Jobs::event( $job_id, 'online_order', '', '', "Online order $reference · set the price on the invoice" );

		$order = self::load( $order_id );
		self::email_received( $order );
		self::email_staff_new( $order );
		MQP_Notify::order_received( $order );

		return array(
			'reference' => $reference,
			'email'     => $email,
			'track_url' => self::url( 'track/' ) . '?ref=' . rawurlencode( $reference ),
		);
	}

	/* ---------- Tracking ---------- */

	public static function load( $id, $by = 'id' ) {
		global $wpdb;
		$col = 'reference' === $by ? 'reference' : 'id';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mqp_table( 'orders' ) . " WHERE $col = %s", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row            = mqp_cast_row( $row, array( 'id', 'document_id', 'job_id', 'brand_id', 'branch_id', 'payment_proof_id', 'verified_by' ), array( 'total' ) );
		$row['details'] = json_decode( (string) $row['details'], true );
		$job            = $row['job_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT number, stage, stage_changed_at, delivered_at, notified_at FROM ' . mqp_table( 'jobs' ) . ' WHERE id = %d', $row['job_id'] ), ARRAY_A ) : null;
		$row['job']     = $job;
		$doc            = $row['document_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT number, subtotal, discount, total, amount_paid, status FROM ' . mqp_table( 'documents' ) . ' WHERE id = %d', $row['document_id'] ), ARRAY_A ) : null;
		$row['invoice'] = $doc ? mqp_cast_row( $doc, array(), array( 'subtotal', 'discount', 'total', 'amount_paid' ) ) : null;
		return $row;
	}

	/** Customer-facing status, steps and wording. */
	public static function customer_status( array $o ) {
		$stage = $o['job']['stage'] ?? 'sales';
		$ready = array( 'pickup' => 'Ready for pick-up', 'courier' => 'Ready — book your rider' )[ $o['delivery_type'] ] ?? 'Out for delivery';
		// Orders from before 1.24 waited for a verified payment; newer ones are paid later.
		$paid_first = 'pending' !== $o['payment_status'];
		$steps      = array( array( 'key' => 'received', 'label' => 'Order received' ) );
		$order      = array( 'received' );
		if ( $paid_first ) {
			$steps[] = array( 'key' => 'paid', 'label' => 'Payment verified' );
			$order[] = 'paid';
		} else {
			// Pay later (1.24+): once staff set the price, the customer sees it here and pays.
			$steps[] = array( 'key' => 'payment', 'label' => 'Payment' );
			$order[] = 'payment';
		}
		foreach ( array( 'printing' => 'Printing', 'qc' => 'Quality check', 'ready' => $ready, 'done' => 'Completed' ) as $k => $label ) {
			$steps[] = array( 'key' => $k, 'label' => $label );
			$order[] = $k;
		}
		$at = 'received';
		if ( 'verified' === $o['payment_status'] ) {
			$at = 'paid';
		}
		$inv = $o['invoice'] ?? null;
		if ( ! $paid_first && $inv && (float) $inv['total'] > 0 ) {
			$at = 'paid' === $inv['status'] ? 'paid_later' : 'payment';
		}
		$map = array( 'creatives' => 'printing', 'production' => 'printing', 'qc' => 'qc', 'ready' => 'ready', 'delivered' => 'done' );
		if ( isset( $map[ $stage ] ) && ( ! $paid_first || 'verified' === $o['payment_status'] ) ) {
			$at = $map[ $stage ];
		}
		// Paid in full but not yet in production: the Payment step is done, Printing is next.
		$idx = 'paid_later' === $at ? array_search( 'payment', $order, true ) + 1 : array_search( $at, $order, true );
		foreach ( $steps as $i => &$s ) {
			$s['done']    = $i < $idx || ( $i === $idx && 'done' === $at );
			$s['current'] = $i === $idx && 'done' !== $at;
		}
		$label = array(
			'received' => 'rejected' === $o['payment_status'] ? 'We could not verify your payment' : ( $paid_first ? 'Order received — verifying your payment' : 'Order received — we are checking your files' ),
			'paid'     => 'Payment verified — your order is in the queue',
			'payment'  => $inv && (float) $inv['amount_paid'] > 0 ? 'Partly paid — please pay the balance' : 'Your price is ready — please pay to start',
			'paid_later' => 'Paid ✓ — your order is in the queue',
			'printing' => 'Your order is being printed',
			'qc'       => 'Quality check in progress',
			'ready'    => $ready,
			'done'     => 'Completed — thank you!',
		)[ $at ];
		if ( 'cancelled' === $stage ) {
			$label = 'This order was cancelled';
		}
		return array( 'key' => 'cancelled' === $stage ? 'cancelled' : $at, 'label' => $label, 'steps' => $steps );
	}

	public static function track( WP_REST_Request $req ) {
		if ( self::limited( 'track', 60 ) ) {
			return MQP_REST::error( 'Too many lookups. Please try again later.', 429 );
		}
		$ref   = strtoupper( sanitize_text_field( (string) $req->get_param( 'reference' ) ) );
		$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
		$o     = $ref ? self::load( $ref, 'reference' ) : null;
		if ( ! $o || '' === $email || strtolower( $o['customer_email'] ) !== $email ) {
			return MQP_REST::error( 'We could not find an order with that order number and email.', 404 );
		}
		$cfg    = self::config();
		$method = self::find( $cfg['payment'], $o['payment_method'] );
		$inv    = $o['invoice'];
		return array(
			'reference'      => $o['reference'],
			'created_at'     => $o['created_at'],
			'customer_name'  => $o['customer_name'],
			'service'        => $o['service'],
			'summary'        => $o['details']['summary'] ?? '',
			'files'          => $o['details']['files'] ?? array(),
			'supplies'       => $o['details']['supplies'] ?? array(),
			'items'          => 'pending' === $o['payment_status'] ? self::invoice_lines( $o ) : ( $o['details']['items'] ?? array() ),
			'subtotal'       => $inv ? (float) $inv['subtotal'] : 0,
			'discount'       => $inv ? (float) $inv['discount'] : 0,
			'amount_paid'    => $inv ? (float) $inv['amount_paid'] : 0,
			// Newer orders: the price staff set on the invoice (0 = not priced yet) and what is left to pay.
			'total'          => 'pending' === $o['payment_status'] ? ( $inv ? (float) $inv['total'] : 0 ) : $o['total'],
			'balance'        => $inv ? max( 0, round( (float) $inv['total'] - (float) $inv['amount_paid'], 2 ) ) : 0,
			'invoice_status' => $inv ? $inv['status'] : '',
			'delivery_type'  => $o['delivery_type'],
			'delivery'       => $o['delivery_location'],
			'payment_method' => $method ? $method['name'] : $o['payment_method'],
			'payment_status' => $o['payment_status'],
			'payment_note'   => $o['payment_note'],
			'status'         => self::customer_status( $o ),
			'can_repay'      => 'rejected' === $o['payment_status'],
		);
	}

	/** The priced invoice lines (the computation shown to the customer); none until staff set a price. */
	public static function invoice_lines( array $o ) {
		global $wpdb;
		if ( ! $o['document_id'] || ! $o['invoice'] || (float) $o['invoice']['total'] <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT description, specs, qty, unit, unit_price, amount FROM ' . mqp_table( 'document_items' ) . ' WHERE document_id = %d ORDER BY sort, id', $o['document_id'] ), ARRAY_A );
		return array_map( function ( $r ) {
			return array(
				'description' => $r['description'],
				'specs'       => (string) $r['specs'],
				'qty'         => (float) $r['qty'],
				'unit'        => $r['unit'],
				'unit_price'  => (float) $r['unit_price'],
				'amount'      => (float) $r['amount'],
			);
		}, (array) $rows );
	}

	/** After a rejected payment, the customer sends a new reference / proof from the tracking page. */
	public static function resubmit_payment( WP_REST_Request $req ) {
		global $wpdb;
		if ( self::limited( 'repay', 20 ) ) {
			return MQP_REST::error( 'Too many attempts. Please try again later.', 429 );
		}
		$ref   = strtoupper( sanitize_text_field( (string) $req->get_param( 'reference' ) ) );
		$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
		$o     = $ref ? self::load( $ref, 'reference' ) : null;
		if ( ! $o || '' === $email || strtolower( $o['customer_email'] ) !== $email ) {
			return MQP_REST::error( 'Order not found.', 404 );
		}
		if ( 'rejected' !== $o['payment_status'] ) {
			return MQP_REST::error( 'This order is not waiting for a new payment.' );
		}
		$method = self::find( self::config()['payment'], (string) $req->get_param( 'method' ) );
		$payref = sanitize_text_field( (string) $req->get_param( 'payment_reference' ) );
		if ( ! $method || strlen( $payref ) < 4 ) {
			return MQP_REST::error( 'Choose how you paid and enter the reference number.' );
		}
		$proof = $req->get_param( 'proof' ) ? self::claim_draft( (array) $req->get_param( 'proof' ), 'payment_draft' ) : null;
		if ( $proof ) {
			$wpdb->update( mqp_table( 'files' ), array( 'job_id' => $o['job_id'], 'kind' => 'payment', 'token' => '' ), array( 'id' => $proof['id'] ) );
		}
		$wpdb->update(
			mqp_table( 'orders' ),
			array( 'payment_method' => $method['id'], 'payment_reference' => $payref, 'payment_proof_id' => $proof ? (int) $proof['id'] : $o['payment_proof_id'], 'payment_status' => 'submitted', 'payment_note' => '' ),
			array( 'id' => $o['id'] )
		);
		MQP_REST_Jobs::event( $o['job_id'], 'note', '', '', 'Customer sent a new payment: ' . $method['name'] . " ref $payref" );
		self::email_staff_new( self::load( $o['id'] ), true );
		MQP_Notify::order_received( self::load( $o['id'] ), true );
		return array( 'ok' => true );
	}

	/* ---------- Staff actions (called from MQP_REST_Orders) ---------- */

	public static function verify( $id ) {
		global $wpdb;
		$o = self::load( $id );
		if ( ! $o ) {
			return MQP_REST::error( 'Order not found.', 404 );
		}
		// Claim the order first so two staff clicking at once cannot record the payment twice.
		$claimed = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . mqp_table( 'orders' ) . " SET payment_status = 'verified', payment_note = '', verified_at = %s, verified_by = %d WHERE id = %d AND payment_status <> 'verified'",
			mqp_now(), get_current_user_id(), $id
		) );
		if ( ! $claimed ) {
			return MQP_REST::error( 'This payment is already verified.' );
		}
		$due = round( $o['invoice']['total'] - $o['invoice']['amount_paid'], 2 );
		if ( $due > 0 ) { // Nothing to record if staff already took the payment in the Invoice Manager.
			$method = self::find( self::config()['payment'], $o['payment_method'] );
			$res    = MQP_REST_Documents::add_payment(
				$o['document_id'],
				array( 'uuid' => wp_generate_uuid4(), 'amount' => $due, 'method' => $o['payment_method'], 'reference' => $o['payment_reference'], 'notes' => 'Online order ' . $o['reference'] . ( $method ? ' via ' . $method['name'] : '' ) )
			);
			if ( is_wp_error( $res ) ) {
				$wpdb->update( mqp_table( 'orders' ), array( 'payment_status' => $o['payment_status'], 'verified_at' => null, 'verified_by' => null ), array( 'id' => $id ) );
				return $res;
			}
		}
		$o = self::load( $id );
		self::email_status( $o, 'Payment verified', 'We received your payment for order ' . $o['reference'] . '. Your order is now in our printing queue — we will email you again when it is ready.' );
		return $o;
	}

	public static function reject( $id, $reason ) {
		global $wpdb;
		$o = self::load( $id );
		if ( ! $o ) {
			return MQP_REST::error( 'Order not found.', 404 );
		}
		if ( 'verified' === $o['payment_status'] ) {
			return MQP_REST::error( 'This payment is already verified.' );
		}
		$wpdb->update( mqp_table( 'orders' ), array( 'payment_status' => 'rejected', 'payment_note' => substr( $reason, 0, 255 ) ), array( 'id' => $id ) );
		MQP_REST_Jobs::event( $o['job_id'], 'note', '', '', 'Payment not verified: ' . $reason );
		$o = self::load( $id );
		self::email_status( $o, 'We could not verify your payment', "We could not verify the payment for order {$o['reference']}.\nReason: $reason\n\nPlease open your order and send the correct reference number or a screenshot of your payment.", 'Update my payment' );
		return $o;
	}

	/**
	 * Admins remove an online order (spam, test, double order) with its invoice, job order and
	 * files. Refused once a payment is recorded: void the invoice instead, so money is never
	 * hidden. Every delete is kept in the activity log and the admins are emailed.
	 */
	public static function delete( $id, $reason ) {
		global $wpdb;
		$o = self::load( $id );
		if ( ! $o ) {
			return MQP_REST::error( 'Order not found.', 404 );
		}
		if ( $o['invoice'] && (float) $o['invoice']['amount_paid'] > 0 ) {
			return MQP_REST::error( 'A payment is recorded on this order\'s invoice. Void the invoice instead (Sales → Invoice Manager), so the payment stays on record.', 409 );
		}
		if ( 'verified' === $o['payment_status'] ) {
			return MQP_REST::error( 'This order\'s payment was verified. Void the invoice instead.', 409 );
		}
		$files = $o['job_id'] ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, stored_path FROM ' . mqp_table( 'files' ) . ' WHERE job_id = %d', $o['job_id'] ) ) : array();
		if ( $o['payment_proof_id'] ) {
			$files = array_merge( $files, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, stored_path FROM ' . mqp_table( 'files' ) . ' WHERE id = %d', $o['payment_proof_id'] ) ) );
		}
		foreach ( $files as $f ) {
			wp_delete_file( MQP_Files::base_dir() . '/' . $f->stored_path );
			$wpdb->delete( mqp_table( 'files' ), array( 'id' => $f->id ) );
		}
		if ( $o['job_id'] ) {
			foreach ( array( 'job_events', 'commissions' ) as $t ) {
				$wpdb->delete( mqp_table( $t ), array( 'job_id' => $o['job_id'] ) );
			}
			$wpdb->delete( mqp_table( 'jobs' ), array( 'id' => $o['job_id'] ) );
		}
		if ( $o['document_id'] ) {
			$wpdb->delete( mqp_table( 'document_items' ), array( 'document_id' => $o['document_id'] ) );
			$wpdb->delete( mqp_table( 'documents' ), array( 'id' => $o['document_id'] ) );
		}
		$wpdb->delete( mqp_table( 'orders' ), array( 'id' => $o['id'] ) );

		$u    = wp_get_current_user();
		$what = sprintf( 'Online order %s (%s, invoice %s, job %s) deleted: %s', $o['reference'], $o['customer_name'], $o['invoice']['number'] ?? '—', $o['job']['number'] ?? '—', $reason );
		mqp_log( 'order_deleted', array( 'username' => $u->user_login, 'reason' => $what ) );
		MQP_Mail::send(
			MQP_Monitor::admin_to(),
			'Online order ' . $o['reference'] . ' was deleted',
			'An online order was deleted',
			array( MQP_Mail::table( array(
				array( 'Order number', $o['reference'], true ),
				array( 'Customer', $o['customer_name'] . ' · ' . $o['customer_phone'] ),
				array( 'Order', (string) ( $o['details']['summary'] ?? '' ) ),
				array( 'Deleted by', $u->display_name ),
				array( 'Reason', $reason ),
			) ) )
		);
		return array( 'ok' => true );
	}

	/** Emails the customer when their job reaches Ready or Delivered. */
	public static function on_stage_changed( $job_id, $from, $to ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'orders' ) . ' WHERE job_id = %d', $job_id ) );
		if ( ! $id ) {
			return;
		}
		$o = self::load( $id );
		if ( 'ready' === $to ) {
			if ( 'pickup' === $o['delivery_type'] ) {
				$msg = "Good news! Order {$o['reference']} is ready. You can pick it up at {$o['delivery_location']}. Please bring your order number.";
			} elseif ( 'courier' === $o['delivery_type'] && false !== strpos( $o['delivery_location'], 'pin:' ) ) {
				$msg = "Good news! Order {$o['reference']} is ready. You can now book your rider ({$o['delivery_location']}) and give them your order number.";
			} else {
				$msg = "Good news! Order {$o['reference']} is ready and will be delivered to " . $o['delivery_location'] . '.';
			}
			self::email_status( $o, 'Your order is ready', $msg );
		} elseif ( 'delivered' === $to ) {
			self::email_status( $o, 'Order completed', "Order {$o['reference']} is complete. Thank you for choosing MQ Printing Services!" );
		}
	}

	/* ---------- Emails ---------- */

	public static function track_link( array $o ) {
		return self::url( 'track/' ) . '?ref=' . rawurlencode( $o['reference'] ) . '&email=' . rawurlencode( $o['customer_email'] );
	}

	/** Rows for the files and supplies of an order (newer orders), else its priced lines. */
	private static function order_rows( array $o ) {
		$rows = array();
		foreach ( (array) ( $o['details']['files'] ?? array() ) as $f ) {
			$rows[] = array( $f['kind'] . ': ' . $f['name'] . ( '' !== (string) $f['note'] ? ' — ' . $f['note'] : '' ), $f['pages'] ? $f['pages'] . ' pages' : '' );
		}
		foreach ( (array) ( $o['details']['supplies'] ?? array() ) as $sup ) {
			$rows[] = array( $sup['name'] . ' (' . $sup['unit'] . ')', '× ' . $sup['qty'] );
		}
		foreach ( (array) ( $o['details']['items'] ?? array() ) as $it ) {
			$rows[] = array( $it['description'] . ( ! empty( $it['specs'] ) ? ' (' . $it['specs'] . ')' : '' ), '₱' . number_format( $it['amount'], 2 ) );
		}
		return $rows;
	}

	/** The payment QR codes as an email block, so customers have them when they pay later. */
	public static function qr_block() {
		$cells = '';
		foreach ( self::config()['payment'] as $p ) {
			if ( empty( $p['qr'] ) ) {
				continue;
			}
			$cells .= '<td align="center" style="padding:6px;vertical-align:top"><img src="' . esc_url( $p['qr'] ) . '" width="200" alt="' . esc_attr( $p['name'] . ' QR code' ) . '" style="display:block;width:200px;max-width:100%;height:auto;border:1px solid #e1e4ea;border-radius:8px"><div style="font-size:13px;font-weight:600;margin-top:6px">' . esc_html( $p['name'] ) . '</div>'
				. ( ! empty( $p['account_name'] ) ? '<div style="font-size:12px;color:#545454">' . esc_html( $p['account_name'] . ( ! empty( $p['account_number'] ) ? ' · ' . $p['account_number'] : '' ) ) . '</div>' : '' ) . '</td>';
		}
		return $cells ? array( 'html' => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 16px"><tr>' . $cells . '</tr></table>' ) : '';
	}

	public static function email_received( array $o ) {
		$cfg     = self::config();
		$pending = 'pending' === $o['payment_status'];
		$rows    = array_merge( array( array( 'Order number', $o['reference'], true ) ), self::order_rows( $o ) );
		if ( ! $pending ) {
			$rows[] = array( 'Total', '₱' . number_format( $o['total'], 2 ), true );
			$rows[] = array( 'Payment', strtoupper( $o['payment_method'] ) . ' · ref ' . $o['payment_reference'] );
		}
		$rows[] = array( 'pickup' === $o['delivery_type'] ? 'Pick up at' : 'Delivery', $o['delivery_location'] );
		if ( $o['notes'] ) {
			$rows[] = array( 'Your note', $o['notes'] );
		}
		$blocks = array(
			'We received your order. Please keep your order number — use it when you message us on our Facebook page about this order, and when you pick it up.',
			MQP_Mail::table( $rows ),
		);
		if ( $pending ) {
			$blocks[] = 'Our team will check your files and message you the price. No need to pay yet. When you are ready to pay, scan one of these QR codes and send us the screenshot with your order number.';
			$blocks[] = self::qr_block();
			if ( 'courier' === $o['delivery_type'] ) {
				$blocks[] = 'Delivery: book your own rider once we tell you the order is ready, and use this pin: ' . $o['delivery_location'] . '. ' . $cfg['courier_note'];
			}
		} else {
			$blocks[] = 'Our team will verify your payment shortly. We will email you again when your order is ready.';
		}
		MQP_Mail::send(
			$o['customer_email'],
			'Your MQ Printing Services order ' . $o['reference'],
			'Thank you, ' . $o['customer_name'] . '!',
			array_values( array_filter( $blocks ) ),
			$cfg['messenger_url'] ? array( 'Message us about this order', $cfg['messenger_url'] ) : array( 'Track my order', self::track_link( $o ) )
		);
	}

	public static function email_status( array $o, $heading, $text, $button = 'Track my order' ) {
		MQP_Mail::send( $o['customer_email'], $heading . ' — ' . $o['reference'], $heading, array( $text ), array( $button, self::track_link( $o ) ) );
	}

	public static function email_staff_new( array $o, $repay = false ) {
		$to      = MQP_Settings::get( 'order_notify_email' );
		$to      = $to ? $to : MQP_Monitor::admin_to();
		$pending = 'pending' === $o['payment_status'];
		$rows    = array(
			array( 'Order number', $o['reference'], true ),
			array( 'Customer', $o['customer_name'] . ' · ' . $o['customer_phone'] ),
			array( 'Order', (string) ( $o['details']['summary'] ?? '' ) ),
		);
		if ( ! $pending ) {
			$rows[] = array( 'Total', '₱' . number_format( $o['total'], 2 ), true );
			$rows[] = array( 'Payment', strtoupper( $o['payment_method'] ) . ' · ref ' . $o['payment_reference'] );
		}
		$rows[] = array( 'pickup' === $o['delivery_type'] ? 'Pick up at' : 'Delivery', $o['delivery_location'] );
		MQP_Mail::send(
			$to,
			( $repay ? 'New payment for ' : 'New online order ' ) . $o['reference'] . ( $pending ? '' : ' — ₱' . number_format( $o['total'], 2 ) ),
			$repay ? 'A customer sent a new payment' : 'New online order',
			array(
				MQP_Mail::table( $rows ),
				$pending ? 'Open the job order to check the files and notes, set the price on the invoice, then message the customer on Facebook.' : 'Verify the payment in the portal: Sales → Online orders.',
			),
			array( 'Open the portal', MQP_Portal::url() . '#/sales/orders/' . (int) $o['id'] )
		);
	}

	/**
	 * Staff → invoice → "Email to customer": the updated invoice with each line's price, the total,
	 * what is paid and what is left, and the payment QR codes (like emailing a quotation).
	 */
	public static function email_invoice( array $doc, $email ) {
		global $wpdb;
		$rows = array();
		foreach ( $doc['items'] as $i ) {
			$rows[] = array( $i['description'] . ' × ' . (float) $i['qty'] . ' ' . $i['unit'] . ' @ ₱' . number_format( (float) $i['unit_price'], 2 ), '₱' . number_format( (float) $i['amount'], 2 ) );
		}
		if ( (float) $doc['discount'] > 0 ) {
			$rows[] = array( 'Subtotal', '₱' . number_format( (float) $doc['subtotal'], 2 ) );
			$rows[] = array( 'Discount', '− ₱' . number_format( (float) $doc['discount'], 2 ) );
		}
		$rows[] = array( 'Total amount', '₱' . number_format( (float) $doc['total'], 2 ), true );
		if ( (float) $doc['amount_paid'] > 0 ) {
			$rows[] = array( 'Paid', '− ₱' . number_format( (float) $doc['amount_paid'], 2 ) );
		}
		$balance = max( 0, round( (float) $doc['total'] - (float) $doc['amount_paid'], 2 ) );
		$rows[]  = array( $balance > 0 ? 'Amount to pay' : 'Balance', $balance > 0 ? '₱' . number_format( $balance, 2 ) : 'Paid ✓', true );

		$oid    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mqp_table( 'orders' ) . ' WHERE document_id = %d', (int) $doc['id'] ) );
		$order  = $oid ? self::load( $oid ) : null;
		$first  = strtok( (string) $doc['customer_name'], ' ' );
		$blocks = array(
			'Hi ' . $first . ', here is your invoice ' . $doc['number'] . ( $order ? ' for order ' . $order['reference'] : '' ) . ' with the price of your order.',
			MQP_Mail::table( $rows ),
		);
		if ( $balance > 0 ) {
			$blocks[] = 'To pay, scan one of these QR codes and pay ₱' . number_format( $balance, 2 ) . ', then send us the screenshot' . ( $order ? ' with your order number ' . $order['reference'] : '' ) . '.';
			$blocks[] = self::qr_block();
		}
		$cfg = self::config();
		MQP_Mail::send(
			$email,
			'Your invoice ' . $doc['number'] . ( $order ? ' — order ' . $order['reference'] : '' ),
			$balance > 0 ? 'Your price is ready' : 'Your invoice',
			array_values( array_filter( $blocks ) ),
			$order ? array( 'See my order and pay', self::track_link( $order ) ) : ( $cfg['messenger_url'] ? array( 'Message us', $cfg['messenger_url'] ) : null )
		);
		$job_id = $order ? (int) $order['job_id'] : (int) $doc['job_id'];
		if ( $job_id ) {
			MQP_REST_Jobs::event( $job_id, 'note', '', '', 'Invoice ' . $doc['number'] . ' emailed to the customer (₱' . number_format( (float) $doc['total'], 2 ) . ')' );
		}
	}

	/** Daily cron: removes files uploaded on the order page but never used in an order. */
	public static function clean_drafts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, stored_path FROM ' . mqp_table( 'files' ) . " WHERE job_id IS NULL AND kind IN ('order_draft','payment_draft') AND created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( mqp_now() ) - DAY_IN_SECONDS ) ) );
		foreach ( $rows as $r ) {
			wp_delete_file( MQP_Files::base_dir() . '/' . $r->stored_path );
			$wpdb->delete( mqp_table( 'files' ), array( 'id' => $r->id ) );
		}
	}
}
