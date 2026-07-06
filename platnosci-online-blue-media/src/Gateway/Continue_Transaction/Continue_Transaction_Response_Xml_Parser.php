<?php
/**
 * Parses Autopay XML responses from the payment / continue-transaction endpoint.
 *
 * Element matching is case-insensitive and ignores XML namespaces so variants like
 * `redirectUrl` and `redirecturl` are handled consistently.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Continue_Transaction;

use Ilabs\BM_Woocommerce\Gateway\Autopay_Payment_Protocol;

/**
 * Stateless parser for transaction continuation XML payloads.
 */
final class Continue_Transaction_Response_Xml_Parser {

	/**
	 * Parse raw XML into a normalized associative array for gateway use.
	 *
	 * @param string $response_xml Raw XML string from Autopay.
	 *
	 * @return array<string, string>
	 */
	public static function parse( string $response_xml ): array {
		$trimmed = trim( $response_xml );
		$bom       = Autopay_Payment_Protocol::BINARY_UTF8_BOM;
		$bom_len   = strlen( $bom );
		if ( strncmp( $trimmed, $bom, $bom_len ) === 0 ) {
			$trimmed = substr( $trimmed, $bom_len );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string(
			$trimmed,
			'SimpleXMLElement',
			LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		if ( false === $xml ) {
			return [];
		}

		$confirmation = self::first_value_by_local_name_ci(
			$xml,
			Autopay_Payment_Protocol::XML_LOCAL_CONFIRMATION
		);
		if ( strtoupper( (string) $confirmation ) === Autopay_Payment_Protocol::CONFIRMATION_NOT_CONFIRMED ) {
			return [
				Autopay_Payment_Protocol::XML_LOCAL_CONFIRMATION => $confirmation,
				Autopay_Payment_Protocol::XML_LOCAL_REASON       => self::first_value_by_local_name_ci(
					$xml,
					Autopay_Payment_Protocol::XML_LOCAL_REASON
				),
				Autopay_Payment_Protocol::XML_LOCAL_HASH         => self::first_value_by_local_name_ci(
					$xml,
					Autopay_Payment_Protocol::XML_LOCAL_HASH
				),
			];
		}

		return [
			Autopay_Payment_Protocol::XML_LOCAL_STATUS      => self::first_value_by_local_name_ci(
				$xml,
				Autopay_Payment_Protocol::XML_LOCAL_STATUS
			),
			Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL => self::first_value_by_local_name_ci(
				$xml,
				Autopay_Payment_Protocol::XML_LOCAL_REDIRECT_URL
			),
			Autopay_Payment_Protocol::XML_LOCAL_HASH        => self::first_value_by_local_name_ci(
				$xml,
				Autopay_Payment_Protocol::XML_LOCAL_HASH
			),
		];
	}

	/**
	 * Return the text content of the first element whose local name matches (case-insensitive).
	 *
	 * @param \SimpleXMLElement $xml   Parsed document (any node; search is descendant).
	 * @param string            $local Lowercase local name (alphanumeric, underscore, hyphen only).
	 *
	 * @return string Trimmed text or empty string when not found.
	 */
	private static function first_value_by_local_name_ci( \SimpleXMLElement $xml, string $local ): string {
		if ( ! preg_match( Autopay_Payment_Protocol::XML_LOCAL_NAME_VALIDATION_PATTERN, $local ) ) {
			return '';
		}

		$xpath = '//*[translate(local-name(), "'
			. Autopay_Payment_Protocol::XPATH_UPPERCASE_LETTERS
			. '", "'
			. Autopay_Payment_Protocol::XPATH_LOWERCASE_LETTERS
			. '")="'
			. $local . '"]';

		$nodes = $xml->xpath( $xpath );
		if ( ! is_array( $nodes ) || ! isset( $nodes[0] ) ) {
			return '';
		}

		return trim( (string) $nodes[0] );
	}
}
