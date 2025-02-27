<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

use Exception;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Currency;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;
use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;
use Ilabs\BM_Woocommerce\Domain\Service\Legacy\Importer;
use WC_Settings_API;

class WC_Form_Fields_Integration {

	public function define_template_field() {
		add_filter( 'woocommerce_generate_autopay_template_html',
			function ( $a, $key, $data, WC_Settings_API $wc_settings_api ) {
				if ( is_array( $data ) && isset( $data['template'] ) ) {
					$template              = $data['template'];
					$autopay_template_args = $data['template_args'] ?? [];
					$visible               = $data['visible'] ?? true;
					$disabled              = $data['disabled'] ?? false;
					$custom_attributes     = $data['custom_attributes'] ?? [];
					$tr_classes            = $data['tr_classes'] ?? [];


					if ( ! empty( $data['required'] ) && true === $data['required'] ) {
						$custom_attributes         = array_merge( $custom_attributes,
							[ 'required' => '' ] );
						$data['custom_attributes'] = $custom_attributes;
					}
					if ( $disabled ) {
						$tr_classes += [ 'autopay_disabled' ];
					}

					ob_start();

					if ( empty( $template ) ) {
						return '';
					}

					blue_media()->locate_template( "$template.php",
						$autopay_template_args + [
							'key'               => $key,
							'field_key'         => $wc_settings_api->get_field_key( $key ),
							'wc_settings_api'   => $wc_settings_api,
							'data'              => $data,
							'visible'           => $visible,
							'custom_attributes' => $custom_attributes,
							'tr_classes'        => $tr_classes,
							'active_tab'        => ( new Settings_Tabs() )->get_active_tab_id(),
						] );

					return ob_get_clean();
				}

			},
			10,
			4 );
	}

	public function get_fields_by_tab_id( ?string $tab_id ): array {
		switch ( (string) $tab_id ) {
			case Settings_Tabs::ADVERTISING_SERVICES_TAB_ID:
				return $this->get_advertising_fields();
			case Settings_Tabs::AUTHENTICATION_TAB_ID:
				return $this->get_authentication_fields();
			case Settings_Tabs::PAYMENT_SETTINGS_TAB_ID:
				return $this->get_payment_settings_fields();
			case Settings_Tabs::ANALYTICS_TAB_ID:
				return $this->get_analytics_fields();
			case Settings_Tabs::HELP_TAB_ID:
				return $this->get_help_fields();
			case Settings_Tabs::VAS_TAB_ID:
				return $this->get_vas_fields();
			case Settings_Tabs::ADVANCED_SETTINGS_TAB_ID:
				return $this->get_advanced_settings_fields();
		}

		return $this->get_authentication_fields();
	}

