<?php

namespace Ilabs\BM_Woocommerce;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Exception;

use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Ga4_Hooks;
use Ilabs\BM_Woocommerce\Domain\Service\Product_Feed\Product_Feed;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Manager;
use Ilabs\BM_Woocommerce\Gateway\Webhook\Order_Remote_Status_Manager;
use Ilabs\BM_Woocommerce\Utilities\File_System\Log_Downloader;
use Ilabs\BM_Woocommerce\Utilities\Test_Connection\Async_Request as Connection_Testing_Controller;
use Ilabs\BM_Woocommerce\Data\Remote\Ga4_Service_Client;
use Ilabs\BM_Woocommerce\Domain\Service\Currency\Currency;
use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;
use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Frontend;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Add_Product_To_Cart_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Click_On_Product_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Complete_Transation_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Init_Checkout_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\Remove_Product_From_Cart_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Ga4\View_Product_On_List_Use_Case;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Currency_Tabs;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\WC_Form_Fields_Integration;
use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Admin\Renewal_Order_Diagnostics_Panel;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Admin\Subscription_Diagnostics_Metabox;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Attempt_State_Store;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Deactivation_Retry_Job;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Fs_Lifecycle_Bridge;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Fs_Pause_Sync_Retry_Job;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Renewal_Attempt_Sweep;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Renewal_Failed_Order_Email_Cta;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Renewal_Status_Check_Job;
use Ilabs\BM_Woocommerce\Gateway\Recurring\Subscription_Cancellation_Listener;
use WPDesk\FlexibleSubscriptions\Subscription\Renewal\Renewal;
use Ilabs\BM_Woocommerce\Integration\Flexible_Subscriptions\Flexible_Subscriptions_Integration;
use Ilabs\BM_Woocommerce\Integration\Funnel_Builder\Funnel_Builder_Integration;
use Ilabs\BM_Woocommerce\Integration\Woocommerce_Blocks\WC_Gateway_Autopay_Blocks_Support;
use Ilabs\BM_Woocommerce\Utilities\Test_Connection\Strings;
use Ilabs\BM_Woocommerce\Helpers\Autopay_Urls;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Abstract_Ilabs_Plugin;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Alerts;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Add_To_Cart;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Order_Status_Changed;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Remove_Cart_Item;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Cart_Aware_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Order_Aware_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Product_Aware_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Features_Config_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Woocommerce_Logger;
use WC_Order;
use WC_Session;
use WC_Session_Handler;
use Ilabs\BM_Woocommerce\Controller\Payment_Status_Controller;
use WP_Post;
use WP_REST_Request;

class Plugin extends Abstract_Ilabs_Plugin {

	/**
	 * @var string
	 */
	private $blue_media_currency;

	private static ?Currency $currency_manager = null;

	private static ?Connection_Testing_Controller $connection_testing_controller = null;

	private static ?Fs_Lifecycle_Bridge $fs_lifecycle_bridge = null;

	/**
	 * @var Blue_Media_Gateway
	 */
	private static $blue_media_gateway;

	/**
	 * @var Log_Downloader | null
	 */
	protected ?Log_Downloader $file_downloader = null;


	/**
	 * @var bool
	 */
	private static bool $inactive_on_frontend = false;

	public function get_woocommerce_logger(
		?string $log_id = null,
		bool $force = false
	): Woocommerce_Logger {

		$transient_value = (string) get_transient( 'autopay_debug_enabled' );
		if ( 'on' === $transient_value ) {
			$force = true;
		}

		$settings = get_option( 'woocommerce_bluemedia_settings' );

		$log_id = apply_filters( 'autopay_log_id', $log_id );

		if ( ! $log_id ) {
			$log_id = $this->get_from_config( 'slug' );
		}

		$debug_mode = 'no';
		if ( is_array( $settings ) && isset( $settings['debug_mode'] ) ) {
			$debug_mode = $settings['debug_mode'];
		}

		$logger = new Woocommerce_Logger( $log_id );

		if ( 'yes' === $debug_mode || $force ) {
			$logger->set_null_logger( false );
		} else {
			$logger->set_null_logger( true );
		}

		return $logger;
	}

	public function get_features_config(): Features_Config_Interface {
		return ( new Features() );
	}

