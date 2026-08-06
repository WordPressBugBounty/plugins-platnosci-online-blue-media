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
 * @var array $autopay_data
 * @var array $autopay_tr_classes
 * @var bool $visible
 *
 */

$autopay_defaults = [
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

$autopay_data          = wp_parse_args( $autopay_data, $autopay_defaults );
$autopay_tip_placement = empty( $tip_placement ) ? 'top' : $tip_placement;
$autopay_value         = esc_attr( $wc_settings_api->get_option( $key ) );
$autopay_tr_classes    = empty( $autopay_tr_classes ) ? [] : $autopay_tr_classes;

?>
<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-select <?php echo esc_attr( implode( ' ', $autopay_tr_classes ) ); ?>"
	<?php if ( $visible === false ): ?>style="display: none;"<?php endif; ?>>
	<th scope="row" class="titledesc">
		<label
			for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $autopay_data['title'] ); ?><?php echo $wc_settings_api->get_tooltip_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?></label>
		<?php if ( 'top' === $autopay_tip_placement && ( ! empty( $tip_url ) || ! empty( $tip_modal_id ) ) ): ?>
			<?php
			blue_media()->locate_template( 'settings_url_tooltip.php',
				[
					'url'          => $tip_url,
					'label'        => $tip_url_label,
					'tip_modal_id' => ! empty( $tip_modal_id ) ? $tip_modal_id : null,
					'placement'    => $autopay_tip_placement,
				] ); ?>
		<?php endif; ?>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $autopay_data['title'] ); ?></span>
			</legend>
			<select class="select <?php echo esc_attr( $autopay_data['class'] ); ?>"
					name="<?php echo esc_attr( $field_key ); ?>"
					id="<?php echo esc_attr( $field_key ); ?>"
					style="<?php echo esc_attr( $autopay_data['css'] ); ?>" <?php echo $wc_settings_api->get_custom_attribute_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?>>
				<?php foreach ( (array) $autopay_data['options'] as $autopay_option_key => $autopay_option_value ) : ?>
					<?php if ( is_array( $autopay_option_value ) ) : ?>
						<optgroup
							label="<?php echo esc_attr( $autopay_option_key ); ?>">
							<?php foreach ( $autopay_option_value as $autopay_option_key_inner => $autopay_option_value_inner ) : ?>
								<option
									value="<?php echo esc_attr( $autopay_option_key_inner ); ?>" <?php selected( (string) $autopay_option_key_inner,
									esc_attr( $autopay_value ) ); ?>><?php echo esc_html( $autopay_option_value_inner ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php else : ?>
						<option
							value="<?php echo esc_attr( $autopay_option_key ); ?>" <?php selected( (string) $autopay_option_key,
							esc_attr( $autopay_value ) ); ?>><?php echo esc_html( $autopay_option_value ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<?php if ( 'bottom' === $autopay_tip_placement && ( ! empty( $tip_url ) || ! empty( $tip_modal_id ) ) ): ?>
				<?php
				blue_media()->locate_template( 'settings_url_tooltip.php',
					[
						'url'          => $tip_url,
						'label'        => $tip_url_label,
						'tip_modal_id' => ! empty( $tip_modal_id ) ? $tip_modal_id : null,
						'placement'    => $autopay_tip_placement,
					] ); ?>
			<?php endif; ?>
			<?php echo $wc_settings_api->get_description_html( $autopay_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- WC-generated HTML ?>
		</fieldset>
	</td>
</tr>