	public function get_advertising_fields(): array {

		return [

			'campaign_tracking' => [
				'title'         => __( 'Enable campaign tracking via Autopay and publish the product stream.',
					'bm-woocommerce' ),
				'label'         => __( '',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'default'       => 'no',
				'options'       => [
					'yes' => __( 'Yes', 'bm-woocommerce' ),
					'no'  => __( 'No', 'bm-woocommerce' ),
				],
				'template'      => 'settings_field_extended_radio',
				'template_args' =>
					[
						'bottom_description' => __( 'See how you can run straightforward campaigns with us!',
								'bm-woocommerce' )
						                        . ' ' . '<a target="_blank" href="https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link">'
						                        . ' ' . __( 'Launch in 5 minutes!',
								'bm-woocommerce' ) . '</a>',

					],
				'desc_tip'      => true,
				'bmtab'         => 'authentication',
			],
		];
	}

	public function get_authentication_fields(): array {
		$currency_tabs               = new Currency_Tabs();
		$current_admin_currency_code = $currency_tabs->get_active_tab_currency()
		                                             ->get_code();

		$testmode_opt_value = blue_media()
			->get_blue_media_gateway()
			->get_option( 'testmode', 'no' );

		$whitelabel_opt_value = blue_media()
			->get_blue_media_gateway()
			->get_option( Settings_Manager::get_currency_option_key( 'whitelabel',
				$current_admin_currency_code ),
				'no' );


		$return = [

			'testmode' => [
				'title'         => __( 'Use in sandbox mode',
					'bm-woocommerce' ),
				'label'         => __( 'Enable Sandbox mode',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'default'       => 'no',
				'options'       => [
					'yes' => __( 'Yes', 'bm-woocommerce' ),
					'no'  => __( 'No', 'bm-woocommerce' ),
				],
				'template'      => 'settings_field_extended_radio',
				'template_args' =>
					[
						'status'             => 'yes' === $testmode_opt_value ? __( 'Sandbox active: the payments are simulated. Customers will not be actually charged for any purchases.',
							'bm-woocommerce' ) : false,
						'status_type'        => 'info',
						'bottom_description' => __( 'In order to get access to the sandbox environment contact Autopay using',
								'bm-woocommerce' )
						                        . ' ' . '<a target="_blank" href="https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link">'
						                        . ' ' . __( 'this form',
								'bm-woocommerce' ) . '</a>',

					],
				'help_tip'      => __( 'Payments processed using sandbox environment will not affect store’s settlement with Autopay. Sandbox allows stores to verify integration with Autopay and configuration of this plugin.',
					'bm-woocommerce' ),
				'desc_tip'      => true,
				'bmtab'         => 'authentication',
			],

			'currency_tabs' => [
				'title'         => '',
				'description'   => '',
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_currency_tabs',
				'template_args' =>
					[
						'currency_tabs' => $currency_tabs,
					],
			],

			'whitelabel_title' => [
				'title' => __( 'Select mode of displaying payment methods',
					'bm-woocommerce' ),

				'description' => '',

				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_title',
				'template_args' =>
					[
						'tip_url'       => 'https://developers.autopay.pl/online/wtyczki/woocommerce#ustawienia-p%C5%82atno%C5%9Bci',
						'tip_url_label' => __( 'Learn more',
							'bm-woocommerce' ),
					],
				'bmtab'         => 'authentication',
			],

			Settings_Manager::get_currency_option_key( 'whitelabel',
				$current_admin_currency_code ) => [
				'title'       => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_radio',
				'default'     => 'no',
				'class'       => 'woocommerce_bluemedia_whitelabel',
				'options'     => [
					'no'  => __( 'Redirect to Autopay’s hosted payment page',
						'bm-woocommerce' ),
					'yes' => __( 'Display each payment method separately',
						'bm-woocommerce' ),
				],
				'description' => self::get_whitelabel_description()[ $whitelabel_opt_value ],
				'desc_tip'    => false,
				'bmtab'       => 'authentication',
			],

			'blik_type_title'                  => [],
			Settings_Manager::get_currency_option_key( 'blik_type',
				$current_admin_currency_code ) => [],


			Settings_Manager::get_currency_option_key( 'service_id',
				$current_admin_currency_code ) => [
				'title'         => __( 'Service identifier',
					'bm-woocommerce' ),
				'description'   => __( 'Consists of numbers only. Is unique for each store.',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_extended_text',
				'template_args' =>
					[
						'tip_url'              => 'https://developers.autopay.pl/online/portal-autopay?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#serwis',
						'tip_url_label'        => __( 'where to find?',
							'bm-woocommerce' ),
						'input_field_type_arg' => 'number',
					],
				'required'      => ! ( 'yes' === $testmode_opt_value ),
				'class' => 'woocommerce_bluemedia_service_id_i',
				'tr_classes' => ['woocommerce_bluemedia_service_id-tr'],
			],

			Settings_Manager::get_currency_option_key( 'private_key',
				$current_admin_currency_code ) => [
				'title'         => __( 'Configuration key (hash)',
					'bm-woocommerce' ),
				'description'   => __( 'Key containing numbers and lowercase letters to verify communication. Do not share it with anyone.',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_extended_password',
				'template_args' =>
					[
						'tip_url'       => 'https://developers.autopay.pl/online/portal-autopay?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#serwis',
						'tip_url_label' => __( 'where to find?',
							'bm-woocommerce' ),
					],
				'required'      => ! ( 'yes' === $testmode_opt_value ),
				'class' => 'woocommerce_bluemedia_private_key_i',
				'tr_classes' => ['woocommerce_bluemedia_private_key-tr'],
			],

			Settings_Manager::get_currency_option_key( 'test_service_id',
				$current_admin_currency_code ) => [
				'title'         => __( 'Test service identifier',
					'bm-woocommerce' ),
				'description'   => __( 'Consists of numbers only. Is unique for each store.',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'template'      => 'settings_field_extended_text',
				'template_args' =>
					[
						'tip_url'              => 'https://developers.autopay.pl/online/portal-autopay?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#serwis',
						'tip_url_label'        => __( 'where to find?',
							'bm-woocommerce' ),
						'input_field_type_arg' => 'number',
					],
				'required'      => 'yes' === $testmode_opt_value,
				'class' => 'woocommerce_bluemedia_test_service_id_i',
				'tr_classes' => ['woocommerce_bluemedia_test_service_id-tr'],
			],
			Settings_Manager::get_currency_option_key( 'test_private_key',
				$current_admin_currency_code ) => [
				'title'         => __( 'Test configuration key (hash)',
					'bm-woocommerce' ),
				'description'   => __( 'Key containing numbers and lowercase letters to verify communication. Do not share it with anyone.',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'template'      => 'settings_field_extended_password',
				'template_args' =>
					[
						'tip_url'       => 'https://developers.autopay.pl/online/portal-autopay?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link#serwis',
						'tip_url_label' => __( 'where to find?',
							'bm-woocommerce' ),
					],
				'required'      => 'yes' === $testmode_opt_value,
				'class' => 'woocommerce_bluemedia_test_private_key_i',
				'tr_classes' => ['woocommerce_bluemedia_test_private_key-tr'],
			],

			'remove_currency' => [
				'title'         => '',
				'description'   => '',
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_currency_remove',
				'template_args' =>
					[
						'currency_tabs' => $currency_tabs,
					],
			],
		];

		if ( 'PLN' === $current_admin_currency_code ) {
			$return['blik_type_title'] = [
				'title' => __( 'BLIK payment type',
					'bm-woocommerce' ),

				'description' => '',

				'type'             => 'autopay_template',
				'template'         => 'settings_field_extended_title',
				'template_args'    =>
					[
						'tip_url'       => 'https://developers.autopay.pl/online/wtyczki/woocommerce#ustawienia-p%C5%82atno%C5%9Bci',
						'tip_url_label' => __( 'Learn more',
							'bm-woocommerce' ),
					],
				'template_tr_args' =>
					[
						'test1' => 'val1',
						'test2' => 'val2',
					],

				'bmtab'    => 'payment_settings',
				'disabled' => 'no' === $whitelabel_opt_value,
			];

			$return[ Settings_Manager::get_currency_option_key( 'blik_type',
				$current_admin_currency_code ) ] = [
				'title'       => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'description' => '',
				'options'          => [
					'with_redirect'           => __( 'redirect payer to BLIK’s website',
						'bm-woocommerce' ),
					'blik_0_without_redirect' => __( 'enter BLIK code directly on your store',
						'bm-woocommerce' ),
				],
				'default'          => 'with_redirect',
				'bmtab'            => 'authentication',
				'template_tr_args' =>
					[
						'test1' => 'val1',
						'test2' => 'val2',
					],
				'disabled'         => 'no' === $whitelabel_opt_value,
			];

		} else {
			unset( $return['blik_type_title'] );
			unset( $return[ Settings_Manager::get_currency_option_key( 'blik_type',
					$current_admin_currency_code ) ] );
		}

		return $return;
	}

	private function get_channels_opt_val(): ?array {
		try {
			$channels_opt_value = blue_media()
				->get_blue_media_gateway()
				->gateway_list( true );

		} catch ( Exception $exception ) {
			$channels_opt_value = null;
		}

		return $channels_opt_value;
	}

	public function get_payment_settings_fields(): array {
		$currency_tabs       = new Currency_Tabs();
		$admin_currency_code = $currency_tabs->get_active_tab_currency()
		                                     ->get_code();


		$return = [


			'currency_tabs' => [
				'title'         => '',
				'description'   => '',
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_currency_tabs',
				'template_args' =>
					[
						'currency_tabs' => $currency_tabs,
						'hide_add_tab'  => true,
					],
			],

			'custom_button' => [
				'title'         => __( 'Allowed payment method list',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'description'   => "",
				'desc_tip'      => false,
				'default'       => __( 'Import now',
					'bm-woocommerce' ),
				'template'      => 'settings_field_channels',
				'template_args' => [
					'channels' => function () use ( $admin_currency_code ) {
						try {
							$channels_opt_value = blue_media()
								->get_blue_media_gateway()
								->gateway_list( true, $admin_currency_code );

						} catch ( Exception $exception ) {
							$channels_opt_value = $exception;
						}

						return $channels_opt_value;
					},
				],
			],

			'wc_payment_statuses'                     => [
				'title' => __( 'Payment statuses',
					'bm-woocommerce' ),

				'description' => __( 'Choose how your order statuses will change depending on the status payment has in Autopay.',
					'bm-woocommerce' ),

				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_title',
				'template_args' =>
					[
						'tip_url'       => 'https://developers.autopay.pl/online/wtyczki/woocommerce#ustawienia-p%C5%82atno%C5%9Bci',
						'tip_url_label' => __( 'Learn more',
							'bm-woocommerce' ),
					],
				'bmtab'         => 'payment_settings',
			],
			'wc_payment_status_on_bm_pending'         => [
				'title'       => __( 'Payment started',
					'bm-woocommerce' ),
				'description' => __( '',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-pending',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_success'         => [
				'title'       => __( 'Payment accepted',
					'bm-woocommerce' ),
				'description' => __( '',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-completed',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_success_virtual' => [
				'title'       => __( 'Payment accepted for purchase of ONLY digital products',
					'bm-woocommerce' ),
				'description' => __( '',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-completed',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_failure'         => [
				'title'       => __( 'Payment rejected',
					'bm-woocommerce' ),
				'description' => __( '',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-failed',
				'bmtab'       => 'payment_settings',
			],


		];


		return $return;
	}

	public function get_analytics_fields(): array {
		return [
			'ga4_tracking_id' => [
				'title'         => __( 'Measurement identifier',
					'bm-woocommerce' ),
				'description'   => __( 'Expected format: G-XXXXXXX',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_text',
				'bmtab'         => 'analytics',
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'bm-woocommerce' ),
						'tip_modal_id'  => 'ga4_tracking_id_target',
					],
			],
			'ga4_client_id'   => [
				'title'         => __( 'Stream ID',
					'bm-woocommerce' ),
				'description'   => __( 'The identifier is in numeric format.',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_text',
				'bmtab'         => 'analytics',
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'bm-woocommerce' ),
						'tip_modal_id'  => 'ga4_client_id_target',
					],
			],
			'ga4_api_secret'  => [
				'title'         => __( 'Google Analytics API secret',
					'bm-woocommerce' ),
				'description'   => '',
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_password',
				'bmtab'         => 'analytics',
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'bm-woocommerce' ),
						'tip_modal_id'  => 'ga4_api_secret_target',
					],
			],

			'ga4_purchase_status' => [
				'title'       => __( 'Order status triggering the event ‘Completion of transaction’',
					'bm-woocommerce' ),
				'description' => __( '',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-on-hold',
			],

			'wc_payment_statuses_table' => [
				'title' => __( 'Once connected with this plugin, Google Analytics will start registering the following events:',
					'bm-woocommerce' ),

				'type'          => 'autopay_template',
				'template'      => 'settings_field_ga4_status_table',
				'template_args' =>
					[
					],
				'bmtab'         => 'analytics',
			],

		];
	}

	public function get_help_fields(): array {
		return [
			'help_field' => [
				'title'    => '',
				'label'    => '',
				'type'     => 'autopay_template',
				'template' => 'settings_field_contact',
				'bmtab'    => 'help',
			],
		];

	}


	public function get_advanced_settings_fields(): array {
		$fields = [
			'debug_mode'                              => [
				'title'    => __( 'Debug mode',
					'bm-woocommerce' ),
				'label'    => __( 'Enable debug mode',
					'bm-woocommerce' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'bm-woocommerce' ),
					'yes' => __( 'Yes', 'bm-woocommerce' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'sandbox_for_admins'                      => [
				'title'    => __( 'Sandbox mode for logged-in administrator',
					'bm-woocommerce' ),
				'label'    => __( '',
					'bm-woocommerce' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'bm-woocommerce' ),
					'yes' => __( 'Yes', 'bm-woocommerce' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'autopay_only_for_admins'                 => [
				'title'    => __( 'Show Autopay payment gateway in Checkout only to logged-in administrators',
					'bm-woocommerce' ),
				'label'    => __( '',
					'bm-woocommerce' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'bm-woocommerce' ),
					'yes' => __( 'Yes', 'bm-woocommerce' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'countdown_before_redirection'            => [
				'title'    => __( 'Show countdown screen before redirection to increase compatibility',
					'bm-woocommerce' ),
				'label'    => __( '',
					'bm-woocommerce' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'bm-woocommerce' ),
					'yes' => __( 'Yes', 'bm-woocommerce' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'compatibility_with_live_update_checkout' => [
				'title'    => __( 'Compatibility mode with third-party plugins that reload checkout fragments',
					'bm-woocommerce' ),
				'label'    => __( '',
					'bm-woocommerce' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'bm-woocommerce' ),
					'yes' => __( 'Yes', 'bm-woocommerce' ),
				],
				'bmtab'    => 'advanced_settings',
			],

			'gateway_url' => [
				'title'       => __( 'Alternative transaction start production URL',
					'bm-woocommerce' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_text',
				'bmtab'       => 'help',
			],

			'test_gateway_url' => [
				'title'       => __( 'Alternative transaction start test URL',
					'bm-woocommerce' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_text',
				'bmtab'       => 'help',
			],

			'order_received_url_filter' => [
				'title'         => '',
				'description'   => '',
				'type'          => 'autopay_template',
				'template'      => 'order_received_url_filter_field',
				'bmtab'         => 'help',
				'template_args' =>
					[
						'from_val' => trim( blue_media()
							->get_blue_media_gateway()
							->get_option( 'order_received_url_filter_from',
								'' ) ),
						'to_val'   => trim( blue_media()
							->get_blue_media_gateway()
							->get_option( 'order_received_url_filter_to',
								'' ) ),
					],
			],

			'order_received_url_filter_to' => [
				'title'       => '',
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => null,
			],

			'order_received_url_filter_from' => [
				'title'       => '',
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => null,
			],

			'custom_button' => [
				'title'       => __( 'Import settings from legacy plugin',
					'bm-woocommerce' ),
				'type'        => 'autopay_template',
				'description' => "",
				'desc_tip'    => false,
				'default'     => __( 'Import now',
					'bm-woocommerce' ),
				'template'    => 'settings_field_import',
				'visible'     => $this->import_feature_is_active(),
			],


			'css_editor' => [
				'title'         => __( 'Use own CSS styles',
					'bm-woocommerce' ),
				'type'          => 'autopay_template',
				'description'   => "",
				'desc_tip'      => false,
				'default'       => '',
				'template'      => 'settings_field_css_editor',
				'template_args' => [
					'editor' => new Css_Editor(),
				],
			],
		];

		return $fields;

	}


	public function get_vas_fields(): array {
		return [];
	}

	public static function get_whitelabel_description(): array {
		return [
			'yes' => __( 'In the available payment methods list we will render a separate, dedicated button for each of the payment methods available through Autopay.',
				'bm-woocommerce' ),
			'no'  => __( 'In the available payment methods list we will render one button, upon clicking which payer will be redirected to Autopay’s hosted payment page where all available payment methods will be displayed.',
				'bm-woocommerce' ),
		];
	}

	private function import_feature_is_active(): bool {
		$importer = new Importer();

		return ! empty( $importer->get_legacy_settings() );
	}
}
