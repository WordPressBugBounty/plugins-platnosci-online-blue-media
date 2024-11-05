<?php defined( 'ABSPATH' ) || exit; ?>
<?php

use Ilabs\BM_Woocommerce\Controller\Model\Ajax_Response_Value_Object;
use Ilabs\BM_Woocommerce\Controller\Model\Payment_Status_Response_Value_Object;
use Ilabs\BM_Woocommerce\Controller\Payment_Status_Controller;
use Ilabs\BM_Woocommerce\Controller\Wp_Admin\Connection_Testing_Controller;
use Ilabs\BM_Woocommerce\Controller\Wp_Admin\Transaction_Testing_Controller;

$generic_error_message = __( 'Payment failed.',
	'bm-woocommerce' );
?>

<tr valign="top">
	<th scope="row"
		class="titledesc"><?php _e( 'Check the connection',
			'bm-woocommerce' ) ?></th>
	<td class="forminp">
		<fieldset>

			<div class="bm-testing-status-wrapper">
				<p><span class="bm-testing-status"
						 id="bm-testing-status"></span>
				</p>
			</div>

			<div>
				<button name="autopay_connection_test_btn"
						id="autopay_connection_test_btn"><?php _e( 'Check the connection',
						'bm-woocommerce' ); ?></button>
			</div>


			<div>
				<button name="autopay_transaction_test_btn"
						id="autopay_transaction_test_btn"><?php _e( 'Check transaction',
						'bm-woocommerce' ); ?></button>
			</div>

		</fieldset>
	</td>
</tr>


