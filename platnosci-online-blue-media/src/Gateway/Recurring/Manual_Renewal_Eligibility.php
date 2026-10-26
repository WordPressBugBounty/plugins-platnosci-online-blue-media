<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Manual_Renewal_Eligibility — the single predicate deciding whether a
 * renewal order's saved instrument may be charged via order-pay (MANUAL).
 * Used identically by Blue_Media_Gateway::payment_fields() (what to render)
 * and Manual_Renewal_Payment_Handler::handle() (whether to actually send) --
 * "sprawdzaj te same warunki przy renderowaniu i świeżo przed wysyłką".
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use RuntimeException;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Evaluates whether a renewal order's recurring instrument may be charged now.
 */
final class Manual_Renewal_Eligibility {

	/**
	 * Evaluates whether the renewal order's saved instrument may be charged now.
	 *
	 * @param WC_Order $renewal_order Renewal order being paid.
	 *
	 * @return array{eligible: bool, subscription: ?Subscription, recurring_data: ?array, gateway: ?Blue_Media_Gateway}
	 */
	public static function evaluate( WC_Order $renewal_order ): array {
		$not_eligible = [
			'eligible'       => false,
			'subscription'   => null,
			'recurring_data' => null,
			'gateway'        => null,
		];

		$store        = new Recurring_Subscription_Store();
		$subscription = $store->find_subscription_for_renewal_order( $renewal_order );

		if ( ! $subscription instanceof Subscription || $subscription->is_finalized() ) {
			// Covers subscription-side states that never permit a charge regardless of
			// instrument status (cancelled/pending-cancel/expired/trash/switched).
			// `on-hold` is deliberately NOT excluded here -- MANUAL for a deliberate
			// repayment of a failed renewal while on-hold,
			// with an active, context-matching instrument, is already-agreed scope, not
			// a new business question.
			return $not_eligible;
		}

		$recurring_data = $store->get_recurring_data( $subscription );

		if ( null === $recurring_data ) {
			// No active instrument -- never activated, or deactivated (RPDN makes this
			// null immediately and unconditionally, independent of subscription status,
			// so MANUAL is unreachable after RPDN regardless of on-hold/active).
			return $not_eligible;
		}

		try {
			$currency_gateway = ( new Recurring_Background_Context() )->setup_for_currency( $recurring_data['currency'] );
		} catch ( RuntimeException $e ) {
			return $not_eligible;
		}

		// An active ClientHash alone is not "instrument available" -- it must also
		// match the currency/ServiceID/environment actually configured right now
		// Re-derived from the currency-correct gateway instance
		// Recurring_Background_Context just returned, never assumed from whatever is
		// loaded for the current request.
		$environment = $currency_gateway->resolve_is_test_mode() ? 'test' : 'production';

		if ( $recurring_data['service_id'] !== $currency_gateway->get_service_id()
			|| $recurring_data['environment'] !== $environment
			|| $recurring_data['currency'] !== $renewal_order->get_currency()
		) {
			return $not_eligible;
		}

		return [
			'eligible'       => true,
			'subscription'   => $subscription,
			'recurring_data' => $recurring_data,
			'gateway'        => $currency_gateway,
		];
	}
}
