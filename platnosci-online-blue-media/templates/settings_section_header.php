<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $title
 * @var string $subtitle
 *
 */

?>

<?php if ( ! empty( $title ) ): ?>
	<div class="autopay-section-header">
		<h2><?php echo esc_html( $title ) ?></h2>

		<?php if ( ! empty( $subtitle ) ): ?>
			<p><?php echo esc_html( $subtitle ) ?></p>
		<?php endif ?>
	</div>
<?php endif ?>
