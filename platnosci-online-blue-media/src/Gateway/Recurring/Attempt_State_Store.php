<?php
/**
 * Durable, race-safe record of card-recurring renewal charge attempts.
 *
 * One renewal order can go through several charge attempts over time (auto-retry
 * after a FAILURE, later a manual repayment). This store answers three questions
 * that must never be wrong, even under concurrent requests/webhooks/cron:
 *  - is there an attempt in progress right now for this order (so a second one must
 *    not start)?
 *  - which attempt does an incoming result (ITN, status check) belong to (so a late
 *    result for an old, already-closed attempt cannot corrupt a newer one)?
 *  - once an attempt is finished, did we actually finish applying its effect to the
 *    WC_Order/subscription, or did the process die in between (so recovery knows
 *    whether there is unfinished work, not just an unfinished-looking record)?
 *
 * Persistence model ("Prawdziwa atomowość"): exactly one `wp_options`
 * row per renewal order holds the JSON state of its *current* attempt, keyed
 * `bm_recurring_attempt_{renewal_order_id}`. It exists only while an attempt is
 * unresolved and is deleted once resolved AND its side effects are confirmed
 * applied — deleting it is what allows a next attempt to start. A second,
 * separate, NEVER-deleted `wp_options` row per order,
 * `bm_recurring_attempt_seq_{renewal_order_id}`, hands out the next attempt number
 * — this is what keeps attempt identifiers unique for the lifetime of the order
 * even across many start/resolve/release cycles of the row above.
 *
 * Every mutation goes through raw `$wpdb` SQL (INSERT for mutual exclusion, a
 * literal compare-and-swap UPDATE for state transitions, delete-if-matches for
 * release) — never `get_option()`/`update_option()`/`add_option()`/`delete_option()`
 * for these two keys, so nothing here is ever affected by WordPress's option object
 * cache. All correctness here comes from the two column constraints of
 * `wp_options` (`option_name` is UNIQUE, and `option_value` is compared verbatim
 * in the WHERE clause), never from PHP-level read-then-write.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use WC_Order;
use wpdb;

/**
 * Durable, race-safe record of card-recurring renewal charge attempts.
 */
class Attempt_State_Store {

	/**
	 * Attempt created, not yet sent to Autopay. Only legal starting state.
	 */
	public const STATE_STARTING = 'starting';

	/**
	 * Charge request sent to Autopay; outcome not yet known locally.
	 */
	public const STATE_SENT = 'sent';

	/**
	 * Terminal: Autopay confirmed the charge succeeded.
	 */
	public const STATE_SUCCESS = 'success';

	/**
	 * Terminal: Autopay confirmed the charge failed (or reported no transaction).
	 */
	public const STATE_FAILURE = 'failure';

	/**
	 * Terminal: outcome could not be determined after exhausting status checks
	 * (transport errors / ambiguous responses) — requires manual review.
	 */
	public const STATE_UNKNOWN = 'unknown';

	private const TERMINAL_STATES = [ self::STATE_SUCCESS, self::STATE_FAILURE, self::STATE_UNKNOWN ];

	/**
	 * Subset of TERMINAL_STATES that can never be overwritten or released-and-forgotten
	 * automatically. UNKNOWN is deliberately excluded
	 * from both uses of this constant: resolve() must still accept a later, correct
	 * UNKNOWN -> SUCCESS/FAILURE transition, and try_release_lock() must never let a
	 * caller release an UNKNOWN attempt's lock (the guard lives here, in the store, not
	 * only in a calling convention a future caller could forget to honour).
	 */
	private const LOCKED_TERMINAL_STATES = [ self::STATE_SUCCESS, self::STATE_FAILURE ];

	private const LOCK_OPTION_PREFIX = 'bm_recurring_attempt_';

	private const SEQ_OPTION_PREFIX = 'bm_recurring_attempt_seq_';

