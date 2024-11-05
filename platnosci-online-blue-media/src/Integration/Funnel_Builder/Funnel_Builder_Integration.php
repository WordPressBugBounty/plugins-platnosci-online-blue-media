<?php

namespace Ilabs\BM_Woocommerce\Integration\Funnel_Builder;

class Funnel_Builder_Integration {

	public function init() {
		add_action( 'wffn_loaded', [ $this, 'fix_redirect_location' ] );
	}

	public function fix_redirect_location() {
		add_filter( 'wp_redirect', function ( $location, $status ) {
			$request                         = blue_media()->get_request();
			$autopay_express_payment         = $request->get_by_key( 'autopay_express_payment' );
			$autopay_payment_on_account_page = $request->get_by_key( 'autopay_payment_on_account_page' );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[$autopay_express_payment] [%s] ',
					print_r( $autopay_express_payment, true )
				) );

			if ( ! $autopay_express_payment ) {
				if ( ! $autopay_payment_on_account_page ) {
					return $location;
				}
				$param_to_add = 'autopay_payment_on_account_page';
			} else {
				$param_to_add = 'autopay_express_payment';
			}

			$gets = parse_url( $location );
			if ( isset( $gets['query'] ) ) {
				$query_args = [];
				parse_str( (string) $gets['query'], $query_args );
				if ( isset( $query_args['nt'] ) && (string) $query_args['nt'] === '1' ) {
					$location = add_query_arg( [ $param_to_add => '1' ],
						$location );
				}
			}

			return $location;
		}, 10, 2 );
	}
}
