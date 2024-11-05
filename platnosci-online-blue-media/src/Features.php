<?php

namespace Ilabs\BM_Woocommerce;

use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Features_Config_Interface;

class Features implements Features_Config_Interface {

	const PROCESS_CARD_PAYMENT = 'process_card_payment';

	const SETTINGS_BANNER = 'settings_banner';

	const VAS_SERVER_SIDE = 'vas_server_side';

	const BANNER_SERVER_SIDE = 'banner_server_side';

	public function get_config(): array {
		return [
			self::PROCESS_CARD_PAYMENT => 0,
			self::SETTINGS_BANNER      => 1,
			self::VAS_SERVER_SIDE      => 1,
			self::BANNER_SERVER_SIDE   => 1,
		];
	}
}
