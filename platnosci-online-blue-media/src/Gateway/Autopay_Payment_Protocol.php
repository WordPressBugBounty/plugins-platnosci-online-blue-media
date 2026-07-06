<?php
/**
 * Autopay payment integration: request field names, paths, XML local names, and transport markers.
 *
 * Values match Autopay/Blue Media web payment and continue-transaction documentation.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway;

/**
 * Single source of truth for protocol literals (no duplicated magic strings).
 */
final class Autopay_Payment_Protocol {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	// --- HTTP -----------------------------------------------------------------
	/**
	 * Path segment appended to gateway base URL for transaction start/continue POST.
	 */
	public const HTTP_PAYMENT_PATH = 'payment';

	/**
	 * BmHeader value required for continue-transaction style POST to /payment.
	 */
	public const HTTP_HEADER_BM_CONTINUE_TRANSACTION = 'pay-bm-continue-transaction-url';

	/**
	 * Prefix returned by {@see Client} when Guzzle throws (string detection, not structured errors).
	 */
	public const HTTP_TRANSPORT_ERROR_LEADER = 'Error: ';

	// --- XML response (local names, lowercase for XPath matching) ------------
	public const XML_LOCAL_CONFIRMATION = 'confirmation';

	public const XML_LOCAL_REASON = 'reason';

	public const XML_LOCAL_HASH = 'hash';

	public const XML_LOCAL_STATUS = 'status';

	public const XML_LOCAL_REDIRECT_URL = 'redirecturl';

	/**
	 * Value of confirmation when the gateway rejects the request (e.g. INVALID_HASH).
	 */
	public const CONFIRMATION_NOT_CONFIRMED = 'NOTCONFIRMED';

	// --- Form / hash field names (Autopay parameter names) --------------------
	public const FIELD_SERVICE_ID = 'ServiceID';

	public const FIELD_ORDER_ID = 'OrderID';

	public const FIELD_AMOUNT = 'Amount';

	public const FIELD_DESCRIPTION = 'Description';

	public const FIELD_GATEWAY_ID = 'GatewayID';

	public const FIELD_CURRENCY = 'Currency';

	public const FIELD_CUSTOMER_EMAIL = 'CustomerEmail';

	public const FIELD_CUSTOMER_IP = 'CustomerIP';

	public const FIELD_TITLE = 'Title';

	public const FIELD_PAYMENT_TOKEN = 'PaymentToken';

	public const FIELD_WALLET_TYPE = 'WalletType';

	public const FIELD_HASH = 'Hash';

	/**
	 * WalletType for card data collected in the partner iframe (Autopay param 54).
	 */
	public const WALLET_TYPE_WIDGET_VALUE = 'WIDGET';

	// --- Logging (card widget diagnostics) -----------------------------------
	public const LOG_SOURCE_PREFIX_CARD_WIDGET = '[CardWidget]';

	public const LOG_PLACEHOLDER_MASKED_HASH = '[masked]';

	public const LOG_PAYMENT_TOKEN_LENGTH_LABEL = 'b64_len';

	public const LOG_RAW_RESPONSE_PREVIEW_MAX_BYTES = 500;

	public const LOG_RAW_RESPONSE_ERROR_DUMP_MAX_BYTES = 4000;

	public const LOG_PARSED_REASON_SNIPPET_MAX_BYTES = 160;

	/**
	 * Log event identifiers (suffix after {@see self::LOG_SOURCE_PREFIX_CARD_WIDGET}).
	 */
	public const LOG_EVENT_HTTP_TRANSPORT_ERROR = 'http_transport_error';

	public const LOG_EVENT_RAW_RESPONSE = 'raw_response';

	public const LOG_EVENT_EMPTY_REDIRECTURL = 'empty_redirecturl';

	public const LOG_EVENT_REQUEST = 'request';

	public const LOG_EVENT_PARSED = 'parsed';

	/**
	 * {@see wp_json_encode()} / {@see json_encode()} flags for structured debug lines.
	 */
	public const JSON_ENCODE_FLAGS_LOG = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Separator between token length label and numeric value in masked logs.
	 */
	public const LOG_KEY_VALUE_SEPARATOR = '=';

	// --- XPath (case-insensitive local-name) -----------------------------------
	/**
	 * Arguments for translate() in XPath 1.0 local-name matching.
	 */
	public const XPATH_UPPERCASE_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	public const XPATH_LOWERCASE_LETTERS = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Allowed XML local names passed into XPath (defensive; values come from protocol constants).
	 */
	public const XML_LOCAL_NAME_VALIDATION_PATTERN = '/^[a-z0-9_-]+$/';

	// --- Binary ----------------------------------------------------------------
	/**
	 * UTF-8 byte order mark stripped before XML parse.
	 */
	public const BINARY_UTF8_BOM = "\xEF\xBB\xBF";

	/**
	 * Strip all whitespace before base64 decoding payment tokens from POST.
	 */
	public const PAYMENT_TOKEN_STRIP_WHITESPACE_PATTERN = '/\s+/';
}
