<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Ga4;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Ga4_Service_Client;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Add_To_Cart;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Order_Status_Changed;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Event\Wc_Remove_Cart_Item;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Cart_Aware_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Order_Aware_Interface;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Wc_Product_Aware_Interface;
use WC_Order;
use WC_Session;

class Ga4_Hooks {

	public function init() {
		$this->handle_ga4_events();

		if ( ! $this->is_purchase_status_based_on_itn() ) {
			$this->handle_ga4_serverside_events();
		} else {
			add_action( 'bm_order_bm_int_status_SUCCESS_processed',
				[ $this, 'handle_ga4_serverside_by_itn' ],
				10,
				1 );
		}

	}

	private function is_purchase_status_based_on_itn(): bool {
		return blue_media()->get_autopay_option( 'ga4_purchase_status_based_on_itn',
				'no' ) === 'yes';
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	private function handle_ga4_events() {
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


	/**
	 * @return void
	 * @throws Exception
	 */
	private function handle_ga4_serverside_events() {
		$ga4_Service_Client = new Ga4_Service_Client();
		if ( ! $ga4_Service_Client->get_tracking_id()
		     || ! $ga4_Service_Client->get_client_id()
		     || ! $ga4_Service_Client->get_api_secret() ) {
			return;
		}

		$ga4 = blue_media()->get_event_chain();

		$ga4->on_wc_order_status_changed()
		    ->when( function ( Wc_Order_Status_Changed $event ) {
			    $mapped_status = blue_media()->get_blue_media_gateway()
			                                 ->get_option( 'ga4_purchase_status',
				                                 'wc-on-hold' );
			    if ( substr( $mapped_status, 0, 3 ) === 'wc-' ) {
				    $mapped_status = substr( $mapped_status, 3 );
			    }


			    blue_media()
				    ->get_woocommerce_logger( 'analytics' )
				    ->log_debug(
					    sprintf( '[handle_ga4_serverside] [purchase_event on_wc_order_status_changed] [%s]',
						    print_r( [
							    'new order status' => $event->get_new_status(),
							    'mapped status'    => $mapped_status,
							    'order_id'         => $event->get_order()
							                                ->get_id(),
						    ], true )
					    ) );


			    return $event->get_new_status() === $mapped_status;
		    } )
		    ->action( function ( Wc_Order_Aware_Interface $order_aware_interface
		    ) {
			    blue_media()
				    ->get_woocommerce_logger( 'analytics' )
				    ->log_debug(
					    sprintf( '[handle_ga4_serverside] [purchase_event create Ga4_Service_Client instance and call purchase_event] [%s]',
						    print_r( [
							    'order_id' => $order_aware_interface->get_order()
							                                        ->get_id(),
						    ], true )
					    ) );

			    try {
				    ( new Ga4_Service_Client() )->purchase_event( new Complete_Transation_Use_Case( $order_aware_interface->get_order() ) );
			    } catch ( Exception $e ) {
				    blue_media()
					    ->get_woocommerce_logger( 'analytics' )
					    ->log_error(
						    sprintf( '[handle_ga4_serverside] [purchase_event exception] [%s]',
							    print_r( [
								    'message'  => $e->getMessage(),
								    'order_id' => $order_aware_interface->get_order()
								                                        ->get_id(),
							    ], true )
						    ) );
			    }
		    } )
		    ->execute();
	}

	public function handle_ga4_serverside_by_itn( WC_Order $order ) {
		try {

			blue_media()
				->get_woocommerce_logger( 'analytics' )
				->log_debug(
					sprintf( '[handle_ga4_serverside_by_itn triggered] [%s]',
						print_r( [
							'order_id' => $order->get_id(),
						], true )
					) );

			( new Ga4_Service_Client() )->purchase_event( new Complete_Transation_Use_Case( $order ) );
		} catch ( Exception $e ) {
			blue_media()
				->get_woocommerce_logger( 'analytics' )
				->log_error(
					sprintf( '[handle_ga4_serverside_by_itn] [purchase_event exception] [%s]',
						print_r( [
							'message'  => $e->getMessage(),
							'order_id' => $order
								->get_id(),
						], true )
					) );
		}
	}
}
