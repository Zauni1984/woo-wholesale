<?php
/**
 * Admin view: markup and sales prices.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

$wwpart_settings = WWPart_Settings::all();
$wwpart_policy   = WWPart_Settings::policy();
$wwpart_cats     = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	)
);
$wwpart_cats     = is_wp_error( $wwpart_cats ) ? array() : $wwpart_cats;
?>

<h2><?php esc_html_e( 'Your prices', 'woo-wholesale-partner' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Your sales price is the purchase price from your supplier plus your markup. Set one markup for the whole shop, or a different one per category - upwards or downwards.', 'woo-wholesale-partner' ); ?>
</p>

<?php if ( ! $wwpart_policy['allow_markup'] ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'Your supplier does not allow an own markup. Products are sold at the purchase price.', 'woo-wholesale-partner' ); ?></p>
	</div>
<?php else : ?>
	<p>
		<?php
		printf(
			/* translators: 1: lowest allowed markup, 2: highest allowed markup */
			esc_html__( 'Your supplier allows a markup between %1$s %% and %2$s %%.', 'woo-wholesale-partner' ),
			esc_html( wc_format_localized_decimal( $wwpart_policy['markup_min'] ) ),
			esc_html( wc_format_localized_decimal( $wwpart_policy['markup_max'] ) )
		);
		?>
	</p>
<?php endif; ?>

<h3><?php esc_html_e( 'Change prices by percentage', 'woo-wholesale-partner' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Sets the markup and recalculates the sales prices right away. This is what you use after the first sync to put your margin on the imported prices.', 'woo-wholesale-partner' ); ?>
</p>

<form id="wwpart-markup-form" onsubmit="return false;">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Change', 'woo-wholesale-partner' ); ?></th>
			<td>
				<select id="wwpart-direction">
					<option value="up"><?php esc_html_e( 'Markup up by', 'woo-wholesale-partner' ); ?></option>
					<option value="down"><?php esc_html_e( 'Markup down by', 'woo-wholesale-partner' ); ?></option>
				</select>
				<input type="text" class="small-text" id="wwpart-percent" value="<?php echo esc_attr( ltrim( (string) $wwpart_settings['markup'], '-' ) ); ?>" /> %
				<p class="description"><?php esc_html_e( 'Relative to the purchase price: 30 % means a purchase price of 10.00 becomes 13.00.', 'woo-wholesale-partner' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Products', 'woo-wholesale-partner' ); ?></th>
			<td>
				<label><input type="radio" name="wwpart-scope" value="all" checked /> <?php esc_html_e( 'All products from the supplier', 'woo-wholesale-partner' ); ?></label><br />
				<label><input type="radio" name="wwpart-scope" value="category" /> <?php esc_html_e( 'Only one category:', 'woo-wholesale-partner' ); ?></label>
				<select id="wwpart-category">
					<option value="0"><?php esc_html_e( '— please choose —', 'woo-wholesale-partner' ); ?></option>
					<?php foreach ( $wwpart_cats as $wwpart_cat ) : ?>
						<option value="<?php echo esc_attr( $wwpart_cat->term_id ); ?>"><?php echo esc_html( $wwpart_cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<br />
				<label><input type="checkbox" id="wwpart-children" checked /> <?php esc_html_e( 'Include subcategories', 'woo-wholesale-partner' ); ?></label><br />
				<label><input type="checkbox" id="wwpart-overwrite" /> <?php esc_html_e( 'Also reset markups set per category', 'woo-wholesale-partner' ); ?></label>
			</td>
		</tr>
	</table>

	<p>
		<button type="button" class="button button-primary" id="wwpart-markup-start" <?php disabled( ! $wwpart_policy['allow_markup'] ); ?>><?php esc_html_e( 'Apply to products', 'woo-wholesale-partner' ); ?></button>
	</p>

	<div id="wwpart-markup-progress" class="wwpart-progress" hidden>
		<div class="wwpart-progress-bar"><span></span></div>
		<p class="wwpart-progress-label"></p>
		<pre class="wwpart-log"></pre>
	</div>
</form>

<hr />

<h3><?php esc_html_e( 'Standard markup and rounding', 'woo-wholesale-partner' ); ?></h3>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'wwpart_save_prices' ); ?>
	<input type="hidden" name="action" value="wwpart_save_prices" />

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wwpart-markup"><?php esc_html_e( 'Standard markup', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<input type="text" class="small-text" id="wwpart-markup" name="prices[markup]" value="<?php echo esc_attr( $wwpart_settings['markup'] ); ?>" /> %
				<p class="description"><?php esc_html_e( 'Used for every product whose category has no markup of its own. A negative value sells below the purchase price, if your supplier allows it.', 'woo-wholesale-partner' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wwpart-rounding"><?php esc_html_e( 'Rounding', 'woo-wholesale-partner' ); ?></label></th>
			<td>
				<select id="wwpart-rounding" name="prices[rounding]">
					<?php foreach ( WWPart_Settings::rounding_modes() as $wwpart_mode => $wwpart_label ) : ?>
						<option value="<?php echo esc_attr( $wwpart_mode ); ?>" <?php selected( $wwpart_settings['rounding'], $wwpart_mode ); ?>><?php echo esc_html( $wwpart_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Markup per category', 'woo-wholesale-partner' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Leave a field empty and the category uses the markup of its parent category, or the standard markup.', 'woo-wholesale-partner' ); ?></p>

	<?php if ( empty( $wwpart_cats ) ) : ?>
		<p><?php esc_html_e( 'There are no product categories yet. They arrive with the first sync.', 'woo-wholesale-partner' ); ?></p>
	<?php else : ?>
		<table class="widefat striped wwpart-category-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Category', 'woo-wholesale-partner' ); ?></th>
					<th><?php esc_html_e( 'Products', 'woo-wholesale-partner' ); ?></th>
					<th><?php esc_html_e( 'Markup', 'woo-wholesale-partner' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $wwpart_cats as $wwpart_cat ) : ?>
				<tr>
					<td>
						<?php echo esc_html( str_repeat( '— ', max( 0, count( get_ancestors( $wwpart_cat->term_id, 'product_cat' ) ) ) ) ); ?>
						<?php echo esc_html( $wwpart_cat->name ); ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $wwpart_cat->count ) ); ?></td>
					<td>
						<input type="text" class="small-text" name="category_markup[<?php echo esc_attr( $wwpart_cat->term_id ); ?>]" value="<?php echo esc_attr( WWPart_Settings::category_markup( $wwpart_cat->term_id ) ); ?>" <?php disabled( ! $wwpart_policy['allow_markup'] ); ?> /> %
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="submit">
		<button type="submit" class="button"><?php esc_html_e( 'Save prices', 'woo-wholesale-partner' ); ?></button>
	</p>
	<p class="description"><?php esc_html_e( 'Saving stores the values. Use "Apply to products" above so the sales prices in the shop are recalculated.', 'woo-wholesale-partner' ); ?></p>
</form>
