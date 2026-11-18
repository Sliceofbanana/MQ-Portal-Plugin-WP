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
		add_action( "admin_head-$hook", array( 'MQP_System', 'styles' ) );
		add_action( "admin_head-$hook", array( 'MQP_Dev_Admin', 'styles' ) );
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
		return $daily ? wp_date( 'D, M j, Y', strtotime( $key . ' 12:00:00' ) ) : wp_date( 'F Y', strtotime( $key . '-15 12:00:00' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		list( $from, $to, $preset ) = self::range( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$d     = self::data( $from, $to );
		$first = self::first_day();
		$money = function ( $v ) {
			return esc_html( self::peso( $v ) );
		};

		echo '<div class="wrap mqp-sys mqp-dev mqp-sales"><h1 class="wp-heading-inline">Sales</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'mqp_sales_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'mqp_sales_csv' ) ) . '">Download CSV</a><hr class="wp-header-end">';
		echo '<p class="mqp-intro">' . ( $first
			? 'Every sale since the first one recorded on ' . esc_html( wp_date( 'F j, Y', strtotime( $first . ' 12:00:00' ) ) ) . '. Counted the same way as the owner dashboard in the portal: POS receipts (not voided) plus invoices (not voided), with payments received shown as Collected.'
			: 'No sales recorded yet. POS receipts and invoices show here as soon as the shop records them.' ) . '</p>';

		// Range picker.
		$presets = array( 'all' => 'From the start', 'year' => 'This year', '12m' => 'Last 12 months', 'month' => 'This month', '30d' => 'Last 30 days' );
		echo '<form class="mqp-sales-range" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><div class="mqp-sales-presets">';
		foreach ( $presets as $k => $label ) {
			echo '<a class="button' . ( $k === $preset ? ' button-primary' : '' ) . '" href="' . esc_url( self::url( 'all' === $k ? array() : array( 'range' => $k ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div><label>From <input type="date" name="from" value="' . esc_attr( $from ) . '"></label><label>To <input type="date" name="to" value="' . esc_attr( $to ) . '" max="' . esc_attr( mqp_today() ) . '"></label><button class="button' . ( 'custom' === $preset ? ' button-primary' : '' ) . '">Show</button></form>';
		echo '<p class="description">' . esc_html( wp_date( 'M j, Y', strtotime( $from . ' 12:00:00' ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $to . ' 12:00:00' ) ) . ' · ' . number_format_i18n( $d['days'] ) . ' day(s)' ) . '</p>';

		// Tiles.
		$months = max( 1, count( $d['periods'] ) );
		$tile   = function ( $label, $value, $sub, $tone = '' ) {
			return '<div class="mqp-dev-tile' . ( $tone ? ' is-' . $tone : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $sub ) . '</small></div>';
		};
		echo '<div class="mqp-dev-tiles">';
		echo $tile( 'Total sales', self::peso( $d['total'] ), 'POS + invoices · avg ' . self::peso( $d['total'] / $months ) . ' per ' . ( $d['daily'] ? 'day' : 'month' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'POS sales', self::peso( $d['pos'] ), number_format_i18n( $d['pos_count'] ) . ' receipt(s)' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Invoices', self::peso( $d['invoices'] ), number_format_i18n( $d['inv_count'] ) . ' invoice(s) · ' . self::peso( $d['web'] ) . ' from online orders' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Collected', self::peso( $d['collected'] ), 'Payments received on invoices' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Still to collect', self::peso( $d['receivable'] ), 'All unpaid invoice balances, today', $d['receivable'] > 0 ? 'warn' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $tile( 'Voided POS', self::peso( $d['void'] ), number_format_i18n( $d['void_count'] ) . ' receipt(s), not counted', $d['void_count'] ? 'bad' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		echo '<div class="mqp-grid"><div>';

		// By period, with bars.
		$rows = array_reverse( $d['periods'], true );
		$max  = max( 1, max( array_merge( array( 0 ), array_column( $d['periods'], 'total' ) ) ) );
		$best = $d['periods'] ? array_search( max( array_column( $d['periods'], 'total' ) ), array_column( $d['periods'], 'total' ), true ) : false;
		$best = false !== $best ? array_keys( $d['periods'] )[ $best ] : '';
		echo '<div class="mqp-card"><div class="mqp-dev-head"><h2>' . ( $d['daily'] ? 'By day' : 'By month' ) . '</h2><span class="mqp-sales-legend"><i class="is-pos"></i>POS <i class="is-inv"></i>Invoices</span></div>';
		if ( $best && $d['total'] > 0 ) {
			echo '<p class="description">Best ' . ( $d['daily'] ? 'day' : 'month' ) . ': <strong>' . esc_html( self::period_label( $best, $d['daily'] ) ) . '</strong> (' . $money( $d['periods'][ $best ]['total'] ) . ').</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<table class="widefat striped mqp-sales-periods"><thead><tr><th>' . ( $d['daily'] ? 'Day' : 'Month' ) . '</th><th class="mqp-num">POS</th><th class="mqp-num">Invoices</th><th class="mqp-num">Total</th><th class="mqp-num">Collected</th><th class="mqp-sales-barcol"></th></tr></thead><tbody>';
		foreach ( $rows as $key => $r ) {
			$wp = $r['pos'] / $max * 100;
			$wi = $r['invoices'] / $max * 100;
			echo '<tr' . ( $key === $best && $r['total'] > 0 ? ' class="is-best"' : '' ) . '><td>' . esc_html( self::period_label( $key, $d['daily'] ) ) . '</td>'
				. '<td class="mqp-num">' . $money( $r['pos'] ) . ' <small>(' . (int) $r['pos_count'] . ')</small></td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td class="mqp-num">' . $money( $r['invoices'] ) . ' <small>(' . (int) $r['invoice_count'] . ')</small></td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td class="mqp-num"><strong>' . $money( $r['total'] ) . '</strong></td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td class="mqp-num">' . $money( $r['collected'] ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td class="mqp-sales-barcol"><div class="mqp-sales-bar"><span class="is-pos" style="width:' . esc_attr( round( $wp, 2 ) ) . '%"></span><span class="is-inv" style="width:' . esc_attr( round( $wi, 2 ) ) . '%"></span></div></td></tr>';
		}
		echo '</tbody><tfoot><tr><th>Total</th><th class="mqp-num">' . $money( $d['pos'] ) . '</th><th class="mqp-num">' . $money( $d['invoices'] ) . '</th><th class="mqp-num">' . $money( $d['total'] ) . '</th><th class="mqp-num">' . $money( $d['collected'] ) . '</th><th></th></tr></tfoot></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// By shop.
		echo '<div class="mqp-card"><h2>By shop</h2><table class="widefat striped"><thead><tr><th>Shop</th><th class="mqp-num">POS</th><th class="mqp-num">Invoices</th><th class="mqp-num">Total</th><th class="mqp-num">Collected</th></tr></thead><tbody>';
		foreach ( $d['by_brand'] as $b ) {
			echo '<tr><td>' . esc_html( $b['name'] ) . '</td><td class="mqp-num">' . $money( $b['pos'] ) . ' <small>(' . (int) $b['pos_count'] . ')</small></td><td class="mqp-num">' . $money( $b['invoices'] ) . ' <small>(' . (int) $b['inv_count'] . ')</small></td><td class="mqp-num"><strong>' . $money( $b['total'] ) . '</strong></td><td class="mqp-num">' . $money( $b['collected'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		if ( count( $d['by_branch'] ) > 1 ) {
			echo '<h3>POS by branch</h3>';
			self::list_table( $d['by_branch'], 'Branch', 'Receipts' );
		}
		echo '</div>';

		echo '</div><div>';

		echo '<div class="mqp-card"><h2>Top POS items</h2>';
		self::list_table( $d['top_items'], 'Item', 'Qty' );
		echo '</div>';

		echo '<div class="mqp-card"><h2>Top customers <span class="description">(invoices)</span></h2>';
		self::list_table( $d['top_clients'], 'Customer', 'Invoices' );
		echo '</div>';

		echo '<div class="mqp-card"><h2>How customers paid</h2><h3>POS</h3>';
		self::list_table( $d['pos_methods'], 'Method', 'Receipts', true );
		echo '<h3>Invoice payments</h3>';
		self::list_table( $d['pay_methods'], 'Method', 'Payments', true );
		echo '</div>';

		echo '<div class="mqp-card"><h2>Online orders</h2><table class="mqp-facts">';
		echo '<tr><th>Invoices from the website</th><td>' . esc_html( number_format_i18n( $d['web_count'] ) ) . ' · ' . $money( $d['web'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Share of invoices</th><td>' . esc_html( $d['invoices'] > 0 ? round( $d['web'] / $d['invoices'] * 100 ) . '%' : '—' ) . '</td></tr>';
		echo '<tr><th>Unpaid on these dates\' invoices</th><td>' . $money( $d['unpaid'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</table><p class="description">Online orders become invoices, so they are already in the Invoices total.</p></div>';

		echo '</div></div></div>';
	}

	/** Name · count · amount table, or "None yet". */
	private static function list_table( array $rows, $label, $count_label, $method = false ) {
		if ( ! $rows ) {
			echo '<p class="description">None yet.</p>';
			return;
		}
		$sum = array_sum( array_map( 'floatval', array_column( $rows, 'v' ) ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( $label ) . '</th><th class="mqp-num">' . esc_html( $count_label ) . '</th><th class="mqp-num">Amount</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$name = (string) $r['name'];
			$name = $method ? ( '' === $name ? 'Not set' : ucwords( str_replace( '_', ' ', $name ) ) ) : $name;
			$pct  = $sum > 0 ? ' <small>' . round( (float) $r['v'] / $sum * 100 ) . '%</small>' : '';
			echo '<tr><td>' . esc_html( $name ) . '</td><td class="mqp-num">' . esc_html( number_format_i18n( (float) $r['n'], fmod( (float) $r['n'], 1 ) ? 2 : 0 ) ) . '</td><td class="mqp-num">' . esc_html( self::peso( $r['v'] ) ) . $pct . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
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
.mqp-sales .mqp-sales-range{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin:4px 0 6px}
.mqp-sales .mqp-sales-presets{display:flex;flex-wrap:wrap;gap:6px;margin-right:6px}
.mqp-sales .mqp-sales-range label{display:flex;gap:6px;align-items:center}
.mqp-sales .mqp-num{text-align:right;white-space:nowrap}
.mqp-sales .mqp-num small,.mqp-sales td small{color:#646970}
.mqp-sales table.widefat tfoot th{font-weight:700}
.mqp-sales .mqp-sales-barcol{width:28%;min-width:120px}
.mqp-sales .mqp-sales-bar{display:flex;height:12px;border-radius:6px;background:#f0f0f1;overflow:hidden}
.mqp-sales .mqp-sales-bar span{display:block;height:100%}
.mqp-sales .is-pos{background:#2271b1}.mqp-sales .is-inv{background:#f88a35}
.mqp-sales .mqp-sales-legend{display:flex;gap:6px;align-items:center;color:#50575e;font-size:12px}
.mqp-sales .mqp-sales-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-left:6px}
.mqp-sales tr.is-best td{background:#edfaef}
@media (max-width:960px){.mqp-sales .mqp-grid{grid-template-columns:1fr}.mqp-sales .mqp-sales-barcol{display:none}}
</style>';
	}
}
