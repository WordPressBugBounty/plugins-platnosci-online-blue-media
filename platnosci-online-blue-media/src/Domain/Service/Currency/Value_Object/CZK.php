<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Value_Object;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;

class CZK implements Currency_Interface {

	public function get_code(): string {
		return Currency_Interface::CODE_CZK;
	}

	public function get_name(): string {
		return __( 'Czech koruna', 'platnosci-online-blue-media' );
	}

	public function get_symbol(): string {
		return '&#75;&#269;';
	}

	public function get_element_id(): string {
		return 'czk';
	}
}
