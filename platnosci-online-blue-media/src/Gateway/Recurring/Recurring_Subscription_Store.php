<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Reads and writes card-recurring data on a Flexible Subscriptions Subscription,
 * including an atomic ClientHash coordination mechanism (see
 * try_claim_for_activation()/release_activation_claim()/mark_client_hash_tombstoned()).
 *
 * Deliberately uses `SubscriptionFinder` (not `SubscriptionRepository`) for read
 * queries even though FS marks it `@deprecated` in favour of the repository: the
 * repository requires FS's own, internally-wired EventDispatcherInterface, which
 * Flexible Subscriptions does not expose to external code (verified — no static
 * accessor, global, or hook exposes its DI container). SubscriptionFinder has no
 * constructor dependencies and is a thin, safe wrapper around `wc_get_orders()`/
 * `wc_get_order()` — exactly what a read-only lookup needs here.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use wpdb;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;
use WPDesk\FlexibleSubscriptions\Subscription\SubscriptionFinder;

/**
 * Reads and writes card-recurring data on a Flexible Subscriptions Subscription.
 */
class Recurring_Subscription_Store {

	private const META_CLIENT_HASH         = '_autopay_client_hash';
	private const META_CHANNEL             = '_autopay_recurring_channel';
	private const META_CURRENCY            = '_autopay_recurring_currency';
	private const META_SERVICE_ID          = '_autopay_recurring_service_id';
	private const META_ENVIRONMENT         = '_autopay_recurring_environment';
	private const META_STATUS              = '_autopay_recurring_status';
	private const META_EXTERNAL_CONFIRMED  = '_autopay_recurring_external_deactivation_confirmed';
	private const META_DEACTIVATION_REASON = '_autopay_recurring_deactivation_reason';

	/**
	 * Set (`yes`) when a local RPDN pause (Fs_Lifecycle_Bridge::pause()) failed or
	 * could not be attempted (FS inactive/not captured), so the FS-side subscription
	 * status could not be synced to `on-hold` even though the instrument is already
	 * blocked locally (see mark_fs_sync_pending()/clear_fs_sync_pending()). Never
	 * used for any charge-safety decision — only diagnostics/completion tracking.
	 */
	private const META_FS_SYNC_PENDING = '_autopay_recurring_fs_sync_pending';

	private const STATUS_ACTIVE   = 'active';
	private const STATUS_INACTIVE = 'inactive';

	private const CLIENT_HASH_LOCK_OPTION_PREFIX = 'bm_recurring_ch_lock_';

	/**
	 * Permanent terminal state of a ClientHash+ServiceID+environment lock row —
	 * once written, a lock row never leaves this state (see mark_client_hash_tombstoned()).
	 */
	private const CH_STATE_TOMBSTONED = 'tombstoned';

	/**
	 * Prefix for a temporary, in-flight activation claim (see try_claim_for_activation()).
	 * The remainder of the value is an opaque per-claim token, unique per call.
	 */
	private const CH_STATE_CLAIMED_PREFIX = 'claimed:';

	/**
	 * Concurrent writers for the same ClientHash+ServiceID+environment context are
	 * expected to be rare (at most an RPAN and an RPDN landing within milliseconds
	 * of each other) — same assumption already documented for Attempt_State_Store.
	 */
	private const MAX_CAS_RETRIES = 3;

