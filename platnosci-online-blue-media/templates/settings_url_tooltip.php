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

$autopay_placement = empty( $placement ) ? 'top' : $placement;

?>

<?php if ( ! empty( $tip_modal_id ) ): ?>
	<span class="autopay-url-tip placement-<?php echo esc_attr( $autopay_placement ); ?>">
	<a class="bm_ga_help_modal" href="#"
	   data-modal="<?php echo esc_attr( $tip_modal_id ); ?>"><?php echo esc_html( $label ) ?></a>
</span>
<?php else: ?>
	<span class="autopay-url-tip placement-<?php echo esc_attr( $autopay_placement ); ?>">
	<a target="_blank" href="<?php echo esc_url( $url ) ?>"><?php echo esc_html( $label ) ?></a>
</span>
<?php endif; ?>
