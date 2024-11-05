<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $field_key
 * @var string $tip_url
 * @var string $tip_url_label
 * @var string $tip_modal_id
 * @var string $tip_placement
 * @var WC_Settings_API $wc_settings_api
 * @var array $data
 * @var array $tr_classes
 * @var bool $visible
 *
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
	'options'           => [],
];

$data          = wp_parse_args( $data, $defaults );
$tip_placement = empty( $tip_placement ) ? 'top' : $tip_placement;
$value         = esc_attr( $wc_settings_api->get_option( $key ) );
$tr_classes    = empty( $tr_classes ) ? [] : $tr_classes;

?>
<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-select <?php esc_attr_e( implode( ' ', $tr_classes ) ) ?>"
	<?php if ( $visible === false ): ?>style="display: none;"<?php endif; ?>>
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?><?php echo $wc_settings_api->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
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
			<select class="select <?php echo esc_attr( $data['class'] ); ?>"
					name="<?php echo esc_attr( $field_key ); ?>"
					id="<?php echo esc_attr( $field_key ); ?>"
					style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $wc_settings_api->get_custom_attribute_html( $data ); // WPCS: XSS ok. ?>>
				<?php foreach ( (array) $data['options'] as $option_key => $option_value ) : ?>
					<?php if ( is_array( $option_value ) ) : ?>
						<optgroup
							label="<?php echo esc_attr( $option_key ); ?>">
							<?php foreach ( $option_value as $option_key_inner => $option_value_inner ) : ?>
								<option
									value="<?php echo esc_attr( $option_key_inner ); ?>" <?php selected( (string) $option_key_inner,
									esc_attr( $value ) ); ?>><?php echo esc_html( $option_value_inner ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php else : ?>
						<option
							value="<?php echo esc_attr( $option_key ); ?>" <?php selected( (string) $option_key,
							esc_attr( $value ) ); ?>><?php echo esc_html( $option_value ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
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
			<?php echo $wc_settings_api->get_description_html( $data ); // WPCS: XSS ok. ?>
		</fieldset>
	</td>
</tr>
