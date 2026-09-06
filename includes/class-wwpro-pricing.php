<?php
/**
 * Price resolution engine.
 *
 * Precedence for a role:
 *   1. Fixed price on the variation / product
 *   2. Percentage discount on the variation / product
 *   3. Fixed price or percentage discount on the parent product (variations only)
 *   4. Percentage discount on a product category (or one of its parents)
 *   5. Store-wide percentage discount of the role
 *
 * Tiered quantity discounts are layered on top of the resulting unit price.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pricing helper (static).
 */
class WWPro_Pricing {

	const META_PRICE    = '_wwpro_price_';
	const META_DISCOUNT = '_wwpro_discount_';

	/**
	 * Resolution cache: "product_id|role" => array|null.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Runtime prices for cart line items (tier prices): object id => price.
	 *
	 * @var array
	 */
	private static $runtime = array();

	/**
	 * Meta key of the fixed price for a role.
	 *
	 * @param string $role Role key.
	 * @return string
	 */
	public static function price_key( $role ) {
		return self::META_PRICE . sanitize_key( $role );
	}

	/**
	 * Meta key of the percentage discount for a role.
	 *
	 * @param string $role Role key.
	 * @return string
	 */
	public static function discount_key( $role ) {
		return self::META_DISCOUNT . sanitize_key( $role );
	}