	/**
	 * Direct database access, used only by the low-level ClientHash lock primitives
	 * below (mirrors Attempt_State_Store's own reasoning: these operations must
	 * never go through get_option()/update_option()/add_option()/delete_option(),
	 * which are read-then-write at the PHP level and would defeat the whole point
	 * of using `wp_options.option_name`'s UNIQUE constraint for mutual exclusion).
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
	 * Save a new recurring activation, unless it would silently reactivate an
	 * instrument this subscription previously had and that was later deactivated
	 * (RPDN) — reactivation and instrument change are explicitly out of MVP scope.
	 *
	 * Coordinates with a concurrent/earlier RPDN for the SAME ClientHash+ServiceID+
	 * environment via an atomic claim (see try_claim_for_activation()):
	 * this is a real mutual-exclusion guarantee for the ClientHash's lock row, not
	 * a check-then-write race. The one gap a single wp_options row cannot close by
	 * itself — this call's own subscription-meta write landing in the database
	 * AFTER a concurrent RPDN's tombstone already took over the claim — is closed
	 * by re-checking the claim right after the save and self-correcting
	 * (mark_inactive()) if it was lost, so the ClientHash never ends up left in a
	 * usable, active state once Autopay has told us (via a valid RPDN) it is dead,
	 * regardless of the exact interleaving of the two requests.
	 *
	 * @param Subscription $subscription Subscription to activate.
	 * @param string       $client_hash  ClientHash received in RPAN.
	 * @param int          $channel      Autopay GatewayID used for activation (1503).
	 * @param string       $currency     Currency active at activation time.
	 * @param string       $service_id   ServiceID active at activation time.
	 * @param string       $environment  'test' or 'production', active at activation time.
	 *
	 * @return bool True if the activation was accepted and saved, false if rejected.
	 */
	public function save_activation(
		Subscription $subscription,
		string $client_hash,
		int $channel,
		string $currency,
		string $service_id,
		string $environment
	): bool {
		$previous_hash = (string) $subscription->get_meta( self::META_CLIENT_HASH, true );

		if ( '' !== $previous_hash && $previous_hash !== $client_hash ) {
			// Subscription already has (or had) a different ClientHash — this would be
			// an instrument change or reactivation with a new instrument, both out of
			// MVP scope.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Subscription_Store] [save_activation rejected: instrument change/reactivation out of scope] [subscription_id: %d] [previous_hash: %s]',
					$subscription->get_id(),
					$this->mask_client_hash( $previous_hash )
				)
			);

