<?php

namespace Ilabs\BM_Woocommerce\Domain\Service\Settings;

use Exception;
use Ilabs\BM_Woocommerce\Data\Remote\Blue_Media\Admin_Section_Resources_Client;
use Ilabs\BM_Woocommerce\Domain\Model\Settings\Woocomerce_Vas_Value_Object;

class Banner {

	public function get_banner_content(): string {
		try {
			$vas_value_object = $this->create_vas_value_object();

			return $this->parse_body_string(
				( new Admin_Section_Resources_Client() )
					->get_banner( $vas_value_object )
			);
		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[get_banner_content error:] [%s]',
					print_r( $exception->getMessage(), true )
				) );

			return '';
		}
	}

	public function get_banner_src(): string {
		try {
			$vas_value_object = $this->create_vas_value_object();

			return (new Admin_Section_Resources_Client())->resolve_banner_url( $vas_value_object->get_language_code() );

		} catch ( Exception $exception ) {
			blue_media()->get_woocommerce_logger()->log_error(
				sprintf( '[get_banner_url error:] [%s]',
					print_r( $exception->getMessage(), true )
				) );

			return '';
		}
	}

	private function parse_body_string( string $body ): string {

		$patterns = [
			'#<script(.*?)>(.*?)</script>#is',  // Usuwanie znaczników <script>
			'#<link(.*?)>#is',                  // Usuwanie znaczników <link>
			'#<title(.*?)>(.*?)</title>#is',    // Usuwanie znaczników <title>
			'#<meta(.*?)>#is',                  // Usuwanie znaczników <meta>
			'#<!--(.*?)-->#is'                  // Usuwanie komentarzy HTML
		];

		return preg_replace($patterns, '', $body);
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
			blue_media()->get_blue_media_gateway()->get_service_id(),
			$php_version ?: null
		);
	}
}
