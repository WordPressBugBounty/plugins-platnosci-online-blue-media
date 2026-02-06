<?php

namespace Ilabs\BM_Woocommerce\Assets;

/**
 * Manages loading of frontend and admin assets for the payment gateway.
 *
 * Separates asset management concerns from the main gateway class.
 */
class AssetManager {

    private string $plugin_version;
    private string $plugin_base_file;

    public function __construct( string $plugin_version, string $plugin_base_file ) {
        $this->plugin_version = $plugin_version;
        $this->plugin_base_file = $plugin_base_file;
    }

    /**
     * Initialize asset loading hooks.
     */
    public function init(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Enqueue admin assets for payment method sorting functionality.
     */
    public function enqueue_admin_assets( string $hook ): void {
        // Only load on WooCommerce settings page
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        // Only load on Checkout > Autopay section
        $is_checkout_tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout';
        $is_autopay_section = isset( $_GET['section'] ) && $_GET['section'] === 'bluemedia';

        if ( ! ( $is_checkout_tab && $is_autopay_section ) ) {
            return;
        }

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_enqueue_script(
            'bm-admin-sortable',
            plugins_url( 'assets/js/admin-sortable.js', $this->plugin_base_file ),
            [ 'jquery', 'jquery-ui-sortable' ],
            $this->plugin_version,
            true
        );
    }

    /**
     * Get plugin base file path for asset URL generation.
     */
    public function get_plugin_base_file(): string {
        return $this->plugin_base_file;
    }
}
