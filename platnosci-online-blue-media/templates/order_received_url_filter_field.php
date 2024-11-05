<?php defined( 'ABSPATH' ) || exit; ?>

<?php
/**
 * @var string $key
 * @var string $from_val
 * @var string $to_val
 */
?>

<?php
$from_val = empty( $from_val ) ? '' : $from_val;
$to_val   = empty( $to_val ) ? '' : $to_val;

?>
<tr valign="top" class="woocommerce_bluemedia_order_received_url_filter-tr autopay-comp-order_received_url_filter autopay-comp-text">
	<th scope="row" class="titledesc"><?php _e( 'Replace the default order confirmation endpoint address.', 'bm-woocommerce' ); ?></th>
	<td class="forminp">
		<fieldset>
			<label
				for="woocommerce_bluemedia_order_received_url_filter_from"><?php _e( 'Replace the phrase:', 'bm-woocommerce' ); ?></label>
			<input class="input-text regular-input" type="text"
				   name="woocommerce_bluemedia_order_received_url_filter_from"
				   id="woocommerce_bluemedia_order_received_url_filter_from" style="" data-origin_value=""
				   value="<?php esc_attr_e( $from_val ); ?>"
				   placeholder="" spellcheck="false" data-ms-editor="true">
		</fieldset>
	</td>
	<td class="forminp forminp--dashicons">
		<span class="dashicons dashicons-arrow-right-alt"></span>
	</td>
	<td class="forminp">
		<fieldset>
			<label
				for="woocommerce_bluemedia_order_received_url_filter_to"><?php _e( 'To:', 'bm-woocommerce' ); ?></label>
			<input class="input-text regular-input" type="text"
				   name="woocommerce_bluemedia_order_received_url_filter_to"
				   id="woocommerce_bluemedia_order_received_url_filter_to" style="" data-origin_value=""
				   value="<?php esc_attr_e( $to_val ); ?>"
				   placeholder="" spellcheck="false" data-ms-editor="true">
		</fieldset>
	</td>
</tr>
