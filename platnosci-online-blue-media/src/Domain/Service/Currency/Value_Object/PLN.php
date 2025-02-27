<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class PLN implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_PLN;
	}

	public function get_name(): string {
		return __( 'Polish z&#x142;oty', 'bm-woocommerce' );
	}

	public function get_symbol(): string {
		return '&#122;&#322;';
	}

	public function get_element_id(): string {
		return 'pln';
	}
}
