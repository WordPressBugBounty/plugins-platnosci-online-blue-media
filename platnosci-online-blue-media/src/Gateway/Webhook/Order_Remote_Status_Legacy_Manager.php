<?php

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Service\Versioning\Versioning;
use WC_Order;
use function GuzzleHttp\Psr7\str;

class Order_Remote_Status_Legacy_Manager {

	const AUTOPAY_PLUGIN_VERSION_4_6_4 = '4.6.4';

	private static $debug_id = 'bm_woocommerce_itn';

	private static function get_itn_status_from_order_meta( WC_Order $order
	): ?string {

		$status = (string) $order->get_meta( 'bm_order_itn_status' );

		if ( '' === $status ) {
			$status = (string) get_post_meta( $order->get_id(),
				'bm_order_itn_status',
				true );
		}

		blue_media()->get_woocommerce_logger( self::$debug_id )->log_debug(
			sprintf( '[get_itn_status_from_order_meta] [%s]',
				print_r( [
					'status'   => $status,
					'order_id' => $order->get_id(),
				], true ),
			) );


		if ( in_array( $status, [
			'SUCCESS',
			'PENDING',
			'FAILURE',
		] ) ) {
			return $status;
		}

		return null;
	}

	public static function find_itn_status( int $order_id
	): ?string {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$order_version = Versioning::get_autopay_version_from_order( $order );

		if ( $order_version ) {
			if ( self::is_legacy_version( $order_version ) ) {

				blue_media()
					->get_woocommerce_logger( self::$debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status_Legacy_Manager] [find_itn_status] [order_version found] [is_legacy_version = true]  return: get_itn_status_from_order_meta [order_id: %s]',
							$order_id ) );


				return self::get_itn_status_from_order_meta( $order );
			} else {

				blue_media()
					->get_woocommerce_logger( self::$debug_id )
					->log_debug(
						sprintf( '[Order_Remote_Status_Legacy_Manager] [find_itn_status] [order_version found] [is_legacy_version = false]  return: null [order_id: %s]',
							$order_id ) );

				return null;
			}
		}

		blue_media()
			->get_woocommerce_logger( self::$debug_id )
			->log_debug(
				sprintf( '[Order_Remote_Status_Legacy_Manager] [find_itn_status] [order_version not found] return: get_itn_status_from_order_meta [order_id: %s]',
					$order_id ) );

		return self::get_itn_status_from_order_meta( $order );
	}


	private static function is_legacy_version(
		string $version_from_order
	): bool {
		return version_compare( $version_from_order,
			self::AUTOPAY_PLUGIN_VERSION_4_6_4,
			'<' );

	}
}
