<?php
/**
 * Admin view: image check.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

$wwpart_stats = WWPart_Images::stats();
$wwpart_next  = wp_next_scheduled( WWPart_Images::CRON_HOOK );
$wwpart_auto  = WWPart_Settings::is( 'auto_images' );
?>

<h2><?php esc_html_e( 'Image check', 'woo-wholesale-partner' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'A sync never fails because of an image. Whatever could not be fetched stays in a queue, is retried in the background with a growing delay, and is picked up here. The check also walks through the products that are already here and compares what should be there against what really is - so an image that was deleted later, or never arrived, is fetched again.', 'woo-wholesale-partner' ); ?>
</p>

<table class="widefat striped wwpart-stats">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Products from your supplier', 'woo-wholesale-partner' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( (int) $wwpart_stats['managed'] ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Waiting for images', 'woo-wholesale-partner' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( (int) $wwpart_stats['pending_products'] ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Gave up for now', 'woo-wholesale-partner' ); ?></th>
			<td>
				<?php echo esc_html( number_format_i18n( (int) $wwpart_stats['failed_products'] ) ); ?>
				<?php if ( (int) $wwpart_stats['failed_products'] > 0 ) : ?>
					<p class="description"><?php esc_html_e( 'These products were tried several times without success. Use "Retry everything" to start over.', 'woo-wholesale-partner' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images in the queue', 'woo-wholesale-partner' ); ?></th>
			<td><?php echo esc_html( number_format_i18n( (int) $wwpart_stats['queued_images'] ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background check', 'woo-wholesale-partner' ); ?></th>
			<td>
				<?php if ( ! $wwpart_auto ) : ?>
					<?php esc_html_e( 'switched off', 'woo-wholesale-partner' ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the sync screen */
							wp_kses_post( __( 'You can switch it on under %s.', 'woo-wholesale-partner' ) ),
							'<a href="' . esc_url( WWPart_Admin::url( 'sync' ) ) . '">' . esc_html__( 'Sync', 'woo-wholesale-partner' ) . '</a>'
						);
						?>
					</p>
				<?php elseif ( $wwpart_next ) : ?>
					<?php
					printf(
						/* translators: %s: human readable time difference */
						esc_html__( 'next run in %s', 'woo-wholesale-partner' ),
						esc_html( human_time_diff( time(), (int) $wwpart_next ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'not scheduled - deactivate and activate the plugin once to schedule it again', 'woo-wholesale-partner' ); ?>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<p>
	<button type="button" class="button button-primary" id="wwpart-image-start"><?php esc_html_e( 'Check images now', 'woo-wholesale-partner' ); ?></button>
	<button type="button" class="button" id="wwpart-image-force"><?php esc_html_e( 'Retry everything', 'woo-wholesale-partner' ); ?></button>
</p>
<p class="description"><?php esc_html_e( '"Retry everything" also takes on the images that gave up and ignores the waiting time.', 'woo-wholesale-partner' ); ?></p>

<div id="wwpart-image-progress" class="wwpart-progress" hidden>
	<div class="wwpart-progress-bar"><span></span></div>
	<p class="wwpart-progress-label"></p>
	<pre class="wwpart-log"></pre>
</div>

<?php
$wwpart_waiting = WWPart_Images::waiting_products( 0, 20 );

if ( ! empty( $wwpart_waiting ) ) :
	?>
	<h3><?php esc_html_e( 'Products that are still waiting', 'woo-wholesale-partner' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'woo-wholesale-partner' ); ?></th>
				<th><?php esc_html_e( 'Images in the queue', 'woo-wholesale-partner' ); ?></th>
				<th><?php esc_html_e( 'Last message', 'woo-wholesale-partner' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $wwpart_waiting as $wwpart_product_id ) : ?>
			<?php
			$wwpart_queue = WWPart_Images::queue( $wwpart_product_id );
			$wwpart_error = '';

			foreach ( $wwpart_queue as $wwpart_entry ) {
				if ( '' !== (string) $wwpart_entry['last_error'] ) {
					$wwpart_error = (string) $wwpart_entry['last_error'];
				}
			}
			?>
			<tr>
				<td>
					<a href="<?php echo esc_url( get_edit_post_link( $wwpart_product_id ) ); ?>"><?php echo esc_html( get_the_title( $wwpart_product_id ) ); ?></a>
				</td>
				<td><?php echo esc_html( number_format_i18n( count( $wwpart_queue ) ) ); ?></td>
				<td><span class="description"><?php echo '' !== $wwpart_error ? esc_html( $wwpart_error ) : '&ndash;'; ?></span></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
