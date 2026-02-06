<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class USD implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_USD;
	}

	public function get_name(): string {
		return __( 'United States (US) dollar', 'bm-woocommerce' );
	}

	public function get_symbol(): string {
		return '&#36;';
	}

	public function get_element_id(): string {
		return 'usd';
	}
}



