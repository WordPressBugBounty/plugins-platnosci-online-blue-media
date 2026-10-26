<?php
/**
 * Classifies an order/cart context for card-recurring (etap 1).
 *
 * Scope of this class is intentionally limited to recognising whether an order
 * is a fresh checkout eligible for the recurring card channel, an unsupported subscription
 * cart that must be blocked, or a Flexible Subscriptions renewal order. It does NOT decide
 * whether a renewal order currently has a chargeable instrument (ClientHash) — that requires
 * Recurring_Subscription_Store, introduced in a later task, and is out of scope here.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use WC_Cart;
use WC_Order;
use WC_Product;
use WPDesk\FlexibleSubscriptions\Product\SubscriptionProduct;
use WPDesk\FlexibleSubscriptions\Product\SubscriptionProductWrapper;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;

/**
 * Classifies an order/cart context for card recurring (etap 1).
 */
class Recurring_Context {

	/**
	 * Order has nothing to do with a subscription (or Flexible Subscriptions is not
	 * active) — recurring is irrelevant, existing behaviour applies unchanged.
	 */
	public const ORDINARY = 'ordinary';

	/**
	 * A fresh checkout order for exactly one Flexible Subscriptions billing plan —
	 * one subscription, optionally alongside any number of ordinary one-off products
	 * and/or the subscription's own sign-up fee (mixed cart; the sign-up fee is
	 * never a separate cart item to begin with — see analyze_products()) — with
	 * the recurring card channel actually configured for its currency and the feature
	 * switch enabled, and either a non-zero total or a zero total (trial with no
	 * sign-up fee) whose subscription has a confirmed future non-zero renewal in PLN
	 * — see decide(). The only state in which channel 1503 may be offered/accepted.
	 */
	public const SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT = 'supported_subscription_first_payment';

	/**
	 * Order contains a subscription but one or more preconditions are not met (feature
	 * disabled, more than one DISTINCT subscription billing plan, a zero-amount total
	 * with no confirmed future non-zero renewal or in a currency other than PLN, or
	 * channel 1503 not configured for the order's currency). A mixed cart (subscription
	 * plus one-off products) is, on its own, NOT a reason for this state — see decide().
	 * Checkout for this order must be blocked outright — never silently ORDINARY.
	 */
	public const UNSUPPORTED_SUBSCRIPTION_CART = 'unsupported_subscription_cart';

	/**
	 * A Flexible Subscriptions renewal order. Recognised as a fact only — whether it
	 * currently has a chargeable instrument, and how it should be routed, is decided by
	 * later tasks (Recurring_Subscription_Store, Attempt_State_Store) that do not exist
	 * yet. Callers must not assume anything beyond "this is a renewal order" from this
	 * state today.
	 */
	public const RENEWAL_ORDER = 'renewal_order';

	private const FEATURE_SWITCH_OPTION = 'bm_recurring_card_enabled';

	/**
	 * The gateway instance, used to read the current Gateway List.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway Gateway instance, used to read the current
	 *                                    Gateway List (channel availability per currency).
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Whether Flexible Subscriptions is active AND the merchant has explicitly enabled
	 * the recurring card feature. Gates only NEW activations (cart classification and
	 * `supports('subscriptions')`) — never gates handling of already-activated
	 * subscriptions (renewals, RPAN/RPDN, cancellation), because Flexible Subscriptions
	 * evaluates `supports('subscriptions')` only once, at subscription creation time.
	 */
	public function is_recurring_feature_enabled(): bool {
		if ( ! $this->is_flexible_subscriptions_active() ) {
			return false;
		}

		// Read via the gateway's own WC_Settings_API storage (single serialized
		// `woocommerce_bluemedia_settings` option), matching every other setting in
		// this plugin (e.g. is_whitelabel_mode_enabled() uses $this->get_option()) —
		// NOT a bare get_option(), which would read a different, unrelated storage
		// location and never see what the settings field actually saves.
		return 'yes' === $this->gateway->get_option( self::FEATURE_SWITCH_OPTION, 'no' );
	}

