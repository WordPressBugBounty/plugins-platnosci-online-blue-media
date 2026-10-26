<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "bm" in package name is a pre-existing convention across this codebase.
/**
 * Recurring_Logger — dedicated WooCommerce log source for the card-recurring
 * (channel 1503) lifecycle: detection, request building, hashing, session
 * hand-off, and the request actually sent to Autopay.
 *
 * Every event lands in its own WooCommerce log file, selectable in
 * WooCommerce -> Status -> Logs under {@see self::SOURCE} -- so the whole
 * lifecycle of one activation attempt can be read in isolation instead of
 * being interleaved with every other gateway log line.
 *
 * Wraps {@see \Ilabs\BM_Woocommerce\Plugin::get_woocommerce_logger()} rather
 * than reimplementing logging -- this reuses the SAME debug-mode gate every
 * other logger in this plugin already respects (WooCommerce gateway "Debug
 * mode" option, or the `autopay_debug_enabled` transient); no separate
 * on/off switch is introduced for recurring logging.
 *
 * @package Ilabs\BM_Woocommerce\Gateway\Recurring
 */

namespace Ilabs\BM_Woocommerce\Gateway\Recurring;

defined( 'ABSPATH' ) || exit;

/**
 * Structured logging for the card-recurring (1503) lifecycle.
 */
class Recurring_Logger {

	/**
	 * WooCommerce log source name -- selectable in WooCommerce -> Status -> Logs.
	 */
	public const SOURCE = 'bm_woocommerce_recurring';

	/**
	 * Context keys whose value is dropped outright and replaced with a fixed
	 * placeholder, matched case-insensitively with underscores stripped (so
	 * `private_key`, `PrivateKey`, and `privatekey` are all caught). These are
	 * secrets, card/wallet tokens, or auth material -- never partial, never masked.
	 */
	private const REDACT_FULLY = [
		'privatekey',
		'sharedkey',
		'secretkey',
		'secret',
		'authorization',
		'password',
		'paymenttoken',
		'atpcardpaymenttoken',
		'atpgpaypaymenttoken',
		'bluemediablikcode',
		'blikcode',
	];

	/**
	 * Context keys shown only as a masked tail (last 4 characters) -- enough
	 * for cross-log correlation (matching the same value across events)
	 * without exposing the full identifier.
	 */
	private const REDACT_TAIL = [
		'clienthash',
		'recurringacceptanceid',
	];

	/**
	 * Context keys treated as an email address -- kept only in masked form
	 * (first character of the local part + full domain).
	 */
	private const REDACT_EMAIL = [
		'customeremail',
	];

	/**
	 * Context keys whose value is the request/response signature itself --
	 * logged as presence + length only, never the value.
	 */
	private const HASH_KEYS = [
		'hash',
	];

	/**
	 * Log a recurring-lifecycle event at debug level.
	 *
	 * @param string $event   Short, stable event identifier, e.g. `recurring_params_built`.
	 * @param array  $context Structured context. Order of keys is preserved in the
	 *                        log line (PHP arrays and `wp_json_encode()` both iterate
	 *                        in insertion order) -- pass field lists/params arrays as-is
	 *                        when the caller needs to record field order.
	 */
	public function debug( string $event, array $context = [] ): void {
		$this->write( 'log_debug', $event, $context );
	}

	/**
	 * Log a recurring-lifecycle event at warning level.
	 *
	 * @param string $event   Short, stable event identifier.
	 * @param array  $context Structured context, see {@see self::debug()}.
	 */
	public function warning( string $event, array $context = [] ): void {
		$this->write( 'log_warning', $event, $context );
	}

	/**
	 * Log a recurring-lifecycle event at error level.
	 *
	 * @param string $event   Short, stable event identifier.
	 * @param array  $context Structured context, see {@see self::debug()}.
	 */
	public function error( string $event, array $context = [] ): void {
		$this->write( 'log_error', $event, $context );
	}

	/**
	 * Sanitize the context and write it to the dedicated recurring log source.
	 *
	 * @param string $method  One of `log_debug`/`log_warning`/`log_error` on
	 *                        the underlying Woocommerce_Logger.
	 * @param string $event   Short, stable event identifier.
	 * @param array  $context Raw (unsanitized) context.
	 */
	private function write( string $method, string $event, array $context ): void {
		blue_media()->get_woocommerce_logger( self::SOURCE )->{$method}(
			sprintf(
				'[%s] %s',
				$event,
				wp_json_encode( $this->sanitize( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			)
		);
	}

	/**
	 * Recursively redact/mask known-sensitive fields by key name. Never
	 * inspects values to guess sensitivity -- only the denylists above decide
	 * what gets touched; every other value passes through unchanged, including
	 * plain field-name lists (e.g. `array_keys($params)`), which carry no
	 * values at all and are always safe to log in full.
	 *
	 * @param array $context Raw context.
	 */
	private function sanitize( array $context ): array {
		$sanitized = [];

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize( $value );
				continue;
			}

			$normalized = self::normalize_key( $key );

			if ( in_array( $normalized, self::REDACT_FULLY, true ) ) {
				$sanitized[ $key ] = '[redacted]';
				continue;
			}

			if ( in_array( $normalized, self::HASH_KEYS, true ) && is_string( $value ) ) {
				$sanitized[ $key ] = '' === $value ? '' : sprintf( '[present len=%d]', strlen( $value ) );
				continue;
			}

			if ( in_array( $normalized, self::REDACT_TAIL, true ) && is_string( $value ) ) {
				$sanitized[ $key ] = '' === $value ? '' : self::mask_tail( $value );
				continue;
			}

			if ( in_array( $normalized, self::REDACT_EMAIL, true ) && is_string( $value ) ) {
				$sanitized[ $key ] = self::mask_email( $value );
				continue;
			}

			$sanitized[ $key ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Normalize a context key for denylist matching: lowercase, underscores
	 * stripped -- so `client_hash`, `ClientHash`, and `clientHash` all match
	 * the same denylist entry regardless of the caller's naming style.
	 *
	 * @param int|string $key Raw array key.
	 */
	private static function normalize_key( $key ): string {
		return is_string( $key ) ? strtolower( str_replace( '_', '', $key ) ) : '';
	}

	/**
	 * Mask all but the last `$keep` characters of a value -- e.g. a
	 * `ClientHash` or `RecurringAcceptanceID`, kept correlatable across log
	 * lines without exposing the identifier itself. Mirrors the masking
	 * already used elsewhere in this plugin (e.g.
	 * `Transaction_Request_Builder::secure_key()`,
	 * `RPAN_Webhook_Handler::mask_client_hash()`).
	 *
	 * @param string $value Raw value.
	 * @param int    $keep  Number of trailing characters to leave visible.
	 */
	public static function mask_tail( string $value, int $keep = 4 ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= $keep ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return str_repeat( '*', strlen( $value ) - $keep ) . substr( $value, -$keep );
	}

	/**
	 * Mask an email address: first character of the local part, the rest of
	 * the local part replaced with `*`, domain kept intact (useful for
	 * spotting a wrong-domain/typo pattern without exposing the address).
	 *
	 * @param string $email Raw email address.
	 */
	public static function mask_email( string $email ): string {
		if ( '' === $email ) {
			return '';
		}

		$at_position = strpos( $email, '@' );

		if ( false === $at_position ) {
			return self::mask_tail( $email, 2 );
		}

		$local  = substr( $email, 0, $at_position );
		$domain = substr( $email, $at_position );
		$first  = '' === $local ? '' : $local[0];

		return $first . str_repeat( '*', max( 0, strlen( $local ) - 1 ) ) . $domain;
	}
}
