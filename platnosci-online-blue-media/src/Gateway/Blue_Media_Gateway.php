<?php

namespace Ilabs\BM_Woocommerce\Gateway;

defined( 'ABSPATH' ) || exit;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Expandable_Group;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response_Factory;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Group;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\v3\Gateway_List_Response;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;
use Ilabs\BM_Woocommerce\Domain\Service\Legacy\Importer;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Domain\Service\Versioning\Versioning;
use Ilabs\BM_Woocommerce\Domain\Service\Gateway_List\Gateway_List_Mapper_Block_Checkout;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use Ilabs\BM_Woocommerce\Helpers\Helper;
use Ilabs\BM_Woocommerce\Plugin;
use WC_Order;
use WC_Payment_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Hooks\Payment_On_Account_Page;
use Ilabs\BM_Woocommerce\Gateway\Card_Widget\Blue_Media_Hash_Generator;
use Ilabs\BM_Woocommerce\Gateway\Card_Widget\Card_Widget_Payment_Service;
use Ilabs\BM_Woocommerce\Gateway\Card_Widget\Card_Widget_Start_Result;
use Ilabs\BM_Woocommerce\Gateway\Continue_Transaction\Continue_Transaction_Response_Xml_Parser;

class Blue_Media_Gateway extends WC_Payment_Gateway {

	const GATEWAY_PRODUCTION = 'https://pay.autopay.eu/';

	const GATEWAY_SANDBOX = 'https://testpay.autopay.eu/';

	const BLIK_0_CHANNEL = 509;

	const APPLE_PAY_CHANNEL = 1513;

	const GPAY_CHANNEL = 1512;

	const CARD_CHANNEL = 1500;

	/**
	 * Google Pay requires an explicit WooCommerce terms & conditions checkbox on checkout
	 * (same conditions as core {@see wc_terms_and_conditions_checkbox_enabled()}).
	 */
	public function should_offer_google_pay_on_checkout(): bool {
		if ( ! function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) ) {
			return true;
		}

		if ( ! apply_filters( 'woocommerce_checkout_show_terms', true ) ) {
			return false;
		}

