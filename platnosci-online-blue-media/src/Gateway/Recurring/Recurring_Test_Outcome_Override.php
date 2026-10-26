<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Recurring_Test_Outcome_Override — SECURITY-SENSITIVE test seam letting a
 * developer-only tool force the outcome of an automatic (AUTO) card-recurring
 * renewal attempt, without sending a real charge to Autopay.
 *
 * Exists so regression tests can reliably reproduce a confirmed FAILURE (or any
 * other terminal outcome) for a real, fully-activated subscription — real first
 * payment, real Flexible Subscriptions renewal generation, real
 * `Attempt_State_Store`/`Recurring_Result_Applier` side effects — with only the
 * actual HTTP round-trip to Autopay skipped. The saved instrument (ClientHash)
 * is never touched, so a subsequent MANUAL repayment with the SAME instrument
 * can still genuinely succeed afterwards.
 *
 * THREE INDEPENDENT GATES, ALL REQUIRED, ALL CHECKED HERE — in the code that
 * CONSUMES the filter, never trusted from whatever plugin registers it:
 *
 * 1. `BM_RECURRING_TEST_MODE` must be defined `true` in `wp-config.php`.
 *    Requires filesystem access to the server, not just a WordPress admin
 *    session — this is the outermost, per-environment opt-in. It must never be
 *    set on a production site's `wp-config.php`. Consistent with this
 *    codebase's existing convention of a `wp-config.php`-defined constant
 *    toggling dev/test-only behavior (see `BLUE_MEDIA_DISABLE_CACHE` in
 *    `Blue_Media_Gateway::__construct()`).
 * 2. `Blue_Media_Gateway::resolve_is_test_mode()` must be true — the SAME
 *    switch that decides whether a charge would go to Autopay's real API or
 *    its sandbox. This is the strongest guarantee: even if every other gate
 *    were somehow bypassed, this override can never apply to a real,
 *    production charge, because at that point we are not even looking at a
 *    live-money code path.
 * 3. `current_user_can( 'manage_woocommerce' )` must be true AT THE MOMENT THE
 *    FILTER IS CONSUMED — not only when a test tool's admin page rendered a
 *    button. This matters because `Renewal_Payment_Handler::handle()` also runs
 *    from an unattended Action Scheduler worker for a genuine scheduled
 *    renewal, where no user is logged in at all. Gating consumption on an
 *    active admin session means a stray/forgotten forced-outcome flag left by
 *    a test tool can NEVER fire during that kind of unattended, real run — it
 *    can only ever take effect while an admin is actively, synchronously
 *    triggering it (exactly how a test tool is meant to be used).
 *
 * No code in this plugin ever calls `apply_filters( self::FILTER, ... )` with a
 * non-null default, and nothing in this plugin registers a callback on it —
 * by itself, this class is a complete no-op. A separate, developer-only tool
 * (not shipped to customers) is expected to hook `self::FILTER` and decide,
 * per subscription/renewal, whether to force an outcome.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use WC_Order;

/**
 * Test-only seam for forcing an AUTO renewal attempt's terminal outcome.
 */
final class Recurring_Test_Outcome_Override {

	/**
	 * Filter tag. Callback signature: `function( ?array $forced, WC_Order
	 * $renewal_order, array $context ): ?array`. Return
	 * `[ 'state' => Attempt_State_Store::STATE_SUCCESS|STATE_FAILURE|STATE_UNKNOWN,
	 * 'reason' => string ]` to force that outcome, or `null` (the default) to let
	 * the real charge proceed.
	 */
	public const FILTER = 'bm_recurring_test_force_outcome';

	/**
	 * Returns a forced outcome for this renewal attempt, or null if none of the
	 * three gates above are satisfied, or nothing hooked the filter, or the
	 * hooked value was not a validly-shaped forced outcome.
	 *
	 * @param WC_Order $renewal_order Renewal order about to be charged.
	 * @param array    $context       Same attempt context `Renewal_Payment_Handler`
	 *                                already built for this send.
	 *
	 * @return array{state: string, reason: string}|null
	 */
	public static function maybe_get_forced_outcome( WC_Order $renewal_order, array $context ): ?array {
		if ( ! self::is_override_allowed() ) {
			return null;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal, bm_-prefixed string above; phpcs cannot resolve the class constant statically.
		$forced = apply_filters( self::FILTER, null, $renewal_order, $context );

		if ( ! is_array( $forced ) || ! isset( $forced['state'] ) ) {
			return null;
		}

		$allowed_states = [
			Attempt_State_Store::STATE_SUCCESS,
			Attempt_State_Store::STATE_FAILURE,
			Attempt_State_Store::STATE_UNKNOWN,
		];

		if ( ! in_array( $forced['state'], $allowed_states, true ) ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Test_Outcome_Override] [ignored: invalid forced state] [renewal_order_id: %d] [state: %s]',
					$renewal_order->get_id(),
					wp_json_encode( $forced['state'] )
				)
			);

			return null;
		}

		blue_media()->get_woocommerce_logger()->log_warning(
			sprintf(
				'[Recurring_Test_Outcome_Override] [FORCING TEST OUTCOME -- no real Autopay charge was sent] [renewal_order_id: %d] [forced_state: %s]',
				$renewal_order->get_id(),
				$forced['state']
			)
		);

		return [
			'state'  => (string) $forced['state'],
			'reason' => (string) ( $forced['reason'] ?? 'test_override' ),
		];
	}

	/**
	 * The three gates. See class docblock for why each one exists and why all
	 * three are required together.
	 */
	private static function is_override_allowed(): bool {
		if ( ! defined( 'BM_RECURRING_TEST_MODE' ) || true !== BM_RECURRING_TEST_MODE ) {
			return false;
		}

		$gateway = blue_media()->get_blue_media_gateway();

		if ( ! $gateway instanceof Blue_Media_Gateway || ! $gateway->resolve_is_test_mode() ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' );
	}
}
