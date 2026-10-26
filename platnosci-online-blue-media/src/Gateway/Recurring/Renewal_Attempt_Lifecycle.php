<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Attempt_Lifecycle — the shared "start an attempt safely" sequence:
 * start_new_attempt() -> schedule recovery -> fail-fast if
 * scheduling failed. Used identically by AUTO (Renewal_Payment_Handler),
 * MANUAL (Manual_Renewal_Payment_Handler) and ordinary one-off repayment
 * (Blue_Media_Gateway::process_payment()), so none of them re-implement the
 * attempt model themselves ("nie duplikuj modelu prób w czterech handlerach").
 *
 * Deliberately stops here: the "fresh re-check right before send" and the
 * mark_sent()/send step differ enough per caller (different data to
 * re-verify, different transport -- Recurring_Api_Client vs. four legacy
 * channel builders) that forcing them into this class would hide real
 * differences behind a false shared abstraction.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Starts a card-recurring renewal charge attempt and its recovery safety net.
 */
final class Renewal_Attempt_Lifecycle {

	/**
	 * Starts a new attempt and schedules its recovery safety net.
	 *
	 * @param WC_Order $renewal_order Renewal order to charge.
	 * @param array    $context       Attempt context (currency/service_id/environment/
	 *                                source/gateway_id/gateway_choice/amount_cents, ...).
	 *
	 * @return array|null The started attempt record, with an added 'order_id' key
	 *                     (Attempt_State_Store::build_order_id() for this attempt),
	 *                     or null when blocked: another attempt is open, the order
	 *                     settled concurrently, or recovery scheduling failed.
	 */
	public function begin( WC_Order $renewal_order, array $context ): ?array {
		// A brand-new attempt makes any previous attempt's polling status stale --
		// clear it so a customer polling for THIS attempt's outcome never sees the
		// previous attempt's FAILURE/SUCCESS.
		$renewal_order->delete_meta_data( 'bm_order_itn_status' );
		$renewal_order->save_meta_data();

		$store   = new Attempt_State_Store();
		$attempt = $store->start_new_attempt( $renewal_order->get_id(), $context );

		if ( null === $attempt ) {
			return null;
		}

		$seq = (int) $attempt['seq'];

		if ( ! Renewal_Status_Check_Job::schedule( $renewal_order->get_id(), $seq, 1 ) ) {
			// Nothing has been sent yet -- a safe withdrawal, not a payment FAILURE.
			$store->abort_unsent( $renewal_order->get_id(), $seq, 'recovery_scheduling_failed' );

			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Renewal_Attempt_Lifecycle] [recovery scheduling failed, aborted before send] [renewal_order_id: %d] [seq: %d]',
					$renewal_order->get_id(),
					$seq
				)
			);

			return null;
		}

		$attempt['order_id'] = Attempt_State_Store::build_order_id( $renewal_order->get_id(), $seq );

		return $attempt;
	}
}
