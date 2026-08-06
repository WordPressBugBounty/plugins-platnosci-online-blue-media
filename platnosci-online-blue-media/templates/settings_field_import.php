<?php defined( 'ABSPATH' ) || exit; ?>

<tr valign="top" class="autopay-comp-css-importer">
	<th scope="row importer-title"
		class="titledesc"><?php esc_html_e( 'Import settings from 2.x/3.x version',
			'platnosci-online-blue-media' ) ?></th>
	<td class="forminp importer-desc">
		<fieldset>
			<p class="description"><?php esc_html_e( 'Imports Service ID, configuration key and environment setting ( testing / production)',
					'platnosci-online-blue-media' ) ?></p>
		</fieldset>
	</td>
	<td class="forminp importer-btn">
		<fieldset>
			<?php wp_nonce_field( 'autopay_import_nonce', 'autopay_import_nonce_field' ); ?>
			<input type="submit" id="autopay_start_import"
				class="button-primary"
				value="<?php esc_attr_e( 'Start import',
					'platnosci-online-blue-media' ) ?>">
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
