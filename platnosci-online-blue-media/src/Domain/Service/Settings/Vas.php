<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

use DOMDocument;
use DOMXPath;
use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Admin_Section_Resources_Client;
use Ilabs\BM_Woocommerce\Domain\Model\Settings\Woocomerce_Vas_Value_Object;

class Vas {

	public function get_vas_content(): string {
		try {
			$vas_value_object = $this->create_vas_value_object();

			return $this->parse_body_string(
				( new Admin_Section_Resources_Client() )
					->get_vas( $vas_value_object )
			);

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[get_vas_content error:] [%s]',
					print_r( $exception->getMessage(), true )
				) );

			return '';
		}
	}

	public function get_vas_src(): string {
		try {
			$vas_value_object = $this->create_vas_value_object();

			return ( new Admin_Section_Resources_Client() )->get_vas_url_with_params( $vas_value_object );

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[get_vas_url error:] [%s]',
					print_r( $exception->getMessage(), true )
				) );

			return '';
		}
	}

	private function parse_body_string( string $body ): string {
		$patterns = [
			'#<script(.*?)>(.*?)</script>#is',
			'#<link(.*?)>#is',
			'#<title(.*?)>(.*?)</title>#is',
			'#<meta(.*?)>#is',
			'#<!--(.*?)-->#is',
		];

		return preg_replace( $patterns, '', $body );
	}

	/**
	 * @return Woocomerce_Vas_Value_Object
	 * @throws Exception
	 */
	private function create_vas_value_object(): Woocomerce_Vas_Value_Object {
		$php_version = phpversion();

		return new Woocomerce_Vas_Value_Object(
			'woocommerce',
			WC()->version,
			'platnosci-online-blue-media',
			blue_media()->get_plugin_version(),
			'woocommerce_panel',
			'woocommerce_panel',
			'vas_tab',
			(string) get_bloginfo( "language" ),
			null,
			null
		);
	}
}
