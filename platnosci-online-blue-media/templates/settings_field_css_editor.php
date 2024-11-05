<?php

use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;

defined( 'ABSPATH' ) || exit;

/**
 * @var Css_Editor $editor
 */
?>

<tr valign="top" class="autopay-comp-css-editor">
	<th scope="row"
		class="titledesc"><?php _e( 'Use own CSS styles',
			'bm-woocommerce' ) ?></th>
	<td class="forminp">
		<fieldset>
		<p class="warning"><?php _e( 'Use this feature carefully. The CSS code you enter may cause unexpected visual changes to your Checkout page.',
			'bm-woocommerce' ) ?></p>
			<?php
			$editor->display_editor();
			?>
		</fieldset>
	</td>
</tr>
