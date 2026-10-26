<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Subscription_Cancellation_Listener — reacts to a subscription cancellation
 * already performed by Flexible Subscriptions: blocks the instrument locally,
 * unconditionally, and best-effort deactivates it externally.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Listens for FS's own, terminal "cancelled" status transition and reacts to it —
 * never initiates cancellation itself.
 */
class Subscription_Cancellation_Listener {

	/**
	 * Statuses a subscription can NEVER leave except to `trash` (FS's own
	 * `ALLOWED_STATUS_TRANSITIONS`, `flexible-subscriptions/src/Subscription/Subscription.php:49-81`
	 * — `cancelled`/`expired`/`trash` map only to `trash` or nothing; `switched`
	 * appears in FS's own `is_finalized()` list but is not reachable through that
	 * transition table at all in the installed version, included here defensively).
	 * Any OTHER status (`pending`, `on-hold`, `active`, `pending-cancel`) can, per
	 * that same table, still transition back to `active` — so a subscription in one
	 * of those is still a genuinely entitled user of a shared ClientHash, even
	 * `pending-cancel`, which is explicitly reversible.
	 */
	private const CH_SHARING_TERMINAL_STATUSES = [ 'cancelled', 'expired', 'trash', 'switched' ];

	/**
	 * Registers the listener. MUST be called unconditionally, early
	 * (`Plugin::before_init()`) — `fsub/subscription/status/updated/to/cancelled`
	 * (`flexible-subscriptions/src/Subscription/Subscription.php:456-467`) can fire
	 * from a bare Action Scheduler worker running FS's own scheduled end-of-period
	 * cancellation (`SubscriptionScheduledCancel`, `fsub/subscription/cancel`),
	 * which has no reason to construct `Blue_Media_Gateway` on its own — identical
	 * reasoning to `Renewal_Status_Check_Job`/`Fs_Lifecycle_Bridge`.
	 */
	public function register(): void {
		add_action( 'fsub/subscription/status/updated/to/cancelled', [ $this, 'handle' ], 10, 2 );
	}

	/**
	 * Hook callback. Fires AFTER `$subscription->save()` (WC_Abstract_Order's own
	 * `status_transition()` is called from within `save()`) — the `$subscription`
	 * argument is the same, already-loaded, in-memory object, so its meta
	 * (including ours — FS never touches `_autopay_*` keys) is read fresh, not
	 * stale, without a redundant `wc_get_order()`.
	 *
	 * @param mixed       $subscription    Expected `Subscription`; typed loosely
	 *                                      because WordPress action callbacks receive
	 *                                      whatever the firing code passes.
	 * @param string|null $previous_status Status the subscription transitioned from.
	 */
	public function handle( $subscription, $previous_status ): void {
		unset( $previous_status );

		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		// Force gateway construction in a bare Action Scheduler worker, same
		// established reason as Renewal_Status_Check_Job::run().
		if ( function_exists( 'WC' ) ) {
			WC()->payment_gateways();
		}

		$store          = new Recurring_Subscription_Store();
		$recurring_data = $store->get_recurring_data( $subscription ); // Snapshot BEFORE mark_inactive().

		if ( null === $recurring_data ) {
			return; // Never had an active instrument, or already deactivated (e.g. RPDN).
		}

		$store->mark_inactive( $subscription, 'subscription_cancelled' ); // Immediate, unconditional, never depends on HTTP.

		if ( $this->has_other_entitled_subscription( $store, $subscription->get_id(), $recurring_data ) ) {
			$subscription->add_order_note(
				__( 'Autopay: instrument recurring pozostaje aktywny zewnętrznie — współdzielony przez inną, wciąż uprawnioną subskrypcję.', 'platnosci-online-blue-media' )
			);
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Subscription_Cancellation_Listener] [shared ClientHash, external deactivation skipped] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return;
		}

