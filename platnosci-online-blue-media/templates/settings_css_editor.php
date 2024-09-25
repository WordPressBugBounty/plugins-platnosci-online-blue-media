<?php

use Ilabs\BM_Woocommerce\Domain\Service\Custom_Styles\Css_Editor;

defined( 'ABSPATH' ) || exit;

/**
 * @var Css_Editor $editor
 */
?>

<div class="bm-settings-css-editor">
	<h3><?php _e( 'CSS Editor', 'bm-woocommerce' ) ?></h3>

	<div>
		<?php
		$editor->display_editor();
		?>

		<p><?php _e( 'Use this feature carefully. The CSS code you enter may cause unexpected visual changes to your Checkout page.',
				'bm-woocommerce' ) ?></p>

	</div>

	<p class="submit">
		<input type="submit" value="<?php _e( 'Save changes',
			'bm-woocommerce' ) ?>" class="button-primary">
	</p>
</div>
