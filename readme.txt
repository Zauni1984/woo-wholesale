=== Woo Wholesale Pro ===
Contributors: zauni1984
Tags: woocommerce, wholesale, b2b, roles, pricing
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Role-based wholesale pricing for WooCommerce with per-product prices, category and store-wide discounts, tiered quantity discounts and an importer for WooCommerce Wholesale Prices.

== Description ==

* Unlimited wholesale roles (real WordPress roles) with per-role store-wide discount, net/gross secondary price, tax display, minimum order amount and coupon lock.
* Per product / variation: fixed price and percentage discount for every role.
* Per category: percentage discount (inherited by sub-categories).
* Tiered quantity discounts per role on products and categories.
* Wholesale customers see exactly one price; guests see the standard price.
* Importer for WooCommerce Wholesale Prices (Wholesale Suite) data, batched via AJAX.
* HPOS and block checkout compatible.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload and activate it.
2. Go to WooCommerce > Wholesale to manage roles, settings and the importer.
3. Assign a wholesale role to a user under Users > Edit user.

== Changelog ==

= 1.0.2 =
* Fix: overlapping role blocks in the product editor; the tier fields now use WooCommerce's standard field markup.

= 1.0.1 =
* Fix: roles created on activation or by the importer had the product editor fields disabled. Existing installs are repaired automatically.
* Product list: one price column per wholesale role, toggleable in the screen options.

= 1.0.0 =
* Initial release.
