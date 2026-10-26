<?php
/**
 * Restricts a Gateway List response to the channels a recurring-context classification
 * allows.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Domain\Service\Gateway_List;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Context;

/**
 * Restricts a raw {@see Gateway_List_Response} to the subset of channels a given
 * `Recurring_Context` classification allows, BEFORE it reaches either consumer's own
 * sorting/grouping (`Payment_Channel_Renderer::render_channels_v3()` for classic,
 * `Gateway_List_Mapper_Block_Checkout::map_for_blocks()` for Blocks — both call
 * `View_Model_Group_Factory::create()`, which already drops any resulting group left
 * with zero gateways, so this class only needs to touch the flat `gatewayList`, never
 * the separate `gatewayGroups` metadata).
 *
 * Deliberately lives here, not on `Recurring_Context` — the business decision (which
 * classification applies) and the Gateway List transport structure (flat gateway array
 * vs. group metadata) are two different concerns; this class owns only the latter.
 *
 * @package bm-woocommerce
 */
class Gateway_List_Recurring_Filter {

	/**
	 * Returns a NEW response with the channel list restricted per classification — never
	 * mutates the given `$response` (safe to call on an object another caller still holds
	 * a reference to, e.g. a cached/shared hydration).
	 *
	 * - `ORDINARY`: every channel except 1503 (a plain purchase is never offered "pay
	 *   with recurring card" as a one-off option).
	 * - `SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT`: only 1503 (every other channel would be
	 *   rejected by `Blue_Media_Gateway::process_payment()`'s guard anyway — showing them
	 *   would be a dead end, never a silent fallback to an ordinary payment).
	 * - `UNSUPPORTED_SUBSCRIPTION_CART` / `RENEWAL_ORDER`: returned unchanged — not this
	 *   filter's job. Callers must not even reach here for `UNSUPPORTED_SUBSCRIPTION_CART`
	 *   (render the blocking message instead, no channel list at all), and must never
	 *   call this for `RENEWAL_ORDER` (that rendering path is owned entirely elsewhere).
	 *
	 * @param Gateway_List_Response $response       Freshly hydrated response to restrict.
	 * @param string                $classification One of the `Recurring_Context` class
	 *                                               constants.
	 *
	 * @return Gateway_List_Response
	 */
	public static function filter( Gateway_List_Response $response, string $classification ): Gateway_List_Response {
		if ( Recurring_Context::ORDINARY !== $classification
			&& Recurring_Context::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT !== $classification
		) {
			return $response;
		}

		$keep_only_recurring_channel = Recurring_Context::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT === $classification;

		$filtered_gateways = array_values(
			array_filter(
				$response->getGatewayList() ?? [],
				static function ( Gateway $gateway ) use ( $keep_only_recurring_channel ): bool {
					$is_recurring_channel = Blue_Media_Gateway::CARD_RECURRING_CHANNEL === $gateway->getGatewayID();

					return $keep_only_recurring_channel ? $is_recurring_channel : ! $is_recurring_channel;
				}
			)
		);

		$filtered_response = clone $response;
		$filtered_response->setGatewayList( $filtered_gateways );

		return $filtered_response;
	}
}
