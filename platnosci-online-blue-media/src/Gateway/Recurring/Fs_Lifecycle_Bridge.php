<?php
/**
 * Fs_Lifecycle_Bridge — the only point of contact with Flexible Subscriptions'
 * status-change lifecycle.
 *
 * All knowledge of FS's extension hook name (`fsub/initialized`), the shape of the
 * object it passes, and which method to call on it lives exclusively in this one
 * class — no other handler in this plugin knows FS's hook names.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Adapter over FS's `fsub/initialized` extension point, exposing only what this
 * plugin actually needs (pause()) instead of leaking FS's own API further.
 *
 * Must be obtained via `blue_media()->get_fs_lifecycle_bridge()` (Plugin.php) and
 * never instantiated directly by callers other than that accessor — `capture()`
 * populates an INSTANCE property, so a freshly-constructed `new Fs_Lifecycle_Bridge()`
 * would never have observed `fsub/initialized` and `pause()` would always fail.
 */
class Fs_Lifecycle_Bridge {

	/**
	 * The FS integration object captured from `fsub/initialized`, if it fired in
	 * this request. Untyped (no class typehint) so this file remains loadable even
	 * when Flexible Subscriptions is not installed/active.
	 *
	 * @var object|null
	 */
	private $fsub = null;

	/**
	 * Registers the capture callback. MUST be called unconditionally, early, and
	 * independently of `Blue_Media_Gateway` construction (`Plugin::before_init()`,
	 * same place/reason as `Renewal_Status_Check_Job`/`Renewal_Attempt_Sweep`):
	 * `Blue_Media_Gateway` is only actually instantiated when WooCommerce resolves
	 * the `woocommerce_payment_gateways` filter (`WC_Payment_Gateways::init()`),
	 * which is not synchronised with FS's own `init`-hooked `fsub/initialized`
	 * (`flexible-subscriptions/src/HookProvider/Init.php:16-22`) and may not happen
	 * at all in a given request (e.g. a bare Action Scheduler worker). Registering
	 * this callback at plugin boot, before `init` fires, is what guarantees
	 * `capture()` runs regardless of whether/when the gateway gets constructed.
	 */
	public function register(): void {
		add_action( 'fsub/initialized', [ $this, 'capture' ] );
	}

	/**
	 * Captures the FS integration object for the remainder of this request.
	 *
	 * @param object $fsub `WPDesk\FlexibleSubscriptions\Integration\FlexibleSubscriptions` instance.
	 */
	public function capture( $fsub ): void {
		$this->fsub = $fsub;
	}

	/**
	 * Pause a subscription through FS's own status-change lifecycle
	 * (`subscription_updater()->handle_status_change()`), so the correct domain
	 * events fire and Action Scheduler is correctly resynced (e.g. the pending
	 * `fsub/subscription/payment_request/process` job removed) — never via a
	 * manually-constructed `SubscriptionRepository::save()`, which would bypass FS's
	 * own, properly wired event dispatcher.
	 *
	 * Never reports success when nothing actually happened: returns `false` both
	 * when `$fsub` was never captured (FS inactive, or this request never reached
	 * `init`) and when FS itself refuses the transition (`handle_status_change()`
	 * returns `false`, e.g. the subscription is no longer in a status that can
	 * legally move to `on-hold`).
	 *
	 * `cancel()`/`activate()` are deliberately NOT implemented here — etap 1 does
	 * not need them: cancellation is reacted to, never initiated, by this plugin
	 * (Subscription_Cancellation_Listener), and resumption of an already-active
	 * instrument goes through FS's own, pre-existing admin path.
	 *
	 * @param Subscription $subscription Subscription to pause.
	 *
	 * @return bool True if FS's own lifecycle actually transitioned the subscription.
	 */
	public function pause( Subscription $subscription ): bool {
		if ( null === $this->fsub || ! method_exists( $this->fsub, 'subscription_updater' ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Fs_Lifecycle_Bridge] [pause failed: fsub/initialized was never captured in this request] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return false;
		}

		$updater = $this->fsub->subscription_updater();

		if ( ! is_object( $updater ) || ! method_exists( $updater, 'handle_status_change' ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Fs_Lifecycle_Bridge] [pause failed: subscription_updater() unavailable] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return false;
		}

		$paused = (bool) $updater->handle_status_change( $subscription, 'on-hold' );

		if ( ! $paused ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf(
					'[Fs_Lifecycle_Bridge] [pause failed: FS refused the on-hold transition] [subscription_id: %d] [status: %s]',
					$subscription->get_id(),
					$subscription->get_status()
				)
			);
		}

		return $paused;
	}
}
