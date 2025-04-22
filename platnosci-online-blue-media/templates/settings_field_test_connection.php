<?php


/**
 * @var string $field_key
 * @var string $nonce
 */

?>


<input type="hidden" name="autopay_audit_nonce"
	   id="autopay_audit_nonce"
	   value="<?php echo esc_attr( $nonce ); ?>">


<tr valign="top"
	class="<?php echo esc_attr( $field_key ); ?>-tr autopay-comp-text">

	<td><h3>
			<?php echo __( 'Connection and transaction validity audit',
				'bm-woocommerce' ); ?>
		</h3>
		<span id="autopay_audit_btn_start"
			  class="button">{Start}</span>
	</td>

</tr>

<tr class="status" id="autopay_audit_status">
	<td>
		<span class="in-progress"><span
				class="icon in-progress" id="autopay_audit_status_icon"></span> <span id="autopay_audit_status_title"></span> <span
				class="stage"
				id="autopay_audit_stage_name"></span> <span
				id="autopay_audit_please_wait"></span></span>
	</td>
	<td>
		<span id="summary-error" class="summary-critical">
			<?php echo __( 'Critical problems total: ',
				'bm-woocommerce' ); ?><span class="counter"
											id="autopay_audit_counter_critical"></span>
		</span>
		<span id="summary-warning" class="summary-warning">
			<?php echo __( 'Warnings total: ',
				'bm-woocommerce' ); ?><span class="counter" id="autopay_audit_counter_warning"></span>
		</span>
	</td>
</tr>


<tr class="logs-entries" id="autopay_audit_log">
</tr>

<tr class="summary" id="autopay_audit_summary">
	<td>
		<ul>
			<li id="autopay_audit_s_s" class="summary-success">
				<span
					class="summary-success--title"><span
						id="autopay_audit_s_s_h"> <?php echo __( 'Test has been completed. Click "Download full log" to download logs to disk.',
							'bm-woocommerce' ); ?></span></span>
			</li>
			<li id="autopay_audit_s_e" class="summary-error">
				<span
					class="summary-error--title"> <span
						id="autopay_audit_s_e_h"><?php echo __( 'An error occurred during finalization: ',
							'bm-woocommerce' ); ?></span><span
						id="autopay_audit_s_e_m"
						class="summary-error--message"></span></span>
			</li>
			<li id="autopay_audit_s_w" class="summary-warning">
				<span
					class="summary-warning--title"> <span
						id="autopay_audit_s_w_h"><?php echo __( 'An warning occurred during finalization: ',
							'bm-woocommerce' ); ?></span><span
						id="autopay_audit_s_w_m"
						class="summary-warning--message"></span></span>
			</li>
	</td>
</tr>

<tr class="buttons">
	<td>
		<ul>
			<li>
				<span id="autopay_audit_btn_download"
					  class="button button--download"><?php echo __( 'Download full log',
						'bm-woocommerce' ); ?></span>
			</li>
			<li>
				<span id="autopay_audit_btn_copy"
					  class="button button--copy"><?php echo __( 'Copy log to clipboard',
						'bm-woocommerce' ); ?></span>
			</li>
		</ul>
	</td>
</tr>
