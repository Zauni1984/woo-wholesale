<?php
/**
 * Bulk percentage adjustment of wholesale prices.
 *
 * Built for the step after the first import: the prices are in the shop, but
 * every price of a role (or of one category) has to move up or down by a
 * percentage. The work runs in batches so that large catalogues finish without
 * a timeout, and the admin screen shows a progress bar while it does.
 *
 * The result is always written as a fixed price for the role, so the outcome is
 * unambiguous: the percentage discount of the same role on that product is
 * removed, otherwise two rules would describe the same price.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk price adjuster (static).
 */
class WWPro_Bulk_Prices {

	/**
	 * Products per batch.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Rounding modes.
	 *
	 * @return array key => label
	 */
	public static function rounding_modes() {
		return array(
			'none' => __( 'Shop decimals (no extra rounding)', 'woo-wholesale' ),
			'005'  => __( 'Round to 0.05', 'woo-wholesale' ),
			'010'  => __( 'Round to 0.10', 'woo-wholesale' ),
			'full' => __( 'Round to whole currency units', 'woo-wholesale' ),
			'99'   => __( 'Price ending in .99', 'woo-wholesale' ),
			'95'   => __( 'Price ending in .95', 'woo-wholesale' ),
		);
	}

	/**
	 * Price bases.
	 *
	 * @return array key => label
	 */
	public static function bases() {
		return array(
			'wholesale' => __( 'Current wholesale price of the role (products without one are skipped)', 'woo-wholesale' ),
			'retail'    => __( 'Regular shop price', 'woo-wholesale' ),
		);
	}