	/**
	 * Minimum time a side-effects claim (see try_claim_side_effects()) must be held
	 * before a second caller is allowed to reclaim it. This is a bounded, honest
	 * mitigation against two concurrent callers (e.g. ITN and a status-check job
	 * landing within milliseconds of each other) both applying WC_Order/FS side
	 * effects for the same resolved attempt -- it is NOT a proof of exactly-once
	 * execution under arbitrary process pauses/crashes.
	 */
	private const SIDE_EFFECTS_CLAIM_TIMEOUT_SECONDS = 120;

	/**
	 * Compare-and-swap operations only ever race against ~1 other concurrent writer
	 * in the documented usage of this store ("przy spodziewanej
	 * liczbie współbieżnych zapisujących ≤2"); 3 attempts is a deliberate margin, not
	 * a tuned-for-load figure.
	 */
	private const MAX_CAS_RETRIES = 3;

	/**
	 * Direct database access, used for every read/write in this class.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	/**
	 * The Autopay `OrderID` for a given attempt — unique within this order for the
	 * lifetime of the ServiceID (OrderID does not
	 * have to equal the WooCommerce order id, only be unique per ServiceID).
	 * Every notification for this attempt (sync response, ITN, status check) carries
	 * this same string back, which is what makes correlation a string comparison
	 * rather than a heuristic.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number for that order (from start_new_attempt()).
	 */
	public static function build_order_id( int $renewal_order_id, int $seq ): string {
		return $renewal_order_id . '-' . $seq;
	}

	/**
	 * Reverse of build_order_id(). Returns null for anything not matching the
	 * `{digits}-{digits}` shape this store produces — callers must treat that as
	 * an unresolvable anomaly ("pozostaje nierozstrzygnięty"),
	 * never guess.
	 *
	 * @param string $order_id Raw `orderID` from an Autopay notification/response.
	 *
	 * @return array{renewal_order_id: int, seq: int}|null
	 */
	public static function parse_order_id( string $order_id ): ?array {
		if ( 1 !== preg_match( '/^(\d+)-(\d+)$/', $order_id, $matches ) ) {
			return null;
		}

		return [
			'renewal_order_id' => (int) $matches[1],
			'seq'              => (int) $matches[2],
		];
	}

	/**
	 * Cheap, non-authoritative pre-check: is it worth even trying to start a new
	 * attempt for this order? Real mutual exclusion is enforced by the atomic
	 * INSERT inside start_new_attempt() — this only avoids that work when it is
	 * obviously pointless.
	 *
	 * True only when the order still needs payment (never true once any attempt has
	 * reached payment_complete() — WooCommerce's own signal, not our history,
	 * "prostszego i bardziej autorytatywnego warunku") AND no
	 * attempt is currently unresolved for it.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 */
	public function can_start_new_attempt( int $renewal_order_id ): bool {
		$order = wc_get_order( $renewal_order_id );

		if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) {
			return false;
		}

