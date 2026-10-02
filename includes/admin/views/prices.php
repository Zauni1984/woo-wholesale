<?php
/**
 * Admin view: change wholesale prices in bulk by a percentage.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

$wwpro_roles      = WWPro_Roles::all();
$wwpro_last       = get_option( 'wwpro_last_bulk_price', array() );
$wwpro_categories = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	)
);
$wwpro_categories = is_wp_error( $wwpro_categories ) ? array() : $wwpro_categories;
?>

<h2><?php esc_html_e( 'Change prices by percentage', 'woo-wholesale' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Moves the wholesale prices of one role up or down by a percentage - for the whole catalogue or for a single category. This is the tool for the step after the first import, when the imported prices need a margin on top or a discount taken off.', 'woo-wholesale' ); ?>
</p>

<?php if ( empty( $wwpro_roles ) ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Create a wholesale role first.', 'woo-wholesale' ); ?></p></div>
	<?php
	return;
endif;
?>

<?php if ( ! empty( $wwpro_last['time'] ) ) : ?>
	<p>
		<?php
		printf(
			/* translators: 1: date, 2: number of products */
			esc_html__( 'Last run: %1$s, %2$d products changed.', 'woo-wholesale' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $wwpro_last['time'] ) ),
			isset( $wwpro_last['summary']['changed'] ) ? (int) $wwpro_last['summary']['changed'] : 0
		);
		?>
	</p>
<?php endif; ?>

<form id="wwpro-bulk-price-form" onsubmit="return false;">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wwpro-bulk-role"><?php esc_html_e( 'Wholesale role', 'woo-wholesale' ); ?></label></th>
			<td>
				<select id="wwpro-bulk-role" name="role">
					<?php foreach ( $wwpro_roles as $wwpro_role_key => $wwpro_role ) : ?>
						<option value="<?php echo esc_attr( $wwpro_role_key ); ?>"><?php echo esc_html( $wwpro_role['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Change', 'woo-wholesale' ); ?></th>
			<td>
				<select id="wwpro-bulk-direction" name="direction">
					<option value="up"><?php esc_html_e( 'Increase by', 'woo-wholesale' ); ?></option>
					<option value="down"><?php esc_html_e( 'Decrease by', 'woo-wholesale' ); ?></option>
				</select>
				<input type="text" class="small-text" id="wwpro-bulk-percent" name="percent" value="10" /> %
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wwpro-bulk-base"><?php esc_html_e( 'Calculate from', 'woo-wholesale' ); ?></label></th>
			<td>
				<select id="wwpro-bulk-base" name="base">
					<?php foreach ( WWPro_Bulk_Prices::bases() as $wwpro_base_key => $wwpro_base_label ) : ?>
						<option value="<?php echo esc_attr( $wwpro_base_key ); ?>"><?php echo esc_html( $wwpro_base_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Products', 'woo-wholesale' ); ?></th>
			<td>
				<label><input type="radio" name="scope" value="all" checked /> <?php esc_html_e( 'All products', 'woo-wholesale' ); ?></label><br />
				<label><input type="radio" name="scope" value="category" /> <?php esc_html_e( 'Only one category:', 'woo-wholesale' ); ?></label>
				<select id="wwpro-bulk-category" name="category">
					<option value="0"><?php esc_html_e( '— please choose —', 'woo-wholesale' ); ?></option>
					<?php foreach ( $wwpro_categories as $wwpro_cat ) : ?>
						<option value="<?php echo esc_attr( $wwpro_cat->term_id ); ?>"><?php echo esc_html( $wwpro_cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<br />
				<label><input type="checkbox" id="wwpro-bulk-children" checked /> <?php esc_html_e( 'Include subcategories', 'woo-wholesale' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wwpro-bulk-rounding"><?php esc_html_e( 'Rounding', 'woo-wholesale' ); ?></label></th>
			<td>
				<select id="wwpro-bulk-rounding" name="rounding">
					<?php foreach ( WWPro_Bulk_Prices::rounding_modes() as $wwpro_round_key => $wwpro_round_label ) : ?>
						<option value="<?php echo esc_attr( $wwpro_round_key ); ?>"><?php echo esc_html( $wwpro_round_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'The new price is written as a fixed wholesale price for the chosen role. A percentage discount of the same role on those products is removed, so one rule describes the price. There is no undo - make a database backup before a large run.', 'woo-wholesale' ); ?></p>
	</div>

	<p>
		<button type="button" class="button button-primary" id="wwpro-bulk-start"><?php esc_html_e( 'Change prices now', 'woo-wholesale' ); ?></button>
	</p>

	<div id="wwpro-bulk-progress" class="wwpro-progress" hidden>
		<div class="wwpro-progress-bar"><span></span></div>
		<p class="wwpro-progress-label"></p>
		<pre class="wwpro-import-log"></pre>
	</div>
</form>
