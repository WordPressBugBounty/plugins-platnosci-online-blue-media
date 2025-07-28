<?php

namespace Ilabs\BM_Woocommerce\Gateway;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Client;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Expandable_Group;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Expandable_Group_Interface;
use Ilabs\BM_Woocommerce\Domain\Model\White_Label\Group;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Interfaces\Currency_Interface;
use Ilabs\BM_Woocommerce\Domain\Service\Legacy\Importer;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Domain\Service\Versioning\Versioning;
use Ilabs\BM_Woocommerce\Domain\Service\White_Label\Group_Mapper;
use Ilabs\BM_Woocommerce\Domain\Woocommerce\Autopay_Order_Factory;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use Ilabs\BM_Woocommerce\Helpers\Helper;
use Ilabs\BM_Woocommerce\Plugin;
use SimpleXMLElement;
use WC_Order;
use WC_Payment_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Hooks\Payment_On_Account_Page;
use function GuzzleHttp\Psr7\str;

class Blue_Media_Gateway extends WC_Payment_Gateway {

	const GATEWAY_PRODUCTION = 'https://pay.autopay.eu/';

	const GATEWAY_SANDBOX = 'https://testpay.autopay.eu/';

	const BLIK_0_CHANNEL = 509;

	const CARD_CHANNEL = 1500;

	const ITN_SUCCESS_STATUS_ID = 'SUCCESS';

	const ITN_PENDING_STATUS_ID = 'PENDING';

	const ITN_FAILURE_STATUS_ID = 'FAILURE';

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
		$this->icon
							= blue_media()->get_plugin_images_url()
							  . '/logo-autopay.svg';
		$this->has_fields
							= true;
		$this->method_title = __( 'Autopay Instant payment',
			'bm-woocommerce' );
		$this->method_description
							= __( 'Instant payment, BLIK, credit card, Google Pay, Apple Pay',
			'bm-woocommerce' );

		$this->supports = [
			'products',
		];
		$this->init_form_fields();
		$this->init_settings();

		$this->title = __( 'Autopay gateway',
			'bm-woocommerce' );

		$this->description = __( 'Instant payment, BLIK, credit card, Google Pay, Apple Pay',
			'bm-woocommerce' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->testmode    = $this->resolve_is_test_mode();


		$this->payment_on_account_page = apply_filters( 'autopay_payment_on_account_page',
			$this->payment_on_account_page );

		if ( $this->testmode && ! defined( 'BLUE_MEDIA_DISABLE_CACHE' ) ) {
			define( 'BLUE_MEDIA_DISABLE_CACHE', 1 );
		}

		$this->setup_variables();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
			[ $this, 'process_admin_options' ] );

