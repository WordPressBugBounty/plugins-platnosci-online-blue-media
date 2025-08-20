<?php

namespace Ilabs\BM_Woocommerce\Data\Remote\Blue_Media;

use DOMDocument;
use DOMXPath;
use Exception;
use Ilabs\BM_Woocommerce\Domain\Model\Settings\Woocomerce_Vas_Value_Object;
use \Isolated\Blue_Media\Isolated_Guzzlehttp\GuzzleHttp\Client as GuzzleHttpClient;
use Isolated\Blue_Media\Isolated_Guzzlehttp\GuzzleHttp\Exception\GuzzleException;
use \Isolated\Blue_Media\Isolated_Guzzlehttp\GuzzleHttp\Exception\RequestException;

class Admin_Section_Resources_Client {

	private const VAS_BASE_URL_PL = 'https://plugins-api.autopay.pl/woocomerce-vas';

	private const VAS_BASE_URL_EN = 'https://plugins-api.autopay.pl/en/woocommerce-vas';
	private const VAS_BASE_URL_ES = 'https://plugins-api.autopay.pl/es/woocommerce-vas';
	private const VAS_BASE_URL_IT = 'https://plugins-api.autopay.pl/it/woocommerce-vas';
	private const VAS_BASE_URL_DE = 'https://plugins-api.autopay.pl/de/woocommerce-vas';

	private const BANNER_URL_PL = 'https://plugins-api.autopay.pl/plugins-baner-woocomerce/';

	private const BANNER_URL_EN = 'https://plugins-api.autopay.pl/en/plugins-baner-woocomerce/';
	private const BANNER_URL_ES = 'https://plugins-api.autopay.pl/es/plugins-baner-woocomerce/';
	private const BANNER_URL_IT = 'https://plugins-api.autopay.pl/it/plugins-baner-woocomerce/';
	private const BANNER_URL_DE = 'https://plugins-api.autopay.pl/de/plugins-baner-woocomerce/';


	private GuzzleHttpClient $client;

	public function __construct() {
		$this->client = new GuzzleHttpClient();
	}

	/**
	 * @throws GuzzleException
	 * @throws Exception
	 */
	public function get_vas( Woocomerce_Vas_Value_Object $vas_value_object
	): string {
		$base_url = $this->resolve_vas_url( $vas_value_object->get_language_code() );

		$query_params = [
			'ecommerce'         => $vas_value_object->get_ecommerce(),
			'ecommerce_version' => $vas_value_object->get_ecommerce_version(),
			'plugin_name'       => $vas_value_object->get_plugin_name(),
			'plugin_version'    => $vas_value_object->get_plugin_version(),
			'utm_campaign'      => $vas_value_object->get_utm_campaign(),
			'utm_source'        => $vas_value_object->get_utm_source(),
			'utm_medium'        => $vas_value_object->get_utm_medium(),
		];

		if ( $vas_value_object->get_service_id() ) {
			$query_params['service_id'] = $vas_value_object->get_service_id();
		}

		try {
			$response = $this->client->request( 'GET', $base_url, [
				'query'   => $query_params,
				'timeout' => 5,
			] );

			return $response->getBody()->getContents();

		} catch ( RequestException $e ) {
			throw new Exception( 'Request failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @throws GuzzleException
	 * @throws Exception
	 */
	public function get_banner( Woocomerce_Vas_Value_Object $vas_value_object
	): string {
		$base_url = $this->resolve_banner_url( $vas_value_object->get_language_code() );

		$query_params = [
			'ecommerce'         => $vas_value_object->get_ecommerce(),
			'ecommerce_version' => $vas_value_object->get_ecommerce_version(),
			'plugin_name'       => $vas_value_object->get_plugin_name(),
			'plugin_version'    => $vas_value_object->get_plugin_version(),
			'utm_campaign'      => $vas_value_object->get_utm_campaign(),
			'utm_source'        => $vas_value_object->get_utm_source(),
			'utm_medium'        => $vas_value_object->get_utm_medium(),
		];

		if ( $vas_value_object->get_service_id() ) {
			$query_params['service_id'] = $vas_value_object->get_service_id();
		}

		if ( $vas_value_object->get_programming_language_version() ) {
			$query_params['programming_language_version'] = $vas_value_object->get_programming_language_version();
		}

		try {
			$response = $this->client->request( 'GET', $base_url, [
				'query' => $query_params,
				'timeout' => 5,
			] );

			return $response->getBody()->getContents();

		} catch ( RequestException $e ) {
			throw new Exception( 'Request failed: ' . $e->getMessage() );
		}
	}

	public function get_banner_url_with_params(
		Woocomerce_Vas_Value_Object $vas_value_object
	): string {
		$base_url = $this->resolve_banner_url( $vas_value_object->get_language_code() );

		$query_params = [
			'ecommerce'         => $vas_value_object->get_ecommerce(),
			'ecommerce_version' => $vas_value_object->get_ecommerce_version(),
			'plugin_name'       => $vas_value_object->get_plugin_name(),
			'plugin_version'    => $vas_value_object->get_plugin_version(),
			'utm_campaign'      => $vas_value_object->get_utm_campaign(),
			'utm_source'        => $vas_value_object->get_utm_source(),
			'utm_medium'        => $vas_value_object->get_utm_medium(),
		];

		return add_query_arg( $query_params, $base_url );
	}

	public function get_vas_url_with_params(
		Woocomerce_Vas_Value_Object $vas_value_object
	): string {
		$base_url = $this->resolve_vas_url( $vas_value_object->get_language_code() );

		$query_params = [
			'ecommerce'         => $vas_value_object->get_ecommerce(),
			'ecommerce_version' => $vas_value_object->get_ecommerce_version(),
			'plugin_name'       => $vas_value_object->get_plugin_name(),
			'plugin_version'    => $vas_value_object->get_plugin_version(),
			'utm_campaign'      => $vas_value_object->get_utm_campaign(),
			'utm_source'        => $vas_value_object->get_utm_source(),
			'utm_medium'        => $vas_value_object->get_utm_medium(),
		];

		return add_query_arg( $query_params, $base_url );
	}


	private function resolve_vas_url( string $language_code ) {
		$prefix = substr( strtolower( $language_code ), 0, 2 );
		switch ( $prefix ) {
			case 'pl':
				return self::VAS_BASE_URL_PL;
			case 'es':
				return self::VAS_BASE_URL_ES;
			case 'it':
				return self::VAS_BASE_URL_IT;
			case 'de':
				return self::VAS_BASE_URL_DE;
			default:
				return self::VAS_BASE_URL_EN;
		}
	}

	public function resolve_banner_url( string $language_code ) {
		$prefix = substr( strtolower( $language_code ), 0, 2 );
		switch ( $prefix ) {
			case 'pl':
				return self::BANNER_URL_PL;
			case 'es':
				return self::BANNER_URL_ES;
			case 'it':
				return self::BANNER_URL_IT;
			case 'de':
				return self::BANNER_URL_DE;
			default:
				return self::BANNER_URL_EN;
		}
	}
}
