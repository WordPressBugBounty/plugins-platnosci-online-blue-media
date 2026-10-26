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
use Ilabs\BM_Woocommerce\Gateway\Continue_Transaction\Pretransaction_Response_Result;
use Ilabs\BM_Woocommerce\Gateway\Continue_Transaction\Pretransaction_Response_Validator;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Attempt_State_Store;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Manual_Renewal_Eligibility;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Manual_Renewal_Payment_Handler;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Context;
use Ilabs\BM_Woocommerce\Domain\Service\Gateway_List\Gateway_List_Recurring_Filter;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Logger;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Recurring_Result_Applier;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Renewal_Attempt_Lifecycle;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Renewal_Payment_Handler;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Recurring_Notification_Dispatcher;

class Blue_Media_Gateway extends WC_Payment_Gateway {

	const GATEWAY_PRODUCTION = 'https://pay.autopay.eu/';

	const GATEWAY_SANDBOX = 'https://testpay.autopay.eu/';

	const BLIK_0_CHANNEL = 509;

	const APPLE_PAY_CHANNEL = 1513;

	const GPAY_CHANNEL = 1512;

	const CARD_CHANNEL = 1500;

	/**
	 * Card recurring activation channel (etap 1). Routed through the same
	 * redirect mechanism as BLIK/PBL (see the default branch of process_payment()),
	 * not through Card_Widget_Payment_Service.
	 */
	const CARD_RECURRING_CHANNEL = 1503;

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
	 * Classifies orders/carts for card recurring (etap 1).
	 *
	 * @var Recurring_Context
	 */
	private Recurring_Context $recurring_context;

	/**
	 * @var ITN_Webhook_Handler|null
	 */
	private $itn_webhook_handler;

	/**
	 * Routes RPAN/RPDN notifications away from the ITN entry point.
	 *
	 * @var Recurring_Notification_Dispatcher|null
	 */
	private $recurring_notification_dispatcher;

	/**
	 * Handles payment redirect decisions and URL resolution.
	 *
	 * @var Payment_Redirect_Handler
	 */
	protected Payment_Redirect_Handler $payment_redirect_handler;

	/**
	 * Handles automatic card-recurring renewal charges.
	 *
	 * @var Renewal_Payment_Handler|null
	 */
	private $renewal_payment_handler;

