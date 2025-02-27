<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var string $tip_modal_id
 * @var string $tip_placement
 *
 * @var string $input_field_type_arg
 * @var WC_Settings_API $wc_settings_api
 * @var array $data
 */

$defaults = [
	'title'             => '',
	'disabled'          => false,
	'class'             => '',
	'css'               => '',
	'placeholder'       => '',
	'type'              => 'text',
	'desc_tip'          => false,
	'description'       => '',
	'custom_attributes' => [],
];

$input_field_type_arg = empty( $input_field_type_arg ) ? 'text' : $input_field_type_arg;
$tip_placement        = empty( $tip_placement ) ? 'top' : $tip_placement;
$data                 = wp_parse_args( $data, $defaults );
$tr_classes           = empty( $tr_classes ) ? [] : $tr_classes;

?>

<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text <?php esc_attr_e( implode( ' ',
		$tr_classes ) ) ?>">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
		<?php if ( 'top' === $tip_placement && ( ! empty( $tip_url ) || ! empty( $tip_modal_id ) ) ): ?>
			<?php
			blue_media()->locate_template( 'settings_url_tooltip.php',
				[
					'url'          => $tip_url,
					'label'        => $tip_url_label,
					'tip_modal_id' => ! empty( $tip_modal_id ) ? $tip_modal_id : null,
					'placement'    => $tip_placement,
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
				type="<?php echo esc_attr( $input_field_type_arg ); ?>"
				name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				style="<?php echo esc_attr( $data['css'] ); ?>"
				data-origin_value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'],
				true ); ?> <?php echo $wc_settings_api->get_custom_attribute_html( $data ); // WPCS: XSS ok. ?>
			/>
			<?php echo $wc_settings_api->get_description_html( $data ); // WPCS: XSS ok. ?>
			<?php if ( 'bottom' === $tip_placement && ( ! empty( $tip_url ) || ! empty( $tip_modal_id ) ) ): ?>
				<?php
				blue_media()->locate_template( 'settings_url_tooltip.php',
					[
						'url'          => $tip_url,
						'label'        => $tip_url_label,
						'tip_modal_id' => ! empty( $tip_modal_id ) ? $tip_modal_id : null,
						'placement'    => $tip_placement,
					] ); ?>
			<?php endif; ?>
		</fieldset>
	</td>
</tr>
