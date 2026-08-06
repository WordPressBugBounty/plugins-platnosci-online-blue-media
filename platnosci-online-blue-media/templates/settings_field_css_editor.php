<?php

use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;

defined( 'ABSPATH' ) || exit;

/**
 * @var Css_Editor $editor
 */
?>

<tr valign="top" class="autopay-comp-css-editor">
	<th scope="row"
		class="titledesc"><?php esc_html_e( 'Use own CSS styles',
			'platnosci-online-blue-media' ) ?></th>
	<td class="forminp">
		<fieldset>
			<?php wp_nonce_field( 'autopay_css_editor_nonce', 'autopay_css_editor_nonce_field' ); ?>
		<p class="warning"><?php esc_html_e( 'Use this feature carefully. The CSS code you enter may cause unexpected visual changes to your Checkout page.',
			'platnosci-online-blue-media' ) ?></p>
			<?php
			$editor->display_editor();
			?>
		</fieldset>
	</td>
</tr>
