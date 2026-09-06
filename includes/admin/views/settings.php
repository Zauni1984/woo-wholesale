<?php
/**
 * Admin view: settings.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

$wwpro_settings = WWPro_Settings::all();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-settings-form">
	<?php wp_nonce_field( 'wwpro_save_settings' ); ?>
	<input type="hidden" name="action" value="wwpro_save_settings" />

	<h2><?php esc_html_e( 'Price calculation', 'woo-wholesale' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Discount base', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="radio" name="settings[discount_base]" value="regular" <?php checked( 'regular', $wwpro_settings['discount_base'] ); ?> /> <?php esc_html_e( 'Regular price (sale prices are ignored)', 'woo-wholesale' ); ?></label><br>
				<label><input type="radio" name="settings[discount_base]" value="current" <?php checked( 'current', $wwpro_settings['discount_base'] ); ?> /> <?php esc_html_e( 'Current price (sale price if a sale is running)', 'woo-wholesale' ); ?></label>
				<p class="description"><?php esc_html_e( 'Percentage discounts on product, category and store level are calculated from this price.', 'woo-wholesale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Product in several categories', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="radio" name="settings[multi_category]" value="highest" <?php checked( 'highest', $wwpro_settings['multi_category'] ); ?> /> <?php esc_html_e( 'The highest discount wins (best price for the customer)', 'woo-wholesale' ); ?></label><br>
				<label><input type="radio" name="settings[multi_category]" value="lowest" <?php checked( 'lowest', $wwpro_settings['multi_category'] ); ?> /> <?php esc_html_e( 'The lowest discount wins', 'woo-wholesale' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Price cap', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="checkbox" name="settings[never_above_retail]" value="yes" <?php checked( 'yes', $wwpro_settings['never_above_retail'] ); ?> /> <?php esc_html_e( 'A wholesale customer never pays more than the regular customer price (incl. sale)', 'woo-wholesale' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Quantity discounts', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="radio" name="settings[tier_qty_basis]" value="line" <?php checked( 'line', $wwpro_settings['tier_qty_basis'] ); ?> /> <?php esc_html_e( 'Quantity is counted per cart line (per variation)', 'woo-wholesale' ); ?></label><br>
				<label><input type="radio" name="settings[tier_qty_basis]" value="product" <?php checked( 'product', $wwpro_settings['tier_qty_basis'] ); ?> /> <?php esc_html_e( 'Quantities of all variations of a product are added up', 'woo-wholesale' ); ?></label><br>
				<label><input type="checkbox" name="settings[show_tier_table]" value="yes" <?php checked( 'yes', $wwpro_settings['show_tier_table'] ); ?> /> <?php esc_html_e( 'Show the quantity discount table on the product page', 'woo-wholesale' ); ?></label>
				<p class="description"><?php esc_html_e( 'You can also place the table with the shortcode [wwpro_tiers].', 'woo-wholesale' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Display', 'woo-wholesale' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Sale badge', 'woo-wholesale' ); ?></th>
			<td><label><input type="checkbox" name="settings[hide_sale_badge]" value="yes" <?php checked( 'yes', $wwpro_settings['hide_sale_badge'] ); ?> /> <?php esc_html_e( 'Hide the "Sale!" badge for products with a wholesale price', 'woo-wholesale' ); ?></label></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Secondary price', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="checkbox" name="settings[secondary_price_in_cart]" value="yes" <?php checked( 'yes', $wwpro_settings['secondary_price_in_cart'] ); ?> /> <?php esc_html_e( 'Also show the secondary price in cart and checkout', 'woo-wholesale' ); ?></label>
				<p>
					<label for="wwpro-net-label"><?php esc_html_e( 'Label for net price', 'woo-wholesale' ); ?></label><br>
					<input type="text" id="wwpro-net-label" name="settings[secondary_net_label]" class="regular-text" value="<?php echo esc_attr( $wwpro_settings['secondary_net_label'] ); ?>" />
				</p>
				<p>
					<label for="wwpro-gross-label"><?php esc_html_e( 'Label for gross price', 'woo-wholesale' ); ?></label><br>
					<input type="text" id="wwpro-gross-label" name="settings[secondary_gross_label]" class="regular-text" value="<?php echo esc_attr( $wwpro_settings['secondary_gross_label'] ); ?>" />
				</p>
				<p class="description"><?php esc_html_e( '%s is replaced with the formatted amount.', 'woo-wholesale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Role badge', 'woo-wholesale' ); ?></th>
			<td><label><input type="checkbox" name="settings[show_role_badge]" value="yes" <?php checked( 'yes', $wwpro_settings['show_role_badge'] ); ?> /> <?php esc_html_e( 'Show a small "Your … price" note next to wholesale prices', 'woo-wholesale' ); ?></label></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Data', 'woo-wholesale' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Uninstall', 'woo-wholesale' ); ?></th>
			<td><label><input type="checkbox" name="settings[delete_data_on_uninstall]" value="yes" <?php checked( 'yes', $wwpro_settings['delete_data_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete all wholesale roles, prices and settings when the plugin is deleted', 'woo-wholesale' ); ?></label></td>
		</tr>
	</table>

	<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'woo-wholesale' ); ?></button></p>
</form>
