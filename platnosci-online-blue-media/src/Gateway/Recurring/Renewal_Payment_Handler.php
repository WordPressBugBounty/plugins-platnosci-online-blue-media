<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Payment_Handler — automatic card-recurring renewal charges, triggered
 * by Flexible Subscriptions' scheduled-payment hook.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use RuntimeException;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Handles `woocommerce_scheduled_subscription_payment_bluemedia`.
 */
class Renewal_Payment_Handler {

	/**
	 * Registers the Flexible Subscriptions scheduled-payment hook. Safe to call from
	 * `Blue_Media_Gateway`'s constructor (unlike the two background-job hooks
	 * registered in `Plugin::before_init()` — see their docblocks): FS's own
	 * `AutomaticRequest::request_payment()` explicitly
	 * calls `WC()->payment_gateways()` before firing this exact hook, which guarantees
	 * `Blue_Media_Gateway` (and therefore this registration) already exists.
	 */
	public function register(): void {
		add_action( 'woocommerce_scheduled_subscription_payment_bluemedia', [ $this, 'handle' ], 10, 2 );
	}

	/**
	 * Handles Flexible Subscriptions' `woocommerce_scheduled_subscription_payment_bluemedia`.
	 *
	 * @param float    $amount        FS's own computed total — ignored; the exact
	 *                                charge amount is derived fresh from the renewal
	 *                                order itself (see normalize_amount_to_cents()
	 *                                usage below), which is what `send_charge()`
	 *                                actually transmits.
	 * @param WC_Order $renewal_order Renewal order to charge.
	 */
	public function handle( $amount, WC_Order $renewal_order ): void {
		if ( ! $renewal_order->needs_payment() ) {
			return;
		}

		$store        = new Recurring_Subscription_Store();
		$subscription = $store->find_subscription_for_renewal_order( $renewal_order );

		if ( ! $subscription instanceof Subscription || $subscription->is_finalized() ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Renewal_Payment_Handler] [no chargeable subscription] [renewal_order_id: %d]', $renewal_order->get_id() )
			);

