<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → Shortcodes: every shortcode and link the plugin gives the website,
 * what each one does, where to use it and its options, with a Copy button.
 */
class MQP_Shortcodes_Admin {

	const SLUG = 'mq-portal-shortcodes';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu() {
		$hook = add_submenu_page( 'mq-portal', 'Website shortcodes', 'Shortcodes', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
	}

	/** group => [ title, intro, [ code, name, what, where, options [ attr => meaning ], examples [ code => meaning ] ] ] */
	private static function items() {
		$order = MQP_Order::url();
		return array(
			'customers' => array(
				'Customers send you things',
				'Forms that save straight into the portal, with the customer\'s contact details.',
				array(
					array(
						'[mq_upload]', 'Upload documents (starts an order)',
						'The big upload box. Customers drop or choose their files and the order steps open in a pop-up with those files (PDF for documents, PSD for shirts, tarpaulins and stickers): instructions for each file, office supplies, pick-up at the main office or a courier the customer books, and contact details. No price and no payment on the page: staff set the price and the customer pays later with the QR codes. Orders land in Sales → Online orders. Every page also has the pop-up: link any "Upload Document" button to <code>#mq-upload</code>. If you switch off online ordering (portal → Admin → Settings → Online orders), the older "send files, we contact you" form comes back instead.',
						'The "Ready to Print? Upload Here" section. Header and menu "Upload Document" buttons only need the link <code>#mq-upload</code>.',
						array(
							'text="Start Uploading"' => 'The words in the upload box.',
							'box="no"'               => 'No box on the page (buttons linking to <code>#mq-upload</code> still open the order).',
						),
						array(),
					),
					array(
						'[mq_quote]', 'Get Quotation',
						'An orange "Get Quotation" button and pop-up. Customers pick items with a set price (instant quotation) and/or describe custom work, then give their name, email and mobile. It becomes a quotation in the Invoice Manager; custom items wait as "Needs pricing" and the customer is told you reply within 24 hours. They can accept the quotation online from the link in their email.',
						'Not needed for the pop-up itself: every page has it. Just link your "Get Quotation" buttons to <code>#mq-quote</code>. Use the shortcode only where you want its own orange button.',
						array(
							'button="Request a quote"' => 'Text of the button. <code>button="no"</code>: no button.',
							'brand="EP"'               => 'Fix the brand (otherwise customers choose).',
						),
						array(),
					),
					array(
						'[mq_order_button]', 'Order online (with payment)',
						'An "Order online" button that opens the order steps in a pop-up: upload the files, choose options (paper, color, binding…), see the price, pay with GCash/bank and get a reference number. Same as <code>[mq_order]</code>.',
						'The "How EasyPrint Works" section of the Services page, or anywhere you invite people to order.',
						array(
							'text="Order now"' => 'Text of the button.',
							'track="yes"'      => 'A "Track my order" button instead (customers type their reference and email).',
							'popup="no"'       => 'Open the full order page (' . esc_html( $order ) . ') instead of a pop-up.',
						),
						array(
							'[mq_order_button text="Start your order"]' => 'Order button.',
							'[mq_order_button track="yes"]'             => 'Track my order button.',
						),
					),
				),
			),
			'show' => array(
				'Show on the website',
				'Content that updates by itself from the portal.',
				array(
					array(
						'[mq_prices]', 'Price list',
						'The live price list from the POS products marked "On the website" (Admin → Settings → POS products), grouped by category, with a "Get a quotation" button. Change a price in the portal and the website changes too.',
						'The "Our Print Collection" or a "Prices" section.',
						array(
							'brand="EP"'                   => 'Only one brand\'s products.',
							'category="Printing, Binding"' => 'Only these categories (as named in the POS).',
							'button="no"'                  => 'No button (or other text: <code>button="Ask for a quote"</code>).',
							'note="no"'                    => 'Hide the small note under the list.',
						),
						array( '[mq_prices category="Printing, Photocopy"]' => 'Only printing and photocopy prices.' ),
					),
					array(
						'[mq_reviews]', 'Customer reviews',
						'Review cards in a carousel (3 on computers, 2 on tablets, 1 on phones) with the "Leave a Review" button and form. Only 4- and 5-star reviews you showed in MQ Portal → Reviews appear.',
						'The "What Our Customers Say" section.',
						array(
							'limit="12"'      => 'How many reviews (newest first).',
							'source="google"' => 'Only Google reviews (or <code>manual</code>, <code>website</code>).',
							'button="no"'     => 'No "Leave a Review" button (or other text).',
							'summary="no"'    => 'Hide the Google rating line above the cards.',
						),
						array(),
					),
					array(
						'[mq_print_collection]', 'Our print collection (photo carousel)',
						'Only the photo carousel (the section\'s heading, text and button stay as they are): photos of finished prints with arrows, swipe on phones and click to see a photo large. Add, sort, hide or delete photos in MQ Portal → Print collection. Photos are made small for the web, so many photos take little storage.',
						'The "Our Print Collection" section, in place of the old gallery widget.',
						array(
							'per_view="3"'    => 'Photos side by side on computers (2 to 6; default from the gallery settings).',
							'shape="square"'  => 'Photo shape: <code>portrait</code>, <code>square</code>, <code>tall</code> or <code>landscape</code>.',
							'autoplay="5"'    => 'Slide by itself every 5 seconds.',
							'limit="20"'      => 'Show only the first 20 photos.',
						),
						array(),
					),
					array(
						'[mq_previous_orders]', 'Our previous orders (Facebook posts)',
						'A whole section: heading, a short text, cards with the picture and caption of Facebook posts featuring your orders, and a "See more on our Facebook page" button. Add, sort and switch posts on or off in MQ Portal → Previous orders.',
						'The "Our Previous Orders" section.',
						array(
							'limit="6"'         => 'How many posts (the first ones in the list).',
							'style="facebook"'  => 'Facebook\'s own post cards (full caption and likes; slower, loads Facebook).',
							'button="no"'       => 'No button (or other text).',
							'heading="no"'      => 'No heading (or other text). <code>intro="no"</code> hides the text under it.',
						),
						array(),
					),
					array(
						'[mq_featured_review]', 'Featured review (one per week)',
						'One review card, a new one every Monday. Choose which reviews take turns with "Add to featured lineup" in MQ Portal → Reviews; the line under the name (e.g. "2026 BAR passer") is set with Edit.',
						'The review column of the "Have questions? Contact Us Before You Print" section.',
						array(
							'label="Review of the week"' => 'A small label on top of the card.',
							'link="/about-us/"'          => 'A "See all reviews →" link under the card (<code>link_text="…"</code> changes the words).',
						),
						array(),
					),
				),
			),
			'staff' => array(
				'For staff',
				'',
				array(
					array(
						'[mq_staff_login]', 'Staff login link',
						'A small "Staff Login" link to the portal\'s sign-in page.',
						'The footer.',
						array( 'text="Staff Log in"' => 'The words of the link.' ),
						array(),
					),
				),
			),
		);
	}

	public static function render() {
		echo '<div class="wrap mqp-sys mqp-sc"><h1>Website shortcodes</h1>';
		echo '<p class="mqp-intro">Put these on your website pages. In Elementor, drag a <strong>Shortcode</strong> widget where it should go and paste the shortcode. Click <strong>Copy</strong> to copy one.</p>';

		echo '<nav class="mqp-sc-toc">';
		foreach ( self::items() as $group ) {
			foreach ( $group[2] as $sc ) {
				echo '<a href="#' . esc_attr( self::anchor( $sc[0] ) ) . '"><code>' . esc_html( $sc[0] ) . '</code> ' . esc_html( $sc[1] ) . '</a>';
			}
		}
		echo '<a href="#links"><code>#mq-quote</code> Links for buttons</a></nav>';

		foreach ( self::items() as $group ) {
			echo '<h2 class="mqp-sc-group">' . esc_html( $group[0] ) . '</h2>';
			if ( $group[1] ) {
				echo '<p class="description">' . esc_html( $group[1] ) . '</p>';
			}
			foreach ( $group[2] as $sc ) {
				list( $code, $name, $what, $where, $opts, $examples ) = $sc;
				echo '<section class="mqp-card mqp-sc-card" id="' . esc_attr( self::anchor( $code ) ) . '">';
				echo '<div class="mqp-sc-head"><div><h3>' . esc_html( $name ) . '</h3>' . self::copy( $code ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p>' . wp_kses_post( $what ) . '</p>';
				echo '<p><strong>Where:</strong> ' . wp_kses_post( $where ) . '</p>';
				if ( $opts ) {
					echo '<details class="mqp-sc-opts"><summary>Options (' . count( $opts ) . ')</summary><table class="mqp-facts">';
					foreach ( $opts as $attr => $meaning ) {
						echo '<tr><th><code>' . esc_html( $attr ) . '</code></th><td>' . wp_kses_post( $meaning ) . '</td></tr>';
					}
					echo '</table></details>';
				}
				foreach ( $examples as $ex => $meaning ) {
					echo '<p class="mqp-sc-ex">' . self::copy( $ex ) . ' <span class="description">' . esc_html( $meaning ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</section>';
			}
		}

		$rows = array(
			'#mq-quote'  => 'Opens the "Get Quotation" pop-up (any page). Or give the button the CSS class <code>mq-quote-open</code> (Elementor: Advanced → CSS Classes).',
			'#mq-upload' => 'Opens the order steps (upload, options, price, payment) in a pop-up, on any page. Or the CSS class <code>mq-upload-open</code>.',
			MQP_Order::url() => 'The online order page (upload, options, payment).',
			MQP_Order::url( 'track/' ) => 'Customers track an online order with their reference and email.',
			MQP_Portal::url() => 'The staff portal (sign-in page).',
		);
		echo '<h2 class="mqp-sc-group" id="links">Links for buttons</h2><p class="description">No shortcode needed: put these in a button\'s Link box.</p>';
		echo '<section class="mqp-card mqp-sc-card"><table class="mqp-facts">';
		foreach ( $rows as $link => $meaning ) {
			echo '<tr><th>' . self::copy( $link ) . '</th><td>' . wp_kses_post( $meaning ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</table></section>';
		echo '</div>';
		?>
		<script>
		document.addEventListener('click', function (e) {
			var b = e.target.closest('.mqp-sc-copy button');
			if (!b) return;
			var text = b.parentNode.querySelector('code').textContent;
			var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
			if (navigator.clipboard) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done();
			}
		});
		</script>
		<?php
	}

	private static function anchor( $code ) {
		return 'sc-' . sanitize_title( trim( strtok( $code, ' ' ), '[]' ) );
	}

	private static function copy( $text ) {
		return '<span class="mqp-sc-copy"><code>' . esc_html( $text ) . '</code><button type="button" class="button button-small">Copy</button></span>';
	}

	public static function styles() {
		echo '<style>
.mqp-sc .mqp-sc-toc{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 4px}
.mqp-sc .mqp-sc-toc a{padding:5px 10px;background:#fff;border:1px solid #dcdcde;border-radius:999px;text-decoration:none;color:#1d2327;font-size:12px}
.mqp-sc .mqp-sc-toc a:hover{border-color:#2271b1;color:#2271b1}
.mqp-sc .mqp-sc-toc code{background:none;padding:0;font-size:12px}
.mqp-sc .mqp-sc-group{margin:26px 0 4px;font-size:17px}
.mqp-sc .mqp-sc-card{max-width:900px;margin:12px 0}
.mqp-sc .mqp-sc-card h3{margin:0 0 8px;font-size:15px}
.mqp-sc .mqp-sc-card p{max-width:760px}
.mqp-sc .mqp-sc-copy{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
.mqp-sc .mqp-sc-copy code{font-size:13px;padding:4px 8px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;word-break:break-all}
.mqp-sc .mqp-sc-opts{margin:6px 0 4px}
.mqp-sc .mqp-sc-opts summary{cursor:pointer;font-weight:600;color:#2271b1}
.mqp-sc .mqp-sc-opts table{margin-top:8px}
.mqp-sc .mqp-sc-ex{margin:8px 0 0}
</style>';
	}
}