	/**
	 * Handles manual (RecurringAction=MANUAL) card-recurring renewal charges from
	 * order-pay.
	 *
	 * @var Manual_Renewal_Payment_Handler|null
	 */
	private $manual_renewal_payment_handler;

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
			// 'subscriptions' is a CANDIDATE here, not a truth value: the overridden
			// supports() below decides the real boolean dynamically. It must still be
			// listed in this array because WC_Gateway_Autopay_Blocks_Support::
			// get_payment_method_data() builds the JS payload via
			// array_filter( $this->supports, [ $this, 'supports' ] ) — a feature absent
			// from this array is never even tested, regardless of what supports()
			// would return for it -- this was missing, which silently made classic
			// checkout and Blocks disagree on capability.
			'subscriptions',
			// Same reasoning as 'subscriptions' above -- listed as a candidate only,
			// real boolean decided dynamically below. Flexible Subscriptions'
			// AvailableGateways::is_failed_automatic_renewal_payment() filters
			// woocommerce_available_payment_gateways down to gateways supporting THIS
			// exact feature (not 'subscriptions') on the order-pay page of a failed
			// automatic renewal -- without it listed here AND handled below, this
			// gateway is silently removed from that page entirely. Currently a
			// no-op for the Blocks payload specifically: order-pay in this project is
			// classic-only today, so get_payment_method_data() never renders
			// for this context yet -- kept here only so classic and Blocks cannot
			// silently disagree if Blocks order-pay support is added later.
			'subscription_payment_method_change_customer',
		];

		$this->recurring_context = new Recurring_Context( $this );

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

					$raw_order_id = (string) ( $params['OrderID'] ?? '' );
					$attempt_ref  = Attempt_State_Store::parse_order_id( $raw_order_id );
					$order_id     = null !== $attempt_ref ? $attempt_ref['renewal_order_id'] : (int) $raw_order_id;

					if ( $this->payment_redirect_handler->can_redirect_to_payment_gateway( $order_id ) ) {
						// For a card-recurring per-attempt OrderID,
						// verify this is still the exact, current, unsent attempt and
						// atomically transition it to SENT (the real "hand-off to
						// Autopay" boundary) before ever exposing its parameters to the
						// browser. A revisited/old URL for a closed or superseded attempt
						// must never re-initiate it. Ordinary orders ($attempt_ref ===
						// null) are completely unaffected -- $ready_to_forward stays true.
						$ready_to_forward = true;

						if ( null !== $attempt_ref ) {
							$attempt_store   = new Attempt_State_Store();
							$current_attempt = $attempt_store->get_current_attempt( $order_id );

							$ready_to_forward = null !== $current_attempt
								&& (int) ( $current_attempt['seq'] ?? -1 ) === $attempt_ref['seq']
								&& $attempt_store->mark_sent( $order_id, $attempt_ref['seq'] );
						}

						if ( ! $ready_to_forward ) {
							WC()->session->set( 'bm_order_payment_params', null );
							Session_Bridge::save();

							blue_media()->get_woocommerce_logger()->log_debug(
								sprintf( '[Print payment form canceled. Stale or already-closed recurring attempt.] [OrderID: %s]', $raw_order_id )
							);

							$stale_order = wc_get_order( $order_id );
							wp_safe_redirect( $stale_order instanceof WC_Order ? $this->get_return_url( $stale_order ) : wc_get_page_permalink( 'shop' ) );
							// phpcs:ignore PHPCompatibility.FunctionDeclarations.RemovedCallingDestructAfterConstructorExit.NeedsInspection -- no __destruct() exists on this class or on its WC_Payment_Gateway/WC_Settings_API ancestors, so PHP 8's "destructor skipped after exit() in constructor" change has nothing to skip.
							exit;
						}

						WC()->session->set( 'bm_order_payment_params', null );
						Session_Bridge::save();
						$order = wc_get_order( $order_id );
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

						$is_recurring_transport = is_array( $params ) && array_key_exists( Autopay_Payment_Protocol::FIELD_RECURRING_ACTION, $params );

						if ( $is_recurring_transport ) {
							( new Recurring_Logger() )->debug( 'read_before_form', [
								'order_id'                 => $order_id,
								'GatewayID'                => $params['GatewayID'] ?? null,
								'field_names'              => array_keys( $params ),
								'field_count'              => count( $params ),
								'RecurringAcceptanceState' => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE ] ?? null,
								'RecurringAction'          => $params[ Autopay_Payment_Protocol::FIELD_RECURRING_ACTION ] ?? null,
								'RecurringAcceptanceID_present' => array_key_exists( 'RecurringAcceptanceID', $params ),
							] );
						}

						if ( is_array( $params ) ) {
							// This used to be a hardcoded list of exactly the 9 base
							// fields (+ Hash) that Transaction_Request_Builder::build() has
							// always produced for BLIK/PBL/GPay (which pass $recurring_params
							// = null). Recurring card activation started merging RecurringAcceptanceState/
							// RecurringAction into that SAME $params array (and into the Hash
							// computed over it, see Transaction_Request_Builder::hash()) for
							// channel 1503 activation -- but this template was never updated,
							// so those two fields were silently dropped from the actual POST
							// while still being part of the Hash Autopay must recompute from
							// what it received. Autopay's hash verification for those requests
							// could never match, rejecting the transaction outright before it
							// is ever created on their side (generic error page, no visible
							// transaction record) -- confirmed via the request log's
							// `[hash_parameters] [fields_count: 11]` (9 base + the 2 recurring
							// fields) not matching this form's 9 hardcoded inputs. Looping over
							// every key in $params instead removes the entire class of bug: any
							// field the builder adds is now always sent, whatever it is named.
							$hidden_inputs = '';
							foreach ( $params as $field_name => $field_value ) {
								$hidden_inputs .= sprintf(
									"\n\t\t\t <input type='hidden' name='%s' value='%s' />",
									esc_attr( (string) $field_name ),
									esc_attr( (string) $field_value )
								);
							}

							if ( $is_recurring_transport ) {
								( new Recurring_Logger() )->debug( 'rendered_form_fields', [
									'order_id'            => $order_id,
									'field_names'         => array_keys( $params ),
									'field_count'         => count( $params ),
									'has_RecurringAcceptanceState' => array_key_exists( Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE, $params ),
									'has_RecurringAction' => array_key_exists( Autopay_Payment_Protocol::FIELD_RECURRING_ACTION, $params ),
									'has_RecurringAcceptanceID' => array_key_exists( 'RecurringAcceptanceID', $params ),
									'transport'           => 'auto_submit_form',
									'http_method'         => 'POST',
									'endpoint'            => $this->express_payment_redirect_url,
								] );
							}

							printf(
								"<form method='post' id='paymentForm' action='%s'>%s\n\t\t\t</form>",
								esc_url( $this->express_payment_redirect_url ),
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden_inputs is assembled above from esc_attr()-escaped field name/value pairs plus static markup; nothing unescaped reaches output here.
								$hidden_inputs
							);
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

						// phpcs:ignore PHPCompatibility.FunctionDeclarations.RemovedCallingDestructAfterConstructorExit.NeedsInspection -- no __destruct() exists on this class or on its WC_Payment_Gateway/WC_Settings_API ancestors, so PHP 8's "destructor skipped after exit() in constructor" change has nothing to skip.
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
		$this->recurring_notification_dispatcher->register();
		// Safe here (unlike Renewal_Status_Check_Job/Renewal_Attempt_Sweep, registered
		// in Plugin::before_init() instead): Flexible Subscriptions' own
		// AutomaticRequest::request_payment() calls WC()->payment_gateways() itself
		// before firing this hook, guaranteeing this gateway (and this registration)
		// already exists.
		$this->renewal_payment_handler->register();
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
		if ( ! isset( $this->recurring_notification_dispatcher ) ) {
			$this->recurring_notification_dispatcher = new Recurring_Notification_Dispatcher( $this );
		}
		if ( ! isset( $this->payment_redirect_handler ) ) {
			$this->payment_redirect_handler = new Payment_Redirect_Handler( $this );
		}
		if ( ! isset( $this->renewal_payment_handler ) ) {
			$this->renewal_payment_handler = new Renewal_Payment_Handler();
		}
		if ( ! isset( $this->manual_renewal_payment_handler ) ) {
			$this->manual_renewal_payment_handler = new Manual_Renewal_Payment_Handler( $this );
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

	/**
	 * Dynamic override for two feature keys:
	 *
	 * - `subscriptions` reflects whether card recurring is actually ready (Flexible
	 *   Subscriptions active + feature switch enabled), not a static declaration.
	 *   Flexible Subscriptions reads this exactly once, when a subscription is created
	 *   (`CartSubscriptionStrategy::requires_manual_renewal()`), so this only affects
	 *   NEW activations — never already-active subscriptions' renewals, RPAN/RPDN, or
	 *   cancellation handling, which do not re-check `supports()`.
	 * - `subscription_payment_method_change_customer` reflects whether the CURRENT
	 *   front-end request's order-pay target is a Flexible Subscriptions renewal order
	 *   — re-evaluated on EVERY call, unlike `subscriptions` above. Flexible
	 *   Subscriptions' `AvailableGateways::is_failed_automatic_renewal_payment()`
	 *   re-checks this feature on every render of the order-pay page for a failed
	 *   automatic renewal, filtering `woocommerce_available_payment_gateways` down to
	 *   gateways declaring it; without this, the gateway is silently removed from that
	 *   page entirely, even though the manual-repay logic it leads to
	 *   (`Manual_Renewal_Eligibility`/`Manual_Renewal_Payment_Handler`) is otherwise
	 *   fully implemented.
	 *
	 * @param string $feature Feature key, e.g. 'products', 'subscriptions',
	 *                        'subscription_payment_method_change_customer'.
	 *
	 * @return bool
	 */
	public function supports( $feature ) {
		if ( 'subscriptions' === $feature ) {
			return $this->recurring_context->is_recurring_feature_enabled();
		}

		if ( 'subscription_payment_method_change_customer' === $feature ) {
			return Recurring_Context::RENEWAL_ORDER === $this->recurring_context->classify_current_request();
		}

		return parent::supports( $feature );
	}

	public function is_whitelabel_mode_enabled(): bool {
		$currency   = blue_media()->resolve_blue_media_currency_symbol();
		$option_key = Settings_Manager::get_currency_option_key( 'whitelabel',
			$currency );
		$whitelabel = apply_filters( 'autopay_filter_option_whitelabel',
			$this->get_option( $option_key, 'no' ) );

		return 'yes' === $whitelabel;
	}

	/**
	 * Exposes the gateway's `Recurring_Context` instance — used by
	 * `WC_Gateway_Autopay_Blocks_Support` and the Store API cart extension so both reuse
	 * the exact same classification the gateway itself relies on, instead of constructing
	 * a second instance.
	 *
	 * @return Recurring_Context
	 */
	public function get_recurring_context(): Recurring_Context {
		return $this->recurring_context;
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
		$order_pay_id     = absint( get_query_var( 'order-pay' ) );
		$order_pay_order  = $order_pay_id > 0 ? wc_get_order( $order_pay_id ) : false;
		$is_renewal_order = false;

		if ( $order_pay_order instanceof WC_Order ) {
			$is_renewal_order = Recurring_Context::RENEWAL_ORDER === $this->recurring_context->classify( $order_pay_order );
		}

		if ( $is_renewal_order ) {
			// No channel choice is offered when the saved
			// instrument will be charged -- it is always the instrument's own
			// channel, never whatever the customer would otherwise click. Merely
			// rendering this does not charge anything -- process_payment() only
			// runs after WC's own order-pay POST+nonce.
			if ( Manual_Renewal_Eligibility::evaluate( $order_pay_order )['eligible'] ) {
				echo '<p>' . esc_html__( 'This payment will be made with your saved payment method — clicking "Pay" will charge it.', 'platnosci-online-blue-media' ) . '</p>';
				echo '<input type="hidden" name="autopay_manual_renewal" value="1" />';

				do_action( 'autopay_after_payment_field' );

				return;
			}

			// Not eligible for MANUAL: intentionally fall through to the unfiltered
			// whitelabel/description branch below, exactly as before -- this is
			// the one-off repayment path (any ordinary channel). RENEWAL_ORDER
			// must never be passed to Gateway_List_Recurring_Filter or blocked by the
			// new UNSUPPORTED_SUBSCRIPTION_CART message; the branch below only computes
			// a recurring classification when it is NOT a renewal order.
		} else {
			// Fresh checkout, OR order-pay for a non-renewal order (a plain past-due
			// order, or a still-unpaid first-payment subscription order retried via
			// order-pay) -- both share the exact same rules, just from a
			// different data source (order vs. session cart).
			$recurring_classification = $order_pay_order instanceof WC_Order
				? $this->recurring_context->classify( $order_pay_order )
				: $this->recurring_context->classify_cart();

			if ( Recurring_Context::UNSUPPORTED_SUBSCRIPTION_CART === $recurring_classification ) {
				echo '<p>' . esc_html( Recurring_Context::get_unsupported_subscription_cart_message() ) . '</p>';
				do_action( 'autopay_after_payment_field' );

				return;
			}
		}

		if ( $this->is_whitelabel_mode_enabled() ) {
			$gpay_form_data = [];
			try {
				$gateway_list_data = $this->gateway_list();

				if ( empty( $gateway_list_data ) ) {
					throw new Exception( 'Gateway list data is empty.' );
				}

				$gateway_list_response = ( new Gateway_List_Response_Factory() )->create( $gateway_list_data );

				if ( ! $is_renewal_order && isset( $recurring_classification ) ) {
					// ORDINARY -> hide 1503; SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT -> only
					// 1503; the filter itself is a no-op for any other value. Applied
					// before render_channels_v3()'s own sorting.
					$gateway_list_response = Gateway_List_Recurring_Filter::filter( $gateway_list_response, $recurring_classification );
				}

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

		// Tracks whether the client sent ANY channel field at all -- distinct from a
		// parsed value of 0, which also results when a field is present but not a valid
		// non-negative integer string (e.g. a malformed/tampered request). Only a true
		// absence of every channel field below counts as "no local channel choice was
		// possible" for the recurring fallback further down -- a present but
		// unparsable value must never be silently treated the same as a legitimate
		// absence of choice.
		$channel_field_present = isset( $_POST['bm-payment-channel'] )
			|| isset( $_POST['autopay_numeric_channel_id'] )
			|| isset( $_POST['autopay_numeric_channel_Id'] );

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


		// Etap 1: recurring guard, evaluated before ANY channel routing below —
		// not only the card branch — so BLIK/GPay/PBL cannot bypass it for a cart/order
		// this gateway does not (yet) support for subscriptions.
		$recurring_classification = $this->recurring_context->classify( $order );

		if ( Recurring_Context::ORDINARY !== $recurring_classification ) {
			// Cart composition (subscription group / one-off item counts) is only
			// meaningful for a fresh-checkout classification -- a RENEWAL_ORDER's items
			// are Flexible Subscriptions' own recurring line items, not a customer cart,
			// and are diagnosed separately (Renewal_Payment_Handler/Manual_Renewal_Payment_Handler).
			$composition = Recurring_Context::RENEWAL_ORDER !== $recurring_classification
				? $this->recurring_context->describe_order_composition( $order )
				: null;

			( new Recurring_Logger() )->debug( 'recurring_detected', array_filter( [
				'order_id'                          => $order_id,
				'classification'                    => $recurring_classification,
				'gateway_id'                        => $payment_channel,
				'service_id'                        => $this->service_id,
				'currency'                          => $order->get_currency(),
				'amount'                            => $order->get_total(),
				'environment'                       => $this->testmode ? 'test' : 'production',
				'checkout_type'                     => $is_classic_checkout ? 'classic' : 'blocks',
				'subscription_group_count'          => $composition['subscription_group_count'] ?? null,
				'one_off_item_count'                => $composition['one_off_item_count'] ?? null,
				'unknown_or_unsupported_item_count' => $composition['unknown_or_unsupported_item_count'] ?? null,
			], static function ( $value ) {
				return null !== $value;
			} ) );
		}

		if ( Recurring_Context::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT === $recurring_classification
			&& 0 === $payment_channel
			&& ! $channel_field_present
		) {
			// No local channel choice existed to begin with (no whitelabel
			// radio was ever rendered in either checkout for this classification --
			// Blue_Media_Gateway::payment_fields() / WC_Gateway_Autopay_Blocks_Support::
			// get_payment_method_data() only ever offer channel 1503 here, never any
			// other). classify() already confirmed 1503's real, current availability for
			// this order's currency before returning SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT
			// -- this does not synthesise availability, it only supplies the channel
			// choice the customer had no UI to make. An explicitly POSTed, wrong channel
			// is a different case and is NOT touched here -- it still fails the check
			// below exactly as before.
			$payment_channel = self::CARD_RECURRING_CHANNEL;
		}

		if ( Recurring_Context::UNSUPPORTED_SUBSCRIPTION_CART === $recurring_classification ) {
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[process_payment] [recurring guard: rejected UNSUPPORTED_SUBSCRIPTION_CART] [Order id: %s]',
					$order_id,
				)
			);

			wc_add_notice(
				__( 'This order cannot be paid yet: this subscription is not eligible for automatic card payments in this store (e.g. more than one subscription in the order, a zero-amount first payment, or the recurring card channel not being configured for this currency). Please contact the store.',
					'platnosci-online-blue-media' ),
				'error'
			);

			return [ 'result' => 'failure' ];
		}

		if ( Recurring_Context::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT === $recurring_classification
			&& self::CARD_RECURRING_CHANNEL !== $payment_channel
		) {
			wc_add_notice(
				__( 'This subscription can only be paid with the recurring card payment method.',
					'platnosci-online-blue-media' ),
				'error'
			);

			return [ 'result' => 'failure' ];
		}

		if ( self::CARD_RECURRING_CHANNEL === $payment_channel
			&& Recurring_Context::SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT !== $recurring_classification
		) {
			// Covers RENEWAL_ORDER too: channel 1503 as a POSTed *channel choice* is
			// never a valid way to reach MANUAL -- MANUAL is reached below
			// purely from the order being a renewal order with an eligible instrument,
			// never from the customer selecting GatewayID 1503.
			blue_media()->get_woocommerce_logger()->log_warning(
				sprintf(
					'[process_payment] [recurring guard: rejected channel 1503 outside allowed context] [Order id: %s] [classification: %s]',
					$order_id,
					$recurring_classification,
				)
			);

			wc_add_notice(
				__( 'The selected payment channel is not available for this order.',
					'platnosci-online-blue-media' ),
				'error'
			);

			return [ 'result' => 'failure' ];
		}

		// Renewal-order-specific routing (MANUAL charge of the
		// saved instrument, or a protected one-off repayment through an ordinary
		// channel). Nothing here runs for a fresh checkout order.
		$renewal_order_id_override = null;
		$renewal_attempt_seq       = null;

		if ( Recurring_Context::RENEWAL_ORDER === $recurring_classification ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by WC's own order-pay nonce/order-key auth (WC_Form_Handler::pay_action()), verified before process_payment() runs.
			$manual_requested_in_form = isset( $_POST['autopay_manual_renewal'] );
			$fresh_eval               = Manual_Renewal_Eligibility::evaluate( $order );

			if ( $manual_requested_in_form !== $fresh_eval['eligible'] ) {
				// What was rendered no longer matches reality -- never silently switch
				// between charging the saved card and whatever ordinary channel the
				// customer chose. Ask for a fresh page instead of
				// guessing which one the customer actually intended.
				blue_media()->get_woocommerce_logger()->log_warning(
					sprintf(
						'[process_payment] [renewal payment conditions changed between render and submit] [Order id: %s] [rendered_manual: %s] [now_eligible: %s]',
						$order_id,
						$manual_requested_in_form ? 'yes' : 'no',
						$fresh_eval['eligible'] ? 'yes' : 'no'
					)
				);

				wc_add_notice(
					__( 'Payment conditions for this order have changed. Please refresh the page and try again.',
						'platnosci-online-blue-media' ),
					'error'
				);

				return [ 'result' => 'failure' ];
			}

			if ( $fresh_eval['eligible'] ) {
				return $this->manual_renewal_payment_handler->handle( $order );
			}

			// Not eligible for MANUAL -- an ordinary, one-off repayment through
			// whichever channel is selected below, fully protected by the same
			// Attempt_State_Store lock as AUTO/MANUAL.
			$amount_cents = $this->normalize_amount_to_cents_for_ordinary_repayment( $order );

			if ( null === $amount_cents ) {
				wc_add_notice(
					__( 'Payment could not be started. Please try again.', 'platnosci-online-blue-media' ),
					'error'
				);

				return [ 'result' => 'failure' ];
			}

			$ordinary_attempt = ( new Renewal_Attempt_Lifecycle() )->begin( $order, [
				'currency'       => $order->get_currency(),
				'service_id'     => (string) $this->service_id,
				'environment'    => $this->resolve_is_test_mode() ? 'test' : 'production',
				'source'         => 'ordinary',
				'gateway_id'     => $payment_channel,
				// GatewayID=0 means "Autopay's hosted page decides the channel" (no
				// whitelabel widget) -- the real, eventual GatewayID cannot be known in
				// advance, so ITN/recovery must not require it to match.
				'gateway_choice' => 0 === $payment_channel ? 'hosted' : 'explicit',
				'amount_cents'   => $amount_cents,
			] );

			if ( null === $ordinary_attempt ) {
				wc_add_notice(
					__( 'Payment for this order is already being processed. Please try again shortly.', 'platnosci-online-blue-media' ),
					'error'
				);

				return [ 'result' => 'failure' ];
			}

			$renewal_order_id_override = (string) $ordinary_attempt['order_id'];
			$renewal_attempt_seq       = (int) $ordinary_attempt['seq'];
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
				if ( null !== $renewal_order_id_override
					&& ! $this->mark_renewal_attempt_sent_or_abort( $order_id, $renewal_attempt_seq )
				) {
					return [ 'status' => 'failure' ];
				}

				$this->process_blik_0_payment( $order,
					$blik_code,
					$blik_0_block_payment,
					$renewal_order_id_override );
				$is_blik_0 = true;
			} else {
				if ( null !== $renewal_order_id_override ) {
					// Local rejection before any HTTP call -- safe to abandon outright.
					( new Attempt_State_Store() )->abort_unsent( $order_id, $renewal_attempt_seq, 'local_validation_failed' );
				}

				wc_add_notice( __( 'The code you provided is invalid. Code should be 6 digits.',
					'platnosci-online-blue-media' ),
					'error' );

				return [
					'status' => 'failure',
				];
			}
		} elseif ( self::CARD_CHANNEL === $payment_channel ) {
			if ( $is_classic_checkout ) {
				if ( null !== $renewal_order_id_override ) {
					if ( '' === $this->read_atp_card_payment_token_from_request() ) {
						( new Attempt_State_Store() )->abort_unsent( $order_id, $renewal_attempt_seq, 'local_validation_failed' );
						wc_add_notice(
							__( 'Card payment data is missing. Please complete the card form and try again.',
								'platnosci-online-blue-media' ),
							'error',
						);

						return [ 'status' => 'failure' ];
					}

					if ( ! $this->mark_renewal_attempt_sent_or_abort( $order_id, $renewal_attempt_seq ) ) {
						return [ 'status' => 'failure' ];
					}
				}

				try {
					$_3ds_redirect_url = $this->process_card_widget_payment( $order, $renewal_order_id_override );

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

					// mark_sent() already succeeded above -- never
					// abort_unsent() here. 'autopay_card_gateway_error' is
					// Card_Widget_Start_Result::REJECTED, a real (if unsigned) response
					// body from Autopay -- the same trust level this codebase already
					// gives that exact field for an ordinary card-1500 payment, so it is
					// resolved as a verified FAILURE. Every other known exception here
					// (token missing -- pre-empted above for the renewal case; transport
					// error; bad/empty response; untrusted redirect) is genuinely
					// uncertain and is left SENT for ITN/recovery.
					if ( null !== $renewal_order_id_override && 'autopay_card_gateway_error' === $exception->getMessage() ) {
						( new Recurring_Result_Applier() )->apply( $order_id, $renewal_attempt_seq, Attempt_State_Store::STATE_FAILURE, 'card_widget_rejected' );
					}

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
					if ( null !== $renewal_order_id_override ) {
						( new Attempt_State_Store() )->abort_unsent( $order_id, $renewal_attempt_seq, 'local_validation_failed' );
					}

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

				if ( null !== $renewal_order_id_override
					&& ! $this->mark_renewal_attempt_sent_or_abort( $order_id, $renewal_attempt_seq )
				) {
					return [ 'status' => 'failure' ];
				}

				try {
					$_3ds_redirect_url = $this->process_card_widget_payment( $order, $renewal_order_id_override );

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

					if ( null !== $renewal_order_id_override && 'autopay_card_gateway_error' === $exception->getMessage() ) {
						( new Recurring_Result_Applier() )->apply( $order_id, $renewal_attempt_seq, Attempt_State_Store::STATE_FAILURE, 'card_widget_rejected' );
					}

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

			if ( null !== $renewal_order_id_override ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only presence check on a Base64 payload; process_gpay_payment() re-reads, unslashes, and validates the same field.
				$gpay_token_present = isset( $_POST['atp_gpay_payment_token'] ) && is_string( $_POST['atp_gpay_payment_token'] ) && '' !== trim( (string) wp_unslash( $_POST['atp_gpay_payment_token'] ) );

				if ( ! $gpay_token_present ) {
					( new Attempt_State_Store() )->abort_unsent( $order_id, $renewal_attempt_seq, 'local_validation_failed' );

					return [ 'status' => 'failure' ];
				}

				if ( ! $this->mark_renewal_attempt_sent_or_abort( $order_id, $renewal_attempt_seq ) ) {
					return [ 'status' => 'failure' ];
				}
			}

			try {
				$_3ds_redirect_url = $this->process_gpay_payment( $order, null, $renewal_order_id_override );

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
				// process_gpay_payment() does not distinguish a
				// transport failure from a verified rejection (both collapse into its
				// own internal 'failed' status update) -- for a renewal-order attempt
				// this is treated conservatively as uncertain: mark_sent() already
				// succeeded above, never abort_unsent() here, and no FAILURE is
				// resolved from this exception alone. ITN/recovery own the real outcome.
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[process gpay payment failed] [Order id: %s] [Error: %s]',
						$order_id,
						$exception->getMessage(),
					) );


				return [
					'status' => 'failure',
				];
			}
		} elseif ( self::CARD_RECURRING_CHANNEL === $payment_channel ) {
			// 1503/INIT_WITH_PAYMENT goes through server-to-server Przedtransakcja
			// instead of falling into the generic auto-submit-form `else` branch
			// below. See process_card_recurring_init_via_pretransaction() docblock
			// for the rationale. Every other channel (BLIK/PBL/GPay-with-redirect)
			// is unaffected -- they still reach the `else` branch exactly as before,
			// which no longer special-cases 1503 at all (dead branch removed, since
			// this elseif now intercepts it first).
			try {
				$redirect_url = $this->process_card_recurring_init_via_pretransaction( $order, $payment_channel );
			} catch ( Exception $exception ) {
				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[process_card_recurring_init_via_pretransaction failed] [Order id: %s] [Error: %s]',
						$order_id,
						$exception->getMessage(),
					) );

				return [ 'result' => 'failure' ];
			}

			// Mirrors the unconditional call the shared tail below makes for every
			// other channel (schedules the 'bm_cancel_failed_pending_order_after_one_hour'
			// auto-cancel job) -- this branch returns before reaching that shared
			// code, so it is called explicitly here instead. Idempotent
			// (wp_next_scheduled() guard inside), matching CARD_CHANNEL/GPAY_CHANNEL
			// above, which likewise skip it on their own early-return failure path.
			$this->payment_redirect_handler->schedule_remove_unpaid_orders( $order_id );

			wc()->session->set( 'store_api_draft_order', 0 );
			WC()->cart->empty_cart();
			Session_Bridge::save();

			return [
				'result'   => 'success',
				'redirect' => $redirect_url,
			];
		} else {
			$recurring_params = null;

			$params = [
				'params' => $this->transaction_builder->build(
					wc_get_order( $order_id ),
					$payment_channel,
					$recurring_params,
					$renewal_order_id_override
				),
			];
			WC()->session->set( 'bm_order_payment_params', $params );
			Session_Bridge::save();

			// mark_sent() deliberately does NOT happen here -- the
			// real hand-off to Autopay is the auto-submitting form built by the
			// `?autopay_express_payment=1` handler in this class's constructor, not
			// this session write. That handler transitions the attempt to SENT
			// atomically, immediately before rendering the form.

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

			if ( is_array( $params['params'] ?? null )
				&& array_key_exists( Autopay_Payment_Protocol::FIELD_RECURRING_ACTION, $params['params'] )
			) {
				( new Recurring_Logger() )->debug( 'session_saved', [
					'order_id'                 => $order_id,
					'field_names'              => array_keys( $params['params'] ),
					'field_count'              => count( $params['params'] ),
					'RecurringAcceptanceState' => $params['params'][ Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE ] ?? null,
					'RecurringAction'          => $params['params'][ Autopay_Payment_Protocol::FIELD_RECURRING_ACTION ] ?? null,
				] );
			}


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
		?string $payment_token = null,
		?string $order_id_override = null
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
			'OrderID'       => $order_id_override ?? $order->get_id(),
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
	 * Initiate channel 1503 (SUPPORTED_SUBSCRIPTION_FIRST_PAYMENT) via
	 * server-to-server Przedtransakcja.
	 * `RecurringAction=INIT_WITH_PAYMENT` for a non-zero first payment; for a
	 * zero-amount first payment with a confirmed future non-zero renewal,
	 * `RecurringAction=INIT_WITH_REFUND` with a technical `Amount` instead (Autopay
	 * refunds it automatically). PLN only -- see
	 * `Autopay_Payment_Protocol::INIT_WITH_REFUND_SUPPORTED_CURRENCY`.
	 *
	 * Uses `Client::continue_transaction_request()`
	 * (`BmHeader: pay-bm-continue-transaction-url`) instead of the auto-submit
	 * browser form used by every other redirect channel. Mirrors the existing,
	 * working `process_gpay_payment()`/`Card_Widget_Payment_Service::start_pretransaction()`
	 * pattern for the same header/transport -- does not introduce a new HTTP
	 * mechanism, only routes this one channel/action combination through the
	 * one this plugin already uses for other card-rail transactions.
	 *
	 * Field set and hash algorithm are unchanged from the auto-submit-form
	 * variant: `Transaction_Request_Builder::build()` with the same shape of
	 * `$recurring_params` -- including the existing omission of
	 * `PlatformName`/`PlatformVersion`/`PlatformPluginVersion` for this exact
	 * call (see `Transaction_Request_Builder::build()`, `$recurring_params
	 * !== null` branch). Only the transport changes here.
	 *
	 * `RecurringAcceptanceState=ACCEPTED` (not `FORCE`): matches
	 * `pay-wp/src/WooCommerceGateway/SubscriptionGateway.php:23,212`, a
	 * separate, multi-merchant Autopay integration that sends this value
	 * unconditionally, without `RecurringAcceptanceID`, for every one of its
	 * own merchants/ServiceIDs. `FORCE`/`PROMPT` are documented as requiring
	 * a business arrangement with Autopay per ServiceID ("zależy od
	 * uzgodnień biznesowych") -- not something this plugin, distributed to
	 * many independent merchants, can assume any given store has. `ACCEPTED`
	 * carries the same caveat in the literal text of that same documentation
	 * note, so this is not a confirmed-safe default either -- it is the
	 * deliberate, single, non-conditional rule chosen (matching the one
	 * other real multi-merchant integration available to compare against),
	 * not a fallback tried after `FORCE` fails. Never switch this
	 * automatically based on a rejected payment.
	 *
	 * Response validation is delegated to {@see Pretransaction_Response_Validator}
	 * (dependency-free, unit-tested against the documented "Odpowiedź na
	 * Przedtransakcję" contract -- see `bin/test-pretransaction-response-validator.php`)
	 * rather than reimplemented inline here.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param int      $payment_channel Always `self::CARD_RECURRING_CHANNEL` (1503) --
	 *                                  passed through rather than hardcoded so this
	 *                                  method reads the same as its sibling `process_*`
	 *                                  methods above.
	 *
	 * @return string Trusted redirect URL from Autopay.
	 *
	 * @throws Exception One of `autopay_card_recurring_transport_error`,
	 *                    `autopay_card_recurring_rejected`,
	 *                    `autopay_card_recurring_invalid_response`,
	 *                    `autopay_card_recurring_order_id_mismatch`,
	 *                    `autopay_card_recurring_untrusted_redirect`.
	 */
	private function process_card_recurring_init_via_pretransaction( WC_Order $order, int $payment_channel ): string {
		$order_id         = $order->get_id();
		$recurring_logger = new Recurring_Logger();

		// Recurring_Context::decide() only lets total<=0 through for PLN; this check
		// is defense-in-depth, not the primary gate.
		$is_zero_amount_first_payment = $order->get_total() <= 0.0;
		$amount_override              = null;

		if ( $is_zero_amount_first_payment ) {
			if ( Autopay_Payment_Protocol::INIT_WITH_REFUND_SUPPORTED_CURRENCY !== $order->get_currency() ) {
				$recurring_logger->error( 'pretransaction_result', [
					'order_id' => $order_id,
					'outcome'  => 'zero_amount_currency_unsupported',
					'currency' => $order->get_currency(),
				] );

				wc_add_notice( __( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ), 'error' );
				throw new Exception( 'autopay_card_recurring_zero_amount_currency_unsupported' );
			}

			$recurring_action = Autopay_Payment_Protocol::RECURRING_ACTION_INIT_WITH_REFUND;
			$amount_override  = Autopay_Payment_Protocol::INIT_WITH_REFUND_TECHNICAL_AMOUNT;
		} else {
			$recurring_action = Autopay_Payment_Protocol::RECURRING_ACTION_INIT_WITH_PAYMENT;
		}

		$recurring_params = [
			Autopay_Payment_Protocol::FIELD_RECURRING_ACCEPTANCE_STATE => Autopay_Payment_Protocol::RECURRING_ACCEPTANCE_STATE_ACCEPTED,
			Autopay_Payment_Protocol::FIELD_RECURRING_ACTION           => $recurring_action,
		];

		// Reuses the exact same builder/hash call as the auto-submit-form variant
		// (Transaction_Request_Builder::build()) -- same field set, same order,
		// same Hash algorithm; only what happens with the result differs below.
		// $amount_override flows into bm_transaction_init_params too, so RPAN
		// correlation (RPAN_Webhook_Handler::matches_initiating_transaction()) needs
		// no change.
		$params_with_hash  = $this->transaction_builder->build( $order, $payment_channel, $recurring_params, null, $amount_override );
		$expected_order_id = (string) $params_with_hash['OrderID'];

		// Saved BEFORE the HTTP call, matching process_gpay_payment()/
		// process_blik_0_payment() (both save 'bm_transaction_init_params' before
		// calling continue_transaction_request(), not after). If Autopay accepts
		// and creates the transaction on their side but our own connection then
		// times out or drops before we read the response, an ITN/RPAN for this
		// OrderID can still arrive. RPAN_Webhook_Handler::matches_initiating_transaction()
		// needs this meta present to correlate that notification -- saving it
		// only after a locally-confirmed success would leave a real, Autopay-side
		// transaction unmatched purely because of a timing race on our end, not
		// because anything about the transaction itself failed.
		$order->update_meta_data( 'bm_transaction_init_params', $params_with_hash );
		$order->save_meta_data();

		$raw = ( new Client() )->continue_transaction_request(
			$params_with_hash,
			$this->gateway_url . Autopay_Payment_Protocol::HTTP_PAYMENT_PATH
		);

		if ( is_string( $raw ) && 0 === strpos( $raw, Autopay_Payment_Protocol::HTTP_TRANSPORT_ERROR_LEADER ) ) {
			$recurring_logger->error( 'pretransaction_result', [
				'order_id'       => $order_id,
				'outcome'        => 'transport_error',
				// Redacted to a small allowlist of safe, bounded classifications
				// (cURL error code, HTTP status line) -- never the raw message.
				// See redact_transport_error_note().
				'transport_note' => $this->redact_transport_error_note( $raw ),
			] );

			wc_add_notice( __( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ), 'error' );
			throw new Exception( 'autopay_card_recurring_transport_error' );
		}

		$raw_string = (string) $raw;
		$validator  = new Pretransaction_Response_Validator( $this->private_key );
		$result     = $validator->validate( $raw_string, $expected_order_id );

		$recurring_logger->debug( 'pretransaction_result', [
			'order_id'             => $order_id,
			'outcome'              => $result->get_outcome(),
			'status'               => $result->get_status(),
			'confirmation'         => $result->get_confirmation(),
			'reason'               => null !== $result->get_reason()
				? substr( $result->get_reason(), 0, 200 )
				: null,
			// Own OrderID is not sensitive -- logged in full for correlation.
			// The response's echoed orderID is logged too, specifically so an
			// ORDER_ID_MISMATCH is diagnosable from the log alone.
			'response_order_id'    => $result->get_response_order_id(),
			'redirect_url_present' => null !== $result->get_redirect_url() && '' !== $result->get_redirect_url(),
			'redirect_url_length'  => strlen( (string) $result->get_redirect_url() ),
			'raw_response_length'  => strlen( $raw_string ),
		] );

		if ( ! $result->is_valid_redirect() ) {
			if ( Pretransaction_Response_Result::REJECTED === $result->get_outcome() ) {
				// Generic message only -- the raw `reason` from Autopay is kept in
				// the redacted debug log above (truncated to 200 chars), never
				// shown to the customer.
				wc_add_notice( __( 'Card payment was rejected. Please try again.', 'platnosci-online-blue-media' ), 'error' );
				throw new Exception( 'autopay_card_recurring_rejected' );
			}

			if ( Pretransaction_Response_Result::ORDER_ID_MISMATCH === $result->get_outcome() ) {
				wc_add_notice( __( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ), 'error' );
				throw new Exception( 'autopay_card_recurring_order_id_mismatch' );
			}

			// MALFORMED_XML, MISSING_HASH, INVALID_SIGNATURE, UNEXPECTED_SHAPE --
			// none of these are ever trusted enough to redirect the customer.
			wc_add_notice( __( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ), 'error' );
			throw new Exception( 'autopay_card_recurring_invalid_response' );
		}

		$redirect_url = (string) $result->get_redirect_url();

		if ( ! $this->payment_redirect_handler->is_trusted_autopay_redirect_url( $redirect_url ) ) {
			$recurring_logger->error( 'pretransaction_result', [
				'order_id' => $order_id,
				'outcome'  => 'untrusted_redirect',
			] );

			wc_add_notice( __( 'Card payment could not be started. Please try again.', 'platnosci-online-blue-media' ), 'error' );
			throw new Exception( 'autopay_card_recurring_untrusted_redirect' );
		}

		// Never downgrade an order an ITN has already moved past "awaiting
		// payment" -- e.g. a duplicate/slow submit whose HTTP response comes
		// back after a concurrent attempt's ITN already arrived. 'pending' (or
		// whatever wc_payment_status_on_bm_pending is configured to) is only
		// ever a step FORWARD from the order's initial unpaid state.
		if ( $order->has_status( [ 'processing', 'completed', 'refunded', 'on-hold' ] ) ) {
			$recurring_logger->warning( 'pretransaction_result', [
				'order_id' => $order_id,
				'outcome'  => 'pending_status_skipped_order_already_paid',
				'status'   => $order->get_status(),
			] );
		} else {
			$start_status = $this->get_currency_aware_option( 'wc_payment_status_on_bm_pending', 'pending' );
			$this->update_order_status( $order, $start_status );
			$order->add_order_note( __( 'Autopay: recurring card (1503) pretransaction started for order ID:', 'platnosci-online-blue-media' ) . $order_id );
		}

		return $redirect_url;
	}

	/**
	 * Redact a `Client::continue_transaction_request()` error-prefixed return
	 * value before logging it. Guzzle's exception message can embed the full
	 * request URI and, for HTTP error responses, a preview of the response
	 * body -- rather than trying to strip only the parts known to be
	 * URL-shaped (which does not guarantee the rest is free of anything
	 * sensitive), this extracts only a small ALLOWLIST of known-safe,
	 * bounded classifications (a cURL error code, or an HTTP status line) and
	 * falls back to a fixed generic string with none of the original content
	 * when nothing recognized matches.
	 *
	 * @param string $raw_note Raw value returned by `continue_transaction_request()`.
	 */
	private function redact_transport_error_note( string $raw_note ): string {
		if ( preg_match( '/cURL error (\d+): ([^\r\n]{0,80})/i', $raw_note, $matches ) ) {
			$reason = (string) preg_replace( '#https?://\S+#i', '[url-redacted]', $matches[2] );

			return sprintf( 'cURL error %s: %s', $matches[1], trim( $reason ) );
		}

		if ( preg_match( '/\b([1-5][0-9]{2}) ([A-Za-z ]{1,40})\b/', $raw_note, $matches ) ) {
			return sprintf( 'HTTP %s %s', $matches[1], trim( $matches[2] ) );
		}

		return 'unclassified_transport_error';
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
	 * @param WC_Order    $order              Order.
	 * @param string|null $order_id_override  Per-attempt OrderID for a
	 *                                        renewal-order repayment.
	 *
	 * @return string|null Redirect URL from Autopay.
	 *
	 * @throws Exception When token is missing or Autopay rejects the start request.
	 */
	private function process_card_widget_payment( WC_Order $order, ?string $order_id_override = null ): ?string {
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
			$order_id_override,
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
		bool $block_payment = false,
		?string $order_id_override = null
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
			'OrderID'           => $order_id_override ?? $order->get_id(),
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
				'Autopay BLIK-0: payment start failed.' );
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
	 * Calls mark_sent() right before the real HTTP call for an ordinary one-off
	 * renewal repayment -- called only AFTER local validation (token/
	 * BLIK code) has already passed. Adds the generic-error notice and returns
	 * false when mark_sent() itself refuses (e.g. a concurrent process already
	 * moved this attempt past STARTING) -- the caller must not proceed to send.
	 *
	 * @param int $order_id Renewal order id.
	 * @param int $seq      Attempt number.
	 */
	private function mark_renewal_attempt_sent_or_abort( int $order_id, int $seq ): bool {
		if ( ( new Attempt_State_Store() )->mark_sent( $order_id, $seq ) ) {
			return true;
		}

		wc_add_notice(
			__( 'Payment could not be started. Please try again.', 'platnosci-online-blue-media' ),
			'error'
		);

		return false;
	}

	/**
	 * Exact integer-cents amount for the recurring attempt context of an ordinary,
	 * one-off repayment of a renewal order -- what ITN_Webhook_Handler
	 * later compares against with `===`, never a float. Same normalisation as
	 * Renewal_Payment_Handler::normalize_amount_to_cents(), kept as a separate copy
	 * per this codebase's existing convention (re: format_amount()).
	 *
	 * @param WC_Order $order Renewal order.
	 */
	private function normalize_amount_to_cents_for_ordinary_repayment( WC_Order $order ): ?int {
		$raw = str_replace( ',', '.', trim( (string) wc_format_decimal( $order->get_total(), 2 ) ) );

		if ( 1 !== preg_match( '/^-?\d+(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}

		$parts       = array_pad( explode( '.', $raw, 2 ), 2, '' );
		$fraction    = str_pad( substr( $parts[1], 0, 2 ), 2, '0' );
		$is_negative = 0 === strpos( $parts[0], '-' );
		$int_part    = ltrim( $is_negative ? substr( $parts[0], 1 ) : $parts[0], '0' );
		$int_part    = '' === $int_part ? '0' : $int_part;
		$cents       = (int) ( $int_part . $fraction );

		return $is_negative ? -$cents : $cents;
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
