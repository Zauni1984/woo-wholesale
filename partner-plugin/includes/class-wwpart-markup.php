<?php
/**
 * Sales price of the partner shop.
 *
 * The supplier delivers the wholesale price. The sales price of this shop is
 * that price plus the partner's markup, which can be set for the whole
 * catalogue or per product category - upwards or downwards. The supplier's
 * policy sets the range the markup has to stay in, so the partner cannot
 * undercut the agreed price unless the supplier allows it.
 *
 * Applying a markup runs in batches, so a large catalogue finishes without a
 * timeout and the screen can show a progress bar.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markup engine (static).
 */
class WWPart_Markup {

	/**
	 * Products per batch.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Markup that applies to a product, in percent.
	 *
	 * The most specific category wins; a category without a markup inherits from
	 * its parent, and a product without any category markup uses the global one.
	 *
	 * @param int $product_id Product ID.
	 * @return float
	 */
	public static function for_product( $product_id ) {
		$policy = WWPart_Settings::policy();

		if ( ! $policy['allow_markup'] ) {
			return 0.0;
		}

		$markup = self::category_markup( $product_id );

		if ( null === $markup ) {
			$global = WWPart_Settings::get( 'markup' );
			$markup = ( '' === $global || ! is_numeric( $global ) ) ? 0.0 : (float) $global;
		}

		return self::clamp( $markup );
	}

	/**
	 * Markup of the deepest category of a product that has one.
	 *
	 * @param int $product_id Product ID.
	 * @return float|null
	 */
	public static function category_markup( $product_id ) {
		$terms = get_the_terms( (int) $product_id, 'product_cat' );

		if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}

		$best       = null;
		$best_depth = -1;

		foreach ( $terms as $term ) {
			$depth = 0;
			$node  = $term;

			while ( $node instanceof WP_Term && $depth < 10 ) {
				$value = WWPart_Settings::category_markup( $node->term_id );

				if ( '' !== $value ) {
					// A markup found further down the tree is the more specific one.
					$own_depth = self::term_depth( $term ) - $depth;
					if ( $own_depth > $best_depth ) {
						$best       = (float) $value;
						$best_depth = $own_depth;
					}
					break;
				}

				$node = $node->parent ? get_term( $node->parent, 'product_cat' ) : null;
				++$depth;
			}
		}