			return;
		}

		$recurring_data = $store->get_recurring_data( $subscription );

		if ( null === $recurring_data ) {
			// No active instrument (never activated, or deactivated by RPDN/cancellation)
			// -- an odnowienie bez aktywnego powiązania never sends a charge.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Renewal_Payment_Handler] [no active recurring instrument] [renewal_order_id: %d]', $renewal_order->get_id() )
			);

			return;
		}

		try {
			( new Recurring_Background_Context() )->setup_for_currency( $recurring_data['currency'] );
		} catch ( RuntimeException $e ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Renewal_Payment_Handler] [currency context setup failed] [renewal_order_id: %d] [error: %s]',
					$renewal_order->get_id(),
					$e->getMessage()
				)
			);

			return;
		}

		$amount_cents = $this->normalize_amount_to_cents( (string) wc_format_decimal( $renewal_order->get_total(), 2 ) );

		if ( null === $amount_cents ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[Renewal_Payment_Handler] [could not normalise renewal order total] [renewal_order_id: %d]', $renewal_order->get_id() )
			);

			return;
		}

		// Carries everything a later validator (ITN's resolve_recurring_transactions())
		// needs to confirm a notification matches what was ACTUALLY sent, not merely
		// what the order's live total happens to be right now.
		$context = [
			'currency'       => $recurring_data['currency'],
			'service_id'     => $recurring_data['service_id'],
			'environment'    => $recurring_data['environment'],
			'source'         => 'auto',
			'gateway_id'     => $recurring_data['channel'],
			'gateway_choice' => 'explicit',
			'amount_cents'   => $amount_cents,
		];

		// Shared start->schedule-recovery->fail-fast sequence, reused
		// identically by MANUAL (Manual_Renewal_Payment_Handler) and ordinary one-off
		// repayment (Blue_Media_Gateway::process_payment()) -- behavior here is
		// unchanged from before this refactor.
		$attempt = ( new Renewal_Attempt_Lifecycle() )->begin( $renewal_order, $context );

		if ( null === $attempt ) {
			return; // Another attempt in progress, or the order settled concurrently.
		}

		$attempt_store = new Attempt_State_Store();
		$seq           = (int) $attempt['seq'];
		$order_id      = (string) $attempt['order_id'];

		// Real, fresh reads -- not a second call on the same in-memory objects
		// needs_payment() on the object received as this
		// method's argument would not reflect a concurrent change.
		$fresh_renewal_order = wc_get_order( $renewal_order->get_id() );

		if ( ! $fresh_renewal_order instanceof WC_Order || ! $fresh_renewal_order->needs_payment() ) {
			$attempt_store->abort_unsent( $renewal_order->get_id(), $seq, 'conditions_changed_before_send' );

			return;
		}

		$fresh_subscription = $store->find_subscription_for_renewal_order( $fresh_renewal_order );

		if ( ! $fresh_subscription instanceof Subscription
			|| $fresh_subscription->is_finalized()
			|| $fresh_subscription->is_manual()
		) {
			$attempt_store->abort_unsent( $renewal_order->get_id(), $seq, 'conditions_changed_before_send' );

			return;
		}

		$fresh_recurring_data = $store->get_recurring_data( $fresh_subscription );

		if ( $fresh_recurring_data !== $recurring_data ) {
			// Any change -- currency, channel, ServiceID, environment, or client_hash --
			// aborts. Never assume only client_hash matters.
			$attempt_store->abort_unsent( $renewal_order->get_id(), $seq, 'conditions_changed_before_send' );

			return;
		}

		try {
			$gateway = ( new Recurring_Background_Context() )->setup_for_currency( $recurring_data['currency'] );
		} catch ( RuntimeException $e ) {
			$attempt_store->abort_unsent( $renewal_order->get_id(), $seq, 'conditions_changed_before_send' );

			return;
		}

		if ( ! $attempt_store->mark_sent( $renewal_order->get_id(), $seq ) ) {
			return;
		}

		// SECURITY-SENSITIVE test seam -- see Recurring_Test_Outcome_Override's class
		// docblock for the three independent gates that make this a complete no-op
		// outside a developer's own sandbox-mode, admin-authenticated, wp-config.php-
		// opted-in environment. Skips the real HTTP call to Autopay entirely when (and
		// only when) all three gates pass and something has hooked the filter; mark_sent()
		// above still ran for real, so Attempt_State_Store's state machine looks exactly
		// like a real send that was resolved instantly.
		$forced = Recurring_Test_Outcome_Override::maybe_get_forced_outcome( $fresh_renewal_order, $context );

		if ( null !== $forced ) {
			( new Recurring_Result_Applier() )->apply( $renewal_order->get_id(), $seq, $forced['state'], $forced['reason'] );

			return;
		}

		$result = ( new Recurring_Api_Client( $gateway ) )->send_charge(
			$fresh_renewal_order,
			$order_id,
			(int) $fresh_recurring_data['channel'],
			(string) $fresh_recurring_data['client_hash'],
			Autopay_Payment_Protocol::RECURRING_ACTION_AUTO,
			$context
		);

		$this->handle_send_result( $renewal_order->get_id(), $seq, $result );
	}

	/**
	 * Maps a `send_charge()` result to an action. Only CHARGE_NOT_CONFIRMED is a
	 * definitive, verified pre-transaction rejection — every other outcome (including
	 * CHARGE_ACCEPTED, which explicitly does not mean the charge executed) leaves the
	 * attempt SENT for ITN/Renewal_Status_Check_Job to resolve.
	 *
	 * @param int   $renewal_order_id WooCommerce renewal order id.
	 * @param int   $seq              Attempt number.
	 * @param array $result           `Recurring_Api_Client::send_charge()` result.
	 */
	private function handle_send_result( int $renewal_order_id, int $seq, array $result ): void {
		if ( Recurring_Api_Client::CHARGE_NOT_CONFIRMED === $result['outcome'] ) {
			( new Recurring_Result_Applier() )->apply( $renewal_order_id, $seq, Attempt_State_Store::STATE_FAILURE, (string) $result['reason'] );

			return;
		}

		if ( '' !== (string) ( $result['remote_id'] ?? '' ) ) {
			( new Attempt_State_Store() )->record_remote_id( $renewal_order_id, $seq, (string) $result['remote_id'] );
		}

		if ( Recurring_Api_Client::CHARGE_CONTINUATION_REQUIRED === $result['outcome'] ) {
			// Never log redirect_url itself -- it may carry a token or other sensitive
			// data. Presence + remote_id only, for correlation.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Renewal_Payment_Handler] [AUTO charge unexpectedly required further customer interaction] [renewal_order_id: %d] [seq: %d] [remote_id: %s]',
					$renewal_order_id,
					$seq,
					(string) ( $result['remote_id'] ?? '' )
				)
			);
		}

		// CHARGE_ACCEPTED / CHARGE_CONTINUATION_REQUIRED / TRANSPORT_ERROR /
		// INVALID_RESPONSE / CREDENTIALS_UNAVAILABLE: none of these are a payment
		// outcome -- leave the attempt SENT, resolved later by ITN or recovery.
	}

	/**
	 * Same normalisation as `ITN_Webhook_Handler::normalize_amount_to_cents()` — kept
	 * as a separate copy rather than a new shared helper, consistent with this
	 * codebase's existing convention of duplicating small (few-line) amount-formatting
	 * logic rather than introducing a shared utility for it (re: `format_amount()`).
	 *
	 * @param string $raw Decimal amount string.
	 */
	private function normalize_amount_to_cents( string $raw ): ?int {
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
