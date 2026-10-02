<?php
/**
 * Admin view: help.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;
?>

<h2><?php esc_html_e( 'How this plugin works', 'woo-wholesale-partner' ); ?></h2>

<h3><?php esc_html_e( 'The two halves', 'woo-wholesale-partner' ); ?></h3>
<p>
	<?php esc_html_e( 'Your supplier runs "Woo Wholesale Pro" and maintains the products there. This shop runs "Woo Wholesale Partner" and fetches them. Both plugins belong to the same release and should always be updated together.', 'woo-wholesale-partner' ); ?>
</p>

<h3><?php esc_html_e( 'Getting started', 'woo-wholesale-partner' ); ?></h3>
<ol>
	<li><?php esc_html_e( 'Enter the address of the supplier shop and your partner key under "Supplier", then use "Test connection".', 'woo-wholesale-partner' ); ?></li>
	<li><?php esc_html_e( 'Set your markup under "Prices" - you can change it later at any time.', 'woo-wholesale-partner' ); ?></li>
	<li><?php esc_html_e( 'Run the first sync under "Sync". New products are created as drafts, so you can check them first.', 'woo-wholesale-partner' ); ?></li>
	<li><?php esc_html_e( 'Check under "Images" whether anything is still missing, and publish the products.', 'woo-wholesale-partner' ); ?></li>
</ol>

<h3><?php esc_html_e( 'How your price is calculated', 'woo-wholesale-partner' ); ?></h3>
<p><?php esc_html_e( 'Sales price = purchase price × (1 + markup ÷ 100), then rounded the way you chose. The markup of the most specific category of a product wins; if no category has one, the standard markup is used.', 'woo-wholesale-partner' ); ?></p>
<p><?php esc_html_e( 'Your supplier can cap the markup and can recommend one. It cannot prescribe a minimum: you are an independent reseller, and a prescribed minimum or fixed resale price would be resale price maintenance, which is not permitted (Art. 101 TFEU, § 1 GWB). So the way down is always open to you, down to below the purchase price.', 'woo-wholesale-partner' ); ?></p>
<p><?php esc_html_e( 'A price you type into a product by hand is replaced the next time prices are recalculated. If you want to keep a special price for one article, use a sale price - a sale price below the calculated price is left alone.', 'woo-wholesale-partner' ); ?></p>

<h3><?php esc_html_e( 'Why some fields cannot be edited', 'woo-wholesale-partner' ); ?></h3>
<p><?php esc_html_e( 'Your supplier decides which product data it maintains. Those fields are shown as locked, and if something changes them anyway - a bulk edit, an import, another plugin through the REST API - the supplier value is written back when the product is saved. Your prices, your stock and your own products are not affected.', 'woo-wholesale-partner' ); ?></p>

<h3><?php esc_html_e( 'Images', 'woo-wholesale-partner' ); ?></h3>
<p><?php esc_html_e( 'Images are only downloaded from the supplier shop, never from any other address, and only if they really are images. An image that is too large or unreachable is retried later instead of stopping the sync.', 'woo-wholesale-partner' ); ?></p>

<h3><?php esc_html_e( 'If something goes wrong', 'woo-wholesale-partner' ); ?></h3>
<ul>
	<li><?php esc_html_e( '"Unknown or revoked partner key": ask your supplier for a new key, keys can be replaced at any time.', 'woo-wholesale-partner' ); ?></li>
	<li><?php esc_html_e( 'No answer on the partner route: the supplier shop needs Woo Wholesale Pro 1.1.0 or newer.', 'woo-wholesale-partner' ); ?></li>
	<li><?php esc_html_e( 'A sync that stops in the middle can simply be started again; it continues where it makes sense and never creates a product twice.', 'woo-wholesale-partner' ); ?></li>
</ul>
