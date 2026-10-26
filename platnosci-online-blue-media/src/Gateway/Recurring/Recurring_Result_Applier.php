<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Recurring_Result_Applier — the single, shared path for turning a resolved
 * card-recurring charge attempt into WC_Order side effects.
 *
 * Used identically by ITN_Webhook_Handler, Renewal_Status_Check_Job, and
 * Renewal_Attempt_Sweep — none of those callers duplicate this
 * mapping themselves.
 *
 * Flexible Subscriptions' own OrderStatusListener/ProcessPaidRenewal (verified in
 * FS source, not assumed) already advances the subscription period safely and
 * idempotently once the renewal order's WC status actually transitions.
 *
 * `ProcessPaidRenewal::execute()` itself never touches
 * `Subscription` status, BUT the method it calls to advance the billing period,
 * `Subscription::advance_billing_period()`
 * (`flexible-subscriptions/src/Subscription/Subscription.php:368-381`), does —
 * unconditionally: `if ( ! $this->has_status('active') ) { $this->set_status('active'); }`,
 * followed by `record_event(new SubscriptionActivated($this))`, which
 * `SyncScheduleOnSubscriptionChange::on_subscription_activated()` turns into a
 * freshly (re)scheduled `fsub/subscription/payment_request/process` job. Since
 * `OrderStatusListener` is wired to the GENERIC `woocommerce_order_status_changed`
 * hook (fired synchronously from `payment_complete()`/`update_order_status()`
 * below) for EVERY renewal order regardless of who changed its status, a late
 * SUCCESS for an attempt that was in flight when RPDN paused the subscription
 * (`on-hold`) reactivates it as an unintended side effect — `is_finalized()`
 * (which blocks `ProcessPaidRenewal` outright) does not cover `on-hold`, only
 * `cancelled`/`pending-cancel`/`expired`/`trash`/`switched`. This class therefore
 * DOES touch `Subscription`, but only to detect and immediately revert exactly
 * this one unintended reactivation (see undo_unintended_fs_reactivation()) — never
 * to prevent the underlying, already-sent charge from being correctly recorded.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Applies the WC_Order side effects of a resolved card-recurring attempt.
 */
class Recurring_Result_Applier {

	/**
	 * Attempt a state transition for a specific attempt, then always try to finish
	 * whatever side effects that exact attempt still owes — regardless of whether
	 * THIS call's own resolve() actually changed anything. This is what lets a
	 * process that crashed between a previous, successful resolve() and its side
	 * effects be recovered by any later caller (another ITN retry, a status-check
	 * job, or a sweep pass) without re-deriving the outcome from scratch
	 * .
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param int    $seq              Attempt number this result belongs to.
	 * @param string $new_state        One of Attempt_State_Store::STATE_SUCCESS/STATE_FAILURE/STATE_UNKNOWN.
	 * @param string $reason           Short machine-readable reason, stored for diagnostics.
	 */
	public function apply( int $renewal_order_id, int $seq, string $new_state, string $reason ): void {
		( new Attempt_State_Store() )->resolve( $renewal_order_id, $seq, $new_state, $reason );

		$this->finish_pending_side_effects( $renewal_order_id, $seq );
	}

	/**
	 * Finishes the side effects of ONE specific attempt (`$seq`) if — and only if —
	 * it is still the current attempt for `$renewal_order_id`, it has reached a
	 * terminal state, and its side effects are not yet applied. Tied to `$seq`
	 * deliberately: a rejected/late notification about an
	 * OLD attempt must never finish the side effects of whatever NEWER attempt
	 * happens to be current now — if the current attempt's seq does not match
	 * `$seq`, this is a pure no-op.
	 *
	 * Mutual exclusion against a second, near-simultaneous caller is provided by
	 * Attempt_State_Store::try_claim_side_effects() — a bounded mitigation, not a
	 * proof of exactly-once execution (see that method's docblock). Release of the
	 * lock is attempted on every call for SUCCESS/FAILURE, even when this call did
	 * not itself just apply the side effects, so a process that crashed between
	 * mark_side_effects_applied() and try_release_lock() is recovered the next time
	 * anything calls this method for the same attempt.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number whose side effects should be finished.
	 */
	public function finish_pending_side_effects( int $renewal_order_id, int $seq ): void {
		$store   = new Attempt_State_Store();
		$attempt = $store->get_current_attempt( $renewal_order_id );

		if ( null === $attempt || (int) ( $attempt['seq'] ?? -1 ) !== $seq ) {
			// Not the current attempt any more (superseded, or already released) --
			// never act on behalf of whatever attempt IS current now.
			return;
		}

		$state = (string) ( $attempt['state'] ?? '' );

		if ( ! in_array( $state, [ Attempt_State_Store::STATE_SUCCESS, Attempt_State_Store::STATE_FAILURE, Attempt_State_Store::STATE_UNKNOWN ], true ) ) {
			return; // Not terminal yet -- nothing to finish.
		}

		if ( true !== ( $attempt['side_effects_applied'] ?? false ) ) {
			$claimed = $store->try_claim_side_effects( $renewal_order_id, $seq );

			if ( null !== $claimed ) {
				$this->apply_side_effects( $renewal_order_id, $seq, $claimed );
				$store->mark_side_effects_applied( $renewal_order_id, $seq );
			}
			// If the claim was not granted (already applied by a concurrent caller in
			// the meantime, or another caller's claim has not yet expired), fall
			// through to the release attempt below -- it is a safe no-op either way.
		}

		if ( Attempt_State_Store::STATE_UNKNOWN !== $state ) {
			// LOCKED_TERMINAL_STATES only (SUCCESS/FAILURE) -- try_release_lock()
			// itself refuses UNKNOWN regardless, this check just avoids a pointless
			// call/log line for the state we know it will always refuse.
			$store->try_release_lock( $renewal_order_id, (string) $seq );
		}
	}

