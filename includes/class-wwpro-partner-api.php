<?php
/**
 * REST API for partner shops.
 *
 * The companion plugin "Woo Wholesale Partner" talks to these routes. They are
 * deliberately read-only apart from the heartbeat: a partner shop can pull
 * products, prices and its policy, and report back what it has - it can never
 * write into this shop.
 *
 * Authentication is a bearer token that the administrator issues per partner.
 * Only the HMAC of the token is stored here, failed attempts are rate limited.
 *
 * @package WooWholesalePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Partner API (static).
 */
class WWPro_Partner_API {

	const NAMESPACE_V1 = 'wwpro/v1';

	/**
	 * Failed authentications allowed per window and IP.
	 */
	const MAX_FAILURES = 20;

	/**
	 * Rate limit window in seconds.
	 */
	const FAILURE_WINDOW = 600;

	/**
	 * Largest page size a partner may request.
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Partner authenticated for the current request.
	 *
	 * @var array|null
	 */
	private static $current = null;

	/**
	 * Register routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route registration.
	 */
	public static function register_routes() {
		$auth = array( __CLASS__, 'authorize' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/partner/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/partner/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'categories' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/partner/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'products' ),
				'permission_callback' => $auth,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
					'since'    => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/partner/heartbeat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'heartbeat' ),
				'permission_callback' => $auth,
				'args'                => array(
					'products'       => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'missing_images' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'failed_images'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'version'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'error'          => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Bearer token of the current request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function token_from( $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' !== $header && 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return trim( (string) $request->get_header( 'x_wwpro_key' ) );
	}

	/**
	 * Permission callback: resolve the partner from the bearer token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( $request ) {
		self::$current = null;

		if ( self::is_rate_limited() ) {
			return new WP_Error(
				'wwpro_rate_limited',
				__( 'Too many failed authentication attempts. Please try again later.', 'woo-wholesale' ),
				array( 'status' => 429 )
			);
		}

		$token   = self::token_from( $request );
		$partner = '' === $token ? null : WWPro_Partners::find_by_key( $token );

		if ( ! $partner ) {
			self::count_failure();
			return new WP_Error(
				'wwpro_partner_unauthorized',
				__( 'Unknown or revoked partner key.', 'woo-wholesale' ),
				array( 'status' => 401 )
			);
		}

		if ( 'woocommerce' !== $partner['type'] ) {
			return new WP_Error(
				'wwpro_partner_wrong_type',
				__( 'This partner is configured as a Shopify shop and cannot use the pull API.', 'woo-wholesale' ),
				array( 'status' => 403 )
			);
		}

		if ( ! WWPro_Partners::is_active( $partner ) ) {
			return new WP_Error(
				'wwpro_partner_inactive',
				__( 'This partner shop is paused or its wholesale role no longer exists.', 'woo-wholesale' ),
				array( 'status' => 403 )
			);
		}

		self::$current = $partner;

		return true;
	}

	/**
	 * Partner of the current request.
	 *
	 * @return array|null
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * Transient key of the rate limit counter for the calling IP.
	 *
	 * @return string
	 */
	private static function failure_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'wwpro_partner_fail_' . md5( $ip );
	}

	/**
	 * Whether the calling IP is currently blocked.
	 *
	 * @return bool
	 */
	private static function is_rate_limited() {
		return (int) get_transient( self::failure_key() ) >= self::MAX_FAILURES;
	}

	/**
	 * Count a failed authentication.
	 */
	private static function count_failure() {
		$key   = self::failure_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::FAILURE_WINDOW );
	}

	/**
	 * GET /partner/manifest
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function manifest( $request ) {
		$partner = self::$current;
		$since   = (int) $request->get_param( 'since' );

		WWPro_Partners::touch( $partner['id'], array( 'last_seen' => time() ) );

		return rest_ensure_response(
			array(
				'master'   => array(
					'name'     => get_bloginfo( 'name' ),
					'url'      => home_url( '/' ),
					'version'  => WWPRO_VERSION,
					'currency' => get_woocommerce_currency(),
				),
				'partner'  => array(
					'id'        => $partner['id'],
					'name'      => $partner['name'],
					'role'      => $partner['role'],
					'role_name' => WWPro_Roles::label( $partner['role'] ),
				),
				'policy'   => WWPro_Partners::policy( $partner ),
				'catalog'  => array(
					'products'   => WWPro_Product_Payload::count( $partner, $since ),
					'categories' => count( self::category_list( $partner ) ),
				),
				'now'      => time(),
			)
		);
	}

	/**
	 * Category list a partner receives.
	 *
	 * @param array $partner Partner.
	 * @return array[]
	 */
	private static function category_list( $partner ) {
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		);

		if ( ! empty( $partner['categories'] ) ) {
			$slugs = WWPro_Product_Payload::category_slugs( $partner['categories'] );
			if ( empty( $slugs ) ) {
				return array();
			}
			$args['slug'] = $slugs;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$parent = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
			$out[]  = array(
				'slug'        => $term->slug,
				'name'        => $term->name,
				'description' => $term->description,
				'parent_slug' => ( $parent instanceof WP_Term ) ? $parent->slug : '',
			);
		}

		return $out;
	}

	/**
	 * GET /partner/categories
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function categories( $request ) {
		return rest_ensure_response( array( 'categories' => self::category_list( self::$current ) ) );
	}

	/**
	 * GET /partner/products
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function products( $request ) {
		$partner = self::$current;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$since    = max( 0, (int) $request->get_param( 'since' ) );

		$result = WWPro_Product_Payload::page( $partner, $page, $per_page, $since );

		WWPro_Partners::touch( $partner['id'], array( 'last_seen' => time() ) );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /partner/heartbeat
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function heartbeat( $request ) {
		$partner = self::$current;

		$stats = array(
			'products'       => max( 0, (int) $request->get_param( 'products' ) ),
			'missing_images' => max( 0, (int) $request->get_param( 'missing_images' ) ),
			'failed_images'  => max( 0, (int) $request->get_param( 'failed_images' ) ),
			'version'        => sanitize_text_field( (string) $request->get_param( 'version' ) ),
			'reported'       => time(),
		);

		WWPro_Partners::touch(
			$partner['id'],
			array(
				'last_seen'  => time(),
				'last_sync'  => time(),
				'last_error' => sanitize_text_field( (string) $request->get_param( 'error' ) ),
				'stats'      => $stats,
			)
		);

		return rest_ensure_response(
			array(
				'ok'     => true,
				'policy' => WWPro_Partners::policy( $partner ),
			)
		);
	}
}