		if ( isset( $_GET['autopay_express_payment'] ) || isset( $_GET['autopay_payment_on_account_page'] ) ) {
			if ( is_object( WC()->session ) && ! wp_doing_ajax() ) {
				if ( ! empty( WC()->session->get( 'bm_order_payment_params' ) ) ) {
					$params
						= WC()->session->get( 'bm_order_payment_params' )['params'];

					if ( $this->can_redirect_to_payment_gateway( (int) $params['OrderID'] ) ) {
						WC()->session->set( 'bm_order_payment_params', null );
						WC()->session->save_data();
						delete_post_meta( (int) $params['OrderID'],
							'bm_order_payment_params' );

						if ( 'yes' === $this->get_option( 'countdown_before_redirection' ) ) {
							ob_start();
							wp_head();
							$wp_head_html = ob_get_contents();
							ob_end_clean();
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
								$this->express_payment_redirect_url,
								$params['ServiceID'],
								$params['OrderID'],
								$params['Amount'],
								! empty( $params['GatewayID'] ) ? $params['GatewayID'] : '0',
								blue_media()->resolve_blue_media_currency_symbol(),
								$params['CustomerEmail'],
								$params['PlatformName'],
								$params['PlatformVersion'],
								$params['PlatformPluginVersion'],
								$params['Hash'] );
						}

						if ( 'yes' === $this->get_option( 'countdown_before_redirection' ) ) {
							_wp_footer_scripts();
							echo '</body>';
						} else {
							echo "<script type='text/javascript'>document.getElementById('paymentForm').submit();</script>";
						}

						blue_media()->get_woocommerce_logger()->log_debug(
							sprintf( '[Print payment form and submit by JS] [Params: %s] [url: %s] [is_rest_request: %s]',
								serialize( $params ),
								$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
								defined( 'REST_REQUEST' ) ? 'yes' : 'no'
							) );

						$order = wc_get_order( $params['OrderID'] );
						$order->add_meta_data( 'bm_transaction_init_params',
							$params );
						$order->save_meta_data();

						blue_media()->update_payment_cache( 'bm_payment_start',
							'1' );

						exit;
					} else {
						WC()->session->set( 'bm_order_payment_params', null );
						WC()->session->save_data();

						blue_media()->get_woocommerce_logger()->log_debug(
							sprintf( '[Print payment form canceled.] [Params: %s] [url: %s]',
								serialize( $params ),
								$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
							) );
					}
				} else {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf( '[Print payment form canceled. bm_order_payment_params not found in WC Session] [url: %s]',
							$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
						) );
				}
			} else {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[Print payment form canceled. WC Session not exists] [url: %s]',
						$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
					) );
			}
		}
		$this->webhook();
	}

	/**
	 * @param int $order_id
	 *
	 * @return bool
	 * @throws Exception
	 *
	 * @desc payment redirect loop protection
	 */
	private function can_redirect_to_payment_gateway( int $order_id ): bool {
		$return   = true;
		$wc_order = wc_get_order( $order_id );

		if ( ! $wc_order ) {
			return false;
		}

		$status   = $wc_order->get_meta( 'bm_order_payment_params' );
		$returned = (string) $wc_order->get_meta( 'autopay_returned_from_payment' );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] [$status = %s] [$returned = %s] [GET: %s] [REQUEST: %s] [Order ID: %s]',
				print_r( $status, true ),
				$returned,
				print_r( $_GET, true ),
				print_r( $_REQUEST, true ),
				$order_id )
		);

		if ( '1' === $returned || ! isset( $_GET['autopay_express_payment'] ) || empty( $status ) ) {
			$return = false;
		}

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] $return = %s',
				$return ? 'true' : 'false' )
		);

		$return_filtered = apply_filters( 'autopay_filter_can_redirect_to_payment_gateway',
			$return );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[can_redirect_to_payment_gateway] $return_filtered = %s',
				$return_filtered ? 'true' : 'false' )
		);

		return $return_filtered;
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
				$this->express_payment_redirect_url = $this->gateway_url . 'payment';
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
				$this->express_payment_redirect_url = $this->gateway_url . 'payment';
			}
			$this->gateway_url_not_modified_by_user = self::GATEWAY_PRODUCTION;
			$this->private_key                      = $this->get_option( Settings_Manager::get_currency_option_key( 'private_key',
				$currency ) );
			$this->service_id                       = $this->get_option( Settings_Manager::get_currency_option_key( 'service_id',
				$currency ) );
		}
	}

	private function resolve_is_test_mode(): bool {
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

	private function is_whitelabel_mode_enabled(): bool {
		$currency   = blue_media()->resolve_blue_media_currency_symbol();
		$option_key = Settings_Manager::get_currency_option_key( 'whitelabel',
			$currency );
		$whitelabel = apply_filters( 'autopay_filter_option_whitelabel',
			$this->get_option( $option_key, 'no' ) );

		return 'yes' === $whitelabel;
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	public function payment_fields() {
		if ( $this->is_whitelabel_mode_enabled() ) {
			try {

				$gateway_list = $this->gateway_list();
			} catch ( Exception $exception ) {
				$gateway_list = [];
			}

			$this->render_channels( $gateway_list );
		} else {
			echo wpautop( wptexturize( $this->description ) );
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
		blue_media()->get_order_remote_status_manager()
					->install_db_schema();

		if ( wc_notice_count( 'error' ) > 0 ) {
			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[process_payment wc_notice_count > 0 exiting] [Order id: %s]',
					$order_id
				) );

			return [];
		}

		blue_media()->get_order_remote_status_manager()
					->add_order_remote_status(
						(int) $order_id,
						Order_Remote_Status_Manager::STATUS_PROCESS_PAYMENT
					);

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_payment start] [Order id: %s]',
				$order_id
			) );


		$order = wc_get_order( $order_id );
		Versioning::update_autopay_version_in_order( $order );
		$payment_channel = (int) $_POST['bm-payment-channel'] ?? null;//classic checkout

		if ( 0 === $payment_channel ) {
			$payment_channel = (int) $_POST['autopay_numeric_channel_id'] ?? null;
		}
		$is_blik_0 = false;

		if ( 0 === $payment_channel && $this->is_whitelabel_mode_enabled() ) {
			if ( isset( $_POST['bm-payment-channel'] ) ) {//nie pokazuj błędu w module blokowym w opcji z przekierowaniem
				blue_media()->get_woocommerce_logger()->log_error(
					sprintf( '[Cannot redirect to payment] [$_POST["bm-payment-channel"]: %s]',
						sanitize_key( $_POST['bm-payment-channel'] )
					) );

				throw new Exception( __( 'Autopay payments: Cannot redirect to payment because no payment channel selected.',
					'bm-woocommerce' ) );
			}
		}

		$blik0_type = $this->get_option( 'blik_type', 'with_redirect' );

		if ( self::BLIK_0_CHANNEL === $payment_channel && 'blik_0_without_redirect' === $blik0_type ) {
			$blik_code            = (string) $_POST['bluemedia_blik_code'];
			$blik_0_block_payment = $_POST['blik_0_block_payment'] && (int) $_POST['blik_0_block_payment'] === 1;


			if ( $this->is_blik_0_code_valid( $blik_code ) ) {
				$this->process_blik_0_payment( $order,
					$blik_code,
					$blik_0_block_payment );
				$is_blik_0 = true;
			} else {

				wc_add_notice( __( 'The code you provided is invalid. Code should be 6 digits.',
					'bm-woocommerce' ),
					'error' );

				return [
					'status' => 'failure',
				];
			}
		} else {
			$params = [
				'params' => $this->prepare_initial_transaction_parameters(
					wc_get_order( $order_id ),
					$payment_channel
				),
			];
			WC()->session->set( 'bm_order_payment_params', $params );
			WC()->session->save_data();
			$order->add_meta_data( 'bm_order_payment_params', $params );
			$order->save_meta_data();

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[bm_order_payment_params saved to wc_session] [Order id: %s]',
					$order_id
				) );
		}

		$this->schedule_remove_unpaid_orders( $order_id );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[wc_get_order_statuses] [%s]',
				print_r( wc_get_order_statuses(), true )
			) );

		if ( ! $is_blik_0 ) {
			$this->update_order_status( $order, 'pending' );
			$order->add_order_note( __( 'Autopay: Payment process started for order ID:',
					'bm-woocommerce' ) . $order_id );
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

		$order->add_meta_data( 'autopay_order_received_url',
			$order_received_url_filtered );

		$order->save();

		$return = [
			'result'   => 'success',
			'redirect' => $original_order_received_url,
		];

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_payment] [Order id: %s] [return: %s]',
				$order_id,
				print_r( $return, true ),
			) );

		wc()->session->set( 'store_api_draft_order', 0 );
		WC()->cart->empty_cart();
		WC()->session->save_data();

		return $return;
	}

	public function resolve_return_url( WC_Order $order ) {
		$order_received_url_filter_from = trim( $this->get_option( 'order_received_url_filter_from',
			'' ) );
		$order_received_url_filter_to   = trim( $this->get_option( 'order_received_url_filter_to',
			'' ) );
		$return_url                     = $this->get_return_url( $order );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[resolve_return_url] [original return URL: %s]',
				$return_url,
			) );

		if ( $order_received_url_filter_from !== '' ) {

			$return = str_replace( $order_received_url_filter_from,
				$order_received_url_filter_to,
				$return_url );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[resolve_return_url] [order_received_url_filter_from: %s] [order_received_url_filter_to: %s] [result: %s]',
					$order_received_url_filter_from,
					$order_received_url_filter_to,
					$return
				) );

			return $return;
		} else {
			return $return_url;
		}
	}

	private function is_blik_0_code_valid( string $code ): bool {

		return strlen( $code ) === 6 && ctype_digit( $code );
	}

	public function decode_continue_transaction_response( $response_xml ) {
		$xml = simplexml_load_string( $response_xml );

		if ( ! $xml ) {
			return [];
		}

		if ( isset( $xml->confirmation ) && (string) $xml->confirmation === 'NOTCONFIRMED' ) {
			return [
				'confirmation' => (string) $xml->confirmation,
				'reason'       => (string) $xml->reason,
				'hash'         => (string) $xml->hash,
			];
		} else {
			return [
				'status'      => (string) $xml->status,
				'redirecturl' => (string) $xml->redirecturl,
				'hash'        => (string) $xml->hash,
			];
		}
	}


	private function process_blik_0_payment(
		WC_Order $order,
		string $blik_authorization_code,
		bool $block_payment = false
	) {
		if ( ! $block_payment ) {
			add_filter( 'woocommerce_get_checkout_order_received_url',
				function ( $redirect_url, WC_Order $order ) {
					WC()->session->set( 'bm_original_order_received_url',
						$redirect_url );

					$order->add_meta_data( 'autopay_original_order_received_url',
						$redirect_url );
					$order->save_meta_data();

					return '#';
				},
				10,
				2 );
		}

		WC()->session->set( 'bm_wc_order_id', $order->get_id() );
		WC()->session->save_data();


		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[process_blik_0_payment] [Order ID: %s] [block_payment: %s]',
				print_r( $order->get_id(), true ),
				print_r( $block_payment ? 'true' : 'false', true )
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
			$order->add_meta_data( 'bm_transaction_init_params',
				$params );
			$order->save_meta_data();

			$result = $this->decode_continue_transaction_response( $client->continue_transaction_request(
				$params,
				$this->gateway_url . 'payment'
			) );

			blue_media()->get_woocommerce_logger()->log_debug(
				sprintf( '[process_blik_0_payment] [continue_transaction_request] [params: %s] [result: %s]',
					print_r( $params, true ),
					print_r( $result, true ),
				) );

			if ( isset( $result['reason'] ) ) {
				throw new Exception( $result['reason'] );
			}

			if ( empty( $result ) || ! is_array( $result ) ) {
				throw new Exception( sprintf( 'Continue transaction response invalid format (%s)',
					serialize( $result ) ) );
			}

			WC()->session->set( 'bm_continue_transaction_start_error', '' );

			$this->update_order_status( $order, 'pending' );
			$order->add_order_note( __( 'Autopay: BLIK-0 payment process started for order ID:',
					'bm-woocommerce' ) . $order->get_id() );

		} catch ( Exception $e ) {

			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[continue_transaction_request] [Params: %s] [Error message: %s]',
					json_encode( $params ),
					$e->getMessage()
				) );

			WC()->session->set( 'bm_continue_transaction_start_error',
				__( 'Payment failed.',
					'bm-woocommerce' ) );

			$new_status = $this->get_option( 'wc_payment_status_on_bm_failure',
				'failed' );
			$this->update_order_status( $order,
				$new_status,
				'Autopay ITN: paymentStatus FAILURE' );
			$order->save();
			WC()->session->save_data();
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

	private function schedule_remove_unpaid_orders( int $order_id ) {
		$woocommerce_hold_stock_minutes = (int) get_option( 'woocommerce_hold_stock_minutes' );

		if ( $woocommerce_hold_stock_minutes > 0 ) {
			$woocommerce_hold_stock_minutes *= 60;
			if ( ! wp_next_scheduled( 'bm_cancel_failed_pending_order_after_one_hour',
				[ $order_id ] ) ) {
				wp_schedule_single_event( time() + $woocommerce_hold_stock_minutes,
					'bm_cancel_failed_pending_order_after_one_hour',
					[ $order_id ] );
			}
		}
	}

	/**
	 * @return void
	 */
	public function webhook() {
		do_action( 'bm_debugger' );

		add_action( 'woocommerce_api_wc_gateway_bluemedia', function () {
			if ( ob_get_level() ) {
				ob_clean();
			}

			try {
				if ( ! empty( $_POST ) ) {
					$posted                  = wp_unslash( $_POST );
					$posted_xml              = simplexml_load_string( base64_decode( $posted['transactions'] ) );
					$all_fields_itn          = [];
					$all_fields_reponse      = [];
					$order_success_to_update = [];
					$order_failure_to_update = [];
					$order_pending_to_update = [];


					blue_media()
						->get_woocommerce_logger( 'bm_woocommerce_itn' )
						->log_debug(
							'Transactions from ITN: ' . print_r( base64_decode( $posted['transactions'] ),
								true )
						);

					if ( preg_match( '/<currency>\s*(.*?)\s*<\/currency>/',
						base64_decode( $posted['transactions'] )
						,
						$matches )
					) {

						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf( '[webhook] [Transactions from ITN] [currency found: %s]',
									print_r( $matches[1], true ),
								) );

						blue_media()
							->get_currency_manager()
							->reconfigure( $matches[1] );
						$this->setup_variables();
					}

					$xw = xmlwriter_open_memory();
					xmlwriter_set_indent( $xw, 1 );
					$res = xmlwriter_set_indent_string( $xw, ' ' );

					xmlwriter_start_document( $xw, '1.0', 'UTF-8' );
					xmlwriter_start_element( $xw, 'confirmationList' );
					xmlwriter_start_element( $xw, 'serviceID' );
					xmlwriter_text( $xw, $this->service_id );
					xmlwriter_end_element( $xw ); // serviceID
					xmlwriter_start_element( $xw, 'transactionsConfirmations' );


					foreach (
						$posted_xml->xpath( '/transactionList/transactions/transaction' )
						as $transaction
					) {

						blue_media()->get_currency_manager()->reconfigure();
						$this->setup_variables();

						/**
						 * @var SimpleXMLElement $field
						 */
						foreach ( $transaction as $field ) {

							$fieldString = ( (string) $field );
							if ( ! empty( $field )
							) {
								if ( $field->getName() == 'customerData' ) {
									$customer_data_fields = (array) $field;
									foreach ( $customer_data_fields as $value ) {
										$all_fields_itn[] = $value;
									}
								} else {
									$all_fields_itn[] = $fieldString;
								}
							}
						}


						$wc_order_id        = (int) (string) $transaction->orderID;
						$bm_order_status    = (string) $transaction->paymentStatus;
						$bm_remote_id       = (string) $transaction->remoteID;
						$bm_currency_symbol = (string) $transaction->currency;

						blue_media()
							->get_currency_manager()
							->reconfigure( $bm_currency_symbol );
						$this->setup_variables();

						$order               = wc_get_order( $wc_order_id );
						$confirmation_result = '';

						if ( $order instanceof WC_Order ) {
							$init_params = $order->get_meta( 'bm_transaction_init_params' );
							if ( ! is_array( $init_params ) ) {
								$confirmation_result                = Order_Remote_Status_Manager::RESULT_CONFIRMED;
								$status_processing_allowed_in_store = false;
								blue_media()
									->get_woocommerce_logger( 'bm_woocommerce_itn' )
									->log_debug( '[webhook] [init params not found in order meta]' );

							}
						} else {
							$confirmation_result                = Order_Remote_Status_Manager::RESULT_CONFIRMED;
							$status_processing_allowed_in_store = false;
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [order not found]' );
						}


						if ( $confirmation_result === '' ) {
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [remote_status_manager - do update status]' );

							$remote_status_manager = blue_media()->get_order_remote_status_manager();
							$remote_status_manager->install_db_schema();


							$confirmation_result = $remote_status_manager->update_order_status( $wc_order_id,
								$bm_order_status );

							$status_processing_allowed_in_store = $remote_status_manager->is_status_processing_allowed_in_store();
						} else {
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug( '[webhook] [remote_status_manager - skip]' );
						}


						xmlwriter_start_element( $xw, 'transactionConfirmed' );
						xmlwriter_start_element( $xw, 'orderID' );
						xmlwriter_text( $xw, $wc_order_id );
						$all_fields_reponse[] = $wc_order_id;
						xmlwriter_end_element( $xw ); // orderID
						xmlwriter_start_element( $xw, 'confirmation' );
						xmlwriter_text( $xw, $confirmation_result );
						$all_fields_reponse[] = $confirmation_result;
						xmlwriter_end_element( $xw ); // confirmation
						xmlwriter_end_element( $xw ); // transactionConfirmed


						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug( sprintf( '[webhook] [%s]',
								print_r( [
									'order_id'                           => $wc_order_id,
									'ITN status'                         => $bm_order_status,
									'confirmation_result'                => $confirmation_result,
									'status_processing_allowed_in_store' => $status_processing_allowed_in_store ? 'yes' : 'no',
								], true )
							) );


						if ( $status_processing_allowed_in_store ) {
							$wc_order = wc_get_order( $wc_order_id );

							if ( self::ITN_SUCCESS_STATUS_ID === $bm_order_status ) {
								$order_success_to_update[ $bm_remote_id ] = $wc_order;
							}

							if ( self::ITN_PENDING_STATUS_ID === $bm_order_status ) {
								$order_pending_to_update[ $bm_remote_id ] = $wc_order;
							}

							if ( self::ITN_FAILURE_STATUS_ID === $bm_order_status ) {
								$order_failure_to_update[ $bm_remote_id ] = $wc_order;
							}
						}
					}

					$hash_from_itn = $posted_xml->xpath( '/transactionList/hash' );
					$hash_from_itn = (string) $hash_from_itn[0];

					$is_hash_valid = $this->validate_itn_hash( $all_fields_itn,
						$hash_from_itn );

					if ( ! $is_hash_valid && ! $this->testmode ) {
						$this->testmode = true;
						$this->setup_variables();
						$is_hash_valid  = $this->validate_itn_hash( $all_fields_itn,
							$hash_from_itn );
						$this->testmode = false;
						$this->setup_variables();
					}

					if ( ! $is_hash_valid ) {

						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf( '[webhook] [validate_itn_hash - not valid] [fields_itn: %s] [Hash: %s]',
									print_r( $all_fields_itn, true ),
									$hash_from_itn
								) );


						ob_start();
						header( 'HTTP/1.0 401 Unauthorized' );
						echo __( 'validate_itn_hash - not valid',
							'bm-woocommerce' );
						exit;
					}

					foreach ( $order_success_to_update as $k => $wc_order ) {
						$wc_order->add_meta_data( 'autopay_itn_received',
							'SUCCESS' );
						$autopay_order = ( new Autopay_Order_Factory() )->create_by_wc_order( $wc_order );
						if ( $autopay_order->is_order_only_virtual() ) {
							blue_media()
								->get_woocommerce_logger( 'bm_woocommerce_itn' )
								->log_debug(
									sprintf( '[webhook] [is_order_only_virtual] returns true [Order_Id: %s]',
										$wc_order->get_id()
									) );

							$new_status = $this->get_option( 'wc_payment_status_on_bm_success_virtual',
								'completed' );
						} else {
							$new_status = $this->get_option( 'wc_payment_status_on_bm_success',
								'completed' );
						}

						$wc_order->payment_complete( $k );

						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf( '[webhook] [Status from ITN: SUCCESS] [Matched WC status: %s] [Order_Id: %s]',
									$new_status,
									$wc_order->get_id()
								) );

						$this->update_order_status( $wc_order,
							$new_status,
							'Autopay ITN: paymentStatus SUCCESS' );


						$wc_order->update_meta_data( 'bm_order_itn_status',
							self::ITN_SUCCESS_STATUS_ID );
						$wc_order->save_meta_data();

						do_action( 'woocommerce_payment_complete',
							$wc_order->get_id(),
							'' );


					}

					foreach ( $order_pending_to_update as $k => $wc_order ) {
						$wc_order->add_meta_data( 'autopay_itn_received',
							'PENDING' );
						$new_status = $this->get_option( 'wc_payment_status_on_bm_pending',
							'pending' );
						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf( '[webhook] [Status from ITN: PENDING] [Matched WC status: %s] [Order_Id: %s]',
									$new_status,
									$wc_order->get_id()
								) );

						$this->update_order_status( $wc_order,
							$new_status,
							'Autopay ITN: paymentStatus PENDING' );


						$wc_order->update_meta_data( 'bm_order_itn_status',
							self::ITN_PENDING_STATUS_ID );
						$wc_order->save_meta_data();
					}

					foreach ( $order_failure_to_update as $k => $wc_order ) {
						$wc_order->add_meta_data( 'autopay_itn_received',
							'FAILURE' );
						$new_status = $this->get_option( 'wc_payment_status_on_bm_failure',
							'failed' );
						blue_media()
							->get_woocommerce_logger( 'bm_woocommerce_itn' )
							->log_debug(
								sprintf( '[webhook] [Status from ITN: FAILURE] [Matched WC status: %s] [Order_Id: %s]',
									$new_status,
									$wc_order->get_id()
								) );

						$this->update_order_status( $wc_order,
							$new_status,
							'Autopay ITN: paymentStatus FAILURE' );

						$wc_order->update_meta_data( 'bm_order_itn_status',
							self::ITN_FAILURE_STATUS_ID );
						$wc_order->save_meta_data();
					}


					xmlwriter_end_element( $xw ); // transactionsConfirmations
					xmlwriter_start_element( $xw, 'hash' );
					xmlwriter_text( $xw,
						$this->generate_response_xml_hash( $all_fields_reponse ) );
					xmlwriter_end_element( $xw ); // hash
					xmlwriter_end_document( $xw );

					$xml_response = xmlwriter_output_memory( $xw );
					blue_media()
						->get_woocommerce_logger( 'bm_woocommerce_itn' )
						->log_debug(
							sprintf( '[webhook xml_response] [xml: %s]',
								$xml_response
							) );

					echo $xml_response;

					exit;//exit with 200
				}
			} catch ( Exception $e ) {
				blue_media()
					->get_woocommerce_logger( 'bm_woocommerce_itn' )
					->log_error(
						sprintf( '[Webhook exception debug] [message: %s] [Post data: %s]',
							json_encode( $e->getMessage() ),
							json_encode( $_POST )
						) );

				die( 'Message: ' . $e->getMessage() . ' Code: ' . $e->getCode() );
			}
		} );

	}

	/**
	 * @param array $all_fields_reponse
	 *
	 * @return string
	 */
	private function generate_response_xml_hash( array $all_fields_reponse
	): string {
		array_unshift( $all_fields_reponse, $this->service_id );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[generate_response_xml_hash] [$all_fields_reponse %s]',
				print_r( $all_fields_reponse, true )
			) );

		return $this->hash_transaction_parameters( $all_fields_reponse );
	}

	/**
	 * @param array $transactions_from_itn
	 * @param $hash_from_itn
	 *
	 * @return bool
	 */
	private function validate_itn_hash(
		array $transactions_from_itn,
		$hash_from_itn
	): bool {
		array_unshift( $transactions_from_itn,
			$this->service_id );
		$itn_values_based_hash = $this->hash_transaction_parameters( $transactions_from_itn );

		return $hash_from_itn === $itn_values_based_hash;
	}

	/**
	 * @param WC_Order $wc_order
	 * @param int $payment_channel
	 *
	 * @return array
	 * @throws Exception
	 */
	private
	function prepare_initial_transaction_parameters(
		WC_Order $wc_order,
		int $payment_channel = 0
	): array {
		$price = $this->get_price_for_api_request( $wc_order );

		$params = [
			'ServiceID'             => $this->service_id,
			'OrderID'               => $wc_order->get_id(),
			'Amount'                => $price,
			'GatewayID'             => $payment_channel,
			'Currency'              => blue_media()->resolve_blue_media_currency_symbol(),
			'CustomerEmail'         => $wc_order->get_billing_email(),
			'PlatformName'          => 'Woocommerce',
			'PlatformVersion'       => WC_VERSION,
			'PlatformPluginVersion' => blue_media()->get_plugin_version(),
		];

		$params_hash = $this->hash_transaction_parameters(
			$params
		);

		return array_merge( $params, [ 'Hash' => $params_hash ] );
	}

	/**
	 * @param array $params
	 *
	 * @return string
	 * @throws Exception
	 */
	public
	function hash_transaction_parameters(
		array $params
	): string {
		$private_key_secured     = $this->secure_private_key( $this->get_private_key() );
		$imploded_string_secured = implode( '|',
				$params ) . '|' . $private_key_secured;
		$imploded_string         = implode( '|', $params ) . '|'
								   . $this->get_private_key();

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[hash_parameters] %s',
				$imploded_string_secured
			) );

		return hash( 'sha256', $imploded_string );
	}

	private function secure_private_key( string $key ): string {
		$length = strlen( $key );
		if ( $length <= 4 ) {
			return $key;
		}

		$hiddenPart  = substr( $key, 0, $length - 4 );
		$hiddenPart  = str_repeat( '*', strlen( $hiddenPart ) );
		$visiblePart = substr( $key, - 4 );

		return $hiddenPart . $visiblePart;
	}

	/**
	 * @return string
	 */
	public
	function get_private_key() {
		return $this->private_key;
	}

	/**
	 * @throws Exception
	 */
	public
	function gateway_list(
		$force_rebuild_cache = false,
		?string $currency_code = null
	): array {
		$currency_code     = esc_attr( $currency_code ?: blue_media()->resolve_blue_media_currency_symbol() );
		$currency_code_opt = strtolower( $currency_code );
		if ( defined( 'BLUE_MEDIA_DISABLE_CACHE' ) || $force_rebuild_cache || time()
																			  - (int) get_option( 'bm_gateway_list_cache_time' )
																			  > 600//10 minutes cache
		) {
			$gateway_list_cache = $this->api_get_gateway_list( $currency_code );

			if ( ! $this->resolve_is_test_mode() ) {
				update_option( "bm_gateway_list_cache_$currency_code_opt",
					$gateway_list_cache );
				update_option( "bm_gateway_list_cache_time_$currency_code_opt",
					time() );
			}

		} else {
			$gateway_list_cache = get_option( "bm_gateway_list_cache_$currency_code_opt" );
			if ( empty( $gateway_list_cache ) ) {
				$gateway_list_cache = $this->api_get_gateway_list( $currency_code );
				update_option( "bm_gateway_list_cache_$currency_code_opt",
					$gateway_list_cache );
				update_option( "bm_gateway_list_cache_time_$currency_code_opt",
					time() );
			}
		}

		return $gateway_list_cache;
	}

	/**
	 * @throws Exception
	 */
	private
	function api_get_gateway_list(
		?string $currency_code = null

	): ?array {
		$service_id = $this->service_id;
		$message_id = substr( bin2hex( random_bytes( 32 ) ), 32 );
		$currencies = $currency_code ?: blue_media()->resolve_blue_media_currency_symbol();

		$params = [
			'ServiceID'  => $service_id,
			'MessageID'  => $message_id,
			'Currencies' => $currencies,
		];


		$params_hash = $this->hash_transaction_parameters(
			$params
		);

		$params = array_merge( $params, [ 'Hash' => $params_hash ] );

		$url = $this->gateway_url_not_modified_by_user . 'gatewayList/v2';

		$wp_remote_post_args = [
			'headers' => [
				'content-type' => 'application/json',
			],
			'body'    => json_encode( $params ),
		];

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[api_get_gateway_list request] [url: %s] [args: %s]',
				$url,
				print_r( $wp_remote_post_args, true )
			) );


		$result = wp_remote_post(
			$url,
			$wp_remote_post_args
		);


		if ( is_wp_error( $result ) ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[gatewayList/v2] [error message: %s]',
					$result->get_error_message()
				) );
		}

		$result_decoded = json_decode( wp_remote_retrieve_body( $result ) );


		if ( is_object( $result_decoded )
			 && property_exists( $result_decoded,
				'result' )
			 && $result_decoded->result === 'ERROR' ) {

			blue_media()->get_woocommerce_logger()->log_error( $message =
				sprintf( '[gatewayList/v2] [URL: %s] [Error: %s]',
					$url,
					print_r( $result_decoded, true )
				) );

			throw new Exception( $message );
		}

		if ( is_object( $result_decoded ) && property_exists( $result_decoded,
				'gatewayList' ) ) {

			if ( empty( $result_decoded->gatewayList ) ) {
				blue_media()->get_woocommerce_logger()->log_error( $message =
					sprintf( '[gatewayList/v2] [URL: %s] [Empty results: %s]',
						$url,
						serialize( $result_decoded )
					) );

				throw new Exception( $message );
			}

			blue_media()->get_woocommerce_logger( 'paywall_v3' )->log_debug(
				sprintf( '[api_get_gateway_list] [%s]',
					print_r(
						[
							'result_decoded' => $result_decoded,
						]
						, true ) )
			);


			return $result_decoded->gatewayList;
		}

		blue_media()->get_woocommerce_logger()->log_error( $message =
			sprintf( '[gatewayList/v2] [URL: %s] [Failed decode results: %s]',
				$url,
				serialize( $result )
			) );
		throw new Exception( $message );
	}

	/**
	 * @param array $channels
	 *
	 * @return void
	 * @throws Exception
	 */
	public
	function render_channels(
		array $channels
	) {


		$group_arr = ( new Group_Mapper( $channels ) )->map();

		$payment_names = [];
		foreach ( $group_arr as $group ) {
			$payment_names[] = $group->get_name();
		}

		echo '<div class="payment_box payment_method_bacs">';
		echo '<p>' . __( implode( ', ', $payment_names ),
				'bm-woocommerce' ) . '</p>';
		echo '<p>' . __( 'Select the payment method you want to use.',
				'bm-woocommerce' ) . '</p>';
		echo '</div>';
		echo '<div class="payment_box payment_method_bacs">';
		echo '<div class="bm-payment-channels-wrapper">';
		printf( '<ul id="shipping_method" class="woocommerce-shipping-methods bm-%s">',
			rand( 0, 1000 ) );

		/**
		 * @var Group[] $group_arr
		 */
		foreach ( $group_arr as $group ) {
			$expandable_Group = $group instanceof Expandable_Group;

			if ( empty( $group->get_items() ) ) {
				continue;
			}

			printf( "<div class='bm-group-%s%s'><li><ul>",
				$group->get_slug(),
				$expandable_Group ? ' bm-group-expandable' : '' );


			if ( $expandable_Group ) {
				// add radio before "PRZELEW INTERNETOWY" logo to add possibility
				// show-hide list of banks
				printf( '<li class="bm-payment-channel-group-item">
							<label for="bm-gateway-bank-group">
								<input type="radio" name="bm-payment-channel-group" id="bm-gateway-bank-group" >
                                <img src="%s" class="bm-payment-channel-group-method-logo">
								<p class="bm-payment-channel-group-method-name">%s</p>
							</label>
							<span class="bm-payment-channel-method-desc">
							<span>
							<span class="payment-method-description">%s</span>
							</span>
                        </span>
						</li>',
					$group->get_icon(),
					$group->get_name(),
					$group->get_subtitle()
				);

				echo '<div class="bm-group-expandable-wrapper">';
			}


			foreach ( $group->get_items() as $item ) {
				printf( '<li class="bm-payment-channel-item %s">
							<label class="bm-payment-channel-label" for="bm-gateway-id-%s">
								<input type="radio" name="bm-payment-channel" onclick="addCurrentClass(this)" data-index="0" id="bm-gateway-id-%s" value="%s" class="%s">
								<img src="%s" class="bm-payment-channel-method-logo">
								<p class="bm-payment-channel-method-name">%s</p>
							</label>
							<span class="bm-payment-channel-method-desc">%s</span>
                        </li>',
					(string) $item->get_class(),
					$item->get_id(),
					$item->get_id(),
					$item->get_id(),
					$expandable_Group ? 'bm-payment-channel-group-in-group' : '',
					$item->get_icon(),
					$item->get_name(),
					$item->get_description()
				);
				$script = $item->get_script();
				if ( $script ) {
					echo $item->get_script();
				}
			}
			if ( $expandable_Group ) {
				echo '</div>';
			}
			printf( "</li></ul></div>" );


		}

		echo '</ul></div>';

		echo '</div>';

		?>

		<script>
			<?php if ('yes' === $this->get_option( 'compatibility_with_live_update_checkout',
				'no' )):?>
			BmTimerValue = 1500;
			<?php else:?>
			BmTimerValue = 0;
			<?php endif;?>

			jQuery(document).ready(function () {
				clearTimeout(bm_global_timer)

				var isBlueMediaSelected = jQuery('#payment_method_bluemedia').is(':checked');

				if (isBlueMediaSelected) {
					BmDeactivateNewOrderButton()
				}

				blueMediaRadioHide();

				bm_global_timer = setTimeout(function () {
					bm_global_update_checkout_in_progress = 0;
					blueMediaRadioTest();
				}, BmTimerValue);

			});

			jQuery("input[name='payment_method']").on("click touchstart", function () {
				var radioButtons = jQuery("input[name='payment_method']");
				for (var i = 0; i < radioButtons.length; i++) {
					if (radioButtons[i].checked && radioButtons[i].id !== "payment_method_bluemedia") {
						BmActivateNewOrderButton()
						BmDeselectGroupedLi()
					}
				}

				jQuery("input[id='payment_method_bluemedia']").on("click", function () {
					jQuery(".payment_box").find("input[type='radio']").prop("checked", false);
					jQuery(".payment_box").find("li").removeClass("selected");
					BmDeactivateNewOrderButton()
				});

				clearTimeout(bm_global_timer);
				bm_global_timer = setTimeout(function () {

					if (0 === bm_global_update_checkout_in_progress) {
						console.log('blueMediaRadioTest bm_global_update_checkout_in_progress ' + bm_global_update_checkout_in_progress)
						blueMediaRadioTest();
					}
				}, BmTimerValue);

				jQuery('#payment_method_bluemedia').on('click', function () {

					clearTimeout(bm_global_timer);
					bm_global_timer = setTimeout(function () {

						if (0 === bm_global_update_checkout_in_progress) {
							console.log('click bm_global_update_checkout_in_progress ' + bm_global_update_checkout_in_progress)
							blueMediaRadioShow();
						}
					}, BmTimerValue);

				});

				jQuery('ul.wc_payment_methods > li.wc_payment_method:not(.payment_method_bluemedia)').on('click', function () {
					blueMediaRadioHide();
				});
			});

		</script><?php
	}


	public
	function render_channels_for_admin_panel(
		array $channels
	) {
		$group_arr = ( new Group_Mapper( $channels ) )->map_for_admin_panel();

		echo '<ul id="shipping_method" class="woocommerce-shipping-methods payment_box payment_box_wpadmin payment_method_bacs bm-payment-channels__wrapper">';

		/**
		 * @var Group[] $group_arr
		 */
		foreach ( $group_arr as $group ) {

			$expandable_Group = $group instanceof Expandable_Group;

			if ( empty( $group->get_items() ) ) {
				continue;
			}

			printf( "<li class='bm-payment-channel bm-group-%s%s'><ul class='bm-payment-channel__wrapper'>",
				$group->get_slug(),
				$expandable_Group ? ' bm-group-expandable' : '' );

			if ( $expandable_Group ) {
				printf( "<p class='bm-group-name'>%s</p>",
					$group->get_name() );
			}

			foreach ( $group->get_items() as $item ) {
				printf( '<li class="bm-payment-channel__item %s"><img class="bm-payment-channel__logo" src="%s"><p class="bm-payment-channel__desc %s">%s</p></li>',
					(string) $item->get_class(),
					$item->get_icon(),
					$expandable_Group ? 'bm-inside-expandable-group' : 'bm-inside-single-item',
					$item->get_name(),
				);
			}

			printf( "</li></ul>" );


		}

		echo '</ul>';
	}

	/**
	 * Reposition an array element by its key.
	 *
	 * @param array $array The array being reordered.
	 * @param string|int $key They key of the element you want to reposition.
	 * @param int $order The position in the array you want to move the element
	 *     to. (0 is first)
	 *
	 * @throws \Exception
	 */
	private function repositionArrayElement(
		array &$array,
		$key,
		int $order
	): void {
		if ( ( $a = array_search( $key, array_keys( $array ) ) ) === false ) {
			throw new Exception( "The {$key} cannot be found in the given array." );
		}
		$p1    = array_splice( $array, $a, 1 );
		$p2    = array_splice( $array, 0, $order );
		$array = array_merge( $p2, $p1, $array );
	}

	public function update_order_status(
		WC_Order $order,
		string $new_status,
		string $note = ''
	): bool {
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[update status to: %s] [Order id: %s]',
				$new_status,
				$order->get_id()
			) );

		$result = $order->update_status( $new_status, $note );

		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[update status result: %s] [Order id: %s] [Status: %s]',
				$result ? 'true' : 'false',
				$order->get_id(),
				$new_status
			) );

		return $result;
	}

	public function admin_options() {
		$this->settings_manager->render_settings(
			$this->generate_settings_html( $this->get_form_fields(), false )
		);
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
}
