<?php
/**
 * Admin view: supplier connection.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

$wwpart_settings = WWPart_Settings::all();
$wwpart_policy   = WWPart_Settings::policy();
$wwpart_locked   = WWPart_Lock::locked_labels();
?>

<h2><?php esc_html_e( 'Your supplier', 'woo-wholesale-partner' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Enter the shop you buy from and the partner key you received from it. This shop then pulls products, images and your purchase prices from there.', 'woo-wholesale-partner' ); ?>
</p>

<?php if ( '' !== $wwpart_settings['last_error'] ) : ?>
	<div class="notice notice-error inline"><p><?php echo esc_html( $wwpart_settings['last_error'] ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'wwpart_save_connection' ); ?>
	<input type="hidden" name="action" value="wwpart_save_connection" />

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wwpart-master-url"><?php esc_html_e( 'Address of the supplier shop', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<input type="url" class="regular-text code" id="wwpart-master-url" name="connection[master_url]" value="<?php echo esc_attr( $wwpart_settings['master_url'] ); ?>" placeholder="https://supplier.example" required />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wwpart-api-key"><?php esc_html_e( 'Partner key', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<input type="password" class="regular-text code" id="wwpart-api-key" name="connection[api_key]" value="" autocomplete="new-password" placeholder="wwp_…" />
				<p class="description">
					<?php if ( '' !== $wwpart_settings['key_hint'] ) : ?>
						<?php
						printf(
							/* translators: %s: beginning of the stored key */
							esc_html__( 'Stored: %s. Leave empty to keep it.', 'woo-wholesale-partner' ),
							'<code>' . esc_html( $wwpart_settings['key_hint'] ) . '</code>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'You get the key from your supplier. It is stored encrypted and never shown again.', 'woo-wholesale-partner' ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save connection', 'woo-wholesale-partner' ); ?></button>
	</p>
</form>

<?php if ( WWPart_Settings::is_connected() ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpart-inline-form">
		<?php wp_nonce_field( 'wwpart_connect' ); ?>
		<input type="hidden" name="action" value="wwpart_connect" />
		<button type="submit" class="button"><?php esc_html_e( 'Test connection', 'woo-wholesale-partner' ); ?></button>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwpart-inline-form">
		<?php wp_nonce_field( 'wwpart_disconnect' ); ?>
		<input type="hidden" name="action" value="wwpart_disconnect" />
		<button type="submit" class="button"><?php esc_html_e( 'Remove key', 'woo-wholesale-partner' ); ?></button>
	</form>

	<h3><?php esc_html_e( 'What your supplier allows', 'woo-wholesale-partner' ); ?></h3>

	<table class="widefat striped">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Supplier', 'woo-wholesale-partner' ); ?></th>
				<td><?php echo '' !== $wwpart_settings['master_name'] ? esc_html( $wwpart_settings['master_name'] ) : '&ndash;'; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'You are registered as', 'woo-wholesale-partner' ); ?></th>
				<td>
					<?php echo '' !== $wwpart_settings['partner_name'] ? esc_html( $wwpart_settings['partner_name'] ) : '&ndash;'; ?>
					<?php if ( '' !== $wwpart_settings['role_name'] ) : ?>
						<br /><span class="description">
							<?php
							printf(
								/* translators: %s: name of the wholesale role */
								esc_html__( 'Price list: %s', 'woo-wholesale-partner' ),
								esc_html( $wwpart_settings['role_name'] )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Your markup', 'woo-wholesale-partner' ); ?></th>
				<td>
					<?php if ( null !== $wwpart_policy['markup_max'] ) : ?>
						<?php
						printf(
							/* translators: %s: highest allowed markup */
							esc_html__( 'at most %s %%', 'woo-wholesale-partner' ),
							esc_html( wc_format_localized_decimal( $wwpart_policy['markup_max'] ) )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'no ceiling', 'woo-wholesale-partner' ); ?>
					<?php endif; ?>

					<?php if ( null !== $wwpart_policy['markup_recommended'] ) : ?>
						<br />
						<span class="description">
							<?php
							printf(
								/* translators: %s: recommended markup */
								esc_html__( 'Your supplier recommends %s %% - a suggestion, nothing more.', 'woo-wholesale-partner' ),
								esc_html( wc_format_localized_decimal( $wwpart_policy['markup_recommended'] ) )
							);
							?>
						</span>
					<?php endif; ?>

					<p class="description"><?php esc_html_e( 'Lowering your price is always your decision: a supplier may cap your price and may recommend one, but it cannot prescribe a minimum. The plugin has no setting for that.', 'woo-wholesale-partner' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Maintained by your supplier', 'woo-wholesale-partner' ); ?></th>
				<td>
					<?php if ( empty( $wwpart_locked ) ) : ?>
						<?php esc_html_e( 'Nothing is locked. Your changes are still overwritten on the next sync for everything the supplier delivers.', 'woo-wholesale-partner' ); ?>
					<?php else : ?>
						<?php echo esc_html( implode( ', ', $wwpart_locked ) ); ?>
						<p class="description"><?php esc_html_e( 'These fields cannot be changed here. If you edit them anyway, the supplier value is written back when you save.', 'woo-wholesale-partner' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last sync', 'woo-wholesale-partner' ); ?></th>
				<td>
					<?php
					echo $wwpart_settings['last_sync']
						? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $wwpart_settings['last_sync'] ) )
						: esc_html__( 'never', 'woo-wholesale-partner' );
					?>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( WWPart_Admin::url( 'sync' ) ); ?>"><?php esc_html_e( 'Go to the sync', 'woo-wholesale-partner' ); ?></a>
	</p>
<?php endif; ?>