		return $best;
	}

	/**
	 * Depth of a term in the category tree (0 = top level).
	 *
	 * @param WP_Term $term Term.
	 * @return int
	 */
	private static function term_depth( $term ) {
		$depth = 0;
		$node  = $term;

		while ( $node instanceof WP_Term && $node->parent && $depth < 10 ) {
			$node = get_term( $node->parent, $node->taxonomy );
			++$depth;
		}

		return $depth;
	}

	/**
	 * Keep a markup inside the range the supplier allows.
	 *
	 * @param float $markup Markup in percent.
	 * @return float
	 */
	public static function clamp( $markup ) {
		$policy = WWPart_Settings::policy();

		if ( ! $policy['allow_markup'] ) {
			return 0.0;
		}

		return (float) max( $policy['markup_min'], min( $policy['markup_max'], (float) $markup ) );
	}

	/**
	 * Apply a rounding mode.
	 *
	 * @param float  $price Price.
	 * @param string $mode  Rounding mode.
	 * @return float
	 */
	public static function round_price( $price, $mode ) {
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
	 * Sales price for a wholesale price.
	 *
	 * @param float $base   Wholesale price.
	 * @param float $markup Markup in percent.
	 * @return float
	 */
	public static function sales_price( $base, $markup ) {
		$price = (float) $base * ( 1 + (float) $markup / 100 );

		/**
		 * Filter the sales price the partner shop calculates.
		 *
		 * @param float $price  Sales price before rounding.
		 * @param float $base   Wholesale price.
		 * @param float $markup Markup in percent.
		 */
		$price = (float) apply_filters( 'wwpart_sales_price', $price, (float) $base, (float) $markup );

		return self::round_price( $price, (string) WWPart_Settings::get( 'rounding' ) );
	}

	/**
	 * Recalculate the price of one product (and its variations).
	 *
	 * @param int        $product_id Product ID.
	 * @param float|null $markup     Markup to use, null = resolve from the settings.
	 * @return bool Whether a price was written.
	 */
	public static function apply_to_product( $product_id, $markup = null ) {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( null === $markup ) {
			$markup = self::for_product( $product->get_id() );
		}

		$markup  = self::clamp( $markup );
		$changed = false;

		if ( $product->is_type( 'variable' ) ) {
			foreach ( (array) $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				if ( self::write_price( $variation, $markup ) ) {
					$changed = true;
				}
			}

			if ( $changed ) {
				wc_delete_product_transients( $product->get_id() );
			}
		} else {
			$changed = self::write_price( $product, $markup );
		}

		update_post_meta( $product->get_id(), WWPart_Product::META_MARKUP, (string) $markup );

		return $changed;
	}

	/**
	 * Write the sales price onto a product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $markup  Markup in percent.
	 * @return bool
	 */
	public static function write_price( $product, $markup ) {
		$base = WWPart_Product::base_price( $product->get_id() );

		if ( null === $base || $base <= 0 ) {
			return false;
		}

		$price   = self::sales_price( $base, $markup );
		$current = $product->get_regular_price( 'edit' );

		if ( $price <= 0 ) {
			return false;
		}

		if ( '' !== (string) $current && is_numeric( $current ) && abs( (float) $current - $price ) < 0.0000001 ) {
			return false;
		}

		$product->set_regular_price( (string) $price );

		// A sale price below the new regular price stays; one above it would be
		// a price increase disguised as a sale, so it is dropped.
		$sale = $product->get_sale_price( 'edit' );
		if ( '' !== (string) $sale && is_numeric( $sale ) && (float) $sale >= $price ) {
			$product->set_sale_price( '' );
		}

		if ( '' === (string) $product->get_sale_price( 'edit' ) ) {
			$product->set_price( (string) $price );
		}

		$product->save();

		return true;
	}

	/**
	 * Sanitize a markup job from the browser.
	 *
	 * @param mixed $raw Decoded JSON.
	 * @return array|WP_Error
	 */
	public static function sanitize_job( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'wwpart_job', __( 'The price change could not be read.', 'woo-wholesale-partner' ) );
		}

		$policy = WWPart_Settings::policy();

		if ( ! $policy['allow_markup'] ) {
			return new WP_Error( 'wwpart_job_blocked', __( 'The supplier does not allow an own markup in this shop.', 'woo-wholesale-partner' ) );
		}

		$percent = WWPart_Settings::sanitize_percent( isset( $raw['percent'] ) ? $raw['percent'] : '' );
		if ( '' === $percent ) {
			return new WP_Error( 'wwpart_job_percent', __( 'Please enter a percentage.', 'woo-wholesale-partner' ) );
		}

		$direction = ( isset( $raw['direction'] ) && 'down' === $raw['direction'] ) ? 'down' : 'up';
		$markup    = ( 'down' === $direction ) ? -1 * abs( (float) $percent ) : abs( (float) $percent );

		if ( $markup < $policy['markup_min'] || $markup > $policy['markup_max'] ) {
			return new WP_Error(
				'wwpart_job_range',
				sprintf(
					/* translators: 1: lowest allowed markup, 2: highest allowed markup */
					__( 'The supplier allows a markup between %1$s %% and %2$s %%.', 'woo-wholesale-partner' ),
					wc_format_localized_decimal( $policy['markup_min'] ),
					wc_format_localized_decimal( $policy['markup_max'] )
				)
			);
		}

		$scope    = ( isset( $raw['scope'] ) && 'category' === $raw['scope'] ) ? 'category' : 'all';
		$category = isset( $raw['category'] ) ? absint( $raw['category'] ) : 0;

		if ( 'category' === $scope && ( ! $category || ! term_exists( $category, 'product_cat' ) ) ) {
			return new WP_Error( 'wwpart_job_category', __( 'Please choose a product category.', 'woo-wholesale-partner' ) );
		}

		if ( 'all' === $scope ) {
			$category = 0;
		}

		return array(
			'markup'    => $markup,
			'direction' => $direction,
			'percent'   => abs( (float) $percent ),
			'scope'     => $scope,
			'category'  => $category,
			'children'  => ! isset( $raw['children'] ) || ! empty( $raw['children'] ),
			'overwrite' => ! empty( $raw['overwrite'] ),
		);
	}

	/**
	 * Store the markup of a job so later syncs keep using it.
	 *
	 * @param array $job Job.
	 */
	public static function store_job( array $job ) {
		if ( 'all' === $job['scope'] ) {
			WWPart_Settings::set( array( 'markup' => (string) $job['markup'] ) );

			if ( ! empty( $job['overwrite'] ) ) {
				self::clear_category_markups();
			}

			return;
		}

		WWPart_Settings::set_category_markup( $job['category'], $job['markup'] );

		// Subcategories with their own markup would keep their old value, so
		// including them means they go back to inheriting this one.
		if ( ! empty( $job['children'] ) ) {
			$children = get_term_children( (int) $job['category'], 'product_cat' );
			if ( is_array( $children ) ) {
				foreach ( $children as $child_id ) {
					delete_term_meta( (int) $child_id, WWPart_Settings::TERM_MARKUP );
				}
			}
		}
	}

	/**
	 * Remove every category markup.
	 */
	public static function clear_category_markups() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term_id ) {
			delete_term_meta( (int) $term_id, WWPart_Settings::TERM_MARKUP );
		}
	}

	/**
	 * Number of products a job touches.
	 *
	 * @param array $job Job.
	 * @return int
	 */
	public static function count( array $job ) {
		return WWPart_Product::count_managed( (int) $job['category'], ! empty( $job['children'] ) );
	}

	/**
	 * Run one batch of a job.
	 *
	 * @param array $job    Job.
	 * @param int   $offset Offset.
	 * @return array processed, changed, offset, done
	 */
	public static function run_batch( array $job, $offset ) {
		$ids = WWPart_Product::managed_ids( $offset, self::BATCH_SIZE, (int) $job['category'], ! empty( $job['children'] ) );

		$changed = 0;

		foreach ( $ids as $product_id ) {
			// The stored markup is resolved per product, so a run over "all"
			// still respects a category that keeps its own markup.
			if ( self::apply_to_product( $product_id ) ) {
				++$changed;
			}
		}

		$processed = count( $ids );

		return array(
			'processed' => $processed,
			'changed'   => $changed,
			'offset'    => $offset + $processed,
			'done'      => $processed < self::BATCH_SIZE,
		);
	}

	/**
	 * Human readable description of a job.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function describe( array $job ) {
		$scope = __( 'all products', 'woo-wholesale-partner' );

		if ( 'category' === $job['scope'] ) {
			$term  = get_term( (int) $job['category'], 'product_cat' );
			$scope = ( $term instanceof WP_Term ) ? $term->name : __( 'selected category', 'woo-wholesale-partner' );
		}

		return sprintf(
			/* translators: 1: signed markup in percent, 2: scope description */
			__( 'Markup %1$s on the wholesale price for %2$s', 'woo-wholesale-partner' ),
			( $job['markup'] >= 0 ? '+' : '−' ) . wc_format_localized_decimal( abs( (float) $job['markup'] ) ) . ' %',
			$scope
		);
	}

	/**
	 * Drop the price caches after a run.
	 */
	public static function finish() {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}
}
