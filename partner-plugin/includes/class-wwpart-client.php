<?php
/**
 * HTTP client for the supplier shop.
 *
 * Talks to the "wwpro/v1/partner" routes of Woo Wholesale Pro. Every call is
 * authenticated with the partner key as a bearer token; the key itself never
 * appears in a URL, so it does not end up in access logs.
 *
 * @package WooWholesalePartner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplier client (static).
 */
class WWPart_Client {

	/**
	 * REST namespace of the supplier plugin.
	 */
	const ROUTE_BASE = '/wp-json/wwpro/v1/partner';

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 30;

	/**
	 * Base URL of the supplier shop.
	 *
	 * @return string
	 */
	public static function master_url() {
		return untrailingslashit( (string) WWPart_Settings::get( 'master_url' ) );
	}

	/**
	 * Host name of the supplier shop, used to keep downloads on that host.
	 *
	 * @return string
	 */
	public static function master_host() {
		$host = wp_parse_url( self::master_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Full URL of a route.
	 *
	 * @param string $route Route below the namespace.
	 * @param array  $args  Query arguments.
	 * @return string
	 */
	public static function url( $route, $args = array() ) {
		$url = self::master_url() . self::ROUTE_BASE . '/' . ltrim( $route, '/' );
		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method GET or POST.
	 * @param string $route  Route.
	 * @param array  $args   Query arguments (GET) or body (POST).
	 * @return array|WP_Error Decoded response.
	 */
	private static function request( $method, $route, $args = array() ) {
		if ( ! WWPart_Settings::is_connected() ) {
			return new WP_Error( 'wwpart_not_connected', __( 'No supplier shop is configured yet.', 'woo-wholesale-partner' ) );
		}

		$key = WWPart_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'wwpart_no_key', __( 'The stored partner key could not be read. Please enter it again.', 'woo-wholesale-partner' ) );
		}

		$options = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$options['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$options['body']                    = wp_json_encode( $args );
			$url                                = self::url( $route );
		} else {
			$url = self::url( $route, $args );
		}

		$response = wp_remote_request( $url, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status || 403 === $status ) {
			$message = ( is_array( $body ) && isset( $body['message'] ) )
				? (string) $body['message']
				: __( 'The supplier shop refused the partner key.', 'woo-wholesale-partner' );
			return new WP_Error( 'wwpart_unauthorized', $message );
		}

		if ( 404 === $status ) {
			return new WP_Error( 'wwpart_not_found', __( 'The supplier shop did not answer on the partner route. Is Woo Wholesale Pro installed and up to date there?', 'woo-wholesale-partner' ) );
		}

		if ( 429 === $status ) {
			return new WP_Error( 'wwpart_rate_limited', __( 'The supplier shop is rate limiting the connection. Please try again in a few minutes.', 'woo-wholesale-partner' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = ( is_array( $body ) && isset( $body['message'] ) ) ? (string) $body['message'] : '';
			return new WP_Error(
				'wwpart_http',
				'' !== $message ? $message : sprintf(
					/* translators: %d: HTTP status code */
					__( 'The supplier shop answered with HTTP status %d.', 'woo-wholesale-partner' ),
					$status
				)
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wwpart_bad_json', __( 'The answer of the supplier shop could not be read.', 'woo-wholesale-partner' ) );
		}

		return $body;
	}

	/**
	 * GET /manifest - connection test, policy and totals.
	 *
	 * @return array|WP_Error
	 */
	public static function manifest() {
		return self::request( 'GET', 'manifest' );
	}

	/**
	 * GET /categories
	 *
	 * @return array|WP_Error
	 */
	public static function categories() {
		return self::request( 'GET', 'categories' );
	}

	/**
	 * GET /products
	 *
	 * @param int $page     Page (1 based).
	 * @param int $per_page Page size.
	 * @param int $since    Modified-since timestamp.
	 * @return array|WP_Error
	 */
	public static function products( $page = 1, $per_page = 10, $since = 0 ) {
		return self::request(
			'GET',
			'products',
			array(
				'page'     => max( 1, (int) $page ),
				'per_page' => max( 1, (int) $per_page ),
				'since'    => max( 0, (int) $since ),
			)
		);
	}

	/**
	 * POST /heartbeat - report the local state back to the supplier.
	 *
	 * @param array $stats Stats.
	 * @return array|WP_Error
	 */
	public static function heartbeat( array $stats ) {
		return self::request(
			'POST',
			'heartbeat',
			array(
				'products'       => isset( $stats['products'] ) ? (int) $stats['products'] : 0,
				'missing_images' => isset( $stats['missing_images'] ) ? (int) $stats['missing_images'] : 0,
				'failed_images'  => isset( $stats['failed_images'] ) ? (int) $stats['failed_images'] : 0,
				'version'        => WWPART_VERSION,
				'error'          => isset( $stats['error'] ) ? (string) $stats['error'] : '',
			)
		);
	}
}
