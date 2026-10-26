<?php
/**
 * Compatibility fixes for the Flexible Subscriptions plugin.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Integration\Flexible_Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Handles compatibility with the Flexible Subscriptions plugin (WP Desk).
 *
 * Its bundled marketing library enqueues a global flexbox grid stylesheet on every
 * admin screen (also duplicated inside support-boxes.css). The grid overrides generic
 * .row, .col-* and .container-fluid classes used in the remote Autopay banner and VAS
 * markup, breaking the layout of the Autopay settings screen.
 */
final class Flexible_Subscriptions_Integration {

	/**
	 * Hook priority used to dequeue conflicting styles.
	 *
	 * Must be higher than 299, the priority at which the marketing library enqueues its styles.
	 *
	 * @var int
	 */
	private const DEQUEUE_PRIORITY = 1000;

	/**
	 * Style handles enqueued by the marketing library that conflict with the Autopay settings screen.
	 *
	 * @var string[]
	 */
	private const CONFLICTING_STYLE_HANDLES = [
		'flexbox-grid',
		'support-boxes',
	];

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'dequeue_conflicting_styles' ], self::DEQUEUE_PRIORITY );
	}

	/**
	 * Dequeues conflicting styles on the Autopay settings screen.
	 *
	 * Does nothing when the styles are not enqueued, e.g. when Flexible Subscriptions is inactive.
	 *
	 * @return void
	 */
	public function dequeue_conflicting_styles(): void {
		if ( ! $this->is_autopay_settings_screen() ) {
			return;
		}

		foreach ( self::CONFLICTING_STYLE_HANDLES as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Checks whether the current admin screen is the Autopay gateway settings screen.
	 *
	 * Covers all Autopay settings subtabs, including VAS.
	 *
	 * @return bool True on the Autopay settings screen, false otherwise.
	 */
	private function is_autopay_settings_screen(): bool {
		$current_screen = get_current_screen();

		if ( ! $current_screen instanceof \WP_Screen || 'woocommerce_page_wc-settings' !== $current_screen->id ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter used only to detect the current screen; no state is changed.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter used only to detect the current screen; no state is changed.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		return 'checkout' === $tab && 'bluemedia' === $section;
	}
}
