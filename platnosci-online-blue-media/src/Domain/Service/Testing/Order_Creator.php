<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Testing;


use Exception;
use WC_Product;

class Order_Creator {

	/**
	 * @throws Exception
	 */
	public function create() {
		$hash         = $this->generate_random_hash();
		$product_name = 'autopay_test_product_' . $hash;

		$product_id = $this->create_test_product( $product_name );

		if ( ! $product_id ) {
			throw new Exception( __( 'Failed to create product',
				'bm-woocommerce' ) );
		}

		$order_id = $this->create_test_order( $product_id );

		if ( ! $order_id ) {
			throw new Exception( __( 'Failed to create order',
				'bm-woocommerce' ) );
		}

		return wc_get_order( $order_id );
	}

	/**
	 * @throws Exception
	 */
	public function remove( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			throw new Exception( __( 'Failed to delete test order',
				'bm-woocommerce' ) );
		}

		// Check if it's a test order
		if ( $order->get_meta( 'autopay_test_order' ) === '1' ) {
			// Get all items in the order
			$items = $order->get_items();
			foreach ( $items as $item ) {
				$product_id = $item->get_product_id();
				// Check if the product is a test product
				if ( get_post_meta( $product_id,
						'autopay_test_product',
						true ) === 'true' ) {
					$order->remove_item( $item->get_id() );
					wp_trash_post( $product_id );
				}
			}

			$order->delete();
		} else {
			throw new Exception( __( 'Failed to delete test order',
				'bm-woocommerce' ) );
		}
	}

	private function create_test_product( $product_name ): int {
		$product = new WC_Product();
		$product->set_name( $product_name );
		$product->set_status( 'private' ); // Make product not visible to public
		$product->set_price( '10.00' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$product_id = $product->get_id();

		if ( $product_id ) {
			update_post_meta( $product_id, 'autopay_test_product', 'true' );
		}

		return $product_id;
	}

	private function create_test_order( $product_id ): int {
		$order = wc_create_order();

		$order->set_address( $this->get_billing_address(), 'billing' );
		$order->set_address( $this->get_shipping_address(), 'shipping' );

		$order->add_product( wc_get_product( $product_id ),
			1 ); // Add product with quantity 1

		$order->calculate_totals();
		$order->add_meta_data( 'autopay_test_order', '1' );
		$order->save();

		return $order->get_id();
	}

	private function generate_random_hash( $length = 6 ) {
		return substr( str_shuffle( '0123456789abcdefghijklmnopqrstuvwxyz' ),
			0,
			$length );
	}

	// Placeholder methods for getting address data
	private function get_billing_address() {
		return [
			'first_name' => 'Test',
			'last_name'  => 'User',
			'company'    => '',
			'address_1'  => '123 Test St',
			'address_2'  => '',
			'city'       => 'Test City',
			'state'      => 'CA',
			'postcode'   => '90001',
			'country'    => 'PL',
			'email'      => 'test@autopay.com',
			'phone'      => '555-555-5555',
		];
	}

	private function get_shipping_address() {
		return [
			'first_name' => 'Test',
			'last_name'  => 'User',
			'company'    => '',
			'address_1'  => '123 Test St',
			'address_2'  => '',
			'city'       => 'Test City',
			'state'      => 'PL',
			'postcode'   => '90001',
			'country'    => 'US',
		];
	}
}

