<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * @var string $key
 * @var string $field_key
 * @var WC_Settings_API $wc_settings_api
 * @var array $data
 * @var array $template_args
 */

$defaults = [
	'title'             => '',
	'disabled'          => false,
	'class'             => '',
	'css'               => '',
	'placeholder'       => '',
	'type'              => 'text',
	'description'       => '',
	'custom_attributes' => [],
];

$data       = wp_parse_args( $data, $defaults );
$tr_classes = empty( $tr_classes ) ? [] : $tr_classes;
$max_length = isset( $template_args['max_length'] ) ? (int) $template_args['max_length'] : 80;
$value      = (string) $wc_settings_api->get_option( $key );
?>

<tr valign="top" class="<?php echo esc_attr( $field_key ); ?>-tr autopay-title-field <?php esc_attr_e( implode( ' ', $tr_classes ) ) ?>">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
		<span class="autopay-counter" data-for="<?php echo esc_attr( $field_key ); ?>">
				<?php echo esc_html__( 'max. 80 characters', 'bm-woocommerce' ); ?>
		</span>
	</th>
	<td class="forminp">
		<fieldset>
			<input
				class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
				type="text"
				name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				style="<?php echo esc_attr( $data['css'] ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				maxlength="<?php echo esc_attr( $max_length ); ?>"
				aria-describedby="<?php echo esc_attr( $field_key ); ?>-help <?php echo esc_attr( $field_key ); ?>-error"
		/>
		<div class="autopay-field-meta">
			<span class="autopay-error" id="<?php echo esc_attr( $field_key ); ?>-error" role="alert" style="display:none;">
				<?php esc_html_e( 'Maximum length exceeded', 'bm-woocommerce' ); ?>
			</span>
		</div>
		<script>
		jQuery(function($){
			var $input = $('#<?php echo esc_js( $field_key ); ?>');
			var max = <?php echo (int) $max_length; ?>;
			var $error = $('#<?php echo esc_js( $field_key ); ?>-error');
			$input.on('input', function(){
				var len = $(this).val().length;
				if(len > max){
					$error.show();
					$input.addClass('input-error').attr('aria-invalid','true');
				}else{
					$error.hide();
					$input.removeClass('input-error').removeAttr('aria-invalid');
				}
			});
			// initial check in case prefilled value exceeds
			$input.trigger('input');
		});
		</script>
		<?php if ( ! empty( $data['description'] ) ) : ?>
			<p class="description" id="<?php echo esc_attr( $field_key ); ?>-help"><?php echo wp_kses_post( $data['description'] ); ?></p>
		<?php endif; ?>
		</fieldset>
	</td>
</tr>


