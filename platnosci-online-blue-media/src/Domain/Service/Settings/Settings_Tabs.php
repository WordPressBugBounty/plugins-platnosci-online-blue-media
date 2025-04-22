<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

class Settings_Tabs {

	const AUTHENTICATION_TAB_ID = 'authentication';

	const PAYMENT_SETTINGS_TAB_ID = 'payment_settings';

	const ANALYTICS_TAB_ID = 'analytics';

	const HELP_TAB_ID = 'help';

	const VAS_TAB_ID = 'vas';

	const ADVERTISING_SERVICES_TAB_ID = 'advertising_services';

	const ADVANCED_SETTINGS_TAB_ID = 'advanced_settings';


	private static string $active_tab_id;


	public function get_active_tab_id(): string {
		if ( empty( self::$active_tab_id ) ) {
			self::$active_tab_id = isset( $_GET['bmtab'] ) ? sanitize_text_field( $_GET['bmtab'] ) : self::AUTHENTICATION_TAB_ID;
		}

		return self::$active_tab_id;
	}

	public function get_active_tab_name(): string {
		return $this->get_available_tabs()[ $this->get_active_tab_id() ];
	}

	public function get_available_tabs(): array {
		return [
			self::AUTHENTICATION_TAB_ID    => __( 'Authentication',
				'bm-woocommerce' ),
			/*self::ADVERTISING_SERVICES_TAB_ID => __( 'Advertising services',
				'bm-woocommerce' ),*/
			self::PAYMENT_SETTINGS_TAB_ID  => __( 'Payment settings',
				'bm-woocommerce' ),
			self::ANALYTICS_TAB_ID         => __( 'Analytics',
				'bm-woocommerce' ),
			self::VAS_TAB_ID               => __( 'Services for you',
				'bm-woocommerce' ),
			self::ADVANCED_SETTINGS_TAB_ID => __( 'Advanced settings',
				'bm-woocommerce' ),
			self::HELP_TAB_ID              => __( 'Help',
				'bm-woocommerce' ),
		];
	}

	public function get_available_tabs_ids(): array {
		return [
			//self::ADVERTISING_SERVICES_TAB_ID,
			self::AUTHENTICATION_TAB_ID,
			self::PAYMENT_SETTINGS_TAB_ID,
			self::ANALYTICS_TAB_ID,
			self::VAS_TAB_ID,
			self::HELP_TAB_ID,
			self::ADVANCED_SETTINGS_TAB_ID,
		];
	}

}
