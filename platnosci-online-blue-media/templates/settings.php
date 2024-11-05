<?php

use Ilabs\BM_Woocommerce\Domain\Service\Settings\Settings_Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * @var string $settings_html
 * @var string $title
 * @var string $tab_id
 * * @var string $subtitle
 *
 */

$title    = esc_attr( $title );
$subtitle = esc_attr( $subtitle );
$tab_id   = esc_attr( $tab_id );


?>


<div class="autopay-settings-section section-<?php esc_attr_e( $tab_id ); ?>">

	<?php if ( ! empty( $title ) ): ?>
		<?php
		blue_media()->locate_template( 'settings_section_header.php',
			[
				'title'    => $title,
				'subtitle' => $subtitle,
			] ); ?>
	<?php endif; ?>


	<?php if ( Settings_Tabs::ADVANCED_SETTINGS_TAB_ID === $tab_id ): ?>
		<div class="autopay-settings-sidebar section-<?php esc_attr_e( $tab_id ); ?>">
			<?php
			blue_media()->locate_template( 'settings-advanced-sidebar.php' ); ?>
		</div>
	<?php endif; ?>


	<table class="form-table">
		<?php echo $settings_html ?>
	</table>


</div>
