<?php

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Settings\Currency_Tabs;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs;

/**
 * @var Currency_Tabs $currency_tabs
 * @var string $field_key
 * @var WC_Settings_API $wc_settings_api
 */

$autopay_auth_tab_id            = Settings_Tabs::AUTHENTICATION_TAB_ID;
$autopay_active_currency_tab_id = $currency_tabs->get_active_tab_id();
$autopay_available_tabs         = $currency_tabs->get_available_tabs();
?>

<?php if ( count( $currency_tabs->get_currency_manager()
								->get_selected_currencies() ) > 1 ): ?>

	<tr valign="top"
		class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text">

		<td class="forminp">


			<div class="autopay-remove-currency">
				<a class="bm_ga_help_modal" href="#"
				   data-modal="remove_currency_modal_target">
				   <img src="<?php echo esc_attr( plugin_dir_url( __FILE__ ) ); ?>../assets/img/remove.svg" alt="<?php esc_attr_e( 'Remove currency', 'platnosci-online-blue-media' ); ?>">
					<span><?php esc_html_e( 'Remove currency',
							'platnosci-online-blue-media' ); ?>: <?php echo esc_html( $currency_tabs->get_active_tab_currency()
																			 ->get_code() ) ?></span>
				</a>
			</div>


			<div class="bm-modal-content remove_currency_modal_target">
				<p><?php esc_html_e( 'Are you sure to remove currency configuration:',
						'platnosci-online-blue-media' ); ?><?php echo esc_html( $currency_tabs->get_active_tab_currency()
																	   ->get_code() ) ?></p>

				<div class="remove-currency-buttons-wrapper">
					<button type="button" class="autopay-remove-currency-item"
							data-cur="<?php echo esc_attr( $currency_tabs->get_active_tab_currency()
																   ->get_code() ); ?>"><?php esc_html_e( 'Yes',
							'platnosci-online-blue-media' ); ?></button>

					<button type="button" class="autopay-remove-currency-cancel bm-close"
							data-cur="<?php echo esc_attr( $currency_tabs->get_active_tab_currency()
																   ->get_code() ); ?>"><?php esc_html_e( 'Cancel',
							'platnosci-online-blue-media' ); ?></button>
				</div>
			</div>
		</td>
	</tr>
<?php endif ?>
