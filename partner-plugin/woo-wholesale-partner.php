<?php
/**
 * Plugin Name:          Woo Wholesale Partner
 * Plugin URI:           https://github.com/zauni1984/woo-wholesale
 * Description:          Companion plugin for partner shops: pulls products, images and wholesale prices from a shop running Woo Wholesale Pro, protects the data the supplier dictates and adds the partner's own percentage markup.
 * Version:              1.1.0
 * Author:               Stefan Zaunreither
 * Author URI:           https://github.com/zauni1984
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          woo-wholesale-partner
 * Domain Path:          /languages
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      11.1
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

define( 'WWPART_VERSION', '1.1.0' );
define( 'WWPART_FILE', __FILE__ );
define( 'WWPART_PATH', plugin_dir_path( __FILE__ ) );
define( 'WWPART_URL', plugin_dir_url( __FILE__ ) );
define( 'WWPART_BASENAME', plugin_basename( __FILE__ ) );
define( 'WWPART_MIN_PHP', '7.4' );
define( 'WWPART_MIN_WC', '7.0' );

if ( version_compare( PHP_VERSION, WWPART_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum PHP version */
				esc_html__( 'Woo Wholesale Partner requires PHP %s or newer. The plugin is inactive.', 'woo-wholesale-partner' ),
				esc_html( WWPART_MIN_PHP )
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once WWPART_PATH . 'includes/class-wwpart-plugin.php';
require_once WWPART_PATH . 'includes/class-wwpart-install.php';

register_activation_hook( __FILE__, array( 'WWPart_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WWPart_Install', 'deactivate' ) );

/**
 * Global accessor.
 *
 * @return WWPart_Plugin
 */
function wwpart() {
	return WWPart_Plugin::instance();
}

wwpart();