	/**
	 * Sanitize a job description coming from the browser.
	 *
	 * @param mixed $raw Decoded JSON or form array.
	 * @return array|WP_Error
	 */
	public static function sanitize_job( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'wwpro_bulk_input', __( 'The price change could not be read.', 'woo-wholesale' ) );
		}

		$role = isset( $raw['role'] ) ? sanitize_key( $raw['role'] ) : '';
		if ( '' === $role || ! WWPro_Roles::exists( $role ) ) {
			return new WP_Error( 'wwpro_bulk_role', __( 'Please choose a wholesale role.', 'woo-wholesale' ) );
		}

		$percent = WWPro_Roles::to_decimal( isset( $raw['percent'] ) ? $raw['percent'] : '' );
		if ( '' === $percent || ! is_numeric( $percent ) ) {
			return new WP_Error( 'wwpro_bulk_percent', __( 'Please enter a percentage.', 'woo-wholesale' ) );
		}

		$percent = abs( (float) $percent );
		if ( $percent <= 0 || $percent > 100 ) {
			return new WP_Error( 'wwpro_bulk_percent_range', __( 'The percentage must be greater than 0 and at most 100.', 'woo-wholesale' ) );
		}

		$direction = ( isset( $raw['direction'] ) && 'up' === $raw['direction'] ) ? 'up' : 'down';

		$scope    = ( isset( $raw['scope'] ) && 'category' === $raw['scope'] ) ? 'category' : 'all';
		$category = isset( $raw['category'] ) ? absint( $raw['category'] ) : 0;

		if ( 'category' === $scope ) {
			if ( ! $category || ! term_exists( $category, 'product_cat' ) ) {
				return new WP_Error( 'wwpro_bulk_category', __( 'Please choose a product category.', 'woo-wholesale' ) );
			}
		} else {
			$category = 0;
		}

		$base = ( isset( $raw['base'] ) && array_key_exists( $raw['base'], self::bases() ) ) ? $raw['base'] : 'wholesale';

		$rounding = ( isset( $raw['rounding'] ) && array_key_exists( $raw['rounding'], self::rounding_modes() ) ) ? $raw['rounding'] : 'none';

		return array(
			'role'      => $role,
			'percent'   => $percent,
			'direction' => $direction,
			'scope'     => $scope,
			'category'  => $category,
			'base'      => $base,
			'rounding'  => $rounding,
			// Subcategories of the chosen category are included.
			'children'  => ! isset( $raw['children'] ) || ! empty( $raw['children'] ),
		);
	}

	/**
	 * Signed factor of a job (1.1 for +10 %, 0.9 for -10 %).
	 *
	 * @param array $job Job.
	 * @return float
	 */
	public static function factor( array $job ) {
		$percent = (float) $job['percent'];
		return ( 'up' === $job['direction'] ) ? ( 1 + $percent / 100 ) : ( 1 - $percent / 100 );
	}

	/**
	 * Product query for a job.
	 *
	 * @param array $job    Job.
	 * @param int   $offset Offset.
	 * @param int   $limit  Limit (0 = count only).
	 * @return array Query args for wc_get_products().
	 */
	public static function query_args( array $job, $offset = 0, $limit = 0 ) {
		$args = array(
			'status'  => array( 'publish', 'private', 'draft', 'pending' ),
			'type'    => array( 'simple', 'variable' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
			'limit'   => $limit > 0 ? (int) $limit : 1,
			'offset'  => max( 0, (int) $offset ),
		);

		if ( $limit <= 0 ) {
			$args['limit']    = 1;
			$args['paginate'] = true;
		}

		if ( 'category' === $job['scope'] && $job['category'] ) {
			$term = get_term( (int) $job['category'], 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$slugs = array( $term->slug );

				if ( ! empty( $job['children'] ) ) {
					$children = get_term_children( $term->term_id, 'product_cat' );
					if ( is_array( $children ) ) {
						foreach ( $children as $child_id ) {
							$child = get_term( (int) $child_id, 'product_cat' );
							if ( $child instanceof WP_Term ) {
								$slugs[] = $child->slug;
							}
						}
					}
				}

				$args['category'] = array_values( array_unique( $slugs ) );
			} else {
				$args['category'] = array( '__wwpro_no_match__' );
			}
		}

		return $args;
	}

	/**
	 * Number of products in the scope of a job.
	 *
	 * @param array $job Job.
	 * @return int
	 */
	public static function count( array $job ) {
		$result = wc_get_products( self::query_args( $job, 0, 0 ) );
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Apply the rounding mode of a job.
	 *
	 * @param float  $price Price.
	 * @param string $mode  Rounding mode.
	 * @return float
	 */
	public static function round( $price, $mode ) {
		$price    = (float) $price;
		$decimals = wc_get_price_decimals();

		switch ( $mode ) {
			case '005':
				$price = round( $price * 20 ) / 20;
				break;

			case '010':
				$price = round( $price * 10 ) / 10;
				break;

			case 'full':
				$price = round( $price );
				break;

			case '99':
			case '95':
				// Charm pricing: snap to the nearest x.99 / x.95, up or down.
				$ending = ( '99' === $mode ) ? 0.99 : 0.95;
				$lower  = floor( $price ) - 1 + $ending;
				$upper  = floor( $price ) + $ending;

				if ( $lower <= 0 ) {
					$price = $upper;
				} else {
					$price = ( abs( $price - $lower ) <= abs( $upper - $price ) ) ? $lower : $upper;
				}
				break;

			case 'none':
			default:
				$price = round( $price, $decimals );
				break;
		}

		$price = round( $price, $decimals );

		return $price > 0 ? $price : 0.0;
	}

	/**
	 * Base price of a product for a job.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $job     Job.
	 * @return float|null Null when the product cannot be adjusted.
	 */
	public static function base_price( $product, array $job ) {
		if ( 'retail' === $job['base'] ) {
			$value = $product->get_regular_price( 'edit' );
			if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
				$value = $product->get_price( 'edit' );
			}
			return ( '' === $value || null === $value || ! is_numeric( $value ) ) ? null : (float) $value;
		}

		// Current wholesale price of the role: only products that actually have one.
		return WWPro_Pricing::unit_price( $product, $job['role'] );
	}

	/**
	 * Adjust one product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $job     Job.
	 * @return array changed (bool), old (float|null), new (float|null)
	 */
	public static function adjust_product( $product, array $job ) {
		$base = self::base_price( $product, $job );

		if ( null === $base || $base <= 0 ) {
			return array(
				'changed' => false,
				'old'     => null,
				'new'     => null,
			);
		}

		$new = self::round( $base * self::factor( $job ), $job['rounding'] );

		/**
		 * Filter a single price produced by the bulk adjuster.
		 *
		 * @param float      $new     New price.
		 * @param float      $base    Base price.
		 * @param array      $job     Job.
		 * @param WC_Product $product Product.
		 */
		$new = (float) apply_filters( 'wwpro_bulk_adjusted_price', $new, $base, $job, $product );

		if ( $new <= 0 ) {
			return array(
				'changed' => false,
				'old'     => $base,
				'new'     => null,
			);
		}

		$price_key    = WWPro_Pricing::price_key( $job['role'] );
		$discount_key = WWPro_Pricing::discount_key( $job['role'] );
		$stored       = $product->get_meta( $price_key, true, 'edit' );
		$had_discount = '' !== (string) $product->get_meta( $discount_key, true, 'edit' );

		if ( '' !== (string) $stored && is_numeric( $stored ) && abs( (float) $stored - $new ) < 0.0000001 && ! $had_discount ) {
			return array(
				'changed' => false,
				'old'     => (float) $stored,
				'new'     => $new,
			);
		}

		$product->update_meta_data( $price_key, (string) $new );

		// A fixed price and a percentage for the same role would describe the
		// same price twice; the percentage is dropped so the result is readable.
		if ( $had_discount ) {
			$product->delete_meta_data( $discount_key );
		}

		$product->save();

		return array(
			'changed' => true,
			'old'     => ( '' !== (string) $stored && is_numeric( $stored ) ) ? (float) $stored : null,
			'new'     => $new,
		);
	}

	/**
	 * Run one batch.
	 *
	 * @param array $job    Job.
	 * @param int   $offset Offset into the product list.
	 * @return array processed, changed, skipped, done, offset
	 */
	public static function run_batch( array $job, $offset ) {
		$ids = wc_get_products( self::query_args( $job, $offset, self::BATCH_SIZE ) );
		$ids = is_array( $ids ) ? $ids : array();

		$changed = 0;
		$skipped = 0;

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				++$skipped;
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				$touched = false;

				foreach ( (array) $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( ! $variation instanceof WC_Product ) {
						continue;
					}
					$result = self::adjust_product( $variation, $job );
					if ( $result['changed'] ) {
						$touched = true;
					}
				}

				if ( $touched ) {
					++$changed;
					wc_delete_product_transients( $product->get_id() );
				} else {
					++$skipped;
				}

				continue;
			}

			$result = self::adjust_product( $product, $job );
			if ( $result['changed'] ) {
				++$changed;
			} else {
				++$skipped;
			}
		}

		$processed = count( $ids );

		return array(
			'processed' => $processed,
			'changed'   => $changed,
			'skipped'   => $skipped,
			'offset'    => $offset + $processed,
			'done'      => $processed < self::BATCH_SIZE,
		);
	}

	/**
	 * Finish a run: drop the price caches.
	 *
	 * @param array $job     Job.
	 * @param array $summary Totals of the run.
	 */
	public static function finish( array $job, array $summary ) {
		WWPro_Settings::bump_cache_version();

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		update_option(
			'wwpro_last_bulk_price',
			array(
				'time'    => time(),
				'job'     => $job,
				'summary' => $summary,
			),
			false
		);
	}

	/**
	 * Human readable description of a job (used in the log).
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function describe( array $job ) {
		$scope = __( 'all products', 'woo-wholesale' );

		if ( 'category' === $job['scope'] ) {
			$term  = get_term( (int) $job['category'], 'product_cat' );
			$scope = ( $term instanceof WP_Term ) ? $term->name : __( 'selected category', 'woo-wholesale' );
		}

		return sprintf(
			/* translators: 1: signed percentage, 2: wholesale role name, 3: scope description */
			__( '%1$s on the wholesale prices of "%2$s" in %3$s', 'woo-wholesale' ),
			( 'up' === $job['direction'] ? '+' : '−' ) . wc_format_localized_decimal( $job['percent'] ) . ' %',
			WWPro_Roles::label( $job['role'] ),
			$scope
		);
	}
}
