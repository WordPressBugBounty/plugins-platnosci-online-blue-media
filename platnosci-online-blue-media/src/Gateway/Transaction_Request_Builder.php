<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Transaction_Request_Builder — builds and signs Autopay API payment parameters.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Builds and signs payment request parameters for the Autopay API.
 *
 * Re-instantiated by Blue_Media_Gateway::setup_variables() on each currency switch.
 */
class Transaction_Request_Builder {

	/**
	 * @var string
	 */
	private $service_id;

	/**
	 * @var string
	 */
	private $private_key;

	/**
	 * Create builder for a specific currency context.
	 *
	 * @param string $service_id  Autopay service ID for the active currency.
	 * @param string $private_key Autopay private key for the active currency.
	 */
	public function __construct( string $service_id, string $private_key ) {
		$this->service_id  = $service_id;
		$this->private_key = $private_key;
	}

	/**
	 * Build a signed parameter array for an initial payment request.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param int      $gateway_id Autopay channel ID (0 = let gateway decide).
	 *
	 * @return array Params including Hash.
	 */
	public function build( WC_Order $order, int $gateway_id = 0 ): array {
		$params = [
			'ServiceID'             => $this->service_id,
			'OrderID'               => $order->get_id(),
			'Amount'                => $this->get_price( $order ),
			'GatewayID'             => $gateway_id,
			'Currency'              => blue_media()->resolve_blue_media_currency_symbol(),
			'CustomerEmail'         => $order->get_billing_email(),
			'PlatformName'          => 'Woocommerce',
			'PlatformVersion'       => WC_VERSION,
			'PlatformPluginVersion' => blue_media()->get_plugin_version(),
		];

		return array_merge( $params, [ 'Hash' => $this->hash( $params ) ] );
	}

	/**
	 * Hash a parameter array with the configured private key (SHA-256).
	 *
	 * @param array $params Ordered key-value pairs to hash.
	 *
	 * @return string Hexadecimal SHA-256 hash.
	 */
	public function hash( array $params ): string {
		$private_key_secured     = $this->secure_key( $this->private_key );
		$imploded_string_secured = implode( '|', $params ) . '|' . $private_key_secured;
		$imploded_string         = implode( '|', $params ) . '|' . $this->private_key;

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[hash_parameters] [fields_count: %d]', count( $params ) )
		);

		return hash( 'sha256', $imploded_string );
	}

	/**
	 * Mask all but the last 4 characters of the private key for safe logging.
	 *
	 * @param string $key Raw private key.
	 *
	 * @return string Masked key.
	 */
	private function secure_key( string $key ): string {
		$length = strlen( $key );
		if ( $length <= 4 ) {
			return $key;
		}

		return str_repeat( '*', $length - 4 ) . substr( $key, -4 );
	}

	/**
	 * Format WC order total as a decimal string accepted by the Autopay API.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return string Decimal price string, e.g. "19.99".
	 */
	private function get_price( WC_Order $order ): string {
		$price = str_replace( ',', '.', (string) $order->get_total( false ) );
		if ( false === strpos( $price, '.' ) ) {
			$price .= '.00';
		}

		return $price;
	}
}
