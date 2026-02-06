<?php

namespace Ilabs\BM_Woocommerce\Integration\Woocommerce_Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;
use Ilabs\BM_Woocommerce\Controller\Payment_Status_Controller;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response_Factory;
use Ilabs\BM_Woocommerce\Domain\Service\Gateway_List\Gateway_List_Mapper_Block_Checkout;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;

/**
 *
 * @since 1.0.3
 */
final class WC_Gateway_Autopay_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 *
	 * @var Blue_Media_Gateway
	 */
	private $gateway;

	/**
	 * @var string
	 */
	protected $name = 'bluemedia';

	/**
	 *
	 * @var array
	 */
	protected $settings = [];

	public function initialize() {
		$gateways = WC()->payment_gateways->payment_gateways();

		if ( ! isset( $gateways[ $this->name ] ) ) {
			return;
		}

		$this->gateway = $gateways[ $this->name ];
	}

	/**
	 * @return boolean
	 */
	public function is_active(): bool {
		if ( ! $this->gateway ) {
			return false;
		}

		return $this->gateway->is_available();
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function get_payment_method_script_handles() {
		$script_path       = 'blocks/assets/js/frontend/blocks.js';
		$script_path_css   = 'blocks/assets/js/frontend/blocks-styles.css';
		$script_asset_path = blue_media()->get_plugin_dir() . '/blocks/assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: [
				'dependencies' => [],
				'version'      => '1.2.0',
			];
		$script_url        = blue_media()->get_plugin_url() . $script_path;
		$script_url_css    = blue_media()->get_plugin_url() . $script_path_css;

		wp_register_script(
			'autopay-payments-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_enqueue_style(
			'autopay-payments-blocks-css',
			$script_url_css
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			$result = wp_set_script_translations( 'autopay-payments-blocks',
				blue_media()->get_text_domain(),
				blue_media()->get_plugin_dir() . blue_media()->get_from_config( 'lang_dir' ) );
		}

		return [ 'autopay-payments-blocks' ];
	}

	public function get_payment_method_data(): array {
		$is_whitelabel = $this->gateway->is_whitelabel_mode_enabled();

		if ( $is_whitelabel ) {
			try {
				$gateway_list_data = blue_media()
					->get_blue_media_gateway()
					->gateway_list( true );
			} catch ( Exception $exception ) {
				$gateway_list_data = [];
			}

			$channels                   = ( new Gateway_List_Response_Factory() )->create( $gateway_list_data );
			$channels_mapped_for_blocks = ( new Gateway_List_Mapper_Block_Checkout( $channels ) )->map_for_blocks();
		} else {
			$channels_mapped_for_blocks = [];
		}


		return [
			'title'                    => $this->gateway->get_title(),
			'description'              => $this->gateway->get_method_description(),
			'icon_src'                 => blue_media()->get_plugin_images_url() . "/logo-autopay-banner.svg",
			'whitelabel'               => $is_whitelabel,
			'place_order_button_label' => __( 'Pay with Autopay',
				'bm-woocommerce' ),
			'supports'                 => array_filter( $this->gateway->supports,
				[ $this->gateway, 'supports' ] ),
			'channels'                 => $channels_mapped_for_blocks,
			'messages'                 => [
				'payment_failed'                          => __( 'Payment failed',
					'bm-woocommerce' ),
				'no_payment_channel_selected'             => __( 'No payment channel selected.',
					'bm-woocommerce' ),
				'enter_the_blik_code'                     => __( 'Enter the BLIK code.',
					'bm-woocommerce' ),
				'the_code_has_6_digits_note'              => __( "You'll find it in your banking app.",
					'bm-woocommerce' ),
				'code_is_invalid_code_should_be_6_digits' => __( 'The code you provided is invalid. Code should be 6 digits.',
					'bm-woocommerce' ),

			],
			'adminAjaxUrl'             => esc_url( admin_url( 'admin-ajax.php' ) ),
			'nonce'                    => wp_create_nonce( Payment_Status_Controller::NONCE_ACTION ),
		];
	}
}
