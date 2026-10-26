<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Fs_Pause_Sync_Retry_Job — the single allowed retry of a failed
 * `Fs_Lifecycle_Bridge::pause()` call after a valid RPDN.
 *
 * The instrument is already blocked locally the moment RPDN validates
 * (`Recurring_Subscription_Store::mark_inactive()` runs unconditionally, before
 * this job even exists) — this job exists only to complete the FS-side status sync
 * (`on-hold`) that a failed `pause()` left incomplete, never for charge safety.
 * Never assumed to happen "naturally" via Autopay redelivering an already-CONFIRMED
 * RPDN — this package's own, explicit retry is the durable, defined path to
 * completing it, on top of (not instead of) the same idempotent, fresh-status-check
 * completion a genuine redelivery or duplicate notification would also trigger.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use WPDesk\FlexibleSubscriptions\Subscription\Subscription;

/**
 * Schedules and runs the one permitted retry of a failed RPDN pause() sync.
 */
class Fs_Pause_Sync_Retry_Job {

	private const HOOK = 'bm_recurring_fs_pause_sync_retry';

	/**
	 * Durable, never-deleted retry-slot marker — same atomic-INSERT primitive and
	 * "at most one retry, ever" reasoning as `Deactivation_Retry_Job`.
	 */
	private const RETRY_SLOT_OPTION_PREFIX = 'bm_recurring_fs_pause_sync_retry_slot_';

	/**
	 * Short delay — this is a purely local FS status sync (no outbound HTTP call to
	 * Autopay involved), so there is no transport-recovery reasoning behind a longer
	 * wait; a transient FS-not-yet-initialised condition is expected to clear
	 * quickly.
	 */
	private const DELAY_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Claims the one-time retry slot and, only if that succeeds, schedules the retry.
	 *
	 * @param int $subscription_id WooCommerce subscription (order) id.
	 *
	 * @return bool True if a retry was actually scheduled.
	 */
	public static function schedule_once( int $subscription_id ): bool {
		if ( ! self::claim_retry_slot( $subscription_id ) ) {
			return false;
		}

		return 0 !== as_schedule_single_action(
			time() + self::DELAY_SECONDS,
			self::HOOK,
			[ 'subscription_id' => $subscription_id ]
		);
	}

	/**
	 * Atomically claims the one-time retry slot for a subscription.
	 *
	 * @param int $subscription_id WooCommerce subscription (order) id.
	 */
	private static function claim_retry_slot( int $subscription_id ): bool {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate raw INSERT: this row's mere existence (never read back for display, never cached) IS the atomic "retry slot used" mutex, relying on wp_options.option_name being UNIQUE, exactly like Attempt_State_Store's own lock rows.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::RETRY_SLOT_OPTION_PREFIX . $subscription_id,
				(string) time()
			)
		);

		$wpdb->suppress_errors( $previous );

		return false !== $inserted;
	}

	/**
	 * Registers the Action Scheduler callback. Must be called unconditionally at
	 * plugin boot (`Plugin::before_init()`) — same reasoning as the sibling jobs.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ], 10, 1 );
	}

	/**
	 * Action Scheduler callback for the single retry.
	 *
	 * @param int $subscription_id WooCommerce subscription (order) id.
	 */
	public function run( int $subscription_id ): void {
		if ( function_exists( 'WC' ) ) {
			WC()->payment_gateways();
		}

		$subscription = wc_get_order( $subscription_id );

		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		$store = new Recurring_Subscription_Store();

		if ( ! $store->is_fs_sync_pending( $subscription ) ) {
			return; // Already resolved (an earlier retry, a redelivered RPDN, or a manual admin fix).
		}

		if ( ! $subscription->has_status( 'active' ) ) {
			// Status moved on for an unrelated reason (e.g. cancelled) -- there is
			// nothing left for pause() to do; the pending flag is now stale.
			$store->clear_fs_sync_pending( $subscription );

			return;
		}

		if ( blue_media()->get_fs_lifecycle_bridge()->pause( $subscription ) ) {
			$store->clear_fs_sync_pending( $subscription );

			return;
		}

		// Slot already consumed -- no further automatic attempt. The durable
		// `_autopay_recurring_fs_sync_pending` flag and this log line are the
		// defined path to completing it: opening and re-saving the subscription in
		// wp-admin runs FS's own `AdminSubscriptionUpdate` -> `handle_status_change()`
		// path, which will correctly move it to `on-hold` even though this job could not.
		blue_media()->get_woocommerce_logger()->log_error(
			sprintf(
				'[Fs_Pause_Sync_Retry_Job] [retry failed — manual admin action required: re-save the subscription in wp-admin to complete the on-hold sync] [subscription_id: %d]',
				$subscription_id
			)
		);
	}
}
