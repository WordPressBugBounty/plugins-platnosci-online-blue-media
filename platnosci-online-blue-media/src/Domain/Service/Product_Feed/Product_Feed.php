<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Product_Feed;

use SimpleXMLElement;

class Product_Feed {

	public function init() {
		if (
			isset( $_GET['product_feed'] ) && blue_media()
				                                  ->get_blue_media_gateway()
				                                  ->get_option( 'campaign_tracking',
					                                  'no' ) === 'yes'
		) {
			$this->generate_google_product_feed();
		}
	}

	function generate_google_product_feed() {
		error_reporting( E_ERROR | E_WARNING | E_PARSE );

		$products = wc_get_products( [ 'status' => 'publish' ] );

		$store_name        = get_bloginfo( 'name' );
		$store_description = get_bloginfo( 'description' );

		$rss     = new SimpleXMLElement( '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"></rss>' );
		$channel = $rss->addChild( 'channel' );
		$channel->addChild( 'title', $store_name );
		$channel->addChild( 'link', home_url() );
		$channel->addChild( 'description', $store_description );

		foreach ( $products as $product ) {
			$product_id           = $product->get_id();
			$product_title        = $product->get_name();
			$product_link         = get_permalink( $product_id );
			$product_image        = wp_get_attachment_url( $product->get_image_id() ) ?? 'false';
			$product_price        = $product->get_price();
			$product_description  = wp_strip_all_tags( $product->get_description(),
				true );
			$product_sku          = $product->get_sku();
			$product_availability = $product->is_in_stock() ? 'in stock' : 'out of stock';
			$product_condition    = 'New';
			$product_gtin         = $product->get_meta( '_global_unique_id',
				true );

			if ( ! empty( $product_image ) ) {
				$item = $channel->addChild( 'item' );
				$item->addChild( 'g:id', $product_id, 'g' );
				$item->addChild( 'title', $product_title );
				$item->addChild( 'link', $product_link );
				$item->addChild( 'description', $product_description );
				$item->addChild( 'g:image_link', $product_image, 'g' );
				$item->addChild( 'g:price',
					$product_price . ' ' . get_woocommerce_currency(),
					'g' );
				$item->addChild( 'g:condition', $product_condition, 'g' );
				$item->addChild( 'g:availability', $product_availability, 'g' );
				$item->addChild( 'g:mpn', $product_sku, 'g' );

				if ( ! empty( $product_gtin ) ) {
					$item->addChild( 'g:gtin', $product_gtin, 'g' );
				} else {
					$item->addChild( 'g:identifier_exists', 'false', 'g' );
				}
			}
		}

		header( 'Content-Type: application/xml; charset=utf-8' );
		echo $rss->asXML();
		exit();
	}
}
