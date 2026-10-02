=== Woo Wholesale Pro ===
Contributors: zauni1984
Tags: woocommerce, wholesale, b2b, roles, pricing
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.1.0
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
* REST API and WordPress Abilities API support, so MCP connectors such as Easy MCP AI can read and write wholesale prices.
* Partner shops: hand your catalogue and one role's prices to a WooCommerce partner (companion plugin "Woo Wholesale Partner") or push it into a Shopify store through the Admin API.
* Bulk price change: move the wholesale prices of a role up or down by a percentage, for the whole catalogue or one category, with a progress bar.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload and activate it.
2. Go to WooCommerce > Wholesale to manage roles, settings and the importer.
3. Assign a wholesale role to a user under Users > Edit user.

== Changelog ==

= 1.1.0 =
* New companion plugin "Woo Wholesale Partner" for partner shops: it pulls products, images and purchase prices from this shop, protects the fields the supplier maintains, adds the partner's own percentage markup per category or for everything, and retries missing images in the background. Both plugins carry the same version and belong together.
* New "Partner shops" tab: manage partners, their wholesale role, the categories they receive, the fields they may not change, an optional maximum markup and a non-binding price recommendation. Partner keys are stored as an HMAC only.
* No minimum resale prices: a supplier can cap a partner's markup and recommend one, but cannot prescribe a minimum or fixed resale price - that is resale price maintenance and not permitted (Art. 101 TFEU, Sec. 1 GWB). The partner stays free to lower its price.
* New Shopify connection: push products, variants, prices and images into a partner's Shopify store through the GraphQL Admin API.
* New "Price change" tab: move the wholesale prices of a role by a percentage, for all products or one category, with rounding options and a progress bar.

= 1.0.4 =
* Fix: page caches such as LiteSpeed Cache could serve the guest price to a logged-in wholesale customer. Wholesale pages are now kept out of the public page cache, LiteSpeed keeps a separate copy per role, and the page cache is cleared whenever prices or rules change.

= 1.0.3 =
* Wholesale prices are registered for the REST API and exposed as WordPress abilities, so MCP connectors such as Easy MCP AI can read and fill them in.

= 1.0.2 =
* Fix: overlapping role blocks in the product editor; the tier fields now use WooCommerce's standard field markup.

= 1.0.1 =
* Fix: roles created on activation or by the importer had the product editor fields disabled. Existing installs are repaired automatically.
* Product list: one price column per wholesale role, toggleable in the screen options.

= 1.0.0 =
* Initial release.
