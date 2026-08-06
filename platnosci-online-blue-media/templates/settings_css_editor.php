<?php

use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;

defined( 'ABSPATH' ) || exit;

/**
 * @var Css_Editor $editor
 */
?>

<div class="bm-settings-css-editor">
	<h3><?php esc_html_e( 'Use own CSS styles', 'platnosci-online-blue-media' ) ?></h3>

	<div>
		<?php wp_nonce_field( 'autopay_css_editor_nonce', 'autopay_css_editor_nonce_field' ); ?>
		<?php
		$editor->display_editor();
		?>

		<p><?php esc_html_e( 'Use this feature carefully. The CSS code you enter may cause unexpected visual changes to your Checkout page.',
				'platnosci-online-blue-media' ) ?></p>

	</div>

	<p class="submit">
		<input type="submit" value="<?php esc_attr_e( 'Save changes',
			'platnosci-online-blue-media' ) ?>" class="button-primary">
	</p>
</div>