	/**
	 * @throws Exception
	 */
	protected function before_init() {
		$request_uri = $this->get_request_uri();
		if ( $request_uri && stripos( $request_uri,
				'wp-json/wc/v3' ) !== false ) {
			return;
		}

		$this->configure_third_party_integrations();
		$this->start_output_buffer_on_itn_request();

		// Registered here, unconditionally, and NOT inside
		// Blue_Media_Gateway's constructor. A bare Action Scheduler queue-runner
		// request (WP-Cron, the async-request runner, or WP-CLI) has no reason to call
		// WC()->payment_gateways() itself, so a gateway-constructor-only registration
		// would silently never attach when these jobs are due to run. Placed before the
		// `resolve_is_autopay_hidden()` guard below on purpose: whether the gateway is
		// currently offered at checkout has no bearing on whether already-active
		// subscriptions still need their recovery jobs to run.
		( new Renewal_Status_Check_Job() )->register();
		( new Renewal_Attempt_Sweep() )->register();

		// Same reasoning as the two registrations above -- FS's own
		// `init`-hooked `fsub/initialized` (activation), `fsub/subscription/cancel`
		// (scheduled end-of-period cancellation), and any bare Action Scheduler
		// worker running a previously-scheduled retry job are none of them
		// guaranteed to construct `Blue_Media_Gateway` on their own, so these four
		// registrations must not be deferred into the gateway's constructor.
		$this->get_fs_lifecycle_bridge()->register();
		( new Subscription_Cancellation_Listener() )->register();
		( new Deactivation_Retry_Job() )->register();
		( new Fs_Pause_Sync_Retry_Job() )->register();

		// Admin-only diagnostics, unconditional for the same
		// reason as the registrations above -- `add_meta_boxes` on the FS subscription
		// screen/the WC order edit screen has no dependency on whether Autopay is
		// currently offered at checkout.
		( new Subscription_Diagnostics_Metabox() )->register();
		( new Renewal_Order_Diagnostics_Panel() )->register();

		// Same reasoning as the registrations above -- WooCommerce can render/send the
		// customer failed-order email from a context that never constructs
		// Blue_Media_Gateway on its own (an admin "resend email" action, or this
		// plugin's own Renewal_Status_Check_Job/Renewal_Attempt_Sweep Action Scheduler
		// workers reaching a `failed` transition), so this registration must not be
		// deferred into the gateway's constructor either.
		( new Renewal_Failed_Order_Email_Cta() )->register();

		if ( $this->resolve_is_autopay_hidden() ) {
			return;
		}
		add_action( 'woocommerce_blocks_loaded',
			[ $this, 'woocommerce_block_support' ] );


		$this->init_payment_gateway();
		$this->register_hooks();
		$this->get_connection_testing_controller()->handle();
		( new Payment_Status_Controller() )->handle();

		// A renewal order belonging to this integration
		// (payment_method=bluemedia, or -- covering the moment before that meta is
		// ever set -- an open card-recurring attempt) is never payable through some
		// OTHER installed WC gateway on order-pay. WC_Payment_Gateways::
		// get_available_payment_gateways() applies this filter fresh on every call,
		// including the one WC_Form_Handler::pay_action() itself makes to validate
		// $_POST['payment_method'] -- so this blocks selection at POST-time too, not
		// only in the rendered list. Scoped to renewal orders "belonging to us" only
		// -- never touches a subscription/renewal-order paid through a different
		// gateway (no global "no other PSP" rule).
		add_filter( 'woocommerce_available_payment_gateways', function ( array $gateways ) {
			if ( is_admin() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
				return $gateways;
			}

			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order
				|| ! class_exists( Renewal::class )
				|| ! Renewal::is_renewal_order( $order )
			) {
				return $gateways;
			}

			$is_ours = 'bluemedia' === $order->get_payment_method()
				|| null !== ( new Attempt_State_Store() )->get_current_attempt( $order_id );

			if ( ! $is_ours ) {
				return $gateways;
			}

			return array_intersect_key( $gateways, [ 'bluemedia' => true ] );
		} );

