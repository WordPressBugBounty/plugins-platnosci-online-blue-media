<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces;

interface Currency_Interface {

	const CODE_PLN = 'PLN';

	const CODE_EUR = 'EUR';

	const CODE_CZK = 'CZK';

	const CODE_RON = 'RON';

	const CODE_HUF = 'HUF';
	
	const CODE_USD = 'USD';
	
	const CODE_GBP = 'GBP';

	public function get_code(): string;

	public function get_name(): string;

	public function get_symbol(): string;

	public function get_element_id(): string;
}
