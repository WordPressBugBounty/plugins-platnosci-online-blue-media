<?php

defined( 'ABSPATH' ) || exit;
/**
 * @var string $field_key
 * @var string $nonce
 */

?>

<input type="hidden" name="autopay_audit_nonce"
       id="autopay_audit_nonce"
       value="<?php echo esc_attr($nonce); ?>">

<tr valign="top"
    class="<?php echo esc_attr($field_key); ?>-tr autopay-comp-text">

	<td><h3>
			<?php esc_html_e( 'Connection and transaction validity audit',
				'platnosci-online-blue-media' ); ?>
		</h3>
		<span id="autopay_audit_btn_start"
			  class="button">{Start}</span>
	</td>

</tr>

<tr class='status'
    id='autopay_audit_status'
    style='display: none;'>
    <td>
        <span class="in-progress">
            <span class="icon in-progress" id="autopay_audit_status_icon"></span>
            <span id="autopay_audit_status_title"></span>
            <span class="stage" id="autopay_audit_stage_name"></span>
            <span id="autopay_audit_please_wait"></span>
        </span>
    </td>
    <td>
		<span id="summary-error" class="summary-critical">
			<?php esc_html_e( 'Critical problems total: ',
				'platnosci-online-blue-media' ); ?><span class="counter"
											id="autopay_audit_counter_critical"></span>
		</span>
		<span id="summary-warning" class="summary-warning">
			<?php esc_html_e( 'Warnings total: ',
				'platnosci-online-blue-media' ); ?><span class="counter" id="autopay_audit_counter_warning"></span>
		</span>
    </td>
</tr>


<tr class="logs-entries" id="autopay_audit_log">
</tr>

<tr class="summary" id="autopay_audit_summary">
    <td>
        <ul>
            <li id='autopay_audit_s_s'
                class='summary-success'
                style='display: none;'>
				<span
					class="summary-success--title"><span
						id="autopay_audit_s_s_h"> <?php esc_html_e( 'Test has been completed. Click "Download full log" to download logs to disk.',
							'platnosci-online-blue-media' ); ?></span></span>
			</li>
            <li id='autopay_audit_s_e'
                class='summary-error'
                style='display: none;'>
				<span
					class="summary-error--title"> <span
						id="autopay_audit_s_e_h"><?php esc_html_e( 'An error occurred during finalization: ',
							'platnosci-online-blue-media' ); ?></span><span
						id="autopay_audit_s_e_m"
						class="summary-error--message"></span></span>
			</li>
            <li id='autopay_audit_s_w'
                class='summary-warning'
                style='display: none;'>
				<span
					class="summary-warning--title"> <span
						id="autopay_audit_s_w_h"><?php esc_html_e( 'An warning occurred during finalization: ',
							'platnosci-online-blue-media' ); ?></span><span
						id="autopay_audit_s_w_m"
						class="summary-warning--message"></span></span>
			</li>
	</td>
</tr>

<tr class="buttons">
    <td>
        <ul>
            <li>
                <span id='autopay_audit_btn_download'
                      class='button button--download'
                      style='display: none;'>
                    <?php esc_html_e( 'Download full log', 'platnosci-online-blue-media' ); ?>
                </span>
            </li>
            <li>
                <span id='autopay_audit_btn_copy'
                      class='button button--copy'
                      style='display: none;'>
                    <?php esc_html_e( 'Copy log to clipboard', 'platnosci-online-blue-media' ); ?>
                </span>
            </li>
        </ul>
    </td>
</tr>