		return null === $this->read_raw( $this->lock_option_name( $renewal_order_id ) );
	}

	/**
	 * Atomically start a new attempt for a renewal order, or refuse.
	 *
	 * Refuses (returns null) when: the order cannot be loaded; the order does not
	 * need payment (already settled — by this gateway or otherwise, never re-charge
	 * a paid order); another attempt is currently unresolved (the INSERT below loses
	 * the unique-key race); or the order was settled by a concurrent process in the
	 * brief window between winning the lock and this check (re-verified —
	 * "sprawdzenie po zdobyciu blokady" — in which case the just-created
	 * lock row is rolled back so it cannot block a legitimate future attempt).
	 *
	 * @param int   $renewal_order_id WooCommerce renewal order id.
	 * @param array $context          Free-form context to carry on the attempt record
	 *                                (expected: currency, service_id, environment, source).
	 *
	 * @return array|null The new attempt record, or null if no attempt was started.
	 */
	public function start_new_attempt( int $renewal_order_id, array $context ): ?array {
		$order = wc_get_order( $renewal_order_id );

		if ( ! $order instanceof WC_Order ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Attempt_State_Store] [start_new_attempt rejected: order not found] [order_id: %d]', $renewal_order_id )
			);

			return null;
		}

		if ( ! $order->needs_payment() ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Attempt_State_Store] [start_new_attempt rejected: order already settled] [order_id: %d]', $renewal_order_id )
			);

			return null;
		}

		try {
			$seq = $this->allocate_next_seq( $renewal_order_id );
		} catch ( RuntimeException $exception ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Attempt_State_Store] [start_new_attempt failed: could not allocate a unique attempt sequence] [order_id: %d] [error: %s]',
					$renewal_order_id,
					$exception->getMessage()
				)
			);

			return null;
		}

		$now    = time();
		$record = [
			'renewal_order_id'              => $renewal_order_id,
			'seq'                           => $seq,
			'state'                         => self::STATE_STARTING,
			'reason'                        => '',
			'remote_id'                     => null,
			'context'                       => $context,
			'side_effects_applied'          => false,
			'side_effects_claim_started_at' => null,
			'status_checks_done'            => 0,
			'started_at'                    => $now,
			'updated_at'                    => $now,
		];

		$raw = (string) wp_json_encode( $record );

		if ( ! $this->insert_lock_row( $renewal_order_id, $raw ) ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Attempt_State_Store] [start_new_attempt rejected: unresolved attempt already in progress] [order_id: %d]', $renewal_order_id )
			);

			return null;
		}

		// Re-check right after winning the lock: the cheap pre-check above can race
		// with e.g. a concurrent manual repayment that completed the order in
		// between ("sprawdzenie po zdobyciu
		// blokady", never trust only the check made before acquiring it).
		$order = wc_get_order( $renewal_order_id );

		if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) {
			$this->delete_row_if_matches( $renewal_order_id, $raw );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Attempt_State_Store] [start_new_attempt aborted: order settled concurrently after acquiring lock] [order_id: %d] [seq: %d]',
					$renewal_order_id,
					$seq
				)
			);

			return null;
		}

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Attempt_State_Store] [start_new_attempt] [order_id: %d] [seq: %d]', $renewal_order_id, $seq )
		);

		return $record;
	}

	/**
	 * Apply a terminal result (SUCCESS/FAILURE/UNKNOWN) to the attempt it belongs
	 * to, identified by `$seq` — never by guessing "the current attempt". A result
	 * whose `$seq` does not match the currently-open attempt is a
	 * late notification for an attempt that already closed and is ignored outright:
	 * it changes neither state nor lock ownership of whatever attempt is open now.
	 *
	 * Resolving twice for the same attempt (duplicate notification) is a no-op the
	 * second time — the state is already terminal, so this refuses instead of
	 * re-writing it. Sets `side_effects_applied = false` on a fresh transition: the
	 * caller still owes WC_Order/FS the
	 * corresponding update and must call mark_side_effects_applied() once done,
	 * before try_release_lock() will allow releasing the lock.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param int    $seq              Attempt number this result belongs to (from
	 *                                 the `OrderID` the notification carried).
	 * @param string $new_state        One of STATE_SUCCESS / STATE_FAILURE / STATE_UNKNOWN.
	 * @param string $reason           Short machine-readable reason, stored for diagnostics.
	 *
	 * @return bool True if the transition was applied, false if ignored/refused.
	 */
	public function resolve( int $renewal_order_id, int $seq, string $new_state, string $reason = '' ): bool {
		if ( ! in_array( $new_state, self::TERMINAL_STATES, true ) ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Attempt_State_Store] [resolve rejected: not a terminal state] [order_id: %d] [seq: %d] [state: %s]',
					$renewal_order_id,
					$seq,
					$new_state
				)
			);

			return false;
		}

		$option_name = $this->lock_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Attempt_State_Store] [resolve ignored: no attempt lock found] [order_id: %d] [seq: %d]', $renewal_order_id, $seq )
				);

				return false;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'], $record['state'] ) ) {
				blue_media()->get_woocommerce_logger()->log_warning(
					sprintf( '[Attempt_State_Store] [resolve failed: corrupt attempt record] [order_id: %d]', $renewal_order_id )
				);

				return false;
			}

			if ( (int) $record['seq'] !== $seq ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf(
						'[Attempt_State_Store] [late notification for closed attempt, ignored] [seq=%d] [order_id: %d] [current_seq: %d]',
						$seq,
						$renewal_order_id,
						(int) $record['seq']
					)
				);

				return false;
			}

			// SUCCESS/FAILURE are permanently immutable. UNKNOWN is deliberately NOT in
			// LOCKED_TERMINAL_STATES: a later, correct notification for the same seq
			// must still be able to override it. The second
			// condition still refuses a same-state repeat (e.g. UNKNOWN resolved again
			// after another exhausted recovery run) -- without it, that repeat would
			// pass the CAS below and spuriously reset side_effects_applied to false,
			// causing Recurring_Result_Applier to reapply "manual review needed" side
			// effects (e.g. a duplicate order note) every time it happens again.
			if ( in_array( $record['state'], self::LOCKED_TERMINAL_STATES, true ) || $record['state'] === $new_state ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf(
						'[Attempt_State_Store] [resolve ignored: attempt already terminal or unchanged] [order_id: %d] [seq: %d] [state: %s] [new_state: %s]',
						$renewal_order_id,
						$seq,
						$record['state'],
						$new_state
					)
				);

				return false;
			}

			$record['state']                = $new_state;
			$record['reason']               = $reason;
			$record['side_effects_applied'] = false;
			$record['updated_at']           = time();

			$new_raw = (string) wp_json_encode( $record );

			if ( $this->cas_update( $option_name, $raw, $new_raw ) ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Attempt_State_Store] [resolve applied] [order_id: %d] [seq: %d] [state: %s]', $renewal_order_id, $seq, $new_state )
				);

				return true;
			}
		}

		blue_media()->get_woocommerce_logger()->log_warning(
			sprintf(
				'[Attempt_State_Store] [resolve failed: lost the compare-and-swap race after %d attempts] [order_id: %d] [seq: %d]',
				self::MAX_CAS_RETRIES,
				$renewal_order_id,
				$seq
			)
		);

		return false;
	}

	/**
	 * Record the Autopay `remoteID` captured from the synchronous charge response,
	 * for cross-diagnostics only — it is never used for correlation (that is
	 * `OrderID`/`seq`, see build_order_id()/parse_order_id()); `remoteID` is
	 * "dodatkowy identyfikator diagnostyczny/krzyżowy", not the correlation key.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param int    $seq              Attempt number this transaction id belongs to.
	 * @param string $remote_id        Autopay `remoteID` for the transaction.
	 *
	 * @return bool True if recorded, false if the attempt is no longer the current one.
	 */
	public function record_remote_id( int $renewal_order_id, int $seq, string $remote_id ): bool {
		$option_name = $this->lock_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				return false;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'] ) || (int) $record['seq'] !== $seq ) {
				return false;
			}

			$record['remote_id']  = $remote_id;
			$record['updated_at'] = time();

			if ( $this->cas_update( $option_name, $raw, (string) wp_json_encode( $record ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mark that step 5 (idempotent WC_Order/FS side effects for this attempt's
	 * terminal result) has actually run — the flag try_release_lock() requires
	 * before it will let the lock go, and the flag recovery code checks to tell
	 * "resolved" apart from "resolved AND applied" after a process was killed
	 * between the two (`side_effects_applied`).
	 *
	 * Idempotent: calling this again after it is already true is a no-op success,
	 * since applying side effects twice via Recurring_Result_Applier is itself
	 * idempotent (payment_complete()/update_status() on an already-updated order).
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number whose side effects were applied.
	 *
	 * @return bool True if marked (or already marked), false if this is no longer
	 *              the current attempt for the order.
	 */
	public function mark_side_effects_applied( int $renewal_order_id, int $seq ): bool {
		$option_name = $this->lock_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				return false;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'] ) || (int) $record['seq'] !== $seq ) {
				return false;
			}

			if ( true === ( $record['side_effects_applied'] ?? false ) ) {
				return true;
			}

			$record['side_effects_applied'] = true;
			$record['updated_at']           = time();

			if ( $this->cas_update( $option_name, $raw, (string) wp_json_encode( $record ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read-only peek at the current attempt for a renewal order, for recovery code
	 * and diagnostics (e.g. deciding whether a terminal-but-unapplied attempt needs
	 * its side effects finished after an interrupted process). Never used by this
	 * store itself to make a decision — every mutating method here re-reads under
	 * its own compare-and-swap instead of trusting a value returned earlier.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 *
	 * @return array|null The current attempt record, or null if none is open.
	 */
	public function get_current_attempt( int $renewal_order_id ): ?array {
		$raw = $this->read_raw( $this->lock_option_name( $renewal_order_id ) );

		if ( null === $raw ) {
			return null;
		}

		$record = json_decode( $raw, true );

		return is_array( $record ) ? $record : null;
	}

	/**
	 * STARTING -> SENT: the only transition allowed immediately before the HTTP charge
	 * request is made. Refuses when this is no longer the current
	 * attempt (seq mismatch — a newer attempt has since started, or the order settled
	 * and the row was released) or when the current state is not STARTING (defensive:
	 * this method is meant to be called exactly once per attempt, right before
	 * send_charge() — a second call finding SENT already set is not the expected path).
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number to transition.
	 *
	 * @return bool True if the transition was applied.
	 */
	public function mark_sent( int $renewal_order_id, int $seq ): bool {
		$option_name = $this->lock_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				return false;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'], $record['state'] ) || (int) $record['seq'] !== $seq ) {
				return false;
			}

			if ( self::STATE_STARTING !== $record['state'] ) {
				blue_media()->get_woocommerce_logger()->log_warning(
					sprintf(
						'[Attempt_State_Store] [mark_sent rejected: not in STARTING] [order_id: %d] [seq: %d] [state: %s]',
						$renewal_order_id,
						$seq,
						$record['state']
					)
				);

				return false;
			}

			$record['state']      = self::STATE_SENT;
			$record['updated_at'] = time();

			if ( $this->cas_update( $option_name, $raw, (string) wp_json_encode( $record ) ) ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Attempt_State_Store] [mark_sent] [order_id: %d] [seq: %d]', $renewal_order_id, $seq )
				);

				return true;
			}
		}

		return false;
	}

	/**
	 * Abandons an attempt that was created (STARTING) but never transitioned to SENT —
	 * i.e. nothing was ever transmitted to Autopay for it. Deletes the lock row outright
	 * without recording any terminal state, since there is nothing to reconcile: as far
	 * as Autopay is concerned this attempt never existed. Refuses when the current state
	 * is not STARTING (never abort an attempt that might already have been sent — once
	 * SENT, the only safe path is a status check, never a silent local abort) or when
	 * `$seq` no longer matches the current attempt.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param int    $seq              Attempt number to abandon.
	 * @param string $reason           Short machine-readable reason, logged only (not stored).
	 *
	 * @return bool True if the row was deleted.
	 */
	public function abort_unsent( int $renewal_order_id, int $seq, string $reason = '' ): bool {
		$raw = $this->read_raw( $this->lock_option_name( $renewal_order_id ) );

		if ( null === $raw ) {
			return false;
		}

		$record = json_decode( $raw, true );

		if ( ! is_array( $record ) || ! isset( $record['seq'], $record['state'] ) || (int) $record['seq'] !== $seq ) {
			return false;
		}

		if ( self::STATE_STARTING !== $record['state'] ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Attempt_State_Store] [abort_unsent rejected: not in STARTING] [order_id: %d] [seq: %d] [state: %s] [reason: %s]',
					$renewal_order_id,
					$seq,
					$record['state'],
					$reason
				)
			);

			return false;
		}

		$released = $this->delete_row_if_matches( $renewal_order_id, $raw );

		if ( $released ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Attempt_State_Store] [abort_unsent] [order_id: %d] [seq: %d] [reason: %s]', $renewal_order_id, $seq, $reason )
			);
		}

		return $released;
	}

	/**
	 * Claims the right to apply this attempt's terminal side effects, so that two
	 * near-simultaneous callers (e.g. an ITN request and a status-check job landing
	 * within milliseconds of each other) do not both run Recurring_Result_Applier for
	 * the same resolved attempt. This is a BOUNDED mitigation, not a proof of
	 * exactly-once execution: if the winning caller crashes after claiming but before
	 * calling mark_side_effects_applied(), the claim is only reclaimable after
	 * SIDE_EFFECTS_CLAIM_TIMEOUT_SECONDS have passed — a
	 * documented, accepted limitation, not a guarantee against every interleaving.
	 *
	 * Returns the exact record that was claimed (so the caller applies side effects
	 * against a value it atomically owns, never a separately re-read, possibly-stale
	 * snapshot), or null when: no open attempt, seq mismatch, not terminal, side
	 * effects already applied, or the claim window has not yet expired for a still-held
	 * claim.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number whose side effects the caller wants to apply.
	 *
	 * @return array|null The claimed record, or null if the claim was not granted.
	 */
	public function try_claim_side_effects( int $renewal_order_id, int $seq ): ?array {
		$option_name = $this->lock_option_name( $renewal_order_id );
		$now         = time();

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				return null;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'], $record['state'] ) || (int) $record['seq'] !== $seq ) {
				return null;
			}

			if ( ! in_array( $record['state'], self::TERMINAL_STATES, true ) || true === ( $record['side_effects_applied'] ?? false ) ) {
				return null;
			}

			$claimed_at = $record['side_effects_claim_started_at'] ?? null;

			if ( null !== $claimed_at && ( $now - (int) $claimed_at ) < self::SIDE_EFFECTS_CLAIM_TIMEOUT_SECONDS ) {
				// Someone else holds an unexpired claim -- back off, do not apply
				// side effects concurrently with them.
				return null;
			}

			$record['side_effects_claim_started_at'] = $now;
			$new_raw                                 = (string) wp_json_encode( $record );

			if ( $this->cas_update( $option_name, $raw, $new_raw ) ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Atomically increments the persisted count of status checks performed for this
	 * attempt. This is the AUTHORITATIVE budget for Renewal_Status_Check_Job's
	 * MAX_CHECKS decision — never re-derived solely from
	 * an Action Scheduler job argument, so a lost/re-scheduled job (e.g. found and
	 * restarted by Renewal_Attempt_Sweep) can never silently reset the budget back to 1.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 * @param int $seq              Attempt number being checked.
	 *
	 * @return int|null The new count, or null if this is no longer the current attempt.
	 */
	public function increment_status_check_count( int $renewal_order_id, int $seq ): ?int {
		$option_name = $this->lock_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$raw = $this->read_raw( $option_name );

			if ( null === $raw ) {
				return null;
			}

			$record = json_decode( $raw, true );

			if ( ! is_array( $record ) || ! isset( $record['seq'] ) || (int) $record['seq'] !== $seq ) {
				return null;
			}

			$new_count                    = ( (int) ( $record['status_checks_done'] ?? 0 ) ) + 1;
			$record['status_checks_done'] = $new_count;
			$record['updated_at']         = time();

			if ( $this->cas_update( $option_name, $raw, (string) wp_json_encode( $record ) ) ) {
				return $new_count;
			}
		}

		return null;
	}

	/**
	 * Paginated, authoritative scan of every currently-open attempt record directly
	 * from `wp_options` — the only reliable way to enumerate attempts needing recovery
	 * attention. Deliberately NOT derived from a
	 * WC_Order query: an order's `date_modified` says nothing about whether its attempt
	 * lock is old or fresh, and a WC query would either miss genuinely stuck attempts on
	 * old orders (if age-filtered) or require scanning every renewal order ever created
	 * (if not) just to throw almost all of them away after a cheap store lookup already
	 * available here.
	 *
	 * @param int $limit  Maximum rows to return.
	 * @param int $offset Rows to skip (for paging through more than `$limit` open attempts).
	 *
	 * @return array<int, array> Renewal order id => decoded attempt record.
	 */
	public function find_open_attempts( int $limit, int $offset = 0 ): array {
		$lock_like = $this->db->esc_like( self::LOCK_OPTION_PREFIX ) . '%';
		$seq_like  = $this->db->esc_like( self::SEQ_OPTION_PREFIX ) . '%';

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT option_name, option_value FROM {$this->db->options}
				 WHERE option_name LIKE %s AND option_name NOT LIKE %s
				 ORDER BY option_id ASC LIMIT %d OFFSET %d",
				$lock_like,
				$seq_like,
				$limit,
				$offset
			)
		);

		$open = [];

		foreach ( (array) $rows as $row ) {
			$renewal_order_id = (int) str_replace( self::LOCK_OPTION_PREFIX, '', (string) $row->option_name );
			$record           = json_decode( (string) $row->option_value, true );

			if ( $renewal_order_id > 0 && is_array( $record ) ) {
				$open[ $renewal_order_id ] = $record;
			}
		}

		return $open;
	}

	/**
	 * Release the lock for a renewal order, but only if `$owner_token` (the attempt
	 * `seq` the caller believes it holds, as a string) still matches the current
	 * attempt, that attempt has reached a terminal state, AND its side effects are
	 * confirmed applied (release happens "wyłącznie po potwierdzeniu, że krok 5 ...
	 * faktycznie się wykonał"). The delete itself is
	 * a delete-if-matches against the exact row content just read, so a release
	 * request based on stale information can never delete a row someone else has
	 * since changed.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param string $owner_token      The attempt `seq` the caller holds, as a string.
	 *
	 * @return bool True if this call released the lock.
	 */
	public function try_release_lock( int $renewal_order_id, string $owner_token ): bool {
		$raw = $this->read_raw( $this->lock_option_name( $renewal_order_id ) );

		if ( null === $raw ) {
			return false;
		}

		$record = json_decode( $raw, true );

		if ( ! is_array( $record ) || ! isset( $record['seq'], $record['state'], $record['side_effects_applied'] ) ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf( '[Attempt_State_Store] [try_release_lock failed: corrupt attempt record] [order_id: %d]', $renewal_order_id )
			);

			return false;
		}

		if ( (string) $record['seq'] !== $owner_token ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Attempt_State_Store] [try_release_lock rejected: owner mismatch] [order_id: %d] [owner_token: %s] [current_seq: %d]',
					$renewal_order_id,
					$owner_token,
					(int) $record['seq']
				)
			);

			return false;
		}

		// LOCKED_TERMINAL_STATES (SUCCESS/FAILURE only) -- NOT the broader TERMINAL_STATES.
		// UNKNOWN must never be released through this public API: doing so would let a
		// new attempt start (new seq), after which a later, correct notification for
		// THIS seq would be rejected by resolve() as "late notification for a closed
		// attempt" (its seq no longer matches the current record). The guard lives here
		// so no future caller can release an UNKNOWN attempt by simply choosing to call
		// this method for it.
		if ( ! in_array( $record['state'], self::LOCKED_TERMINAL_STATES, true ) || true !== $record['side_effects_applied'] ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Attempt_State_Store] [try_release_lock rejected: attempt not resolved with side effects applied, or state is not releasable] [order_id: %d] [seq: %s] [state: %s]',
					$renewal_order_id,
					$owner_token,
					$record['state']
				)
			);

			return false;
		}

		$released = $this->delete_row_if_matches( $renewal_order_id, $raw );

		if ( $released ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[Attempt_State_Store] [try_release_lock] [order_id: %d] [seq: %s]', $renewal_order_id, $owner_token )
			);
		}

		return $released;
	}

	/**
	 * Durable, monotonically-increasing attempt counter for a renewal order — never
	 * deleted, unlike the lock row, so identifiers stay unique across many
	 * start/resolve/release cycles for the same order (this is what the plan calls
	 * "trwała unikalność identyfikatora próby także po zwolnieniu blokady"). A true
	 * compare-and-swap loop, same shape as cas_update(): each caller only returns
	 * the value it itself successfully wrote, never a value merely observed — two
	 * concurrent callers reading the same "current" value after both increments
	 * completed could otherwise both compute and return the same "next" number.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 *
	 * @throws RuntimeException If the compare-and-swap could not succeed within
	 *                          MAX_CAS_RETRIES attempts.
	 */
	private function allocate_next_seq( int $renewal_order_id ): int {
		$option_name = $this->seq_option_name( $renewal_order_id );

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$current_raw = $this->read_raw( $option_name );

			if ( null === $current_raw ) {
				$previous = $this->db->suppress_errors( true );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a trusted table-name property, not user input.
				$inserted = $this->db->query(
					$this->db->prepare(
						"INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						$option_name,
						'1'
					)
				);
				$this->db->suppress_errors( $previous );

				if ( false !== $inserted ) {
					return 1;
				}

				// Lost the race to create the counter row (duplicate key) — someone
				// else just created it; re-read and CAS-increment it on the next
				// loop iteration instead of failing.
				continue;
			}

			$current = (int) $current_raw;
			$next    = $current + 1;

			if ( $this->cas_update( $option_name, $current_raw, (string) $next ) ) {
				return $next;
			}
		}

		throw new RuntimeException(
			sprintf( 'Could not allocate a unique attempt sequence for renewal order %d after %d attempts.', $renewal_order_id, self::MAX_CAS_RETRIES ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is thrown, not echoed; escaping belongs to the display layer.
		);
	}

	/**
	 * Insert the lock row for a renewal order. Succeeds (returns true) only if no
	 * row with this `option_name` exists yet — `wp_options.option_name` is UNIQUE,
	 * so a concurrent second INSERT for the same order fails at the database level.
	 * This, not any PHP-level check, is the actual mutual-exclusion guarantee
	 * ("prawdziwa gwarancja wzajemnego wykluczania, nie odczyt-porównanie-zapis"). The duplicate-key failure is an expected, routine
	 * outcome of a race, not an error condition, so DB error reporting is
	 * suppressed around it to avoid spamming logs for normal contention.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param string $raw_json         Encoded attempt record to store.
	 */
	private function insert_lock_row( int $renewal_order_id, string $raw_json ): bool {
		$previous = $this->db->suppress_errors( true );

		$inserted = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$this->lock_option_name( $renewal_order_id ),
				$raw_json
			)
		);

		$this->db->suppress_errors( $previous );

		return false !== $inserted;
	}

	/**
	 * Delete-if-matches: removes the lock row only if its current `option_value`
	 * still equals `$expected_raw` verbatim — a single-statement compare-and-swap
	 * delete, so a release decision made from a value read a moment ago can never
	 * remove a row that something else has since changed.
	 *
	 * @param int    $renewal_order_id WooCommerce renewal order id.
	 * @param string $expected_raw     The exact `option_value` the row must still hold.
	 */
	private function delete_row_if_matches( int $renewal_order_id, string $expected_raw ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s",
				$this->lock_option_name( $renewal_order_id ),
				$expected_raw
			)
		);

		return 1 === $affected;
	}

	/**
	 * Single-statement compare-and-swap: updates `option_value` to `$new_raw` only
	 * if it currently still equals `$current_raw` verbatim. Atomicity is MySQL's
	 * (the UPDATE ... WHERE evaluates and applies in one statement), never
	 * PHP-level read-then-write.
	 *
	 * @param string $option_name  Option to update.
	 * @param string $current_raw  Value the row must still hold for the update to apply.
	 * @param string $new_raw      Value to write.
	 */
	private function cas_update( string $option_name, string $current_raw, string $new_raw ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				$option_name,
				$current_raw
			)
		);

		return 1 === $affected;
	}

	/**
	 * Raw, uncached read of a single option's value directly from the database —
	 * deliberately bypasses `get_option()`/the options object cache entirely, so it
	 * always reflects what the compare-and-swap operations above actually see.
	 *
	 * @param string $option_name Option to read.
	 */
	private function read_raw( string $option_name ): ?string {
		$value = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $option_name )
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Option name for a renewal order's current-attempt lock row.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 */
	private function lock_option_name( int $renewal_order_id ): string {
		return self::LOCK_OPTION_PREFIX . $renewal_order_id;
	}

	/**
	 * Option name for a renewal order's durable attempt-sequence counter.
	 *
	 * @param int $renewal_order_id WooCommerce renewal order id.
	 */
	private function seq_option_name( int $renewal_order_id ): string {
		return self::SEQ_OPTION_PREFIX . $renewal_order_id;
	}
}