	/**
	 * Applies the actual WC_Order mutation for one claimed, terminal attempt record.
	 * Called only once the caller has WON the side-effects claim for this exact
	 * record — never re-reads the store, so it always acts
	 * on the exact state it was granted, not a possibly-since-changed one.
	 *
	 * @param int   $renewal_order_id WooCommerce renewal order id.
	 * @param int   $seq              Attempt number (for logging only).
	 * @param array $attempt          The claimed attempt record.
	 */
	private function apply_side_effects( int $renewal_order_id, int $seq, array $attempt ): void {
		$order = wc_get_order( $renewal_order_id );

		if ( ! $order instanceof WC_Order ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Recurring_Result_Applier] [apply_side_effects failed: renewal order not found] [order_id: %d] [seq: %d]',
					$renewal_order_id,
					$seq
				)
			);

			return;
		}

		$gateway = blue_media()->get_blue_media_gateway();
		$reason  = (string) ( $attempt['reason'] ?? '' );
		$context = (array) ( $attempt['context'] ?? [] );

		switch ( $attempt['state'] ) {
			case Attempt_State_Store::STATE_SUCCESS:
				$subscription_store = new Recurring_Subscription_Store();
				$subscription       = $subscription_store->find_subscription_for_renewal_order( $order );
				// Snapshot BEFORE payment_complete()/update_order_status() below --
				// those are exactly what triggers FS's own, synchronous
				// woocommerce_order_status_changed -> ProcessPaidRenewal ->
				// advance_billing_period() chain (see class docblock).
				$was_deactivated = $subscription instanceof Subscription
					&& null === $subscription_store->get_recurring_data( $subscription );

				if ( $order->needs_payment() ) {
					$order->payment_complete( (string) ( $attempt['remote_id'] ?? '' ) );
				}

				if ( $gateway instanceof Blue_Media_Gateway ) {
					$gateway->update_order_status(
						$order,
						$gateway->get_currency_aware_option( 'wc_payment_status_on_bm_success', 'completed' ),
						'Autopay recurring: SUCCESS'
					);
				}

				do_action(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name with bm_ prefix; reuses the existing, documented ITN hook.
					sprintf( 'bm_order_bm_int_status_%s_processed', Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID ),
					$order
				);

				self::update_polling_status_meta( $order, $context, Blue_Media_Gateway::ITN_SUCCESS_STATUS_ID );
				self::record_last_attempt_snapshot( $order, $seq, $attempt, $reason );

				if ( $was_deactivated && $subscription instanceof Subscription ) {
					$this->undo_unintended_fs_reactivation( $subscription );
				}
				break;

			case Attempt_State_Store::STATE_FAILURE:
				if ( $gateway instanceof Blue_Media_Gateway ) {
					$gateway->update_order_status(
						$order,
						$gateway->get_currency_aware_option( 'wc_payment_status_on_bm_failure', 'failed' ),
						sprintf( 'Autopay recurring: FAILURE (%s)', $reason )
					);
				}

				self::update_polling_status_meta( $order, $context, Blue_Media_Gateway::ITN_FAILURE_STATUS_ID );
				self::record_last_attempt_snapshot( $order, $seq, $attempt, $reason );
				break;

			case Attempt_State_Store::STATE_UNKNOWN:
				// No WC status change -- the outcome is genuinely unknown, guessing a
				// status would be worse than leaving it as-is. Only a diagnostic note.
				$order->add_order_note(
					sprintf(
						/* translators: %s: machine-readable reason the outcome could not be determined. */
						__( 'Autopay recurring: charge outcome could not be determined and requires manual review (reason: %s).', 'platnosci-online-blue-media' ),
						$reason
					)
				);
				break;
		}

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf(
				'[Recurring_Result_Applier] [apply_side_effects] [order_id: %d] [seq: %d] [state: %s]',
				$renewal_order_id,
				$seq,
				$attempt['state']
			)
		);
	}

	/**
	 * Updates the frontend polling status meta (Payment_Status_Controller /
	 * `bm_wc_order_id`-keyed AJAX polling, shared by card-widget/GPay/BLIK-0) for a
	 * card-recurring attempt. Only 'ordinary' attempts are ever
	 * polled by that controller -- AUTO has no browser, MANUAL does not offer any
	 * of the three synchronous channels this polling exists for -- so this is a
	 * no-op for every other source, on purpose.
	 *
	 * Called from the SAME, seq-gated code path for both ITN and recovery
	 * (Recurring_Result_Applier::apply()/finish_pending_side_effects() is the
	 * single entry point both use) and from the two PENDING branches
	 * (ITN_Webhook_Handler::resolve_recurring_transactions(),
	 * Renewal_Status_Check_Job::handle_check_result()) -- never duplicated as a
	 * separate model, matching the "wspólna, skorelowana obsługa" requirement.
	 *
	 * @param WC_Order $order        Renewal order.
	 * @param array    $context      Attempt context (as stored on the attempt record).
	 * @param string   $itn_status_id One of Blue_Media_Gateway::ITN_*_STATUS_ID.
	 */
	public static function update_polling_status_meta( WC_Order $order, array $context, string $itn_status_id ): void {
		if ( 'ordinary' !== (string) ( $context['source'] ?? '' ) ) {
			return;
		}

		$order->update_meta_data( 'bm_order_itn_status', $itn_status_id );
		$order->save_meta_data();
	}

	/**
	 * Diagnostic-only snapshot of the last resolved attempt, written INSIDE the same
	 * side-effects-claim-protected path that is about to delete the live
	 * Attempt_State_Store record. Never a source of truth for any
	 * decision -- only Attempt_State_Store::get_current_attempt() (while the record
	 * still exists) is. Safe against overwriting a newer attempt's result with an
	 * older one: this is only ever reached for the exact `$seq` this call just
	 * (re)confirmed is still the current attempt (try_claim_side_effects() already
	 * verified that before apply_side_effects() was called at all).
	 *
	 * @param WC_Order $order   Renewal order.
	 * @param int      $seq     Attempt number.
	 * @param array    $attempt Claimed attempt record.
	 * @param string   $reason  Machine-readable reason stored on the attempt.
	 */
	private static function record_last_attempt_snapshot( WC_Order $order, int $seq, array $attempt, string $reason ): void {
		$order->update_meta_data(
			'_autopay_last_attempt_snapshot',
			wp_json_encode(
				[
					'seq'         => $seq,
					'state'       => (string) ( $attempt['state'] ?? '' ),
					'source'      => (string) ( $attempt['context']['source'] ?? '' ),
					'reason'      => $reason,
					'remote_id'   => (string) ( $attempt['remote_id'] ?? '' ),
					'resolved_at' => time(),
				]
			)
		);
		$order->save_meta_data();
	}

	/**
	 * Reverts FS's own, unintended reactivation of a subscription that WE had
	 * already deactivated (RPDN or cancellation) before this late SUCCESS resolved
	 * (see class docblock for the exact FS call chain). Only relevant for `on-hold`
	 * — a `cancelled`/`pending-cancel` subscription can never reach this path in the
	 * first place, because `Subscription::is_finalized()` (which covers both) makes
	 * `ProcessPaidRenewal::execute()` return before it ever calls
	 * `advance_billing_period()`.
	 *
	 * This does NOT try to stop or unwind the charge itself — it was already
	 * correctly recorded above (payment_complete()/update_order_status()). It only
	 * reverts the side effect FS's own code performed on the SUBSCRIPTION, which is
	 * wrong specifically because WE, not FS, are the ones who decided this
	 * instrument/subscription should stay blocked.
	 *
	 * @param Subscription $subscription Subscription the resolved renewal order belongs to.
	 */
	private function undo_unintended_fs_reactivation( Subscription $subscription ): void {
		$fresh = wc_get_order( $subscription->get_id() );

		if ( ! $fresh instanceof Subscription || ! $fresh->has_status( 'active' ) ) {
			return; // FS did not reactivate it (or something else already handled it) -- nothing to undo.
		}

		$paused = blue_media()->get_fs_lifecycle_bridge()->pause( $fresh );

		blue_media()->get_woocommerce_logger()->log_warning(
			sprintf(
				'[Recurring_Result_Applier] [reverted unintended FS reactivation after a late SUCCESS resolved a deactivated instrument] [subscription_id: %d] [pause_result: %s]',
				$fresh->get_id(),
				$paused ? 'ok' : 'failed'
			)
		);

		if ( ! $paused ) {
			( new Recurring_Subscription_Store() )->mark_fs_sync_pending( $fresh );

			if ( ! Fs_Pause_Sync_Retry_Job::schedule_once( $fresh->get_id() ) ) {
				blue_media()->get_woocommerce_logger()->log_error(
					sprintf(
						'[Recurring_Result_Applier] [pause sync retry could not be scheduled — manual review required] [subscription_id: %d]',
						$fresh->get_id()
					)
				);
			}
		}
	}
}
