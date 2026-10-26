<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Subscription_Diagnostics_Metabox — basic recurring diagnostics on the
 * Flexible Subscriptions subscription edit screen.
 *
 * No AJAX, no unblock/force-resolve tooling — read-only, reusing existing
 * Recurring_Subscription_Store accessors only.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring\Admin;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Subscription_Store;
use WP_Post;
use WPDesk\FlexibleSubscriptions\Subscription\Subscription;
use WPDesk\FlexibleSubscriptions\Subscription\SubscriptionFinder;

/**
 * Renders a read-only "Autopay — recurring" side metabox on the subscription
 * edit screen (legacy posts table and HPOS both use `wc_get_page_screen_id()`).
 */
final class Subscription_Diagnostics_Metabox {

	/**
	 * Registers the `add_meta_boxes` callback.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
	}

	/**
	 * Registers the metabox on the FS subscription edit screen.
	 */
	public function add_meta_box(): void {
		if ( ! class_exists( SubscriptionFinder::class ) || ! function_exists( 'wc_get_page_screen_id' ) ) {
			// Flexible Subscriptions not active -- nothing to attach to.
			return;
		}

		add_meta_box(
			'autopay-recurring-diagnostics',
			__( 'Autopay — recurring', 'platnosci-online-blue-media' ),
			[ $this, 'render' ],
			wc_get_page_screen_id( 'fsb-subscription' ),
			'side',
			'default'
		);
	}

	/**
	 * Renders the metabox contents.
	 *
	 * @param WP_Post|Subscription $post_or_subscription WP passes a WP_Post on the
	 *                                                    legacy posts table; FS may
	 *                                                    pass a Subscription on HPOS
	 *                                                    (same parameter shape as
	 *                                                    FS's own metaboxes, e.g.
	 *                                                    ScheduleMetaBox).
	 */
	public function render( $post_or_subscription ): void {
		$subscription = $post_or_subscription instanceof Subscription
			? $post_or_subscription
			: ( $post_or_subscription instanceof WP_Post && class_exists( SubscriptionFinder::class )
				? ( new SubscriptionFinder() )->find( $post_or_subscription->ID )
				: null );

		if ( ! $subscription instanceof Subscription ) {
			echo '<p>' . esc_html__( 'No diagnostic data available.', 'platnosci-online-blue-media' ) . '</p>';

			return;
		}

		$store = new Recurring_Subscription_Store();
		$data  = $store->get_recurring_data_ignoring_status( $subscription );

		if ( null === $data ) {
			echo '<p>' . esc_html__( 'No recurring card instrument was ever linked to this subscription.', 'platnosci-online-blue-media' ) . '</p>';

			return;
		}

		$is_active = null !== $store->get_recurring_data( $subscription );

		echo '<p><strong>' . esc_html__( 'Instrument status', 'platnosci-online-blue-media' ) . ':</strong> '
			. esc_html( $is_active ? __( 'active', 'platnosci-online-blue-media' ) : __( 'inactive', 'platnosci-online-blue-media' ) )
			. '</p>';

		if ( ! $is_active ) {
			$reason = $store->get_deactivation_reason( $subscription );

			if ( '' !== $reason ) {
				echo '<p><strong>' . esc_html__( 'Deactivation reason', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( $reason ) . '</p>';
			}

			echo '<p><strong>' . esc_html__( 'External deactivation confirmed', 'platnosci-online-blue-media' ) . ':</strong> '
				. esc_html( $store->is_external_deactivation_confirmed( $subscription ) ? __( 'yes', 'platnosci-online-blue-media' ) : __( 'no', 'platnosci-online-blue-media' ) )
				. '</p>';
		}

		// Never the full ClientHash -- masked, matching Recurring_Subscription_Store's
		// own logging convention.
		echo '<p><strong>' . esc_html__( 'ClientHash', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( $store->mask_client_hash( $data['client_hash'] ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Channel', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( (string) $data['channel'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Currency', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( $data['currency'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'ServiceID', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( $data['service_id'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Environment', 'platnosci-online-blue-media' ) . ':</strong> ' . esc_html( $data['environment'] ) . '</p>';

		if ( $store->is_fs_sync_pending( $subscription ) ) {
			echo '<p style="color:#b32d2e;">' . esc_html__( 'A local FS pause is not yet confirmed applied — a retry is scheduled.', 'platnosci-online-blue-media' ) . '</p>';
		}
	}
}