		add_action( 'bm_cancel_failed_pending_order_after_one_hour',
			function ( $order_id ) {
				$order = wc_get_order( $order_id );
				wp_clear_scheduled_hook( 'bm_cancel_failed_pending_order_after_one_hour',
					[ $order_id ] );

				if ( ! $order instanceof WC_Order || ! $order->has_status( [ 'pending' ] ) ) {
					return;
				}

				// A renewal order with an open (not yet fully
				// released) card-recurring attempt must never be cancelled here.
				// get_current_attempt() returning a record at all -- regardless of its
				// state -- is exactly "not yet released": try_release_lock() only ever
				// deletes it for SUCCESS/FAILURE AFTER Recurring_Result_Applier has
				// finished applying the corresponding WC_Order side effects. A terminal
				// SUCCESS/FAILURE whose side effects are not yet applied is therefore
				// ALSO protected here, without inspecting `state` at all -- cancelling
				// in that narrow window would race Recurring_Result_Applier's own
				// payment_complete()/update_order_status() call for the very same
				// order. No second coordination mechanism is introduced: the existing
				// attempt-record lifecycle IS the coordination.
				if ( class_exists( Renewal::class ) && Renewal::is_renewal_order( $order )
					&& null !== ( new Attempt_State_Store() )->get_current_attempt( $order_id )
				) {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf(
							'[bm_cancel_failed_pending_order_after_one_hour] [skip: unresolved recurring attempt] [order_id: %d]',
							$order_id
						)
					);

					return;
				}

				$payment_method = (string) $order->get_payment_method();
				$is_test_order  = '1' === (string) $order->get_meta( 'autopay_test_order' );

				if ( 'bluemedia' !== $payment_method && ! $is_test_order ) {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf(
							'[bm_cancel_failed_pending_order_after_one_hour] [skip: foreign payment method] [order_id: %d] [payment_method: %s]',
							$order_id,
							$payment_method
						)
					);

					return;
				}

				if ( blue_media()->get_order_remote_status_manager()->is_success_or_applying( (int) $order_id ) ) {
					blue_media()->get_woocommerce_logger()->log_debug(
						sprintf(
							'[bm_cancel_failed_pending_order_after_one_hour] [skip: order paid or payment finalizing] [order_id: %d]',
							$order_id
						)
					);

					return;
				}

