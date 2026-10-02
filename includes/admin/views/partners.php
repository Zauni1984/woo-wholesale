<?php
/**
 * Admin view: partner shops.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

$wwpro_roles      = WWPro_Roles::all();
$wwpro_partners   = WWPro_Partners::all();
$wwpro_new_keys   = WWPro_Admin_Sync::take_new_keys();
$wwpro_action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wwpro_edit_id    = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wwpro_editing    = '' !== $wwpro_edit_id ? WWPro_Partners::get( $wwpro_edit_id ) : null;
$wwpro_is_new     = ( 'new' === $wwpro_action ) || ( '' !== $wwpro_edit_id && ! $wwpro_editing );
$wwpro_partner    = $wwpro_editing ? $wwpro_editing : WWPro_Partners::defaults();
$wwpro_show_form  = $wwpro_is_new || $wwpro_editing;
$wwpro_categories = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	)
);
$wwpro_categories = is_wp_error( $wwpro_categories ) ? array() : $wwpro_categories;
?>

<h2><?php esc_html_e( 'Partner shops', 'woo-wholesale' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'A partner shop receives products and the wholesale prices of one role from this shop. WooCommerce partners install the companion plugin "Woo Wholesale Partner" and pull the data; Shopify partners are written to directly through the Shopify Admin API.', 'woo-wholesale' ); ?>
</p>

<?php if ( empty( $wwpro_roles ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %s: link to the roles tab */
				wp_kses_post( __( 'Create a wholesale role first - a partner shop always receives the prices of one role. %s', 'woo-wholesale' ) ),
				'<a href="' . esc_url( WWPro_Admin::url( 'roles', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Create a role', 'woo-wholesale' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php
	return;
endif;
?>

<?php foreach ( $wwpro_new_keys as $wwpro_key_id => $wwpro_key_value ) : ?>
	<div class="notice notice-success wwpro-key-notice">
		<p><strong><?php esc_html_e( 'Key for the partner shop', 'woo-wholesale' ); ?></strong></p>
		<p><code class="wwpro-key"><?php echo esc_html( $wwpro_key_value ); ?></code></p>
		<p class="description"><?php esc_html_e( 'Copy this key into the partner plugin now. It is shown this one time only and cannot be read again afterwards.', 'woo-wholesale' ); ?></p>
	</div>
<?php endforeach; ?>

<?php if ( $wwpro_show_form ) : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-partner-form">
		<?php wp_nonce_field( 'wwpro_save_partner' ); ?>
		<input type="hidden" name="action" value="wwpro_save_partner" />
		<input type="hidden" name="is_new" value="<?php echo $wwpro_is_new ? '1' : '0'; ?>" />
		<?php if ( ! $wwpro_is_new ) : ?>
			<input type="hidden" name="partner[id]" value="<?php echo esc_attr( $wwpro_partner['id'] ); ?>" />
		<?php endif; ?>

		<h3><?php echo $wwpro_is_new ? esc_html__( 'New partner shop', 'woo-wholesale' ) : esc_html__( 'Edit partner shop', 'woo-wholesale' ); ?></h3>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wwpro-partner-name"><?php esc_html_e( 'Name', 'woo-wholesale' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wwpro-partner-name" name="partner[name]" value="<?php echo esc_attr( $wwpro_partner['name'] ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwpro-partner-type"><?php esc_html_e( 'Shop system', 'woo-wholesale' ); ?></label></th>
				<td>
					<select id="wwpro-partner-type" name="partner[type]" class="wwpro-partner-type">
						<?php foreach ( WWPro_Partners::types() as $wwpro_type_key => $wwpro_type_label ) : ?>
							<option value="<?php echo esc_attr( $wwpro_type_key ); ?>" <?php selected( $wwpro_partner['type'], $wwpro_type_key ); ?>><?php echo esc_html( $wwpro_type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwpro-partner-role"><?php esc_html_e( 'Prices of this role', 'woo-wholesale' ); ?></label></th>
				<td>
					<select id="wwpro-partner-role" name="partner[role]" required>
						<option value=""><?php esc_html_e( '— please choose —', 'woo-wholesale' ); ?></option>
						<?php foreach ( $wwpro_roles as $wwpro_role_key => $wwpro_role ) : ?>
							<option value="<?php echo esc_attr( $wwpro_role_key ); ?>" <?php selected( $wwpro_partner['role'], $wwpro_role_key ); ?>><?php echo esc_html( $wwpro_role['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The partner shop pays the price of this role. Category and store-wide discounts of the role are included.', 'woo-wholesale' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwpro-partner-status"><?php esc_html_e( 'Status', 'woo-wholesale' ); ?></label></th>
				<td>
					<select id="wwpro-partner-status" name="partner[status]">
						<option value="active" <?php selected( $wwpro_partner['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'woo-wholesale' ); ?></option>
						<option value="paused" <?php selected( $wwpro_partner['status'], 'paused' ); ?>><?php esc_html_e( 'Paused (no sync)', 'woo-wholesale' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wwpro-type-woocommerce">
				<th scope="row"><label for="wwpro-partner-url"><?php esc_html_e( 'Shop URL', 'woo-wholesale' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="wwpro-partner-url" name="partner[site_url]" value="<?php echo esc_attr( $wwpro_partner['site_url'] ); ?>" placeholder="https://partner.example" />
					<p class="description"><?php esc_html_e( 'Optional, only used as a link in this list.', 'woo-wholesale' ); ?></p>
				</td>
			</tr>
			<tr class="wwpro-type-shopify">
				<th scope="row"><label for="wwpro-partner-shop"><?php esc_html_e( 'Shopify domain', 'woo-wholesale' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wwpro-partner-shop" name="partner[shop_domain]" value="<?php echo esc_attr( $wwpro_partner['shop_domain'] ); ?>" placeholder="partner.myshopify.com" />
				</td>
			</tr>
			<tr class="wwpro-type-shopify">
				<th scope="row"><label for="wwpro-partner-token"><?php esc_html_e( 'Admin API access token', 'woo-wholesale' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="wwpro-partner-token" name="partner[token]" value="" autocomplete="new-password" placeholder="shpat_…" />
					<p class="description">
						<?php esc_html_e( 'Token of a custom app in the Shopify admin with the scopes write_products and read_products. It is stored encrypted.', 'woo-wholesale' ); ?>
						<?php if ( '' !== $wwpro_partner['token_hint'] ) : ?>
							<br><?php printf( esc_html__( 'Stored: %s. Leave empty to keep it.', 'woo-wholesale' ), '<code>' . esc_html( $wwpro_partner['token_hint'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Product selection', 'woo-wholesale' ); ?></th>
				<td>
					<label for="wwpro-partner-cats"><?php esc_html_e( 'Only these categories (nothing selected = whole catalogue)', 'woo-wholesale' ); ?></label><br />
					<select id="wwpro-partner-cats" name="partner[categories][]" multiple size="8" class="wwpro-partner-cats">
						<?php foreach ( $wwpro_categories as $wwpro_cat ) : ?>
							<option value="<?php echo esc_attr( $wwpro_cat->term_id ); ?>" <?php selected( in_array( (int) $wwpro_cat->term_id, array_map( 'intval', (array) $wwpro_partner['categories'] ), true ) ); ?>>
								<?php echo esc_html( $wwpro_cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Subcategories of a selected category are always included.', 'woo-wholesale' ); ?></p>
					<p>
						<label><input type="checkbox" name="partner[in_stock_only]" value="yes" <?php checked( $wwpro_partner['in_stock_only'], 'yes' ); ?> /> <?php esc_html_e( 'Only send products that are in stock', 'woo-wholesale' ); ?></label><br />
						<label><input type="checkbox" name="partner[sync_stock]" value="yes" <?php checked( $wwpro_partner['sync_stock'], 'yes' ); ?> /> <?php esc_html_e( 'Also send stock quantities', 'woo-wholesale' ); ?></label>
					</p>
				</td>
			</tr>
			<tr class="wwpro-type-woocommerce">
				<th scope="row"><?php esc_html_e( 'The partner may not change', 'woo-wholesale' ); ?></th>
				<td>
					<?php foreach ( WWPro_Partners::lockable_fields() as $wwpro_field => $wwpro_label ) : ?>
						<label style="display:block">
							<input type="checkbox" name="partner[lock_fields][]" value="<?php echo esc_attr( $wwpro_field ); ?>" <?php checked( in_array( $wwpro_field, (array) $wwpro_partner['lock_fields'], true ) ); ?> />
							<?php echo esc_html( $wwpro_label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'The partner plugin blocks these fields on every product it received from here - in the product editor and through its REST API. On the next sync the value from this shop is restored.', 'woo-wholesale' ); ?></p>
				</td>
			</tr>
			<tr class="wwpro-type-woocommerce">
				<th scope="row"><?php esc_html_e( 'Price markup of the partner', 'woo-wholesale' ); ?></th>
				<td>
					<label><input type="checkbox" name="partner[allow_markup]" value="yes" <?php checked( $wwpro_partner['allow_markup'], 'yes' ); ?> /> <?php esc_html_e( 'The partner may set its own sales price as a markup on the wholesale price', 'woo-wholesale' ); ?></label>
					<p>
						<label><?php esc_html_e( 'Lowest', 'woo-wholesale' ); ?> <input type="text" class="small-text" name="partner[markup_min]" value="<?php echo esc_attr( $wwpro_partner['markup_min'] ); ?>" /> %</label>
						<label><?php esc_html_e( 'Highest', 'woo-wholesale' ); ?> <input type="text" class="small-text" name="partner[markup_max]" value="<?php echo esc_attr( $wwpro_partner['markup_max'] ); ?>" /> %</label>
						<label><?php esc_html_e( 'Preset', 'woo-wholesale' ); ?> <input type="text" class="small-text" name="partner[markup_default]" value="<?php echo esc_attr( $wwpro_partner['markup_default'] ); ?>" /> %</label>
					</p>
					<p class="description"><?php esc_html_e( 'A markup outside this range is refused in the partner shop. 0 % means the partner sells at the wholesale price. Enter a negative lowest value only if the partner may sell below it.', 'woo-wholesale' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo $wwpro_is_new ? esc_html__( 'Create partner shop', 'woo-wholesale' ) : esc_html__( 'Save partner shop', 'woo-wholesale' ); ?></button>
			<a class="button" href="<?php echo esc_url( WWPro_Admin::url( 'partners' ) ); ?>"><?php esc_html_e( 'Cancel', 'woo-wholesale' ); ?></a>
		</p>
	</form>

<?php else : ?>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( WWPro_Admin::url( 'partners', array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add partner shop', 'woo-wholesale' ); ?></a>
	</p>

	<?php if ( empty( $wwpro_partners ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No partner shop has been set up yet.', 'woo-wholesale' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped wwpro-partner-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Partner shop', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Role', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Products', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Last sync', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Connection', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'woo-wholesale' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $wwpro_partners as $wwpro_id => $wwpro_row ) : ?>
				<?php
				$wwpro_types   = WWPro_Partners::types();
				$wwpro_stats   = is_array( $wwpro_row['stats'] ) ? $wwpro_row['stats'] : array();
				$wwpro_missing = isset( $wwpro_stats['missing_images'] ) ? (int) $wwpro_stats['missing_images'] : 0;
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $wwpro_row['name'] ); ?></strong><br />
						<span class="description"><?php echo esc_html( isset( $wwpro_types[ $wwpro_row['type'] ] ) ? $wwpro_types[ $wwpro_row['type'] ] : $wwpro_row['type'] ); ?></span>
						<?php if ( 'paused' === $wwpro_row['status'] ) : ?>
							<br /><span class="wwpro-badge-paused"><?php esc_html_e( 'paused', 'woo-wholesale' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $wwpro_row['site_url'] ) : ?>
							<br /><a href="<?php echo esc_url( $wwpro_row['site_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $wwpro_row['site_url'], PHP_URL_HOST ) ); ?></a>
						<?php elseif ( '' !== $wwpro_row['shop_domain'] ) : ?>
							<br /><code><?php echo esc_html( $wwpro_row['shop_domain'] ); ?></code>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( WWPro_Roles::label( $wwpro_row['role'] ) ); ?></td>
					<td>
						<?php echo esc_html( number_format_i18n( isset( $wwpro_stats['products'] ) ? (int) $wwpro_stats['products'] : 0 ) ); ?>
						<?php if ( $wwpro_missing > 0 ) : ?>
							<br /><span class="wwpro-col-note">
								<?php
								printf(
									/* translators: %d: number of products */
									esc_html__( '%d without image', 'woo-wholesale' ),
									(int) $wwpro_missing
								);
								?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						echo $wwpro_row['last_sync']
							? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $wwpro_row['last_sync'] ) )
							: '&ndash;';
						?>
					</td>
					<td>
						<?php if ( 'woocommerce' === $wwpro_row['type'] ) : ?>
							<?php if ( '' !== $wwpro_row['key_hint'] ) : ?>
								<code><?php echo esc_html( $wwpro_row['key_hint'] ); ?></code>
							<?php else : ?>
								<span class="wwpro-badge-paused"><?php esc_html_e( 'no key', 'woo-wholesale' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<?php echo '' !== $wwpro_row['token_hint'] ? '<code>' . esc_html( $wwpro_row['token_hint'] ) . '</code>' : '&ndash;'; ?>
						<?php endif; ?>
						<?php if ( '' !== $wwpro_row['last_error'] ) : ?>
							<br /><span class="wwpro-error-note"><?php echo esc_html( $wwpro_row['last_error'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( WWPro_Admin::url( 'partners', array( 'edit' => $wwpro_id ) ) ); ?>"><?php esc_html_e( 'Edit', 'woo-wholesale' ); ?></a>

						<?php if ( 'woocommerce' === $wwpro_row['type'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-inline-form wwpro-key-form">
								<?php wp_nonce_field( 'wwpro_partner_key' ); ?>
								<input type="hidden" name="action" value="wwpro_partner_key" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $wwpro_id ); ?>" />
								<input type="hidden" name="mode" value="issue" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'New key', 'woo-wholesale' ); ?></button>
							</form>
							<?php if ( '' !== $wwpro_row['key_hint'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-inline-form wwpro-key-form">
									<?php wp_nonce_field( 'wwpro_partner_key' ); ?>
									<input type="hidden" name="action" value="wwpro_partner_key" />
									<input type="hidden" name="id" value="<?php echo esc_attr( $wwpro_id ); ?>" />
									<input type="hidden" name="mode" value="revoke" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke key', 'woo-wholesale' ); ?></button>
								</form>
							<?php endif; ?>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-inline-form">
								<?php wp_nonce_field( 'wwpro_test_shopify' ); ?>
								<input type="hidden" name="action" value="wwpro_test_shopify" />
								<input type="hidden" name="id" value="<?php echo esc_attr( $wwpro_id ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Test connection', 'woo-wholesale' ); ?></button>
							</form>
							<button type="button" class="button button-small wwpro-shopify-sync" data-partner="<?php echo esc_attr( $wwpro_id ); ?>"><?php esc_html_e( 'Start sync', 'woo-wholesale' ); ?></button>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpro-inline-form wwpro-delete-partner-form">
							<?php wp_nonce_field( 'wwpro_delete_partner' ); ?>
							<input type="hidden" name="action" value="wwpro_delete_partner" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $wwpro_id ); ?>" />
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'woo-wholesale' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div id="wwpro-shopify-progress" class="wwpro-progress" hidden>
			<div class="wwpro-progress-bar"><span></span></div>
			<p class="wwpro-progress-label"></p>
			<pre class="wwpro-import-log"></pre>
		</div>

		<h3><?php esc_html_e( 'How a WooCommerce partner connects', 'woo-wholesale' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Install and activate the plugin "Woo Wholesale Partner" in the partner shop.', 'woo-wholesale' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: URL of this shop */
					esc_html__( 'Enter the address of this shop there: %s', 'woo-wholesale' ),
					'<code>' . esc_html( home_url( '/' ) ) . '</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Paste the key of the partner into the partner plugin and start the first sync.', 'woo-wholesale' ); ?></li>
		</ol>
	<?php endif; ?>

<?php endif; ?>
