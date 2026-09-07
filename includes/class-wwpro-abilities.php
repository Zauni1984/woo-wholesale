<?php
/**
 * WordPress Abilities API integration.
 *
 * Plugins that bridge WordPress to AI assistants - Easy MCP AI among them -
 * expose registered abilities as callable tools. Registering the wholesale
 * pricing operations here lets an assistant read and fill in B2B prices
 * without the site having to hand out generic database access.
 *
 * Everything is a no-op when the Abilities API is not available.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abilities (static).
 */
class WWPro_Abilities {

	/**
	 * Category slug. Uses the plugin slug so it cannot collide with other plugins.
	 */
	const CATEGORY = 'woo-wholesale';

	/**
	 * Register hooks. Safe to call when the Abilities API is missing.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Wholesale pricing', 'woo-wholesale' ),
				'description' => __( 'Read and maintain role-based wholesale prices of WooCommerce products.', 'woo-wholesale' ),
			)
		);
	}

	/**
	 * Whether the current user may use the abilities.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register the abilities.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$permission = array( __CLASS__, 'can_manage' );

		wp_register_ability(
			'woo-wholesale/list-roles',
			array(
				'label'               => __( 'List wholesale roles', 'woo-wholesale' ),
				'description'         => __( 'Lists the configured wholesale roles with their keys, store-wide discounts and price display settings. Use the returned role key for every other wholesale ability.', 'woo-wholesale' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $permission,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'roles' => array(
							'type'        => 'array',
							'description' => 'Configured wholesale roles.',
							'items'       => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'list_roles' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'woo-wholesale/get-product-prices',
			array(
				'label'               => __( 'Read wholesale prices of a product', 'woo-wholesale' ),
				'description'         => __( 'Returns the wholesale price of a product for every role, including prices that come from a category discount or the store-wide discount, plus the values stored on the product itself.', 'woo-wholesale' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'WooCommerce product or variation ID. Either product_id or sku is required.',
						),
						'sku'        => array(
							'type'        => 'string',
							'description' => 'Product SKU, used when no product_id is given.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'name'       => array( 'type' => 'string' ),
						'prices'     => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_product_prices' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'woo-wholesale/set-product-price',
			array(
				'label'               => __( 'Set the wholesale price of a product', 'woo-wholesale' ),
				'description'         => __( 'Stores a fixed wholesale price and/or a percentage discount for one role on a product or variation. A fixed price takes precedence over the percentage. Send an empty string to remove a value.', 'woo-wholesale' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'WooCommerce product or variation ID. Either product_id or sku is required.',
						),
						'sku'        => array(
							'type'        => 'string',
							'description' => 'Product SKU, used when no product_id is given.',
						),
						'role'       => array(
							'type'        => 'string',
							'description' => 'Wholesale role key, as returned by woo-wholesale/list-roles.',
						),
						'price'      => array(
							'type'        => array( 'string', 'number' ),
							'description' => 'Fixed wholesale price in shop currency, decimal point as separator. Empty string removes the fixed price.',
						),
						'discount'   => array(
							'type'        => array( 'string', 'number' ),
							'description' => 'Discount in percent, 0 to 100. Only used when no fixed price is set. Empty string removes the discount.',
						),
					),
					'required'             => array( 'role' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer' ),
						'role'       => array( 'type' => 'string' ),
						'prices'     => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'set_product_price' ),
				'meta'                => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'woo-wholesale/set-category-discount',
			array(
				'label'               => __( 'Set the wholesale discount of a product category', 'woo-wholesale' ),
				'description'         => __( 'Stores a percentage discount for one role on a product category. It applies to all products of that category and its sub-categories that have no wholesale price of their own. Send an empty string to remove it.', 'woo-wholesale' ),
				'category'            => self::CATEGORY,
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'category_id' => array(
							'type'        => 'integer',
							'description' => 'Product category term ID. Either category_id or slug is required.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Product category slug, used when no category_id is given.',
						),
						'role'        => array(
							'type'        => 'string',
							'description' => 'Wholesale role key, as returned by woo-wholesale/list-roles.',
						),
						'discount'    => array(
							'type'        => array( 'string', 'number' ),
							'description' => 'Discount in percent, 0 to 100. Empty string removes the discount.',
						),
					),
					'required'             => array( 'role', 'discount' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'category_id' => array( 'type' => 'integer' ),
						'role'        => array( 'type' => 'string' ),
						'discount'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'set_category_discount' ),
				'meta'                => array(
					'annotations' => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Ability: list roles.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_roles( $input = array() ) {
		$roles = array();

		foreach ( WWPro_Roles::all() as $key => $role ) {
			$roles[] = array(
				'role'            => $key,
				'name'            => $role['name'],
				'description'     => $role['description'],
				'global_discount' => $role['global_discount'],
				'secondary_price' => $role['secondary_price'],
				'users'           => WWPro_Roles::user_count( $key ),
			);
		}

		return array( 'roles' => $roles );
	}

	/**
	 * Ability: read prices.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_product_prices( $input = array() ) {
		$product = self::resolve_product( $input );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return array(
			'product_id' => $product->get_id(),
			'name'       => $product->get_name(),
			'prices'     => WWPro_Rest::price_report( $product ),
		);
	}

	/**
	 * Ability: set a price and/or discount.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function set_product_price( $input = array() ) {
		$product = self::resolve_product( $input );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : '';
		if ( ! WWPro_Roles::exists( $role ) ) {
			return new WP_Error(
				'wwpro_unknown_role',
				__( 'Unknown wholesale role. Use woo-wholesale/list-roles to get the valid role keys.', 'woo-wholesale' ),
				array( 'status' => 400 )
			);
		}

		$has_price    = array_key_exists( 'price', $input );
		$has_discount = array_key_exists( 'discount', $input );

		if ( ! $has_price && ! $has_discount ) {
			return new WP_Error(
				'wwpro_nothing_to_do',
				__( 'Please provide a price or a discount.', 'woo-wholesale' ),
				array( 'status' => 400 )
			);
		}

		WWPro_Pricing::apply_values(
			$product,
			$role,
			$has_price ? (string) $input['price'] : null,
			$has_discount ? (string) $input['discount'] : null
		);

		$product->save();
		WWPro_Pricing::flush_runtime_cache();

		return array(
			'product_id' => $product->get_id(),
			'role'       => $role,
			'prices'     => WWPro_Rest::price_report( wc_get_product( $product->get_id() ) ),
		);
	}

	/**
	 * Ability: set a category discount.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function set_category_discount( $input = array() ) {
		$term = null;

		if ( ! empty( $input['category_id'] ) ) {
			$term = get_term( (int) $input['category_id'], 'product_cat' );
		} elseif ( ! empty( $input['slug'] ) ) {
			$term = get_term_by( 'slug', sanitize_title( $input['slug'] ), 'product_cat' );
		}

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'wwpro_category_not_found',
				__( 'Product category not found.', 'woo-wholesale' ),
				array( 'status' => 404 )
			);
		}

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : '';
		if ( ! WWPro_Roles::exists( $role ) ) {
			return new WP_Error(
				'wwpro_unknown_role',
				__( 'Unknown wholesale role. Use woo-wholesale/list-roles to get the valid role keys.', 'woo-wholesale' ),
				array( 'status' => 400 )
			);
		}

		$discount = WWPro_Roles::sanitize_percent( isset( $input['discount'] ) ? $input['discount'] : '' );

		if ( '' === $discount ) {
			delete_term_meta( $term->term_id, WWPro_Pricing::discount_key( $role ) );
		} else {
			update_term_meta( $term->term_id, WWPro_Pricing::discount_key( $role ), $discount );
		}

		WWPro_Pricing::flush_runtime_cache();

		return array(
			'category_id' => $term->term_id,
			'role'        => $role,
			'discount'    => $discount,
		);
	}

	/**
	 * Find the product of an ability call.
	 *
	 * @param array $input Input.
	 * @return WC_Product|WP_Error
	 */
	private static function resolve_product( $input ) {
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;

		if ( ! $product_id && ! empty( $input['sku'] ) ) {
			$product_id = (int) wc_get_product_id_by_sku( wc_clean( (string) $input['sku'] ) );
		}

		$product = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'wwpro_product_not_found',
				__( 'Product not found. Provide a valid product_id or sku.', 'woo-wholesale' ),
				array( 'status' => 404 )
			);
		}

		return $product;
	}
}
