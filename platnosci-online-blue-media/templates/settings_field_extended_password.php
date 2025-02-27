<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var string $tip_modal_id
 * @var WC_Settings_API $wc_settings_api
 * @var array $data
 */

$defaults = [
	'title'             => '',
	'disabled'          => false,
	'class'             => '',
	'css'               => '',
	'placeholder'       => '',
	'type'              => 'password',
	'desc_tip'          => false,
	'description'       => '',
	'custom_attributes' => [],
];

$data       = wp_parse_args( $data, $defaults );
$tr_classes = empty( $tr_classes ) ? [] : $tr_classes;
?>

<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-password <?php esc_attr_e( implode( ' ',
		$tr_classes ) ) ?>">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
		<?php if ( ! empty( $tip_url ) || ! empty( $tip_modal_id ) ): ?>
			<?php
			blue_media()->locate_template( 'settings_url_tooltip.php',
				[
					'url'          => $tip_url,
					'label'        => $tip_url_label,
					'tip_modal_id' => ! empty( $tip_modal_id ) ? $tip_modal_id : null,
				] ); ?>
		<?php endif; ?>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $data['title'] ); ?></span>
			</legend>
			<input
				class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
				type="password"
				name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				style="<?php echo esc_attr( $data['css'] ); ?>"
				data-origin_value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'],
				true ); ?> <?php echo $wc_settings_api->get_custom_attribute_html( $data ); // WPCS: XSS ok. ?> />
			<?php echo $wc_settings_api->get_description_html( $data ); // WPCS: XSS ok. ?>
		</fieldset>
	</td>
</tr>
