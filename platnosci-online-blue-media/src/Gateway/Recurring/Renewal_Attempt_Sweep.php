<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Attempt_Sweep — periodic safety net for card-recurring charge attempts
 * that no single scheduled job is currently watching.
 *
 * Closes the gap that "schedule recovery before HTTP" alone does not cover:
 * a process that dies between creating the attempt
 * record and successfully scheduling its Renewal_Status_Check_Job, or an Action
 * Scheduler job that is lost/cleared after being scheduled.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

/**
 * Periodically scans every currently-open attempt via Attempt_State_Store's own
 * authoritative storage and resolves each into exactly one of: abandon (STARTING,
 * stale), finish pending side effects (terminal, unapplied), or (re)schedule a
 * status check (SENT, unwatched).
 */
class Renewal_Attempt_Sweep {

	public const HOOK = 'bm_recurring_attempt_sweep';

	/**
	 * Placeholder — no source document was found in this pass to derive this from;
	 * a working default, not a confirmed requirement.
	 */
	private const INTERVAL_SECONDS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Placeholder — same caveat as INTERVAL_SECONDS. Chosen well above any realistic
	 * request runtime between start_new_attempt() and Renewal_Status_Check_Job::schedule()
	 * (a handful of PHP statements) so a STILL-legitimately-in-flight request is never
	 * mistaken for an abandoned one.
	 */
	private const STARTING_ABANDON_AFTER_SECONDS = 10 * MINUTE_IN_SECONDS;

	private const PAGE_SIZE = 200;

	/**
	 * Bounds worst-case work per run — this is a periodic safety net, not the primary
	 * recovery path, so it is acceptable for an exceptionally large backlog to be
	 * finished over several runs rather than in one unbounded pass.
	 */
	private const MAX_PAGES_PER_RUN = 20;

	/**
	 * Registers the recurring Action Scheduler job. Must be called unconditionally at
	 * plugin boot (`Plugin::before_init()`), for the same reason as
	 * `Renewal_Status_Check_Job::register()` — see that class's docblock.
	 *
	 * The `as_*` scheduling call itself is deferred to `init`:
	 * `Plugin::before_init()` runs synchronously while the main plugin
	 * file is being included, before Action Scheduler (bundled with/loaded by
	 * WooCommerce) has defined its global `as_*` functions — calling
	 * `as_has_scheduled_action()` directly here is a fatal "undefined function"
	 * error on every request. `add_action()` itself never requires Action
	 * Scheduler, so registering the HOOK callback stays here, unconditional.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Ensures the recurring job is scheduled, once Action Scheduler is guaranteed
	 * to be loaded. Safe to call on every `init` — `as_has_scheduled_action()`
	 * itself is the idempotency guard.
	 */
	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			// Action Scheduler still not available for some other reason (e.g.
			// WooCommerce inactive) -- nothing to schedule against.
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL_SECONDS, self::INTERVAL_SECONDS, self::HOOK );
		}
	}

	/**
	 * Action Scheduler callback. Paginates through Attempt_State_Store's own storage —
	 * never a WC_Order query — so nothing is missed or wrongly excluded based on an
	 * order's `date_modified`: that field says nothing about
	 * how old an attempt's lock actually is.
	 */
	public function run(): void {
		if ( function_exists( 'WC' ) ) {
			// Force gateway construction even in a bare Action Scheduler worker that
			// never called WC()->payment_gateways() itself.
			WC()->payment_gateways();
		}

		$store   = new Attempt_State_Store();
		$applier = new Recurring_Result_Applier();
		$offset  = 0;

		for ( $page = 0; $page < self::MAX_PAGES_PER_RUN; $page++ ) {
			$open_attempts = $store->find_open_attempts( self::PAGE_SIZE, $offset );

			if ( [] === $open_attempts ) {
				break;
			}

			foreach ( $open_attempts as $renewal_order_id => $attempt ) {
				$this->handle_one( (int) $renewal_order_id, $attempt, $store, $applier );
			}

			if ( count( $open_attempts ) < self::PAGE_SIZE ) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}
	}

	/**
	 * Resolves one open attempt into exactly one recovery action.
	 *
	 * @param int                      $renewal_order_id WooCommerce renewal order id.
	 * @param array                    $attempt          Decoded attempt record.
	 * @param Attempt_State_Store      $store            Shared store instance for this run.
	 * @param Recurring_Result_Applier $applier          Shared applier instance for this run.
	 */
	private function handle_one( int $renewal_order_id, array $attempt, Attempt_State_Store $store, Recurring_Result_Applier $applier ): void {
		$seq   = (int) ( $attempt['seq'] ?? 0 );
		$state = (string) ( $attempt['state'] ?? '' );

		if ( Attempt_State_Store::STATE_STARTING === $state
			&& ( time() - (int) ( $attempt['started_at'] ?? 0 ) ) > self::STARTING_ABANDON_AFTER_SECONDS
		) {
			$store->abort_unsent( $renewal_order_id, $seq, 'sweep_abandoned_starting' );

			return;
		}

		$is_terminal = in_array(
			$state,
			[ Attempt_State_Store::STATE_SUCCESS, Attempt_State_Store::STATE_FAILURE, Attempt_State_Store::STATE_UNKNOWN ],
			true
		);

		if ( $is_terminal && true !== ( $attempt['side_effects_applied'] ?? false ) ) {
			// Safe at any age — a terminal-but-unapplied attempt needs its side
			// effects finished regardless of how long ago it was resolved.
			$applier->finish_pending_side_effects( $renewal_order_id, $seq );

			return;
		}

		if ( Attempt_State_Store::STATE_SENT === $state
			&& ! as_has_scheduled_action( Renewal_Status_Check_Job::HOOK, null, Renewal_Status_Check_Job::group( $renewal_order_id, $seq ) )
		) {
			// Orphaned SENT attempt with no pending check job. Resume from the
			// PERSISTED count, never reset to check_number=1
			// -- a lost/re-scheduled job must never grant extra retries beyond
			// Renewal_Status_Check_Job::MAX_CHECKS.
			$done = (int) ( $attempt['status_checks_done'] ?? 0 );
			Renewal_Status_Check_Job::schedule( $renewal_order_id, $seq, $done + 1 );
		}
	}
}
