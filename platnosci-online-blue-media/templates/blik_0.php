<?php

defined( 'ABSPATH' ) || exit;

use Ilabs\BM_Woocommerce\Controller\Model\Payment_Status_Response_Value_Object;
use Ilabs\BM_Woocommerce\Controller\Payment_Status_Controller;

$autopay_generic_error_message = __( 'Payment failed.',
	'platnosci-online-blue-media' );

$autopay_blik0_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
	$autopay_blik0_json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$autopay_blik0_generic_error_json = wp_json_encode( $autopay_generic_error_message, $autopay_blik0_json_flags );
if ( false === $autopay_blik0_generic_error_json ) {
	$autopay_blik0_generic_error_json = '"Payment failed."';
}

?>
<div class="bm-blik-overlay">
	<p><span class="bm-blik-overlay-status" id="bm-blik-overlay-status"></span>
	</p>
</div>
<div class="bm-blik-code-wrapper">
	<label
		for="bm-blik-code"><?php
		esc_html_e( 'Enter the BLIK code.',
			'platnosci-online-blue-media' ); ?></label>
	<input id="bluemedia_blik_code" type="text" name="bluemedia_blik_code"
		   inputmode="numeric" minlength="6" maxlength="6" autocomplete="off">

	<span><?php esc_html_e( 'The code has 6 digits. You\'ll find it in your banking app.',
			'platnosci-online-blue-media' ); ?></span>


	<div class="bluemedia-simple-status-box">
		<div class="bluemedia-success-wrapper">
			<span id="bluemedia-success-msg"></span>
		</div>
		<div class="bluemedia-info-wrapper">
			<span id="bluemedia-info-msg"></span>
		</div>
		<div class="bluemedia-error-wrapper">
			<span id="bluemedia-error-msg"></span>
		</div>
	</div>


</div>


<script>
	var bm_blik0_payment_in_progress = false;
	var placeOrderBlikStarted = false;

	var autopayBlik0TimePassed = false;
	var autopayBlik0TimerRunning = false;

	function autopayBlik0Countdown() {
		if (autopayBlik0TimePassed || autopayBlik0TimerRunning) {
			return;
		}
		autopayBlik0TimerRunning = true;
		setTimeout(function () {
			autopayBlik0TimePassed = true;
			autopayBlik0TimerRunning = false;
		}, 120000);
	}


	jQuery(document).ready(function ($) {
		var originalTriggerHandler = $.fn.triggerHandler;

		var $blik0Radio = $('#bm-gateway-id-509');
		var $bmBLikCode = $('#bluemedia_blik_code');

		$bmBLikCode.on('keydown', function (e) {
			if ($.inArray(e.keyCode, [8, 9, 13, 27, 46, 37, 38, 39, 40]) !== -1) {
				return;
			}
			if ((e.keyCode < 48 || e.keyCode > 57) && (e.keyCode < 96 || e.keyCode > 105)) {
				e.preventDefault();
			}
		});

		$bmBLikCode.on('input', function () {
			var value = $(this).val();
			bmBLikCodeValidate(value)
		});

		$blik0Radio.on('click', function () {
			BmDeactivateNewOrderButton()
		});

		function bmBLikCodeValidate(value) {
			if (/^\d{6}$/.test(value)) {
				$(this).removeClass('not-valid');
				BmActivateNewOrderButton()
			} else {
				$(this).addClass('not-valid');
				BmDeactivateNewOrderButton()
			}
		}


		$.fn.triggerHandler = function (event, data) {
			if (event === 'checkout_place_order_success') {
				if ($('#bm-gateway-id-509').is(':checked')) {
					if (false === bm_blik0_payment_in_progress) {
						bm_blik0_payment_in_progress = true
					}
				}

				bmCheckBlik0Status()

				return originalTriggerHandler.apply(this, arguments);
			}

			return originalTriggerHandler.apply(this, arguments);
		};


		function bmCheckBlik0Status() {
			jQuery('.bluemedia-loader').show()
			jQuery('.bluemedia-status-box').show()

			autopayBlik0Countdown();

			var data = {
				action: "bm_payment_get_status_action",
				nonce: "<?php echo esc_js( wp_create_nonce( Payment_Status_Controller::NONCE_ACTION ) ) ?>"
			};


			console.log('ajax start');

			jQuery.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) )?>', data, function (response) {

				if (response !== 0) {
					response = JSON.parse(response);
					console.log(response.status);

					if (response.hasOwnProperty('status')
						&& (response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_SUCCESS ) ?>'
							|| response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_ERROR ) ?>'
							|| response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_WAIT ) ?>'
							||
							response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_CHECK_DEVICE ) ?>'
						)
					) {
						if (response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_SUCCESS ) ?>') {

							if (response.hasOwnProperty('message')

							) {
								blueMediaUpdateStatus(response.message, response.status)

								setTimeout(function () {
									window.location.href = response.order_received_url;

								}, 3000)

								return false
							}
							blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, 'error')
							return false
						}

						if (response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_WAIT ) ?>'
							|| response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_CHECK_DEVICE ) ?>') {

							if (response.hasOwnProperty('message')

							) {

								if (autopayBlik0TimePassed) {
									var baseUrl = response.order_received_url;
									var sepBlik = baseUrl.indexOf('?') >= 0 ? '&' : '?';
									var blikTimeoutUrl = baseUrl + sepBlik + 'blik0_timeout=1';
									blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, 'error')
									setTimeout(function () {
										window.location.href = blikTimeoutUrl;
									}, 3000)

								} else {
									blueMediaUpdateStatus(response.message, response.status)

									setTimeout(function () {
										bmCheckBlik0Status()
									}, 3000)
								}


								return false
							}
							blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + JSON.stringify(response), 'error')
							return false
						}


						if (response.status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_ERROR ) ?>') {
							if (response.hasOwnProperty('message')) {
								blueMediaUpdateStatus(response.message, response.status)
								setTimeout(function () {
									//bmCheckBlik0Status()
									window.location.href = response.order_received_url;
								}, 3000)
								return false
							}

							blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + JSON.stringify(response), 'error')

							return false

						}
					}
					blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + JSON.stringify(response), 'error')

					return false
				} else {
					blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + JSON.stringify(response), 'error')
				}


			}).fail(function (jqXHR, textStatus, errorThrown) {
				jQuery('.bluemedia-loader').hide()
				blueMediaUpdateStatus(<?php echo $autopay_blik0_generic_error_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + jqXHR.status, 'error');

				return false
			});


		}


		function blueMediaUpdateStatus(message, status) {
			$('.bm-blik-overlay').show();

			//$targetWrapper = $('.bluemedia-success-wrapper');
			var $targetSpan = $('#bm-blik-overlay-status');

			if (status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_SUCCESS ) ?>') {
				$targetSpan.addClass('bm-blik-overlay-status--success').removeClass('bm-blik-overlay-status--process bm-blik-overlay-status--error');
			} else if (status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_CHECK_DEVICE ) ?>') {
				$targetSpan.addClass('bm-blik-overlay-status--process').removeClass('bm-blik-overlay-status--success bm-blik-overlay-status--error');
			} else if (status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_WAIT ) ?>') {
				$targetSpan.addClass('bm-blik-overlay-status--process').removeClass('bm-blik-overlay-status--success bm-blik-overlay-status--error');
			} else if (status === '<?php echo esc_js( Payment_Status_Response_Value_Object::STATUS_ERROR ) ?>') {
				$targetSpan.addClass('bm-blik-overlay-status--error').removeClass('bm-blik-overlay-status--success bm-blik-overlay-status--process');
			}

			$targetSpan.text(message);
		}

	})
	;

</script>
