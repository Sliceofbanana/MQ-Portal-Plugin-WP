<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → MQ Portal → Sales: every sale from the first one recorded to today (or a chosen range).
 *
 * Sales are counted the same way as the portal's owner dashboard:
 * - POS sales: completed receipts (voided ones shown apart), by the date sold;
 * - invoices: every invoice that is not void, by the date it was made (online orders included);
 * - collected: payments received on invoices that are not void, by the date paid.
 * Total sales = POS sales + invoices.
 */
class MQP_Sales_Admin {

	const SLUG = 'mq-portal-sales';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_mqp_sales_csv', array( __CLASS__, 'csv' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hook = add_submenu_page( 'mq-portal', 'Sales', 'Sales', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 1 );
		add_action( "admin_head-$hook", array( __CLASS__, 'styles' ) );
	}

	/* ---------- Range ---------- */

	/** The first day with a sale, invoice or payment ('' when there is none yet). */
	public static function first_day() {
		global $wpdb;
		$hide  = $wpdb->suppress_errors( true );
		$dates = array_filter(
			array(
				(string) $wpdb->get_var( 'SELECT MIN(sold_at) FROM ' . mqp_table( 'pos_sales' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
				(string) $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . mqp_table( 'documents' ) . " WHERE type = 'invoice'" ), // phpcs:ignore WordPress.DB.PreparedSQL
				(string) $wpdb->get_var( 'SELECT MIN(paid_at) FROM ' . mqp_table( 'payments' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			)
		);
		$wpdb->suppress_errors( $hide );
		return $dates ? substr( min( $dates ), 0, 10 ) : '';
	}

	/** [from, to, preset] from the request; all time (first sale → today) by default. */
	private static function range( array $in ) {
		$today  = mqp_today();
		$first  = self::first_day();
		$first  = $first && $first < $today ? $first : $today;
		$preset = sanitize_key( (string) ( $in['range'] ?? '' ) );
		$from   = isset( $in['from'] ) && mqp_valid_date( $in['from'] ) ? $in['from'] : '';
		$to     = isset( $in['to'] ) && mqp_valid_date( $in['to'] ) ? $in['to'] : '';
		if ( $from || $to ) {
			$preset = 'custom';
			$from   = $from ? $from : $first;
			$to     = $to ? $to : $today;
			if ( $to < $from ) {
				list( $from, $to ) = array( $to, $from );
			}
			return array( $from, $to, $preset );
		}
		switch ( $preset ) {
			case 'year':
				return array( substr( $today, 0, 4 ) . '-01-01', $today, $preset );
			case '12m':
				return array( gmdate( 'Y-m-01', strtotime( substr( $today, 0, 7 ) . '-01 -11 months' ) ), $today, $preset );
			case 'month':
				return array( substr( $today, 0, 7 ) . '-01', $today, $preset );
			case '30d':
				return array( gmdate( 'Y-m-d', strtotime( "$today -29 days" ) ), $today, $preset );
			default:
				return array( $first, $today, 'all' );
		}
	}

	/* ---------- Figures ---------- */

	/**
	 * Every figure for the page and the CSV.
	 *
	 * @return array
	 */
	public static function data( $from, $to ) {
		global $wpdb;
		$start = "$from 00:00:00";
		$end   = "$to 23:59:59";
		$ps    = mqp_table( 'pos_sales' );
		$docs  = mqp_table( 'documents' );
		$pay   = mqp_table( 'payments' );
		$days  = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		$daily = $days <= 62; // Short ranges by day, longer ones by month.
		$fmt   = $daily ? '%%Y-%%m-%%d' : '%%Y-%%m'; // Doubled: these queries go through $wpdb->prepare().
		$hide  = $wpdb->suppress_errors( true );

		$one = function ( $sql, ...$args ) use ( $wpdb ) {
			return (array) $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		};
		$all = function ( $sql, ...$args ) use ( $wpdb ) {
			return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		};

		$pos = $one(
			"SELECT COALESCE(SUM(CASE WHEN status = 'completed' THEN total END),0) AS total,
			        COALESCE(SUM(status = 'completed'),0) AS count,
			        COALESCE(SUM(CASE WHEN status = 'void' THEN total END),0) AS void_total,
			        COALESCE(SUM(status = 'void'),0) AS void_count
			 FROM $ps WHERE sold_at BETWEEN %s AND %s",
			$start,
			$end
		);
		$inv = $one(
			"SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS count,
			        COALESCE(SUM(CASE WHEN source = 'website' THEN total END),0) AS web_total,
			        COALESCE(SUM(source = 'website'),0) AS web_count,
			        COALESCE(SUM(total - amount_paid),0) AS open_total
			 FROM $docs WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s",
			$start,
			$end
		);
		$collected = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(p.amount),0) FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s", $start, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$receivable = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total - amount_paid),0) FROM $docs WHERE type = 'invoice' AND status IN ('unpaid','partial')" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// By period.
		$periods = array();
		$add     = function ( $rows, $key ) use ( &$periods ) {
			foreach ( $rows as $r ) {
				$p = (string) $r['p'];
				if ( ! isset( $periods[ $p ] ) ) {
					$periods[ $p ] = array( 'pos' => 0.0, 'pos_count' => 0, 'invoices' => 0.0, 'invoice_count' => 0, 'collected' => 0.0 );
				}
				$periods[ $p ][ $key ] = (float) $r['v'];
				if ( isset( $r['n'] ) ) {
					$periods[ $p ][ 'pos' === $key ? 'pos_count' : 'invoice_count' ] = (int) $r['n'];
				}
			}
		};
		$add( $all( "SELECT DATE_FORMAT(sold_at, '$fmt') AS p, SUM(total) AS v, COUNT(*) AS n FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s GROUP BY p", $start, $end ), 'pos' );
		$add( $all( "SELECT DATE_FORMAT(created_at, '$fmt') AS p, SUM(total) AS v, COUNT(*) AS n FROM $docs WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s GROUP BY p", $start, $end ), 'invoices' );
		$add( $all( "SELECT DATE_FORMAT(p.paid_at, '$fmt') AS p, SUM(p.amount) AS v FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s GROUP BY p", $start, $end ), 'collected' );

		// Every day / month in the range, empty ones included, oldest first.
		$filled = array();
		$cursor = strtotime( $daily ? $from : substr( $from, 0, 7 ) . '-01' );
		$stop   = strtotime( $to );
		while ( $cursor <= $stop && count( $filled ) < 2000 ) {
			$key            = gmdate( $daily ? 'Y-m-d' : 'Y-m', $cursor );
			$row            = $periods[ $key ] ?? array( 'pos' => 0.0, 'pos_count' => 0, 'invoices' => 0.0, 'invoice_count' => 0, 'collected' => 0.0 );
			$row['total']   = mqp_money( $row['pos'] + $row['invoices'] );
			$filled[ $key ] = $row;
			$cursor         = strtotime( $daily ? '+1 day' : '+1 month', $cursor );
		}

		// By shop.
		$brands   = $all( 'SELECT id, name, has_branches FROM ' . mqp_table( 'brands' ) . ' ORDER BY sort, id' );
		$pos_b    = array_column( $all( "SELECT brand_id AS id, SUM(total) AS v, COUNT(*) AS n FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s GROUP BY brand_id", $start, $end ), null, 'id' );
		$inv_b    = array_column( $all( "SELECT brand_id AS id, SUM(total) AS v, COUNT(*) AS n FROM $docs WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s GROUP BY brand_id", $start, $end ), null, 'id' );
		$col_b    = array_column( $all( "SELECT d.brand_id AS id, SUM(p.amount) AS v FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s GROUP BY d.brand_id", $start, $end ), null, 'id' );
		$by_brand = array();
		foreach ( $brands as $b ) {
			$id         = $b['id'];
			$p          = (float) ( $pos_b[ $id ]['v'] ?? 0 );
			$i          = (float) ( $inv_b[ $id ]['v'] ?? 0 );
			$by_brand[] = array(
				'name'      => $b['name'],
				'pos'       => $p,
				'pos_count' => (int) ( $pos_b[ $id ]['n'] ?? 0 ),
				'invoices'  => $i,
				'inv_count' => (int) ( $inv_b[ $id ]['n'] ?? 0 ),
				'collected' => (float) ( $col_b[ $id ]['v'] ?? 0 ),
				'total'     => mqp_money( $p + $i ),
			);
		}

		$out = array(
			'from'        => $from,
			'to'          => $to,
			'daily'       => $daily,
			'days'        => $days,
			'pos'         => (float) ( $pos['total'] ?? 0 ),
			'pos_count'   => (int) ( $pos['count'] ?? 0 ),
			'void'        => (float) ( $pos['void_total'] ?? 0 ),
			'void_count'  => (int) ( $pos['void_count'] ?? 0 ),
			'invoices'    => (float) ( $inv['total'] ?? 0 ),
			'inv_count'   => (int) ( $inv['count'] ?? 0 ),
			'web'         => (float) ( $inv['web_total'] ?? 0 ),
			'web_count'   => (int) ( $inv['web_count'] ?? 0 ),
			'unpaid'      => (float) ( $inv['open_total'] ?? 0 ),
			'collected'   => mqp_money( $collected ),
			'receivable'  => mqp_money( $receivable ),
			'periods'     => $filled,
			'by_brand'    => $by_brand,
			'by_branch'   => $all( "SELECT b.name, COUNT(s.id) AS n, SUM(s.total) AS v FROM $ps s JOIN " . mqp_table( 'branches' ) . " b ON b.id = s.branch_id WHERE s.status = 'completed' AND s.sold_at BETWEEN %s AND %s GROUP BY b.id, b.name ORDER BY v DESC", $start, $end ),
			'pos_methods' => $all( "SELECT method AS name, COUNT(*) AS n, SUM(total) AS v FROM $ps WHERE status = 'completed' AND sold_at BETWEEN %s AND %s GROUP BY method ORDER BY v DESC", $start, $end ),
			'pay_methods' => $all( "SELECT p.method AS name, COUNT(*) AS n, SUM(p.amount) AS v FROM $pay p JOIN $docs d ON d.id = p.document_id WHERE d.status <> 'void' AND p.paid_at BETWEEN %s AND %s GROUP BY p.method ORDER BY v DESC", $start, $end ),
			'top_items'   => $all( 'SELECT i.name, SUM(i.qty) AS n, SUM(i.amount) AS v FROM ' . mqp_table( 'pos_sale_items' ) . " i JOIN $ps s ON s.id = i.sale_id WHERE s.status = 'completed' AND s.sold_at BETWEEN %s AND %s GROUP BY i.name ORDER BY v DESC LIMIT 10", $start, $end ),
			'top_clients' => $all( "SELECT CASE WHEN customer_company <> '' THEN customer_company WHEN customer_name <> '' THEN customer_name ELSE '(no name)' END AS name, COUNT(*) AS n, SUM(total) AS v FROM $docs WHERE type = 'invoice' AND status <> 'void' AND created_at BETWEEN %s AND %s GROUP BY name ORDER BY v DESC LIMIT 10", $start, $end ),
		);
		$wpdb->suppress_errors( $hide );
		$out['total'] = mqp_money( $out['pos'] + $out['invoices'] );
		return $out;
	}

	/* ---------- Page ---------- */

	private static function peso( $v ) {
		return '₱' . number_format( (float) $v, 2 );
	}

	private static function period_label( $key, $daily ) {
		return $daily ? wp_date( 'M j, Y', strtotime( $key . ' 12:00:00' ) ) : wp_date( 'M Y', strtotime( $key . '-15 12:00:00' ) );
	}

	/** Name / count / amount rows for the charts. */
	private static function rows( array $rows, $method = false ) {
		return array_map(
			function ( $r ) use ( $method ) {
				$name = (string) $r['name'];
				if ( $method ) {
					$name = '' === $name ? 'Not set' : ucwords( str_replace( '_', ' ', $name ) );
				}
				return array( 'name' => $name, 'n' => (float) $r['n'], 'v' => (float) $r['v'] );
			},
			$rows
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		list( $from, $to, $preset ) = self::range( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$d     = self::data( $from, $to );
		$first = self::first_day();

		// Payment methods: POS receipts and invoice payments together.
		$methods = array();
		foreach ( array_merge( self::rows( $d['pos_methods'], true ), self::rows( $d['pay_methods'], true ) ) as $m ) {
			$methods[ $m['name'] ] = ( $methods[ $m['name'] ] ?? 0 ) + $m['v'];
		}
		arsort( $methods );

		$periods = array();
		foreach ( $d['periods'] as $key => $r ) {
			$periods[] = array(
				'k'   => $key,
				'l'   => self::period_label( $key, $d['daily'] ),
				'pos' => $r['pos'],
				'inv' => $r['invoices'],
				'tot' => $r['total'],
				'col' => $r['collected'],
			);
		}
		$json = array(
			'daily'   => $d['daily'],
			'periods' => $periods,
			'methods' => array_map(
				function ( $k, $v ) {
					return array( 'name' => (string) $k, 'v' => $v );
				},
				array_keys( $methods ),
				array_values( $methods )
			),
			'brands'  => array_map(
				function ( $b ) {
					return array( 'name' => $b['name'], 'pos' => $b['pos'], 'inv' => $b['invoices'], 'v' => $b['total'] );
				},
				$d['by_brand']
			),
		);

		$months   = max( 1, count( $d['periods'] ) );
		$unit     = $d['daily'] ? 'day' : 'month';
		$totals   = array_column( $d['periods'], 'total' );
		$best_key = $totals && max( $totals ) > 0 ? array_keys( $d['periods'] )[ array_search( max( $totals ), $totals, true ) ] : '';
		$csv      = wp_nonce_url( add_query_arg( array( 'action' => 'mqp_sales_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'mqp_sales_csv' );
		$presets  = array( 'all' => 'All time', 'year' => 'This year', '12m' => '12 months', 'month' => 'This month', '30d' => '30 days' );

		echo '<div class="wrap mqp-sales"><h1 class="screen-reader-text">Sales</h1><div class="sx">';

		// Header.
		echo '<header class="sx-top"><div><h2 class="sx-title">Sales</h2><p class="sx-sub">' . ( $first
			? esc_html( 'Since the first sale on ' . wp_date( 'F j, Y', strtotime( $first . ' 12:00:00' ) ) . ' · showing ' . wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) ) )
			: 'No sales recorded yet. POS receipts and invoices show here as soon as the shop records them.' ) . '</p></div>';
		echo '<form class="sx-range" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><nav class="sx-seg">';
		foreach ( $presets as $k => $label ) {
			echo '<a class="' . ( $k === $preset ? 'is-on' : '' ) . '" href="' . esc_url( self::url( 'all' === $k ? array() : array( 'range' => $k ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><span class="sx-dates"><input type="date" name="from" value="' . esc_attr( $from ) . '" aria-label="From"><span>→</span><input type="date" name="to" value="' . esc_attr( $to ) . '" max="' . esc_attr( mqp_today() ) . '" aria-label="To"><button class="sx-btn' . ( 'custom' === $preset ? ' is-on' : '' ) . '">Show</button></span>';
		echo '<a class="sx-btn sx-btn-ghost" href="' . esc_url( $csv ) . '"><span class="dashicons dashicons-download"></span> CSV</a></form></header>';

		// KPIs.
		$kpi = function ( $label, $value, $sub, $tone = '' ) {
			echo '<div class="sx-kpi' . ( $tone ? ' is-' . esc_attr( $tone ) : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $sub ) . '</small></div>';
		};
		echo '<section class="sx-kpis">';
		$kpi( 'Total sales', self::peso( $d['total'] ), 'Avg ' . self::peso( $d['total'] / $months ) . ' per ' . $unit, 'accent' );
		$kpi( 'POS sales', self::peso( $d['pos'] ), number_format_i18n( $d['pos_count'] ) . ' receipts' );
		$kpi( 'Invoices', self::peso( $d['invoices'] ), number_format_i18n( $d['inv_count'] ) . ' invoices · ' . self::peso( $d['web'] ) . ' online' );
		$kpi( 'Collected', self::peso( $d['collected'] ), $d['invoices'] > 0 ? round( min( 999, $d['collected'] / $d['invoices'] * 100 ) ) . '% of invoiced' : 'Payments on invoices', 'good' );
		$kpi( 'Still to collect', self::peso( $d['receivable'] ), 'Unpaid balances today', $d['receivable'] > 0 ? 'warn' : '' );
		$kpi( 'Voided POS', self::peso( $d['void'] ), number_format_i18n( $d['void_count'] ) . ' receipts, not counted', $d['void_count'] ? 'bad' : '' );
		echo '</section>';

		// Revenue overview.
		echo '<section class="sx-card sx-wide"><div class="sx-head"><div><h3>Revenue overview</h3><p>Sales (POS + invoices) and payments collected' . ( $best_key ? ' · best ' . esc_html( $unit ) . ': <b>' . esc_html( self::period_label( $best_key, $d['daily'] ) ) . '</b> (' . esc_html( self::peso( $d['periods'][ $best_key ]['total'] ) ) . ')' : '' ) . '</p></div>';
		echo '<nav class="sx-seg sx-toggle" data-chart="rev">' . ( $d['daily'] ? '<a class="is-on" data-mode="daily">Daily</a>' : '<a class="is-on" data-mode="month">Monthly</a><a data-mode="year">Yearly</a>' ) . '</nav></div>';
		echo '<div class="sx-legend"><i class="c1"></i>Sales <i class="c2 is-dash"></i>Collected</div><div class="sx-chart" id="sx-rev"></div></section>';

		echo '<div class="sx-grid">';

		echo '<section class="sx-card"><div class="sx-head"><div><h3>POS vs invoices</h3><p>Where the sales came from</p></div><nav class="sx-seg sx-toggle" data-chart="mix">' . ( $d['daily'] ? '<a class="is-on" data-mode="daily">Daily</a>' : '<a class="is-on" data-mode="month">Monthly</a><a data-mode="year">Yearly</a>' ) . '</nav></div>';
		echo '<div class="sx-legend"><i class="c3"></i>POS <i class="c1"></i>Invoices</div><div class="sx-chart" id="sx-mix"></div></section>';

		echo '<section class="sx-card"><div class="sx-head"><div><h3>How customers paid</h3><p>POS receipts and invoice payments</p></div></div><div id="sx-donut" class="sx-donut"></div></section>';

		echo '<section class="sx-card"><div class="sx-head"><div><h3>By shop</h3><p>Total sales per brand</p></div></div><div id="sx-brands"></div></section>';

		$list = function ( $title, $sub, array $rows, $count_label ) {
			echo '<section class="sx-card"><div class="sx-head"><div><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $sub ) . '</p></div></div>';
			if ( ! $rows ) {
				echo '<p class="sx-empty">None yet.</p></section>';
				return;
			}
			$max = max( array_column( $rows, 'v' ) );
			echo '<ol class="sx-list">';
			foreach ( $rows as $i => $r ) {
				$w = $max > 0 ? $r['v'] / $max * 100 : 0;
				echo '<li><span class="sx-rank">' . (int) ( $i + 1 ) . '</span><div><div class="sx-row"><b>' . esc_html( $r['name'] ) . '</b><span>' . esc_html( self::peso( $r['v'] ) ) . '</span></div><div class="sx-bar"><i style="width:' . esc_attr( round( $w, 1 ) ) . '%"></i></div><small>' . esc_html( number_format_i18n( $r['n'], fmod( $r['n'], 1 ) ? 2 : 0 ) . ' ' . $count_label ) . '</small></div></li>';
			}
			echo '</ol></section>';
		};
		$list( 'Top POS items', 'Best sellers at the counter', self::rows( $d['top_items'] ), 'sold' );
		$list( 'Top customers', 'By invoiced amount', self::rows( $d['top_clients'] ), 'invoice(s)' );

		echo '</div>';

		// Details table.
		echo '<details class="sx-card sx-wide sx-details"><summary>' . ( $d['daily'] ? 'Day' : 'Month' ) . '-by-' . ( $d['daily'] ? 'day' : 'month' ) . ' numbers</summary><div class="sx-table-wrap"><table class="sx-table"><thead><tr><th>' . ( $d['daily'] ? 'Day' : 'Month' ) . '</th><th>POS</th><th>Invoices</th><th>Total</th><th>Collected</th></tr></thead><tbody>';
		foreach ( array_reverse( $d['periods'], true ) as $key => $r ) {
			echo '<tr' . ( $key === $best_key ? ' class="is-best"' : '' ) . '><td>' . esc_html( self::period_label( $key, $d['daily'] ) ) . '</td><td>' . esc_html( self::peso( $r['pos'] ) ) . ' <small>(' . (int) $r['pos_count'] . ')</small></td><td>' . esc_html( self::peso( $r['invoices'] ) ) . ' <small>(' . (int) $r['invoice_count'] . ')</small></td><td><b>' . esc_html( self::peso( $r['total'] ) ) . '</b></td><td>' . esc_html( self::peso( $r['collected'] ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><td>Total</td><td>' . esc_html( self::peso( $d['pos'] ) ) . '</td><td>' . esc_html( self::peso( $d['invoices'] ) ) . '</td><td>' . esc_html( self::peso( $d['total'] ) ) . '</td><td>' . esc_html( self::peso( $d['collected'] ) ) . '</td></tr></tfoot></table></div>';
		echo '<p class="sx-note">Counted the same way as the owner dashboard in the portal: POS receipts (not voided) plus invoices (not voided). Online orders become invoices, so they are inside Invoices. Collected = payments received on invoices.</p></details>';

		echo '<div class="sx-tip" id="sx-tip" hidden></div></div></div>';
		echo '<script>window.MQP_SALES=' . wp_json_encode( $json ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::script();
	}

	/** The charts: plain SVG, no outside library. */
	private static function script() {
		?>
<script>
(function () {
	var D = window.MQP_SALES; if (!D) return;
	var NS = 'http://www.w3.org/2000/svg', tip = document.getElementById('sx-tip');
	var C = ['#f97316', '#fbbf24', '#60a5fa', '#34d399', '#a78bfa', '#f472b6', '#22d3ee', '#a3e635'];
	function peso(v, short) {
		v = +v || 0;
		if (short) { var a = Math.abs(v); return (v < 0 ? '-' : '') + '₱' + (a >= 1e6 ? (a / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M' : a >= 1e3 ? (a / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'K' : Math.round(a)); }
		return '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function el(n, a, p) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; }
	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
	function nice(max) { if (max <= 0) return 1000; var p = Math.pow(10, Math.floor(Math.log10(max))), f = max / p, s = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]; for (var i = 0; i < s.length; i++) if (f <= s[i]) return s[i] * p; return 10 * p; }
	function series(mode) {
		if (mode !== 'year') return D.periods;
		var y = {}, out = [];
		D.periods.forEach(function (p) { var k = p.k.slice(0, 4); if (!y[k]) { y[k] = { k: k, l: k, pos: 0, inv: 0, tot: 0, col: 0 }; out.push(y[k]); } ['pos', 'inv', 'tot', 'col'].forEach(function (f) { y[k][f] += p[f]; }); });
		return out;
	}
	// Smooth line that never overshoots (monotone cubic).
	function curve(pts) {
		var n = pts.length; if (!n) return ''; if (n === 1) return 'M' + pts[0][0] + ',' + pts[0][1];
		var dx = [], m = [], t = [];
		for (var i = 0; i < n - 1; i++) { dx[i] = pts[i + 1][0] - pts[i][0]; m[i] = (pts[i + 1][1] - pts[i][1]) / dx[i]; }
		t[0] = m[0]; t[n - 1] = m[n - 2];
		for (i = 1; i < n - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : 3 * (dx[i - 1] + dx[i]) / ((2 * dx[i] + dx[i - 1]) / m[i - 1] + (dx[i] + 2 * dx[i - 1]) / m[i]);
		var d = 'M' + pts[0][0] + ',' + pts[0][1];
		for (i = 0; i < n - 1; i++) { var h = dx[i] / 3; d += 'C' + (pts[i][0] + h) + ',' + (pts[i][1] + h * t[i]) + ' ' + (pts[i + 1][0] - h) + ',' + (pts[i + 1][1] - h * t[i + 1]) + ' ' + pts[i + 1][0] + ',' + pts[i + 1][1]; }
		return d;
	}
	function frame(box, data, H, keys) {
		box.innerHTML = '';
		var W = Math.max(280, box.clientWidth), L = 58, R = 12, T = 12, B = 30, w = W - L - R, h = H - T - B;
		var max = 0; data.forEach(function (p) { keys.forEach(function (k) { max = Math.max(max, k === 'stack' ? p.pos + p.inv : p[k]); }); });
		var step = nice(max / 4), ticks = Math.max(1, Math.min(5, Math.ceil(max / step) || 1)), top = step * ticks;
		var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, class: 'sx-svg' }, box);
		for (var i = 0; i <= ticks; i++) {
			var y = T + h - h * i / ticks;
			el('line', { x1: L, x2: W - R, y1: y, y2: y, class: 'sx-grid-l' }, svg);
			el('text', { x: L - 10, y: y + 4, 'text-anchor': 'end', class: 'sx-ax' }, svg).textContent = peso(step * i, true);
		}
		var every = Math.ceil(data.length / Math.max(2, Math.floor(w / 64)));
		return { svg: svg, W: W, L: L, T: T, w: w, h: h, top: top, every: every };
	}
	function xlab(f, data, x, i) {
		if (i % f.every && i !== data.length - 1) return;
		var p = data[i], s = D.daily ? p.l.replace(/, \d{4}$/, '') : (p.k.length === 4 ? p.k : p.l);
		el('text', { x: x, y: f.T + f.h + 20, 'text-anchor': 'middle', class: 'sx-ax' }, f.svg).textContent = s;
	}
	function hover(f, data, xs, box, onShow) {
		var guide = el('line', { y1: f.T, y2: f.T + f.h, class: 'sx-guide', opacity: 0 }, f.svg);
		var hit = el('rect', { x: f.L, y: f.T, width: f.w, height: f.h, fill: 'transparent' }, f.svg);
		function move(ev) {
			var r = f.svg.getBoundingClientRect(), x = (ev.clientX - r.left) * f.W / r.width, best = 0;
			xs.forEach(function (v, i) { if (Math.abs(v - x) < Math.abs(xs[best] - x)) best = i; });
			guide.setAttribute('x1', xs[best]); guide.setAttribute('x2', xs[best]); guide.setAttribute('opacity', 1);
			var p = data[best];
			tip.innerHTML = '<b>' + esc(p.l) + '</b>' + onShow(p);
			tip.hidden = false;
			var tx = ev.clientX + 16, ty = ev.clientY + 12;
			if (tx + tip.offsetWidth > window.innerWidth - 8) tx = ev.clientX - tip.offsetWidth - 16;
			tip.style.left = tx + 'px'; tip.style.top = ty + 'px';
		}
		hit.addEventListener('mousemove', move);
		hit.addEventListener('mouseleave', function () { tip.hidden = true; guide.setAttribute('opacity', 0); });
	}
	var row = function (c, label, v, dash) { return '<div class="sx-tr"><i style="background:' + c + '"' + (dash ? ' class="is-dash"' : '') + '></i>' + label + '<span>' + peso(v) + '</span></div>'; };

	function revenue(mode) {
		var box = document.getElementById('sx-rev'), data = series(mode);
		if (!data.length) { box.innerHTML = '<p class="sx-empty">No sales in these dates.</p>'; return; }
		var f = frame(box, data, 300, ['tot', 'col']);
		var xs = data.map(function (p, i) { return f.L + (data.length === 1 ? f.w / 2 : f.w * i / (data.length - 1)); });
		var Y = function (v) { return f.T + f.h - v / f.top * f.h; };
		var defs = el('defs', {}, f.svg), g = el('linearGradient', { id: 'sx-fill', x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
		el('stop', { offset: '0%', 'stop-color': '#f97316', 'stop-opacity': 0.35 }, g); el('stop', { offset: '100%', 'stop-color': '#f97316', 'stop-opacity': 0 }, g);
		var tot = data.map(function (p, i) { return [xs[i], Y(p.tot)]; }), col = data.map(function (p, i) { return [xs[i], Y(p.col)]; });
		var line = curve(tot);
		el('path', { d: line + 'L' + xs[xs.length - 1] + ',' + (f.T + f.h) + 'L' + xs[0] + ',' + (f.T + f.h) + 'Z', fill: 'url(#sx-fill)' }, f.svg);
		el('path', { d: curve(col), class: 'sx-line c2' }, f.svg);
		el('path', { d: line, class: 'sx-line c1' }, f.svg);
		if (data.length <= 40) tot.forEach(function (pt) { el('circle', { cx: pt[0], cy: pt[1], r: 3, class: 'sx-dot' }, f.svg); });
		data.forEach(function (p, i) { xlab(f, data, xs[i], i); });
		hover(f, data, xs, box, function (p) { return row('#f97316', 'Sales', p.tot) + row('#60a5fa', 'POS', p.pos) + row('#fdba74', 'Invoices', p.inv) + row('#fbbf24', 'Collected', p.col, 1); });
	}

	function mix(mode) {
		var box = document.getElementById('sx-mix'), data = series(mode);
		if (!data.length) { box.innerHTML = '<p class="sx-empty">No sales in these dates.</p>'; return; }
		var f = frame(box, data, 260, ['stack']), n = data.length, slot = f.w / n, bw = Math.max(2, Math.min(34, slot * 0.62));
		var Y = function (v) { return v / f.top * f.h; }, xs = [];
		data.forEach(function (p, i) {
			var x = f.L + slot * i + slot / 2, base = f.T + f.h, hp = Y(p.pos), hi = Y(p.inv); xs.push(x);
			if (hp > 0) el('rect', { x: x - bw / 2, y: base - hp, width: bw, height: hp, rx: 0, class: 'sx-b3' }, f.svg);
			if (hi > 0) el('rect', { x: x - bw / 2, y: base - hp - hi, width: bw, height: hi, class: 'sx-b1' }, f.svg);
			if (hp + hi > 0) el('rect', { x: x - bw / 2, y: base - hp - hi, width: bw, height: Math.min(4, hp + hi), rx: 2, class: hi > 0 ? 'sx-b1' : 'sx-b3' }, f.svg);
			xlab(f, data, x, i);
		});
		hover(f, data, xs, box, function (p) { return row('#60a5fa', 'POS', p.pos) + row('#f97316', 'Invoices', p.inv) + row('#e4e4e7', 'Total', p.pos + p.inv); });
	}

	function donut() {
		var box = document.getElementById('sx-donut'), rows = D.methods.filter(function (m) { return m.v > 0; });
		var sum = rows.reduce(function (a, m) { return a + m.v; }, 0);
		if (!sum) { box.innerHTML = '<p class="sx-empty">No payments in these dates.</p>'; return; }
		var S = 180, r = 70, c = 2 * Math.PI * r, off = 0;
		var svg = el('svg', { viewBox: '0 0 ' + S + ' ' + S, width: S, height: S, class: 'sx-svg' });
		el('circle', { cx: S / 2, cy: S / 2, r: r, fill: 'none', stroke: '#26262c', 'stroke-width': 22 }, svg);
		rows.forEach(function (m, i) {
			var len = m.v / sum * c;
			el('circle', { cx: S / 2, cy: S / 2, r: r, fill: 'none', stroke: C[i % C.length], 'stroke-width': 22, 'stroke-dasharray': Math.max(0, len - 2) + ' ' + (c - Math.max(0, len - 2)), 'stroke-dashoffset': -off, transform: 'rotate(-90 ' + S / 2 + ' ' + S / 2 + ')' }, svg);
			off += len;
		});
		el('text', { x: S / 2, y: S / 2 - 2, 'text-anchor': 'middle', class: 'sx-d-big' }, svg).textContent = peso(sum, true);
		el('text', { x: S / 2, y: S / 2 + 16, 'text-anchor': 'middle', class: 'sx-ax' }, svg).textContent = 'received';
		box.innerHTML = '';
		box.appendChild(svg);
		var ul = document.createElement('ul'); ul.className = 'sx-keys';
		ul.innerHTML = rows.map(function (m, i) { return '<li><i style="background:' + C[i % C.length] + '"></i>' + esc(m.name) + '<span>' + Math.round(m.v / sum * 100) + '%</span><b>' + peso(m.v) + '</b></li>'; }).join('');
		box.appendChild(ul);
	}

	function brands() {
		var box = document.getElementById('sx-brands'), rows = D.brands.slice().sort(function (a, b) { return b.v - a.v; });
		var max = Math.max.apply(null, rows.map(function (b) { return b.v; }).concat([0]));
		box.innerHTML = rows.length ? '<ul class="sx-hbars">' + rows.map(function (b) {
			var wp = max > 0 ? b.pos / max * 100 : 0, wi = max > 0 ? b.inv / max * 100 : 0;
			return '<li><div class="sx-row"><b>' + esc(b.name) + '</b><span>' + peso(b.v) + '</span></div><div class="sx-bar sx-split"><i class="c3" style="width:' + wp + '%"></i><i class="c1" style="width:' + wi + '%"></i></div><small>POS ' + peso(b.pos) + ' · Invoices ' + peso(b.inv) + '</small></li>';
		}).join('') + '</ul>' : '<p class="sx-empty">No shops yet.</p>';
	}

	var modes = { rev: D.daily ? 'daily' : 'month', mix: D.daily ? 'daily' : 'month' };
	function draw() { revenue(modes.rev); mix(modes.mix); }
	document.querySelectorAll('.sx-toggle').forEach(function (nav) {
		nav.addEventListener('click', function (e) {
			var a = e.target.closest('a[data-mode]'); if (!a) return;
			nav.querySelectorAll('a').forEach(function (x) { x.classList.toggle('is-on', x === a); });
			modes[nav.getAttribute('data-chart')] = a.getAttribute('data-mode'); draw();
		});
	});
	var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(draw, 120); });
	draw(); donut(); brands();
})();
</script>
		<?php
	}

	/* ---------- CSV ---------- */

	public static function csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only administrators can do this.', 403 );
		}
		check_admin_referer( 'mqp_sales_csv' );
		list( $from, $to ) = self::range( wp_unslash( $_GET ) );
		$d = self::data( $from, $to );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mq-sales-' . $from . '-to-' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads ₱ and accents.
		fputcsv( $out, array( $d['daily'] ? 'Day' : 'Month', 'POS sales', 'POS receipts', 'Invoices', 'Invoice count', 'Total sales', 'Collected' ) );
		foreach ( $d['periods'] as $key => $r ) {
			fputcsv( $out, array( $key, number_format( $r['pos'], 2, '.', '' ), $r['pos_count'], number_format( $r['invoices'], 2, '.', '' ), $r['invoice_count'], number_format( $r['total'], 2, '.', '' ), number_format( $r['collected'], 2, '.', '' ) ) );
		}
		fputcsv( $out, array( 'Total', number_format( $d['pos'], 2, '.', '' ), $d['pos_count'], number_format( $d['invoices'], 2, '.', '' ), $d['inv_count'], number_format( $d['total'], 2, '.', '' ), number_format( $d['collected'], 2, '.', '' ) ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		MQP_Monitor::log( 'system', "Downloaded sales CSV ($from to $to)" );
		exit;
	}

	public static function styles() {
		echo '<style>
#wpcontent:has(.mqp-sales){background:#0c0c0f}
.mqp-sales{margin:0;padding:18px 20px 30px 0}
.mqp-sales .notice{margin:0 0 14px}
.sx{--bg:#0c0c0f;--card:#141418;--line:#24242b;--txt:#f4f4f5;--mute:#a1a1aa;--dim:#71717a;--c1:#f97316;--c2:#fbbf24;--c3:#60a5fa;--good:#34d399;--bad:#f87171;color:var(--txt);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px}
.sx *{box-sizing:border-box}
.sx-top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:14px;margin:0 0 18px}
.sx-title{margin:0;font-size:26px;font-weight:700;color:var(--txt);letter-spacing:-.02em}
.sx-sub{margin:4px 0 0;color:var(--mute)}
.sx-range{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.sx-seg{display:inline-flex;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:3px;gap:2px}
.sx-seg a{color:var(--mute);text-decoration:none;padding:6px 11px;border-radius:7px;font-weight:600;cursor:pointer;font-size:12px;line-height:1.3}
.sx-seg a:hover{color:var(--txt)}.sx-seg a.is-on{background:#26262d;color:var(--txt);box-shadow:0 1px 2px rgba(0,0,0,.4)}
.sx-seg a:focus{box-shadow:none;outline:2px solid var(--c1)}
.sx-dates{display:inline-flex;align-items:center;gap:6px;color:var(--dim)}
.sx-dates input[type=date]{background:var(--card);border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:4px 8px;min-height:32px;color-scheme:dark}
.sx-btn{display:inline-flex;align-items:center;gap:4px;background:var(--c1);color:#111;border:0;border-radius:8px;padding:0 14px;height:32px;font-weight:700;cursor:pointer;text-decoration:none}
.sx-btn:hover{filter:brightness(1.1);color:#111}
.sx-btn-ghost{background:transparent;color:var(--txt);border:1px solid var(--line)}.sx-btn-ghost:hover{color:var(--txt);border-color:#3f3f46}
.sx-btn .dashicons{font-size:16px;width:16px;height:16px}
.sx-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin:0 0 14px}
.sx-kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;display:flex;flex-direction:column;gap:4px;position:relative;overflow:hidden}
.sx-kpi span{color:var(--mute);font-weight:600;font-size:12px}
.sx-kpi strong{font-size:22px;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.sx-kpi small{color:var(--dim);font-size:12px}
.sx-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:transparent}
.sx-kpi.is-accent{background:linear-gradient(135deg,rgba(249,115,22,.22),rgba(249,115,22,.04) 60%),var(--card);border-color:rgba(249,115,22,.4)}
.sx-kpi.is-accent strong{color:#fdba74}
.sx-kpi.is-good:before{background:var(--good)}.sx-kpi.is-warn:before{background:var(--c2)}.sx-kpi.is-bad:before{background:var(--bad)}
.sx-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;min-width:0}
.sx-wide{margin:0 0 14px}
.sx-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin:0 0 10px}
.sx-head h3{margin:0;font-size:15px;font-weight:700;color:var(--txt)}
.sx-head p{margin:3px 0 0;color:var(--mute)}.sx-head p b{color:var(--txt)}
.sx-legend{display:flex;gap:16px;align-items:center;color:var(--mute);font-size:12px;margin:0 0 6px}
.sx-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:-10px}
.sx-legend i.c1{background:var(--c1)}.sx-legend i.c3{background:var(--c3)}
.sx-legend i.c2{height:0;border-top:2px dashed var(--c2);border-radius:0;width:14px}
.sx-chart{width:100%;min-height:200px}
.sx-svg{display:block;max-width:100%;overflow:visible}
.sx-grid-l{stroke:#202026;stroke-width:1}
.sx-ax{fill:var(--dim);font-size:11px;font-variant-numeric:tabular-nums}
.sx-line{fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.sx-line.c1{stroke:var(--c1)}.sx-line.c2{stroke:var(--c2);stroke-dasharray:5 5;stroke-width:2}
.sx-dot{fill:var(--card);stroke:var(--c1);stroke-width:2}
.sx-guide{stroke:#52525b;stroke-dasharray:3 4}
.sx-b1{fill:var(--c1)}.sx-b3{fill:var(--c3)}
.sx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 14px}
.sx-donut{display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.sx-d-big{fill:var(--txt);font-size:20px;font-weight:700}
.sx-keys{list-style:none;margin:0;padding:0;flex:1;min-width:180px}
.sx-keys li{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--line);margin:0}
.sx-keys li:last-child{border-bottom:0}
.sx-keys i{width:10px;height:10px;border-radius:3px;flex:none}
.sx-keys span{color:var(--dim);margin-left:auto}.sx-keys b{min-width:96px;text-align:right;font-variant-numeric:tabular-nums}
.sx-row{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.sx-row b{font-weight:600;color:var(--txt);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sx-row span{font-variant-numeric:tabular-nums;color:var(--txt);white-space:nowrap}
.sx-bar{height:8px;border-radius:99px;background:#202026;overflow:hidden;margin:6px 0 4px;display:flex}
.sx-bar i{display:block;height:100%;background:linear-gradient(90deg,#ea580c,#fb923c);border-radius:99px}
.sx-split i{border-radius:0}.sx-split i.c3{background:var(--c3)}.sx-split i.c1{background:var(--c1)}
.sx-hbars,.sx-list{list-style:none;margin:0;padding:0}
.sx-hbars li{margin:0 0 14px}.sx-hbars li:last-child{margin:0}
.sx-hbars small,.sx-list small{color:var(--dim)}
.sx-list li{display:flex;gap:12px;align-items:flex-start;margin:0 0 12px}.sx-list li>div{flex:1;min-width:0}
.sx-rank{flex:none;width:24px;height:24px;border-radius:7px;background:#202026;color:var(--mute);display:grid;place-items:center;font-weight:700;font-size:11px}
.sx-list li:first-child .sx-rank{background:rgba(249,115,22,.18);color:#fdba74}
.sx-empty{color:var(--dim);margin:20px 0;text-align:center}
.sx-details summary{cursor:pointer;font-weight:700;font-size:14px;color:var(--txt)}
.sx-details[open] summary{margin-bottom:12px}
.sx-table-wrap{overflow-x:auto}
.sx-table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.sx-table th{color:var(--dim);font-weight:600;text-align:right;padding:8px 10px;border-bottom:1px solid var(--line);font-size:12px}
.sx-table td{padding:8px 10px;border-bottom:1px solid #1d1d22;text-align:right;color:var(--txt)}
.sx-table th:first-child,.sx-table td:first-child{text-align:left}
.sx-table small{color:var(--dim)}.sx-table tfoot td{font-weight:700;border-bottom:0}
.sx-table tr.is-best td{background:rgba(52,211,153,.08)}
.sx-note{color:var(--dim);margin:12px 0 0}
.sx-tip{position:fixed;z-index:100000;pointer-events:none;background:#1c1c22;border:1px solid #33333b;border-radius:10px;padding:10px 12px;min-width:190px;box-shadow:0 10px 30px rgba(0,0,0,.5);color:var(--txt);font-size:12px}
.sx-tip b{display:block;margin:0 0 6px;font-size:13px}
.sx-tr{display:flex;align-items:center;gap:8px;padding:2px 0;color:var(--mute)}
.sx-tr i{width:9px;height:9px;border-radius:2px}.sx-tr span{margin-left:auto;color:var(--txt);font-variant-numeric:tabular-nums}
@media (max-width:1100px){.sx-grid{grid-template-columns:1fr}}
@media (max-width:782px){.mqp-sales{padding:12px 10px 24px 0}.sx-top{align-items:stretch}.sx-seg{flex-wrap:wrap}}
</style>';
	}
}
