<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Status_Check_Job — scheduled recovery check for a single card-recurring
 * charge attempt whose synchronous `send_charge()` result did not resolve it.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use RuntimeException;
use WC_Order;

/**
 * Polls `check_status()` for one attempt until it resolves or exhausts its budget.
 */
class Renewal_Status_Check_Job {

	public const HOOK = 'bm_recurring_renewal_status_check';

	/**
	 * Placeholder — requires confirmation against Autopay's documented transaction
	 * `ValidityTime` for channel 1503 AUTO (unresolved).
	 */
	private const CHECK_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Placeholder — same caveat as CHECK_INTERVAL_SECONDS.
	 */
	private const MAX_CHECKS = 4;

	/**
	 * Schedules a status check for one attempt. `$check_number` is carried only for
	 * Action Scheduler argument uniqueness/logging — the AUTHORITATIVE retry budget is
	 * `Attempt_State_Store::increment_status_check_count()`'s persisted counter,
	 * never this argument alone, so a lost-and-rescheduled job (see
	 * Renewal_Attempt_Sweep) can never silently reset the budget.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number to check.
	 * @param int $check_number     Informational sequence number for this scheduling call.
	 *
	 * @return bool True if Action Scheduler accepted the job.
	 */
	public static function schedule( int $renewal_order_id, int $seq, int $check_number ): bool {
		$scheduled = as_schedule_single_action(
			time() + self::CHECK_INTERVAL_SECONDS,
			self::HOOK,
			[
				'renewal_order_id' => $renewal_order_id,
				'seq'              => $seq,
				'check_number'     => $check_number,
			],
			self::group( $renewal_order_id, $seq )
		);

		return 0 !== $scheduled;
	}

	/**
	 * Action Scheduler group for one attempt's checks — lets Renewal_Attempt_Sweep ask
	 * "does this attempt have ANY pending check?" without needing to know the exact
	 * `check_number` of whatever job might already be scheduled.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number.
	 */
	public static function group( int $renewal_order_id, int $seq ): string {
		return sprintf( 'bm-recurring-check-%d-%d', $renewal_order_id, $seq );
	}

