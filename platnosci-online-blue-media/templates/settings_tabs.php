<?php defined( 'ABSPATH' ) || exit; ?>
<?php $tab = isset( $_GET['bmtab'] ) ? sanitize_text_field( $_GET['bmtab'] ) : ''; ?>


<div class="bm-settings-tabs" style="display: flex">
	<ul class="subsubsub">
		<li>
			<?php if ( empty( $tab ) ): ?>
				<span class="autopay-tab current"><?php _e( 'Settings',
						'bm-woocommerce' ) ?></span> |

			<?php else: ?>
				<span class="autopay-tab"><a
						href="<?php esc_attr_e( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluemedia' ) ) ?>">
					<?php _e( 'Settings', 'bm-woocommerce' ) ?></a></span> |

			<?php endif; ?>
		</li>


		<li>
			<?php if ( 'channels' === $tab ): ?>
				<span
					class="autopay-tab current"><?php _e( 'Payment gateway list',
						'bm-woocommerce' ) ?></span> |
			<?php else: ?>
				<span class="autopay-tab"><a
						href="<?php esc_attr_e( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=channels' ) ) ?>">
					<?php _e( 'Payment gateway list', 'bm-woocommerce' ) ?></a></span> |
			<?php endif; ?>
		</li>

		<li>
			<?php if ( 'css_editor' === $tab ): ?>
				<span class="autopay-tab current"><?php _e( 'CSS Editor',
						'bm-woocommerce' ) ?></span> |
			<?php else: ?>
				<span class="autopay-tab"><a
						href="<?php esc_attr_e( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=css_editor' ) ) ?>">
					<?php _e( 'CSS Editor', 'bm-woocommerce' ) ?></a></span> |
			<?php endif; ?>
		</li>


<li>
			<?php if ( 'vas' === $tab ): ?>
				<span class="autopay-tab current"><?php _e( 'Services for you',
						'bm-woocommerce' ) ?></span>
			<?php else: ?>
				<span class="autopay-tab"><a
						href="<?php esc_attr_e( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=vas' ) ) ?>">
					<?php _e( 'Services for you', 'bm-woocommerce' ) ?></a></span>
			<?php endif; ?>
		</li>



	</ul>
</div>

<?php return?>

<li>
	<?php if ( 'status' === $tab ): ?>
		<span class="autopay-tab current"><?php _e( 'Status',
				'bm-woocommerce' ) ?></span>
	<?php else: ?>
		<span class="autopay-tab"><a
				href="<?php esc_attr_e( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluemedia&bmtab=status' ) ) ?>">
					<?php _e( 'Status', 'bm-woocommerce' ) ?></a></span>
	<?php endif; ?>
</li>