		return wc_terms_and_conditions_checkbox_enabled();
	}

	const ITN_SUCCESS_STATUS_ID = 'SUCCESS';

	const ITN_PENDING_STATUS_ID = 'PENDING';

	const ITN_FAILURE_STATUS_ID = 'FAILURE';

	public const SPLIT_GROUP_SLUGS = [
		'wallet',     // Apple Pay / Google Pay (legacy gatewayList groupType).
		'apple_pay',  // Apple Pay (gatewayList groupType APPLE_PAY).
		'google_pay', // Google Pay (gatewayList groupType GOOGLE_PAY).
		'bnpl',       // Kup teraz, zapłać później / PayPo.
		'fr',         // Volkswagen / SGB / Other banks.
	];

	/**
	 * @var string
	 */
	private $gateway_url;

	/**
	 * @var string
	 */
	private $gateway_url_not_modified_by_user;

	/**
	 * @var string
	 */
	private $express_payment_redirect_url;

	/**
	 * @var string
	 */
	private $service_id;

	/**
	 * @var string
	 */
	private $private_key;

	/**
	 * @var bool
	 */
	private $testmode;

	private $payment_on_account_page = false;

	private Settings_Manager $settings_manager;
	private \Ilabs\BM_Woocommerce\Assets\AssetManager $asset_manager;

	/**
	 * @var Gateway_Configuration|null
	 */
	private $gateway_config;

	/**
	 * @var Transaction_Request_Builder|null
	 */
	private $transaction_builder;

	/**
	 * @var Gateway_List_Service|null
	 */
	private $gateway_list_service;

	/**
	 * @var Payment_Channel_Renderer|null
	 */
	private $payment_channel_renderer;

	/**
	 * @var ITN_Webhook_Handler|null
	 */
	private $itn_webhook_handler;

	/**
	 * Handles payment redirect decisions and URL resolution.
	 *
	 * @var Payment_Redirect_Handler
	 */
	protected Payment_Redirect_Handler $payment_redirect_handler;

	/**
	 * Cached Google Pay form data (set externally during payment init).
	 *
	 * @var array|null
	 */
	private ?array $gpay_form_data = null;

	/**
	 *
	 * @throws Exception
	 */
	public function __construct() {
		( new Hooks() )->init();

		blue_media()->set_bluemedia_gateway( $this );

		$this->settings_manager = new Settings_Manager();
		$this->settings_manager->init_once();


		( new Importer() )->handle_import();

		$this->id           = 'bluemedia';
		$this->icon         = blue_media()->get_plugin_images_url() . '/logo-autopay.svg';
		$this->has_fields
		                    = true;
		$this->method_title = __( 'Autopay Instant payment',
			'platnosci-online-blue-media' );
		$this->method_description
		                    = __( 'Instant payment, BLIK, credit card, Google Pay, Apple Pay',
			'platnosci-online-blue-media' );

		$this->supports = [
			'products',
		];
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'payment_method_title', '' );

		$this->description = $this->get_option( 'payment_method_description', '' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->testmode    = $this->resolve_is_test_mode();

		$this->icon = $this->get_checkout_logo_url();

		$this->payment_on_account_page = apply_filters( 'autopay_payment_on_account_page',
			$this->payment_on_account_page );

		if ( $this->testmode && ! defined( 'BLUE_MEDIA_DISABLE_CACHE' ) ) {
			define( 'BLUE_MEDIA_DISABLE_CACHE', 1 );
		}

		$this->setup_variables();

		// Initialize dependencies
		$plugin_base_file    = dirname( __DIR__,
				2 ) . '/bluemedia-woocommerce.php';
		$this->asset_manager = new \Ilabs\BM_Woocommerce\Assets\AssetManager(
			blue_media()->get_plugin_version(),
			$plugin_base_file,
		);

		// Initialize asset loading
		$this->asset_manager->init();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
			[ $this, 'process_admin_options' ] );

		// Clear gateway list cache when language changes
		add_action( 'update_option_WPLANG',
			[ $this, 'clear_gateway_list_cache' ] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
		if ( isset( $_GET['autopay_express_payment'] ) || isset( $_GET['autopay_payment_on_account_page'] ) ) {
			blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_debug(
				sprintf( '[wc_session - constructor] [keys: %s]',
					is_object( WC()->session ) ? implode( ', ', array_keys( (array) WC()->session->get_session_data() ) ) : 'session_unavailable',
				) );

			if ( is_object( WC()->session ) && ! wp_doing_ajax() ) {
				Session_Bridge::restore_session_data();
				if ( ! empty( WC()->session->get( 'bm_order_payment_params' ) ) ) {
					$params
						= WC()->session->get( 'bm_order_payment_params' )['params'];

					$order_id = (int) $params['OrderID'];

					if ( $this->payment_redirect_handler->can_redirect_to_payment_gateway( (int) $params['OrderID'] ) ) {
						WC()->session->set( 'bm_order_payment_params', null );
						Session_Bridge::save();
						$order = wc_get_order( $params['OrderID'] );
						$order->delete_meta_data( 'bm_order_payment_params' );
						$order->save_meta_data();
						if ( 'yes' === $this->get_option( 'countdown_before_redirection' ) ) {
							ob_start();
							wp_head();
							$wp_head_html = ob_get_contents();
							ob_end_clean();
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is WordPress-generated wp_head() HTML, stripped of body tag; no user input involved.
							echo preg_replace( '/<body[^>]*>.*<\/body>/isU',
								'',
								$wp_head_html );
							echo '<body>';
							blue_media()->locate_template( 'redirect_to_payment_overlay.php' );
						}

						if ( is_array( $params ) ) {
							printf( "<form method='post' id='paymentForm' action='%s'>
			 <input type='hidden' name='ServiceID'  value='%s' />
			 <input type='hidden' name='OrderID'  value='%s' />
			 <input type='hidden' name='Amount'  value='%s' />
			 <input type='hidden' name='GatewayID'  value='%s' />
			 <input type='hidden' name='Currency'  value='%s' />
			 <input type='hidden' name='CustomerEmail'  value='%s' />
			 <input type='hidden' name='PlatformName'  value='%s' />
			 <input type='hidden' name='PlatformVersion'  value='%s' />
			 <input type='hidden' name='PlatformPluginVersion'  value='%s' />
			 <input type='hidden' name='Hash'  value='%s' /></form>",
								esc_url( $this->express_payment_redirect_url ),
								esc_attr( $params['ServiceID'] ),
								esc_attr( $params['OrderID'] ),
								esc_attr( $params['Amount'] ),
								esc_attr( ! empty( $params['GatewayID'] ) ? $params['GatewayID'] : '0' ),
								esc_attr( blue_media()->resolve_blue_media_currency_symbol() ),
								esc_attr( $params['CustomerEmail'] ),
								esc_attr( $params['PlatformName'] ),
								esc_attr( $params['PlatformVersion'] ),
								esc_attr( $params['PlatformPluginVersion'] ),
								esc_attr( $params['Hash'] ) );
						}

						if ( 'yes' === $this->get_option( 'countdown_before_redirection' ) ) {
							_wp_footer_scripts();
							echo '</body>';
						} else {
							echo "<script type='text/javascript'>document.getElementById('paymentForm').submit();</script>";
						}

						blue_media()->get_woocommerce_logger()->log_debug(
							sprintf( '[Print payment form and submit by JS] [OrderID: %s] [GatewayID: %s] [url: %s] [is_rest_request: %s]',
								$params['OrderID'] ?? '',
								$params['GatewayID'] ?? '',
								sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
								defined( 'REST_REQUEST' ) ? 'yes' : 'no',
							) );


						$order->update_meta_data( 'bm_transaction_init_params',
							$params );
						$order->save_meta_data();

						blue_media()->update_payment_cache( 'bm_payment_start',
							'1' );

						exit;
					} else {
						WC()->session->set( 'bm_order_payment_params', null );
						Session_Bridge::save();

						blue_media()->get_woocommerce_logger()->log_debug(
							sprintf( '[Print payment form canceled.] [OrderID: %s] [url: %s]',
								$params['OrderID'] ?? '',
								sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
							) );

						$this->payment_redirect_handler->redirect_to_3ds( $order_id );
					}
				} else {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[Print payment form canceled. bm_order_payment_params not found in WC Session] [url: %s]',
							sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
						) );
				}
			} else {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Print payment form canceled. WC Session not exists] [url: %s]',
						sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
					) );
			}
		}
		$this->webhook();
	}

	public function setup_variables( ?Currency_Interface $forced_currency = null
	) {
		if ( $forced_currency ) {
			$currency = $forced_currency->get_code();
		} else {
			$currency = blue_media()->resolve_blue_media_currency_symbol();
		}

		if ( $this->testmode ) {
			$test_gateway_url = $this->get_option( 'test_gateway_url' );
			if ( Helper::is_string_url( $test_gateway_url ) ) {
				$test_gateway_url                   = Helper::format_gateway_url( $test_gateway_url );
				$this->gateway_url                  = $test_gateway_url;
				$this->express_payment_redirect_url = $this->gateway_url;
			} else {
				$this->gateway_url                  = self::GATEWAY_SANDBOX;
				$this->express_payment_redirect_url = $this->gateway_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH;
			}
			$this->gateway_url_not_modified_by_user = self::GATEWAY_SANDBOX;
			$this->private_key                      = $this->get_option( Settings_Manager::get_currency_option_key( 'test_private_key',
				$currency ) );
			$this->service_id                       = $this->get_option( Settings_Manager::get_currency_option_key( 'test_service_id',
				$currency ) );
		} else {
			$production_gateway_url = $this->get_option( 'gateway_url' );
			if ( Helper::is_string_url( $production_gateway_url ) ) {
				$production_gateway_url             = Helper::format_gateway_url( $production_gateway_url );
				$this->gateway_url                  = $production_gateway_url;
				$this->express_payment_redirect_url = $this->gateway_url;
			} else {
				$this->gateway_url                  = self::GATEWAY_PRODUCTION;
				$this->express_payment_redirect_url = $this->gateway_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH;
			}
			$this->gateway_url_not_modified_by_user = self::GATEWAY_PRODUCTION;
			$this->private_key                      = $this->get_option( Settings_Manager::get_currency_option_key( 'private_key',
				$currency ) );
			$this->service_id                       = $this->get_option( Settings_Manager::get_currency_option_key( 'service_id',
				$currency ) );
		}

		// Surface missing per-currency credentials as warnings so support can identify the
		// misconfiguration from logs without exposing secrets.
		if ( empty( $this->private_key ) || empty( $this->service_id ) ) {
			blue_media()
				->get_woocommerce_logger( 'bm_woocommerce_itn' )
				->log_warning(
					sprintf(
					/* translators: 1: currency code, 2: testmode flag, 3: private_key empty flag, 4: service_id empty flag */
						'[setup_variables] empty credentials for currency=%1$s testmode=%2$s private_key_empty=%3$s service_id_empty=%4$s',
						$currency,
						$this->testmode ? 'yes' : 'no',
						empty( $this->private_key ) ? 'yes' : 'no',
						empty( $this->service_id ) ? 'yes' : 'no'
					)
				);
		}

		$this->gateway_config = new Gateway_Configuration(
			(string) $this->service_id,
			(string) $this->private_key,
			(string) $this->gateway_url,
			(string) $this->gateway_url_not_modified_by_user,
			(string) $this->express_payment_redirect_url,
			(bool) $this->testmode
		);
		$this->transaction_builder = new Transaction_Request_Builder(
			$this->gateway_config->get_service_id(),
			$this->gateway_config->get_private_key()
		);
		if ( ! isset( $this->gateway_list_service ) ) {
			$this->gateway_list_service = new Gateway_List_Service( $this );
		}
		if ( ! isset( $this->payment_channel_renderer ) ) {
			$this->payment_channel_renderer = new Payment_Channel_Renderer( $this );
		}
		if ( ! isset( $this->itn_webhook_handler ) ) {
			$this->itn_webhook_handler = new ITN_Webhook_Handler( $this );
		}
		if ( ! isset( $this->payment_redirect_handler ) ) {
			$this->payment_redirect_handler = new Payment_Redirect_Handler( $this );
		}
	}

	/**
	 * Read a setting honouring the active multi-currency context.
	 *
	 * The currency-postfixed option key (e.g. {@see Settings_Manager::get_currency_option_key()})
	 * is read first; if it is not set, we fall back to the non-postfixed legacy key. This keeps
	 * backward compatibility for stores that have not yet configured per-currency values.
	 *
	 * @param string $option  Logical option name (without currency postfix).
	 * @param string $default Default value when neither the currency-aware nor legacy key is set.
	 *
	 * @return string
	 */
	public function get_currency_aware_option( string $option, string $default = '' ): string {
		$currency           = blue_media()->resolve_blue_media_currency_symbol();
		$currency_aware_key = Settings_Manager::get_currency_option_key( $option, $currency );

		if ( $currency_aware_key !== $option ) {
			$value = (string) $this->get_option( $currency_aware_key, '' );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return (string) $this->get_option( $option, $default );
	}

	public function resolve_is_test_mode(): bool {
		if ( 'yes' === $this->get_option( 'testmode', 'no' ) ) {
			return true;
		} else {
			if ( 'yes' === $this->get_option( 'sandbox_for_admins', 'no' ) ) {
				$current_user = wp_get_current_user();
				if ( user_can( $current_user, 'administrator' ) ) {
					blue_media()->get_woocommerce_logger()->log_debug(
						'[resolve_is_test_mode] Test mode forced by sandbox_for_admins option' );

					return true;
				}
			}
		}

		return false;
	}


	/**
	 * @return void
	 * @throws Exception
	 */
	public function init_form_fields() {
		$this->form_fields = $this->settings_manager->get_form_fields();
	}

	/**
	 * SVG used next to the gateway name on classic checkout.
	 */
	public function get_checkout_logo_url(): string {
		$variant = $this->get_checkout_logo_variant();
		$file    = 'light' === $variant ? 'logo-autopay-light.svg' : 'logo-autopay.svg';
		$url     = blue_media()->get_plugin_images_url() . '/' . $file;

		return (string) apply_filters( 'autopay_checkout_logo_url', $url, $variant );
	}

	/**
	 * SVG for WooCommerce Blocks checkout payment method icon.
	 */
	public function get_checkout_logo_banner_url(): string {
		$variant = $this->get_checkout_logo_variant();
		$file    = 'light' === $variant ? 'logo-autopay-banner-light.svg' : 'logo-autopay-banner.svg';
		$url     = blue_media()->get_plugin_images_url() . '/' . $file;

		return (string) apply_filters( 'autopay_checkout_logo_banner_url', $url, $variant );
	}

	/**
	 * SVG used by expandable checkout payment method groups (e.g. PBL).
	 */
	public function get_checkout_group_logo_url(): string {
		$variant = $this->get_checkout_logo_variant();
		$file    = 'light' === $variant ? 'logo-group-light.svg' : 'logo-group.svg';
		$url     = blue_media()->get_plugin_images_url() . '/' . $file;

		return (string) apply_filters( 'autopay_checkout_group_logo_url', $url, $variant );
	}

	private function get_checkout_logo_variant(): string {
		$variant = (string) $this->get_option( 'checkout_logo_variant', 'dark' );

		return in_array( $variant, [ 'dark', 'light' ], true ) ? $variant : 'dark';
	}

	public function is_whitelabel_mode_enabled(): bool {
		$currency   = blue_media()->resolve_blue_media_currency_symbol();
		$option_key = Settings_Manager::get_currency_option_key( 'whitelabel',
			$currency );
		$whitelabel = apply_filters( 'autopay_filter_option_whitelabel',
			$this->get_option( $option_key, 'no' ) );

		return 'yes' === $whitelabel;
	}


	public function configure_google_pay(): ?array {
		$urlparts = wp_parse_url( home_url() );
		$domain   = $urlparts['host'];
		$params   = [
			'ServiceID'      => $this->service_id,
			'MerchantDomain' => $domain,
		];

		$client = new Client();

		$params = array_merge( $params, [
			'Hash' => $this->hash_transaction_parameters(
				$params ),
		] );

		$result = null;
		$error  = '';
		try {
			$result = json_decode( $client->google_pay_merchant_info( $params,
				$this->gateway_url ),
				true );
			if ( is_array( $result ) ) {
				$result['cart_total'] = WC()->cart->get_total( 'edit' );
				$result['currency']   = get_woocommerce_currency();
			}
		} catch ( Exception $e ) {
			$error  = $e->getMessage();
			$result = null;
		} finally {
			blue_media()
				->get_woocommerce_logger( 'bm_woocommerce_googlepay' )
				->log_debug( sprintf( '[webhook] [%s]',
					wp_json_encode( [
						'params'      => $params,
						'response'    => $result,
						'error'       => $error,
						'gateway_url' => $this->gateway_url,
					] ),
				) );

			return $result;
		}
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	public function payment_fields() {
		if ( $this->is_whitelabel_mode_enabled() ) {
			$gpay_form_data = [];
			try {
				$gateway_list_data = $this->gateway_list();

				if ( empty( $gateway_list_data ) ) {
					throw new Exception( 'Gateway list data is empty.' );
				}

				$gateway_list_response = ( new Gateway_List_Response_Factory() )->create( $gateway_list_data );
				if ( $this->should_offer_google_pay_on_checkout() ) {
					$gpay_form_data = $this->configure_google_pay() ?? [];
				}

				// Cache for inline template injection.
				$this->gpay_form_data = $gpay_form_data;

				$this->render_channels_v3( $gateway_list_response,
					$gpay_form_data );
			} catch ( Exception $exception ) {
				blue_media()->get_woocommerce_logger()->log_error(
					sprintf( '[payment_fields] Could not render payment channels. Error: %s',
						$exception->getMessage() ),
				);
				echo esc_html( __( 'Payment methods are currently unavailable. Please try again later.',
					'platnosci-online-blue-media' ) );
			}
		} elseif ( ! empty( $this->description ) ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		do_action( 'autopay_after_payment_field' );
	}

	/**
	 * @param $order_id
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_payment( $order_id ) {
		blue_media()
			->get_order_remote_status_manager()
			->install_db_schema();

		if ( wc_notice_count( 'error' ) > 0 ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[process_payment wc_notice_count > 0 exiting] [Order id: %s]',
					$order_id,
				) );

			return [];
		}

		blue_media()
			->get_order_remote_status_manager()
			->add_order_remote_status(
				(int) $order_id,
				Order_Remote_Status_Manager::STATUS_PROCESS_PAYMENT,
			);

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_payment start] [Order id: %s]',
				$order_id,
			) );


		$order = wc_get_order( $order_id );
		Versioning::update_autopay_version_in_order( $order );


		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies woocommerce-process_checkout nonce before calling process_payment().
		$is_classic_checkout = false;
		if ( isset( $_POST['bm_standard_checkout'] ) ) {
			//classic checkout
			$is_classic_checkout = '1' === sanitize_text_field( wp_unslash( $_POST['bm_standard_checkout'] ) );
		}

		$payment_channel = 0;
		if ( isset( $_POST['bm-payment-channel'] ) ) {
			//classic checkout
			$payment_channel = (int) sanitize_text_field( wp_unslash( $_POST['bm-payment-channel'] ) );
		}

		if ( 0 === $payment_channel && ! $is_classic_checkout ) {
			if ( isset( $_POST['autopay_numeric_channel_id'] ) ) {
				// Block checkout (Store API normalizes some keys to lowercase).
				$payment_channel = (int) sanitize_text_field( wp_unslash( $_POST['autopay_numeric_channel_id'] ) );
			} elseif ( isset( $_POST['autopay_numeric_channel_Id'] ) ) {
				$payment_channel = (int) sanitize_text_field( wp_unslash( $_POST['autopay_numeric_channel_Id'] ) );
			}
		}


		$is_blik_0      = false;
		$is_gpay        = false;
		$is_card_widget = false;


		if ( 0 === $payment_channel && $this->is_whitelabel_mode_enabled() ) {
			if ( $is_classic_checkout ) {//nie pokazuj błędu w module blokowym w opcji z przekierowaniem
				wc_add_notice( __( 'Autopay payments: Cannot redirect to payment because no payment channel selected.',
					'platnosci-online-blue-media' ),
					'error' );

				return [
					'status' => 'failure',
				];
			}
		}

		$blik0_type = $this->get_option( 'blik_type', 'with_redirect' );
		$gpay_type = $this->get_option(
			Settings_Manager::get_currency_option_key( 'gpay_type', $order->get_currency() ),
			'with_redirect'
		);

		if ( self::BLIK_0_CHANNEL === $payment_channel && 'blik_0_without_redirect' === $blik0_type ) {
			$blik_code            = sanitize_text_field( wp_unslash( $_POST['bluemedia_blik_code'] ?? '' ) );
			$blik_0_block_payment = isset( $_POST['blik_0_block_payment'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['blik_0_block_payment'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( $this->is_blik_0_code_valid( $blik_code ) ) {
				$this->process_blik_0_payment( $order,
					$blik_code,
					$blik_0_block_payment );
				$is_blik_0 = true;
			} else {
				wc_add_notice( __( 'The code you provided is invalid. Code should be 6 digits.',
					'platnosci-online-blue-media' ),
					'error' );

				return [
					'status' => 'failure',
				];
			}
		} elseif ( self::CARD_CHANNEL === $payment_channel ) {
			if ( $is_classic_checkout ) {
				try {
					$_3ds_redirect_url = $this->process_card_widget_payment( $order );

					if ( $_3ds_redirect_url ) {
						$order->update_meta_data( 'bm_3ds_redirect_url',
							$_3ds_redirect_url );
						$order->save_meta_data();
					}

					$is_card_widget = true;
					$params         = [
						'params' => [ 'OrderID' => $order->get_id() ],
					];
					WC()->session->set( 'bm_order_payment_params',
						$params );
					WC()->session->save_data();
				} catch ( Exception $exception ) {
					blue_media()->get_woocommerce_logger()->log_error(
						sprintf( '[process_card_widget_payment failed] [Order id: %s] [Error: %s]',
							$order_id,
							$exception->getMessage(),
						) );

					$known = [
						'autopay_card_token_empty',
						'autopay_card_gateway_error',
						'autopay_card_invalid_response',
						'autopay_card_transport_error',
						'autopay_card_untrusted_redirect',
					];
					if ( ! in_array( $exception->getMessage(), $known, true ) ) {
						wc_add_notice(
							__( 'Card payment could not be started. Please try again.',
								'platnosci-online-blue-media' ),
							'error',
						);
					}

					return [
						'status' => 'failure',
					];
				}
			} else {
				// Checkout Block: Autopay card uses the same card widget + pretransaction flow as classic (not initial redirect params).
				$card_token = $this->read_atp_card_payment_token_from_request();
				if ( '' === $card_token ) {
					blue_media()->get_woocommerce_logger()->log_error(
						sprintf( '[process_card_widget_payment failed] [block checkout card token missing] [Order id: %s]',
							$order_id
						) );

					wc_add_notice(
						__( 'Card payment data is missing. Please complete the card form and try again.',
							'platnosci-online-blue-media' ),
						'error',
					);

					return [
						'status' => 'failure',
					];
				}

				try {
					$_3ds_redirect_url = $this->process_card_widget_payment( $order );

					if ( $_3ds_redirect_url ) {
						$order->update_meta_data( 'bm_3ds_redirect_url',
							$_3ds_redirect_url );
						$order->save_meta_data();
					}

					$is_card_widget = true;
					$params         = [
						'params' => [ 'OrderID' => $order->get_id() ],
					];
					WC()->session->set( 'bm_order_payment_params',
						$params );
					WC()->session->save_data();

					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[bm_order_payment_params saved] [block checkout card channel] [Order id: %s]',
							$order_id
						) );
				} catch ( Exception $exception ) {
					blue_media()->get_woocommerce_logger()->log_error(
						sprintf( '[process_card_widget_payment failed] [Order id: %s] [Error: %s]',
							$order_id,
							$exception->getMessage(),
						) );

					$known = [
						'autopay_card_token_empty',
						'autopay_card_gateway_error',
						'autopay_card_invalid_response',
						'autopay_card_transport_error',
						'autopay_card_untrusted_redirect',
					];
					if ( ! in_array( $exception->getMessage(), $known, true ) ) {
						wc_add_notice(
							__( 'Card payment could not be started. Please try again.',
								'platnosci-online-blue-media' ),
							'error',
						);
					}

					return [
						'status' => 'failure',
					];
				}
			}
		} elseif ( self::GPAY_CHANNEL === $payment_channel && 'without_redirect' === $gpay_type ) {
			if ( ! $this->should_offer_google_pay_on_checkout() ) {
				wc_add_notice(
					__( 'Google Pay is unavailable because the store checkout does not require acceptance of the terms and conditions.',
						'platnosci-online-blue-media' ),
					'error',
				);

				return [
					'status' => 'failure',
				];
			}
			try {
				$_3ds_redirect_url = $this->process_gpay_payment( $order );

				if ( $_3ds_redirect_url ) {
					$order->update_meta_data( 'bm_3ds_redirect_url',
						$_3ds_redirect_url );
					$order->save_meta_data();
				}

				$is_gpay = true;
				$params  = [
					'params' => [ 'OrderID' => $order->get_id() ],
				];
				WC()->session->set( 'bm_order_payment_params',
					$params );
				Session_Bridge::save();
			} catch ( Exception $exception ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[process gpay payment failed] [Order id: %s] [Error: %s]',
						$order_id,
						$exception->getMessage(),
					) );


				return [
					'status' => 'failure',
				];
			}
		} else {
			$params = [
				'params' => $this->transaction_builder->build(
					wc_get_order( $order_id ),
					$payment_channel,
				),
			];
			WC()->session->set( 'bm_order_payment_params', $params );
			Session_Bridge::save();

			// A fresh payment attempt for this order starts here — clear any stale
			// "already returned from a previous gateway visit" marker left over from an
			// earlier, already-finished attempt, so it doesn't block this new one in
			// Payment_Redirect_Handler::can_redirect_to_payment_gateway().
			$order->delete_meta_data( 'autopay_returned_from_payment' );
			$order->update_meta_data( 'bm_order_payment_params', $params );
			$order->save_meta_data();

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[process_payment] [cleared stale autopay_returned_from_payment] [Order id: %s]',
					$order_id,
				) );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[bm_order_payment_params saved to wc_session] [Order id: %s]',
					$order_id,
				) );


			blue_media()->get_woocommerce_logger( 'bm_woocommerce_session_debug' )->log_debug(
				sprintf( '[wc_session - process payment] [keys: %s]',
					is_object( WC()->session ) ? implode( ', ', array_keys( (array) WC()->session->get_session_data() ) ) : 'session_unavailable',
				) );
		}

		$this->payment_redirect_handler->schedule_remove_unpaid_orders( $order_id );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[wc_get_order_statuses] [%s]',
				wp_json_encode( wc_get_order_statuses() ),
			) );

		if ( ! $is_blik_0 && ! $is_gpay && ! $is_card_widget ) {
			$start_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_pending', 'pending' );
			$this->update_order_status( $order, $start_status );
			$order->add_order_note( __( 'Autopay: Payment process started for order ID:',
					'platnosci-online-blue-media' ) . $order_id );
		}

		$order_received_url_filtered = $this->resolve_return_url( $order );
		$original_order_received_url = $this->get_return_url( $order );
		if ( $this->payment_on_account_page ) {
			if ( ! is_user_logged_in() ) {
				$signature = Payment_On_Account_Page::generate_signature( $order_id );

				$order_received_url_filtered = add_query_arg(
					[
						'autopay_payment_on_account_page' => '1',
						'sig'                             => $signature,
						'order_id'                        => $order_id,
					],
					$order_received_url_filtered );

				$original_order_received_url = add_query_arg(
					[
						'autopay_payment_on_account_page' => '1',
						'sig'                             => $signature,
						'order_id'                        => $order_id,
					],
					$original_order_received_url );
			} else {
				$order_received_url_filtered = add_query_arg(
					[
						'autopay_payment_on_account_page' => '1',
					],
					$order_received_url_filtered );

				$original_order_received_url = add_query_arg(
					[
						'autopay_payment_on_account_page' => '1',
					],
					$original_order_received_url );
			}
		} elseif ( ! $is_blik_0 ) {
			$order_received_url_filtered = add_query_arg(
				[
					'autopay_express_payment' => '1',
				],
				$order_received_url_filtered );

			$original_order_received_url = add_query_arg(
				[
					'autopay_express_payment' => '1',
				],
				$original_order_received_url );
		}

		$order->update_meta_data( 'autopay_order_received_url',
			$order_received_url_filtered );

		$order->save();

		$return = [
			'result'   => 'success',
			'redirect' => $original_order_received_url,
		];

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_payment] [Order id: %s] [return: %s]',
				$order_id,
				wp_json_encode( $return ),
			) );

		wc()->session->set( 'store_api_draft_order', 0 );
		WC()->cart->empty_cart();
		Session_Bridge::save();

		return $return;
	}

	/**
	 * Resolve the order-received URL, applying any configured find/replace filter.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return string
	 */
	public function resolve_return_url( WC_Order $order ): string {
		return $this->payment_redirect_handler->resolve_return_url( $order );
	}

	private function is_blik_0_code_valid( string $code ): bool {
		return strlen( $code ) === 6 && ctype_digit( $code );
	}

	/**
	 * Decode XML returned by Autopay continue-transaction / payment endpoints.
	 *
	 * @param string $response_xml Raw response body.
	 *
	 * @return array<string, string>
	 */
	public function decode_continue_transaction_response( $response_xml ) {
		return Continue_Transaction_Response_Xml_Parser::parse( (string) $response_xml );
	}

	private function process_gpay_payment(
		WC_Order $order,
		?string $payment_token = null
	): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- GPay token is a JSON/Base64 payload; sanitize_text_field() would corrupt the format. Validated via type check and length limit.
		$gpay_token_raw = isset( $_POST['atp_gpay_payment_token'] ) && is_string( $_POST['atp_gpay_payment_token'] ) && strlen( $_POST['atp_gpay_payment_token'] ) < 10000
			? (string) wp_unslash( $_POST['atp_gpay_payment_token'] )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		blue_media()->get_woocommerce_logger( 'bm_woocommerce_googlepay' )->log_debug(
			sprintf(
				'[process_gpay_payment] [Order ID: %s] [token_field_len: %d]',
				$order->get_id(),
				strlen( $gpay_token_raw )
			) );

		WC()->session->set( 'bm_wc_order_id', $order->get_id() );
		Session_Bridge::save();

		if ( ! $payment_token ) {
			$payment_token = '' !== $gpay_token_raw ? $gpay_token_raw : '';
			if ( empty( $payment_token ) ) {
				throw new Exception( "payment_token is empty" );
			}
		}

		$payment_token = base64_decode( $payment_token );

		blue_media()->get_woocommerce_logger( 'bm_woocommerce_googlepay' )->log_debug(
			sprintf(
				'[process_gpay_payment] [Order ID: %s] [decoded_token_len: %d]',
				$order->get_id(),
				is_string( $payment_token ) ? strlen( $payment_token ) : 0
			) );

		/*$currency = isset( $_POST['atp_gpay_currency'] ) ? sanitize_text_field( stripslashes( $_POST['atp_gpay_currency'] ) ) : '';
		if ( empty( $payment_token ) ) {
			throw new Exception( "currency is empty" );
		}*/


		$client = new Client();
		$params = [
			'ServiceID'     => $this->service_id,
			'OrderID'       => $order->get_id(),
			'Amount'        => $this->get_price_for_api_request( $order ),
			'Description'   => (string) $order->get_id(),
			'GatewayID'     => self::GPAY_CHANNEL,
			'Currency'      => $order->get_currency(),
			'CustomerEmail' => $order->get_billing_email(),
			'CustomerIP'    => blue_media()
				->get_core_helpers()
				->get_visitor_ip(),
			'Title'         => (string) $order->get_id(),
			'PaymentToken'  => base64_encode( $payment_token ),
		];

		$params = array_merge( $params, [
			'Hash' => $this->hash_transaction_parameters(
				$params ),
		] );

		try {
			$order->update_meta_data( 'bm_transaction_init_params',
				$params );
			$order->save_meta_data();

			$result = $this->decode_continue_transaction_response( $client->continue_transaction_request(
				$params,
				$this->gateway_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH,
			) );

			$params_log = $params;
			if ( isset( $params_log['PaymentToken'] ) ) {
				$params_log['PaymentToken'] = '[redacted len=' . strlen( (string) $params_log['PaymentToken'] ) . ']';
			}
			if ( isset( $params_log['Hash'] ) ) {
				$params_log['Hash'] = '[redacted]';
			}
			blue_media()->get_woocommerce_logger( 'bm_woocommerce_googlepay' )->log_debug(
				sprintf( '[process_gpay_payment] [continue_transaction_request] [params: %s] [result: %s]',
					wp_json_encode( $params_log ),
					wp_json_encode( $result ),
				) );

			if ( isset( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] ) ) {
				throw new Exception( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] );
			}

			if ( empty( $result ) || ! is_array( $result ) ) {
				throw new Exception( sprintf( 'Continue transaction response invalid format (%s)',
					wp_json_encode( $result ) ) );
			}

			$redirecturl = null;
			if ( ! empty( $result[ Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL ] ) ) {
				$redirecturl = $result[ Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL ];
			}

			if ( null !== $redirecturl && '' !== $redirecturl
				&& ! $this->payment_redirect_handler->is_trusted_autopay_redirect_url( (string) $redirecturl ) ) {
				throw new Exception( 'autopay_untrusted_redirect' );
			}

			WC()->session->set( 'bm_continue_transaction_start_error', '' );

			$start_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_pending', 'pending' );
			$this->update_order_status( $order, $start_status );
			$order->add_order_note( __( 'Autopay: Google Pay payment process started for order ID:',
					'platnosci-online-blue-media' ) . $order->get_id() );

			return $redirecturl;
		} catch ( Exception $e ) {
			blue_media()->get_woocommerce_logger( 'bm_woocommerce_googlepay' )->log_error(
				sprintf( '[process_gpay_payment] [continue_transaction_request] [Params: %s] [Error message: %s]',
					wp_json_encode( $params ),
					$e->getMessage(),
				) );

			WC()->session->set( 'bm_continue_transaction_start_error',
				__( 'Payment failed.',
					'platnosci-online-blue-media' ) );

			$new_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_failure',
				'failed' );
			$this->update_order_status( $order,
				$new_status,
				'Autopay Google Pay: paymentStatus FAILURE' );
			$order->save();
			Session_Bridge::save(); // intentional: ensure persistence before error response
		}

		return null;
	}

	/**
	 * Read `atp_card_payment_token` from the current request (POST).
	 *
	 * Do not use {@see sanitize_text_field()}: it can alter Base64 (+, /, =) and break Hash vs. token.
	 *
	 * @return string Trimmed token or empty string.
	 */
	private function read_atp_card_payment_token_from_request(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Card token is a Base64/JSON payload; sanitize_text_field() would corrupt the format. WC checkout nonce is verified before reaching this point. Validated via type check and length limit.
		if ( isset( $_POST['atp_card_payment_token'] ) && is_string( $_POST['atp_card_payment_token'] ) && strlen( $_POST['atp_card_payment_token'] ) < 10000 ) {
			return trim( wp_unslash( (string) $_POST['atp_card_payment_token'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		return '';
	}

	/**
	 * Card widget (GatewayID 1500): read POST token, delegate pretransaction to {@see Card_Widget_Payment_Service}.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string|null Redirect URL from Autopay.
	 *
	 * @throws Exception When token is missing or Autopay rejects the start request.
	 */
	private function process_card_widget_payment( WC_Order $order ): ?string {
		blue_media()->get_woocommerce_logger( 'CardWidget' )->log_debug(
			sprintf(
				'[process_card_widget_payment] [Order ID: %d]',
				$order->get_id()
			),
		);

		WC()->session->set( 'bm_wc_order_id', $order->get_id() );
		WC()->session->save_data();

		$payment_token_input = $this->read_atp_card_payment_token_from_request();

		if ( '' === $payment_token_input ) {
			wc_add_notice(
				__( 'Card payment data is missing. Please complete the card form and try again.',
					'platnosci-online-blue-media' ),
				'error',
			);
			throw new Exception( 'autopay_card_token_empty' );
		}

		$service = new Card_Widget_Payment_Service(
			new Blue_Media_Hash_Generator( $this ),
			new Client(),
			blue_media()->get_woocommerce_logger( 'CardWidget' ),
		);

		$result = $service->start_pretransaction(
			$order,
			$payment_token_input,
			$this->get_price_for_api_request( $order ),
			(string) $this->service_id,
			$this->gateway_url,
		);

		if ( Card_Widget_Start_Result::TRANSPORT_ERROR === $result->get_type() ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: remote error message. */
					__( 'Card pretransaction request failed: %s', 'platnosci-online-blue-media' ),
					$result->get_transport_detail()
				),
				'error',
			);
			throw new Exception( 'autopay_card_transport_error' );
		}

		if ( Card_Widget_Start_Result::REJECTED === $result->get_type() ) {
			$reason = $result->get_gateway_reason();
			if ( '' !== $reason ) {
				$reason_safe = substr( wp_strip_all_tags( $reason, true ), 0, 500 );
				wc_add_notice(
					sprintf(
						/* translators: %s: rejection reason from payment gateway. */
						__( 'Card payment was rejected: %s', 'platnosci-online-blue-media' ),
						$reason_safe
					),
					'error',
				);
			} else {
				wc_add_notice(
					__( 'Card payment was rejected. Please try again.', 'platnosci-online-blue-media' ),
					'error',
				);
			}
			throw new Exception( 'autopay_card_gateway_error' );
		}

		if ( Card_Widget_Start_Result::BAD_RESPONSE === $result->get_type() ) {
			wc_add_notice(
				__( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ),
				'error',
			);
			throw new Exception( 'autopay_card_invalid_response' );
		}

		if ( Card_Widget_Start_Result::SUCCESS !== $result->get_type() ) {
			throw new Exception( 'autopay_card_invalid_response' );
		}

		$redirect_out = $result->get_redirect_url();
		if ( ! $this->payment_redirect_handler->is_trusted_autopay_redirect_url( $redirect_out ) ) {
			blue_media()->get_woocommerce_logger( 'CardWidget' )->log_error(
				sprintf(
					'[process_card_widget_payment] untrusted redirect blocked order_id=%d',
					$order->get_id()
				)
			);
			wc_add_notice(
				__( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ),
				'error',
			);
			throw new Exception( 'autopay_card_untrusted_redirect' );
		}

		$order->update_meta_data( 'bm_transaction_init_params', $result->get_params_with_hash() );
		$order->save_meta_data();

		WC()->session->set( 'bm_continue_transaction_start_error', '' );

		$this->update_order_status( $order, 'pending' );
		$order->add_order_note(
			__( 'Autopay: Card payment process started for order ID:', 'platnosci-online-blue-media' )
			. $order->get_id()
		);

		return $redirect_out;
	}


	private function process_blik_0_payment(
		WC_Order $order,
		string $blik_authorization_code,
		bool $block_payment = false
	) {
		// On the order-pay page, WC_Form_Handler::pay_action() performs a server-side wp_redirect()
		// with whatever process_payment() returns. Returning '#' would reload the order-pay page.
		// Skip the filter so process_payment() returns the real order-received URL instead,
		// letting pay_action() redirect the customer there directly after BLIK-0 is submitted.
		$is_order_pay_page = ! $block_payment && absint( get_query_var( 'order-pay' ) ) > 0;

		if ( ! $block_payment && ! $is_order_pay_page ) {
			add_filter( 'woocommerce_get_checkout_order_received_url',
				function ( $redirect_url, WC_Order $order ) {
					WC()->session->set( 'bm_original_order_received_url',
						$redirect_url );

					$order->update_meta_data( 'autopay_original_order_received_url',
						$redirect_url );
					$order->save_meta_data();

					return '#';
				},
				10,
				2 );
		}

		WC()->session->set( 'bm_wc_order_id', $order->get_id() );
		Session_Bridge::save();

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_blik_0_payment] [Order ID: %s] [block_payment: %s]',
				wp_json_encode( $order->get_id() ),
				$block_payment ? 'true' : 'false',
			) );

		$client = new Client();
		$params = [
			'ServiceID'         => $this->service_id,
			'OrderID'           => $order->get_id(),
			'Amount'            => $this->get_price_for_api_request( $order ),
			'Description'       => (string) $order->get_id(),
			'GatewayID'         => self::BLIK_0_CHANNEL,
			'Currency'          => 'PLN',
			'CustomerEmail'     => $order->get_billing_email(),
			'CustomerIP'        => blue_media()
				->get_core_helpers()
				->get_visitor_ip(),
			'Title'             => (string) $order->get_id(),
			'AuthorizationCode' => $blik_authorization_code,
		];

		$params = array_merge( $params, [
			'Hash' => $this->hash_transaction_parameters(
				$params ),
		] );

		try {
			$order->update_meta_data( 'bm_transaction_init_params',
				$params );
			$order->save_meta_data();

			$result = $this->decode_continue_transaction_response( $client->continue_transaction_request(
				$params,
				$this->gateway_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH,
			) );

			$params_log         = $params;
			$params_log['Hash'] = '***';
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[process_blik_0_payment] [continue_transaction_request] [params: %s] [result: %s]',
					wp_json_encode( $params_log ),
					wp_json_encode( $result ),
				) );

			if ( isset( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] ) ) {
				throw new Exception( $result[ Autopay_Payment_Protocol::XML_LOCAL_REASON ] );
			}

			if ( empty( $result ) || ! is_array( $result ) ) {
				throw new Exception( sprintf( 'Continue transaction response invalid format (%s)',
					wp_json_encode( $result ) ) );
			}

			WC()->session->set( 'bm_continue_transaction_start_error', '' );

			$start_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_pending', 'pending' );
			$this->update_order_status( $order, $start_status );
			$order->add_order_note( __( 'Autopay: BLIK-0 payment process started for order ID:',
					'platnosci-online-blue-media' ) . $order->get_id() );
		} catch ( Exception $e ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[continue_transaction_request] [Params: %s] [Error message: %s]',
					wp_json_encode( $params ),
					$e->getMessage(),
				) );

			WC()->session->set( 'bm_continue_transaction_start_error',
				__( 'Payment failed.',
					'platnosci-online-blue-media' ) );

			$new_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_failure',
				'failed' );
			$this->update_order_status( $order,
				$new_status,
				'Autopay ITN: paymentStatus FAILURE' );
			$order->save();
			Session_Bridge::save(); // intentional: ensure persistence before error response
		}
	}

	private function get_price_for_api_request( WC_Order $order ) {
		$price = str_replace( ',',
			'.',
			(string) $order->get_total( false ) );
		if ( strpos( $price, '.' ) === false ) {
			$price = $price . '.00';
		}

		return $price;
	}

	/**
	 * Hash a parameter array with the configured private key (SHA-256).
	 *
	 * Delegates to Transaction_Request_Builder::hash(). Kept public for
	 * backward compatibility with Transaction_Test and Blue_Media_Hash_Generator.
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	public function hash_transaction_parameters( array $params ): string {
		return $this->transaction_builder->hash( $params );
	}

	/**
	 * @return string
	 */
	public
	function get_private_key() {
		return $this->private_key;
	}

	/**
	 * Update a WooCommerce order status and log the result.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $new_status Target status (without wc- prefix).
	 * @param string   $note       Optional order note.
	 *
	 * @return bool
	 */
	public function update_order_status(
		WC_Order $order,
		string $new_status,
		string $note = ''
	): bool {
		return $this->payment_redirect_handler->update_order_status( $order, $new_status, $note );
	}

	public function process_admin_options() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies woocommerce-settings nonce before calling process_admin_options().
		// Enforce max lengths for custom fields before saving
		if ( isset( $_POST[ $this->get_field_key( 'payment_method_title' ) ] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST[ $this->get_field_key( 'payment_method_title' ) ] ) );
			if ( mb_strlen( $title ) > 80 ) {
				$title                                                   = mb_substr( $title,
					0,
					80 );
				$_POST[ $this->get_field_key( 'payment_method_title' ) ] = $title;
				\WC_Admin_Settings::add_error( __( 'Payment method title has been truncated to 80 characters.',
					'platnosci-online-blue-media' ) );
			}
		}
		if ( isset( $_POST[ $this->get_field_key( 'payment_method_description' ) ] ) ) {
			$desc = sanitize_textarea_field( wp_unslash( $_POST[ $this->get_field_key( 'payment_method_description' ) ] ) );
			if ( mb_strlen( $desc ) > 500 ) {
				$desc                                                          = mb_substr( $desc,
					0,
					500 );
				$_POST[ $this->get_field_key( 'payment_method_description' ) ] = $desc;
				\WC_Admin_Settings::add_error( __( 'Payment method description has been truncated to 500 characters.',
					'platnosci-online-blue-media' ) );
			}
		}

		// Call parent to preserve default behaviour (save WooCommerce settings)
		$result = parent::process_admin_options();

		// Save custom order of payment methods if present
		if ( isset( $_POST['bm_reset_order'] ) && '1' === sanitize_key( wp_unslash( $_POST['bm_reset_order'] ) ) ) {
			// Remove custom ordering – revert to default
			delete_option( 'bm_payment_methods_order' );

			// Also reset custom title & description to defaults
			$defaults_title = __( 'Autopay gateway', 'platnosci-online-blue-media' );
			$defaults_desc  = __( 'Instant payment, BLIK, credit card, Google Pay, Apple Pay',
				'platnosci-online-blue-media' );
			$settings_key   = $this->get_option_key();
			$settings_arr   = get_option( $settings_key, [] );
			if ( ! is_array( $settings_arr ) ) {
				$settings_arr = [];
			}
			$settings_arr['payment_method_title']       = $defaults_title;
			$settings_arr['payment_method_description'] = $defaults_desc;
			$settings_arr['checkout_logo_variant']      = 'dark';
			update_option( $settings_key, $settings_arr );
			\WC_Admin_Settings::add_message( __( 'Title and description have been reset to defaults.',
				'platnosci-online-blue-media' ) );
		} elseif ( isset( $_POST['bm_payment_methods_order'] ) ) {
			$order = sanitize_text_field( wp_unslash( $_POST['bm_payment_methods_order'] ) );
			update_option( 'bm_payment_methods_order', $order );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $result;
	}

	public function admin_options() {
		$active_tab_id = ( new \Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs() )->get_active_tab_id();

		// Hide Woo default save button on Payment settings tab; we will render our own row
		if ( $active_tab_id === \Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs::PAYMENT_SETTINGS_TAB_ID ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce admin convention for hiding the default save button.
			$GLOBALS['hide_save_button'] = true;
		}

		$this->settings_manager->render_settings(
			$this->generate_settings_html( $this->get_form_fields(), false ),
		);

		// Render custom submit row only on Payment settings tab (with flex spacing)
		if ( $active_tab_id === \Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs::PAYMENT_SETTINGS_TAB_ID ) {
			echo '<p class="submit autopay-submit-row" style="display:flex;justify-content:space-between;align-items:center;text-align:center">';
			// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Intentional reuse of existing WooCommerce translation for 'Save changes'.
			echo '<button name="save" class="woocommerce-save-button components-button is-primary" type="submit" value="' . esc_attr__( 'Save changes',
					'woocommerce' ) . '">' . esc_html__( 'Save changes',
					'woocommerce' ) . '</button>';
			// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
			echo '<button type="submit" name="bm_reset_order" value="1" id="bm-reset-order" style="background:none!important;border:0!important;box-shadow:none!important;text-shadow:none!important;padding:0!important;margin-left:8px!important;display:inline-flex!important;align-items:center!important;justify-content:right!important;text-align:center!important;color:#2271b1!important;text-decoration:underline!important">' . esc_html__( 'Reset to default',
					'platnosci-online-blue-media' ) . '</button>';
			wp_nonce_field( 'woocommerce-settings' );
			echo '</p>';
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce admin convention for hiding the default save button.
			unset( $GLOBALS['hide_save_button'] );
		}
	}

	public function get_gateway_url(): string {
		return $this->gateway_url;
	}

	public function get_gateway_url_not_modified_by_user(): string {
		return $this->gateway_url_not_modified_by_user;
	}

	public function get_service_id(): string {
		return $this->service_id;
	}

	/**
	 * Return the GPay form data array (set during payment init).
	 *
	 * @return array|null
	 */
	public function get_gpay_form_data(): ?array {
		return $this->gpay_form_data;
	}

	/**
	 * Override the testmode flag (used in ITN hash validation fallback).
	 *
	 * @param bool $testmode Whether to enable test mode.
	 *
	 * @return void
	 */
	public function set_testmode( bool $testmode ): void {
		$this->testmode = $testmode;
	}

	/**
	 * Delegate to Gateway_List_Service::gateway_list().
	 *
	 * @param bool        $force_rebuild_cache Whether to bypass the cache.
	 * @param string|null $currency_code       ISO-4217 currency code.
	 *
	 * @return array
	 * @throws Exception When the API call fails.
	 */
	public function gateway_list( $force_rebuild_cache = false, ?string $currency_code = null ): array {
		return $this->gateway_list_service->gateway_list( $force_rebuild_cache, $currency_code );
	}

	/**
	 * Delegate to Gateway_List_Service::clear_gateway_list_cache().
	 *
	 * @return void
	 */
	public function clear_gateway_list_cache(): void {
		$this->gateway_list_service->clear_gateway_list_cache();
	}

	/**
	 * Delegate to Payment_Channel_Renderer::render_channels_v3().
	 *
	 * @param Gateway_List_Response $gateway_list_response  The gateway list API response.
	 * @param array                 $temporary_ignore_this_param Unused legacy parameter.
	 *
	 * @return void
	 */
	public function render_channels_v3( Gateway_List_Response $gateway_list_response, array $temporary_ignore_this_param = array() ) {
		$this->payment_channel_renderer->render_channels_v3( $gateway_list_response, $temporary_ignore_this_param );
	}

	/**
	 * Delegate to Payment_Channel_Renderer::render_channels_for_admin_panel().
	 *
	 * @param Gateway_List_Response $gateway_list_response The gateway list API response.
	 *
	 * @return void
	 */
	public function render_channels_for_admin_panel( Gateway_List_Response $gateway_list_response ) {
		$this->payment_channel_renderer->render_channels_for_admin_panel( $gateway_list_response );
	}

	/**
	 * Delegate to ITN_Webhook_Handler::webhook().
	 *
	 * @return void
	 */
	public function webhook() {
		$this->itn_webhook_handler->webhook();
	}
}
