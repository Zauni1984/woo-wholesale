<?php
/**
 * Frontend price integration.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Frontend hooks (static).
 */
class WWPro_Frontend {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Simple / external / variation objects.
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );

		// Variable product price ranges (cached by WooCommerce, therefore the hash filter).
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_prices_price' ), 99, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'filter_variation_prices_price' ), 99, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'filter_variation_prices_sale_price' ), 99, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_prices_hash' ), 99, 3 );

		// A wholesale price is a single price: no strike-through, no sale badge.
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), 99, 2 );
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'filter_sale_flash' ), 99, 3 );

		// Secondary (net/gross) price and role badge.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 99, 2 );

		// Tax display override per role.
		add_filter( 'pre_option_woocommerce_tax_display_shop', array( __CLASS__, 'filter_tax_display' ), 10, 1 );
		add_filter( 'pre_option_woocommerce_tax_display_cart', array( __CLASS__, 'filter_tax_display' ), 10, 1 );

		// Tier table on the product page.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_tier_table' ), 11 );
		add_shortcode( 'wwpro_tiers', array( __CLASS__, 'shortcode_tiers' ) );

		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Wholesale price for a product object (runtime cart price first).
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	private static function wholesale_price( $product ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role || ! $product instanceof WC_Product ) {
			return null;
		}

		$runtime = WWPro_Pricing::get_runtime_price( $product );
		if ( null !== $runtime ) {
			return $runtime;
		}

		return WWPro_Pricing::unit_price( $product, $role );
	}

	/**
	 * Filter: active price.
	 *
	 * @param mixed      $price   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_price( $price, $product ) {
		$wholesale = self::wholesale_price( $product );
		return null === $wholesale ? $price : wc_format_decimal( $wholesale );
	}

	/**
	 * Filter: regular price. Equals the wholesale price so that no strike-through is shown.
	 *
	 * @param mixed      $price   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_regular_price( $price, $product ) {
		$wholesale = self::wholesale_price( $product );
		return null === $wholesale ? $price : wc_format_decimal( $wholesale );
	}

	/**
	 * Filter: sale price is dropped when a wholesale price applies.
	 *
	 * @param mixed      $price   Price.
	 * @param WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_sale_price( $price, $product ) {
		$wholesale = self::wholesale_price( $product );
		return null === $wholesale ? $price : '';
	}

	/**
	 * Filter: variation price inside get_variation_prices().
	 *
	 * @param mixed                $price     Price.
	 * @param WC_Product_Variation $variation Variation.
	 * @param WC_Product_Variable  $product   Parent.
	 * @return mixed
	 */
	public static function filter_variation_prices_price( $price, $variation, $product ) {
		$wholesale = self::wholesale_price( $variation );
		return null === $wholesale ? $price : wc_format_decimal( $wholesale );
	}

	/**
	 * Filter: variation sale price inside get_variation_prices().
	 *
	 * @param mixed                $price     Price.
	 * @param WC_Product_Variation $variation Variation.
	 * @param WC_Product_Variable  $product   Parent.
	 * @return mixed
	 */
	public static function filter_variation_prices_sale_price( $price, $variation, $product ) {
		$wholesale = self::wholesale_price( $variation );
		return null === $wholesale ? $price : wc_format_decimal( $wholesale );
	}

	/**
	 * Add the role and the rule version to the variation price cache hash.
	 *
	 * @param array               $hash        Hash parts.
	 * @param WC_Product_Variable $product     Product.
	 * @param bool                $for_display For display.
	 * @return array
	 */
	public static function variation_prices_hash( $hash, $product, $for_display ) {
		$role = WWPro_Pricing::current_role();
		if ( null !== $role ) {
			$hash['wwpro'] = $role . ':' . WWPro_Settings::cache_version();
		}
		return $hash;
	}

	/**
	 * Filter: on sale.
	 *
	 * @param bool       $on_sale On sale.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function filter_is_on_sale( $on_sale, $product ) {
		if ( ! $on_sale ) {
			return $on_sale;
		}

		$role = WWPro_Pricing::current_role();
		if ( null === $role ) {
			return $on_sale;
		}

		if ( $product instanceof WC_Product_Variable ) {
			// A variable product is "on sale" for the role only if a child still is.
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && null === WWPro_Pricing::unit_price( $child, $role ) && $child->is_on_sale() ) {
					return true;
				}
			}
			return false;
		}

		return null === WWPro_Pricing::unit_price( $product, $role );
	}

	/**
	 * Filter: sale badge.
	 *
	 * @param string     $html    Badge HTML.
	 * @param WP_Post    $post    Post.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_sale_flash( $html, $post, $product ) {
		if ( ! WWPro_Settings::is( 'hide_sale_badge' ) || null === WWPro_Pricing::current_role() ) {
			return $html;
		}
		return ( $product instanceof WC_Product && $product->is_on_sale() ) ? $html : '';
	}

	/**
	 * Convert a raw price to the secondary display amount.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $price   Raw price.
	 * @param string     $mode    net|gross.
	 * @return float
	 */
	private static function convert( $product, $price, $mode ) {
		$args = array(
			'qty'   => 1,
			'price' => $price,
		);
		return 'net' === $mode
			? (float) wc_get_price_excluding_tax( $product, $args )
			: (float) wc_get_price_including_tax( $product, $args );
	}

	/**
	 * Secondary price HTML for a product (or '' when not applicable).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $context shop|cart.
	 * @return string
	 */
	public static function secondary_price_html( $product, $context = 'shop' ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role ) {
			return '';
		}

		$config = WWPro_Roles::get( $role );
		if ( ! $config || 'none' === $config['secondary_price'] ) {
			return '';
		}

		$mode = $config['secondary_price'];

		if ( ! wc_tax_enabled() || ! $product instanceof WC_Product || $product->is_type( 'grouped' ) || ! $product->is_taxable() ) {
			return '';
		}

		$display = get_option( 'cart' === $context ? 'woocommerce_tax_display_cart' : 'woocommerce_tax_display_shop' );
		if ( ( 'net' === $mode && 'excl' === $display ) || ( 'gross' === $mode && 'incl' === $display ) ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( false );
			if ( empty( $prices['price'] ) ) {
				return '';
			}
			$min = (float) current( $prices['price'] );
			$max = (float) end( $prices['price'] );

			$min_c = self::convert( $product, $min, $mode );
			$max_c = self::convert( $product, $max, $mode );

			$formatted = $min_c !== $max_c ? wc_format_price_range( $min_c, $max_c ) : wc_price( $min_c );
		} else {
			$raw = $product->get_price();
			if ( '' === $raw || null === $raw ) {
				return '';
			}
			$formatted = wc_price( self::convert( $product, (float) $raw, $mode ) );
		}

		$label = WWPro_Settings::get( 'net' === $mode ? 'secondary_net_label' : 'secondary_gross_label' );
		$text  = sprintf( $label, $formatted );

		$html = '<span class="wwpro-secondary-price wwpro-secondary-' . esc_attr( $mode ) . '">' . wp_kses_post( $text ) . '</span>';

		/**
		 * Filter the secondary price HTML.
		 *
		 * @param string     $html    HTML.
		 * @param WC_Product $product Product.
		 * @param string     $mode    net|gross.
		 * @param string     $context shop|cart.
		 */
		return apply_filters( 'wwpro_secondary_price_html', $html, $product, $mode, $context );
	}

	/**
	 * Filter: price HTML.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_price_html( $html, $product ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role || '' === $html ) {
			return $html;
		}

		$html .= self::secondary_price_html( $product, 'shop' );

		if ( WWPro_Settings::is( 'show_role_badge' ) && null !== WWPro_Pricing::unit_price( $product, $role ) ) {
			$html .= '<span class="wwpro-role-badge">' . esc_html(
				sprintf(
					/* translators: %s: wholesale role name */
					__( 'Your %s price', 'woo-wholesale' ),
					WWPro_Roles::label( $role )
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * Override the tax display option for the role.
	 *
	 * @param mixed $pre Pre-option value (false = no override).
	 * @return mixed
	 */
	public static function filter_tax_display( $pre ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role ) {
			return $pre;
		}
		$config = WWPro_Roles::get( $role );
		if ( $config && in_array( $config['tax_display'], array( 'incl', 'excl' ), true ) ) {
			return $config['tax_display'];
		}
		return $pre;
	}

	/**
	 * Tier table HTML for a product (or '').
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function tier_table_html( $product ) {
		$role = WWPro_Pricing::current_role();
		if ( null === $role || ! $product instanceof WC_Product ) {
			return '';
		}

		$config = WWPro_Roles::get( $role );
		if ( ! $config || 'yes' !== $config['show_tiers'] ) {
			return '';
		}

		$sets = WWPro_Tiers::resolve( $product, $role );
		if ( empty( $sets ) ) {
			return '';
		}

		$unit = $product->is_type( 'variable' ) ? null : WWPro_Pricing::effective_unit_price( $product, $role );
		$rows = WWPro_Tiers::display_rows( $sets, $unit );
		if ( empty( $rows ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="wwpro-tier-table">
			<h4 class="wwpro-tier-title"><?php esc_html_e( 'Quantity discounts', 'woo-wholesale' ); ?></h4>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Quantity', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Unit price', 'woo-wholesale' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php
							printf(
								/* translators: %s: quantity */
								esc_html__( 'from %s', 'woo-wholesale' ),
								esc_html( number_format_i18n( $row['qty'] ) )
							);
							?>
						</td>
						<td>
							<?php
							if ( null !== $row['price'] ) {
								$display = wc_get_price_to_display( $product, array( 'price' => $row['price'] ) );
								echo wp_kses_post( wc_price( $display ) );
								if ( null !== $row['discount'] && $row['discount'] > 0 ) {
									echo ' <span class="wwpro-tier-discount">(&minus;' . esc_html( wc_format_localized_decimal( $row['discount'] ) ) . '&nbsp;%)</span>';
								}
							} elseif ( null !== $row['discount'] ) {
								echo '<span class="wwpro-tier-discount">&minus;' . esc_html( wc_format_localized_decimal( $row['discount'] ) ) . '&nbsp;%</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( 'product' === WWPro_Settings::get( 'tier_qty_basis' ) && $product->is_type( 'variable' ) ) : ?>
				<p class="wwpro-tier-note"><?php esc_html_e( 'Quantities of all variations of this product are added up.', 'woo-wholesale' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Output the tier table on the single product page.
	 */
	public static function render_tier_table() {
		if ( ! WWPro_Settings::is( 'show_tier_table' ) ) {
			return;
		}
		global $product;
		echo self::tier_table_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tier_table_html().
	}

	/**
	 * Shortcode [wwpro_tiers id=""].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_tiers( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'wwpro_tiers' );
		$product = $atts['id'] ? wc_get_product( absint( $atts['id'] ) ) : ( isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null );
		return $product instanceof WC_Product ? self::tier_table_html( $product ) : '';
	}

	/**
	 * Body classes for theming.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$role = WWPro_Pricing::current_role();
		if ( null !== $role ) {
			$classes[] = 'wwpro-wholesale';
			$classes[] = 'wwpro-role-' . sanitize_html_class( $role );
		}
		return $classes;
	}

	/**
	 * Frontend assets.
	 */
	public static function enqueue() {
		if ( null === WWPro_Pricing::current_role() ) {
			return;
		}
		wp_enqueue_style( 'wwpro-frontend', WWPRO_URL . 'assets/css/frontend.css', array(), WWPRO_VERSION );
	}
}
