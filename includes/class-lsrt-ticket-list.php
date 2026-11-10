<?php
/**
 * WooCommerce → Raffle tickets: every ticket number sold online, one raffle at a time.
 *
 * Built for tallying the paper stubs against online payments. Each raffle is one
 * product (make a new one for each event), grouped under the year it sold in.
 *
 * @package Lumaha_Simple_Raffle_Tickets
 */

defined( 'ABSPATH' ) || exit;

final class LSRT_Ticket_List {

	const PAGE = 'lsrt-tickets';
	const CSV  = 'lsrt_tickets_csv';

	/** @var Lumaha_Simple_Raffle_Tickets */
	private $raffle;

	public function __construct( Lumaha_Simple_Raffle_Tickets $raffle ) {
		$this->raffle = $raffle;
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_' . self::CSV, array( $this, 'csv' ) );
	}

	/** Who can see the list. manage_woocommerce = administrators and shop managers. */
	private function capability() {
		return (string) apply_filters( 'lsrt_ticket_list_capability', 'manage_woocommerce' );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Raffle tickets', 'lumaha-simple-raffle-tickets' ),
			__( 'Raffle tickets', 'lumaha-simple-raffle-tickets' ),
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/** Orders in these statuses are left off: the tickets weren't bought. */
	private function skipped_statuses() {
		return (array) apply_filters( 'lsrt_ticket_list_skipped_statuses', array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ) );
	}

	/** Statuses that count as paid. Anything else shown is flagged as not paid yet. */
	private function paid_statuses() {
		return (array) apply_filters( 'lsrt_ticket_list_paid_statuses', wc_get_is_paid_statuses() );
	}

	/**
	 * Every sold book or single ticket, from the numbers saved on order lines.
	 *
	 * Reads the order line tables directly, which WooCommerce uses with or without
	 * High-Performance Order Storage, so a product that has since left the raffle
	 * category still shows its sales.
	 *
	 * @return array[] Rows: product_id, product, year, order, numbers (int[]), size, amount.
	 */
	private function rows() {
		global $wpdb;
		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin report, no core API for this.
			"SELECT oi.order_item_id, oi.order_id, oi.order_item_name, pm.meta_value AS product_id, vm.meta_value AS variation_id, nm.meta_value AS numbers
			FROM {$wpdb->prefix}woocommerce_order_items oi
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta nm ON nm.order_item_id = oi.order_item_id AND nm.meta_key = '_lsrt_numbers'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta vm ON vm.order_item_id = oi.order_item_id AND vm.meta_key = '_variation_id'
			WHERE oi.order_item_type = 'line_item'"
		);