	/**
	 * Whether wholesale pricing is active for the current request/user.
	 *
	 * Never active in the classic admin (product editing, manual orders),
	 * but active for AJAX, REST (Store API) and the frontend.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return null !== self::current_role();
	}

	/**
	 * Wholesale role of the current user, or null.
	 *
	 * @return string|null
	 */
	public static function current_role() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return null;
		}

		if ( ! is_user_logged_in() ) {
			return null;
		}

		$role = WWPro_Roles::get_user_role();

		/**
		 * Filter the active wholesale role for the current request.
		 *
		 * @param string|null $role Role key or null.
		 */
		return apply_filters( 'wwpro_current_role', $role );
	}

	/**
	 * Product categories of a product (parent product for variations).
	 *
	 * @param WC_Product $product Product.
	 * @return WP_Term[]
	 */
	public static function categories_of( $product ) {
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		if ( ! $id ) {
			return array();
		}
		$terms = get_the_terms( $id, 'product_cat' );
		return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Base price that percentage discounts are calculated from (raw, edit context).
	 *
	 * @param WC_Product $product Product.
	 * @return string '' when the product has no price.
	 */
	public static function get_base_price( $product ) {
		if ( 'current' === WWPro_Settings::get( 'discount_base' ) ) {
			$base = $product->get_price( 'edit' );
		} else {
			$base = $product->get_regular_price( 'edit' );
			if ( '' === $base || null === $base ) {
				$base = $product->get_price( 'edit' );
			}
		}
		return ( '' === $base || null === $base || ! is_numeric( $base ) ) ? '' : (string) $base;
	}

	/**
	 * Fixed price stored on a product for a role.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return float|null
	 */
	public static function get_fixed_price( $product, $role ) {
		$value = $product->get_meta( self::price_key( $role ), true, 'edit' );
		if ( '' === $value || null === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Percentage discount stored on a product for a role.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return float|null
	 */
	public static function get_product_discount( $product, $role ) {
		$value = $product->get_meta( self::discount_key( $role ), true, 'edit' );
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return max( 0.0, min( 100.0, (float) $value ) );
	}

	/**
	 * Percentage discount from a category (walking up parents).
	 *
	 * @param WP_Term $term  Category.
	 * @param string  $role  Role key.
	 * @param int     $depth Recursion guard.
	 * @return float|null
	 */
	public static function get_term_discount( $term, $role, $depth = 0 ) {
		if ( ! $term instanceof WP_Term || $depth > 10 ) {
			return null;
		}

		$value = get_term_meta( $term->term_id, self::discount_key( $role ), true );
		if ( '' !== $value && null !== $value && is_numeric( $value ) ) {
			return max( 0.0, min( 100.0, (float) $value ) );
		}

		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent instanceof WP_Term ) {
				return self::get_term_discount( $parent, $role, $depth + 1 );
			}
		}

		return null;
	}

	/**
	 * Category discount for a product (highest/lowest across categories per setting).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return float|null
	 */
	public static function get_category_discount( $product, $role ) {
		$found = array();
		foreach ( self::categories_of( $product ) as $term ) {
			$d = self::get_term_discount( $term, $role );
			if ( null !== $d ) {
				$found[] = $d;
			}
		}

		if ( empty( $found ) ) {
			return null;
		}

		return 'lowest' === WWPro_Settings::get( 'multi_category' ) ? min( $found ) : max( $found );
	}

	/**
	 * Store-wide discount of a role.
	 *
	 * @param string $role Role key.
	 * @return float|null
	 */
	public static function get_global_discount( $role ) {
		$config = WWPro_Roles::get( $role );
		if ( ! $config || '' === $config['global_discount'] || ! is_numeric( $config['global_discount'] ) ) {
			return null;
		}
		return max( 0.0, min( 100.0, (float) $config['global_discount'] ) );
	}

	/**
	 * Round a price to the shop's decimals.
	 *
	 * @param float $price Price.
	 * @return float
	 */
	public static function round_price( $price ) {
		$decimals = wc_get_price_decimals();

		/**
		 * Filter the rounded wholesale price.
		 *
		 * @param float $rounded  Rounded price.
		 * @param float $price    Unrounded price.
		 * @param int   $decimals Shop decimals.
		 */
		return (float) apply_filters( 'wwpro_round_price', round( (float) $price, $decimals ), (float) $price, $decimals );
	}

	/**
	 * Resolve the wholesale unit price of a product for a role.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return array|null array( price => float, source => string, discount => float|null, capped => bool ) or null when no rule applies.
	 */
	public static function resolve( $product, $role ) {
		if ( ! $product instanceof WC_Product || ! $role ) {
			return null;
		}

		$cache_key = $product->get_id() . '|' . $role;
		if ( array_key_exists( $cache_key, self::$cache ) ) {
			return self::$cache[ $cache_key ];
		}

		$result = null;
		$base   = self::get_base_price( $product );

		// 1. + 2. Own fixed price / discount.
		$fixed = self::get_fixed_price( $product, $role );
		if ( null !== $fixed ) {
			$result = array(
				'price'    => $fixed,
				'source'   => $product->is_type( 'variation' ) ? 'variation' : 'product',
				'discount' => null,
			);
		} else {
			$pct    = self::get_product_discount( $product, $role );
			$source = $product->is_type( 'variation' ) ? 'variation' : 'product';

			// 3. Parent product of a variation.
			if ( null === $pct && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$parent_fixed = self::get_fixed_price( $parent, $role );
					if ( null !== $parent_fixed ) {
						$result = array(
							'price'    => $parent_fixed,
							'source'   => 'parent',
							'discount' => null,
						);
					} else {
						$pct    = self::get_product_discount( $parent, $role );
						$source = 'parent';
					}
				}
			}

			// 4. Category.
			if ( null === $result && null === $pct ) {
				$pct    = self::get_category_discount( $product, $role );
				$source = 'category';
			}

			// 5. Store-wide.
			if ( null === $result && null === $pct ) {
				$pct    = self::get_global_discount( $role );
				$source = 'global';
			}

			if ( null === $result && null !== $pct && '' !== $base ) {
				$result = array(
					'price'    => (float) $base * ( 1 - $pct / 100 ),
					'source'   => $source,
					'discount' => $pct,
				);
			}
		}

		if ( null !== $result ) {
			$result['capped'] = false;
			$result['price']  = self::round_price( $result['price'] );

			if ( WWPro_Settings::is( 'never_above_retail' ) ) {
				$current = $product->get_price( 'edit' );
				if ( '' !== $current && null !== $current && is_numeric( $current ) && (float) $current < $result['price'] ) {
					$result['price']  = (float) $current;
					$result['capped'] = true;
				}
			}
		}

		/**
		 * Filter the resolved wholesale price.
		 *
		 * @param array|null $result  Resolution result or null.
		 * @param WC_Product $product Product.
		 * @param string     $role    Role key.
		 */
		$result = apply_filters( 'wwpro_resolve_price', $result, $product, $role );

		self::$cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Wholesale unit price (without tiers) or null.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return float|null
	 */
	public static function unit_price( $product, $role ) {
		$r = self::resolve( $product, $role );
		return $r ? (float) $r['price'] : null;
	}

	/**
	 * Unit price the role pays before tiers: wholesale price or, if none, the normal price.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return float|null Null when the product has no price at all.
	 */
	public static function effective_unit_price( $product, $role ) {
		$price = self::unit_price( $product, $role );
		if ( null !== $price ) {
			return $price;
		}
		$current = $product->get_price( 'edit' );
		return ( '' === $current || null === $current || ! is_numeric( $current ) ) ? null : (float) $current;
	}

	/**
	 * Tiered unit price for a quantity, or null when no tier matches.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @param int        $qty     Quantity.
	 * @return float|null
	 */
	public static function tier_price( $product, $role, $qty ) {
		$sets = WWPro_Tiers::resolve( $product, $role );
		if ( empty( $sets ) ) {
			return null;
		}

		$unit = self::effective_unit_price( $product, $role );
		if ( null === $unit ) {
			return null;
		}

		$price = WWPro_Tiers::price_for_qty( $sets, (int) $qty, $unit );
		if ( null === $price ) {
			return null;
		}

		$price = self::round_price( $price );

		// A tier must never make the item more expensive than the unit price.
		if ( $price > $unit ) {
			$price = $unit;
		}

		/**
		 * Filter the tiered price.
		 *
		 * @param float      $price   Tier price.
		 * @param WC_Product $product Product.
		 * @param string     $role    Role key.
		 * @param int        $qty     Quantity.
		 */
		return (float) apply_filters( 'wwpro_tier_price', $price, $product, $role, (int) $qty );
	}

	/**
	 * Set a runtime (cart line) price for a product object.
	 *
	 * @param WC_Product $product Product object.
	 * @param float      $price   Price.
	 */
	public static function set_runtime_price( $product, $price ) {
		$id = spl_object_id( $product );
		if ( ! isset( self::$runtime[ $id ] ) ) {
			self::$runtime[ $id ] = array(
				'price'    => (float) $price,
				'original' => $product->get_price( 'edit' ),
			);
		} else {
			self::$runtime[ $id ]['price'] = (float) $price;
		}
	}

	/**
	 * Get a runtime price for a product object.
	 *
	 * @param WC_Product $product Product object.
	 * @return float|null
	 */
	public static function get_runtime_price( $product ) {
		$id = spl_object_id( $product );
		return isset( self::$runtime[ $id ] ) ? self::$runtime[ $id ]['price'] : null;
	}

	/**
	 * Remove a runtime price.
	 *
	 * @param WC_Product $product Product object.
	 */
	public static function clear_runtime_price( $product ) {
		$id = spl_object_id( $product );
		if ( isset( self::$runtime[ $id ] ) ) {
			// Restore the price prop that was overwritten by a tier price earlier in this request.
			$product->set_price( self::$runtime[ $id ]['original'] );
			unset( self::$runtime[ $id ] );
		}
	}

	/**
	 * Flush all request caches.
	 */
	public static function flush_runtime_cache() {
		self::$cache   = array();
		self::$runtime = array();
	}

	/**
	 * Human readable label for a resolution source (admin/debug).
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'product'   => __( 'product price', 'woo-wholesale' ),
			'variation' => __( 'variation price', 'woo-wholesale' ),
			'parent'    => __( 'parent product', 'woo-wholesale' ),
			'category'  => __( 'category discount', 'woo-wholesale' ),
			'global'    => __( 'store-wide discount', 'woo-wholesale' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}
}
