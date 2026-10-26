<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Failed_Order_Email_Cta — adds a direct "pay this renewal now" link to
 * WooCommerce's own customer "failed order" email, for a failed Autopay
 * card-recurring renewal only.
 *
 * Hooks `woocommerce_email_order_meta`, which fires on every WooCommerce order
 * email (including the ADMIN failed-order email and every other transactional
 * order email) — this class must filter to exactly the `customer_failed_order`
 * email id, never assume it is the only listener on this hook. Deliberately not a
 * template override: both the HTML and plain-text `customer-failed-order.php`
 * templates already call this exact action (verified directly against the
 * installed WooCommerce version), so no template copy needs to be maintained.
 *
 * Reuses {@see Manual_Renewal_Eligibility} — the SAME predicate
 * `Blue_Media_Gateway::payment_fields()`/`process_payment()` already gate MANUAL
 * repayment on — never a second, independently-derived eligibility check. If the
 * standard customer email is disabled in WooCommerce settings, this hook never
 * fires and no substitute message is ever sent by this plugin.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use WC_Email;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Adds a direct pay link to the customer failed-order email for eligible
 * Autopay renewal orders only.
 */
class Renewal_Failed_Order_Email_Cta {

	/**
	 * De-duplication guard — never add the CTA twice to the same rendered
	 * message. Keyed per call by the rendering `WC_Email` object identity plus
	 * the order id, so a genuinely different email (e.g. two separate
	 * failed-order emails sent for two different orders in the same request)
	 * is never affected by another email's guard entry.
	 *
	 * @var array<string, true>
	 */
	private static array $rendered = [];

	/**
	 * Registers the hook. Unconditional at plugin boot — WooCommerce can
	 * render/send this email from a context that never constructs
	 * `Blue_Media_Gateway` on its own (an admin "resend email" action, or this
	 * plugin's own `Renewal_Status_Check_Job`/`Renewal_Attempt_Sweep` Action
	 * Scheduler workers reaching a `failed` transition), so this registration
	 * must not be deferred into the gateway's constructor.
	 */
	public function register(): void {
		add_action( 'woocommerce_email_order_meta', [ $this, 'maybe_render' ], 20, 4 );
	}

	/**
	 * `woocommerce_email_order_meta` callback. Parameters are loosely typed to
	 * match WooCommerce's own hook signature (not type-hinted by WooCommerce
	 * core either).
	 *
	 * @param mixed $order         Expected `WC_Order`.
	 * @param mixed $sent_to_admin Expected bool — unused; filtering is done via
	 *                             `$email->id`, never this flag alone.
	 * @param mixed $plain_text    Expected bool.
	 * @param mixed $email         Expected `WC_Email`.
	 */
	public function maybe_render( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( ! $order instanceof WC_Order || ! $email instanceof WC_Email || 'customer_failed_order' !== $email->id ) {
			return;
		}

		$render_key = spl_object_id( $email ) . ':' . $order->get_id();

		if ( isset( self::$rendered[ $render_key ] ) ) {
			return;
		}

		if ( ! $this->is_eligible_renewal_order( $order ) ) {
			return;
		}

		self::$rendered[ $render_key ] = true;

		$this->render_cta( $order, (bool) $plain_text );
	}

	/**
	 * Whether this order should receive the pay-link CTA: a Flexible
	 * Subscriptions renewal order, paid through Autopay, still needing
	 * payment, belonging to a non-manual Autopay-recurring subscription, and
	 * currently eligible for MANUAL repayment.
	 *
	 * @param WC_Order $order Order the email is being sent for.
	 */
	private function is_eligible_renewal_order( WC_Order $order ): bool {
		if ( ! class_exists( Renewal::class ) || ! Renewal::is_renewal_order( $order ) ) {
			return false; // Not a Flexible Subscriptions renewal order -- excludes ordinary failed orders and a subscription's first-payment order (which is never a renewal order).
		}

		if ( 'bluemedia' !== $order->get_payment_method() ) {
			return false; // Renewal order paid through a different gateway.
		}

		if ( ! $order->needs_payment() ) {
			return false; // Already paid (or otherwise settled) -- never link to a repayment that would no longer apply.
		}

		$subscription = ( new Recurring_Subscription_Store() )->find_subscription_for_renewal_order( $order );

		if ( ! $subscription instanceof Subscription
			|| $subscription->is_manual()
			|| 'bluemedia' !== $subscription->get_payment_method()
		) {
			return false; // No resolvable subscription, or the subscription itself is not an Autopay-recurring one.
		}

		// The exact same predicate payment_fields()/process_payment() already gate
		// MANUAL on -- reused, not reimplemented. Manual_Renewal_Eligibility::evaluate()
		// already safely returns not-eligible (never fatals) if the gateway cannot be
		// resolved in this request's context -- see its own Recurring_Background_Context/
		// RuntimeException handling, already implemented.
		return Manual_Renewal_Eligibility::evaluate( $order )['eligible'];
	}

	/**
	 * Renders the CTA for the given order, in either HTML or plain-text form.
	 *
	 * @param WC_Order $order      Renewal order to link to.
	 * @param bool     $plain_text Whether the plain-text email part is being rendered.
	 */
	private function render_cta( WC_Order $order, bool $plain_text ): void {
		$url = $order->get_checkout_payment_url();

		if ( $plain_text ) {
			echo esc_html__( 'You can pay for this renewal directly using the link below:', 'platnosci-online-blue-media' ) . "\n";
			echo esc_url_raw( $url ) . "\n\n";

			return;
		}

		printf(
			'<p><strong><a href="%1$s">%2$s</a></strong></p>',
			esc_url( $url ),
			esc_html__( 'Pay for this renewal now', 'platnosci-online-blue-media' )
		);
	}
}
