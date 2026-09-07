<?php
/**
 * Product editor fields (simple, external, variable + variations).
 *
 * All save hooks used here run only after WooCommerce has verified its own
 * nonce and the user's capability; an additional capability check is done anyway.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product fields (static).
 */
class WWPro_Product_Fields {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ), 10, 1 );

		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );

		add_action( 'woocommerce_variable_product_bulk_edit_actions', array( __CLASS__, 'bulk_actions' ) );
		add_action( 'woocommerce_bulk_edit_variations_default', array( __CLASS__, 'handle_bulk_action' ), 10, 4 );
	}

	/**
	 * Fields in the "General" tab.
	 */
	public static function render_product_fields() {
		global $post;

		$roles = WWPro_Roles::product_roles();
		if ( empty( $roles ) ) {
			return;
		}

		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product ) {
			return;
		}

		$symbol = get_woocommerce_currency_symbol();
		?>
		<div class="options_group wwpro-product-fields show_if_simple show_if_external show_if_variable">
			<p class="form-field wwpro-heading">
				<strong><?php esc_html_e( 'Wholesale prices', 'woo-wholesale' ); ?></strong>
				<span class="description">
					<?php esc_html_e( 'A fixed price takes precedence over the percentage. Both take precedence over category and store-wide discounts.', 'woo-wholesale' ); ?>
					<span class="show_if_variable"><?php esc_html_e( 'For variable products these values are the fallback for variations without their own wholesale price.', 'woo-wholesale' ); ?></span>
				</span>
			</p>
			<?php foreach ( $roles as $key => $role ) : ?>
				<div class="wwpro-role-block">
					<h4 class="wwpro-role-title"><?php echo esc_html( $role['name'] ); ?> <code><?php echo esc_html( $key ); ?></code></h4>
					<?php
					woocommerce_wp_text_input(
						array(
							'id'          => '_wwpro_price_' . $key,
							'name'        => '_wwpro_price[' . $key . ']',
							/* translators: %s: currency symbol */
							'label'       => sprintf( __( 'Wholesale price (%s)', 'woo-wholesale' ), $symbol ),
							'data_type'   => 'price',
							'value'       => wc_format_localized_price( $product->get_meta( WWPro_Pricing::price_key( $key ), true, 'edit' ) ),
							'desc_tip'    => true,
							'description' => __( 'Fixed price for this role. Leave empty to use the percentage or the category / store-wide discount.', 'woo-wholesale' ),
						)
					);
					woocommerce_wp_text_input(
						array(
							'id'          => '_wwpro_discount_' . $key,
							'name'        => '_wwpro_discount[' . $key . ']',
							'label'       => __( 'Discount (%)', 'woo-wholesale' ),
							'data_type'   => 'decimal',
							'value'       => wc_format_localized_decimal( $product->get_meta( WWPro_Pricing::discount_key( $key ), true, 'edit' ) ),
							'desc_tip'    => true,
							'description' => __( 'Percentage off the regular price (or current price, depending on the settings). Only used when no fixed price is set.', 'woo-wholesale' ),
						)
					);
					WWPro_Tiers_Field::render(
						'_wwpro_tiers[' . $key . ']',
						$product->get_meta( WWPro_Tiers::meta_key( $key ), true, 'edit' ),
						'wwpro-tiers-' . $key,
						true
					);
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save product level fields.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product( $product ) {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce).
		$prices    = isset( $_POST['_wwpro_price'] ) && is_array( $_POST['_wwpro_price'] ) ? wp_unslash( $_POST['_wwpro_price'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$discounts = isset( $_POST['_wwpro_discount'] ) && is_array( $_POST['_wwpro_discount'] ) ? wp_unslash( $_POST['_wwpro_discount'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tiers     = isset( $_POST['_wwpro_tiers'] ) && is_array( $_POST['_wwpro_tiers'] ) ? wp_unslash( $_POST['_wwpro_tiers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		if ( empty( $prices ) && empty( $discounts ) && empty( $tiers ) ) {
			return;
		}

		foreach ( WWPro_Roles::product_roles() as $key => $role ) {
			self::apply_values(
				$product,
				$key,
				isset( $prices[ $key ] ) ? $prices[ $key ] : null,
				isset( $discounts[ $key ] ) ? $discounts[ $key ] : null
			);

			if ( array_key_exists( $key, $tiers ) ) {
				$set = WWPro_Tiers_Field::from_request( $tiers, $key );
				if ( empty( $set['rows'] ) && 'no' === $set['enabled'] ) {
					$product->delete_meta_data( WWPro_Tiers::meta_key( $key ) );
				} else {
					$product->update_meta_data( WWPro_Tiers::meta_key( $key ), $set );
				}
			} elseif ( ! empty( $tiers ) ) {
				$product->delete_meta_data( WWPro_Tiers::meta_key( $key ) );
			}
		}
	}

	/**
	 * Apply price / discount values to a product object (not saved here).
	 *
	 * @param WC_Product  $product  Product.
	 * @param string      $key      Role key.
	 * @param string|null $price    Raw price or null (field absent).
	 * @param string|null $discount Raw discount or null (field absent).
	 */
	public static function apply_values( $product, $key, $price, $discount ) {
		WWPro_Pricing::apply_values( $product, $key, $price, $discount );
	}

	/**
	 * Fields inside each variation panel.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		$roles = WWPro_Roles::product_roles();
		if ( empty( $roles ) ) {
			return;
		}

		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}

		$symbol = get_woocommerce_currency_symbol();

		echo '<div class="wwpro-variation-fields"><p class="form-row form-row-full wwpro-heading"><strong>' . esc_html__( 'Wholesale prices', 'woo-wholesale' ) . '</strong></p>';

		foreach ( $roles as $key => $role ) {
			woocommerce_wp_text_input(
				array(
					'id'            => '_wwpro_variation_price_' . $key . '_' . $loop,
					'name'          => '_wwpro_variation_price[' . $key . '][' . $loop . ']',
					/* translators: 1: role name, 2: currency symbol */
					'label'         => sprintf( __( '%1$s price (%2$s)', 'woo-wholesale' ), $role['name'], $symbol ),
					'data_type'     => 'price',
					'wrapper_class' => 'form-row form-row-first',
					'value'         => wc_format_localized_price( $product->get_meta( WWPro_Pricing::price_key( $key ), true, 'edit' ) ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'            => '_wwpro_variation_discount_' . $key . '_' . $loop,
					'name'          => '_wwpro_variation_discount[' . $key . '][' . $loop . ']',
					/* translators: %s: role name */
					'label'         => sprintf( __( '%s discount (%%)', 'woo-wholesale' ), $role['name'] ),
					'data_type'     => 'decimal',
					'wrapper_class' => 'form-row form-row-last',
					'value'         => wc_format_localized_decimal( $product->get_meta( WWPro_Pricing::discount_key( $key ), true, 'edit' ) ),
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Save variation fields.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @param int                  $i         Loop index.
	 */
	public static function save_variation( $variation, $i ) {
		if ( ! $variation instanceof WC_Product || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations nonce).
		$prices    = isset( $_POST['_wwpro_variation_price'] ) && is_array( $_POST['_wwpro_variation_price'] ) ? wp_unslash( $_POST['_wwpro_variation_price'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$discounts = isset( $_POST['_wwpro_variation_discount'] ) && is_array( $_POST['_wwpro_variation_discount'] ) ? wp_unslash( $_POST['_wwpro_variation_discount'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		foreach ( WWPro_Roles::product_roles() as $key => $role ) {
			self::apply_values(
				$variation,
				$key,
				isset( $prices[ $key ][ $i ] ) ? $prices[ $key ][ $i ] : null,
				isset( $discounts[ $key ][ $i ] ) ? $discounts[ $key ][ $i ] : null
			);
		}
	}

	/**
	 * Bulk actions in the variations panel.
	 */
	public static function bulk_actions() {
		foreach ( WWPro_Roles::product_roles() as $key => $role ) {
			/* translators: %s: role name */
			echo '<optgroup label="' . esc_attr( sprintf( __( 'Wholesale: %s', 'woo-wholesale' ), $role['name'] ) ) . '">';
			echo '<option value="wwpro_price__' . esc_attr( $key ) . '">' . esc_html__( 'Set wholesale price', 'woo-wholesale' ) . '</option>';
			echo '<option value="wwpro_discount__' . esc_attr( $key ) . '">' . esc_html__( 'Set discount (%)', 'woo-wholesale' ) . '</option>';
			echo '<option value="wwpro_clear__' . esc_attr( $key ) . '">' . esc_html__( 'Clear wholesale price and discount', 'woo-wholesale' ) . '</option>';
			echo '</optgroup>';
		}
	}

	/**
	 * Handle our bulk actions (WooCommerce verified nonce + edit_products capability).
	 *
	 * @param string $bulk_action Action.
	 * @param array  $data        Data (value).
	 * @param int    $product_id  Parent product ID.
	 * @param array  $variations  Variation IDs.
	 */
	public static function handle_bulk_action( $bulk_action, $data, $product_id, $variations ) {
		if ( 0 !== strpos( $bulk_action, 'wwpro_' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		$parts = explode( '__', $bulk_action, 2 );
		if ( 2 !== count( $parts ) ) {
			return;
		}

		$type = substr( $parts[0], 6 );
		$key  = sanitize_key( $parts[1] );

		if ( ! WWPro_Roles::exists( $key ) || ! in_array( $type, array( 'price', 'discount', 'clear' ), true ) ) {
			return;
		}

		$value = isset( $data['value'] ) ? wc_clean( wp_unslash( $data['value'] ) ) : '';

		foreach ( (array) $variations as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation || (int) $variation->get_parent_id() !== (int) $product_id ) {
				continue;
			}

			if ( 'clear' === $type ) {
				$variation->delete_meta_data( WWPro_Pricing::price_key( $key ) );
				$variation->delete_meta_data( WWPro_Pricing::discount_key( $key ) );
			} elseif ( 'price' === $type ) {
				self::apply_values( $variation, $key, $value, null );
			} else {
				self::apply_values( $variation, $key, null, $value );
			}

			$variation->save();
		}

		WWPro_Settings::bump_cache_version();
	}
}
