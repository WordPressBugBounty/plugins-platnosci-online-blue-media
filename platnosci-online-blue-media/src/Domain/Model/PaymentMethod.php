<?php

namespace Ilabs\BM_Woocommerce\Domain\Model;

/**
 * PaymentMethod value object representing a single payment method with stable identification.
 *
 * This class encapsulates payment method data and provides stable slug generation
 * that is independent of localization and translation changes.
 */
class PaymentMethod {

    private string $id;
    private string $name;
    private array $gateway_ids;
    private bool $is_expandable;

    public function __construct( string $id, string $name, array $gateway_ids = [], bool $is_expandable = false ) {
        $this->id = $id;
        $this->name = $name;
        $this->gateway_ids = $gateway_ids;
        $this->is_expandable = $is_expandable;
    }

    /**
     * Generate stable slug that doesn't change with translations.
     * Uses gateway ID for specific methods, human-readable for expandable groups.
     */
    public function get_stable_slug(): string {
        // Special case for expandable bank transfer list
        if ( $this->is_expandable ) {
            return 'online-bank-transfer';
        }

        // Use first gateway ID for stable identification
        if ( ! empty( $this->gateway_ids ) ) {
            return 'gateway-' . (int) $this->gateway_ids[0];
        }

        // Fallback to sanitized name
        return sanitize_title( $this->name );
    }

    /**
     * Generate human-readable slug for legacy compatibility.
     */
    public function get_readable_slug(): string {
        return sanitize_title( $this->name );
    }

    public function get_id(): string {
        return $this->id;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_gateway_ids(): array {
        return $this->gateway_ids;
    }

    public function is_expandable(): bool {
        return $this->is_expandable;
    }

    public function is_apple_pay(): bool {
        return in_array( 1513, $this->gateway_ids, true );
    }

    public function is_google_pay(): bool {
        return in_array( 1512, $this->gateway_ids, true );
    }

    public function has_gateway_id( int $gateway_id ): bool {
        return in_array( $gateway_id, $this->gateway_ids, true );
    }
}
