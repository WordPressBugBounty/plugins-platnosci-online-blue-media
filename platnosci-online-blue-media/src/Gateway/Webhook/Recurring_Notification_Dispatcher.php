<?php
/**
 * Routes RPAN/RPDN notifications away from the ITN entry point before the ITN
 * handler has a chance to run.
 *
 * @package bm-woocommerce
 */

namespace Ilabs\BM_Woocommerce\Gateway\Webhook;

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Gateway\Blue_Media_Gateway;
use SimpleXMLElement;

/**
 * Autopay sends RPAN/RPDN to the same `?wc-api=WC_Gateway_Bluemedia` endpoint as ITN,
 * carrying a different POST field (`recurring`, base64) instead of `transactions`.
 * `ITN_Webhook_Handler::webhook()` (registered at the default priority 10 on
 * `woocommerce_api_wc_gateway_bluemedia`) assumes `$_POST['transactions']` is present
 * as soon as `$_POST` is non-empty and would fatal on an undefined array key for a
 * request that only carries `recurring` — so this dispatcher MUST run first (lower
 * priority) and `exit` after fully handling a matched request, exactly like the ITN
 * handler does for its own requests. When `$_POST['recurring']` is absent this is a
 * no-op and execution falls through to the ITN handler unaffected — zero behaviour
 * change for ordinary ITN traffic.
 */
class Recurring_Notification_Dispatcher {

	private const POST_FIELD    = 'recurring';
	private const RPAN_ROOT_TAG = 'recurringActivation';
	private const RPDN_ROOT_TAG = 'recurringDeactivation';

	/**
	 * Runs before the ITN handler (priority 10) on the same action.
	 */
	private const HOOK_PRIORITY = 5;

	/**
	 * The main Autopay gateway instance.
	 *
	 * @var Blue_Media_Gateway
	 */
	private Blue_Media_Gateway $gateway;

	/**
	 * RPAN handler this dispatcher delegates to.
	 *
	 * @var RPAN_Webhook_Handler
	 */
	private RPAN_Webhook_Handler $rpan_handler;

	/**
	 * Constructor.
	 *
	 * @param Blue_Media_Gateway $gateway Gateway instance, passed through to handlers.
	 */
	public function __construct( Blue_Media_Gateway $gateway ) {
		$this->gateway      = $gateway;
		$this->rpan_handler = new RPAN_Webhook_Handler( $gateway );
	}

	/**
	 * Register the routing callback. Called once, mirroring how
	 * `ITN_Webhook_Handler::webhook()` registers its own callback.
	 */
	public function register(): void {
		add_action( 'woocommerce_api_wc_gateway_bluemedia', [ $this, 'maybe_handle' ], self::HOOK_PRIORITY );
	}

	/**
	 * Entry point registered on the shared webhook action.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Autopay webhook, authenticated by HMAC signature (validated inside each handler), not a WordPress nonce.
		if ( ! isset( $_POST[ self::POST_FIELD ] ) ) {
			return;
		}

		if ( ob_get_level() ) {
			ob_clean();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Autopay webhook, authenticated by HMAC signature (validated inside each handler), not a WordPress nonce; value is base64 data, decoded and signature-checked below before anything is trusted, so sanitize_text_field() would only corrupt it. wp_unslash() is still applied (harmless no-op for valid base64, which never contains a backslash).
		$raw_post_value = wp_unslash( $_POST[ self::POST_FIELD ] );

		if ( ! is_string( $raw_post_value ) ) {
			// WordPress does not guarantee scalar POST values (e.g. recurring[]=...
			// would arrive as an array) — reject explicitly rather than let a stray
			// array-to-string conversion notice/garbage value reach base64_decode().
			blue_media()->get_woocommerce_logger()->log_error(
				'[Recurring_Notification_Dispatcher] [recurring POST field is not a string]'
			);
			$this->respond_not_confirmed_and_exit();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Autopay webhook payload; base64 is the transport encoding mandated by the Autopay protocol, not obfuscation. Signature validated per-handler before any decoded value is trusted; "sanitizing" base64 ciphertext-like data would corrupt it. Strict mode below rejects malformed base64 explicitly instead of best-effort-decoding it into garbage.
		$decoded = base64_decode( $raw_post_value, true );

		if ( false === $decoded ) {
			blue_media()->get_woocommerce_logger()->log_error(
				'[Recurring_Notification_Dispatcher] [recurring POST field is not valid base64]'
			);
			$this->respond_not_confirmed_and_exit();
		}

		$xml = $this->safe_parse_xml( $decoded );

		if ( ! $xml instanceof SimpleXMLElement ) {
			blue_media()->get_woocommerce_logger()->log_error(
				'[Recurring_Notification_Dispatcher] [unparsable recurring payload — cannot determine RPAN vs RPDN]'
			);
			$this->respond_not_confirmed_and_exit();
		}

		$root_tag = $xml->getName();

		if ( self::RPAN_ROOT_TAG === $root_tag ) {
			$this->rpan_handler->handle( $xml );
			exit;
		}

		if ( self::RPDN_ROOT_TAG === $root_tag ) {
			// RPDN_Webhook_Handler does not exist yet. Fail safe rather
			// than fatal: do not silently confirm a notification we cannot act on, and do
			// not attempt to construct a class that is not there yet.
			if ( class_exists( __NAMESPACE__ . '\\RPDN_Webhook_Handler' ) ) {
				$rpdn_handler_class = __NAMESPACE__ . '\\RPDN_Webhook_Handler';
				( new $rpdn_handler_class( $this->gateway ) )->handle( $xml );
				exit;
			}

			blue_media()->get_woocommerce_logger()->log_warning(
				'[Recurring_Notification_Dispatcher] [RPDN received but RPDN_Webhook_Handler is not implemented yet]'
			);
			$this->respond_not_confirmed_and_exit();
		}

		blue_media()->get_woocommerce_logger()->log_error(
			sprintf(
				'[Recurring_Notification_Dispatcher] [unrecognized recurring root element] [root: %s]',
				esc_html( $root_tag )
			)
		);
		$this->respond_not_confirmed_and_exit();
	}

	/**
	 * Parse untrusted XML from an external payment processor defensively:
	 * - `libxml_use_internal_errors( true )` instead of `@` suppression, so malformed
	 *   input (an expected failure mode here, not a bug) produces no PHP warning while
	 *   still leaving normal error handling intact for anything else in the request;
	 * - `LIBXML_NONET` blocks any network access libxml might otherwise attempt while
	 *   resolving external references;
	 * - no `LIBXML_NOENT`/`LIBXML_DTDLOAD` flags are passed, so external entities are
	 *   not substituted (PHP/libxml's default since long before our minimum supported
	 *   version) — this call does not opt back into that behaviour.
	 *
	 * @param string $decoded Base64-decoded payload, expected to be XML.
	 */
	private function safe_parse_xml( string $decoded ): ?SimpleXMLElement {
		$previous_setting = libxml_use_internal_errors( true );
		$xml              = simplexml_load_string( $decoded, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		return $xml instanceof SimpleXMLElement ? $xml : null;
	}

	/**
	 * Safe fallback response for anything we cannot parse or do not yet handle:
	 * signature/structure could not be established as valid, so we do not claim
	 * CONFIRMED — Autopay's own documented retry schedule will resend the notification.
	 */
	private function respond_not_confirmed_and_exit(): void {
		header( 'HTTP/1.0 400 Bad Request' );
		exit;
	}
}
