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
			$requested_tab = isset( $_GET['bmtab'] ) ? sanitize_text_field( $_GET['bmtab'] ) : self::AUTHENTICATION_TAB_ID;

			if ( $requested_tab === self::VAS_TAB_ID && get_locale() !== 'pl_PL' ) {
				$requested_tab = self::AUTHENTICATION_TAB_ID;
			}

			self::$active_tab_id = $requested_tab;
		}

		return self::$active_tab_id;
	}

	public function get_active_tab_name(): string {
		return $this->get_available_tabs()[ $this->get_active_tab_id() ];
	}

	public function get_available_tabs(): array {
		$tabs = [
			self::AUTHENTICATION_TAB_ID    => __( 'Authentication',
				'bm-woocommerce' ),
			self::ADVERTISING_SERVICES_TAB_ID => __( 'Advertising services',
				'bm-woocommerce' ),
			self::PAYMENT_SETTINGS_TAB_ID  => __( 'Appearance',
				'bm-woocommerce' ),
			self::ANALYTICS_TAB_ID         => __( 'Analytics',
				'bm-woocommerce' ),
			self::VAS_TAB_ID               => __( 'Services for you',
				'bm-woocommerce' ),
			self::ADVANCED_SETTINGS_TAB_ID => __( 'Advanced',
				'bm-woocommerce' ),
			self::HELP_TAB_ID              => __( 'Help',
				'bm-woocommerce' ),
		];

		if ( get_locale() === 'pl_PL' ) {
			$tabs[self::VAS_TAB_ID] = __( 'Services for you', 'bm-woocommerce' );
		}

		return $tabs;
	}

	public function get_available_tabs_ids(): array {
		$tab_ids = [
			self::ADVERTISING_SERVICES_TAB_ID,
			self::AUTHENTICATION_TAB_ID,
			self::PAYMENT_SETTINGS_TAB_ID,
			self::ANALYTICS_TAB_ID,
			self::VAS_TAB_ID,
			self::HELP_TAB_ID,
			self::ADVANCED_SETTINGS_TAB_ID,
		];

		if ( get_locale() === 'pl_PL' ) {
			$tab_ids[] = self::VAS_TAB_ID;
		}

		return $tab_ids;
	}

}
