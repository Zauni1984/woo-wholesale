<?php
/**
 * Admin view: roles.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

$wwpro_roles    = WWPro_Roles::all();
$wwpro_edit_key = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wwpro_is_new   = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wwpro_editing  = $wwpro_edit_key ? WWPro_Roles::get( $wwpro_edit_key ) : null;
$wwpro_form     = $wwpro_is_new ? WWPro_Roles::defaults() : $wwpro_editing;
?>

<div class="wwpro-columns">
	<div class="wwpro-main">
		<h2><?php esc_html_e( 'Wholesale roles', 'woo-wholesale' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Every wholesale role is a regular WordPress user role. Assign it to a customer under Users → Edit user. The first matching role in this list wins if a user has several wholesale roles.', 'woo-wholesale' ); ?>
		</p>

		<?php if ( empty( $wwpro_roles ) ) : ?>
			<p><em><?php esc_html_e( 'No wholesale roles yet. Create one on the right or import roles from WooCommerce Wholesale Prices.', 'woo-wholesale' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped wwpro-roles-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Key', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Users', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Store-wide discount', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Secondary price', 'woo-wholesale' ); ?></th>
						<th><?php esc_html_e( 'Product fields', 'woo-wholesale' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $wwpro_roles as $wwpro_key => $wwpro_role ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $wwpro_role['name'] ); ?></strong>
							<?php if ( $wwpro_role['description'] ) : ?>
								<br><span class="description"><?php echo esc_html( $wwpro_role['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $wwpro_key ); ?></code></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'users.php?role=' . $wwpro_key ) ); ?>"><?php echo esc_html( number_format_i18n( WWPro_Roles::user_count( $wwpro_key ) ) ); ?></a>
						</td>
						<td><?php echo '' === $wwpro_role['global_discount'] ? '&ndash;' : esc_html( wc_format_localized_decimal( $wwpro_role['global_discount'] ) . ' %' ); ?></td>
						<td>
							<?php
							$wwpro_secondary = array(
								'none'  => __( 'none', 'woo-wholesale' ),
								'net'   => __( 'net price', 'woo-wholesale' ),
								'gross' => __( 'gross price', 'woo-wholesale' ),
							);
							echo esc_html( isset( $wwpro_secondary[ $wwpro_role['secondary_price'] ] ) ? $wwpro_secondary[ $wwpro_role['secondary_price'] ] : $wwpro_role['secondary_price'] );
							?>
						</td>
						<td><?php echo 'yes' === $wwpro_role['show_in_product'] ? esc_html__( 'yes', 'woo-wholesale' ) : esc_html__( 'no', 'woo-wholesale' ); ?></td>
						<td class="wwpro-actions">
							<a class="button button-small" href="<?php echo esc_url( WWPro_Admin::url( 'roles', array( 'edit' => $wwpro_key ) ) ); ?>"><?php esc_html_e( 'Edit', 'woo-wholesale' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-inline-form wwpro-delete-form">
								<?php wp_nonce_field( 'wwpro_delete_role' ); ?>
								<input type="hidden" name="action" value="wwpro_delete_role" />
								<input type="hidden" name="key" value="<?php echo esc_attr( $wwpro_key ); ?>" />
								<label class="wwpro-delete-data"><input type="checkbox" name="delete_data" value="1" /> <?php esc_html_e( 'also delete prices', 'woo-wholesale' ); ?></label>
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'woo-wholesale' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( WWPro_Admin::url( 'roles', array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add wholesale role', 'woo-wholesale' ); ?></a>
		</p>
	</div>

	<?php if ( $wwpro_form ) : ?>
	<div class="wwpro-side">
		<div class="wwpro-card">
			<h2><?php echo $wwpro_is_new ? esc_html__( 'New wholesale role', 'woo-wholesale' ) : esc_html__( 'Edit wholesale role', 'woo-wholesale' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wwpro_save_role' ); ?>
				<input type="hidden" name="action" value="wwpro_save_role" />
				<input type="hidden" name="is_new" value="<?php echo $wwpro_is_new ? '1' : ''; ?>" />

				<table class="form-table wwpro-form-table">
					<tr>
						<th><label for="wwpro-role-name"><?php esc_html_e( 'Name', 'woo-wholesale' ); ?></label></th>
						<td><input type="text" id="wwpro-role-name" name="role[name]" class="regular-text" required value="<?php echo esc_attr( $wwpro_form['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. B2B customer', 'woo-wholesale' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="wwpro-role-key"><?php esc_html_e( 'Key', 'woo-wholesale' ); ?></label></th>
						<td>
							<?php if ( $wwpro_is_new ) : ?>
								<input type="text" id="wwpro-role-key" name="role[key]" class="regular-text" required pattern="[a-z][a-z0-9_]{1,39}" value="" placeholder="b2b_customer" />
								<p class="description"><?php esc_html_e( 'Lowercase letters, digits and underscores. Cannot be changed later. To reuse an existing WordPress role (for example wholesale_customer), enter its key here – users keep their assignment.', 'woo-wholesale' ); ?></p>
							<?php else : ?>
								<code><?php echo esc_html( $wwpro_form['key'] ); ?></code>
								<input type="hidden" name="role[key]" value="<?php echo esc_attr( $wwpro_form['key'] ); ?>" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="wwpro-role-description"><?php esc_html_e( 'Description', 'woo-wholesale' ); ?></label></th>
						<td><textarea id="wwpro-role-description" name="role[description]" class="large-text" rows="2"><?php echo esc_textarea( $wwpro_form['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="wwpro-role-global"><?php esc_html_e( 'Store-wide discount (%)', 'woo-wholesale' ); ?></label></th>
						<td>
							<input type="text" id="wwpro-role-global" name="role[global_discount]" class="small-text wc_input_decimal" value="<?php echo esc_attr( '' === $wwpro_form['global_discount'] ? '' : wc_format_localized_decimal( $wwpro_form['global_discount'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Applies to every product without a product or category rule. Leave empty for none.', 'woo-wholesale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="wwpro-role-secondary"><?php esc_html_e( 'Secondary price', 'woo-wholesale' ); ?></label></th>
						<td>
							<select id="wwpro-role-secondary" name="role[secondary_price]">
								<option value="none" <?php selected( 'none', $wwpro_form['secondary_price'] ); ?>><?php esc_html_e( 'None – show only the wholesale price', 'woo-wholesale' ); ?></option>
								<option value="net" <?php selected( 'net', $wwpro_form['secondary_price'] ); ?>><?php esc_html_e( 'Show the net price next to it', 'woo-wholesale' ); ?></option>
								<option value="gross" <?php selected( 'gross', $wwpro_form['secondary_price'] ); ?>><?php esc_html_e( 'Show the gross price next to it', 'woo-wholesale' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wwpro-role-tax"><?php esc_html_e( 'Price display', 'woo-wholesale' ); ?></label></th>
						<td>
							<select id="wwpro-role-tax" name="role[tax_display]">
								<option value="" <?php selected( '', $wwpro_form['tax_display'] ); ?>><?php esc_html_e( 'Shop default', 'woo-wholesale' ); ?></option>
								<option value="incl" <?php selected( 'incl', $wwpro_form['tax_display'] ); ?>><?php esc_html_e( 'Including tax (gross)', 'woo-wholesale' ); ?></option>
								<option value="excl" <?php selected( 'excl', $wwpro_form['tax_display'] ); ?>><?php esc_html_e( 'Excluding tax (net)', 'woo-wholesale' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Overrides the WooCommerce tax display setting in shop and cart for this role.', 'woo-wholesale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="wwpro-role-min"><?php esc_html_e( 'Minimum order amount', 'woo-wholesale' ); ?></label></th>
						<td>
							<input type="text" id="wwpro-role-min" name="role[min_order_amount]" class="small-text wc_input_price" value="<?php echo esc_attr( '' === $wwpro_form['min_order_amount'] ? '' : wc_format_localized_price( $wwpro_form['min_order_amount'] ) ); ?>" />
							<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
							<p class="description"><?php esc_html_e( 'Net cart subtotal required to check out. Leave empty for none.', 'woo-wholesale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Options', 'woo-wholesale' ); ?></th>
						<td>
							<label><input type="checkbox" name="role[show_in_product]" value="yes" <?php checked( 'yes', $wwpro_form['show_in_product'] ); ?> /> <?php esc_html_e( 'Show price and discount fields for this role in the product editor', 'woo-wholesale' ); ?></label><br>
							<label><input type="checkbox" name="role[show_tiers]" value="yes" <?php checked( 'yes', $wwpro_form['show_tiers'] ); ?> /> <?php esc_html_e( 'Show the quantity discount table on product pages', 'woo-wholesale' ); ?></label><br>
							<label><input type="checkbox" name="role[disable_coupons]" value="yes" <?php checked( 'yes', $wwpro_form['disable_coupons'] ); ?> /> <?php esc_html_e( 'Disable coupons for this role', 'woo-wholesale' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $wwpro_is_new ? esc_html__( 'Create role', 'woo-wholesale' ) : esc_html__( 'Save role', 'woo-wholesale' ); ?></button>
					<a class="button" href="<?php echo esc_url( WWPro_Admin::url( 'roles' ) ); ?>"><?php esc_html_e( 'Cancel', 'woo-wholesale' ); ?></a>
				</p>
			</form>
		</div>
	</div>
	<?php endif; ?>
</div>
