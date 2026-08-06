<?php

namespace Ilabs\BM_Woocommerce\Integration\Woocommerce_Blocks;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;
use Ilabs\BM_Woocommerce\Controller\Payment_Status_Controller;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response_Factory;
use Ilabs\BM_Woocommerce\Domain\Service\Gateway_List\Gateway_List_Mapper_Block_Checkout;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Helpers\Autopay_Urls;

/**
 * WooCommerce Blocks integration for the Autopay (Blue Media) gateway.
 *
 * @since 1.0.3
 */
final class WC_Gateway_Autopay_Blocks_Support extends
	AbstractPaymentMethodType {

	/**
	 *
	 * @var Blue_Media_Gateway|null
	 */
	private $gateway;

	/**
	 * @var string
	 */
	protected $name = 'bluemedia';

	/**
	 *
	 * @var array<string, mixed>
	 */
	protected $settings = [];

	public function initialize() {
		$this->maybe_bootstrap_gateway();
	}

	/**
	 * Resolve the WC gateway instance when Blocks bootstrap order differs from {@see initialize()}.
	 */
	private function maybe_bootstrap_gateway(): void {
		if ( $this->gateway ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		if ( ! isset( $gateways[ $this->name ] ) ) {
			return;
		}

		$this->gateway = $gateways[ $this->name ];
	}

	/**
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! $this->gateway ) {
			return false;
		}

		return $this->gateway->is_available();
	}

	/**
	 * Registers `autopay-payments-blocks` and, when the card channel (1500) can appear on whitelabel checkout,
	 * registers `autopay_card_widget` (same URL as classic) so it loads before the Blocks bundle via script order.
	 *
	 * @return array<int, string>
	 * @throws Exception
	 */
	public function get_payment_method_script_handles() {
		$this->maybe_bootstrap_gateway();

		$script_path         = 'blocks/assets/js/frontend/blocks.js';
		$script_path_css     = 'blocks/assets/js/frontend/blocks-styles.css';
		$script_asset_path   = blue_media()->get_plugin_dir() . '/blocks/assets/js/frontend/blocks.asset.php';
		$script_asset        = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: [
				'dependencies' => [],
				'version'      => '1.2.0',
			];
		$script_url          = blue_media()->get_plugin_url() . $script_path;
		$script_url_css      = blue_media()->get_plugin_url() . $script_path_css;
		$script_dependencies = $script_asset['dependencies'];
		$offer_gpay          = $this->should_offer_google_pay_for_blocks();

		if ( $offer_gpay ) {
			wp_register_script(
				'autopay-google-pay',
				'https://pay.google.com/gp/p/js/pay.js',
				[],
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Google Pay CDN script; version is managed by Google and must not be pinned.
				true,
			);

			wp_register_script(
				'autopay-google-pay-atp',
				blue_media()->get_plugin_js_url() . '/google-pay-atp.js',
				[ 'autopay-google-pay' ],
				blue_media()->get_plugin_version(),
				true,
			);

			$script_dependencies[] = 'autopay-google-pay-atp';
		}

		if ( $this->is_autopay_card_widget_needed_for_blocks_checkout() ) {
			$cards_domain = Autopay_Urls::get_cards_domain(
				$this->gateway->resolve_is_test_mode()
			);
			wp_register_script(
				'autopay_card_widget',
				$cards_domain . '/widget-new/widget-communication.min.js',
				[ 'jquery' ],
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Autopay card widget CDN script; version is managed by the Autopay payment infrastructure.
				true
			);
			$script_dependencies[] = 'autopay_card_widget';
		}

		wp_register_script(
			'autopay-payments-blocks',
			$script_url,
			$script_dependencies,
			$script_asset['version'],
			true,
		);

		wp_enqueue_style(
			'autopay-payments-blocks-css',
			$script_url_css,
			[],
			$script_asset['version'],
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			$result = wp_set_script_translations( 'autopay-payments-blocks',
				blue_media()->get_text_domain(),
				blue_media()->get_plugin_dir() . blue_media()->get_from_config( 'lang_dir' ) );
		}

		return [ 'autopay-payments-blocks' ];
	}

	/**
	 * Build frontend config payload for the Checkout Block payment method.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$this->maybe_bootstrap_gateway();

		if ( ! $this->gateway instanceof Blue_Media_Gateway ) {
			return $this->get_payment_method_data_when_gateway_unavailable();
		}

		$is_whitelabel = $this->gateway->is_whitelabel_mode_enabled();

		if ( $is_whitelabel ) {
			try {
				$gateway_list_data = blue_media()
					->get_blue_media_gateway()
					->gateway_list( true );
			} catch ( Exception $exception ) {
				$gateway_list_data = [];
			}

			$offer_gpay     = $this->should_offer_google_pay_for_blocks();
			$gpay_form_data = [];
			if ( $offer_gpay ) {
				try {
					$gpay_form_data = blue_media()
						->get_blue_media_gateway()
						->configure_google_pay();
				} catch ( Exception $exception ) {
					$gpay_form_data = null;
				}
			}

			$channels                   = ( new Gateway_List_Response_Factory() )->create( $gateway_list_data );
			$channels_mapped_for_blocks = ( new Gateway_List_Mapper_Block_Checkout(
				$channels,
				is_array( $gpay_form_data ) ? $gpay_form_data : [],
				$offer_gpay
			) )->map_for_blocks();
		} else {
			$channels_mapped_for_blocks = [];
		}

		$card_widget_config = $this->build_card_widget_config_payload();

		return [
			'title'                    => $this->gateway->get_title(),
			'description'              => $this->gateway->get_description(),
			'icon_src'                 => $this->gateway->get_checkout_logo_banner_url(),
			'whitelabel'               => $is_whitelabel,
			'offer_google_pay_on_checkout' => $this->should_offer_google_pay_for_blocks(),
			'gpay_type'                => blue_media()
				->get_blue_media_gateway()
				->get_option(
					Settings_Manager::get_currency_option_key( 'gpay_type', get_woocommerce_currency() ),
					'with_redirect'
				),
			'place_order_button_label' => __( 'Pay with Autopay',
				'platnosci-online-blue-media' ),
			'supports'                 => array_filter( $this->gateway->supports,
				[ $this->gateway, 'supports' ] ),
			'channels'                 => $channels_mapped_for_blocks,
			'messages'                 => $this->get_payment_method_messages(),
			'adminAjaxUrl'             => esc_url( admin_url( 'admin-ajax.php' ) ),
			'nonce'                    => wp_create_nonce( Payment_Status_Controller::NONCE_ACTION ),
			'environment'              => $this->gateway->resolve_is_test_mode() ? 'sandbox' : 'production',
			'shopBaseCountryCode'      => WC()->countries->get_base_country(),
			'card_widget_config'       => $card_widget_config,
		];
	}

	/**
	 * Same keys as {@see get_payment_method_data()} when the gateway instance is missing
	 * (e.g. race during bootstrap) so Blocks JS never receives a partial shape.
	 *
	 * Includes {@see get_payment_method_data()} `card_widget_config` as null.
	 */
	private function get_payment_method_data_when_gateway_unavailable(): array {
		$wc = WC();

		return [
			'title'                        => '',
			'description'                  => '',
			'icon_src'                     => blue_media()->get_plugin_images_url() . '/logo-autopay-banner.svg',
			'whitelabel'                   => false,
			'offer_google_pay_on_checkout' => false,
			'place_order_button_label'     => __( 'Pay with Autopay',
				'platnosci-online-blue-media' ),
			'supports'                     => [],
			'channels'                     => [],
			'messages'                     => $this->get_payment_method_messages(),
			'adminAjaxUrl'                 => esc_url( admin_url( 'admin-ajax.php' ) ),
			'nonce'                        => wp_create_nonce( Payment_Status_Controller::NONCE_ACTION ),
			'environment'                  => 'production',
			'shopBaseCountryCode'          => ( $wc && $wc->countries ) ? $wc->countries->get_base_country() : '',
			'card_widget_config'           => null,
		];
	}

	/**
	 * Checkout Block messages (BLIK, GPay, card widget, etc.).
	 *
	 * Translations are resolved server-side via {@see __()} and the loaded `.mo` file,
	 * then exposed to React through `MessagesService` (no JS-side `wp_set_script_translations` needed).
	 */
	private function get_payment_method_messages(): array {
		return [
			'payment_failed'                          => __( 'Payment failed',
				'platnosci-online-blue-media' ),
			'no_payment_channel_selected'             => __( 'No payment channel selected.',
				'platnosci-online-blue-media' ),
			'enter_the_blik_code'                     => __( 'Enter the BLIK code.',
				'platnosci-online-blue-media' ),
			'the_code_has_6_digits_note'              => __( "You'll find it in your banking app.",
				'platnosci-online-blue-media' ),
			'code_is_invalid_code_should_be_6_digits' => __( 'The code you provided is invalid. Code should be 6 digits.',
				'platnosci-online-blue-media' ),
			'accept_terms'                            => __( 'Please read and accept the',
				'platnosci-online-blue-media' ),
			'terms_and_conditions'                    => __( 'Terms & Conditions',
				'platnosci-online-blue-media' ),
			'pay_with_google_pay'                     => __( 'Pay with Google Pay',
				'platnosci-online-blue-media' ),
			'card_widget_wait_message'                => __( 'Enter your card details in the form above to place the order.',
				'platnosci-online-blue-media' ),
			'card_widget_fill_message'                => __( 'Complete the card form before placing the order.',
				'platnosci-online-blue-media' ),
			'card_payment_form_title'                 => __( 'Card payment form',
				'platnosci-online-blue-media' ),
		];
	}

	private function should_offer_google_pay_for_blocks(): bool {
		if ( ! $this->gateway->should_offer_google_pay_on_checkout() ) {
			return false;
		}

		return $this->is_terms_checkbox_enabled_in_checkout_block();
	}

	private function is_terms_checkbox_enabled_in_checkout_block(): bool {
		if ( ! function_exists( 'has_block' ) || ! function_exists( 'parse_blocks' ) ) {
			return true;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		if ( ! has_block( 'woocommerce/checkout', $post ) ) {
			return true;
		}

		$terms_block = $this->find_checkout_terms_block( parse_blocks( (string) $post->post_content ) );
		if ( null === $terms_block ) {
			return false;
		}

		return ! empty( $terms_block['attrs']['checkbox'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_checkout_terms_block( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && 'woocommerce/checkout-terms-block' === $block['blockName'] ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_checkout_terms_block( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Whether the card communication script must load before the Blocks bundle (whitelabel + card channel visible).
	 */
	private function is_autopay_card_widget_needed_for_blocks_checkout(): bool {
		if ( ! $this->gateway instanceof Blue_Media_Gateway ) {
			return false;
		}

		if ( ! $this->gateway->is_whitelabel_mode_enabled() || ! $this->gateway->is_available() ) {
			return false;
		}

		// Prefer the same gateway-list mode used to prepare block channels payload.
		try {
			$list_for_blocks = $this->gateway->gateway_list( true );
		} catch ( Exception $exception ) {
			$list_for_blocks = [];
		}

		if ( $this->gateway_list_api_response_contains_card_channel(
			is_array( $list_for_blocks ) ? $list_for_blocks : []
		) ) {
			return true;
		}

		// Fallback for environments where the non-block request includes extra channels.
		try {
			$list_fallback = $this->gateway->gateway_list( false );
		} catch ( Exception $exception ) {
			return false;
		}

		return $this->gateway_list_api_response_contains_card_channel(
			is_array( $list_fallback ) ? $list_fallback : []
		);
	}

	/**
	 * Detect gateway ID 1500 (cards) inside gatewayList/v3 payload.
	 *
	 * @param array<string, mixed> $gateway_list_response Raw API-decoded body.
	 */
	private function gateway_list_api_response_contains_card_channel( array $gateway_list_response ): bool {
		if ( empty( $gateway_list_response['gatewayList'] )
			|| ! is_array( $gateway_list_response['gatewayList'] ) ) {
			return false;
		}

		foreach ( $gateway_list_response['gatewayList'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( Blue_Media_Gateway::CARD_CHANNEL === (int) ( $row['gatewayID'] ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Snapshot for WidgetConnection-style clients (aligned with templates/card_widget.php `card_widget_data`).
	 *
	 * Only exposed when the card channel can appear on block checkout. Amount is the cart total at server render
	 * time; refresh from the cart client-side when totals change before paying.
	 *
	 * @return array<string, mixed>|null
	 */
	private function build_card_widget_config_payload(): ?array {
		if ( ! $this->is_autopay_card_widget_needed_for_blocks_checkout() ) {
			return null;
		}

		if ( ! $this->gateway instanceof Blue_Media_Gateway ) {
			return null;
		}

		$cards_domain = Autopay_Urls::get_cards_domain( $this->gateway->resolve_is_test_mode() );

		return [
			'cardsDomain' => untrailingslashit( $cards_domain ),
			'serviceId'   => $this->gateway->get_service_id(),
			'amount'      => WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0,
			'currency'    => get_woocommerce_currency(),
			'language'    => substr( get_locale(), 0, 2 ),
		];
	}
}