				$order->update_status( 'cancelled' );
				$order->add_order_note( __( 'Unpaid order cancelled - time limit reached.',
					'platnosci-online-blue-media' ) );
				$order->save();
			} );

		$this->get_file_downloader()->handle();

		// Frontend assets (including Google Pay) must be enqueued.
		add_action( 'wp_enqueue_scripts',
			[ $this, 'enqueue_frontend_scripts' ] );

	}

	private function register_hooks() {
		( new Hooks() )->init();
	}

	private function init_custom_css() {
		if ( ! is_admin() ) {

			add_action( 'wp', function () {
				if ( function_exists( 'wc_get_page_id' ) ) {
					$checkoutpage_id = wc_get_page_id( 'checkout' );

					global $wp_query;
					$post_obj = $wp_query->get_queried_object();
					if ( $post_obj instanceof WP_Post ) {
						$page_id = $post_obj->ID;
						if ( $checkoutpage_id === $page_id ) {
							( new Css_Frontend() )->include();
						}
					}
				} else {
					( new Css_Frontend() )->include();
				}
			} );


		}
	}

	public function woocommerce_block_support() {
		$request_uri = $this->get_request_uri();
		if ( '' === $request_uri ) {
			return;
		}
		$current_url   = $request_uri;
		$search_phrase = "order-received";
		if ( strpos( $current_url, $search_phrase ) !== false ) {
			return;
		}

		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( PaymentMethodRegistry $payment_method_registry ) {

					if ( false === self::$inactive_on_frontend ) {
						$payment_method_registry->register( new WC_Gateway_Autopay_Blocks_Support() );
					}

				}
			);

			if ( false === self::$inactive_on_frontend ) {
				// Live, cart-triggered counterpart of get_payment_method_data()'s
				// static `channels`/recurring fields -- see register_store_api_cart_extension()
				// for why this cannot simply be computed once at page render.
				( new WC_Gateway_Autopay_Blocks_Support() )->register_store_api_cart_extension();
			}
		}
	}

	private function resolve_is_autopay_hidden(): bool {

		if ( is_admin() || $this->is_itn_request() ) {
			return false;
		}

		$settings = get_option( 'woocommerce_bluemedia_settings' );

		if ( is_array( $settings ) && isset( $settings['autopay_only_for_admins'] ) ) {
			if ( $settings['autopay_only_for_admins'] === 'yes' ) {
				if ( ! function_exists( 'wp_get_current_user' ) ) {
					@$this->require_wp_core_file( 'wp-includes/pluggable.php' );
					if ( ! function_exists( 'wp_get_current_user' ) ) {
						return false;
					}
				}
				$current_user = wp_get_current_user();

				if ( user_can( $current_user, 'administrator' ) ) {
					blue_media()->get_woocommerce_logger()->log_debug(
						'[resolve_is_autopay_hidden] true' );

					return false;
				} else {
					return true;
				}
			}
		}

		return false;
	}


	private function is_itn_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ITN detection; read-only routing parameter; no state is changed.
		return isset( $_GET['wc-api'] ) && 'wc_gateway_bluemedia' === sanitize_key( wp_unslash( $_GET['wc-api'] ) );
	}

	private function get_request_uri(): string {
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return '';
	}

	/**
	 * @throws Exception
	 */
	public function enqueue_frontend_scripts() {
		wp_enqueue_style( $this->get_plugin_prefix() . '_front_css',
			$this->get_plugin_css_url() . '/frontend.css',
			[],
			blue_media()->get_plugin_version() );

		wp_enqueue_script( $this->get_plugin_prefix() . '_front_js',
			$this->get_plugin_js_url() . '/front.js',
			[ 'jquery' ],
			blue_media()->get_plugin_version(),
			true );

		$is_checkout_page       = function_exists( 'is_checkout' ) && is_checkout();
		$is_block_checkout_page = false;

		if ( $is_checkout_page && function_exists( 'has_block' ) ) {
			$post = get_post();
			if ( $post instanceof \WP_Post ) {
				$is_block_checkout_page = has_block( 'woocommerce/checkout', $post );
			}
		}
		if ( $is_checkout_page && ! $is_block_checkout_page ) {
			$gateway = blue_media()->get_blue_media_gateway();
			if ( $gateway instanceof \Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway ) {
				$offer_google_pay = $gateway->should_offer_google_pay_on_checkout();
				$gpay_type        = $gateway->get_option(
					Settings_Manager::get_currency_option_key( 'gpay_type', get_woocommerce_currency() ),
					'with_redirect'
				);

				wp_localize_script(
					$this->get_plugin_prefix() . '_front_js',
					'autopayGpay',
					[
						'gpayType' => $gpay_type,
					]
				);

				// For classic checkout we still load Google Pay scripts globally.
				// Block checkout receives them from the Blocks integration only when GPAY is eligible.
				if ( $offer_google_pay && 'without_redirect' === $gpay_type ) {
					// Google Pay helper (must load before pay.js).
					wp_enqueue_script( $this->get_plugin_prefix() . '_google_pay_js',
						$this->get_plugin_js_url() . '/google-pay-atp.js',
						[ 'jquery' ],
						blue_media()->get_plugin_version(),
						true );

					// Google Pay API should be in header for classic checkout.
					wp_enqueue_script(
						'autopay_google_pay',
						'https://pay.google.com/gp/p/js/pay.js',
						[],
						null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Google Pay CDN; version pinning is not possible.
						false
					);
				}
			}
		}

		$ga4_tracking_id = ( new Ga4_Service_Client() )->get_tracking_id();

		if ( $ga4_tracking_id ) {
			wp_enqueue_script( $this->get_plugin_prefix() . '_ga4',
				"https://www.googletagmanager.com/gtag/js?id=$ga4_tracking_id",
				[],
				'1.1',
				true );


			wp_localize_script( $this->get_plugin_prefix() . '_front_js',
				'blueMedia',
				[
					'ga4TrackingId' => $ga4_tracking_id,
				]
			);
		}

		if ( $this->get_autopay_option( 'campaign_tracking',
				'no' ) === 'yes' ) {

			$pixel_js_src = $this->get_product_feed()
			                     ->get_pixel_js_src();

			if ( is_string( $pixel_js_src ) ) {
				wp_enqueue_script(
					$this->get_plugin_prefix() . '_autopay_pixel',
					$pixel_js_src,
					[],
					$this->get_plugin_version(),
					false
				);
			}
		}

		// Load checkout payment provider scripts.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$is_block_checkout_page = false;
			if ( function_exists( 'has_block' ) ) {
				$post = get_post();
				if ( $post instanceof \WP_Post ) {
					$is_block_checkout_page = has_block( 'woocommerce/checkout', $post );
				}
			}

			// Google Pay API should be loaded in the header to initialize PaymentsClient.
			wp_enqueue_script(
				'autopay_google_pay',
				'https://pay.google.com/gp/p/js/pay.js',
				[],
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Google Pay CDN; version pinning is not possible.
				false
			);

			$is_test      = 'yes' === $this->get_autopay_option( 'testmode', 'no' );
			$cards_domain = Autopay_Urls::get_cards_domain( $is_test );

			// Card widget script: classic checkout enqueues here; Checkout Block uses WC_Gateway_Autopay_Blocks_Support
			// so `autopay_card_widget` is registered as a dependency of `autopay-payments-blocks` and loads first.
			if ( ! $is_block_checkout_page ) {
				wp_enqueue_script(
					'autopay_card_widget',
					$cards_domain . '/widget-new/widget-communication.min.js',
					[ 'jquery' ],
					null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Autopay card widget CDN; version is managed by the Autopay payment infrastructure.
					true
				);
			}
		}
	}

	/**
	 * @throws Exception
	 */
	public function enqueue_dashboard_scripts() {

		$current_screen = get_current_screen();

		if ( is_a( $current_screen,
				'WP_Screen' ) && 'woocommerce_page_wc-settings' === $current_screen->id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
			if ( isset( $_GET['tab'] ) && sanitize_key( wp_unslash( $_GET['tab'] ) ) === 'checkout' ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
				if ( isset( $_GET['section'] ) && sanitize_key( wp_unslash( $_GET['section'] ) ) === 'bluemedia' ) {

					Css_Editor::enqueue_scripts();

					wp_enqueue_script( $this->get_plugin_prefix() . '_admin_js',
						$this->get_plugin_js_url() . '/admin.js',
						[ 'jquery' ],
						blue_media()->get_plugin_version(),
						true );

					wp_enqueue_script( $this->get_plugin_prefix() . '_test_con_js',
						$this->get_plugin_js_url() . '/testConnection.js',
						[ 'jquery' ],
						blue_media()->get_plugin_version(),
						true );

					wp_localize_script( $this->get_plugin_prefix() . '_test_con_js',
						'autopayAuditData',
						[
							'adminAjaxUrl'        => esc_url( admin_url( 'admin-ajax.php' ) ),
							'adminAjaxActionName' => sanitize_text_field( Connection_Testing_Controller::AJAX_ACTION_NAME ),
							'strings'             => Strings::get_strings(),
						]
					);

					wp_localize_script( $this->get_plugin_prefix() . '_admin_js',
						'blueMedia',
						[
							'whitelabel_description' => WC_Form_Fields_Integration::get_whitelabel_description(),
						]
					);

					wp_enqueue_style( $this->get_plugin_prefix() . '_admin_css',
						$this->get_plugin_css_url() . '/admin.css',
						[],
						blue_media()->get_plugin_version()
					);

					wp_enqueue_style( $this->get_plugin_prefix() . '_banner_css',
						'https://plugins-api.autopay.pl/dokumenty/autopay.css',
						[],
						null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Autopay banner CSS CDN; version is managed by Autopay infrastructure.
					);

					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
					if ( isset( $_GET['bmtab'] ) && sanitize_key( wp_unslash( $_GET['bmtab'] ) ) === 'vas' ) {
						wp_enqueue_style( $this->get_plugin_prefix() . '_vas_css',
							'https://plugins-api.autopay.pl/dokumenty/vas.css',
							[],
							null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Autopay VAS CSS CDN; version is managed by Autopay infrastructure.
						);
					}
				}
			}
		}
	}

	private function start_output_buffer_on_itn_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ITN detection; read-only routing parameter; no state is changed.
		if ( isset( $_GET['wc-api'] ) && sanitize_key( wp_unslash( $_GET['wc-api'] ) ) === 'wc_gateway_bluemedia' && ! ob_get_level() ) {

			ob_start();
		}
	}


	protected function plugins_loaded_hooks() {

	}

	/**
	 * @throws Exception
	 */
	public function init() {
		//Session_Bridge::init();
		$request_uri = $this->get_request_uri();
		if ( $request_uri && stripos( $request_uri,
				'wp-json/wc/v3' ) !== false ) {
			return;
		}

		$stat = $this->get_order_remote_status_manager();

		$this->init_product_feed();
		$this->check_woocommerce_version();
		$this->blue_media_currency = $this->resolve_blue_media_currency_symbol();

		if ( ! $this->blue_media_currency ) {
			$alerts = new Alerts();
			$msg    = sprintf(
				__( 'The selected currency is not supported by the Autopay payment gateway. The gateway has been disabled',
					'platnosci-online-blue-media' )
			);
			$alerts->add_error( 'Autopay: ' . $msg );

			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH
		             . 'wp-admin/includes/class-wp-filesystem-direct.php';


		add_action( 'template_redirect', [ $this, 'express_payment_gateway_init' ], 1 );
		add_action( 'template_redirect', [ $this, 'return_redirect_handler' ] );
		add_action( 'template_redirect', [ $this, 'blik0_timeout_handler' ] );

		add_filter( 'woocommerce_cancel_unpaid_order',
			[ $this, 'bm_woocommerce_cancel_unpaid_order_filter' ],
			10,
			2 );

		if ( get_option( 'bluemedia_activated' ) === '1' ) {
			$this->reposition_on_activate();
			update_option( 'bluemedia_activated', '0' );
		}

		$this->init_custom_css();

	}

	private function init_product_feed() {
		$product_feed = new Product_Feed();

		$product_feed->init();
	}

	private function init_payment_gateway() {
		add_filter( 'woocommerce_payment_gateways',
			function ( $gateways ) {
				$shop_currency = $this->get_currency_manager()
				                      ->get_shop_currency();

				if ( false === is_admin() && ( null === $shop_currency || false === $this->get_currency_manager()
				                                                                         ->is_currency_selected( $shop_currency->get_code() ) ) ) {

					self::$inactive_on_frontend = true;

					return $gateways;
				}

				$gateways[]
					             = 'Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway';
				$order_key_found = '';
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter used only for debug logging; no state is changed.
				if ( isset( $_GET['key'] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter used only for debug logging; no state is changed.
					$keyValue = sanitize_text_field( wp_unslash( $_GET['key'] ) );
					if ( strpos( $keyValue, 'wc_order_' ) === 0 ) {
						$order_key_found = sprintf( '[%s found in GET]',
							$keyValue,
						);
					}
				}

				return $gateways;
			}
		);
	}

	/**
	 * Detect and trigger the Autopay express-payment redirect on template_redirect,
	 * before any HTML output. Works without autopay_express_payment URL param —
	 * uses order meta to determine redirect intent.
	 *
	 * @return void
	 */
	public function express_payment_gateway_init(): void {
		if ( wp_doing_ajax() || is_admin() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$has_express_param = isset( $_GET['autopay_express_payment'] );

		if ( ! $has_express_param ) {
			$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

			if ( ! $order_id && isset( $_GET['key'] ) ) {
				$order_id = function_exists( 'wc_get_order_id_by_order_key' )
					? (int) wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) )
					: 0;
			}

			if ( ! $order_id ) {
				return;
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( 'bluemedia' !== $order->get_payment_method() ) {
				return;
			}

			$meta = $order->get_meta( 'bm_order_payment_params', true );

			if ( empty( $meta ) || ! isset( $meta['params'] ) ) {
				return;
			}

			if ( '1' === (string) $order->get_meta( 'autopay_returned_from_payment', true ) ) {
				return;
			}

			$_GET['autopay_express_payment'] = '1';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( ! function_exists( 'WC' ) || ! is_object( WC()->payment_gateways() ) ) {
			return;
		}

		WC()->payment_gateways()->get_available_payment_gateways();
	}

	public function return_redirect_handler() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
		if ( isset( $_GET['bm_gateway_return'] ) ) {
			$order = null;

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
			if ( isset( $_GET['key'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
				$order_id = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
				$order    = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$init_params = $order->get_meta( 'bm_transaction_init_params' );
					$order       = is_array( $init_params ) ? $order : null;
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
			if ( isset( $_GET['OrderID'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter; no state is changed.
				$order = wc_get_order( absint( wp_unslash( $_GET['OrderID'] ) ) );
				if ( $order instanceof WC_Order ) {
					$init_params = $order->get_meta( 'bm_transaction_init_params' );
					$order       = is_array( $init_params ) ? $order : null;
				}
			}

			if ( $order ) {

				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[return_redirect_handler] [order: %s]',
						$order->get_id()
					) );


				$order->add_meta_data( 'autopay_returned_from_payment', '1' );
				$order->save_meta_data();
				$finish_url = $order->get_meta( 'autopay_order_received_url' );
				if ( empty( $finish_url ) || '#' === $finish_url ) {
					$finish_url = $order->get_meta( 'autopay_original_order_received_url' );
					if ( empty( $finish_url ) ) {
						$finish_url = $order->get_checkout_order_received_url();
					}
				}

				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[return_redirect_handler] [doing redirect] [url: %s]',
						$finish_url
					) );

				wp_safe_redirect( $finish_url );
				exit;
			}
		}
	}

	public function blik0_timeout_handler() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce order key ($_GET['key']) acts as an unguessable per-order token; this URL is generated by the plugin itself during BLIK-0 flow and Autopay redirects back to it. WP nonce cannot be added to Autopay redirect URLs.
		if ( isset( $_GET['key'] ) && isset( $_GET['blik0_timeout'] )
		     && '1' === sanitize_key( wp_unslash( $_GET['blik0_timeout'] ) ) ) {
			$order_id = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$this->get_blue_media_gateway()
				     ->update_order_status( $order,
					     'failed',
					     __( 'Autopay BLIK-0: Timed out while waiting for confirmation.',
						     'platnosci-online-blue-media' ) );
				$order->save();
			}
		}
	}

	public function bm_woocommerce_cancel_unpaid_order_filter(
		$string,
		$order
	) {
		if ( 'bluemedia' === $order->get_payment_method() ) {
			return false;
		}

		return $string;
	}

	/**
	 * @throws Exception
	 */
	public function update_payment_cache( string $key, $value ) {
		$session = WC()->session;
		if ( ! $session ) {
			$session = new WC_Session_Handler();
			$session->init();
		}
		$session->set( $this->get_from_config( 'slug' ) . '_' . $key, $value );
	}

	/**
	 * @throws Exception
	 */
	public function get_from_payment_cache( string $key ) {
		$session = WC()->session;
		if ( ! $session ) {
			WC()->initialize_session();
			$session = WC()->session;
		}

		return $session->get( $this->get_from_config( 'slug' ) . '_' . $key );
	}

	public function resolve_blue_media_currency_symbol(): ?string {
		if ( is_admin() ) {
			$currency_tabs = new Currency_Tabs();

			return $currency_tabs->get_active_tab_currency()
				? $currency_tabs->get_active_tab_currency()->get_code()
				: null;
		}

		$shop_currency = self::get_currency_manager()->get_shop_currency();

		return $shop_currency ? $shop_currency->get_code() : null;
	}

	/**
	 * @return string
	 */
	public function get_blue_media_currency(): string {
		return $this->blue_media_currency;
	}

	public function plugin_activate_actions() {
		update_option( 'bluemedia_activated', '1' );

		$this->get_order_remote_status_manager()->install_db_schema();
	}


	private function reposition_on_activate() {
		$id    = 'bluemedia';
		$array = (array) get_option( 'woocommerce_gateway_order' );

		if ( array_key_exists( 'pre_install_woocommerce_payments_promotion',
				$array ) && $array['pre_install_woocommerce_payments_promotion'] === 0 ) {
			$starts_at = 1;
		} else {
			$starts_at = 0;
		}


		if ( array_key_exists( $id, $array ) ) {
			if ( $array[ $id ] !== 0 ) {
				unset( $array[ $id ] );
			} else {
				return;
			}
		}

		foreach ( $array as $key => &$value ) {
			if ( $key !== 'pre_install_woocommerce_payments_promotion' ) {
				$value += 1;
			}
		}

		$array[ $id ] = $starts_at;
		$flippedArray = array_flip( $array );
		ksort( $flippedArray );

		$normalizedArray = [];
		$counter         = 0;
		foreach ( $flippedArray as $value ) {
			$normalizedArray[ $counter ++ ] = $value;
		}
		$array = array_flip( $normalizedArray );

		update_option( 'woocommerce_gateway_order', $array );
	}

	public function set_bluemedia_gateway(
		Blue_Media_Gateway $blue_media_gateway
	) {
		self::$blue_media_gateway = $blue_media_gateway;
	}

	/**
	 * @return Blue_Media_Gateway | null
	 */
	public function get_blue_media_gateway(): ?Blue_Media_Gateway {
		return self::$blue_media_gateway;
	}

	private function check_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			if ( version_compare( WC_VERSION, '8.1.0', '<' ) ) {
				$alerts = new Alerts();
				$msg    = sprintf(
					__( 'The block-based payment module will not work with the installed version of Woocommerce. Install at least 8.1.0 version.',
						'platnosci-online-blue-media' )
				);
				$alerts->add_error( 'Autopay: ' . $msg );
			}
		}
	}

	private function configure_third_party_integrations(): void {
		( new Funnel_Builder_Integration() )->init();
		( new Flexible_Subscriptions_Integration() )->init();
	}

	public function get_currency_manager(): Currency {
		if ( ! self::$currency_manager ) {
			self::$currency_manager = new Currency();
			self::$currency_manager->init();
		}

		return self::$currency_manager;
	}

	/**
	 * Shared `Fs_Lifecycle_Bridge` singleton. Must be the SAME
	 * instance whose `register()` observed `fsub/initialized` — every caller that
	 * needs `pause()` (RPDN_Webhook_Handler, Recurring_Result_Applier's late-SUCCESS
	 * guard) MUST go through this accessor, never `new Fs_Lifecycle_Bridge()`.
	 */
	public function get_fs_lifecycle_bridge(): Fs_Lifecycle_Bridge {
		if ( ! self::$fs_lifecycle_bridge ) {
			self::$fs_lifecycle_bridge = new Fs_Lifecycle_Bridge();
		}

		return self::$fs_lifecycle_bridge;
	}

	public function get_connection_testing_controller(
	): Connection_Testing_Controller {
		if ( ! self::$connection_testing_controller ) {
			self::$connection_testing_controller = new Connection_Testing_Controller();
		}

		return self::$connection_testing_controller;
	}

	public function get_autopay_option( string $key, $default = null ) {
		if ( $this->get_blue_media_gateway() ) {
			return $this->get_blue_media_gateway()
			            ->get_option( $key, $default );
		} else {
			$settings = get_option( 'woocommerce_bluemedia_settings' );
			if ( is_array( $settings ) && ! empty( $settings[ $key ] ) ) {
				return $settings[ $key ];
			}

			return $default;
		}
	}

	public function update_autopay_option( string $key, $value ): void {
		blue_media()->get_woocommerce_logger()->log_debug(
			sprintf( '[Plugin] [update_autopay_option] [%s]',
				wp_json_encode( [
					'key'   => $key,
					'value' => $value,
				] ),
			) );
		if ( $this->get_blue_media_gateway() ) {
			$this->get_blue_media_gateway()
			     ->update_option( $key, $value );
		} else {
			$settings = get_option( 'woocommerce_bluemedia_settings' );
			if ( is_array( $settings ) ) {
				$settings[ $key ] = $value;
				update_option( 'woocommerce_bluemedia_settings', $settings );
			}
		}
	}

	public function get_order_remote_status_manager(
	): Order_Remote_Status_Manager {
		return new Order_Remote_Status_Manager();
	}

	public function get_product_feed(): Product_Feed {
		return new Product_Feed();
	}

	public function get_file_downloader(): Log_Downloader {
		if ( ! $this->file_downloader ) {
			$file_downloader       = new Log_Downloader();
			$this->file_downloader = $file_downloader;
		}

		return $this->file_downloader;
	}
}