			return false;
		}

		if ( '' !== $previous_hash && $previous_hash === $client_hash
			&& self::STATUS_INACTIVE === (string) $subscription->get_meta( self::META_STATUS, true )
		) {
			// Same ClientHash as before, but it was deactivated (RPDN) — this is a late
			// retry of the original RPAN notification, not a new activation. Do not
			// reactivate a deactivated link.
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Recurring_Subscription_Store] [save_activation ignored: late RPAN for already-deactivated link] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return false;
		}

		$claim_token = $this->try_claim_for_activation( $client_hash, $service_id, $environment );

		if ( null === $claim_token ) {
			// Either permanently tombstoned by RPDN, or a concurrent activation for
			// this exact ClientHash+ServiceID+environment is in flight right now.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Subscription_Store] [save_activation rejected: ClientHash tombstoned or concurrently claimed] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return false;
		}

		$subscription->update_meta_data( self::META_CLIENT_HASH, $client_hash );
		$subscription->update_meta_data( self::META_CHANNEL, $channel );
		$subscription->update_meta_data( self::META_CURRENCY, $currency );
		$subscription->update_meta_data( self::META_SERVICE_ID, $service_id );
		$subscription->update_meta_data( self::META_ENVIRONMENT, $environment );
		$subscription->update_meta_data( self::META_STATUS, self::STATUS_ACTIVE );
		$subscription->delete_meta_data( self::META_DEACTIVATION_REASON );
		$subscription->delete_meta_data( self::META_EXTERNAL_CONFIRMED );
		$subscription->save();

		if ( ! $this->release_activation_claim( $client_hash, $service_id, $environment, $claim_token ) ) {
			// A concurrent RPDN took over our claim (converted it to a permanent
			// tombstone) WHILE the subscription save above was in flight — our
			// activation just committed, but Autopay has, by definition, already
			// told us this ClientHash is dead. Self-correct immediately: never leave
			// a usable, active link for a tombstoned ClientHash, regardless of which
			// of the two requests happened to write to WordPress last.
			$this->mark_inactive( $subscription, 'tombstoned_during_activation_race' );

			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Subscription_Store] [save_activation reverted: RPDN won a concurrent race after our save committed] [subscription_id: %d]',
					$subscription->get_id()
				)
			);

			return false;
		}

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf(
				'[Recurring_Subscription_Store] [save_activation] [subscription_id: %d] [channel: %d] [currency: %s] [client_hash: %s]',
				$subscription->get_id(),
				$channel,
				$currency,
				$this->mask_client_hash( $client_hash )
			)
		);

		return true;
	}

	/**
	 * Recurring data for a subscription, or null when there is no active instrument
	 * (never activated, or deactivated by RPDN/cancellation).
	 *
	 * @param Subscription $subscription Subscription to read.
	 *
	 * @return array{client_hash: string, channel: int, currency: string, service_id: string, environment: string}|null
	 */
	public function get_recurring_data( Subscription $subscription ): ?array {
		if ( self::STATUS_ACTIVE !== (string) $subscription->get_meta( self::META_STATUS, true ) ) {
			return null;
		}

		return $this->get_recurring_data_ignoring_status( $subscription );
	}

	/**
	 * Same fields as get_recurring_data(), but WITHOUT the "status must be active"
	 * gate — the durable record of which instrument a subscription was ever linked
	 * to, readable even after mark_inactive() has already run.
	 *
	 * Used by the cancellation retry (Subscription_Cancellation_Listener),
	 * which must re-derive the exact original ClientHash/currency/ServiceID/
	 * environment for a subscription it has ALREADY marked inactive — never by
	 * trusting a value passed through Action Scheduler arguments (a full ClientHash
	 * must never appear there, see Deactivation_Retry_Job) and never by silently
	 * substituting whatever instrument the subscription happens to reference NOW
	 * (instrument change is out of MVP scope — save_activation() never lets
	 * `_autopay_client_hash` change once set, which is what makes reading it again
	 * here, instead of threading the original value through, safe).
	 *
	 * @param Subscription $subscription Subscription to read.
	 *
	 * @return array{client_hash: string, channel: int, currency: string, service_id: string, environment: string}|null
	 */
	public function get_recurring_data_ignoring_status( Subscription $subscription ): ?array {
		$client_hash = (string) $subscription->get_meta( self::META_CLIENT_HASH, true );

		if ( '' === $client_hash ) {
			return null;
		}

		return [
			'client_hash' => $client_hash,
			'channel'     => (int) $subscription->get_meta( self::META_CHANNEL, true ),
			'currency'    => (string) $subscription->get_meta( self::META_CURRENCY, true ),
			'service_id'  => (string) $subscription->get_meta( self::META_SERVICE_ID, true ),
			'environment' => (string) $subscription->get_meta( self::META_ENVIRONMENT, true ),
		];
	}

	/**
	 * Mark the recurring link inactive immediately (local, unconditional safety —
	 * independent of whether an external deactivation call to Autopay succeeds).
	 *
	 * @param Subscription $subscription Subscription to deactivate locally.
	 * @param string       $reason       Short machine-readable reason, stored and
	 *                                   added as an order note for diagnostics.
	 */
	public function mark_inactive( Subscription $subscription, string $reason ): void {
		$subscription->update_meta_data( self::META_STATUS, self::STATUS_INACTIVE );
		$subscription->update_meta_data( self::META_DEACTIVATION_REASON, $reason );
		$subscription->save();

		$subscription->add_order_note(
			sprintf(
				/* translators: %s: machine-readable deactivation reason. */
				__( 'Autopay recurring card link deactivated locally (reason: %s).', 'platnosci-online-blue-media' ),
				$reason
			)
		);

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf(
				'[Recurring_Subscription_Store] [mark_inactive] [subscription_id: %d] [reason: %s]',
				$subscription->get_id(),
				$reason
			)
		);
	}

	/**
	 * Record separately whether Autopay has confirmed the recurring instrument is
	 * externally deactivated — local blocking (mark_inactive) is unconditional and
	 * must never be conflated with this.
	 *
	 * Set to `true` from two independent sources, either of which is sufficient on
	 * its own: (1) `Recurring_Api_Client::deactivate()` returning verified CONFIRMED
	 * for a merchant/customer-initiated cancellation (Subscription_Cancellation_Listener),
	 * or (2) a correctly-signed, verified RPDN for this ClientHash
	 * (RPDN_Webhook_Handler) — a valid RPDN IS Autopay's own confirmation
	 * that the instrument is externally dead, independent of whether WE ever asked
	 * for that deactivation. Set to `false` when a deactivation request was sent but
	 * not confirmed (transport error / NOTCONFIRMED / credentials unavailable).
	 *
	 * @param Subscription $subscription Subscription being deactivated.
	 * @param bool         $confirmed    Whether external deactivation is confirmed.
	 */
	public function mark_external_deactivation_confirmed( Subscription $subscription, bool $confirmed ): void {
		$subscription->update_meta_data( self::META_EXTERNAL_CONFIRMED, $confirmed ? 'yes' : 'no' );
		$subscription->save();
	}

	/**
	 * Whether external deactivation has already been confirmed for this
	 * subscription's instrument — checked by the cancellation retry
	 * (Subscription_Cancellation_Listener) before sending another `deactivate_recurring`
	 * request, in case an independent RPDN already confirmed it in the meantime.
	 *
	 * @param Subscription $subscription Subscription to check.
	 */
	public function is_external_deactivation_confirmed( Subscription $subscription ): bool {
		return 'yes' === (string) $subscription->get_meta( self::META_EXTERNAL_CONFIRMED, true );
	}

	/**
	 * Machine-readable reason mark_inactive() was called with, for diagnostics
	 * -- empty string when the instrument was never deactivated
	 * locally. Written by mark_inactive(), never read anywhere before this.
	 *
	 * @param Subscription $subscription Subscription to check.
	 */
	public function get_deactivation_reason( Subscription $subscription ): string {
		return (string) $subscription->get_meta( self::META_DEACTIVATION_REASON, true );
	}

	/**
	 * Mark that a local RPDN pause attempt (Fs_Lifecycle_Bridge::pause()) did not
	 * complete — either because no `$fsub` was captured (FS inactive/not yet
	 * initialised in this request) or because `handle_status_change()` itself
	 * refused/failed. The instrument is already safely blocked locally
	 * (mark_inactive() always runs first, unconditionally) — this flag exists only
	 * to make the INCOMPLETE FS-side status sync durably visible and completable,
	 * never to gate any charge-safety decision.
	 *
	 * @param Subscription $subscription Subscription whose FS pause did not complete.
	 */
	public function mark_fs_sync_pending( Subscription $subscription ): void {
		$subscription->update_meta_data( self::META_FS_SYNC_PENDING, 'yes' );
		$subscription->save();
	}

	/**
	 * Clear the pending-FS-sync flag once a (possibly retried) pause() call
	 * eventually succeeds.
	 *
	 * @param Subscription $subscription Subscription whose FS pause has now completed.
	 */
	public function clear_fs_sync_pending( Subscription $subscription ): void {
		if ( '' === (string) $subscription->get_meta( self::META_FS_SYNC_PENDING, true ) ) {
			return; // Already clear — avoid an unnecessary save()/order note churn.
		}

		$subscription->delete_meta_data( self::META_FS_SYNC_PENDING );
		$subscription->save();
	}

	/**
	 * Whether this subscription still has an incomplete FS-side pause sync.
	 *
	 * @param Subscription $subscription Subscription to check.
	 */
	public function is_fs_sync_pending( Subscription $subscription ): bool {
		return 'yes' === (string) $subscription->get_meta( self::META_FS_SYNC_PENDING, true );
	}

	/**
	 * Find every local subscription currently (or previously) linked to a given
	 * ClientHash, scoped to the ServiceID/environment the notification was
	 * verified against. Never assume a ClientHash maps to exactly one subscription
	 * — the documentation does not guarantee this.
	 *
	 * @param string $client_hash ClientHash from RPDN.
	 * @param string $service_id  ServiceID the RPDN signature was verified against.
	 * @param string $environment 'test' or 'production' matching that ServiceID.
	 *
	 * @return Subscription[]
	 */
	public function find_subscription_by_client_hash( string $client_hash, string $service_id, string $environment ): array {
		$orders = wc_get_orders(
			[
				'type'       => 'fsb_subscription',
				'status'     => 'any',
				'limit'      => -1,
				'return'     => 'objects',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by type=fsb_subscription, no realistic index alternative for a cross-cutting ClientHash lookup.
					'relation' => 'AND',
					[
						'key'   => self::META_CLIENT_HASH,
						'value' => $client_hash,
					],
					[
						'key'   => self::META_SERVICE_ID,
						'value' => $service_id,
					],
					[
						'key'   => self::META_ENVIRONMENT,
						'value' => $environment,
					],
				],
			]
		);

		$subscriptions = array_values( array_filter(
			$orders,
			static function ( $order ) {
				return $order instanceof Subscription;
			}
		) );

		if ( 0 === count( $subscriptions ) ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf(
					'[Recurring_Subscription_Store] [find_subscription_by_client_hash: no local match] [client_hash: %s] [service_id: %s] [environment: %s]',
					$this->mask_client_hash( $client_hash ),
					$service_id,
					$environment
				)
			);
		}

		return $subscriptions;
	}

	/**
	 * Find the subscription created from a given (source/checkout) order. MVP scope
	 * guarantees exactly one subscription per order; anything else is logged as an
	 * anomaly and treated as "not found" (fail closed, never guess).
	 *
	 * @param WC_Order $order Source checkout order (not a renewal order).
	 *
	 * @return Subscription|null
	 */
	public function find_subscription_for_order( WC_Order $order ): ?Subscription {
		if ( ! class_exists( SubscriptionFinder::class ) ) {
			// Flexible Subscriptions not active -- reachable from RPAN_Webhook_Handler,
			// an externally-triggered Autopay notification that can arrive (or be
			// retried by Autopay) after FS was deactivated locally. Fail closed instead
			// of a fatal "class not found" from a class FS itself no longer provides.
			return null;
		}

		$subscriptions = array_values( ( new SubscriptionFinder() )->find_all_by_order( $order ) );

		if ( 1 !== count( $subscriptions ) ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[Recurring_Subscription_Store] [find_subscription_for_order: expected exactly one subscription] [order_id: %d] [found: %d]',
					$order->get_id(),
					count( $subscriptions )
				)
			);

			return null;
		}

		return $subscriptions[0] instanceof Subscription ? $subscriptions[0] : null;
	}

	/**
	 * Find the subscription a renewal order belongs to, via the dedicated
	 * `_subscription_renewal` meta contract — never via `get_parent_id()` (its
	 * semantics are reversed between Subscription and renewal order).
	 *
	 * @param WC_Order $renewal_order Renewal order (e.g. from the scheduled payment hook).
	 *
	 * @return Subscription|null
	 */
	public function find_subscription_for_renewal_order( WC_Order $renewal_order ): ?Subscription {
		if ( ! class_exists( Renewal::class ) || ! class_exists( SubscriptionFinder::class ) ) {
			// Flexible Subscriptions not active -- reachable from Renewal_Payment_Handler
			// (the shared `woocommerce_scheduled_subscription_payment_bluemedia` hook) and
			// from Recurring_Result_Applier (ITN webhook / background status-check and
			// sweep jobs), none of which re-check FS's presence before calling here. Those
			// callers, like this one, run independently of the local FS plugin state (an
			// externally-triggered Autopay notification, or an Autopay-side scheduled job
			// registered while FS was still active). Fail closed instead of a fatal
			// "class not found" from a class FS itself no longer provides.
			return null;
		}

		if ( ! Renewal::is_renewal_order( $renewal_order ) ) {
			return null;
		}

		$subscription = ( new SubscriptionFinder() )->find_by_payment_request( $renewal_order->get_id() );

		return $subscription instanceof Subscription ? $subscription : null;
	}

	/**
	 * Atomically claim a ClientHash+ServiceID+environment context for a NEW
	 * activation (RPAN). This is a real mutual-exclusion primitive — a raw SQL
	 * INSERT relying on `wp_options.option_name` being UNIQUE (same primitive as
	 * Attempt_State_Store::insert_lock_row()), never `add_option()`'s check-then-
	 * insert, and never a plain `get_option()` read.
	 *
	 * @param string $client_hash ClientHash being activated.
	 * @param string $service_id  ServiceID the RPAN signature was verified against.
	 * @param string $environment 'test' or 'production' matching that ServiceID.
	 *
	 * @return string|null An opaque claim token to pass to release_activation_claim(),
	 *                      or null if the context is already permanently tombstoned
	 *                      or claimed by another concurrent activation right now.
	 */
	public function try_claim_for_activation( string $client_hash, string $service_id, string $environment ): ?string {
		$option_name = $this->client_hash_lock_option_name( $client_hash, $service_id, $environment );
		$token       = wp_generate_uuid4();

		if ( $this->insert_lock_row( $option_name, self::CH_STATE_CLAIMED_PREFIX . $token ) ) {
			return $token;
		}

		$existing = $this->read_raw_option( $option_name );

		blue_media()->get_woocommerce_logger()->log_warning(
			sprintf(
				'[Recurring_Subscription_Store] [try_claim_for_activation refused] [reason: %s]',
				self::CH_STATE_TOMBSTONED === $existing ? 'tombstoned' : 'concurrently_claimed'
			)
		);

		return null;
	}

	/**
	 * Release an activation claim WITHOUT tombstoning it, once the subscription
	 * meta write it was guarding has completed.
	 *
	 * @param string $client_hash ClientHash that was claimed.
	 * @param string $service_id  ServiceID the claim was made under.
	 * @param string $environment 'test' or 'production' matching that ServiceID.
	 * @param string $token       Token returned by try_claim_for_activation().
	 *
	 * @return bool True if this call released the claim; false if it was taken over
	 *              in the meantime (converted to a tombstone by a concurrent RPDN) —
	 *              the caller MUST treat this as "the activation is no longer valid".
	 */
	public function release_activation_claim( string $client_hash, string $service_id, string $environment, string $token ): bool {
		$option_name = $this->client_hash_lock_option_name( $client_hash, $service_id, $environment );

		return $this->delete_row_if_matches_raw( $option_name, self::CH_STATE_CLAIMED_PREFIX . $token );
	}

	/**
	 * Record that a ClientHash was deactivated (RPDN) — permanently, and with
	 * priority over any in-flight RPAN activation claim for the same
	 * ClientHash+ServiceID+environment. Two cases:
	 *
	 * - No lock row exists yet (no activation is currently in flight, and this
	 *   ClientHash was never tombstoned before): the atomic INSERT below succeeds
	 *   outright.
	 * - A row already exists: if it is already `tombstoned`, this is an idempotent
	 *   no-op (duplicate/retried RPDN for the same ClientHash). If it is an
	 *   in-flight activation CLAIM, this compare-and-swaps it directly to
	 *   `tombstoned` — taking the row over from whatever RPAN currently holds it.
	 *   That RPAN's own release_activation_claim() call will then find the value no
	 *   longer matches its token and report failure, so save_activation() finds out
	 *   and immediately reverts (mark_inactive()) the activation it just committed.
	 *   This is what guarantees deactivation always wins, regardless of whether the
	 *   RPDN or the racing RPAN's subscription-meta write reaches the database
	 *   first — permanent (`tombstoned`), no expiry: Autopay's documented retry
	 *   schedule for a SINGLE notification does not prove a later replay (backup
	 *   restore, infrastructure incident, manual resend) is impossible, so a
	 *   time-limited tombstone would reopen the exact reactivation hole this guard
	 *   exists to close.
	 *
	 * @param string $client_hash ClientHash from the RPDN.
	 * @param string $service_id  ServiceID the RPDN signature was verified against.
	 * @param string $environment 'test' or 'production' matching that ServiceID.
	 */
	public function mark_client_hash_tombstoned( string $client_hash, string $service_id, string $environment ): void {
		$option_name = $this->client_hash_lock_option_name( $client_hash, $service_id, $environment );

		if ( $this->insert_lock_row( $option_name, self::CH_STATE_TOMBSTONED ) ) {
			return;
		}

		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {
			$existing = $this->read_raw_option( $option_name );

			if ( null === $existing || self::CH_STATE_TOMBSTONED === $existing ) {
				return; // Already tombstoned (by us or a concurrent duplicate RPDN) -- done.
			}

			if ( $this->cas_update_raw( $option_name, $existing, self::CH_STATE_TOMBSTONED ) ) {
				return;
			}
		}

		blue_media()->get_woocommerce_logger()->log_warning(
			'[Recurring_Subscription_Store] [mark_client_hash_tombstoned failed: lost the compare-and-swap race]'
		);
	}

	/**
	 * Whether a ClientHash is permanently tombstoned, scoped to the same
	 * ServiceID/environment the current notification was verified against (a
	 * ClientHash string is not guaranteed unique across ServiceIDs/environments).
	 * An in-flight activation CLAIM (not yet released or
	 * tombstoned) is deliberately NOT reported as tombstoned here — it is a
	 * temporary, different state, checked directly by try_claim_for_activation().
	 *
	 * @param string $client_hash ClientHash to check.
	 * @param string $service_id  ServiceID the current notification was verified against.
	 * @param string $environment 'test' or 'production' matching that ServiceID.
	 */
	public function is_client_hash_tombstoned( string $client_hash, string $service_id, string $environment ): bool {
		return self::CH_STATE_TOMBSTONED === $this->read_raw_option( $this->client_hash_lock_option_name( $client_hash, $service_id, $environment ) );
	}

	/**
	 * WP option name for a ClientHash's coordination/lock row, scoped to
	 * ServiceID/environment.
	 *
	 * @param string $client_hash ClientHash to build the option name for.
	 * @param string $service_id  ServiceID scoping this lock.
	 * @param string $environment 'test' or 'production' scoping this lock.
	 */
	private function client_hash_lock_option_name( string $client_hash, string $service_id, string $environment ): string {
		return self::CLIENT_HASH_LOCK_OPTION_PREFIX . md5( $client_hash . '|' . $service_id . '|' . $environment );
	}

	/**
	 * Insert a lock row. Succeeds (returns true) only if no row with this
	 * `option_name` exists yet — `wp_options.option_name` is UNIQUE, so a
	 * concurrent second INSERT for the same key fails at the database level. This,
	 * not any PHP-level check, is the real mutual-exclusion guarantee (same
	 * primitive as Attempt_State_Store::insert_lock_row()). The duplicate-key
	 * failure is an expected, routine outcome of a race, not an error condition, so
	 * DB error reporting is suppressed around it to avoid spamming logs for normal
	 * contention.
	 *
	 * @param string $option_name Option name to insert.
	 * @param string $value       Value to store.
	 */
	private function insert_lock_row( string $option_name, string $value ): bool {
		$previous = $this->db->suppress_errors( true );

		$inserted = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				$value
			)
		);

		$this->db->suppress_errors( $previous );

		return false !== $inserted;
	}

	/**
	 * Delete-if-matches: removes a lock row only if its current `option_value`
	 * still equals `$expected` verbatim.
	 *
	 * @param string $option_name Option to delete.
	 * @param string $expected    The exact `option_value` the row must still hold.
	 */
	private function delete_row_if_matches_raw( string $option_name, string $expected ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s",
				$option_name,
				$expected
			)
		);

		return 1 === $affected;
	}

	/**
	 * Single-statement compare-and-swap: updates `option_value` to `$new_value` only
	 * if it currently still equals `$current` verbatim.
	 *
	 * @param string $option_name Option to update.
	 * @param string $current     Value the row must still hold for the update to apply.
	 * @param string $new_value   Value to write.
	 */
	private function cas_update_raw( string $option_name, string $current, string $new_value ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$option_name,
				$current
			)
		);

		return 1 === $affected;
	}

	/**
	 * Raw, uncached read of a single option's value directly from the database —
	 * deliberately bypasses `get_option()`/the options object cache entirely.
	 *
	 * @param string $option_name Option to read.
	 */
	private function read_raw_option( string $option_name ): ?string {
		$value = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $option_name )
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Never write a full ClientHash to logs.
	 *
	 * @param string $client_hash Value to mask.
	 */
	public function mask_client_hash( string $client_hash ): string {
		if ( strlen( $client_hash ) <= 4 ) {
			return '****';
		}

		return '****' . substr( $client_hash, -4 );
	}
}
