<?php
/**
 * Tiered (quantity based) wholesale discounts.
 *
 * A tier set is stored per role on a product (meta) or on a product
 * category (term meta):
 *
 *   array(
 *     'enabled' => 'yes',
 *     'rows'    => array(
 *       array( 'qty' => 10, 'discount' => '5',  'price' => '' ),
 *       array( 'qty' => 50, 'discount' => '',   'price' => '9.90' ),
 *     ),
 *   )
 *
 * A row matches when the ordered quantity is >= qty. A fixed price wins
 * over a percentage; the percentage is applied to the role's unit price.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tier helper (static).
 */
class WWPro_Tiers {

	const META = '_wwpro_tiers_';

	/**
	 * Meta key for a role.
	 *
	 * @param string $role Role key.
	 * @return string
	 */
	public static function meta_key( $role ) {
		return self::META . sanitize_key( $role );
	}

	/**
	 * Empty tier set.
	 *
	 * @return array
	 */
	public static function empty_set() {
		return array(
			'enabled' => 'no',
			'rows'    => array(),
		);
	}

	/**
	 * Sanitize a raw tier set (from a form or import).
	 *
	 * @param mixed $raw Raw data.
	 * @return array Sanitized set (may have no rows).
	 */
	public static function sanitize( $raw ) {
		$set = self::empty_set();

		if ( ! is_array( $raw ) ) {
			return $set;
		}

		$set['enabled'] = ( isset( $raw['enabled'] ) && in_array( $raw['enabled'], array( 'yes', '1', 1, true ), true ) ) ? 'yes' : 'no';

		$rows = isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array();
		$by_qty = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$qty = isset( $row['qty'] ) ? absint( $row['qty'] ) : 0;
			if ( $qty < 1 ) {
				continue;
			}

			$discount = isset( $row['discount'] ) ? WWPro_Roles::sanitize_percent( $row['discount'] ) : '';
			$price    = isset( $row['price'] ) ? WWPro_Roles::to_decimal( $row['price'] ) : '';
			$price    = ( '' !== $price && is_numeric( $price ) && (float) $price > 0 ) ? (string) (float) $price : '';

			if ( '' === $discount && '' === $price ) {
				continue;
			}

			// Later rows with the same quantity replace earlier ones.
			$by_qty[ $qty ] = array(
				'qty'      => $qty,
				'discount' => $discount,
				'price'    => $price,
			);
		}

		ksort( $by_qty, SORT_NUMERIC );
		$set['rows'] = array_values( $by_qty );

