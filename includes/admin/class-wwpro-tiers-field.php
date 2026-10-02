<?php
/**
 * Reusable admin field for tier sets (product editor and category forms).
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tier field renderer (static).
 */
class WWPro_Tiers_Field {

	/**
	 * Render the field.
	 *
	 * @param string $name  Base input name, e.g. "_wwpro_tiers[b2b]".
	 * @param array  $set   Tier set.
	 * @param string $id    DOM id prefix.
	 * @param bool   $compact Compact layout (product editor).
	 */
	public static function render( $name, $set, $id, $compact = false ) {
		$set  = WWPro_Tiers::sanitize( $set );
		$rows = $set['rows'];
		?>
		<div class="wwpro-tiers<?php echo $compact ? ' wwpro-tiers-compact' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[present]" value="1" />

			<?php // Same markup WooCommerce uses for its own checkboxes, so the label lines up with the price fields. ?>
			<p class="form-field wwpro-tiers-toggle">
				<label for="<?php echo esc_attr( $id ); ?>-enabled"><?php esc_html_e( 'Quantity discounts', 'woo-wholesale' ); ?></label>
				<input type="checkbox" class="checkbox" id="<?php echo esc_attr( $id ); ?>-enabled" name="<?php echo esc_attr( $name ); ?>[enabled]" value="yes" <?php checked( 'yes', $set['enabled'] ); ?> />
				<span class="description"><?php esc_html_e( 'Enable quantity discounts', 'woo-wholesale' ); ?></span>
			</p>

			<div class="wwpro-tiers-panel"<?php echo 'yes' === $set['enabled'] ? '' : ' style="display:none"'; ?>>
				<table class="wwpro-tiers-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From quantity', 'woo-wholesale' ); ?></th>
							<th><?php esc_html_e( 'Discount (%)', 'woo-wholesale' ); ?></th>
							<th>
								<?php
								printf(
									/* translators: %s: currency symbol */
									esc_html__( 'or fixed unit price (%s)', 'woo-wholesale' ),
									esc_html( get_woocommerce_currency_symbol() )
								);
								?>
							</th>
							<th class="wwpro-tiers-actions"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $i => $row ) : ?>
							<?php self::row( $name, $i, $row ); ?>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4">
								<button type="button" class="button wwpro-tier-add"><?php esc_html_e( 'Add tier', 'woo-wholesale' ); ?></button>
								<span class="description"><?php esc_html_e( 'The percentage is taken off the wholesale unit price of this role. A fixed unit price takes precedence over the percentage.', 'woo-wholesale' ); ?></span>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
			<script type="text/template" class="wwpro-tier-template">
				<?php self::row( $name, '__i__', array( 'qty' => '', 'discount' => '', 'price' => '' ) ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Render one row.
	 *
	 * @param string     $name Base name.
	 * @param int|string $i    Row index or placeholder.
	 * @param array      $row  Row data.
	 */
	private static function row( $name, $i, $row ) {
		$base = $name . '[rows][' . $i . ']';
		?>
		<tr class="wwpro-tier-row">
			<td><input type="number" min="1" step="1" class="small-text" name="<?php echo esc_attr( $base ); ?>[qty]" value="<?php echo esc_attr( $row['qty'] ); ?>" placeholder="10" /></td>
			<td><input type="text" class="wc_input_decimal small-text" name="<?php echo esc_attr( $base ); ?>[discount]" value="<?php echo esc_attr( '' === $row['discount'] ? '' : wc_format_localized_decimal( $row['discount'] ) ); ?>" placeholder="5" /></td>
			<td><input type="text" class="wc_input_price small-text" name="<?php echo esc_attr( $base ); ?>[price]" value="<?php echo esc_attr( '' === $row['price'] ? '' : wc_format_localized_price( $row['price'] ) ); ?>" placeholder="" /></td>
			<td class="wwpro-tiers-actions"><button type="button" class="button-link-delete wwpro-tier-remove" aria-label="<?php esc_attr_e( 'Remove tier', 'woo-wholesale' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Read a submitted tier set from a request array.
	 *
	 * @param array $request Unslashed request data (e.g. $_POST['_wwpro_tiers']).
	 * @param string $role   Role key.
	 * @return array Sanitized set.
	 */
	public static function from_request( $request, $role ) {
		if ( ! is_array( $request ) || ! isset( $request[ $role ] ) || ! is_array( $request[ $role ] ) ) {
			return WWPro_Tiers::empty_set();
		}
		return WWPro_Tiers::sanitize( $request[ $role ] );
	}
}