	/**
	 * Pure presence check for Flexible Subscriptions — independent of the feature switch,
	 * used both here and wherever renewal handling for already-active subscriptions
	 * must keep working regardless of the switch.
	 *
	 * `WPDesk\FlexibleSubscriptions\Product\SubscriptionProduct` is an empty MARKER
	 * INTERFACE in Flexible Subscriptions (`src/Product/SubscriptionProduct.php`, FS's
	 * own docblock: "Empty interface... this interface MUST only be implemented by
	 * classes"), never a class. `class_exists()` only matches actual `class`
	 * declarations and returns `false` for an interface regardless of load order, so
	 * checking it directly would report Flexible Subscriptions as inactive even while
	 * it is fully active and processing a real subscription product -- cascading into
	 * `is_recurring_feature_enabled()`, `supports('subscriptions')`, and Flexible
	 * Subscriptions' own `AvailableGateways` filter (`array_filter($gateways, fn($g) =>
	 * $g->supports('subscriptions'))` for a single-subscription cart) removing this
	 * gateway entirely from both checkouts regardless of the `bm_recurring_card_enabled`
	 * switch. `interface_exists()` correctly detects it; `class_exists()` is kept as a
	 * defensive fallback only in case a future Flexible Subscriptions release changes
	 * this to a class.
	 */
	public function is_flexible_subscriptions_active(): bool {
		return interface_exists( SubscriptionProduct::class ) || class_exists( SubscriptionProduct::class );
	}

	/**
	 * Classify an order for the purpose of guarding `process_payment()` before any
	 * channel routing. Operates on the order (not `WC()->cart`) so the same logic
	 * applies uniformly to fresh checkout (once an order exists) and to order-pay.
	 *
	 * RENEWAL_ORDER detection stays here, separate from the shared decision in
	 * {@see decide()} — a renewal order is a fact about the order's origin (created by
	 * Flexible Subscriptions), not a cart-shape decision -- everything that follows
	 * from it is handled elsewhere.
	 *
	 * @param WC_Order $order Order being paid.
	 *
	 * @return string One of the class constants above.
	 */
	public function classify( WC_Order $order ): string {
		if ( $this->is_flexible_subscriptions_active()
			&& class_exists( Renewal::class )
			&& Renewal::is_renewal_order( $order )
		) {
			return self::RENEWAL_ORDER;
		}

		if ( ! $this->is_flexible_subscriptions_active() ) {
			return self::ORDINARY;
		}

		$products = [];
		foreach ( $order->get_items() as $item ) {
			$products[] = $item->get_product();
		}

		return $this->decide( $products, (float) $order->get_total(), $order->get_currency() );
	}

	/**
	 * Classify the current session cart — the counterpart of {@see classify()} for
	 * rendering the payment-channel list BEFORE an order exists (fresh checkout review,
	 * both classic and Blocks). Never returns RENEWAL_ORDER — a cart cannot be a renewal
	 * order; use {@see classify_current_request()} when the caller must also cover
	 * order-pay.
	 *
	 * A missing cart (no session/front-end context) returns ORDINARY — the safe default
	 * that hides channel 1503 (see Gateway_List_Recurring_Filter) rather than ever
	 * synthesising its availability without real cart data.
	 *
	 * @return string One of the class constants above (never RENEWAL_ORDER).
	 */
	public function classify_cart(): string {
		if ( ! $this->is_flexible_subscriptions_active() ) {
			return self::ORDINARY;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return self::ORDINARY;
		}

		$products = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			$products[] = $cart_item['data'] ?? null;
		}

