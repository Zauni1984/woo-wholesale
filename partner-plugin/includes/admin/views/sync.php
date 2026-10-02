<?php
/**
 * Admin view: run the sync.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

$wwpart_settings = WWPart_Settings::all();
$wwpart_summary  = is_array( $wwpart_settings['last_summary'] ) ? $wwpart_settings['last_summary'] : array();
$wwpart_managed  = WWPart_Product::count_managed();
?>

<h2><?php esc_html_e( 'Sync products', 'woo-wholesale-partner' ); ?></h2>

<?php if ( ! WWPart_Settings::is_connected() ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %s: link to the connection screen */
				wp_kses_post( __( 'Set up your supplier first: %s', 'woo-wholesale-partner' ) ),
				'<a href="' . esc_url( WWPart_Admin::url( 'connection' ) ) . '">' . esc_html__( 'Supplier', 'woo-wholesale-partner' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php
	return;
endif;
?>

<p class="description">
	<?php esc_html_e( 'A sync fetches categories, products, purchase prices and images from your supplier, and then calculates your sales prices from your markup. Products that already exist are updated; nothing you added yourself is touched.', 'woo-wholesale-partner' ); ?>
</p>

<p>
	<?php
	printf(
		/* translators: %d: number of products */
		esc_html__( 'Products from your supplier in this shop: %d', 'woo-wholesale-partner' ),
		(int) $wwpart_managed
	);
	?>
	<?php if ( ! empty( $wwpart_summary ) ) : ?>
		<br />
		<span class="description">
			<?php
			printf(
				/* translators: 1: created, 2: updated, 3: unchanged, 4: failed */
				esc_html__( 'Last run: %1$d new, %2$d updated, %3$d unchanged, %4$d failed.', 'woo-wholesale-partner' ),
				isset( $wwpart_summary['created'] ) ? (int) $wwpart_summary['created'] : 0,
				isset( $wwpart_summary['updated'] ) ? (int) $wwpart_summary['updated'] : 0,
				isset( $wwpart_summary['skipped'] ) ? (int) $wwpart_summary['skipped'] : 0,
				isset( $wwpart_summary['failed'] ) ? (int) $wwpart_summary['failed'] : 0
			);
			?>
		</span>
	<?php endif; ?>
</p>

<p>
	<button type="button" class="button button-primary" id="wwpart-sync-start"><?php esc_html_e( 'Start sync', 'woo-wholesale-partner' ); ?></button>
</p>

<div id="wwpart-sync-progress" class="wwpart-progress" hidden>
	<div class="wwpart-progress-bar"><span></span></div>
	<p class="wwpart-progress-label"></p>
	<pre class="wwpart-log"></pre>
</div>

<hr />

<h3><?php esc_html_e( 'Sync options', 'woo-wholesale-partner' ); ?></h3>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'wwpart_save_options' ); ?>
	<input type="hidden" name="action" value="wwpart_save_options" />

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wwpart-new-status"><?php esc_html_e( 'New products', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<select id="wwpart-new-status" name="options[new_status]">
					<option value="draft" <?php selected( $wwpart_settings['new_status'], 'draft' ); ?>><?php esc_html_e( 'Create as draft (you publish them yourself)', 'woo-wholesale-partner' ); ?></option>
					<option value="publish" <?php selected( $wwpart_settings['new_status'], 'publish' ); ?>><?php esc_html_e( 'Publish right away', 'woo-wholesale-partner' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Draft is the safer choice for the first sync: you can check prices and images before anything is in the shop.', 'woo-wholesale-partner' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wwpart-on-removed"><?php esc_html_e( 'Products no longer delivered', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<select id="wwpart-on-removed" name="options[on_removed]">
					<option value="draft" <?php selected( $wwpart_settings['on_removed'], 'draft' ); ?>><?php esc_html_e( 'Set to draft', 'woo-wholesale-partner' ); ?></option>
					<option value="trash" <?php selected( $wwpart_settings['on_removed'], 'trash' ); ?>><?php esc_html_e( 'Move to trash', 'woo-wholesale-partner' ); ?></option>
					<option value="ignore" <?php selected( $wwpart_settings['on_removed'], 'ignore' ); ?>><?php esc_html_e( 'Leave untouched', 'woo-wholesale-partner' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images', 'woo-wholesale-partner' ); ?></th>
			<td>
				<label><input type="checkbox" name="options[sync_images]" value="yes" <?php checked( $wwpart_settings['sync_images'], 'yes' ); ?> /> <?php esc_html_e( 'Download product images from the supplier', 'woo-wholesale-partner' ); ?></label><br />
				<label><input type="checkbox" name="options[auto_images]" value="yes" <?php checked( $wwpart_settings['auto_images'], 'yes' ); ?> /> <?php esc_html_e( 'Fetch missing images automatically in the background', 'woo-wholesale-partner' ); ?></label>
				<p class="description"><?php esc_html_e( 'The background check runs about once an hour. It retries images that failed, with a growing delay, and finds images that went missing later.', 'woo-wholesale-partner' ); ?></p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button"><?php esc_html_e( 'Save settings', 'woo-wholesale-partner' ); ?></button>
	</p>
</form>
