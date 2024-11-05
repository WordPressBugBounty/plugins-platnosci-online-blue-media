<?php defined( 'ABSPATH' ) || exit; ?>

<tr valign="top" class="autopay-comp-css-importer">
	<th scope="row importer-title"
		class="titledesc"><?php _e( 'Import settings from 2.x/3.x version',
			'bm-woocommerce' ) ?></th>
	<td class="forminp importer-desc">
		<fieldset>
			<p class="description"><?php _e( 'Imports Service ID, configuration key and environment setting ( testing / production)',
					'bm-woocommerce' ) ?></p>
		</fieldset>
	</td>
	<td class="forminp importer-btn">
		<fieldset>
			<input type="submit" id="autopay_start_import"
				class="button-primary"
				value="<?php _e( 'Start import',
					'bm-woocommerce' ) ?>">
			<input type="hidden" name="autopay_import_legacy_settings"
				id="autopay_import_legacy_settings"
				value="0">
		</fieldset>
	</td>
</tr>

<script>
	jQuery(document).ready(function () {
		jQuery('#autopay_start_import').click(function (e) {
			e.preventDefault();
			var form = jQuery(this).closest('form');
			form.submit(function () {
				return false;
			});
			jQuery('#autopay_import_legacy_settings').val("1")
			form.unbind('submit').submit();
		});
	});
</script>