	/**
	 * Registers the Action Scheduler callback. Must be called unconditionally at plugin
	 * boot (`Plugin::before_init()`), NOT deferred into `Blue_Media_Gateway`'s
	 * constructor: a bare Action Scheduler queue-runner request has no reason to call
	 * `WC()->payment_gateways()` on its own, so a gateway-constructor-only registration
	 * would silently never attach in that context.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Action Scheduler callback for one scheduled check.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number being checked.
	 * @param int $check_number     Informational — see schedule().
	 */
	public function run( int $renewal_order_id, int $seq, int $check_number ): void {
		// Force gateway construction even if nothing else in this request has -- the
		// same reason FS's own AutomaticRequest::request_payment() calls this before
		// firing the scheduled-payment hook.
		if ( function_exists( 'WC' ) ) {
			WC()->payment_gateways();
		}

		$store   = new Attempt_State_Store();
		$attempt = $store->get_current_attempt( $renewal_order_id );

		if ( null === $attempt || (int) ( $attempt['seq'] ?? -1 ) !== $seq ) {
			// Stale job for an attempt that is no longer current -- never act on
			// whatever attempt IS current now.
			return;
		}

		$state = (string) ( $attempt['state'] ?? '' );

		if ( in_array( $state, [ Attempt_State_Store::STATE_SUCCESS, Attempt_State_Store::STATE_FAILURE, Attempt_State_Store::STATE_UNKNOWN ], true ) ) {
			// Already resolved (e.g. by ITN) before this check fired -- only finish any
			// side effects that might still be pending, never query Autopay again.
			( new Recurring_Result_Applier() )->finish_pending_side_effects( $renewal_order_id, $seq );
			return;
		}

		if ( Attempt_State_Store::STATE_STARTING === $state ) {
			// mark_sent() always runs, in the same request, immediately before
			// send_charge() -- if this scheduled
			// check fires and the attempt is STILL in STARTING, the original process
			// almost certainly died before ever transmitting anything. Safe to abandon
			// without querying Autopay for an OrderID it most likely never received.
			$store->abort_unsent( $renewal_order_id, $seq, 'recovery_found_unsent_attempt' );
			return;
		}

		try {
			$gateway = ( new Recurring_Background_Context() )->setup_for_currency( (string) ( $attempt['context']['currency'] ?? '' ) );
		} catch ( RuntimeException $e ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Renewal_Status_Check_Job] [context setup failed] [renewal_order_id: %d] [seq: %d] [error: %s]',
					$renewal_order_id,
					$seq,
					$e->getMessage()
				)
			);
			$this->reschedule_or_give_up( $renewal_order_id, $seq, 'context_setup_failed' );
			return;
		}

		$order_id = Attempt_State_Store::build_order_id( $renewal_order_id, $seq );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf(
				'[Renewal_Status_Check_Job] [checking] [renewal_order_id: %d] [seq: %d] [check_number: %d]',
				$renewal_order_id,
				$seq,
				$check_number
			)
		);

		$result = ( new Recurring_Api_Client( $gateway ) )->check_status( $order_id, (array) $attempt['context'] );

		$this->handle_check_result( $renewal_order_id, $seq, (array) $attempt['context'], $result );
	}

	/**
	 * Maps a `check_status()` outcome to an action. Only MATCHED_SUCCESS/MATCHED_FAILURE
	 * resolve the attempt directly. Every other outcome — including
	 * EMPTY_CONFIRMED_NO_TRANSACTION — is treated as inconclusive and counted against
	 * the check budget: no documented rule confirming EMPTY_CONFIRMED_NO_TRANSACTION as
	 * safely final was found in Autopay's documentation — a
	 * correct-but-unsigned assumption ("probably nothing was ever sent") is not the same
	 * as a confirmed protocol rule, so exhaustion here ends in UNKNOWN, never FAILURE.
	 *
	 * @param int   $renewal_order_id WooCommerce renewal order id.
	 * @param int   $seq              Attempt number.
	 * @param array $context          Attempt context (currency/service_id/environment/
	 *                                source/gateway_id/gateway_choice/amount_cents).
	 * @param array $result           `Recurring_Api_Client::check_status()` result.
	 */
	private function handle_check_result( int $renewal_order_id, int $seq, array $context, array $result ): void {
		// Same gateway_id defense-in-depth as ITN, applied
		// identically here — skipped for a 'hosted'-choice attempt (GatewayID could
		// not be known in advance), otherwise checked against what THIS attempt's own
		// context recorded, never a hardcoded channel constant.
		$transaction_gateway_id = isset( $result['transaction']['gateway_id'] ) ? (int) $result['transaction']['gateway_id'] : null;
		$is_hosted_choice       = 'hosted' === (string) ( $context['gateway_choice'] ?? 'explicit' );
		$gateway_id_ok          = $is_hosted_choice || null === $transaction_gateway_id
			|| (int) ( $context['gateway_id'] ?? -1 ) === $transaction_gateway_id;

		if ( ! $gateway_id_ok ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Renewal_Status_Check_Job] [unexpected gateway_id in matched transaction] [renewal_order_id: %d] [seq: %d] [expected: %s] [got: %d]',
					$renewal_order_id,
					$seq,
					wp_json_encode( $context['gateway_id'] ?? null ),
					$transaction_gateway_id
				)
			);
			$this->reschedule_or_give_up( $renewal_order_id, $seq, 'gateway_id_mismatch' );
			return;
		}

		if ( Recurring_Api_Client::STATUS_MATCHED_SUCCESS === $result['outcome'] ) {
			( new Recurring_Result_Applier() )->apply( $renewal_order_id, $seq, Attempt_State_Store::STATE_SUCCESS, 'recovery_check' );
			return;
		}

		if ( Recurring_Api_Client::STATUS_MATCHED_FAILURE === $result['outcome'] ) {
			( new Recurring_Result_Applier() )->apply( $renewal_order_id, $seq, Attempt_State_Store::STATE_FAILURE, 'recovery_check' );
			return;
		}

		if ( Recurring_Api_Client::STATUS_MATCHED_PENDING === $result['outcome'] ) {
			// Same shared, seq-gated polling meta update as ITN's
			// PENDING branch — reached here already gated by the current-attempt/seq
			// match performed in run() before this method was called at all.
			$order = wc_get_order( $renewal_order_id );

			if ( $order instanceof WC_Order ) {
				Recurring_Result_Applier::update_polling_status_meta( $order, $context, Blue_Media_Gateway::ITN_PENDING_STATUS_ID );
			}
		}

		// MATCHED_PENDING, EMPTY_CONFIRMED_NO_TRANSACTION, AMBIGUOUS, TRANSPORT_ERROR,
		// INVALID_RESPONSE, CREDENTIALS_UNAVAILABLE: none of these prove an outcome.
		// No WC_Order status mutation for MATCHED_PENDING either — same "leave state
		// unchanged unless a business need is identified" choice as ITN's PENDING
		// handling, applied consistently here.
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf(
				'[Renewal_Status_Check_Job] [inconclusive check] [renewal_order_id: %d] [seq: %d] [outcome: %s]',
				$renewal_order_id,
				$seq,
				$result['outcome']
			)
		);

		$this->reschedule_or_give_up( $renewal_order_id, $seq, (string) $result['outcome'] );
	}

	/**
	 * Bumps the persisted, authoritative check counter and either schedules the next
	 * check or gives up (-> UNKNOWN) once MAX_CHECKS is reached.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param int    $seq              Attempt number.
	 * @param string $last_outcome     Last inconclusive outcome, stored as the UNKNOWN reason on exhaustion.
	 */
	private function reschedule_or_give_up( int $renewal_order_id, int $seq, string $last_outcome ): void {
		$done = ( new Attempt_State_Store() )->increment_status_check_count( $renewal_order_id, $seq );

		if ( null === $done ) {
			return; // No longer the current attempt.
		}

		if ( $done >= self::MAX_CHECKS ) {
			( new Recurring_Result_Applier() )->apply(
				$renewal_order_id,
				$seq,
				Attempt_State_Store::STATE_UNKNOWN,
				'checks_exhausted_' . strtolower( $last_outcome )
			);

			return;
		}

		self::schedule( $renewal_order_id, $seq, $done + 1 );
	}
}
