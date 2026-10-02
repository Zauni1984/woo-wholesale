<?php
/**
 * Admin view: import from WooCommerce Wholesale Prices.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

$wwpro_detect = WWPro_Importer::detect();
$wwpro_roles  = WWPro_Roles::all();
$wwpro_last   = (int) get_option( 'wwpro_last_import', 0 );
?>
<h2><?php esc_html_e( 'Import from WooCommerce Wholesale Prices', 'woo-wholesale' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Copies wholesale prices of products and variations, category discounts and store-wide discounts from the plugin "WooCommerce Wholesale Prices" (Wholesale Suite) into this plugin. The original data is not changed.', 'woo-wholesale' ); ?>
</p>

<?php if ( $wwpro_last ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: date */
			esc_html__( 'Last import: %s', 'woo-wholesale' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wwpro_last ) )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( ! $wwpro_detect['available'] ) : ?>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'No data from WooCommerce Wholesale Prices was found in this database.', 'woo-wholesale' ); ?></p></div>
<?php else : ?>

	<form id="wwpro-import-form" onsubmit="return false;">
		<table class="widefat striped wwpro-import-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Found role', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Users', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Product prices', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Category discounts', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Store-wide', 'woo-wholesale' ); ?></th>
					<th><?php esc_html_e( 'Import into', 'woo-wholesale' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $wwpro_detect['roles'] as $wwpro_key => $wwpro_found ) : ?>
				<tr data-src="<?php echo esc_attr( $wwpro_key ); ?>">
					<td>
						<strong><?php echo esc_html( $wwpro_found['name'] ); ?></strong><br>
						<code><?php echo esc_html( $wwpro_key ); ?></code>
						<?php if ( $wwpro_found['already_managed'] ) : ?>
							<br><span class="description"><?php esc_html_e( 'already a wholesale role of this plugin', 'woo-wholesale' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $wwpro_found['users'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wwpro_found['products'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wwpro_found['categories'] ) ); ?></td>
					<td><?php echo null === $wwpro_found['global_discount'] ? '&ndash;' : esc_html( wc_format_localized_decimal( $wwpro_found['global_discount'] ) . ' %' ); ?></td>
					<td>
						<select class="wwpro-import-target" name="target[<?php echo esc_attr( $wwpro_key ); ?>]">
							<option value="same">
								<?php
								if ( $wwpro_found['already_managed'] ) {
									esc_html_e( 'Same role (already configured)', 'woo-wholesale' );
								} else {
									/* translators: %s: role key */
									printf( esc_html__( 'New role with the same key "%s" (users keep their role)', 'woo-wholesale' ), esc_html( $wwpro_key ) );
								}
								?>
							</option>
							<?php foreach ( $wwpro_roles as $wwpro_target_key => $wwpro_target ) : ?>
								<?php if ( $wwpro_target_key === $wwpro_key ) { continue; } ?>
								<option value="<?php echo esc_attr( $wwpro_target_key ); ?>">
									<?php
									/* translators: 1: role name, 2: role key */
									printf( esc_html__( 'Existing role: %1$s (%2$s)', 'woo-wholesale' ), esc_html( $wwpro_target['name'] ), esc_html( $wwpro_target_key ) );
									?>
								</option>
							<?php endforeach; ?>
							<option value="skip"><?php esc_html_e( 'Skip', 'woo-wholesale' ); ?></option>
						</select>
						<input type="hidden" class="wwpro-import-name" value="<?php echo esc_attr( $wwpro_found['name'] ); ?>" />
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<label><input type="checkbox" id="wwpro-import-overwrite" /> <?php esc_html_e( 'Overwrite wholesale prices and discounts that already exist in this plugin', 'woo-wholesale' ); ?></label><br>
			<label><input type="checkbox" id="wwpro-import-users" checked /> <?php esc_html_e( 'When importing into an existing role: move the users to that role', 'woo-wholesale' ); ?></label>
		</p>

		<p class="description">
			<?php esc_html_e( 'Not imported: per-product minimum order quantities and product visibility settings of Wholesale Suite, as this plugin does not have these features.', 'woo-wholesale' ); ?>
		</p>

		<p>
			<button type="button" class="button button-primary" id="wwpro-import-start"><?php esc_html_e( 'Start import', 'woo-wholesale' ); ?></button>
		</p>

		<div id="wwpro-import-progress" class="wwpro-progress" hidden>
			<div class="wwpro-progress-bar"><span></span></div>
			<p class="wwpro-progress-label"></p>
			<pre class="wwpro-import-log"></pre>
		</div>
	</form>

<?php endif; ?>

<?php if ( $wwpro_detect['plugin_active'] ) : ?>
	<p>
		<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php?s=wholesale' ) ); ?>"><?php esc_html_e( 'Go to the plugins page to deactivate WooCommerce Wholesale Prices after the import', 'woo-wholesale' ); ?></a>
	</p>
<?php endif; ?>
