<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $helptip
 *
 */

$helptip = esc_attr( $helptip );

?>

<span class="woocommerce-help-tip autopay-help-tip"
	  data-tip="<?php echo $helptip ?>"
	  aria-label="<?php echo $helptip ?>">
</span>
