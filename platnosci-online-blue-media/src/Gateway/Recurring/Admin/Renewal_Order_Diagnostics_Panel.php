<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Renewal_Order_Diagnostics_Panel — basic recurring diagnostics on the
 * renewal order edit screen.
 *
 * Reuses the standard, HPOS-safe `woocommerce_admin_order_data_after_billing_address`
 * hook (fires with the order object regardless of storage backend). No AJAX, no
 * unblock/force-resolve tooling.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring\Admin;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Recurring\Attempt_State_Store;
use WC_Order;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;

/**
 * Renders a read-only "Autopay recurring" section on the renewal order edit screen.
 */
final class Renewal_Order_Diagnostics_Panel {

	/**
	 * Registers the render callback.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render' ] );
	}

	/**
	 * Renders the diagnostics section.
	 *
	 * @param WC_Order $order Order being edited.
	 */
	public function render( $order ): void {
		if ( ! $order instanceof WC_Order || ! class_exists( Renewal::class ) || ! Renewal::is_renewal_order( $order ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Autopay recurring', 'platnosci-online-blue-media' ) . '</h3>';

		$attempt = ( new Attempt_State_Store() )->get_current_attempt( $order->get_id() );

		if ( null !== $attempt ) {
			echo '<p>' . esc_html__( 'Current attempt', 'platnosci-online-blue-media' ) . ': '
				. esc_html(
					sprintf(
						'seq=%d state=%s source=%s',
						(int) ( $attempt['seq'] ?? 0 ),
						(string) ( $attempt['state'] ?? '' ),
						(string) ( $attempt['context']['source'] ?? '' )
					)
				)
				. '</p>';

			if ( Attempt_State_Store::STATE_UNKNOWN === (string) ( $attempt['state'] ?? '' ) ) {
				echo '<p style="color:#b32d2e;">'
					. esc_html(
						sprintf(
							/* translators: %s: machine-readable reason the outcome could not be determined. */
							__( 'Blocked — requires manual review (reason: %s).', 'platnosci-online-blue-media' ),
							(string) ( $attempt['reason'] ?? '' )
						)
					)
					. '</p>';
			} elseif ( in_array( (string) ( $attempt['state'] ?? '' ), [ Attempt_State_Store::STATE_STARTING, Attempt_State_Store::STATE_SENT ], true ) ) {
				echo '<p>' . esc_html__( 'Blocked — an attempt is currently in progress.', 'platnosci-online-blue-media' ) . '</p>';
			}

			if ( '' !== (string) ( $attempt['remote_id'] ?? '' ) ) {
				echo '<p>' . esc_html( sprintf( 'remote_id=%s', (string) $attempt['remote_id'] ) ) . '</p>';
			}

			return;
		}

		$snapshot_raw = $order->get_meta( '_autopay_last_attempt_snapshot' );
		$snapshot     = is_string( $snapshot_raw ) && '' !== $snapshot_raw ? json_decode( $snapshot_raw, true ) : null;

		if ( is_array( $snapshot ) ) {
			echo '<p>' . esc_html__( 'Last attempt (diagnostic only, not authoritative)', 'platnosci-online-blue-media' ) . ': '
				. esc_html(
					sprintf(
						'seq=%d state=%s source=%s',
						(int) ( $snapshot['seq'] ?? 0 ),
						(string) ( $snapshot['state'] ?? '' ),
						(string) ( $snapshot['source'] ?? '' )
					)
				)
				. '</p>';

			return;
		}

		echo '<p>' . esc_html__( 'No recurring attempt recorded for this order.', 'platnosci-online-blue-media' ) . '</p>';
	}
}
