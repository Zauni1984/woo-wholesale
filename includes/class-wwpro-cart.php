<?php
/**
 * Cart and checkout integration.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart hooks (static).
 */
class WWPro_Cart {

	const ORDER_META_ROLE = '_wwpro_role';
	const ORDER_META_NAME = '_wwpro_role_name';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_tier_prices' ), 20 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 99, 3 );
		add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'coupons_enabled' ), 99 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_min_order_amount' ) );

		// Classic checkout and block checkout.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_order_meta' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'store_order_meta' ), 10, 1 );
	}

	/**
	 * Apply tiered prices to cart line items.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_tier_prices( $cart ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role || ! $cart instanceof WC_Cart ) {
			return;
		}

		$basis = WWPro_Settings::get( 'tier_qty_basis' );
		$sums  = array();

		if ( 'product' === $basis ) {
			foreach ( $cart->get_cart() as $item ) {
				$pid          = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				$sums[ $pid ] = ( isset( $sums[ $pid ] ) ? $sums[ $pid ] : 0 ) + (int) $item['quantity'];
			}
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$product = $item['data'];
			$qty     = 'product' === $basis ? $sums[ (int) $item['product_id'] ] : (int) $item['quantity'];

			/**
			 * Filter the quantity used for tier matching of a cart line.
			 *
			 * @param int   $qty  Quantity.
			 * @param array $item Cart item.
			 */
			$qty = (int) apply_filters( 'wwpro_tier_quantity', $qty, $item );

			$price = WWPro_Pricing::tier_price( $product, $role, $qty );

			if ( null === $price ) {
				WWPro_Pricing::clear_runtime_price( $product );
				continue;
			}

			WWPro_Pricing::set_runtime_price( $product, $price );
			$product->set_price( wc_format_decimal( $price ) );
		}
	}

	/**
	 * Append the secondary price to the cart line price.
	 *
	 * @param string $html      Price HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart item key.
	 * @return string
	 */
	public static function cart_item_price( $html, $cart_item, $key ) {
		if ( ! WWPro_Settings::is( 'secondary_price_in_cart' ) || empty( $cart_item['data'] ) ) {
			return $html;
		}
		return $html . WWPro_Frontend::secondary_price_html( $cart_item['data'], 'cart' );
	}

	/**
	 * Disable coupons for roles configured accordingly.
	 *
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public static function coupons_enabled( $enabled ) {
		if ( ! $enabled ) {
			return $enabled;
		}
		$role = WWPro_Pricing::current_role();
		if ( null === $role ) {
			return $enabled;
		}
		$config = WWPro_Roles::get( $role );
		return ( $config && 'yes' === $config['disable_coupons'] ) ? false : $enabled;
	}

	/**
	 * Enforce the minimum order subtotal of the role.
	 */
	public static function check_min_order_amount() {
		$role = WWPro_Pricing::current_role();
		if ( null === $role || ! WC()->cart ) {
			return;
		}

		$config = WWPro_Roles::get( $role );
		if ( ! $config || '' === $config['min_order_amount'] ) {
			return;
		}

		$min      = (float) $config['min_order_amount'];
		$subtotal = (float) WC()->cart->get_subtotal();

		if ( $subtotal + 0.00001 < $min ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: minimum amount, 2: current subtotal */
					__( 'The minimum order amount for your account is %1$s (net). Your current subtotal is %2$s.', 'woo-wholesale' ),
					wp_strip_all_tags( wc_price( $min ) ),
					wp_strip_all_tags( wc_price( $subtotal ) )
				),
				'error'
			);
		}
	}

	/**
	 * Store the wholesale role on the order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function store_order_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$role = WWPro_Pricing::current_role();
		if ( null === $role ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META_ROLE, $role );
		$order->update_meta_data( self::ORDER_META_NAME, WWPro_Roles::label( $role ) );
	}
}
