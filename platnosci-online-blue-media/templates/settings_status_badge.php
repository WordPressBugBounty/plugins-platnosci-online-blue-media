<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $status
 * @var string $type
 * @var string $class
 *
 */

?>

<span <?php post_class( [ 'autopay-badge', 'autopay-badge-' . $type, $class ],
	null ); ?>><?php esc_html_e( $status ); ?>
</span>
