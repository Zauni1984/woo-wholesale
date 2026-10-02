=== Woo Wholesale Partner ===
Contributors: zauni1984
Tags: woocommerce, wholesale, b2b, dropshipping, sync
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Companion plugin for partner shops: pulls products, images and purchase prices from a shop running Woo Wholesale Pro and adds your own markup.

== Description ==

This is the partner half of Woo Wholesale Pro. Your supplier maintains the catalogue; this shop fetches it and sells it with your markup on top.

* Pulls categories, products, variations, purchase prices and images from the supplier shop.
* Your sales price is the purchase price plus your markup - set it for the whole shop or per category, upwards or downwards.
* Your supplier can cap your markup and can recommend one, but it cannot prescribe a minimum: you are an independent reseller, and a prescribed minimum or fixed resale price would be resale price maintenance (Art. 101 TFEU, Sec. 1 GWB). Lowering your price, even below the purchase price, is always your decision.
* Changing prices by percentage runs in batches with a progress bar, and the markup stays stored so later syncs keep using it.
* The fields your supplier maintains are protected on the server: whatever changes them - the product editor, a bulk edit, another plugin through the REST API - is reset to the supplier's value when the product is saved.
* A sync never fails because of an image. Missing images stay in a queue, are retried in the background with a growing delay, and a checker keeps comparing what should be there against what really is - so an image that was deleted later is fetched again.
* Images are only downloaded from the supplier shop, only over HTTP or HTTPS, only if they really are images, and with a size limit.
* Your own products, your stock and your orders are never touched.

Both plugins belong to the same release and carry the same version number. Update them together.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload and activate it.
2. Go to WooCommerce > Supplier, enter the address of the supplier shop and the partner key you received, then use "Test connection".
3. Set your markup under "Prices".
4. Run the first sync under "Sync". New products are created as drafts so you can check them before they go live.

== Frequently Asked Questions ==

= Where do I get the partner key? =

From your supplier. In their shop it is created under WooCommerce > Wholesale > Partner shops. The key is shown there once; it can be replaced at any time.

= Why can I not edit the product name? =

Because your supplier maintains it. Which fields are locked is shown under WooCommerce > Supplier. Your prices and your stock are always yours.

= Can my supplier stop me from lowering my price? =

No, and the plugin has no setting for it. A supplier may set a maximum price and may recommend one; a minimum or fixed resale price would be resale price maintenance and is not permitted. The only floor is technical, so a price keeps a remainder.

= I set a price by hand and it came back. =

Prices are calculated from the purchase price and your markup. For a special price on a single article use a sale price: a sale price below the calculated price is left alone.

== Changelog ==

= 1.1.0 =
* First release, together with Woo Wholesale Pro 1.1.0.
