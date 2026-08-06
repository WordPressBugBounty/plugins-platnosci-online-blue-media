<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $src
 * @var string $content
 *
 */
?>


<?php if ( ! empty( $content ) ): ?>
	<div class="bm-settings-banner">
		<?php echo wp_kses_post( $content ) ?>
	</div>
<?php endif ?>
