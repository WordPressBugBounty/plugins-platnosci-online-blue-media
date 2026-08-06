<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var string $tip_modal_id
 * @var WC_Settings_API $wc_settings_api
 * @var array $autopay_data
 */

$autopay_defaults = [
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

$autopay_data       = wp_parse_args( $autopay_data, $autopay_defaults );
$autopay_tr_classes = empty( $autopay_tr_classes ) ? [] : $autopay_tr_classes;
?>

<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-password <?php echo esc_attr( implode( ' ', $autopay_tr_classes ) ); ?>">
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $autopay_data['title'] ); ?></label>
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
				<span><?php echo wp_kses_post( $autopay_data['title'] ); ?></span>
			</legend>
			<input
				class="input-text regular-input <?php echo esc_attr( $autopay_data['class'] ); ?>"
				type="password"
				name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				style="<?php echo esc_attr( $autopay_data['css'] ); ?>"
				data-origin_value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				value="<?php echo esc_attr( $wc_settings_api->get_option( $key ) ); ?>"
				placeholder="<?php echo esc_attr( $autopay_data['placeholder'] ); ?>" <?php disabled( $autopay_data['disabled'],
				true ); ?> <?php echo $wc_settings_api->get_custom_attribute_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?> />
			<?php echo $wc_settings_api->get_description_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?>
		</fieldset>
	</td>
</tr>
