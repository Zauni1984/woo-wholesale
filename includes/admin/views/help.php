<?php
/**
 * Admin view: help.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wwpro-help">
	<h2><?php esc_html_e( 'How prices are determined', 'woo-wholesale' ); ?></h2>
	<p><?php esc_html_e( 'For a logged-in user with a wholesale role the plugin checks the following rules in this order and uses the first one that matches. Guests and customers without a wholesale role always see the normal store prices.', 'woo-wholesale' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Fixed wholesale price on the variation or product', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Percentage discount on the variation or product', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Fixed price or percentage discount on the parent product (variable products)', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Percentage discount of a product category (sub-categories inherit from their parents)', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Store-wide discount of the role', 'woo-wholesale' ); ?></li>
	</ol>
	<p><?php esc_html_e( 'Quantity discounts (tiers) are applied on top of that price in the cart: tiers on the product replace tiers of its categories. A tier with a fixed unit price wins over a percentage.', 'woo-wholesale' ); ?></p>

	<h2><?php esc_html_e( 'Display', 'woo-wholesale' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'Wholesale customers see exactly one price – no crossed-out regular price and no sale badge.', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Per role you can show the net or gross price next to the main price (e.g. for B2B customers) and override the tax display.', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Prices are applied everywhere WooCommerce asks for a product price: shop, product page, cart, checkout, blocks and the Store API.', 'woo-wholesale' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Assigning roles', 'woo-wholesale' ); ?></h2>
	<p><?php esc_html_e( 'Open Users → Edit user and pick the wholesale role in the "Role" dropdown. The Users list shows a "Wholesale" column for a quick overview.', 'woo-wholesale' ); ?></p>

	<h2><?php esc_html_e( 'Caching', 'woo-wholesale' ); ?></h2>
	<p><?php esc_html_e( 'Wholesale prices are only shown to logged-in users. Page caches (WP Rocket, LiteSpeed Cache, …) must not serve cached pages to logged-in users – this is the default of these plugins. Do not enable "cache logged-in users" without varying the cache by role.', 'woo-wholesale' ); ?></p>

	<h2><?php esc_html_e( 'Security', 'woo-wholesale' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'All settings pages and actions require the "Manage WooCommerce" capability and a valid nonce.', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Wholesale roles are created with the "read" capability only – they cannot edit content.', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Prices are resolved on the server for the logged-in user; nothing can be manipulated from the browser.', 'woo-wholesale' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'AI assistants and MCP', 'woo-wholesale' ); ?></h2>
	<p><?php esc_html_e( 'The wholesale prices are registered for the REST API, so connectors like Easy MCP AI can read them and fill them in. Two routes are available:', 'woo-wholesale' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Product meta: for every role the fixed price and the discount can be read and written per product and per variation, and product categories carry their discount the same way. The product response also contains a read-only summary of the price each role actually pays.', 'woo-wholesale' ); ?></li>
		<li><?php esc_html_e( 'Abilities: where the WordPress Abilities API is available, this plugin registers four tools in the category "Wholesale pricing" – list roles, read the prices of a product, set a price or discount, set a category discount.', 'woo-wholesale' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Every access needs a logged-in user with the "Manage WooCommerce" capability. Values are validated on the server: prices of zero or less and percentages outside 0 to 100 are rejected. After a write the price caches are refreshed automatically, no matter whether the change came from the admin, the REST API or an assistant.', 'woo-wholesale' ); ?></p>

	<h2><?php esc_html_e( 'Developer hooks', 'woo-wholesale' ); ?></h2>
	<ul>
		<li><code>wwpro_user_wholesale_role</code> – <?php esc_html_e( 'change the wholesale role of a user', 'woo-wholesale' ); ?></li>
		<li><code>wwpro_resolve_price</code> – <?php esc_html_e( 'change the resolved unit price', 'woo-wholesale' ); ?></li>
		<li><code>wwpro_tier_price</code>, <code>wwpro_tier_sets</code>, <code>wwpro_tier_quantity</code> – <?php esc_html_e( 'quantity discounts', 'woo-wholesale' ); ?></li>
		<li><code>wwpro_secondary_price_html</code> – <?php esc_html_e( 'secondary price markup', 'woo-wholesale' ); ?></li>
	</ul>
</div>
