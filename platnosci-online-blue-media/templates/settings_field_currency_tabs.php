<?php

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Domain\Service\Settings\Currency_Tabs;
use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs;

/**
 * @var Currency_Tabs $currency_tabs
 *
 * @var string $field_key
 * @var WC_Settings_API $wc_settings_api
 * @var string $active_tab
 */

$autopay_auth_tab_id            = Settings_Tabs::AUTHENTICATION_TAB_ID;
$autopay_active_currency_tab_id = $currency_tabs->get_active_tab_id();
$autopay_available_tabs         = $currency_tabs->get_available_tabs();
$autopay_hide_add_tab           = isset( $hide_add_tab ) && $hide_add_tab === true;
?>


<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text">

	<td class="forminp">
		<p class="autopay-currencies-text"><?php esc_html_e( 'Available currencies', 'platnosci-online-blue-media' ); ?></p>
		<nav id="autopay-currencies-menu"
			 class="nav-tab-wrapper woo-nav-tab-wrapper">
			<?php $autopay_i = 0;

			foreach ( $currency_tabs->get_tabs() as $autopay_currency ): $autopay_i ++ ?>
				<a href="<?php echo esc_url( admin_url( sprintf( "admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=%s&cur=%s",
						$active_tab,
						$autopay_currency->get_element_id()
					) ) ); ?>"
				   class="nav-tab<?php echo ( $autopay_currency->get_element_id() === $autopay_active_currency_tab_id ) ? ' nav-tab-active' : '' ?>"><?php echo esc_html( $autopay_currency->get_code() ) ?></a>
			<?php endforeach; ?>

			<?php if ( $autopay_available_tabs && ! $autopay_hide_add_tab ): ?>
				<div class="nav-tab autopay-add-currency-tab">
					<span class="dashicons dashicons-insert"></span>

					<ul class="autopay-select-currency-list hidden">

						<?php foreach ( $autopay_available_tabs as $autopay_k => $autopay_currency ) : ?>
							<li><span class="autopay-select-currency-item"
									  data-cur="<?php echo esc_attr( $autopay_currency->get_element_id() ); ?>"><?php echo esc_attr( $autopay_currency->get_code() ); ?></span>
							</li>

						<?php endforeach; ?>
					</ul>

				</div>
			<?php endif ?>


		</nav>
	</td>
</tr>
