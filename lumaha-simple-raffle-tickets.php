<?php
/**
 * Plugin Name:       Lumaha Simple Raffle Tickets
 * Plugin URI:        https://github.com/f3zz3h/lumaha-simple-raffle-tickets
 * Description:       Asks buyers for their raffle ticket numbers. Books auto-fill from the first number, singles take one number each, and the numbers are saved on the order.
 * Version:           0.3.0
 * Author:            Lumaha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       lumaha-simple-raffle-tickets
 * Update URI:        https://github.com/f3zz3h/lumaha-simple-raffle-tickets
 *
 * Which products: any product in the "Raffle tickets" category (slug raffle-tickets)
 * or any sub-category of it, or any product tagged "raffle". Duplicating a product
 * for next year keeps its category, so it keeps working with no setup.
 *
 * Book size: set "Tickets per book" on each variation. If left blank it is read from
 * the variation name ("Book of 5", "5 tickets"); plain "Book" means 5, anything else 1.
 */

defined( 'ABSPATH' ) || exit;

define( 'LSRT_PLUGIN_FILE', __FILE__ );

// lsrt-github-updater:start (the WordPress.org build removes this block and the file; it updates from WordPress.org).
// Updates from GitHub releases (starts itself), for copies installed from the GitHub release zip.
if ( file_exists( __DIR__ . '/includes/class-lsrt-github-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-lsrt-github-updater.php';
}
// lsrt-github-updater:end

final class Lumaha_Simple_Raffle_Tickets {

	const VERSION        = '0.3.0';
	const META_BOOK_SIZE = '_lsrt_book_size';
	const META_SOLD      = '_lsrt_sold';      // product meta: number => order id
	const META_NUMBERS   = '_lsrt_numbers';   // order item meta: every number, comma separated
	const META_RECORDED  = '_lsrt_recorded';  // order meta flag
	const FIELD          = 'lsrt_start';

	/** Set when an add to cart succeeds on this request. */
	private $added = false;

	public static function init() {
		$self = new self();

		// Admin: tickets-per-book field on variations.
		add_action( 'woocommerce_product_after_variable_attributes', array( $self, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $self, 'save_variation_field' ), 10, 2 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $self, 'exclude_meta_on_duplicate' ) );

		// Product page.
		add_action( 'woocommerce_before_add_to_cart_button', array( $self, 'render_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $self, 'enqueue' ) );

		// Shop/category pages: send simple raffle products to the product page.
		add_filter( 'woocommerce_product_add_to_cart_url', array( $self, 'archive_url' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $self, 'archive_text' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $self, 'no_ajax_add' ), 10, 3 );

		// Cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( $self, 'validate' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $self, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_add_to_cart', array( $self, 'mark_added' ) );
		add_filter( 'woocommerce_get_item_data', array( $self, 'show_in_cart' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $self, 'lock_quantity_classic' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $self, 'lock_quantity_blocks' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( $self, 'check_cart' ) );

		// Order.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $self, 'save_to_order_item' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( $self, 'record_sold' ) );
		add_action( 'woocommerce_order_status_completed', array( $self, 'record_sold' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $self, 'release_sold' ) );
		add_action( 'woocommerce_order_status_refunded', array( $self, 'release_sold' ) );
		add_action( 'woocommerce_order_status_failed', array( $self, 'release_sold' ) );

	}

	/* ---------------------------------------------------------------------
	 * Which products are raffles, and how many tickets per unit
	 * ------------------------------------------------------------------ */

	private function category_slugs() {
		return (array) apply_filters( 'lsrt_category_slugs', array( 'raffle-tickets', 'raffle' ) );
	}

	private function tag_slugs() {
		return (array) apply_filters( 'lsrt_tag_slugs', array( 'raffle' ) );
	}

	public function is_raffle( $product ) {
		$product = wc_get_product( $product );
		if ( ! $product ) {
			return false;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		$cat_ids = array();
		foreach ( $this->category_slugs() as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term ) {
				$cat_ids[] = (int) $term->term_id;
				$cat_ids   = array_merge( $cat_ids, array_map( 'intval', get_term_children( $term->term_id, 'product_cat' ) ) );
			}
		}
		$is = ( $cat_ids && has_term( $cat_ids, 'product_cat', $id ) ) || has_term( $this->tag_slugs(), 'product_tag', $id );

		return (bool) apply_filters( 'lsrt_is_raffle_product', $is, $product );
	}

	/** Tickets per unit bought. 1 = single ticket. */
	public function book_size( $product ) {
		$product = wc_get_product( $product );
		if ( ! $product ) {
			return 1;
		}
		$set = (int) $product->get_meta( self::META_BOOK_SIZE );
		if ( $set > 0 ) {
			return $set;
		}
		if ( $product->is_type( 'variation' ) ) {
			// Attribute values only (not the product name, which may contain a year).
			$text = strtolower( implode( ' ', array_values( $product->get_variation_attributes() ) ) );
			if ( preg_match( '/book[\s\-]*(?:of[\s\-]*)?(\d+)|(\d+)[\s\-]*(?:x[\s\-]*)?tickets?/', $text, $m ) ) {
				$n = (int) ( $m[1] ? $m[1] : $m[2] );
				if ( $n > 0 ) {
					return $n;
				}
			}
			// Plain "Book" with no number: use the standard book size.
			if ( preg_match( '/\bbook\b/', $text ) ) {
				return (int) apply_filters( 'lsrt_default_book_size', 5, $product );
			}
		}
		return 1;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public function variation_field( $loop, $variation_data, $variation ) {
		if ( ! $this->is_raffle( $variation->post_parent ) ) {
			return;
		}
		woocommerce_wp_text_input( array(
			'id'            => self::META_BOOK_SIZE . "[{$loop}]",
			'name'          => self::META_BOOK_SIZE . "[{$loop}]",
			'label'         => __( 'Raffle: tickets per book (1 = single ticket)', 'lumaha-simple-raffle-tickets' ),
			'desc_tip'      => true,
			'description'   => __( 'Leave blank to read it from the variation name, e.g. "Book of 5". Plain "Book" counts as 5. Anything else counts as a single ticket.', 'lumaha-simple-raffle-tickets' ),
			'type'          => 'number',
			'value'         => get_post_meta( $variation->ID, self::META_BOOK_SIZE, true ),
			'wrapper_class' => 'form-row form-row-full',
			'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
		) );
	}

	public function save_variation_field( $variation_id, $loop ) {
		if ( ! isset( $_POST[ self::META_BOOK_SIZE ][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the nonce.
			return;
		}
		$val = absint( wp_unslash( $_POST[ self::META_BOOK_SIZE ][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $val > 0 ) {
			update_post_meta( $variation_id, self::META_BOOK_SIZE, $val );
		} else {
			delete_post_meta( $variation_id, self::META_BOOK_SIZE );
		}
	}

	/** A duplicated product (next year's raffle) starts with no sold numbers. */
	public function exclude_meta_on_duplicate( $keys ) {
		$keys[] = self::META_SOLD;
		return $keys;
	}

	/* ---------------------------------------------------------------------
	 * Product page
	 * ------------------------------------------------------------------ */

	public function render_fields() {
		global $product;
		if ( ! $product || ! $this->is_raffle( $product ) ) {
			return;
		}
		$sizes = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$sizes[ $child_id ] = $this->book_size( $child_id );
			}
			$default = 0; // wait until a variation is chosen
		} else {
			$default = $this->book_size( $product );
		}
		// The product template has no WooCommerce notices area, so show any errors here.
		$notices = '';
		// After a rejected add, put the buyer's numbers back in the boxes.
		$old = $this->rejected_starts( $product );
		if ( function_exists( 'wc_print_notices' ) && wc_notice_count() > 0 ) {
			$notices = wc_print_notices( true );
		}
		printf(
			'<div class="lsrt" data-sizes="%s" data-default-size="%d" data-old="%s">%s<p class="lsrt-intro">%s</p><div class="lsrt-rows"></div></div>',
			esc_attr( wp_json_encode( (object) $sizes ) ),
			(int) $default,
			esc_attr( wp_json_encode( array_values( $old ) ) ),
			$notices, // phpcs:ignore WordPress.Security.EscapeOutput -- WooCommerce notice HTML.
			esc_html__( 'Enter your ticket numbers. For a book, enter the first number on the book and the rest fill in.', 'lumaha-simple-raffle-tickets' )
		);
	}

	/**
	 * The numbers the buyer just submitted for this product, if the add to cart was turned down.
	 *
	 * Don't rely on error notices here: most themes print (and clear) them above the
	 * product before these boxes are drawn.
	 */
	private function rejected_starts( $product ) {
		if ( $this->added || empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- add to cart has no nonce.
			return array();
		}
		if ( absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) !== (int) $product->get_id() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}
		return $this->posted_starts();
	}

	public function mark_added() {
		$this->added = true;
	}

	public function enqueue() {
		if ( ! is_product() ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'lumaha-simple-raffle-tickets', $url . 'raffle.css', array(), self::VERSION );
		wp_enqueue_script( 'lumaha-simple-raffle-tickets', $url . 'raffle.js', array( 'jquery' ), self::VERSION, true );
	}

	public function archive_url( $url, $product ) {
		if ( ! is_product() && $product->is_type( 'simple' ) && $this->is_raffle( $product ) ) {
			return $product->get_permalink();
		}
		return $url;
	}

	public function archive_text( $text, $product ) {
		if ( ! is_product() && $product->is_type( 'simple' ) && $this->is_raffle( $product ) ) {
			return __( 'Enter ticket numbers', 'lumaha-simple-raffle-tickets' );
		}
		return $text;
	}

	public function no_ajax_add( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $this->is_raffle( $product ) ) {
			return false;
		}
		return $supports;
	}

	/* ---------------------------------------------------------------------
	 * Cart
	 * ------------------------------------------------------------------ */

	private function posted_starts() {
		if ( empty( $_POST[ self::FIELD ] ) || ! is_array( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- add to cart has no nonce.
			return array();
		}
		return array_map( 'trim', array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Every ticket number covered by a list of start numbers. */
	private function expand( array $starts, $size ) {
		$all = array();
		foreach ( $starts as $s ) {
			for ( $i = 0; $i < $size; $i++ ) {
				$all[] = (int) $s + $i;
			}
		}
		return $all;
	}

	public function validate( $passed, $product_id, $qty, $variation_id = 0 ) {
		$target = $variation_id ? $variation_id : $product_id;
		if ( ! $passed || ! $this->is_raffle( $target ) ) {
			return $passed;
		}
		$size   = $this->book_size( $target );
		$starts = $this->posted_starts();
		$noun   = $size > 1 ? __( 'the first ticket number for each book', 'lumaha-simple-raffle-tickets' ) : __( 'a number for each ticket', 'lumaha-simple-raffle-tickets' );

		if ( count( $starts ) !== (int) $qty ) {
			wc_add_notice( sprintf( __( 'Please enter %s.', 'lumaha-simple-raffle-tickets' ), $noun ), 'error' );
			return false;
		}
		foreach ( $starts as $s ) {
			if ( ! ctype_digit( $s ) || (int) $s < 1 ) {
				wc_add_notice( sprintf( __( '"%s" is not a valid ticket number. Please enter whole numbers only.', 'lumaha-simple-raffle-tickets' ), esc_html( $s ) ), 'error' );
				return false;
			}
		}

		// No ticket twice, within this add or against what's already in the basket.
		$new  = $this->expand( $starts, $size );
		$have = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['lsrt'] ) && (int) $item['product_id'] === (int) $product_id ) {
					$have = array_merge( $have, $this->expand( $item['lsrt']['starts'], $item['lsrt']['size'] ) );
				}
			}
		}
		$dupes = array_unique( array_merge(
			array_keys( array_filter( array_count_values( $new ), function ( $c ) { return $c > 1; } ) ),
			array_intersect( $new, $have )
		) );
		if ( $dupes ) {
			sort( $dupes );
			wc_add_notice( sprintf( __( 'Ticket number(s) %s are entered more than once. Please check your numbers.', 'lumaha-simple-raffle-tickets' ), implode( ', ', $dupes ) ), 'error' );
			return false;
		}
		return true;
	}

	public function add_cart_item_data( $data, $product_id, $variation_id ) {
		$target = $variation_id ? $variation_id : $product_id;
		if ( ! $this->is_raffle( $target ) ) {
			return $data;
		}
		$starts = array_map( 'intval', $this->posted_starts() );
		if ( $starts ) {
			$data['lsrt'] = array(
				'size'   => $this->book_size( $target ),
				'starts' => $starts,
			);
		}
		return $data;
	}

	public function format( array $raffle ) {
		$size  = (int) $raffle['size'];
		$parts = array();
		foreach ( $raffle['starts'] as $s ) {
			$parts[] = $size > 1 ? sprintf( '%d–%d', $s, $s + $size - 1 ) : (string) $s;
		}
		return implode( ', ', $parts );
	}

	public function show_in_cart( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['lsrt'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Ticket numbers', 'lumaha-simple-raffle-tickets' ),
				'value' => $this->format( $cart_item['lsrt'] ),
			);
		}
		return $item_data;
	}

	/** Numbers are tied to the quantity, so the basket can't change it. Remove and re-add instead. */
	public function lock_quantity_classic( $html, $cart_item_key, $cart_item ) {
		if ( ! empty( $cart_item['lsrt'] ) ) {
			return sprintf( '%d <input type="hidden" name="cart[%s][qty]" value="%d" />', $cart_item['quantity'], esc_attr( $cart_item_key ), $cart_item['quantity'] );
		}
		return $html;
	}

	public function lock_quantity_blocks( $editable, $product, $cart_item = null ) {
		if ( is_array( $cart_item ) && ! empty( $cart_item['lsrt'] ) ) {
			return false;
		}
		return $editable;
	}

	/** Safety net at checkout: numbers must match the quantity. */
	public function check_cart() {
		foreach ( WC()->cart->get_cart() as $item ) {
			$target = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			if ( ! $this->is_raffle( $target ) ) {
				continue;
			}
			$count = empty( $item['lsrt'] ) ? 0 : count( $item['lsrt']['starts'] );
			if ( $count !== (int) $item['quantity'] ) {
				wc_add_notice( sprintf( __( 'The ticket numbers for "%s" don\'t match the quantity. Please remove it from your basket and add it again.', 'lumaha-simple-raffle-tickets' ), $item['data']->get_name() ), 'error' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Order
	 * ------------------------------------------------------------------ */

	public function save_to_order_item( $item, $cart_item_key, $values ) {
		if ( empty( $values['lsrt'] ) ) {
			return;
		}
		$item->add_meta_data( __( 'Ticket numbers', 'lumaha-simple-raffle-tickets' ), $this->format( $values['lsrt'] ), true );
		$item->add_meta_data( self::META_NUMBERS, implode( ',', $this->expand( $values['lsrt']['starts'], $values['lsrt']['size'] ) ), true );
	}

	/** Keep a list of paid-for numbers per product, and flag clashes on the order for the committee. */
	public function record_sold( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_RECORDED ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$nums = $item->get_meta( self::META_NUMBERS );
			if ( '' === $nums || null === $nums ) {
				continue;
			}
			$pid   = $item->get_product_id();
			$sold  = get_post_meta( $pid, self::META_SOLD, true );
			$sold  = is_array( $sold ) ? $sold : array();
			$clash = array();
			foreach ( array_map( 'intval', explode( ',', $nums ) ) as $n ) {
				if ( isset( $sold[ $n ] ) && (int) $sold[ $n ] !== (int) $order_id ) {
					$clash[ $n ] = $sold[ $n ];
				} else {
					$sold[ $n ] = (int) $order_id;
				}
			}
			update_post_meta( $pid, self::META_SOLD, $sold );
			if ( $clash ) {
				$bits = array();
				foreach ( $clash as $n => $other ) {
					$bits[] = sprintf( '%d (order #%d)', $n, $other );
				}
				$order->add_order_note( sprintf( 'Raffle: these ticket numbers were already paid for on another order: %s. Please check with the buyer.', implode( ', ', $bits ) ) );
			}
		}
		$order->update_meta_data( self::META_RECORDED, 1 );
		$order->save();
	}

	public function release_sold( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( self::META_RECORDED ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$pid  = $item->get_product_id();
			$sold = get_post_meta( $pid, self::META_SOLD, true );
			$sold  = is_array( $sold ) ? $sold : array();
			$sold = array_filter( $sold, function ( $oid ) use ( $order_id ) {
				return (int) $oid !== (int) $order_id;
			} );
			update_post_meta( $pid, self::META_SOLD, $sold );
		}
		$order->delete_meta_data( self::META_RECORDED );
		$order->save();
	}
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		Lumaha_Simple_Raffle_Tickets::init();
	}
} );
