<?php use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs;

defined( 'ABSPATH' ) || exit; ?>

<?php

/**
 * @var Settings_Tabs $tabs
 */

$active_tab_id = $tabs->get_active_tab_id();
$tabs          = $tabs->get_available_tabs();
$last          = array_key_last( $tabs );
?>


<div class="bm-settings-tabs" style="display: flex">
	<ul class="autopay-tabs">
		<?php foreach ( $tabs as $tab_id => $tab_name ): ?>
			<?php if ( $tab_id === $active_tab_id ): ?>
				<li class="autopay-tab current">
					<?php echo esc_html( $tab_name ) ?>
				</li>
			<?php else: ?>
				<li class="autopay-tab">
					<a href="<?php esc_attr_e( admin_url( "admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=$tab_id" ) ) ?>"><?php echo esc_html( $tab_name ) ?></a>
				</li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
</div>
