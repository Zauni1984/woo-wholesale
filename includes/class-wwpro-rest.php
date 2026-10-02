<?php
/**
 * REST integration.
 *
 * Wholesale prices live in protected post meta ("_wwpro_*"), which WordPress
 * hides from the REST API unless the meta is registered with show_in_rest and
 * an auth callback. Registering them here is what makes the prices reachable
 * for REST clients and MCP connectors such as Easy MCP AI, so an assistant can
 * read and fill in B2B prices.
 *
 * Writes that arrive through REST bypass the admin screens, so the price caches
 * are invalidated from the meta hooks instead of the save handlers.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST helper (static).
 */
class WWPro_Rest {

	/**
	 * Post types that carry wholesale meta.
	 */
	const POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Whether a cache flush is already scheduled for this request.
	 *
	 * @var bool
	 */
	private static $flush_scheduled = false;

	/**
	 * Products whose transients need clearing at the end of the request.
	 *
	 * @var int[]
	 */
	private static $dirty_products = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_fields' ) );

		// Keep prices correct when meta is written outside of the admin screens.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'post_meta_changed' ), 10, 3 );
		}
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'term_meta_changed' ), 10, 3 );
		}
	}

	/**
	 * Whether a meta key belongs to this plugin.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_own_key( $meta_key ) {
		return is_string( $meta_key ) && 0 === strpos( $meta_key, '_wwpro_' );
	}

	/**
	 * Expose the per-role price and discount meta to the REST API.
	 */
	public static function register_meta() {
		foreach ( WWPro_Roles::all() as $key => $role ) {
			foreach ( self::POST_TYPES as $post_type ) {
				register_post_meta(
					$post_type,
					WWPro_Pricing::price_key( $key ),
					array(
						'single'            => true,
						'type'              => 'string',
						'description'       => sprintf(
							/* translators: %s: wholesale role name */
							__( 'Fixed wholesale price for the role "%s". Empty means no fixed price.', 'woo-wholesale' ),
							$role['name']
						),
						'show_in_rest'      => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_price' ),
						'auth_callback'     => array( __CLASS__, 'can_edit_post_meta' ),
					)
				);

				register_post_meta(
					$post_type,
					WWPro_Pricing::discount_key( $key ),
					array(
						'single'            => true,
						'type'              => 'string',
						'description'       => sprintf(
							/* translators: %s: wholesale role name */
							__( 'Wholesale discount in percent (0-100) for the role "%s".', 'woo-wholesale' ),
							$role['name']
						),
						'show_in_rest'      => true,
						'sanitize_callback' => array( 'WWPro_Roles', 'sanitize_percent' ),
						'auth_callback'     => array( __CLASS__, 'can_edit_post_meta' ),
					)
				);
			}

			register_term_meta(
				'product_cat',
				WWPro_Pricing::discount_key( $key ),
				array(
					'single'            => true,
					'type'              => 'string',
					'description'       => sprintf(
						/* translators: %s: wholesale role name */
						__( 'Category discount in percent (0-100) for the role "%s".', 'woo-wholesale' ),
						$role['name']
					),
					'show_in_rest'      => true,
					'sanitize_callback' => array( 'WWPro_Roles', 'sanitize_percent' ),
					'auth_callback'     => array( __CLASS__, 'can_edit_term_meta' ),
				)
			);
		}
	}

	/**
	 * Sanitize a fixed price coming from REST.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_price( $value ) {
		$value = WWPro_Roles::to_decimal( $value );
		return ( '' !== $value && is_numeric( $value ) && (float) $value > 0 ) ? (string) (float) $value : '';
	}

	/**
	 * Whether the user may read and write wholesale meta of a product.
	 *
	 * @param bool   $allowed   Current decision.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public static function can_edit_post_meta( $allowed, $meta_key, $object_id, $user_id ) {
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}

		$post = get_post( $object_id );
		if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
			$object_id = $post->post_parent;
		}

		return user_can( $user_id, 'edit_post', $object_id );
	}

	/**
	 * Whether the user may read and write wholesale meta of a product category.
	 *
	 * @param bool   $allowed   Current decision.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Term ID.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public static function can_edit_term_meta( $allowed, $meta_key, $object_id, $user_id ) {
		return user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_product_terms' );
	}

	/**
	 * Add a read-only summary of the effective wholesale prices to products.
	 */
	public static function register_fields() {
		foreach ( self::POST_TYPES as $post_type ) {
			register_rest_field(
				$post_type,
				'wwpro_wholesale_prices',
				array(
					'get_callback' => array( __CLASS__, 'rest_prices' ),
					'schema'       => array(
						'description' => __( 'Effective wholesale price per role, including prices inherited from a category or the store-wide discount.', 'woo-wholesale' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}

	/**
	 * Effective prices of a product for every configured role.
	 *
	 * @param array $object REST response object.
	 * @return array
	 */
	public static function rest_prices( $object ) {
		$product_id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		return self::price_report( $product );
	}

	/**
	 * Per-role price report of a product, shared by REST and the abilities.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function price_report( $product ) {
		$out = array();

		if ( ! $product instanceof WC_Product ) {
			return $out;
		}

		foreach ( WWPro_Roles::all() as $key => $role ) {
			$resolved = WWPro_Pricing::resolve( $product, $key );
			$tiers    = WWPro_Tiers::sanitize( $product->get_meta( WWPro_Tiers::meta_key( $key ), true, 'edit' ) );

			$out[ $key ] = array(
				'role'          => $key,
				'role_name'     => $role['name'],
				'price'         => $resolved ? wc_format_decimal( $resolved['price'], wc_get_price_decimals() ) : '',
				'source'        => $resolved ? $resolved['source'] : 'none',
				'own_price'     => (string) $product->get_meta( WWPro_Pricing::price_key( $key ), true, 'edit' ),
				'own_discount'  => (string) $product->get_meta( WWPro_Pricing::discount_key( $key ), true, 'edit' ),
				'regular_price' => (string) $product->get_regular_price( 'edit' ),
				'tiers_enabled' => WWPro_Tiers::is_active_set( $tiers ),
			);
		}

		return $out;
	}

	/**
	 * Post meta of this plugin changed.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function post_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( ! self::is_own_key( $meta_key ) ) {
			return;
		}
		self::$dirty_products[ (int) $object_id ] = true;
		self::schedule_flush();
	}

	/**
	 * Term meta of this plugin changed.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Term ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function term_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( ! self::is_own_key( $meta_key ) ) {
			return;
		}
		self::schedule_flush();
	}

	/**
	 * Flush once at the end of the request instead of on every single write,
	 * so bulk edits and imports stay cheap.
	 */
	private static function schedule_flush() {
		if ( self::$flush_scheduled ) {
			return;
		}
		self::$flush_scheduled = true;
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 20 );
	}

	/**
	 * Invalidate the wholesale price caches.
	 */
	public static function flush() {
		WWPro_Settings::bump_cache_version( false );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			foreach ( array_keys( self::$dirty_products ) as $product_id ) {
				wc_delete_product_transients( $product_id );
			}
		}

		self::$dirty_products  = array();
		self::$flush_scheduled = false;
	}
}