		$skip  = $this->skipped_statuses();
		$paid  = $this->paid_statuses();
		$names = array();
		$rows  = array();
		foreach ( (array) $items as $it ) {
			$order = wc_get_order( (int) $it->order_id );
			if ( ! $order || 'shop_order' !== $order->get_type() || in_array( $order->get_status(), $skip, true ) ) {
				continue;
			}
			$item = $order->get_item( (int) $it->order_item_id );
			if ( ! $item ) {
				continue;
			}
			$numbers = array_values( array_filter( array_map( 'intval', explode( ',', (string) $it->numbers ) ) ) );
			if ( ! $numbers ) {
				continue;
			}
			$pid = (int) $it->product_id;
			if ( ! isset( $names[ $pid ] ) ) {
				$product       = wc_get_product( $pid );
				$names[ $pid ] = $product ? $product->get_name() : $it->order_item_name;
			}

			// Split the line back into its books: numbers are saved book by book.
			$qty   = max( 1, (int) $item->get_quantity() );
			$size  = max( 1, (int) floor( count( $numbers ) / $qty ) );
			$books = array_chunk( $numbers, $size );
			$each  = (float) $item->get_total() / count( $books );
			$date  = $order->get_date_created();

			foreach ( $books as $book ) {
				$rows[] = array(
					'product_id' => $pid,
					'product'    => $names[ $pid ],
					'year'       => $date ? (int) $date->date_i18n( 'Y' ) : 0,
					'order'      => $order,
					'paid'       => in_array( $order->get_status(), $paid, true ),
					'numbers'    => $book,
					'size'       => count( $book ),
					'amount'     => $each,
				);
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $a['numbers'][0], $a['order']->get_id() ) <=> array( $b['numbers'][0], $b['order']->get_id() );
			}
		);
		return $rows;
	}

	/** Years with sales, newest first, each holding its raffles: product_id => name. */
	private function index( array $rows ) {
		$years = array();
		foreach ( $rows as $r ) {
			$years[ $r['year'] ][ $r['product_id'] ] = $r['product'];
		}
		krsort( $years );
		foreach ( $years as &$raffles ) {
			asort( $raffles );
		}
		return $years;
	}

	/** Ticket numbers that appear on more than one order, per raffle. */
	private function clashes( array $rows ) {
		$seen = array();
		foreach ( $rows as $r ) {
			foreach ( $r['numbers'] as $n ) {
				$seen[ $r['product_id'] ][ $n ][ $r['order']->get_id() ] = true;
			}
		}
		$out = array();
		foreach ( $seen as $pid => $nums ) {
			foreach ( $nums as $n => $orders ) {
				if ( count( $orders ) > 1 ) {
					$out[ $pid ][ $n ] = true;
				}
			}
		}
		return $out;
	}

	/** Year and raffle picked in the URL, falling back to the newest year and all its raffles. */
	private function selection( array $years ) {
		$year = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		if ( ! isset( $years[ $year ] ) ) {
			$year = $years ? (int) key( $years ) : 0;
		}
		$raffle = isset( $_GET['raffle'] ) ? absint( wp_unslash( $_GET['raffle'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $raffle && ! isset( $years[ $year ][ $raffle ] ) ) {
			$raffle = 0;
		}
		return array( $year, $raffle );
	}

	private function filter( array $rows, $year, $raffle ) {
		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $year, $raffle ) {
					return $r['year'] === $year && ( ! $raffle || $r['product_id'] === $raffle );
				}
			)
		);
	}

	private function label( array $r ) {
		$first = $r['numbers'][0];
		$last  = end( $r['numbers'] );
		return $r['size'] > 1 ? $first . '–' . $last : (string) $first;
	}

	private function buyer( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		return '' !== $name ? $name : $order->get_billing_email();
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	private function url( $args ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public function render() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'lumaha-simple-raffle-tickets' ) );
		}
		$rows                     = $this->rows();
		$years                    = $this->index( $rows );
		list( $year, $raffle )    = $this->selection( $years );
		$clashes                  = $this->clashes( $rows );

		echo '<div class="wrap lsrt-list">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Raffle tickets', 'lumaha-simple-raffle-tickets' ) . '</h1>';
		$this->styles();

		if ( ! $years ) {
			echo '<p>' . esc_html__( 'No raffle tickets have been sold online yet. Tickets show here once an order with ticket numbers is placed.', 'lumaha-simple-raffle-tickets' ) . '</p></div>';
			return;
		}

		echo '<p class="lsrt-help">' . esc_html__( 'Every ticket number bought online, in number order, to tally against the paper stubs. Each raffle is one product, so make a new product for each event. Cancelled, refunded and failed orders are left off.', 'lumaha-simple-raffle-tickets' ) . '</p>';

		// Years.
		echo '<nav class="nav-tab-wrapper lsrt-noprint">';
		foreach ( array_keys( $years ) as $y ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $this->url( array( 'year' => $y ) ) ),
				$y === $year ? ' nav-tab-active' : '',
				esc_html( $y ? (string) $y : __( 'No date', 'lumaha-simple-raffle-tickets' ) )
			);
		}
		echo '</nav>';

		// Raffles in that year.
		$links = array(
			sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $this->url( array( 'year' => $year ) ) ),
				$raffle ? '' : ' class="current"',
				esc_html__( 'All raffles', 'lumaha-simple-raffle-tickets' )
			),
		);
		foreach ( $years[ $year ] as $pid => $name ) {
			$links[] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $this->url( array( 'year' => $year, 'raffle' => $pid ) ) ),
				$raffle === $pid ? ' class="current"' : '',
				esc_html( $name )
			);
		}
		echo '<ul class="subsubsub lsrt-noprint"><li>' . implode( ' | </li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.

		printf(
			'<p class="lsrt-actions lsrt-noprint"><a class="button" href="%s">%s</a> <button type="button" class="button" onclick="window.print()">%s</button></p>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array( 'action' => self::CSV, 'year' => $year, 'raffle' => $raffle ),
						admin_url( 'admin-post.php' )
					),
					self::CSV
				)
			),
			esc_html__( 'Download CSV', 'lumaha-simple-raffle-tickets' ),
			esc_html__( 'Print', 'lumaha-simple-raffle-tickets' )
		);

		$shown = $raffle ? array( $raffle => $years[ $year ][ $raffle ] ) : $years[ $year ];
		$rows  = $this->filter( $rows, $year, $raffle );
		foreach ( $shown as $pid => $name ) {
			$this->table(
				$name,
				array_values(
					array_filter(
						$rows,
						static function ( $r ) use ( $pid ) {
							return $r['product_id'] === $pid;
						}
					)
				),
				isset( $clashes[ $pid ] ) ? $clashes[ $pid ] : array()
			);
		}
		echo '</div>';
	}

	private function table( $name, array $rows, array $clash ) {
		$tickets = 0;
		$orders  = array();
		$money   = 0.0;
		$unpaid  = 0;
		foreach ( $rows as $r ) {
			$tickets                           += $r['size'];
			$orders[ $r['order']->get_id() ]    = true;
			$money                             += $r['amount'];
			$unpaid                            += $r['paid'] ? 0 : $r['size'];
		}

		echo '<h2>' . esc_html( $name ) . '</h2>';
		echo '<p class="lsrt-summary">';
		printf(
			/* translators: 1: number of tickets, 2: number of orders, 3: money taken */
			esc_html__( '%1$s tickets on %2$s orders, %3$s.', 'lumaha-simple-raffle-tickets' ),
			'<strong>' . esc_html( number_format_i18n( $tickets ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( count( $orders ) ) ) . '</strong>',
			'<strong>' . wp_kses_post( wc_price( $money ) ) . '</strong>'
		);
		if ( $unpaid ) {
			echo ' ';
			/* translators: %s: number of tickets */
			printf( esc_html__( '%s of them not paid yet (marked below).', 'lumaha-simple-raffle-tickets' ), esc_html( number_format_i18n( $unpaid ) ) );
		}
		if ( $clash ) {
			echo ' <span class="lsrt-bad">';
			/* translators: %s: list of ticket numbers */
			printf( esc_html__( 'Sold on more than one order: %s.', 'lumaha-simple-raffle-tickets' ), esc_html( implode( ', ', array_keys( $clash ) ) ) );
			echo '</span>';
		}
		echo '</p>';

		echo '<table class="widefat striped lsrt-table"><thead><tr>';
		echo '<th class="lsrt-tick" aria-label="' . esc_attr__( 'Stub checked', 'lumaha-simple-raffle-tickets' ) . '">&#10003;</th>';
		foreach ( array(
			__( 'Ticket numbers', 'lumaha-simple-raffle-tickets' ),
			__( 'Type', 'lumaha-simple-raffle-tickets' ),
			__( 'Buyer', 'lumaha-simple-raffle-tickets' ),
			__( 'Order', 'lumaha-simple-raffle-tickets' ),
			__( 'Date', 'lumaha-simple-raffle-tickets' ),
			__( 'Paid by', 'lumaha-simple-raffle-tickets' ),
			__( 'Status', 'lumaha-simple-raffle-tickets' ),
		) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$o    = $r['order'];
			$bad  = array_intersect_key( $clash, array_flip( $r['numbers'] ) );
			$date = $o->get_date_paid() ? $o->get_date_paid() : $o->get_date_created();
			printf(
				'<tr class="%s"><td class="lsrt-tick"><span class="lsrt-box"></span></td><td class="lsrt-num">%s</td><td>%s</td><td>%s</td><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_attr( trim( ( $bad ? 'lsrt-clash ' : '' ) . ( $r['paid'] ? '' : 'lsrt-unpaid' ) ) ),
				esc_html( $this->label( $r ) ),
				esc_html(
					$r['size'] > 1
						/* translators: %d: tickets in the book */
						? sprintf( __( 'Book of %d', 'lumaha-simple-raffle-tickets' ), $r['size'] )
						: __( 'Single', 'lumaha-simple-raffle-tickets' )
				),
				esc_html( $this->buyer( $o ) ),
				esc_url( $o->get_edit_order_url() ),
				esc_html( $o->get_order_number() ),
				esc_html( $date ? $date->date_i18n( get_option( 'date_format' ) ) : '' ),
				esc_html( $o->get_payment_method_title() ),
				esc_html( wc_get_order_status_name( $o->get_status() ) )
			);
		}
		echo '</tbody></table>';
	}

	private function styles() {
		?>
		<style>
			.lsrt-list .lsrt-help { max-width: 60em; }
			.lsrt-list .subsubsub { float: none; margin: 1em 0 0; }
			.lsrt-list .lsrt-actions { margin: 1em 0; }
			.lsrt-list h2 { margin-top: 1.5em; }
			.lsrt-table { max-width: 70em; }
			.lsrt-table .lsrt-tick { width: 2em; text-align: center; }
			.lsrt-table .lsrt-num { font-weight: 600; white-space: nowrap; }
			.lsrt-box { display: inline-block; width: 1em; height: 1em; border: 1px solid #8c8f94; border-radius: 2px; vertical-align: middle; }
			.lsrt-table tr.lsrt-unpaid td { color: #996800; }
			.lsrt-table tr.lsrt-clash td, .lsrt-bad { color: #b32d2e; font-weight: 600; }
			@media print {
				#adminmenumain, #wpadminbar, #wpfooter, .notice, .update-nag, .lsrt-noprint, .lsrt-help { display: none !important; }
				#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
				.lsrt-table { max-width: none; }
				.lsrt-table a { color: inherit; text-decoration: none; }
				.lsrt-list h2 { break-before: page; }
				.lsrt-list h2:first-of-type { break-before: auto; }
			}
		</style>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * CSV
	 * ------------------------------------------------------------------ */

	public function csv() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-raffle-tickets' ), 403 );
		}
		check_admin_referer( self::CSV );

		$rows                  = $this->rows();
		$years                 = $this->index( $rows );
		list( $year, $raffle ) = $this->selection( $years );
		$clashes               = $this->clashes( $rows );
		$rows                  = $this->filter( $rows, $year, $raffle );

		$file = sanitize_file_name( 'raffle-tickets-' . $year . ( $raffle ? '-' . $years[ $year ][ $raffle ] : '' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM so Excel reads UTF-8.
		fputcsv( $out, array( 'Raffle', 'First number', 'Ticket numbers', 'Tickets', 'Buyer', 'Email', 'Phone', 'Order', 'Date', 'Paid by', 'Status', 'Amount', 'Sold twice' ) );
		foreach ( $rows as $r ) {
			$o    = $r['order'];
			$date = $o->get_date_paid() ? $o->get_date_paid() : $o->get_date_created();
			$dup  = isset( $clashes[ $r['product_id'] ] ) ? array_keys( array_intersect_key( $clashes[ $r['product_id'] ], array_flip( $r['numbers'] ) ) ) : array();
			fputcsv(
				$out,
				array_map(
					array( $this, 'csv_cell' ),
					array(
						$r['product'],
						$r['numbers'][0],
						$this->label( $r ),
						$r['size'],
						$this->buyer( $o ),
						$o->get_billing_email(),
						$o->get_billing_phone(),
						$o->get_order_number(),
						$date ? $date->date_i18n( 'Y-m-d H:i' ) : '',
						$o->get_payment_method_title(),
						wc_get_order_status_name( $o->get_status() ),
						wc_format_decimal( $r['amount'], wc_get_price_decimals() ),
						implode( ' ', $dup ),
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Stop a buyer's name being read as a spreadsheet formula. */
	public function csv_cell( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}
}
