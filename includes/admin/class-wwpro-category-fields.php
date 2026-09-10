<?php
/**
 * Product category fields (discount + tiers per role).
 *
 * WordPress verifies the term form nonce and the manage_product_terms
 * capability before the save hooks fire.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category fields (static).
 */
class WWPro_Category_Fields {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit' ), 10, 1 );
		add_action( 'created_product_cat', array( __CLASS__, 'save' ), 10, 1 );
		add_action( 'edited_product_cat', array( __CLASS__, 'save' ), 10, 1 );
	}

	/**
	 * Fields on the "add category" form.
	 */
	public static function render_add() {
		$roles = WWPro_Roles::all();
		if ( empty( $roles ) ) {
			return;
		}
		?>
		<div class="form-field wwpro-category-fields">
			<label><?php esc_html_e( 'Wholesale discounts', 'woo-wholesale' ); ?></label>
			<?php foreach ( $roles as $key => $role ) : ?>
				<p>
					<label for="wwpro-cat-discount-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $role['name'] ); ?> – <?php esc_html_e( 'Discount (%)', 'woo-wholesale' ); ?></label>
					<input type="text" class="wc_input_decimal" id="wwpro-cat-discount-<?php echo esc_attr( $key ); ?>" name="_wwpro_discount[<?php echo esc_attr( $key ); ?>]" value="" />
				</p>
				<?php WWPro_Tiers_Field::render( '_wwpro_tiers[' . $key . ']', array(), 'wwpro-cat-tiers-' . $key ); ?>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Applies to all products in this category (and its sub-categories) that have no wholesale price of their own. Enter 0 to explicitly disable the store-wide discount for this category.', 'woo-wholesale' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Fields on the "edit category" form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function render_edit( $term ) {
		$roles = WWPro_Roles::all();
		if ( empty( $roles ) ) {
			return;
		}
		?>
		<tr class="form-field wwpro-category-fields">
			<th scope="row"><?php esc_html_e( 'Wholesale discounts', 'woo-wholesale' ); ?></th>
			<td>
				<?php foreach ( $roles as $key => $role ) : ?>
					<?php $value = get_term_meta( $term->term_id, WWPro_Pricing::discount_key( $key ), true ); ?>
					<div class="wwpro-role-block">
						<h4 class="wwpro-role-title"><?php echo esc_html( $role['name'] ); ?> <code><?php echo esc_html( $key ); ?></code></h4>
						<p>
							<label for="wwpro-cat-discount-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Discount (%)', 'woo-wholesale' ); ?></label>
							<input type="text" class="wc_input_decimal small-text" id="wwpro-cat-discount-<?php echo esc_attr( $key ); ?>" name="_wwpro_discount[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( '' === $value ? '' : wc_format_localized_decimal( $value ) ); ?>" />
						</p>
						<?php
						WWPro_Tiers_Field::render(
							'_wwpro_tiers[' . $key . ']',
							get_term_meta( $term->term_id, WWPro_Tiers::meta_key( $key ), true ),
							'wwpro-cat-tiers-' . $key
						);
						?>
					</div>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Applies to all products in this category (and its sub-categories) that have no wholesale price of their own. Enter 0 to explicitly disable the store-wide discount for this category.', 'woo-wholesale' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save fields.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WordPress term forms.
		$discounts = isset( $_POST['_wwpro_discount'] ) && is_array( $_POST['_wwpro_discount'] ) ? wp_unslash( $_POST['_wwpro_discount'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tiers     = isset( $_POST['_wwpro_tiers'] ) && is_array( $_POST['_wwpro_tiers'] ) ? wp_unslash( $_POST['_wwpro_tiers'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		if ( null === $discounts && null === $tiers ) {
			return; // Quick edit or other form without our fields.
		}

		foreach ( WWPro_Roles::keys() as $key ) {
			if ( null !== $discounts ) {
				$pct = isset( $discounts[ $key ] ) ? WWPro_Roles::sanitize_percent( wc_clean( $discounts[ $key ] ) ) : '';
				if ( '' === $pct ) {
					delete_term_meta( $term_id, WWPro_Pricing::discount_key( $key ) );
				} else {
					update_term_meta( $term_id, WWPro_Pricing::discount_key( $key ), $pct );
				}
			}

			if ( null !== $tiers ) {
				$set = WWPro_Tiers_Field::from_request( $tiers, $key );
				if ( empty( $set['rows'] ) && 'no' === $set['enabled'] ) {
					delete_term_meta( $term_id, WWPro_Tiers::meta_key( $key ) );
				} else {
					update_term_meta( $term_id, WWPro_Tiers::meta_key( $key ), $set );
				}
			}
		}

		WWPro_Settings::bump_cache_version();
	}
}
