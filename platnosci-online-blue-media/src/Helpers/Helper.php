<?php

namespace Ilabs\BM_Woocommerce\Helpers;

class Helper {

	const AUTOPAY_OPTIONS_ID = 'woocommerce_bluemedia_settings';

	public static function is_string_url( string $string ): bool {
		return false != filter_var( $string, FILTER_VALIDATE_URL );
	}

	public static function format_gateway_url( string $url ): string {
		return rtrim( $url, "/" ) . '/';
	}

	public static function get_gateway_options() {
		return get_option( self::AUTOPAY_OPTIONS_ID );
	}

	public static function get_gateway_option( string $id ) {
		$opts = self::get_gateway_options();
		if ( isset( $opts[ $id ] ) ) {
			return $opts[ $id ];
		}

		return null;
	}

	public static function update_gateway_option( string $key, $value ) {
		$opts = self::get_gateway_options();
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts[ $key ] = $value;

		update_option( self::AUTOPAY_OPTIONS_ID, $opts );
	}
}