<script>
	var bm_blik0_payment_in_progress = false;
	var placeOrderBlikStarted = false;
	var bm_test_order_id = 0;
	var bmTestOrderItnReceivedAttempts = 0;


	function bm_sleep(ms) {
		return new Promise(resolve => setTimeout(resolve, ms));
	}

	jQuery(document).ready(function ($) {
		const originalTriggerHandler = $.fn.triggerHandler;


		jQuery("#autopay_transaction_test_btn").click(function (e) {
			e.preventDefault();

			bmInitializeTransactionTest()
		});

		jQuery("#autopay_connection_test_btn").click(function (e) {
			e.preventDefault();

			bmConnectionTest()


		});


		jQuery("autopay_transaction_test_btn").click(function (e) {
			e.preventDefault();


		});


		function bmConnectionTest() {
			jQuery('.bluemedia-loader').show()
			jQuery('.bluemedia-status-box').show()

			var data = {
				action: "bm_connection_test_action",
				nonce: "<?php echo wp_create_nonce( Connection_Testing_Controller::NONCE_ACTION ) ?>"
			};


			console.log('ajax start');

			jQuery.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) )?>', data, function (response) {

				if (response !== 0) {
					response = JSON.parse(response);
					console.log(response.status);

					if (response.hasOwnProperty('status')
						&& (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>'
							|| response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>'
						)
					) {
						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>') {

							if (response.hasOwnProperty('message')

							) {
								blueMediaUpdateStatus(response.message, response.status)
								return false
							}
							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')
							return false
						}

						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>') {
							if (response.hasOwnProperty('message')) {
								blueMediaUpdateStatus(response.message, response.status)
								return false
							}

							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

							return false

						}
					}
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

					return false
				} else {
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')
				}


			}).fail(function (jqXHR, textStatus, errorThrown) {
				jQuery('.bluemedia-loader').hide()
				blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + jqXHR.status, 'error');

				return false
			});


		}

		function bmInitializeTransactionTest() {
			jQuery('.bluemedia-loader').show()
			jQuery('.bluemedia-status-box').show()

			var data = {
				action: "bm_transaction_test_action",
				nonce: "<?php echo wp_create_nonce( Connection_Testing_Controller::NONCE_ACTION ) ?>"
			};


			console.log('ajax start');

			jQuery.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) )?>', data, function (response) {

				if (response !== 0) {
					response = JSON.parse(response);
					console.log(response.status);

					if (response.hasOwnProperty('status')
						&& (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>'
							|| response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>'
						)
					) {
						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>') {

							if (response.hasOwnProperty('message')

							) {
								blueMediaUpdateStatus("Utworzono testowe zamówienie. Testowanie ITN...", response.status)
								bm_test_order_id = parseInt(response.message)
								bmTestOrderItnReceived(bm_test_order_id)
								return false
							}
							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>', 'error')
							return false
						}

						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>') {
							if (response.hasOwnProperty('message')) {
								blueMediaUpdateStatus(response.message, response.status)
								return false
							}

							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

							return false

						}
					}
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

					return false
				} else {
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')
				}


			}).fail(function (jqXHR, textStatus, errorThrown) {
				jQuery('.bluemedia-loader').hide()
				blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + jqXHR.status, 'error');

				return false
			});


		}


		function bmTestOrderItnReceived(orderId) {
			jQuery('.bluemedia-loader').show()
			jQuery('.bluemedia-status-box').show()

			var data = {
				action: "bm_verify_itn_action",
				order_id: orderId,
				nonce: "<?php echo wp_create_nonce( Connection_Testing_Controller::NONCE_ACTION ) ?>"
			};


			console.log('ajax start');

			jQuery.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) )?>', data, function (response) {

				if (response !== 0) {
					response = JSON.parse(response);
					console.log(response.status);

					if (response.hasOwnProperty('status')
						&& (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>'
							|| response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>'
						)
					) {
						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_SUCCESS ?>') {

							if (response.hasOwnProperty('message')

							) {
								if ('1' === response.message) {
									blueMediaUpdateStatus("Odebrano ITN!", response.status)
								} else {
									if (10 === bmTestOrderItnReceivedAttempts) {
										blueMediaUpdateStatus("Nie udało się odebrać komunikatu ITN dla testowego zamówienia:", "<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>")
										bmTestOrderItnReceivedAttempts = 0;
										return false
									}
									setTimeout(function () {
										bmTestOrderItnReceivedAttempts++
										bmTestOrderItnReceived(bm_test_order_id)
									}, 3000)
								}

								return false
							}
							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>', 'error')
							return false
						}

						if (response.status === '<?php echo Ajax_Response_Value_Object::STATUS_ERROR ?>') {
							if (response.hasOwnProperty('message')) {
								blueMediaUpdateStatus(response.message, response.status)
								return false
							}

							blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

							return false

						}
					}
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')

					return false
				} else {
					blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + JSON.stringify(response), 'error')
				}


			}).fail(function (jqXHR, textStatus, errorThrown) {
				jQuery('.bluemedia-loader').hide()
				blueMediaUpdateStatus('<?php esc_html_e( $generic_error_message ); ?>' + jqXHR.status, 'error');

				return false
			});


		}


		function blueMediaUpdateStatus(message, status) {
			$('.bm-testing-status-wrapper').show();

			//$targetWrapper = $('.bluemedia-success-wrapper');
			$targetSpan = $('#bm-testing-status');

			if (status === '<?php echo Payment_Status_Response_Value_Object::STATUS_SUCCESS ?>') {
				$targetSpan.addClass('bm-testing-status--success').removeClass('bm-testing-status--process bm-testing-status--error');
			} else if (status === '<?php echo Payment_Status_Response_Value_Object::STATUS_CHECK_DEVICE ?>') {
				$targetSpan.addClass('bm-testing-status--process').removeClass('bm-testing-status--success bm-testing-status--error');
			} else if (status === '<?php echo Payment_Status_Response_Value_Object::STATUS_WAIT ?>') {
				$targetSpan.addClass('bm-testing-status--process').removeClass('bm-testing-status--success bm-testing-status--error');
			} else if (status === '<?php echo Payment_Status_Response_Value_Object::STATUS_ERROR ?>') {
				$targetSpan.addClass('bm-testing-status--error').removeClass('bm-testing-status--success bm-testing-status--process');
			}

			$targetSpan.text(message);
		}

	})
	;

</script>
