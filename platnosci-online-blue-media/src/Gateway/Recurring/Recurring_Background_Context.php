<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Recurring_Background_Context — explicit currency/ServiceID context setup for
 * out-of-request (Action Scheduler) recurring operations.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use RuntimeException;

/**
 * Re-establishes gateway currency/ServiceID/environment context before a
 * background recurring operation (charge, status check, deactivation),
 * instead of relying on whatever a previous Action Scheduler job running in
 * the same PHP worker process left configured.
 *
 * `Currency::$shop_currency` (the currently active currency) is a static,
 * process-wide singleton — see `Currency::reconfigure()`. Two consecutive
 * background jobs for different currencies, run in the same worker process,
 * would otherwise silently share whichever currency the first job left
 * configured. Every caller must call {@see setup_for_currency()} at the start
 * of each job, not once per queue/process.
 */
class Recurring_Background_Context {

	/**
	 * Switch the shared Currency singleton and the gateway singleton's
	 * service_id/private_key/gateway_url to the given currency, then return the
	 * reconfigured gateway.
	 *
	 * Fails loud (throws) instead of silently continuing with the wrong
	 * configuration when:
	 *  - the `Blue_Media_Gateway` singleton is not available at all (plugin not
	 *    booted for this request/worker), or
	 *  - `Currency::reconfigure()` does not actually switch to the requested
	 *    currency. That method silently falls back to the store's current
	 *    default currency for any code not in its fixed 7-currency list (or on
	 *    any other lookup failure) instead of failing — without this check, a
	 *    caller would sign/send with a leftover or wrong currency's
	 *    ServiceID/private_key with no indication anything went wrong.
	 *
	 * @param string $currency_code ISO-4217 currency code to configure (e.g. the
	 *                               renewal order's/subscription's stored currency).
	 *
	 * @return Blue_Media_Gateway Gateway instance, reconfigured for $currency_code.
	 *
	 * @throws RuntimeException When the gateway instance is unavailable or the
	 *                           currency switch could not be confirmed.
	 */
	public function setup_for_currency( string $currency_code ): Blue_Media_Gateway {
		$gateway = blue_media()->get_blue_media_gateway();

		if ( ! $gateway instanceof Blue_Media_Gateway ) {
			blue_media()->get_woocommerce_logger()->log_error(
				'[Recurring_Background_Context] [setup_for_currency failed: Blue_Media_Gateway instance is not available]'
			);

			throw new RuntimeException( 'Recurring_Background_Context: Blue_Media_Gateway instance is not available.' );
		}

		$reconfigured = blue_media()->get_currency_manager()->reconfigure( $currency_code );

		if ( ! $reconfigured instanceof Currency_Interface || $reconfigured->get_code() !== $currency_code ) {
			// Currency::reconfigure() falls back to the shop's current default
			// currency instead of failing when $currency_code is not one of its
			// fixed 7 supported codes -- never let that silent fallback pass as a
			// successful switch to the REQUESTED currency.
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Recurring_Background_Context] [setup_for_currency failed: could not switch to requested currency] [requested: %s] [got: %s]',
					$currency_code,
					$reconfigured instanceof Currency_Interface ? $reconfigured->get_code() : 'null'
				)
			);

			throw new RuntimeException(
				sprintf( 'Recurring_Background_Context: failed to switch currency context to "%s".', $currency_code ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
			);
		}

		$gateway->setup_variables();

		return $gateway;
	}

	/**
	 * Autopay ServiceID currently configured on the gateway singleton. Callers
	 * must call {@see setup_for_currency()} first in the same request/job --
	 * this method does not switch currency itself.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the gateway instance is unavailable.
	 */
	public function get_configured_service_id(): string {
		$gateway = blue_media()->get_blue_media_gateway();

		if ( ! $gateway instanceof Blue_Media_Gateway ) {
			blue_media()->get_woocommerce_logger()->log_error(
				'[Recurring_Background_Context] [get_configured_service_id failed: Blue_Media_Gateway instance is not available]'
			);

			throw new RuntimeException( 'Recurring_Background_Context: Blue_Media_Gateway instance is not available.' );
		}

		return $gateway->get_service_id();
	}
}
