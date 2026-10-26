<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Transaction_Request_Builder — builds and signs Autopay API payment parameters.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Logger;
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
	 * @param WC_Order    $order             WooCommerce order.
	 * @param int         $gateway_id        Autopay channel ID (0 = let gateway decide).
	 * @param array|null  $recurring_params  Optional recurring-activation fields
	 *                                       (`RecurringAcceptanceState`/`RecurringAction`),
	 *                                       merged in only when not
	 *                                       null -- every existing caller (BLIK/PBL/GPay)
	 *                                       passes null and gets byte-identical output to
	 *                                       before this parameter existed. Values must
	 *                                       never be empty strings: `hash()` joins every
	 *                                       array value with `implode()` unconditionally,
	 *                                       so an empty value would still occupy a
	 *                                       position in the hashed string instead of
	 *                                       being skipped, unlike Autopay's documented
	 *                                       "absent optional field" rule.
	 * @param string|null $order_id_override Per-attempt OrderID
	 *                                       (Attempt_State_Store::build_order_id()) for a
	 *                                       renewal-order repayment. Used only when not
	 *                                       null -- every existing caller passes null and
	 *                                       OrderID remains `$order->get_id()`, unchanged.
	 * @param string|null $amount_override   Technical `Amount` for an `INIT_WITH_REFUND`
	 *                                       card-recurring activation (order total is
	 *                                       0). Used only when not null -- every other
	 *                                       caller passes null and `Amount` remains
	 *                                       `$order->get_total()`.
	 *
	 * @return array Params including Hash.
	 */
	public function build( WC_Order $order, int $gateway_id = 0, ?array $recurring_params = null, ?string $order_id_override = null, ?string $amount_override = null ): array {
		$params = [
			'ServiceID'     => $this->service_id,
			'OrderID'       => $order_id_override ?? $order->get_id(),
			'Amount'        => $amount_override ?? $this->get_price( $order ),
			'GatewayID'     => $gateway_id,
			'Currency'      => blue_media()->resolve_blue_media_currency_symbol(),
			'CustomerEmail' => $order->get_billing_email(),
		];

		// Platform* fields are omitted entirely -- not just left out of the hash --
		// ONLY for the 1503/INIT_WITH_PAYMENT call ($recurring_params !== null; today
		// the only caller passing it, Blue_Media_Gateway.php process_payment()
		// CARD_RECURRING_CHANNEL branch). Every other caller (BLIK/PBL/GPay,
		// $recurring_params === null) still sends all three fields as before.
		if ( null === $recurring_params ) {
			$params['PlatformName']          = 'Woocommerce';
			$params['PlatformVersion']       = WC_VERSION;
			$params['PlatformPluginVersion'] = blue_media()->get_plugin_version();
		} else {
			$params = array_merge( $params, $recurring_params );

			( new Recurring_Logger() )->debug( 'platform_fields_omitted', [
				'order_id' => $params['OrderID'],
				'note'     => 'PlatformName/PlatformVersion/PlatformPluginVersion omitted before hash for this request',
			] );

			( new Recurring_Logger() )->debug( 'request_params_before_hash', [
				'order_id'                      => $params['OrderID'],
				'field_names'                   => array_keys( $params ),
				'field_count'                   => count( $params ),
				'GatewayID'                     => $params['GatewayID'],
				'Currency'                      => $params['Currency'],
				'Amount'                        => $params['Amount'],
				'CustomerEmail'                 => $params['CustomerEmail'],
				'RecurringAcceptanceState'      => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE ] ?? null,
				'RecurringAction'               => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACTION ] ?? null,
				'RecurringAcceptanceID_present' => array_key_exists( 'RecurringAcceptanceID', $params ),
			] );
		}

		$hash = $this->hash( $params );

		if ( null !== $recurring_params ) {
			( new Recurring_Logger() )->debug( 'hash_computed', [
				'order_id'      => $params['OrderID'],
				'hash_present'  => true,
				'hash_length'   => strlen( $hash ),
				'field_count'   => count( $params ),
				'hashed_fields' => array_keys( $params ),
			] );
		}

		return array_merge( $params, [ 'Hash' => $hash ] );
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
