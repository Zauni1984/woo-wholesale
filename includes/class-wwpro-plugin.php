<?php
/**
 * Plugin loader.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class (singleton).
 */
final class WWPro_Plugin {

	/**
	 * Instance.
	 *
	 * @var WWPro_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the runtime requirements are met.
	 *
	 * @var bool
	 */
	private $ready = false;

	/**
	 * Singleton accessor.
	 *
	 * @return WWPro_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
	}

	/**
	 * Declare compatibility with WooCommerce features (HPOS, block checkout).
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WWPRO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WWPRO_FILE, true );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'woo-wholesale', false, dirname( WWPRO_BASENAME ) . '/languages' );
	}

	/**
	 * Bootstrap once all plugins are loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_wc' ) );
			return;
		}

		if ( version_compare( WC_VERSION, WWPRO_MIN_WC, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_old_wc' ) );
			return;
		}

		$this->includes();
		$this->ready = true;

		WWPro_Roles::init();
		WWPro_Frontend::init();
		WWPro_Cart::init();

		if ( is_admin() ) {
			WWPro_Admin::init();
			WWPro_Product_Fields::init();
			WWPro_Category_Fields::init();
		}

		/**
		 * Fires once Woo Wholesale Pro has been fully loaded.
		 *
		 * @param WWPro_Plugin $plugin Plugin instance.
		 */
		do_action( 'wwpro_loaded', $this );
	}

	/**
	 * Whether the plugin is fully initialised.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->ready;
	}

	/**
	 * Include class files.
	 */
	private function includes() {
		require_once WWPRO_PATH . 'includes/class-wwpro-settings.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-roles.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-tiers.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-pricing.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-frontend.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-cart.php';
		require_once WWPRO_PATH . 'includes/class-wwpro-importer.php';

		if ( is_admin() ) {
			require_once WWPRO_PATH . 'includes/admin/class-wwpro-admin.php';
			require_once WWPRO_PATH . 'includes/admin/class-wwpro-tiers-field.php';
			require_once WWPRO_PATH . 'includes/admin/class-wwpro-product-fields.php';
			require_once WWPRO_PATH . 'includes/admin/class-wwpro-category-fields.php';
		}
	}

	/**
	 * Admin notice: WooCommerce missing.
	 */
	public function notice_missing_wc() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Woo Wholesale Pro requires WooCommerce to be installed and active.', 'woo-wholesale' );
		echo '</p></div>';
	}

	/**
	 * Admin notice: WooCommerce too old.
	 */
	public function notice_old_wc() {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: minimum WooCommerce version */
			esc_html__( 'Woo Wholesale Pro requires WooCommerce %s or newer.', 'woo-wholesale' ),
			esc_html( WWPRO_MIN_WC )
		);
		echo '</p></div>';
	}
}
