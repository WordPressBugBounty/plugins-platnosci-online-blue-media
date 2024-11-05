<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $title
 * @var string $subtitle
 *
 */

$title    = esc_attr( $title );
$subtitle = esc_attr( $subtitle );

?>

<?php if ( ! empty( $title ) ): ?>
	<div class="autopay-section-header">
		<h2><?php echo $title ?></h2>

		<?php if ( ! empty( $subtitle ) ): ?>
			<p><?php echo $subtitle ?></p>
		<?php endif ?>
	</div>
<?php endif ?>
