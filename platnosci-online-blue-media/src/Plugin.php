<?php

namespace Ilabs\BM_Woocommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Exception;

use Ilabs\BM_Woocommerce\Domain\Service\Product_Feed\Product_Feed;
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
use Ilabs\BM_Woocommerce\Integration\Funnel_Builder\Funnel_Builder_Integration;
use Ilabs\BM_Woocommerce\Integration\Woocommerce_Blocks\WC_Gateway_Autopay_Blocks_Support;
use Ilabs\BM_Woocommerce\Utilities\Test_Connection\Strings;
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
		if ( stripos( $_SERVER['REQUEST_URI'], 'wp-json/wc/v3' ) !== false ) {
			return;
		}

		$this->configure_third_party_integrations();
		$this->start_output_buffer_on_itn_request();

		if ( $this->resolve_is_autopay_hidden() ) {
			return;
		}
		add_action( 'woocommerce_blocks_loaded',
			[ $this, 'woocommerce_block_support' ] );


		$lang_dir = $this->get_from_config( 'lang_dir' );
		load_plugin_textdomain( $this->get_text_domain(),
			\false,
			$this->get_plugin_basename() . "/{$lang_dir}/" );

		$this->init_payment_gateway();

		$this->implement_ga4();
		$this->implement_ga4_serverside();

		$this->get_connection_testing_controller()->handle();
		( new Payment_Status_Controller() )->handle();

		add_action( 'bm_cancel_failed_pending_order_after_one_hour',
			function ( $order_id ) {
				$order = wc_get_order( $order_id );
				wp_clear_scheduled_hook( 'bm_cancel_failed_pending_order_after_one_hour',
					[ $order_id ] );

				if ( $order instanceof WC_Order ) {
					if ( $order->has_status( [ 'pending' ] ) ) {
						$order->update_status( 'cancelled' );
						$order->add_order_note( __( 'Unpaid order cancelled - time limit reached.',
							'bm-woocommerce' ) );
						$order->save();
					}
				}
			} );

		$this->get_file_downloader()->handle();

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
		$current_url   = "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
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

		return isset( $_GET['wc-api'] ) && 'wc_gateway_bluemedia' === $_GET['wc-api'];
	}

	/**
	 * @throws Exception
	 */
	public function enqueue_frontend_scripts() {
		global $wp_version;

		wp_enqueue_style( $this->get_plugin_prefix() . '_front_css',
			$this->get_plugin_css_url() . '/frontend.css',
			[],
			blue_media()->get_plugin_version() );

		wp_enqueue_script( $this->get_plugin_prefix() . '_front_js',
			$this->get_plugin_js_url() . '/front.js',
			[ 'jquery' ],
			blue_media()->get_plugin_version(),
			true );

		$ga4_tracking_id = ( new Ga4_Service_Client() )->get_tracking_id();

		if ( $ga4_tracking_id ) {
			wp_enqueue_script( $this->get_plugin_prefix() . '_ga4',
				"https://www.googletagmanager.com/gtag/js?id=$ga4_tracking_id",
				[],
				1.1,
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
					$this->get_plugin_version()
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
			if ( isset( $_GET['tab'] ) && $_GET['tab'] == 'checkout' ) {
				if ( isset( $_GET['section'] ) && $_GET['section'] === 'bluemedia' ) {

					Css_Editor::enqueue_scripts();

					wp_enqueue_script( $this->get_plugin_prefix() . '_admin_js',
						$this->get_plugin_js_url() . '/admin.js',
						[ 'jquery' ],
						1.1,
						true );

					wp_enqueue_script( $this->get_plugin_prefix() . '_test_con_js',
						$this->get_plugin_js_url() . '/testConnection.js',
						[ 'jquery' ],
						1.1,
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
						$this->get_plugin_css_url() . '/admin.css'
					);

					wp_enqueue_style( $this->get_plugin_prefix() . '_banner_css',
						'https://plugins-api.autopay.pl/dokumenty/baner.css'
					);

					if ( isset( $_GET['bmtab'] ) && $_GET['bmtab'] === 'vas' ) {
						wp_enqueue_style( $this->get_plugin_prefix() . '_vas_css',
							'https://plugins-api.autopay.pl/dokumenty/vas.css'
						);
					}
				}
			}
		}
	}

	private function start_output_buffer_on_itn_request() {
		if ( isset( $_GET['wc-api'] ) && $_GET['wc-api'] === 'wc_gateway_bluemedia' && ! ob_get_level() ) {

			ob_start();
		}
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	private function implement_ga4_serverside() {
		$ga4_Service_Client = new Ga4_Service_Client();
		if ( ! $ga4_Service_Client->get_tracking_id()
		     || ! $ga4_Service_Client->get_client_id()
		     || ! $ga4_Service_Client->get_api_secret() ) {
			return;
		}

		$ga4 = blue_media()->get_event_chain();

		$ga4->on_wc_order_status_changed()
		    ->when( function ( Wc_Order_Status_Changed $event ) {
			    $mapped_status = $this->get_blue_media_gateway()
			                          ->get_option( 'ga4_purchase_status',
				                          'wc-on-hold' );
			    if ( substr( $mapped_status, 0, 3 ) === 'wc-' ) {
				    $mapped_status = substr( $mapped_status, 3 );
			    }

			    return $event->get_new_status() === $mapped_status;
		    } )
		    ->action( function ( Wc_Order_Aware_Interface $order_aware_interface
		    ) {
			    try {
				    ( new Ga4_Service_Client() )->purchase_event( new Complete_Transation_Use_Case( $order_aware_interface->get_order() ) );
			    } catch ( Exception $e ) {
				    blue_media()->get_woocommerce_logger()->log_error(
					    sprintf( '[purchase_event exception] [message: %s]',
						    esc_html( $e->getMessage() )
					    ) );
			    }
		    } )
		    ->execute();
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	private function implement_ga4() {
		if ( ! function_exists( 'WC' ) || false === WC()->session instanceof WC_Session ) {
			return;
		}

		$ga4_Service_Client = new Ga4_Service_Client();
		if ( ! $ga4_Service_Client->get_tracking_id()
		     || ! $ga4_Service_Client->get_client_id()
		     || ! $ga4_Service_Client->get_api_secret() ) {
			return;
		}

		$ga4                      = blue_media()->get_event_chain();
		$ga4_task_queue           = $ga4->get_wc_session_cache( 'ga4_tasks' );
		$ga4_list_items_dto_queue = $ga4->get_wc_session_cache( 'ga4_list_items_dto_queue' );

		$ga4
			->on_wp()
			->when_is_frontend()
			->action( function () use ( $ga4_task_queue ) {
				$ga4_task_queue->clear();
			} )
			->on_wc_before_shop_loop_item()
			->when_is_shop()
			->action( function (
				Wc_Product_Aware_Interface $product_aware_interface
			) use ( $ga4_list_items_dto_queue
			) {
				//view_item_list
				$ga4_list_items_dto_queue->push(
					( new View_Product_On_List_Use_Case( $product_aware_interface->get_product() ) )->create_dto() );

			} )
			->on_wc_before_single_product()
			->action( function (
				Wc_Product_Aware_Interface $product_aware_interface
			) use ( $ga4_task_queue ) {
				//view_item
				$ga4_task_queue->push(
					( new Ga4_Service_Client )->view_item_event_export_array(
						( new Click_On_Product_Use_Case( $product_aware_interface->get_product() ) )
					) );
			} )
			->on_wc_add_to_cart()
			->action( function ( Wc_Add_To_Cart $event ) use ( $ga4_task_queue
			) {
				//add_to_cart
				( new Ga4_Service_Client() )->add_to_cart_event( new Add_Product_To_Cart_Use_Case( $event->get_product(),
					$event->get_quantity() ) );
			} )
			->on_wc_remove_cart_item()
			->action( function ( Wc_Remove_Cart_Item $event ) use (
				$ga4_task_queue
			) {
				//remove_from_cart
				( new Ga4_Service_Client() )->remove_from_cart_event( new Remove_Product_From_Cart_Use_Case
				( $event->get_product(), $event->get_quantity() ) );
			} )
			->on_wc_checkout_page()
			->when_is_not_ajax()
			->action( function ( Wc_Cart_Aware_Interface $cart_aware_interface
			) use ( $ga4_task_queue ) {
				//begin_checkout
				if ( $cart_aware_interface->get_cart()
				                          ->get_cart_contents_count() > 0 ) {
					$ga4_task_queue->push(
						( new Ga4_Service_Client )->init_checkout_event_export_array(
							( new Init_Checkout_Use_Case( $cart_aware_interface->get_cart() ) )
						) );
				}
			} )
			->on_wp_footer()
			->when_is_not_ajax()
			->when_is_frontend()
			->action( function () use (
				$ga4_task_queue,
				$ga4_list_items_dto_queue
			) {
				if ( $ga4_list_items_dto_queue->get() ) {
					$view_Product_On_List_Use_Case = new View_Product_On_List_Use_Case( null );
					$payload                       = $view_Product_On_List_Use_Case->get_ga4_payload_dto();
					$payload->set_items( $ga4_list_items_dto_queue->get() );
					$view_Product_On_List_Use_Case->set_payload( $payload );
					$ga4_task_queue->push( ( new Ga4_Service_Client() )->view_item_list_event_export_array( $view_Product_On_List_Use_Case ) );
					$ga4_list_items_dto_queue->clear();
				}

				if ( $ga4_task_queue->get() ) {
					echo "<script>var blue_media_ga4_tasks = '" . wp_json_encode( $ga4_task_queue->get() ) . "'</script>";
					$ga4_task_queue->clear();
				}

			} )->execute();
	}

	protected function plugins_loaded_hooks() {

	}

	/**
	 * @throws Exception
	 */
	public function init() {
		if ( stripos( $_SERVER['REQUEST_URI'], 'wp-json/wc/v3' ) !== false ) {
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
					'bm-woocommerce' )
			);
			$alerts->add_error( 'Autopay: ' . $msg );

			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH
		             . 'wp-admin/includes/class-wp-filesystem-direct.php';


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
				if ( false === is_admin()
				     && false === $this->get_currency_manager()
				                       ->is_currency_selected(
					                       $this
						                       ->get_currency_manager()
						                       ->get_shop_currency()
						                       ->get_code()
				                       ) ) {

					self::$inactive_on_frontend = true;

					return $gateways;
				}

				$gateways[]
					             = 'Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway';
				$order_key_found = '';
				if ( isset( $_GET['key'] ) ) {
					$keyValue = $_GET['key'];
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

	public function return_redirect_handler() {
		if ( isset( $_GET['bm_gateway_return'] ) ) {
			$order = null;

			if ( isset( $_GET['key'] ) ) {
				$order_id = wc_get_order_id_by_order_key( (int) $_GET['key'] );
				$order    = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$init_params = $order->get_meta( 'bm_transaction_init_params' );
					$order       = is_array( $init_params ) ? $order : null;
				}
			}

			if ( isset( $_GET['OrderID'] ) ) {
				$order = wc_get_order( (int) $_GET['OrderID'] );
				if ( $order instanceof WC_Order ) {
					$init_params = $order->get_meta( 'bm_transaction_init_params' );
					$order       = is_array( $init_params ) ? $order : null;
				}
			}

			if ( $order ) {

				blue_media()->get_woocommerce_logger()->log_debug(
					sprintf( '[return_redirect_handler] [order: %s]',
						$order
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

				wp_redirect( $finish_url );
				exit;
			}
		}
	}

	public function blik0_timeout_handler() {
		if ( isset( $_GET['key'] ) && isset( $_GET['blik0_timeout'] )
		     && '1' === $_GET['blik0_timeout'] ) {
			$order_id = wc_get_order_id_by_order_key( $_GET['key'] );
			$order    = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$this->get_blue_media_gateway()
				     ->update_order_status( $order,
					     'failed',
					     __( 'Autopay BLIK-0: Timed out while waiting for confirmation.',
						     'bm-woocommerce' ) );
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
			                     ->get_code();
		}

		return self::get_currency_manager()->get_shop_currency()->get_code();
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
						'bm-woocommerce' )
				);
				$alerts->add_error( 'Autopay: ' . $msg );
			}
		}
	}

	private function configure_third_party_integrations(): void {
		( new Funnel_Builder_Integration() )->init();
	}

	public function get_currency_manager(): Currency {
		if ( ! self::$currency_manager ) {
			self::$currency_manager = new Currency();
			self::$currency_manager->init();
		}

		return self::$currency_manager;
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
				print_r( [
					'key'   => $key,
					'value' => $value,

				], true ),
			) );
		if ( $this->get_blue_media_gateway() ) {
			$this->get_blue_media_gateway()
			     ->update_option( $key, $value );
		} else {
			$settings = get_option( 'woocommerce_bluemedia_settings' );
			if ( is_array( $settings ) && ! empty( $settings[ $key ] ) ) {
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