		return $this->decide( $products, (float) $cart->get_total( 'edit' ), get_woocommerce_currency() );
	}

	/**
	 * Resolves the classification governing the payment-channel list for the CURRENT
	 * front-end request — an existing order for order-pay (`?order-pay={id}`), or the
	 * session cart for fresh checkout. Used by both classic (`payment_fields()`) and
	 * Blocks (`get_payment_method_data()`) so the order-pay boundary is decided in one
	 * place, not re-implemented per caller.
	 *
	 * Relies on `get_query_var( 'order-pay' )`, which only resolves correctly for a
	 * normal front-end page request — NOT inside a bare REST/Store API request (e.g. the
	 * Store API `/wc/store/v1/cart` endpoint has no such query var). Callers running in
	 * a REST-only context must call {@see classify_cart()} directly instead — see
	 * `Gateway_List_Recurring_Filter`/`WC_Gateway_Autopay_Blocks_Support` call sites.
	 *
	 * @return string One of the class constants above.
	 */
	public function classify_current_request(): string {
		$order_pay_id = absint( get_query_var( 'order-pay' ) );

		if ( $order_pay_id <= 0 ) {
			return $this->classify_cart();
		}

		$order = wc_get_order( $order_pay_id );

		return $order instanceof WC_Order ? $this->classify( $order ) : self::ORDINARY;
	}

	/**
	 * The single business decision shared by {@see classify()} and {@see classify_cart()}
	 * once each has collected its own products/total/currency from its own source
	 * (order items vs cart items) — same rules, two data sources.
	 *
	 * Counts DISTINCT Flexible Subscriptions billing plans among the given products, not
	 * line items: `ProposalFactory::from_wc_cart()`/`from_order()` (FS core) group items
	 * into ONE `SubscriptionProposal` (one subscription) per unique
	 * `SubscriptionPlan::get_hash()` (billing frequency + trial + expiration), regardless
	 * of how many line items or what quantity carry that plan. Two line items sharing the
	 * same plan hash — same product twice, or two different subscription products
	 * configured with identical billing/trial/expiration — become ONE subscription, not
	 * two; counting line items instead of distinct plan hashes would misclassify that
	 * cart as `UNSUPPORTED_SUBSCRIPTION_CART` (multiple subscriptions) when Flexible
	 * Subscriptions will actually create exactly one.
	 *
	 * @param array<WC_Product|null|false> $products Products from every order/cart line
	 *                                                item (as returned by
	 *                                                `WC_Order_Item_Product::get_product()`
	 *                                                or a cart row's `data`) — may contain
	 *                                                null/false for a deleted product,
	 *                                                tolerated the same way the caller's
	 *                                                source already tolerates it.
	 * @param float                        $total    Order/cart total (`get_total()`),
	 *                                                already excluding shipping/tax
	 *                                                concerns the caller does not pass in.
	 * @param string                       $currency ISO-4217 currency of the order/cart.
	 *
	 * @return string One of ORDINARY / UNSUPPORTED_SUBSCRIPTION_CART /
	 *                SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT.
	 */
	private function decide( array $products, float $total, string $currency ): string {
		$composition = $this->analyze_products( $products );

		if ( empty( $composition['plan_hashes'] ) ) {
			return self::ORDINARY;
		}

		if ( ! $this->is_recurring_feature_enabled() ) {
			return self::UNSUPPORTED_SUBSCRIPTION_CART;
		}

		if ( count( $composition['plan_hashes'] ) > 1 ) {
			// More than one DISTINCT billing plan -- Flexible Subscriptions would create
			// more than one subscription from this cart/order. Out of scope (unchanged
			// by mixed-cart support, which only adds one-off products alongside a SINGLE
			// subscription plan, never multiple subscription schedules/groups).
			return self::UNSUPPORTED_SUBSCRIPTION_CART;
		}

		if ( $composition['unknown_or_unsupported_item_count'] > 0 ) {
			// At least one line item is neither a recognised Flexible Subscriptions
			// subscription product NOR a plain WC_Product we can positively classify as
			// an ordinary one-off (a deleted/unresolvable product, or a recognisable
			// subscription-type product from a different subscriptions engine -- see
			// analyze_products()). Fail closed: never let an item we cannot positively
			// classify slip into a "supported mixed cart" just because it fell out of
			// the SubscriptionProduct check. This was implicitly blocked before mixed-cart
			// support was added (everything non-FS landed in the single "other item"
			// bucket, which was rejected outright) and stays blocked now.
			return self::UNSUPPORTED_SUBSCRIPTION_CART;
		}

		// Mixed-cart support: a mixed cart (the one subscription plan above plus any
		// number of ordinary, positively-recognised one-off WC_Product items) is supported --
		// deliberately no check on $composition['one_off_item_count'] here. The exact
		// first-payment amount is never recomputed from these items:
		// Transaction_Request_Builder::get_price() already reads the order's own,
		// final WooCommerce total (subscription due now + one-off items + tax +
		// shipping + discounts), and Flexible Subscriptions' own renewal order
		// generation never includes one-off items from the original order -- see
		// Renewal_Payment_Handler/Manual_Renewal_Payment_Handler, which both derive the
		// renewal charge fresh from the renewal order's own total.

		if ( $total <= 0.0 ) {
			// A zero-amount first payment (e.g. a trial with no sign-up fee) is
			// supported only for PLN and only when the subscription has a confirmed
			// future non-zero renewal -- a genuinely free subscription or a one-time
			// plan stays out of scope.
			if ( Autopay_Payment_Protocol::INIT_WITH_REFUND_SUPPORTED_CURRENCY !== $currency
				|| ! $this->has_future_chargeable_renewal( $composition['plan_products'] )
			) {
				return self::UNSUPPORTED_SUBSCRIPTION_CART;
			}
		}

		if ( ! $this->is_channel_available_for_currency( Blue_Media_Gateway::CARD_RECURRING_CHANNEL, $currency ) ) {
			// Channel 1503 not returned by Gateway List for this currency/ServiceID —
			// never synthesise availability that Autopay did not actually offer.
			return self::UNSUPPORTED_SUBSCRIPTION_CART;
		}

		return self::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT;
	}

	/**
	 * `WC_Product::get_type()` slugs of subscription product types NOT implemented via
	 * Flexible Subscriptions -- currently WooCommerce Subscriptions' own product types.
	 * Checked purely as a string comparison against `get_type()`, so detecting them
	 * never requires WooCommerce Subscriptions to be installed/loaded (no class/
	 * interface reference, no composer dependency). Flexible Subscriptions registers
	 * its OWN product types under a distinct, prefixed slug (`fsb-subscription`,
	 * `fsb-variable-subscription` -- see
	 * `WPDesk\FlexibleSubscriptions\HookProvider\Product\SubscriptionProductType`), so
	 * this list cannot collide with a real FS product (which is caught earlier anyway,
	 * via the `SubscriptionProduct` marker interface, before `get_type()` is ever
	 * consulted here).
	 */
	private const KNOWN_NON_FS_SUBSCRIPTION_PRODUCT_TYPES = [
		'subscription',
		'variable-subscription',
	];

	/**
	 * Groups the given products by distinct Flexible Subscriptions billing plan hash
	 * (see the decide() docblock for why distinct plan hash, not line item count, is
	 * the right unit), and separately counts:
	 *
	 * - `one_off_item_count`: a plain `WC_Product` positively identified as NOT a
	 *   subscription of any kind -- an ordinary product bought alongside the
	 *   subscription (never includes a sign-up fee, which Flexible Subscriptions prices
	 *   into the subscription product's own line item rather than adding as a separate
	 *   cart item -- see `ProposalFactory`/`SignupFeeFixedDiscount` in Flexible
	 *   Subscriptions);
	 * - `unknown_or_unsupported_item_count`: everything else -- a line item whose
	 *   product could not be resolved at all (deleted product: `get_product()`
	 *   returned `null`/`false`), or a real `WC_Product` recognisable as a subscription
	 *   product type from an engine OTHER than Flexible Subscriptions (see
	 *   `KNOWN_NON_FS_SUBSCRIPTION_PRODUCT_TYPES`). Neither case may ever be silently
	 *   folded into `one_off_item_count`: decide() fails closed
	 *   (`UNSUPPORTED_SUBSCRIPTION_CART`) whenever this count is above zero, exactly
	 *   the same outcome a deleted/foreign-subscription line item produced before
	 *   mixed-cart support was added (when everything non-FS landed in one "other
	 *   item" bucket that was rejected outright).
	 *
	 * Shared by decide() (the business decision) and describe_order_composition()
	 * (diagnostics only) so a logged count can never drift from what the
	 * classifier itself actually saw for the same products.
	 *
	 * @param array<WC_Product|null|false> $products Products from every order/cart line
	 *                                                item (as returned by
	 *                                                `WC_Order_Item_Product::get_product()`
	 *                                                or a cart row's `data`) — may contain
	 *                                                null/false for a deleted product,
	 *                                                tolerated the same way the caller's
	 *                                                source already tolerates it.
	 *
	 * @return array{plan_hashes: array<string, true>, plan_products: array<string, SubscriptionProductWrapper>, one_off_item_count: int, unknown_or_unsupported_item_count: int}
	 */
	private function analyze_products( array $products ): array {
		$subscription_plan_hashes          = [];
		$plan_products                     = [];
		$one_off_item_count                = 0;
		$unknown_or_unsupported_item_count = 0;

		foreach ( $products as $product ) {
			if ( $product instanceof SubscriptionProduct ) {
				$wrapper                                = new SubscriptionProductWrapper( $product );
				$plan_hash                              = $wrapper->get_plan()->get_hash();
				$subscription_plan_hashes[ $plan_hash ] = true;
				// decide() already rejects more than one DISTINCT plan hash, so by the
				// time has_future_chargeable_renewal() runs there is exactly one entry.
				$plan_products[ $plan_hash ] ??= $wrapper;
				continue;
			}

			if ( ! $product instanceof WC_Product ) {
				// Deleted line item / product could not be resolved at all -- never
				// assume this is an ordinary product just because it failed the
				// SubscriptionProduct check.
				++$unknown_or_unsupported_item_count;
				continue;
			}

			if ( in_array( $product->get_type(), self::KNOWN_NON_FS_SUBSCRIPTION_PRODUCT_TYPES, true ) ) {
				// A recognisable subscription product type from an engine other than
				// Flexible Subscriptions -- this plugin's recurring support is FS-only
				// (etap 1 scope); never treat it as an ordinary one-off.
				++$unknown_or_unsupported_item_count;
				continue;
			}

			++$one_off_item_count;
		}

		return [
			'plan_hashes'                       => $subscription_plan_hashes,
			'plan_products'                     => $plan_products,
			'one_off_item_count'                => $one_off_item_count,
			'unknown_or_unsupported_item_count' => $unknown_or_unsupported_item_count,
		];
	}

	/**
	 * Whether the subscription will actually bill a non-zero amount on a future
	 * cycle -- i.e. whether card-recurring for a zero-amount first payment would
	 * ever be charged again.
	 *
	 * Uses `get_price('edit')` (current effective price, incl. sale price/variations),
	 * not `get_regular_price()` -- matches how Flexible Subscriptions itself prices a
	 * recurring line item. `is_recurring()` excludes a one-time plan (billing
	 * frequency == expiration), which has no future renewal regardless of price.
	 *
	 * @param array<string, SubscriptionProductWrapper> $plan_product_wrappers From
	 *                                                   analyze_products()'s `plan_products`.
	 */
	private function has_future_chargeable_renewal( array $plan_product_wrappers ): bool {
		foreach ( $plan_product_wrappers as $wrapper ) {
			if ( $wrapper->is_recurring() && (float) $wrapper->get_price( 'edit' ) > 0.0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cart composition for a given order, for diagnostics only — never used
	 * for the business decision itself, which lives entirely in decide(). Uses the same
	 * grouping rule as decide() (via analyze_products()), from the same source
	 * (`$order->get_items()`) classify() itself reads, so a logged count can never
	 * drift from what classify() actually saw for this exact order.
	 *
	 * @param WC_Order $order Order to describe (typically the order just classified).
	 *
	 * @return array{subscription_group_count: int, one_off_item_count: int, unknown_or_unsupported_item_count: int}
	 */
	public function describe_order_composition( WC_Order $order ): array {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			$products[] = $item->get_product();
		}

		$composition = $this->analyze_products( $products );

		return [
			'subscription_group_count'          => count( $composition['plan_hashes'] ),
			'one_off_item_count'                => $composition['one_off_item_count'],
			'unknown_or_unsupported_item_count' => $composition['unknown_or_unsupported_item_count'],
		];
	}

	/**
	 * Whether the given GatewayID is present in the real, current Gateway List response
	 * for the given currency. Never assume availability from the presence of a related
	 * channel (e.g. 1500) — this is a live, per-currency check.
	 *
	 * `Blue_Media_Gateway::gateway_list()` (→ `Gateway_List_Service::gateway_list()`)
	 * returns the RAW, `json_decode( $body, true )` associative array of the Gateway List
	 * API response — top-level keys `result`/`gatewayGroups`/`gatewayList`/etc, exactly the
	 * shape `Gateway_List_Response_Factory::create( array $data )` consumes — never a flat
	 * list of hydrated `Gateway` objects. The actual channels live in
	 * `$gateway_list_data['gatewayList']`, an array of associative arrays keyed
	 * `gatewayID` (matching `Gateway_List_Service::api_get_gateway_list()`'s own debug
	 * log: `array_column( $result_decoded['gatewayList'], 'gatewayID' )`) -- treating the
	 * top-level array itself as a flat list of `Gateway` objects would silently report
	 * every channel as unavailable, for every currency, regardless of what Autopay
	 * actually returned.
	 *
	 * @param int    $gateway_id Autopay GatewayID to look for (e.g. 1503).
	 * @param string $currency   ISO-4217 currency code.
	 *
	 * @return bool
	 */
	public function is_channel_available_for_currency( int $gateway_id, string $currency ): bool {
		try {
			$gateway_list_data = $this->gateway->gateway_list( false, $currency );
		} catch ( \Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Context] [gateway_list lookup failed] [currency: %s] [gateway_id: %d] [error: %s]',
					$currency,
					$gateway_id,
					$exception->getMessage()
				)
			);

			return false;
		}

		$channels = is_array( $gateway_list_data['gatewayList'] ?? null ) ? $gateway_list_data['gatewayList'] : [];

		foreach ( $channels as $channel ) {
			if ( is_array( $channel ) && isset( $channel['gatewayID'] ) && $gateway_id === (int) $channel['gatewayID'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Single, shared copy of the `UNSUPPORTED_SUBSCRIPTION_CART` blocking message —
	 * classic (`Blue_Media_Gateway::payment_fields()`) and Blocks
	 * (`WC_Gateway_Autopay_Blocks_Support::get_payment_method_data()` /
	 * the Store API cart extension) both render this exact string, never two
	 * independently worded copies.
	 *
	 * Deliberately says Autopay cannot process THIS purchase in the current
	 * configuration -- never implies the store itself cannot sell these products (other
	 * payment methods remain available).
	 *
	 * @return string
	 */
	public static function get_unsupported_subscription_cart_message(): string {
		return __( 'Autopay is not available for this specific order with the current store configuration (e.g. more than one subscription, a zero-amount first payment, or the recurring card channel not being configured for this currency). Please choose a different payment method, or contact the store.', 'platnosci-online-blue-media' );
	}
}
