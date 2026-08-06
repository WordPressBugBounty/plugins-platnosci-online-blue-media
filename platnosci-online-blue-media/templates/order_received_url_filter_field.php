<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $autopay_from_val
 * @var string $autopay_to_val
 */
?>

<?php
$autopay_from_val = empty( $autopay_from_val ) ? '' : $autopay_from_val;
$autopay_to_val   = empty( $autopay_to_val ) ? '' : $autopay_to_val;

?>
<tr valign="top" class="woocommerce_bluemedia_order_received_url_filter-tr autopay-comp-order_received_url_filter autopay-comp-text">
	<th scope="row" class="titledesc"><?php esc_html_e( 'Replace the default order confirmation endpoint address.', 'platnosci-online-blue-media' ); ?></th>
	<td class="forminp">
		<fieldset>
			<label
				for="woocommerce_bluemedia_order_received_url_filter_from"><?php esc_html_e( 'Replace the phrase:', 'platnosci-online-blue-media' ); ?></label>
			<input class="input-text regular-input" type="text"
				   name="woocommerce_bluemedia_order_received_url_filter_from"
				   id="woocommerce_bluemedia_order_received_url_filter_from" style="" data-origin_value=""
				   value="<?php echo esc_attr( $autopay_from_val ); ?>"
				   placeholder="" spellcheck="false" data-ms-editor="true">
		</fieldset>
	</td>
	<td class="forminp forminp--dashicons">
		<span class="dashicons dashicons-arrow-right-alt"></span>
	</td>
	<td class="forminp">
		<fieldset>
			<label
				for="woocommerce_bluemedia_order_received_url_filter_to"><?php esc_html_e( 'To:', 'platnosci-online-blue-media' ); ?></label>
			<input class="input-text regular-input" type="text"
				   name="woocommerce_bluemedia_order_received_url_filter_to"
				   id="woocommerce_bluemedia_order_received_url_filter_to" style="" data-origin_value=""
				   value="<?php echo esc_attr( $autopay_to_val ); ?>"
				   placeholder="" spellcheck="false" data-ms-editor="true">
		</fieldset>
	</td>
</tr>
