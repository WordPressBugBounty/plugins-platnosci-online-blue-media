<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Utilities\Test_Connection\Async_Request as Connection_Testing_Controller;
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
							'autopay_data'      => $data,
							'visible'           => $visible,
							'custom_attributes' => $custom_attributes,
							'autopay_tr_classes' => $tr_classes,
							'active_tab'        => ( new Settings_Tabs() )->get_active_tab_id(),
						] );


					$return = ob_get_clean();

					if ( isset( $autopay_template_args['on_hook'] ) ) {
						add_action(
							$autopay_template_args['on_hook'],
							static function () use ( $return, $key ) {
								printf(
									'<table class="%s">%s</table>',
									esc_attr( 'table-' . $key ),
									$return // phpcs:ignore WordPress.Security.EscapeOutput -- $return is WC template output from ob_get_clean(), esc_html would destroy markup
								);
							},
							10
						);

						return '';
					}

					return $return;

				}

				return '';
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
					'platnosci-online-blue-media' ),
				'label'         => '',
				'type'          => 'autopay_template',
				'default'       => 'no',
				'options'       => [
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
				],
				'template'      => 'settings_field_extended_radio',
				'template_args' =>
					[
						'bottom_description' => __( 'See how you can run straightforward campaigns with us!',
								'platnosci-online-blue-media' )
						                        . ' ' . '<a target="_blank" href="' . esc_url( __( 'https://autopay.eu/products/data/ads',
								'platnosci-online-blue-media' ) ) . '">'
						                        . ' ' . __( 'Launch in 5 minutes!',
								'platnosci-online-blue-media' ) . '</a>',

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

			'test_connection' => [
				'title'         => __( 'Test Connection',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Expected format: G-XXXXXXX',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_test_connection',
				'bmtab'         => 'authentication',
				'template_args' =>
					[
						'nonce'   => Connection_Testing_Controller::generate_nonce(),
						'on_hook' => 'autopay_settings_after_table_authentication',
					],
			],

			'testmode' => [
				'title'         => __( 'Use in sandbox mode',
					'platnosci-online-blue-media' ),
				'label'         => __( 'Enable Sandbox mode',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'default'       => 'no',
				'options'       => [
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
				],
				'template'      => 'settings_field_extended_radio',
				'template_args' =>
					[
						'status'             => 'yes' === $testmode_opt_value ? __( 'Sandbox active: the payments are simulated. Customers will not be actually charged for any purchases.',
							'platnosci-online-blue-media' ) : false,
						'status_type'        => 'info',
						'bottom_description' => __( 'In order to get access to the sandbox environment contact Autopay using',
								'platnosci-online-blue-media' )
						                        . ' ' . '<a target="_blank" href="' . esc_url( __( 'https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_panel&utm_medium=text_link',
								'platnosci-online-blue-media' ) ) . '">'
						                        . ' ' . __( 'this form',
								'platnosci-online-blue-media' ) . '</a>',
						'on_hook'            => 'autopay_settings_before_table_authentication',

					],
				'help_tip'      => __( 'Payments processed using sandbox environment will not affect store’s settlement with Autopay. Sandbox allows stores to verify integration with Autopay and configuration of this plugin.',
					'platnosci-online-blue-media' ),
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
					'platnosci-online-blue-media' ),

				'description' => '',

				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_title',
				'template_args' =>
					[
						'tip_url'       => __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d97171d07c',
							'platnosci-online-blue-media' ),
						'tip_url_label' => __( 'Learn more',
							'platnosci-online-blue-media' ),
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
						'platnosci-online-blue-media' ),
					'yes' => __( 'Display each payment method separately',
						'platnosci-online-blue-media' ),
				],
				'description' => self::get_whitelabel_description()[ $whitelabel_opt_value ],
				'desc_tip'    => false,
				'bmtab'       => 'authentication',
			],

			// Placeholders to preserve order;
			'blik_type_title'                  => [],
			Settings_Manager::get_currency_option_key( 'blik_type',
				$current_admin_currency_code ) => [],
			Settings_Manager::get_currency_option_key( 'gpay_type',
				$current_admin_currency_code ) => [],

			Settings_Manager::get_currency_option_key( 'service_id',
				$current_admin_currency_code ) => [
				'title'         => __( 'Service identifier',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Consists of numbers only. Is unique for each store.',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_extended_text',
				'template_args' =>
					[
						'tip_url'              => __( 'https://developers.autopay.pl/en/online/portal-autopay-en',
							'platnosci-online-blue-media' ),
						'tip_url_label'        => __( 'where to find?',
							'platnosci-online-blue-media' ),
						'input_field_type_arg' => 'number',
					],
				'required'      => ! ( 'yes' === $testmode_opt_value ),
				'class'         => 'woocommerce_bluemedia_service_id_i',
				'tr_classes'    => [ 'woocommerce_bluemedia_service_id-tr' ],
			],

			Settings_Manager::get_currency_option_key( 'private_key',
				$current_admin_currency_code ) => [
				'title'         => __( 'Configuration key (hash)',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Key containing numbers and lowercase letters to verify communication. Do not share it with anyone.',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'desc_tip'      => false,
				'template'      => 'settings_field_extended_password',
				'template_args' =>
					[
						'tip_url'       => __( 'https://developers.autopay.pl/en/online/portal-autopay-en',
							'platnosci-online-blue-media' ),
						'tip_url_label' => __( 'where to find?',
							'platnosci-online-blue-media' ),
					],
				'required'      => ! ( 'yes' === $testmode_opt_value ),
				'class'         => 'woocommerce_bluemedia_private_key_i',
				'tr_classes'    => [ 'woocommerce_bluemedia_private_key-tr' ],
			],

			Settings_Manager::get_currency_option_key( 'test_service_id',
				$current_admin_currency_code ) => [
				'title'         => __( 'Test service identifier',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Consists of numbers only. Is unique for each store.',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'template'      => 'settings_field_extended_text',
				'template_args' =>
					[
						'tip_url'              => __( 'https://developers.autopay.pl/en/online/portal-autopay-en',
							'platnosci-online-blue-media' ),
						'tip_url_label'        => __( 'where to find?',
							'platnosci-online-blue-media' ),
						'input_field_type_arg' => 'number',
					],
				'required'      => 'yes' === $testmode_opt_value,
				'class'         => 'woocommerce_bluemedia_test_service_id_i',
				'tr_classes'    => [ 'woocommerce_bluemedia_test_service_id-tr' ],
			],
			Settings_Manager::get_currency_option_key( 'test_private_key',
				$current_admin_currency_code ) => [
				'title'         => __( 'Test configuration key (hash)',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Key containing numbers and lowercase letters to verify communication. Do not share it with anyone.',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'bmtab'         => 'authentication',
				'template'      => 'settings_field_extended_password',
				'template_args' =>
					[
						'tip_url'       => __( 'https://developers.autopay.pl/en/online/portal-autopay-en',
							'platnosci-online-blue-media' ),
						'tip_url_label' => __( 'where to find?',
							'platnosci-online-blue-media' ),
					],
				'required'      => 'yes' === $testmode_opt_value,
				'class'         => 'woocommerce_bluemedia_test_private_key_i',
				'tr_classes'    => [ 'woocommerce_bluemedia_test_private_key-tr' ],
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
			// Remove separate title row; we render title within the select field
			unset( $return['blik_type_title'] );
			$return[ Settings_Manager::get_currency_option_key( 'blik_type',
				$current_admin_currency_code ) ] = [
				'title'            => __( 'BLIK payment type',
					'platnosci-online-blue-media' ),
				'type'             => 'autopay_template',
				'template'         => 'settings_field_extended_select',
				'description'      => '',
				'options'          => [
					'with_redirect'           => __( 'redirect payer to BLIK’s website',
						'platnosci-online-blue-media' ),
					'blik_0_without_redirect' => __( 'enter BLIK code directly on your store',
						'platnosci-online-blue-media' ),
				],
				'default'          => 'with_redirect',
				'bmtab'            => 'authentication',
				'template_tr_args' =>
					[
						'test1' => 'val1',
						'test2' => 'val2',
					],
				'template_args'    => [
					'tip_url'       => __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d97171d07c',
						'platnosci-online-blue-media' ),
					'tip_url_label' => __( 'Learn more', 'platnosci-online-blue-media' ),
				],
				'disabled'         => 'no' === $whitelabel_opt_value,
			];

		} else {
			unset( $return['blik_type_title'] );
			unset( $return[ Settings_Manager::get_currency_option_key( 'blik_type',
					$current_admin_currency_code ) ] );
		}

		$return[ Settings_Manager::get_currency_option_key( 'gpay_type',
			$current_admin_currency_code ) ] = [
			'title'         => __( 'Google Pay payment type',
				'platnosci-online-blue-media' ),
			'type'          => 'autopay_template',
			'template'      => 'settings_field_extended_select',
			'description'   => '',
			'options'       => [
				'with_redirect' => __( 'redirect to Google Pay payment',
					'platnosci-online-blue-media' ),
				'without_redirect'   => __( 'pay with Google Pay directly on your store',
					'platnosci-online-blue-media' ),
			],
			'default'       => 'with_redirect',
			'bmtab'         => 'authentication',
			'template_args' => [
				'tip_url'       => __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d97171d07c',
					'platnosci-online-blue-media' ),
				'tip_url_label' => __( 'Learn more', 'platnosci-online-blue-media' ),
			],
			'disabled'      => 'no' === $whitelabel_opt_value,
		];

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

			'payment_method_header' => [
				'title'    => __( 'Header and description at checkout',
					'platnosci-online-blue-media' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_title',
				'bmtab'    => 'payment_settings',
			],

			'payment_method_title' => [
				'title'         => __( 'Payment method title',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_title_with_counter',
				'bmtab'         => 'payment_settings',
				'template_args' => [
					'max_length' => 80,
				],
			],

			'payment_method_description' => [
				'title'         => __( 'Payment method description',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_description_with_counter',
				'bmtab'         => 'payment_settings',
				'template_args' => [
					'max_length'    => 500,
					'tip_url'       => __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d97171d07c',
						'platnosci-online-blue-media' ),
					'tip_url_label' => __( 'where to find?', 'platnosci-online-blue-media' ),
				],
			],

			// Simple divider line under the section
			'payment_method_divider'     => [
				'type'     => 'autopay_template',
				'template' => 'settings_field_section_divider',
				'bmtab'    => 'payment_settings',
			],

			'checkout_logo_variant' => [
				'title'             => __( 'Autopay logo on checkout',
					'platnosci-online-blue-media' ),
				'description'       => __( 'Pick the variant that contrasts with your checkout background: dark logo on light pages, light logo on dark pages.',
					'platnosci-online-blue-media' ),
				'type'              => 'autopay_template',
				'template'          => 'settings_field_extended_select',
				'default'           => 'dark',
				'options'           => [
					'dark'  => __( 'Dark logo (light checkout background)',
						'platnosci-online-blue-media' ),
					'light' => __( 'Light logo (dark checkout background)',
						'platnosci-online-blue-media' ),
				],
				'bmtab'             => 'payment_settings',
				'sanitize_callback' => static function ( $value ) {
					$value = is_scalar( $value ) ? (string) $value : '';

					return in_array( $value, [ 'dark', 'light' ], true ) ? $value : 'dark';
				},
			],

			'checkout_logo_variant_divider' => [
				'type'     => 'autopay_template',
				'template' => 'settings_field_section_divider',
				'bmtab'    => 'payment_settings',
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
						'hide_add_tab'  => true,
					],
			],

			'custom_button' => [
				'title'         => __( 'Allowed payment method list',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'description'   => "",
				'desc_tip'      => false,
				'default'       => __( 'Import now',
					'platnosci-online-blue-media' ),
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
		];


		return $return;
	}

	public function get_analytics_fields(): array {
		return [
			'ga4_tracking_id' => [
				'title'         => __( 'Measurement identifier',
					'platnosci-online-blue-media' ),
				'description'   => __( 'Expected format: G-XXXXXXX',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_text',
				'bmtab'         => 'analytics',
				'tr_classes'    => [ 'autopay-ga4-row' ],
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'platnosci-online-blue-media' ),
						'tip_modal_id'  => 'ga4_tracking_id_target',
					],
			],
			'ga4_client_id'   => [
				'title'         => __( 'Stream ID',
					'platnosci-online-blue-media' ),
				'description'   => __( 'The identifier is in numeric format.',
					'platnosci-online-blue-media' ),
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_text',
				'bmtab'         => 'analytics',
				'tr_classes'    => [ 'autopay-ga4-row' ],
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'platnosci-online-blue-media' ),
						'tip_modal_id'  => 'ga4_client_id_target',
					],
			],
			'ga4_api_secret'  => [
				'title'         => __( 'Google Analytics API secret',
					'platnosci-online-blue-media' ),
				'description'   => '',
				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_password',
				'bmtab'         => 'analytics',
				'tr_classes'    => [ 'autopay-ga4-row' ],
				'template_args' =>
					[
						'tip_url'       => '',
						'tip_url_label' => __( 'where to find?',
							'platnosci-online-blue-media' ),
						'tip_modal_id'  => 'ga4_api_secret_target',
					],
			],

			'ga4_purchase_status' => [
				'title'       => __( 'Order status triggering the event ‘Completion of transaction’',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-on-hold',
				'tr_classes'  => [ 'autopay-ga4-row' ],
			],

			'ga4_purchase_status_based_on_itn' => [
				'title'       => __( 'ITN SUCCESS triggering the event ‘Completion of transaction’ instead of order status',
					'platnosci-online-blue-media' ),
				'label'       => '',
				'description' => __( 'If you select “Yes,” a “purchase” event will be sent immediately once an ITN SUCCESS status message is received for the order.',
					'platnosci-online-blue-media' ),
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_radio',
				'default'     => 'no',
				'options'     => [
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
				],
				'bmtab'       => 'analytics',
				'tr_classes'  => [ 'autopay-ga4-row' ],
			],

			'wc_payment_statuses_table' => [
				'title' => __( 'Once connected with this plugin, Google Analytics will start registering the following events:',
					'platnosci-online-blue-media' ),

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
			'wc_payment_statuses'                     => [
				'title' => __( 'Payment statuses',
					'platnosci-online-blue-media' ),

				'description' => __( 'Choose how your order statuses will change depending on the status payment has in Autopay.',
					'platnosci-online-blue-media' ),

				'type'          => 'autopay_template',
				'template'      => 'settings_field_extended_title',
				'template_args' =>
					[
						'tip_url'       => __( 'https://developers.autopay.pl/en/online/plugins/woocomerce#692d97171d07c',
							'platnosci-online-blue-media' ),
						'tip_url_label' => __( 'Learn more',
							'platnosci-online-blue-media' ),
					],
				'bmtab'         => 'payment_settings',
			],
			'wc_payment_status_on_bm_pending'         => [
				'title'       => __( 'Payment started',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-pending',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_success'         => [
				'title'       => __( 'Payment accepted',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-completed',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_success_virtual' => [
				'title'       => __( 'Payment accepted for purchase of ONLY digital products',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-completed',
				'bmtab'       => 'payment_settings',
			],
			'wc_payment_status_on_bm_failure'         => [
				'title'       => __( 'Payment rejected',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_select',
				'options'     => wc_get_order_statuses(),
				'default'     => 'wc-failed',
				'bmtab'       => 'payment_settings',
			],


			'debug_mode'                              => [
				'title'    => __( 'Debug mode',
					'platnosci-online-blue-media' ),
				'label'    => __( 'Enable debug mode',
					'platnosci-online-blue-media' ),
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'sandbox_for_admins'                      => [
				'title'    => __( 'Sandbox mode for logged-in administrator',
					'platnosci-online-blue-media' ),
				'label'    => '',
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'autopay_only_for_admins'                 => [
				'title'    => __( 'Show Autopay payment gateway in Checkout only to logged-in administrators',
					'platnosci-online-blue-media' ),
				'label'    => '',
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'countdown_before_redirection'            => [
				'title'    => __( 'Show countdown screen before redirection to increase compatibility',
					'platnosci-online-blue-media' ),
				'label'    => '',
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
				],
				'bmtab'    => 'advanced_settings',
			],
			'compatibility_with_live_update_checkout' => [
				'title'    => __( 'Compatibility mode with third-party plugins that reload checkout fragments',
					'platnosci-online-blue-media' ),
				'label'    => '',
				'type'     => 'autopay_template',
				'template' => 'settings_field_extended_radio',
				'default'  => 'no',
				'options'  => [
					'no'  => __( 'No', 'platnosci-online-blue-media' ),
					'yes' => __( 'Yes', 'platnosci-online-blue-media' ),
				],
				'bmtab'    => 'advanced_settings',
			],

			'gateway_url' => [
				'title'       => __( 'Alternative transaction start production URL',
					'platnosci-online-blue-media' ),
				'description' => '',
				'type'        => 'autopay_template',
				'template'    => 'settings_field_extended_text',
				'bmtab'       => 'help',
			],

			'test_gateway_url' => [
				'title'       => __( 'Alternative transaction start test URL',
					'platnosci-online-blue-media' ),
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
						'autopay_from_val' => trim( blue_media()
							->get_blue_media_gateway()
							->get_option( 'order_received_url_filter_from',
								'' ) ),
						'autopay_to_val'   => trim( blue_media()
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
					'platnosci-online-blue-media' ),
				'type'        => 'autopay_template',
				'description' => "",
				'desc_tip'    => false,
				'default'     => __( 'Import now',
					'platnosci-online-blue-media' ),
				'template'    => 'settings_field_import',
				'visible'     => $this->import_feature_is_active(),
			],

			'css_editor' => [
				'title'         => __( 'Use own CSS styles',
					'platnosci-online-blue-media' ),
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
				'platnosci-online-blue-media' ),
			'no'  => __( 'In the available payment methods list we will render one button, upon clicking which payer will be redirected to Autopay’s hosted payment page where all available payment methods will be displayed.',
				'platnosci-online-blue-media' ),
		];
	}

	private function import_feature_is_active(): bool {
		$importer = new Importer();

		return ! empty( $importer->get_legacy_settings() );
	}
}
