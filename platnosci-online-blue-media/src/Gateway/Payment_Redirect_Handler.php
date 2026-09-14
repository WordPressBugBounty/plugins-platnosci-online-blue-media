<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention.
/**
 * Payment_Redirect_Handler — handles payment redirect logic.
 *
 * @package Ilabs\BM_Woocommerce\Gateway
 */

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use WC_Order;

/**
 * Handles payment redirect decisions and URL resolution for Autopay.
 *
 * Extracted from Blue_Media_Gateway to isolate redirect-related logic in one place.
 * All calls to Blue_Media_Gateway properties/methods go through the $gateway reference.
 */
class Payment_Redirect_Handler {

	/**
	 * The main Autopay gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway The main Autopay gateway instance.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Payment redirect loop protection — checks whether redirecting to the gateway is allowed.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return bool
	 *
	 * @desc payment redirect loop protection
	 */
	public function can_redirect_to_payment_gateway( int $order_id ): bool {
		$return   = true;
		$wc_order = wc_get_order( $order_id );

		if ( ! $wc_order ) {
			return false;
		}

		$_3ds_redirect_url = $wc_order->get_meta( 'bm_3ds_redirect_url' );

		if ( ! empty( $_3ds_redirect_url ) ) {
			return false;
		}

		$status   = $wc_order->get_meta( 'bm_order_payment_params' );
		$returned = (string) $wc_order->get_meta( 'autopay_returned_from_payment' );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] [status_present = %s] [returned = %s] [autopay_express_payment: %s] [Order ID: %s]',
				empty( $status ) ? 'no' : 'yes',
				$returned,
				isset( $_GET['autopay_express_payment'] ) ? sanitize_key( wp_unslash( $_GET['autopay_express_payment'] ) ) : 'not_set', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect param
				$order_id ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
		if ( '1' === $returned || ! isset( $_GET['autopay_express_payment'] ) || empty( $status ) ) {
			$return = false;
		}

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] $return = %s',
				$return ? 'true' : 'false' ),
		);

		$return_filtered = apply_filters( 'autopay_filter_can_redirect_to_payment_gateway',
			$return );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] $return_filtered = %s',
				$return_filtered ? 'true' : 'false' ),
		);

		return $return_filtered;
	}

	/**
	 * Allow only HTTPS redirects to the same host as the configured Autopay gateway
	 * (mitigates open redirect if meta or XML were tampered with).
	 *
	 * @param string $url Absolute URL from continue-transaction or stored order meta.
	 *
	 * @return bool
	 */
	public function is_trusted_autopay_redirect_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}

		$target = wp_parse_url( $url );
		if ( ! is_array( $target ) || empty( $target['host'] ) ) {
			return false;
		}

		$scheme = isset( $target['scheme'] ) ? strtolower( (string) $target['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return false;
		}

		$base = wp_parse_url( rtrim( $this->gateway->get_gateway_url(), '/' ) . '/' );
		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return false;
		}

		return strtolower( (string) $target['host'] ) === strtolower( (string) $base['host'] );
	}

	/**
	 * Redirect to 3DS URL stored in order meta, with open-redirect protection.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return void
	 */
	public function redirect_to_3ds( int $order_id ): void {
		$wc_order = wc_get_order( $order_id );

		if ( ! $wc_order ) {
			return;
		}

		$_3ds_redirect_url = $wc_order->get_meta( 'bm_3ds_redirect_url' );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[_3ds_redirect_url: %s]',
				$_3ds_redirect_url ),
		);

		if ( ! empty( $_3ds_redirect_url ) ) {
			$_3ds_redirect_url = (string) $_3ds_redirect_url;
			if ( ! $this->is_trusted_autopay_redirect_url( $_3ds_redirect_url ) ) {
				blue_media()->get_woocommerce_logger()->log_error(
					sprintf(
						'[redirect_to_3ds] blocked untrusted redirect host for order_id=%d',
						$order_id
					)
				);
				$wc_order->delete_meta_data( 'bm_3ds_redirect_url' );
				$wc_order->save_meta_data();
				wp_safe_redirect( $wc_order->get_checkout_payment_url( true ) );
				exit;
			}

			$wc_order->delete_meta_data( 'bm_3ds_redirect_url' );
			$wc_order->update_meta_data( 'bm_transaction_init_params',
				[ '3ds' ] );

			$wc_order->save_meta_data();

			add_filter( 'allowed_redirect_hosts', function ( array $hosts ) {
				$parsed = wp_parse_url( $this->gateway->get_gateway_url() );
				if ( ! empty( $parsed['host'] ) ) {
					$hosts[] = strtolower( (string) $parsed['host'] );
				}

				return $hosts;
			} );
			wp_safe_redirect( $_3ds_redirect_url );
			exit;
		}
	}

	/**
	 * Resolve the order-received URL, applying any configured find/replace filter.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return string
	 */
	public function resolve_return_url( WC_Order $order ): string {
		$order_received_url_filter_from = trim( $this->gateway->get_option( 'order_received_url_filter_from',
			'' ) );
		$order_received_url_filter_to   = trim( $this->gateway->get_option( 'order_received_url_filter_to',
			'' ) );
		$return_url                     = $this->gateway->get_return_url( $order );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[resolve_return_url] [original return URL: %s]',
				$return_url,
			) );

		if ( '' !== $order_received_url_filter_from ) {
			$return = str_replace( $order_received_url_filter_from,
				$order_received_url_filter_to,
				$return_url );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[resolve_return_url] [order_received_url_filter_from: %s] [order_received_url_filter_to: %s] [result: %s]',
					$order_received_url_filter_from,
					$order_received_url_filter_to,
					$return,
				) );

			return $return;
		} else {
			return $return_url;
		}
	}

	/**
	 * Schedule a WP-Cron event to cancel the unpaid order after woocommerce_hold_stock_minutes.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return void
	 */
	public function schedule_remove_unpaid_orders( int $order_id ): void {
		$woocommerce_hold_stock_minutes     = (int) get_option( 'woocommerce_hold_stock_minutes' );
		$woocommerce_hold_stock_minutes_old = $woocommerce_hold_stock_minutes;

		if ( $woocommerce_hold_stock_minutes > 0 ) {
			$woocommerce_hold_stock_minutes *= 60;
			blue_media()
				->get_woocommerce_logger( 'bm_woocommerce_schedule_remove_unpaid_orders' )
				->log_debug( sprintf( '[webhook] [%s]',
					wp_json_encode( [
						'order_id' => $order_id,
						'old woocommerce_hold_stock_minutes: ' => $woocommerce_hold_stock_minutes_old,
						'new woocommerce_hold_stock_minutes: ' => $woocommerce_hold_stock_minutes,
					] ),
				) );

			if ( ! wp_next_scheduled( 'bm_cancel_failed_pending_order_after_one_hour',
				[ $order_id ] ) ) {
				wp_schedule_single_event( time() + $woocommerce_hold_stock_minutes,
					'bm_cancel_failed_pending_order_after_one_hour',
					[ $order_id ] );
			}
		}
	}

	/**
	 * Update a WooCommerce order status and log the result.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $new_status Target status (without wc- prefix).
	 * @param string   $note       Optional order note.
	 *
	 * @return bool
	 */
	public function update_order_status(
		WC_Order $order,
		string $new_status,
		string $note = ''
	): bool {
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[update status to: %s] [Order id: %s]',
				$new_status,
				$order->get_id(),
			) );

		$result = $order->update_status( $new_status, $note );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[update status result: %s] [Order id: %s] [Status: %s]',
				$result ? 'true' : 'false',
				$order->get_id(),
				$new_status,
			) );

		return $result;
	}
}
