<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Versioning;

use WC_Order;

class Versioning {

	const FIELD_NAME = '_autopay_version';

	public static function update_autopay_version_in_order( WC_Order $order ) {
		$order->update_meta_data( self::FIELD_NAME,
			blue_media()->get_plugin_version() );
	}

	public static function get_autopay_version_from_order( WC_Order $order
	): ?string {
		$result = $order->get_meta( self::FIELD_NAME );

		return is_string( $result ) && '' !== $result ? $result : null;
	}
}
