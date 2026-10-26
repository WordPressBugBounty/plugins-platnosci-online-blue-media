<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Manual_Renewal_Payment_Handler — manual repayment of a failed renewal
 * order through order-pay, charging the saved recurring instrument
 * (RecurringAction=MANUAL).
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use WC_Order;

/**
 * Handles the MANUAL charge branch of Blue_Media_Gateway::process_payment() for
 * a renewal order whose instrument is available (Manual_Renewal_Eligibility).
 */
final class Manual_Renewal_Payment_Handler {

	/**
	 * Main gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway Main gateway instance (used only for
	 *                                    `get_return_url()` on the CHARGE_ACCEPTED
	 *                                    path -- the currency-correct instance used
	 *                                    to actually send the charge always comes
	 *                                    from Manual_Renewal_Eligibility::evaluate()).
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Handles the MANUAL charge for a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order being paid.
	 *
	 * @return array `WC_Payment_Gateway::process_payment()`-shaped result.
	 */
	public function handle( WC_Order $renewal_order ): array {
		$eval = Manual_Renewal_Eligibility::evaluate( $renewal_order );

		if ( ! $eval['eligible'] ) {
			// Caller (Blue_Media_Gateway::process_payment()) already compares this
			// against what was rendered and rejects a mismatch before reaching here
			// this is only a defensive second check.
			wc_add_notice( self::not_available_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		$recurring_data = $eval['recurring_data'];

		$amount_cents = self::normalize_amount_to_cents( (string) wc_format_decimal( $renewal_order->get_total(), 2 ) );

		if ( null === $amount_cents ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[Manual_Renewal_Payment_Handler] [could not normalise renewal order total] [renewal_order_id: %d]', $renewal_order->get_id() )
			);
			wc_add_notice( self::generic_error_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		$context = [
			'currency'       => $recurring_data['currency'],
			'service_id'     => $recurring_data['service_id'],
			'environment'    => $recurring_data['environment'],
			'source'         => 'manual',
			'gateway_id'     => (int) $recurring_data['channel'],
			'gateway_choice' => 'explicit',
			'amount_cents'   => $amount_cents,
		];

		$attempt = ( new Renewal_Attempt_Lifecycle() )->begin( $renewal_order, $context );

		if ( null === $attempt ) {
			// Nierozstrzygnięta próba blokuje kolejne obciążenie -- the atomic INSERT
			// inside start_new_attempt() is the only real gate; a concurrent AUTO/
			// another MANUAL click that won it a moment earlier is rejected here
			// without ever reaching send_charge().
			wc_add_notice( self::busy_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		$renewal_order_id = $renewal_order->get_id();
		$seq              = (int) $attempt['seq'];
		$order_id         = (string) $attempt['order_id'];
		$store            = new Attempt_State_Store();

		// Fresh re-check, same predicate as rendering, never the value computed
		// earlier in this same request.
		$fresh_renewal_order = wc_get_order( $renewal_order_id );

		if ( ! $fresh_renewal_order instanceof WC_Order || ! $fresh_renewal_order->needs_payment() ) {
			$store->abort_unsent( $renewal_order_id, $seq, 'conditions_changed_before_send' );
			wc_add_notice( self::busy_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		$fresh_eval = Manual_Renewal_Eligibility::evaluate( $fresh_renewal_order );

		if ( ! $fresh_eval['eligible'] || $fresh_eval['recurring_data'] !== $recurring_data ) {
			// Any change -- currency, channel, ServiceID, environment, or client_hash --
			// aborts. Never assume only client_hash matters.
			$store->abort_unsent( $renewal_order_id, $seq, 'conditions_changed_before_send' );
			wc_add_notice( self::not_available_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		// mark_sent() precedes the real HTTP call; its failure blocks the send
		// outright -- never call abort_unsent() past this point.
		if ( ! $store->mark_sent( $renewal_order_id, $seq ) ) {
			wc_add_notice( self::generic_error_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		$result = ( new Recurring_Api_Client( $fresh_eval['gateway'] ) )->send_charge(
			$fresh_renewal_order,
			$order_id,
			(int) $fresh_eval['recurring_data']['channel'],
			(string) $fresh_eval['recurring_data']['client_hash'],
			Autopay_Payment_Protocol::RECURRING_ACTION_MANUAL,
			$context
		);

		return $this->handle_send_result( $renewal_order_id, $seq, $result );
	}

	/**
	 * Maps a `send_charge()` result to a `process_payment()` return value. The
	 * attempt was already mark_sent() before this call -- no branch here ever
	 * calls abort_unsent() ("nie wnioskuj z dowolnego wyjątku, że żądanie nie
	 * zostało wysłane").
	 *
	 * @param int   $renewal_order_id WooCommerce renewal order id.
	 * @param int   $seq              Attempt number.
	 * @param array $result           `Recurring_Api_Client::send_charge()` result.
	 */
	private function handle_send_result( int $renewal_order_id, int $seq, array $result ): array {
		if ( '' !== (string) ( $result['remote_id'] ?? '' ) ) {
			( new Attempt_State_Store() )->record_remote_id( $renewal_order_id, $seq, (string) $result['remote_id'] );
		}

		if ( Recurring_Api_Client::CHARGE_NOT_CONFIRMED === $result['outcome'] ) {
			// Verified pre-transaction rejection from Autopay (hash+identifiers already
			// checked inside send_charge()) -- the only outcome here resolved directly,
			// not left for ITN/recovery.
			( new Recurring_Result_Applier() )->apply( $renewal_order_id, $seq, Attempt_State_Store::STATE_FAILURE, (string) $result['reason'] );

			// Never show the raw API reason to the customer --
			// it is recorded as an order note by Recurring_Result_Applier for admins.
			wc_add_notice( self::declined_notice(), 'error' );

			return [ 'result' => 'failure' ];
		}

		if ( Recurring_Api_Client::CHARGE_CONTINUATION_REQUIRED === $result['outcome']
			&& '' !== (string) ( $result['redirect_url'] ?? '' )
		) {
			// 3DS/CVC continuation required -- the attempt stays SENT, ITN resolves it
			// after the customer returns.
			return [
				'result'   => 'success',
				'redirect' => (string) $result['redirect_url'],
			];
		}

		if ( Recurring_Api_Client::CHARGE_ACCEPTED === $result['outcome'] ) {
			// Synchronous CONFIRMED does not mean the charge executed -- send the
			// customer to the thank-you page; ITN/recovery resolve the real outcome.
			$order = wc_get_order( $renewal_order_id );

			return [
				'result'   => 'success',
				'redirect' => $order instanceof WC_Order ? $this->gateway->get_return_url( $order ) : '',
			];
		}

		// TRANSPORT_ERROR / INVALID_RESPONSE / CREDENTIALS_UNAVAILABLE / an unexpected
		// CHARGE_CONTINUATION_REQUIRED without a redirect_url: uncertain whether the
		// request reached Autopay. Never guess an outcome here -- the attempt stays
		// SENT for ITN/recovery to resolve authoritatively.
		blue_media()->get_woocommerce_logger()->log_warning(
			sprintf(
				'[Manual_Renewal_Payment_Handler] [inconclusive send result, left SENT] [renewal_order_id: %d] [seq: %d] [outcome: %s]',
				$renewal_order_id,
				$seq,
				(string) $result['outcome']
			)
		);

		wc_add_notice( self::pending_confirmation_notice(), 'error' );

		return [ 'result' => 'failure' ];
	}

	/**
	 * Customer-facing notice: eligibility changed / no longer available.
	 */
	private static function not_available_notice(): string {
		return __( 'This payment method is no longer available for this order. Please refresh the page.', 'platnosci-online-blue-media' );
	}

	/**
	 * Customer-facing notice: another attempt is already in progress.
	 */
	private static function busy_notice(): string {
		return __( 'Payment for this order is already being processed. Please try again shortly.', 'platnosci-online-blue-media' );
	}

	/**
	 * Customer-facing notice: verified pre-transaction rejection. Never includes
	 * the raw API reason.
	 */
	private static function declined_notice(): string {
		return __( 'Your saved card was declined. Please contact us or try again later.', 'platnosci-online-blue-media' );
	}

	/**
	 * Customer-facing notice: outcome uncertain, resolved later by ITN/recovery.
	 */
	private static function pending_confirmation_notice(): string {
		return __( 'Payment could not be confirmed immediately. Please check your order status shortly.', 'platnosci-online-blue-media' );
	}

	/**
	 * Customer-facing notice: generic, non-specific failure.
	 */
	private static function generic_error_notice(): string {
		return __( 'Payment could not be started. Please try again.', 'platnosci-online-blue-media' );
	}

	/**
	 * Same normalisation as Renewal_Payment_Handler::normalize_amount_to_cents() --
	 * kept as a separate copy, consistent with this codebase's existing convention
	 * of duplicating small amount-formatting logic rather than introducing a shared
	 * utility for it (re: format_amount()).
	 *
	 * @param string $raw Decimal amount string.
	 */
	private static function normalize_amount_to_cents( string $raw ): ?int {
		$raw = str_replace( ',', '.', trim( $raw ) );

		if ( 1 !== preg_match( '/^-?\d+(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}

		$parts       = array_pad( explode( '.', $raw, 2 ), 2, '' );
		$fraction    = str_pad( substr( $parts[1], 0, 2 ), 2, '0' );
		$is_negative = 0 === strpos( $parts[0], '-' );
		$int_part    = ltrim( $is_negative ? substr( $parts[0], 1 ) : $parts[0], '0' );
		$int_part    = '' === $int_part ? '0' : $int_part;
		$cents       = (int) ( $int_part . $fraction );

		return $is_negative ? -$cents : $cents;
	}
}
