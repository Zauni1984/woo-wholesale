<?php
/**
 * Admin pages, handlers and notices.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin (static).
 */
class WWPro_Admin {

	const PAGE       = 'wwpro';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_action( 'admin_post_wwpro_save_role', array( __CLASS__, 'handle_save_role' ) );
		add_action( 'admin_post_wwpro_delete_role', array( __CLASS__, 'handle_delete_role' ) );
		add_action( 'admin_post_wwpro_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_wwpro_import_step', array( __CLASS__, 'ajax_import_step' ) );

		add_filter( 'plugin_action_links_' . WWPRO_BASENAME, array( __CLASS__, 'action_links' ) );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_role_display' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column_content' ), 10, 2 );

		add_filter( 'manage_users_columns', array( __CLASS__, 'user_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'user_column_content' ), 10, 3 );
	}

	/**
	 * Menu entry under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Wholesale', 'woo-wholesale' ),
			__( 'Wholesale', 'woo-wholesale' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * URL of an admin tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function url( $tab = 'roles', $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'roles'    => __( 'Roles', 'woo-wholesale' ),
			'settings' => __( 'Settings', 'woo-wholesale' ),
			'import'   => __( 'Import', 'woo-wholesale' ),
			'help'     => __( 'Help', 'woo-wholesale' ),
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'roles'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'roles';
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-wholesale' ) );
		}

		$tab = self::current_tab();
		?>
		<div class="wrap wwpro-wrap">
			<h1><?php esc_html_e( 'Woo Wholesale Pro', 'woo-wholesale' ); ?></h1>
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php include WWPRO_PATH . 'includes/admin/views/' . $tab . '.php'; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets on relevant screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_our = ( false !== strpos( $hook, self::PAGE ) );
		$is_pro = $screen && ( 'product' === $screen->post_type ) && in_array( $screen->base, array( 'post', 'edit' ), true );
		$is_cat = $screen && 'product_cat' === $screen->taxonomy;

		if ( ! $is_our && ! $is_pro && ! $is_cat ) {
			return;
		}

		wp_enqueue_style( 'wwpro-admin', WWPRO_URL . 'assets/css/admin.css', array(), WWPRO_VERSION );
		wp_enqueue_script( 'wwpro-admin', WWPRO_URL . 'assets/js/admin.js', array( 'jquery' ), WWPRO_VERSION, true );

		wp_localize_script(
			'wwpro-admin',
			'wwproAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'importNonce' => wp_create_nonce( 'wwpro_import' ),
				'i18n'        => array(
					'confirmDelete' => __( 'Delete this wholesale role? Users of this role are moved to the "Customer" role.', 'woo-wholesale' ),
					'confirmImport' => __( 'Start the import now? Existing values are only overwritten if you selected that option.', 'woo-wholesale' ),
					'importDone'    => __( 'Import finished.', 'woo-wholesale' ),
					'importError'   => __( 'The import stopped because of an error. Please check the log and try again.', 'woo-wholesale' ),
					'working'       => __( 'Working…', 'woo-wholesale' ),
				),
			)
		);
	}

	/**
	 * Plugin list action links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'roles' ) ) . '">' . esc_html__( 'Settings', 'woo-wholesale' ) . '</a>' );
		return $links;
	}

	/**
	 * Store a one-time admin notice for the current user.
	 *
	 * @param string $message Message (plain text).
	 * @param string $type    success|error|warning|info.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$notices   = get_transient( 'wwpro_notices_' . get_current_user_id() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( 'wwpro_notices_' . get_current_user_id(), $notices, 120 );
	}

	/**
	 * Print notices.
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notices = get_transient( 'wwpro_notices_' . get_current_user_id() );
		if ( is_array( $notices ) && ! empty( $notices ) ) {
			delete_transient( 'wwpro_notices_' . get_current_user_id() );
			foreach ( $notices as $notice ) {
				$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			}
		}

		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) && WWPro_Importer::source_plugin_active() ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to the import tab */
				wp_kses_post( __( 'The plugin "WooCommerce Wholesale Prices" is still active. Import its data on the %s tab and deactivate it afterwards, otherwise both plugins would adjust prices at the same time.', 'woo-wholesale' ) ),
				'<a href="' . esc_url( self::url( 'import' ) ) . '">' . esc_html__( 'Import', 'woo-wholesale' ) . '</a>'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Common guard for admin_post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wholesale' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Handler: save role.
	 */
	public static function handle_save_role() {
		self::guard( 'wwpro_save_role' );

		$raw    = isset( $_POST['role'] ) && is_array( $_POST['role'] ) ? wp_unslash( $_POST['role'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPro_Roles::sanitize().
		$is_new = ! empty( $_POST['is_new'] );

		$result = WWPro_Roles::save( $raw, $is_new );

		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
			$redirect = self::url( 'roles', $is_new ? array( 'action' => 'new' ) : array( 'edit' => isset( $raw['key'] ) ? sanitize_key( $raw['key'] ) : '' ) );
		} else {
			self::add_notice( $is_new ? __( 'Wholesale role created.', 'woo-wholesale' ) : __( 'Wholesale role saved.', 'woo-wholesale' ) );
			$redirect = self::url( 'roles' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handler: delete role.
	 */
	public static function handle_delete_role() {
		self::guard( 'wwpro_delete_role' );

		$key         = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$delete_data = ! empty( $_POST['delete_data'] );

		if ( $key && WWPro_Roles::delete( $key, $delete_data ) ) {
			self::add_notice( __( 'Wholesale role deleted.', 'woo-wholesale' ) );
		} else {
			self::add_notice( __( 'The wholesale role could not be deleted.', 'woo-wholesale' ), 'error' );
		}

		wp_safe_redirect( self::url( 'roles' ) );
		exit;
	}

	/**
	 * Handler: save settings.
	 */
	public static function handle_save_settings() {
		self::guard( 'wwpro_save_settings' );

		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in WWPro_Settings::update().
		WWPro_Settings::update( $raw );

		self::add_notice( __( 'Settings saved.', 'woo-wholesale' ) );
		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	/**
	 * AJAX: one import step.
	 *
	 * The client drives a small state machine: roles -> products -> discounts -> categories -> global -> users -> done.
	 */
	public static function ajax_import_step() {
		check_ajax_referer( 'wwpro_import', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'woo-wholesale' ) ), 403 );
		}

		$step       = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'roles';
		$role_index = isset( $_POST['role_index'] ) ? absint( $_POST['role_index'] ) : 0;
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$config_raw = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized below.
		$config     = self::sanitize_import_config( json_decode( (string) $config_raw, true ) );

		$sources = array_keys( $config['roles'] );
		$log     = array();
		$next    = array(
			'step'       => $step,
			'role_index' => $role_index,
			'offset'     => 0,
		);

		$stats_key = 'wwpro_import_stats_' . get_current_user_id();
		$stats     = get_transient( $stats_key );
		$stats     = is_array( $stats ) ? $stats : array( 'products' => 0, 'discounts' => 0, 'categories' => 0, 'global' => 0, 'users' => 0 );

		if ( empty( $sources ) ) {
			wp_send_json_error( array( 'message' => __( 'No roles selected for import.', 'woo-wholesale' ) ) );
		}

		switch ( $step ) {
			case 'roles':
				$stats = array( 'products' => 0, 'discounts' => 0, 'categories' => 0, 'global' => 0, 'users' => 0 );
				foreach ( $sources as $src ) {
					$map    = $config['roles'][ $src ];
					$result = WWPro_Importer::register_role( $src, $map['target'], $map['name'] );
					if ( is_wp_error( $result ) ) {
						wp_send_json_error( array( 'message' => $result->get_error_message() ) );
					}
					$config['roles'][ $src ]['target'] = $result;
					/* translators: 1: source role key, 2: target role name */
					$log[] = sprintf( __( 'Role "%1$s" is mapped to "%2$s".', 'woo-wholesale' ), $src, WWPro_Roles::label( $result ) );
				}
				$next['step'] = 'products';
				break;

			case 'products':
				if ( $role_index >= count( $sources ) ) {
					$next['step']       = 'discounts';
					$next['role_index'] = 0;
					break;
				}
				$src    = $sources[ $role_index ];
				$dst    = self::resolve_target( $config, $src );
				$result = WWPro_Importer::import_products( $src, $dst, $offset, $config['overwrite'] );

				$stats['products'] += $result['imported'];
				/* translators: 1: role key, 2: processed, 3: total */
				$log[] = sprintf( __( 'Prices for "%1$s": %2$d of %3$d entries processed.', 'woo-wholesale' ), $src, min( $offset + $result['processed'], $result['total'] ), $result['total'] );

				if ( $result['done'] ) {
					$next['role_index'] = $role_index + 1;
				} else {
					$next['offset'] = $offset + $result['processed'];
				}
				break;

			case 'discounts':
				foreach ( $sources as $src ) {
					$count               = WWPro_Importer::import_product_discounts( $src, self::resolve_target( $config, $src ), $config['overwrite'] );
					$stats['discounts'] += $count;
					if ( $count ) {
						/* translators: 1: role key, 2: count */
						$log[] = sprintf( __( 'Product discounts for "%1$s": %2$d imported.', 'woo-wholesale' ), $src, $count );
					}
				}
				$next['step'] = 'categories';
				break;

			case 'categories':
				foreach ( $sources as $src ) {
					$count                = WWPro_Importer::import_categories( $src, self::resolve_target( $config, $src ), $config['overwrite'] );
					$stats['categories'] += $count;
					/* translators: 1: role key, 2: count */
					$log[] = sprintf( __( 'Category discounts for "%1$s": %2$d imported.', 'woo-wholesale' ), $src, $count );
				}
				$next['step'] = 'global';
				break;

			case 'global':
				foreach ( $sources as $src ) {
					if ( WWPro_Importer::import_global( $src, self::resolve_target( $config, $src ), $config['overwrite'] ) ) {
						++$stats['global'];
						/* translators: %s: role key */
						$log[] = sprintf( __( 'Store-wide discount for "%s" imported.', 'woo-wholesale' ), $src );
					}
				}
				$next['step'] = 'users';
				break;

			case 'users':
				$moved_any = false;
				if ( $config['migrate_users'] ) {
					foreach ( $sources as $src ) {
						$dst = self::resolve_target( $config, $src );
						if ( $dst === $src ) {
							continue;
						}
						$moved = WWPro_Importer::migrate_users( $src, $dst );
						if ( $moved > 0 ) {
							$moved_any       = true;
							$stats['users'] += $moved;
							/* translators: 1: count, 2: source role, 3: target role */
							$log[] = sprintf( __( '%1$d users moved from "%2$s" to "%3$s".', 'woo-wholesale' ), $moved, $src, $dst );
						}
					}
				}
				$next['step'] = $moved_any ? 'users' : 'done';
				break;

			case 'done':
			default:
				WWPro_Importer::finish();
				$log[] = sprintf(
					/* translators: 1: products, 2: product discounts, 3: categories, 4: global discounts, 5: users */
					__( 'Done: %1$d product prices, %2$d product discounts, %3$d category discounts, %4$d store-wide discounts, %5$d users.', 'woo-wholesale' ),
					$stats['products'],
					$stats['discounts'],
					$stats['categories'],
					$stats['global'],
					$stats['users']
				);
				delete_transient( $stats_key );
				wp_send_json_success(
					array(
						'done' => true,
						'log'  => $log,
					)
				);
		}

		set_transient( $stats_key, $stats, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'done'   => false,
				'next'   => $next,
				'config' => $config,
				'log'    => $log,
			)
		);
	}

	/**
	 * Target role for a source role from the (already registered) config.
	 *
	 * @param array  $config Config.
	 * @param string $src    Source key.
	 * @return string
	 */
	private static function resolve_target( $config, $src ) {
		$target = $config['roles'][ $src ]['target'];
		return ( 'same' === $target ) ? $src : $target;
	}

	/**
	 * Sanitize the import configuration sent by the browser.
	 *
	 * @param mixed $raw Decoded JSON.
	 * @return array
	 */
	private static function sanitize_import_config( $raw ) {
		$config = array(
			'roles'         => array(),
			'overwrite'     => false,
			'migrate_users' => false,
		);

		if ( ! is_array( $raw ) ) {
			return $config;
		}

		$config['overwrite']     = ! empty( $raw['overwrite'] );
		$config['migrate_users'] = ! empty( $raw['migrate_users'] );

		if ( isset( $raw['roles'] ) && is_array( $raw['roles'] ) ) {
			foreach ( $raw['roles'] as $src => $map ) {
				$src = sanitize_key( $src );
				if ( '' === $src || ! preg_match( '/^[a-z][a-z0-9_]*$/', $src ) || WWPro_Roles::is_reserved( $src ) || ! is_array( $map ) ) {
					continue;
				}
				$target = isset( $map['target'] ) ? sanitize_key( $map['target'] ) : 'skip';
				if ( 'skip' === $target || '' === $target ) {
					continue;
				}
				if ( 'same' !== $target && ! WWPro_Roles::exists( $target ) ) {
					continue;
				}
				$config['roles'][ $src ] = array(
					'target' => $target,
					'name'   => isset( $map['name'] ) ? sanitize_text_field( $map['name'] ) : '',
				);
			}
		}

		ksort( $config['roles'] );

		return $config;
	}

	/**
	 * Show the wholesale role on the order edit screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_role_display( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$role = $order->get_meta( WWPro_Cart::ORDER_META_ROLE );
		if ( ! $role ) {
			return;
		}
		$name = $order->get_meta( WWPro_Cart::ORDER_META_NAME );
		echo '<p class="form-field form-field-wide wwpro-order-role"><strong>' . esc_html__( 'Wholesale role:', 'woo-wholesale' ) . '</strong> ' . esc_html( $name ? $name : $role ) . '</p>';
	}

	/**
	 * Product list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function product_columns( $columns ) {
		if ( empty( WWPro_Roles::product_roles() ) ) {
			return $columns;
		}
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'price' === $key ) {
				$new['wwpro'] = __( 'Wholesale', 'woo-wholesale' );
			}
		}
		if ( ! isset( $new['wwpro'] ) ) {
			$new['wwpro'] = __( 'Wholesale', 'woo-wholesale' );
		}
		return $new;
	}

	/**
	 * Product list column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function product_column_content( $column, $post_id ) {
		if ( 'wwpro' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$lines = array();
		foreach ( WWPro_Roles::product_roles() as $key => $role ) {
			$price    = $product->get_meta( WWPro_Pricing::price_key( $key ), true, 'edit' );
			$discount = $product->get_meta( WWPro_Pricing::discount_key( $key ), true, 'edit' );
			$tiers    = WWPro_Tiers::sanitize( $product->get_meta( WWPro_Tiers::meta_key( $key ), true, 'edit' ) );

			$parts = array();
			if ( '' !== $price && is_numeric( $price ) ) {
				$parts[] = wp_strip_all_tags( wc_price( (float) $price ) );
			} elseif ( '' !== $discount && is_numeric( $discount ) ) {
				$parts[] = '-' . wc_format_localized_decimal( $discount ) . ' %';
			}
			if ( WWPro_Tiers::is_active_set( $tiers ) ) {
				$parts[] = __( 'tiers', 'woo-wholesale' );
			}
			if ( $product->is_type( 'variable' ) && empty( $parts ) ) {
				$parts[] = __( 'per variation', 'woo-wholesale' );
			}
			if ( ! empty( $parts ) ) {
				$lines[] = '<strong>' . esc_html( $role['name'] ) . ':</strong> ' . esc_html( implode( ', ', $parts ) );
			}
		}

		echo empty( $lines ) ? '<span class="na">&ndash;</span>' : wp_kses_post( implode( '<br>', $lines ) );
	}

	/**
	 * Users list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function user_columns( $columns ) {
		if ( ! empty( WWPro_Roles::all() ) ) {
			$columns['wwpro'] = __( 'Wholesale', 'woo-wholesale' );
		}
		return $columns;
	}

	/**
	 * Users list column content.
	 *
	 * @param string $output  Output.
	 * @param string $column  Column.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function user_column_content( $output, $column, $user_id ) {
		if ( 'wwpro' !== $column ) {
			return $output;
		}
		$role = WWPro_Roles::get_user_role( $user_id );
		return $role ? esc_html( WWPro_Roles::label( $role ) ) : '<span class="na">&ndash;</span>';
	}
}
