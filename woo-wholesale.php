<?php
/**
 * Plugin Name:          Woo Wholesale Pro
 * Plugin URI:           https://github.com/zauni1984/woo-wholesale
 * Description:          Role-based wholesale pricing for WooCommerce: per-product prices, category and store-wide discounts, tiered quantity discounts, net price display and a one-click importer for WooCommerce Wholesale Prices.
 * Version:              1.0.3
 * Author:               Stefan Zaunreither
 * Author URI:           https://github.com/zauni1984
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          woo-wholesale
 * Domain Path:          /languages
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      11.1
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

define( 'WWPRO_VERSION', '1.0.3' );
define( 'WWPRO_FILE', __FILE__ );
define( 'WWPRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WWPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'WWPRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'WWPRO_MIN_PHP', '7.4' );
define( 'WWPRO_MIN_WC', '7.0' );

if ( version_compare( PHP_VERSION, WWPRO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum PHP version */
				esc_html__( 'Woo Wholesale Pro requires PHP %s or newer. The plugin is inactive.', 'woo-wholesale' ),
				esc_html( WWPRO_MIN_PHP )
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once WWPRO_PATH . 'includes/class-wwpro-plugin.php';
require_once WWPRO_PATH . 'includes/class-wwpro-install.php';

register_activation_hook( __FILE__, array( 'WWPro_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WWPro_Install', 'deactivate' ) );

/**
 * Global accessor.
 *
 * @return WWPro_Plugin
 */
function wwpro() {
	return WWPro_Plugin::instance();
}

wwpro();
