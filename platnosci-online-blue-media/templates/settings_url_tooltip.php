<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $url
 * @var string $label
 * @var string $tip_modal_id
 * @var string $placement
 *
 *
 *
 */

$placement = empty( $placement ) ? 'top' : $placement;

?>

<?php if ( ! empty( $tip_modal_id ) ): ?>
	<span class="autopay-url-tip placement-<?php esc_attr_e( $placement ); ?>">
	<a class="bm_ga_help_modal" href="#"
	   data-modal='<?php esc_attr_e( $tip_modal_id ); ?>'><?php echo $label ?></a>
</span>
<?php else: ?>
	<span class="autopay-url-tip placement-<?php esc_attr_e( $placement ); ?>">
	<a target="_blank" href="<?php echo $url ?>"><?php echo $label ?></a>
</span>
<?php endif; ?>