		return $set;
	}

	/**
	 * Whether a set is active (enabled and has rows).
	 *
	 * @param array|null $set Tier set.
	 * @return bool
	 */
	public static function is_active_set( $set ) {
		return is_array( $set ) && isset( $set['enabled'], $set['rows'] ) && 'yes' === $set['enabled'] && ! empty( $set['rows'] );
	}

	/**
	 * Read the tier set stored on a product (parent product for variations).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return array|null Active set or null.
	 */
	public static function get_product_set( $product, $role ) {
		$target = $product;
		if ( $product->is_type( 'variation' ) ) {
			$target = wc_get_product( $product->get_parent_id() );
			if ( ! $target ) {
				return null;
			}
		}

		$raw = $target->get_meta( self::meta_key( $role ), true, 'edit' );
		if ( empty( $raw ) ) {
			return null;
		}

		$set = self::sanitize( $raw );
		return self::is_active_set( $set ) ? $set : null;
	}

	/**
	 * Read the tier set of a category, walking up to parent categories.
	 *
	 * @param WP_Term $term  Category.
	 * @param string  $role  Role key.
	 * @param int     $depth Recursion guard.
	 * @return array|null
	 */
	public static function get_term_set( $term, $role, $depth = 0 ) {
		if ( ! $term instanceof WP_Term || $depth > 10 ) {
			return null;
		}

		$raw = get_term_meta( $term->term_id, self::meta_key( $role ), true );
		if ( ! empty( $raw ) ) {
			$set = self::sanitize( $raw );
			if ( self::is_active_set( $set ) ) {
				return $set;
			}
		}

		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent instanceof WP_Term ) {
				return self::get_term_set( $parent, $role, $depth + 1 );
			}
		}

		return null;
	}

	/**
	 * Collect the tier sets that apply to a product for a role.
	 *
	 * A set on the product itself replaces category sets.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $role    Role key.
	 * @return array[] List of sets (may be empty).
	 */
	public static function resolve( $product, $role ) {
		$product_set = self::get_product_set( $product, $role );
		if ( $product_set ) {
			$sets = array( $product_set );
		} else {
			$sets = array();
			foreach ( WWPro_Pricing::categories_of( $product ) as $term ) {
				$set = self::get_term_set( $term, $role );
				if ( $set ) {
					$sets[] = $set;
				}
			}
		}

		/**
		 * Filter the tier sets that apply to a product.
		 *
		 * @param array[]    $sets    Tier sets.
		 * @param WC_Product $product Product.
		 * @param string     $role    Role key.
		 */
		return apply_filters( 'wwpro_tier_sets', $sets, $product, $role );
	}

	/**
	 * Find the matching row for a quantity in one set.
	 *
	 * @param array $set Tier set.
	 * @param int   $qty Quantity.
	 * @return array|null
	 */
	public static function match_row( $set, $qty ) {
		$match = null;
		foreach ( $set['rows'] as $row ) {
			if ( $qty >= $row['qty'] ) {
				$match = $row;
			} else {
				break;
			}
		}
		return $match;
	}

	/**
	 * Apply one row to a unit price.
	 *
	 * @param array $row  Tier row.
	 * @param float $unit Unit price (role price).
	 * @return float
	 */
	public static function apply_row( $row, $unit ) {
		if ( '' !== $row['price'] ) {
			return (float) $row['price'];
		}
		return (float) $unit * ( 1 - ( (float) $row['discount'] / 100 ) );
	}

	/**
	 * Tiered unit price for a quantity across several sets.
	 *
	 * @param array[]    $sets Tier sets.
	 * @param int        $qty  Quantity.
	 * @param float|null $unit Unit price; null when unknown (variable parent).
	 * @return float|null Price, or null when no row matches.
	 */
	public static function price_for_qty( $sets, $qty, $unit ) {
		if ( null === $unit ) {
			return null;
		}

		$candidates = array();
		foreach ( $sets as $set ) {
			$row = self::match_row( $set, $qty );
			if ( $row ) {
				$candidates[] = self::apply_row( $row, $unit );
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		return 'lowest' === WWPro_Settings::get( 'multi_category' ) ? max( $candidates ) : min( $candidates );
	}

	/**
	 * Rows for display: every distinct quantity threshold with its effective value.
	 *
	 * @param array[]    $sets Tier sets.
	 * @param float|null $unit Unit price or null (variable products).
	 * @return array[] Each: qty, price (float|null), discount (float|null).
	 */
	public static function display_rows( $sets, $unit ) {
		$thresholds = array();
		foreach ( $sets as $set ) {
			foreach ( $set['rows'] as $row ) {
				$thresholds[ $row['qty'] ] = true;
			}
		}
		ksort( $thresholds, SORT_NUMERIC );

		$out = array();
		foreach ( array_keys( $thresholds ) as $qty ) {
			if ( null !== $unit ) {
				$price = self::price_for_qty( $sets, $qty, $unit );
				if ( null === $price ) {
					continue;
				}
				$out[] = array(
					'qty'      => $qty,
					'price'    => $price,
					'discount' => $unit > 0 ? round( ( 1 - $price / $unit ) * 100, 2 ) : null,
				);
				continue;
			}

			// Unknown unit price (variable parent): show the best percentage, or a fixed price.
			$best_discount = null;
			$fixed         = null;
			foreach ( $sets as $set ) {
				$row = self::match_row( $set, $qty );
				if ( ! $row ) {
					continue;
				}
				if ( '' !== $row['price'] ) {
					$fixed = null === $fixed ? (float) $row['price'] : min( $fixed, (float) $row['price'] );
				} elseif ( '' !== $row['discount'] ) {
					$best_discount = null === $best_discount ? (float) $row['discount'] : max( $best_discount, (float) $row['discount'] );
				}
			}
			if ( null === $fixed && null === $best_discount ) {
				continue;
			}
			$out[] = array(
				'qty'      => $qty,
				'price'    => $fixed,
				'discount' => $best_discount,
			);
		}

		return $out;
	}
}
