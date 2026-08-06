<?php use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs;

defined( 'ABSPATH' ) || exit; ?>

<?php

/**
 * @var Settings_Tabs $tabs
 */

$autopay_active_tab_id = $tabs->get_active_tab_id();
$autopay_tabs          = $tabs->get_available_tabs();
$autopay_last          = array_key_last( $autopay_tabs );
?>


<div class="bm-settings-tabs" style="display: flex">
	<ul class="autopay-tabs">
		<?php foreach ( $autopay_tabs as $autopay_tab_id => $autopay_tab_name ): ?>
			<?php
			// Show VAS tab only for Polish locale (pl_PL), per requirements.
			if ( $autopay_tab_id === Settings_Tabs::VAS_TAB_ID && get_locale() !== 'pl_PL' ) {
				continue;
			}
			?>
			<?php if ( $autopay_tab_id === $autopay_active_tab_id ): ?>
				<li class="autopay-tab current">
					<?php echo esc_html( $autopay_tab_name ) ?>
				</li>
			<?php else: ?>
				<li class="autopay-tab">
					<a href="<?php echo esc_url( admin_url( "admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=$autopay_tab_id" ) ); ?>"><?php echo esc_html( $autopay_tab_name ) ?></a>
				</li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
</div>
