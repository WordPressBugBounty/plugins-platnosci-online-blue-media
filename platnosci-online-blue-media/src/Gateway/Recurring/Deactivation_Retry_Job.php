<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Deactivation_Retry_Job — the single allowed retry of an unconfirmed
 * `deactivate_recurring` call after a subscription cancellation.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the one permitted retry of Subscription_Cancellation_Listener's
 * external deactivation call.
 */
class Deactivation_Retry_Job {

	private const HOOK = 'bm_recurring_deactivation_retry';

	/**
	 * Prefix for a durable, NEVER-deleted `wp_options` row that records "a retry
	 * slot for this subscription's cancellation has been used" — the actual
	 * "at most one retry, ever" guarantee (see schedule_once()). Deliberately not
	 * `as_has_scheduled_action()`: that only reports whether a job is CURRENTLY
	 * pending, which protects against neither two callers racing to schedule at the
	 * same instant nor a second scheduling attempt after the first job already ran
	 * to completion.
	 */
	private const RETRY_SLOT_OPTION_PREFIX = 'bm_recurring_deactivation_retry_slot_';

	/**
	 * No documented Autopay SLA for `deactivate_recurring` retries — a working
	 * value chosen for "long enough to outlast a transient transport issue, short
	 * enough that an unconfirmed deactivation is not left unresolved for days".
	 */
	private const DELAY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Claims the (permanent, one-time) retry slot for a subscription and, only if
	 * the claim succeeds, schedules the retry. Only the ClientHash-free
	 * `subscription_id` crosses into Action Scheduler's own storage (its admin UI
	 * and DB table are not a place a raw ClientHash belongs) — the retry re-derives
	 * everything else itself, from the subscription's own meta, at run time.
	 *
	 * @param int $subscription_id WooCommerce subscription (order) id.
	 *
	 * @return bool True if a retry was actually scheduled; false if the slot was
	 *              already used (a genuine second failure, or a duplicate
	 *              cancellation event) or Action Scheduler refused the job.
	 */
	public static function schedule_once( int $subscription_id ): bool {
		if ( ! self::claim_retry_slot( $subscription_id ) ) {
			return false;
		}

		$scheduled = as_schedule_single_action(
			time() + self::DELAY_SECONDS,
			self::HOOK,
			[ 'subscription_id' => $subscription_id ]
		);

		// If as_schedule_single_action() itself fails after the slot was claimed,
		// the slot is intentionally NOT released: a second, concurrent scheduling
		// attempt reusing it could not be told apart from a genuine second retry,
		// and this package's contract is "first attempt + at most one retry", never
		// "keep trying until Action Scheduler cooperates". The caller
		// (Subscription_Cancellation_Listener::handle_unconfirmed()) logs this
		// explicitly as requiring manual review, precisely because the automatic
		// path stops here.
		return 0 !== $scheduled;
	}

	/**
	 * Atomically claims the one-time retry slot for a subscription — a raw SQL
	 * INSERT relying on `wp_options.option_name` being UNIQUE (same primitive as
	 * `Attempt_State_Store::insert_lock_row()`/`Recurring_Subscription_Store`'s
	 * ClientHash lock), never `add_option()`'s check-then-insert. The row is never
	 * deleted, by design — its mere existence, forever, is the guarantee.
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
	 * plugin boot (`Plugin::before_init()`), not deferred into `Blue_Media_Gateway`'s
	 * constructor — same reasoning as `Renewal_Status_Check_Job`/`Fs_Lifecycle_Bridge`.
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

		( new Subscription_Cancellation_Listener() )->attempt_deactivation( $subscription_id, 2 );
	}
}