		$this->attempt_deactivation( $subscription->get_id(), 1 );
	}

	/**
	 * Attempt (or retry) the external `deactivate_recurring` call for one
	 * subscription's cancellation. Re-checks everything from scratch — never trusts
	 * that conditions from an earlier call (or from scheduling time) still hold
	 * ("retry ponownie sprawdza kontekst i współdzielenie").
	 *
	 * Public: called both directly (attempt 1, from handle()) and by
	 * `Deactivation_Retry_Job::run()` (attempt 2) on a fresh instance — this class
	 * carries no per-request state, so a fresh instance is always safe to use.
	 *
	 * @param int $subscription_id WooCommerce subscription (order) id.
	 * @param int $attempt_number  1 for the original attempt, 2 for the single retry.
	 *                              Never trusted alone to cap retries — see
	 *                              Deactivation_Retry_Job's durable, atomic
	 *                              once-only slot for the actual guarantee.
	 */
	public function attempt_deactivation( int $subscription_id, int $attempt_number ): void {
		$subscription = wc_get_order( $subscription_id );

		if ( ! $subscription instanceof Subscription ) {
			return; // Deleted between scheduling and running -- nothing to report.
		}

		$store    = new Recurring_Subscription_Store();
		$snapshot = $store->get_recurring_data_ignoring_status( $subscription );

		if ( null === $snapshot ) {
			return; // No instrument was ever recorded -- nothing to deactivate. Never
					// substitute a different, currently-active instrument here.
		}

		if ( $store->is_external_deactivation_confirmed( $subscription ) ) {
			return; // Already confirmed externally in the meantime (e.g. an
					// independent RPDN for this exact ClientHash) -- nothing to send.
		}

		if ( $this->has_other_entitled_subscription( $store, $subscription_id, $snapshot ) ) {
			$subscription->add_order_note(
				__( 'Autopay: instrument recurring stał się współdzielony przed ponowieniem — zewnętrzna dezaktywacja porzucona.', 'platnosci-online-blue-media' )
			);
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Subscription_Cancellation_Listener] [instrument now shared, retry abandoned] [subscription_id: %d]',
					$subscription_id
				)
			);

			return;
		}

		try {
			$gateway = ( new Recurring_Background_Context() )->setup_for_currency( (string) $snapshot['currency'] );
		} catch ( RuntimeException $e ) {
			$this->handle_unconfirmed( $subscription_id, 'context_setup_failed', $attempt_number );

			return;
		}

		$result = ( new Recurring_Api_Client( $gateway ) )->deactivate(
			(string) $snapshot['client_hash'],
			[
				'currency'    => $snapshot['currency'],
				'service_id'  => $snapshot['service_id'],
				'environment' => $snapshot['environment'],
			]
		);

		$subscription = wc_get_order( $subscription_id ); // Re-fetch -- the HTTP call above may take a while.

		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		if ( Recurring_Api_Client::DEACTIVATE_CONFIRMED === $result['outcome'] ) {
			$store->mark_external_deactivation_confirmed( $subscription, true );

			return;
		}

		$this->handle_unconfirmed( $subscription_id, (string) $result['outcome'], $attempt_number );
	}

	/**
	 * Common handling for every non-CONFIRMED outcome: record the unconfirmed
	 * state, note it, log it, and schedule the single allowed retry — never on the
	 * second attempt (hard stop, no loop).
	 *
	 * @param int    $subscription_id WooCommerce subscription (order) id.
	 * @param string $reason          Short, machine-readable reason (never a raw
	 *                                 ClientHash/key/HTTP body).
	 * @param int    $attempt_number  1 or 2, see attempt_deactivation().
	 */
	private function handle_unconfirmed( int $subscription_id, string $reason, int $attempt_number ): void {
		$subscription = wc_get_order( $subscription_id );

		if ( $subscription instanceof Subscription ) {
			( new Recurring_Subscription_Store() )->mark_external_deactivation_confirmed( $subscription, false );
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: machine-readable reason. */
                    __( 'Autopay: external recurring instrument deactivation NOT CONFIRMED (%s) - manual verification required.', 'platnosci-online-blue-media' ),
					$reason
				)
			);
		}

		blue_media()->get_woocommerce_logger()->log_error(
			sprintf(
				'[Subscription_Cancellation_Listener] [external deactivation unconfirmed] [subscription_id: %d] [attempt: %d] [reason: %s]',
				$subscription_id,
				$attempt_number,
				$reason
			)
		);

		if ( $attempt_number >= 2 ) {
			return; // Hard stop -- the retry itself already failed, no further attempt.
		}

		if ( ! Deactivation_Retry_Job::schedule_once( $subscription_id ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Subscription_Cancellation_Listener] [retry could not be scheduled (slot already used, or Action Scheduler refused it) — no further automatic attempt, manual review required] [subscription_id: %d]',
					$subscription_id
				)
			);
		}
	}

	/**
	 * Whether any OTHER local subscription is still a genuinely entitled user of
	 * the same ClientHash+ServiceID+environment — i.e. is not in a status FS itself
	 * only ever leaves for `trash` (see CH_SHARING_TERMINAL_STATUSES). Deliberately
	 * broader than "active/on-hold": `pending-cancel` and `pending` can both still
	 * transition back to `active` per FS's own state machine, so a subscription in
	 * either must still be treated as entitled.
	 *
	 * @param Recurring_Subscription_Store $store           Store to query.
	 * @param int                          $subscription_id Subscription to EXCLUDE from the check.
	 * @param array                        $recurring_data  `client_hash`/`service_id`/`environment` to check.
	 */
	private function has_other_entitled_subscription( Recurring_Subscription_Store $store, int $subscription_id, array $recurring_data ): bool {
		foreach ( $store->find_subscription_by_client_hash( (string) $recurring_data['client_hash'], (string) $recurring_data['service_id'], (string) $recurring_data['environment'] ) as $other ) {
			if ( $other->get_id() === $subscription_id ) {
				continue;
			}

			if ( ! $other->has_status( self::CH_SHARING_TERMINAL_STATUSES ) ) {
				return true;
			}
		}

		return false;
	}
}
