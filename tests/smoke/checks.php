<?php
/**
 * Smoke test checks, run with `wp eval-file checks.php <name>` by run.sh.
 *
 * @package Lumaha_Simple_Raffle_Tickets
 */

$lsrt_check = isset( $args[0] ) ? $args[0] : '';
$lsrt_fails = 0;

/**
 * Report one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label What was checked.
 */
function lsrt_check( $ok, $label ) {
	global $lsrt_fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$lsrt_fails++;
	}
}

$lsrt_file = 'lumaha-simple-raffle-tickets/lumaha-simple-raffle-tickets.php';

switch ( $lsrt_check ) {
	case 'github':
		lsrt_check( is_plugin_active( $lsrt_file ), 'plugin is active' );
		lsrt_check( class_exists( 'Lumaha_Simple_Raffle_Tickets' ), 'plugin class loaded' );
		lsrt_check( class_exists( 'LSRT_GitHub_Updater' ), 'GitHub updater loaded' );
		lsrt_check( (bool) has_filter( 'update_plugins_github.com' ), 'updater answers update checks for github.com' );

		// A fake newer release, so the check doesn't depend on GitHub.
		add_filter(
			'pre_http_request',
			static function ( $pre, $parsed_args, $url ) {
				if ( false === strpos( $url, 'api.github.com/repos/f3zz3h/lumaha-simple-raffle-tickets/releases/latest' ) ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'tag_name'     => 'v99.0.0',
							'html_url'     => 'https://github.com/f3zz3h/lumaha-simple-raffle-tickets/releases/tag/v99.0.0',
							'body'         => 'Test release',
							'published_at' => '2026-01-01T00:00:00Z',
							'draft'        => false,
							'prerelease'   => false,
							'assets'       => array(
								array(
									'name'                 => 'lumaha-simple-raffle-tickets-99.0.0.zip',
									'browser_download_url' => 'https://example.com/lumaha-simple-raffle-tickets-99.0.0.zip',
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		LSRT_GitHub_Updater::forget();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$lsrt_updates = get_site_transient( 'update_plugins' );
		$lsrt_offer   = isset( $lsrt_updates->response[ $lsrt_file ] ) ? $lsrt_updates->response[ $lsrt_file ] : null;
		lsrt_check( $lsrt_offer && '99.0.0' === $lsrt_offer->new_version, 'a newer GitHub release is offered as an update' );
		lsrt_check( $lsrt_offer && 'https://example.com/lumaha-simple-raffle-tickets-99.0.0.zip' === $lsrt_offer->package, 'the update installs the release zip' );

		$lsrt_info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'lumaha-simple-raffle-tickets' ) );
		lsrt_check( is_object( $lsrt_info ) && '99.0.0' === $lsrt_info->version, '"View details" shows the release' );
		LSRT_GitHub_Updater::forget();
		break;

	case 'wporg':
		lsrt_check( is_plugin_active( $lsrt_file ), 'plugin is active' );
		lsrt_check( class_exists( 'Lumaha_Simple_Raffle_Tickets' ), 'plugin class loaded' );
		lsrt_check( ! class_exists( 'LSRT_GitHub_Updater' ), 'no GitHub updater in the WordPress.org build' );
		$lsrt_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $lsrt_file, false, false );
		lsrt_check( empty( $lsrt_data['UpdateURI'] ), 'no Update URI in the WordPress.org build' );
		break;

	case 'list':
		// WooCommerce → Raffle tickets shows a paid order's numbers in order, grouped by year and raffle.
		$lsrt_cat = wp_insert_term( 'Raffle tickets', 'product_cat', array( 'slug' => 'raffle-tickets' ) );
		$lsrt_cat = is_wp_error( $lsrt_cat ) ? get_term_by( 'slug', 'raffle-tickets', 'product_cat' )->term_id : $lsrt_cat['term_id'];
		$lsrt_p   = new WC_Product_Simple();
		$lsrt_p->set_name( 'Smoke Test Raffle' );
		$lsrt_p->set_regular_price( '1' );
		$lsrt_p->set_category_ids( array( $lsrt_cat ) );
		$lsrt_p->save();

		$lsrt_order = wc_create_order();
		$lsrt_item  = new WC_Order_Item_Product();
		$lsrt_item->set_product( $lsrt_p );
		$lsrt_item->set_quantity( 2 );
		$lsrt_item->set_total( '2' );
		$lsrt_item->add_meta_data( 'Ticket numbers', '207, 31', true );
		$lsrt_item->add_meta_data( '_lsrt_numbers', '207,31', true );
		$lsrt_order->add_item( $lsrt_item );
		// A line from before the plugin, numbers typed into an old "Ticket Numbers" field.
		$lsrt_old = new WC_Order_Item_Product();
		$lsrt_old->set_product( $lsrt_p );
		$lsrt_old->set_quantity( 2 );
		$lsrt_old->set_total( '6' );
		$lsrt_old->add_meta_data( 'Ticket Numbers', '00036, 01866', true );
		$lsrt_order->add_item( $lsrt_old );
		$lsrt_odd = new WC_Order_Item_Product();
		$lsrt_odd->set_product( $lsrt_p );
		$lsrt_odd->set_quantity( 1 );
		$lsrt_odd->set_total( '1' );
		$lsrt_odd->add_meta_data( 'Ticket Numbers', '5, 6, 7', true );
		$lsrt_order->add_item( $lsrt_odd );
		$lsrt_order->set_billing_first_name( 'Smoke' );
		$lsrt_order->set_billing_last_name( 'Tester' );
		$lsrt_order->calculate_totals();
		$lsrt_order->set_status( 'processing' );
		$lsrt_order->save();

		wp_set_current_user( 1 );
		set_current_screen( 'dashboard' );
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- no admin menu outside wp-admin.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		do_action( 'admin_menu' );
		$lsrt_menu = wp_list_pluck( isset( $submenu['woocommerce'] ) ? $submenu['woocommerce'] : array(), 2 );
		lsrt_check( in_array( 'lsrt-tickets', $lsrt_menu, true ), 'WooCommerce → Raffle tickets menu is there' );

		lsrt_check( class_exists( 'LSRT_Ticket_List' ), 'ticket list class loaded' );
		$lsrt_list = new LSRT_Ticket_List( new Lumaha_Simple_Raffle_Tickets() );
		ob_start();
		$lsrt_list->render();
		$lsrt_html = ob_get_clean();
		lsrt_check( false !== strpos( $lsrt_html, 'Smoke Test Raffle' ), 'list shows the raffle' );
		lsrt_check( false !== strpos( $lsrt_html, 'Smoke Tester' ), 'list shows the buyer' );
		$lsrt_31  = strpos( $lsrt_html, '>31<' );
		$lsrt_207 = strpos( $lsrt_html, '>207<' );
		lsrt_check( false !== $lsrt_31 && false !== $lsrt_207 && $lsrt_31 < $lsrt_207, 'tickets are in number order' );
		lsrt_check( false !== strpos( $lsrt_html, gmdate( 'Y' ) ), 'list has a tab for this year' );
		lsrt_check( 1 === substr_count( $lsrt_html, '>31<' ), 'a line is listed once, not once per numbers field' );
		lsrt_check( false !== strpos( $lsrt_html, '>36<' ) && false !== strpos( $lsrt_html, '>1866<' ), 'old "Ticket Numbers" lines are listed' );
		lsrt_check( 3 === substr_count( $lsrt_html, 'Check order' ) && false !== strpos( $lsrt_html, 'need checking' ), 'an old line that doesn\'t match its quantity is flagged' );

		$lsrt_order->set_status( 'cancelled' );
		$lsrt_order->save();
		ob_start();
		$lsrt_list->render();
		lsrt_check( false === strpos( ob_get_clean(), 'Smoke Tester' ), 'cancelled orders are left off' );
		break;

	default:
		lsrt_check( false, "unknown check '$lsrt_check'" );
}

if ( $lsrt_fails ) {
	WP_CLI::error( "$lsrt_fails check(s) failed" );
}
