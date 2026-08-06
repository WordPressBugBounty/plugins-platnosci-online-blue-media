<?php
/**
 * Card Widget inline template.
 *
 * @package bm-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$autopay_card_widget_data = isset( $autopay_card_widget_data ) && is_array( $autopay_card_widget_data )
	? $autopay_card_widget_data
	: [];

$autopay_cw_service_id = isset( $autopay_card_widget_data['service_id'] ) ? (string) $autopay_card_widget_data['service_id'] : '';
$autopay_cw_amount     = isset( $autopay_card_widget_data['amount'] ) ? (string) $autopay_card_widget_data['amount'] : '0';
$autopay_cw_currency   = isset( $autopay_card_widget_data['currency'] ) ? (string) $autopay_card_widget_data['currency'] : 'PLN';
$autopay_cw_language   = isset( $autopay_card_widget_data['language'] ) ? (string) $autopay_card_widget_data['language'] : 'pl';
$autopay_cw_cards      = isset( $autopay_card_widget_data['cards_domain'] ) ? (string) $autopay_card_widget_data['cards_domain'] : '';
$autopay_cw_wait_msg   = __( 'Enter your card details in the form above to place the order.', 'platnosci-online-blue-media' );
?>

<div
	class="bm-card-widget bm-card-widget--classic"
	id="bm-card-widget"
	data-service-id="<?php echo esc_attr( $autopay_cw_service_id ); ?>"
	data-amount="<?php echo esc_attr( $autopay_cw_amount ); ?>"
	data-currency="<?php echo esc_attr( $autopay_cw_currency ); ?>"
	data-language="<?php echo esc_attr( $autopay_cw_language ); ?>"
	data-cards-domain="<?php echo esc_attr( $autopay_cw_cards ); ?>"
	data-wait-msg="<?php echo esc_attr( $autopay_cw_wait_msg ); ?>"
>
	<p class="bm-card-widget__help"><?php echo esc_html( $autopay_cw_wait_msg ); ?></p>
	<input
		type="hidden"
		name="atp_card_payment_token"
		id="atp_card_payment_token"
		value=""
		autocomplete="off"
	/>

	<div class="bm-card-widget__iframe-wrapper" id="bm-card-widget-iframe-wrapper">
		<iframe
			id="bm-card-widget-iframe"
			title="<?php echo esc_attr__( 'Card payment form', 'platnosci-online-blue-media' ); ?>"
		></iframe>
	</div>
</div>
<?php
// Classic checkout: attach after Autopay widget SDK (footer) so WidgetConnection exists; once per request.
static $autopay_card_widget_classic_js_added = false;
if ( ! $autopay_card_widget_classic_js_added && wp_script_is( 'autopay_card_widget', 'enqueued' ) ) {
	$autopay_card_widget_classic_js_added = true;
	$autopay_cw_inline_js = <<<'JS'
(function (window, document) {
	'use strict';

	/** Read by app/js/front.js BmActivateNewOrderButton (must stay in sync with iframe validity). */
	window.__bmAutopayCardWidgetValid = false;

	var widgetInstance = null;
	var boundRoot = null;
	var boundConfigSignature = '';
	var isSendingForm = false;
	/** Tracks Autopay iframe widget validity (both validityStatus and validationResult). */
	var isWidgetValid = false;
	var paymentMethodListenersBound = false;

	function readConfigSignature(root) {
		if (!root) {
			return '';
		}
		return [
			root.getAttribute('data-service-id') || '',
			root.getAttribute('data-amount') || '',
			root.getAttribute('data-currency') || '',
			root.getAttribute('data-language') || '',
			root.getAttribute('data-cards-domain') || ''
		].join('|');
	}

	function setWidgetValidity(valid) {
		isWidgetValid = !!valid;
		window.__bmAutopayCardWidgetValid = isWidgetValid;
	}

	function getCardRoot() {
		return document.getElementById('bm-card-widget');
	}

	function getTokenInput() {
		return document.getElementById('atp_card_payment_token');
	}

	function getPlaceOrderButton() {
		return document.getElementById('place_order');
	}

	function getSelectedPaymentMethod() {
		var el = document.querySelector('input[name="payment_method"]:checked');
		return el ? el.value : '';
	}

	function getSelectedBmPaymentChannel() {
		var el = document.querySelector('input[name="bm-payment-channel"]:checked');
		return el ? el.value : '';
	}

	function isCardWidgetPaymentSelected() {
		return getSelectedPaymentMethod() === 'bluemedia' && getSelectedBmPaymentChannel() === '1500';
	}

	/**
	 * Detect required WC checkout fields that are empty/invalid before triggering
	 * the Autopay iframe. Mirrors the block-checkout `hasWcFieldValidationErrors()`
	 * guard so the iframe loader never starts when the order would be rejected by
	 * WC client/server validation anyway.
	 */
	function hasInvalidWcCheckoutFields() {
		var form = document.querySelector('form.checkout, form.woocommerce-checkout');
		if (!form) {
			return false;
		}
		var inputs = form.querySelectorAll(
			'.validate-required input:not([type="hidden"]), .validate-required select, .validate-required textarea, [required]:not([type="hidden"])'
		);
		for (var i = 0; i < inputs.length; i++) {
			var el = inputs[i];
			if (el.offsetParent === null) {
				continue;
			}
			var val = (el.value == null ? '' : el.value).toString().trim();
			if (val === '') {
				return true;
			}
			if (el.type === 'checkbox' && !el.checked) {
				return true;
			}
			if (el.type === 'email' && val.indexOf('@') === -1) {
				return true;
			}
		}
		return false;
	}

	function getWaitMessage() {
		var root = getCardRoot();
		return root && root.getAttribute('data-wait-msg') ? root.getAttribute('data-wait-msg') : '';
	}

	/**
	 * Disable #place_order until the card widget reports valid input; re-enable for other methods.
	 * Programmatic submit after token must not leave the button disabled (click() would not run).
	 */
	function syncPlaceOrderButtonState() {
		var btn = getPlaceOrderButton();
		if (!btn) {
			return;
		}

		if (!isCardWidgetPaymentSelected()) {
			btn.disabled = false;
			btn.removeAttribute('title');
			btn.classList.remove('bm-card-widget-place-order--blocked');
			if (typeof window.checkAndUpdateButton === 'function') {
				window.checkAndUpdateButton();
			}
			return;
		}

		if (!isWidgetValid) {
			btn.disabled = true;
			var msg = getWaitMessage();
			if (msg) {
				btn.setAttribute('title', msg);
			}
			btn.classList.add('bm-card-widget-place-order--blocked');
		} else {
			btn.disabled = false;
			btn.removeAttribute('title');
			btn.classList.remove('bm-card-widget-place-order--blocked');
		}

		if (typeof window.checkAndUpdateButton === 'function') {
			window.checkAndUpdateButton();
		}
	}

	function bindPaymentMethodChangeListeners() {
		if (paymentMethodListenersBound || typeof jQuery === 'undefined') {
			return;
		}
		paymentMethodListenersBound = true;
		jQuery(document.body).on(
			'change',
			'input[name="payment_method"], input[name="bm-payment-channel"]',
			function () {
				syncPlaceOrderButtonState();
			}
		);
	}

	function stopWidget() {
		if (widgetInstance && typeof widgetInstance.stopConnection === 'function') {
			try {
				widgetInstance.stopConnection();
			} catch (e) {
				// ignore
			}
		}
		widgetInstance = null;
		boundConfigSignature = '';
		isSendingForm = false;
	}

	function triggerCheckoutSubmit() {
		var button = getPlaceOrderButton();
		if (button) {
			button.click();
		}
	}

	function initCardWidget() {
		bindPaymentMethodChangeListeners();

		var root = getCardRoot();
		var configSignature = readConfigSignature(root);

		if (
			widgetInstance
			&& root
			&& root === boundRoot
			&& boundConfigSignature
			&& boundConfigSignature === configSignature
		) {
			syncPlaceOrderButtonState();
			return;
		}

		if (!root || root !== boundRoot) {
			stopWidget();
			boundRoot = root;
			boundConfigSignature = '';
		}

		if (!root) {
			isSendingForm = false;
			setWidgetValidity(false);
			syncPlaceOrderButtonState();
			return;
		}

		var cardsDomain = root.getAttribute('data-cards-domain') || '';
		var serviceId = root.getAttribute('data-service-id') || '';
		if (!cardsDomain || !serviceId) {
			isSendingForm = false;
			setWidgetValidity(false);
			syncPlaceOrderButtonState();
			return;
		}

		if (typeof window.WidgetConnection === 'undefined' || typeof window.widgetEvents === 'undefined') {
			syncPlaceOrderButtonState();
			return;
		}

		var iframeEl = document.getElementById('bm-card-widget-iframe');
		if (!iframeEl) {
			syncPlaceOrderButtonState();
			return;
		}

		stopWidget();

		isSendingForm = false;
		setWidgetValidity(false);
		syncPlaceOrderButtonState();

		var tokenReady = false;

		iframeEl.src = cardsDomain + '/widget-new/partner';

		widgetInstance = new window.WidgetConnection({
			language: root.getAttribute('data-language') || 'pl',
			amount: parseFloat(root.getAttribute('data-amount') || '0'),
			currency: root.getAttribute('data-currency') || 'PLN',
			serviceId: parseInt(serviceId, 10),
		});
		boundConfigSignature = configSignature;

		widgetInstance.startConnection(iframeEl).then(function () {
			widgetInstance.on(window.widgetEvents.validityStatus, function (message, eventData) {
				setWidgetValidity(eventData && eventData.valid);
				syncPlaceOrderButtonState();
			});
			widgetInstance.on(window.widgetEvents.validationResult, function (message, eventData) {
				var valid = !!(eventData && eventData.valid);
				setWidgetValidity(valid);
				// Reset in-flight state: validationResult is the SDK's terminal response to
				// sendForm(). When the iframe switches to DCC (VIEW_CHANGE) instead of emitting
				// formSuccess, isSendingForm would otherwise stay true and block the next
				// sendForm() call needed to submit the DCC currency selection.
				isSendingForm = false;
				syncPlaceOrderButtonState();
			});
			widgetInstance.on(window.widgetEvents.formSuccess, function (message) {
				setWidgetValidity(true);
				var tokenInput = getTokenInput();
				if (tokenInput) {
					tokenInput.value = message;
				}
				isSendingForm = false;
				tokenReady = true;
				var btn = getPlaceOrderButton();
				if (btn) {
					btn.disabled = false;
					btn.removeAttribute('title');
					btn.classList.remove('bm-card-widget-place-order--blocked');
				}
				if (typeof window.checkAndUpdateButton === 'function') {
					window.checkAndUpdateButton();
				}
				triggerCheckoutSubmit();
			});
		});

		function onPlaceOrderClick(event) {
			var target = event.target;
			if (!target) {
				return;
			}
			var isPlaceOrder =
				target.id === 'place_order' || target.name === 'woocommerce_checkout_place_order';
			if (!isPlaceOrder) {
				return;
			}
			if (!isCardWidgetPaymentSelected()) {
				return;
			}

			if (tokenReady) {
				tokenReady = false;
				return;
			}

			if (hasInvalidWcCheckoutFields()) {
				// Reset the in-flight flag defensively so a subsequent click,
				// after the user fixes the WC field, can reach `sendForm()`.
				isSendingForm = false;
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();

			if (!isWidgetValid || isSendingForm || !widgetInstance) {
				return;
			}

			isSendingForm = true;
			widgetInstance.sendForm();
		}

		if (window.bmCardWidgetPlaceOrderCapture) {
			document.removeEventListener('click', window.bmCardWidgetPlaceOrderCapture, true);
		}
		window.bmCardWidgetPlaceOrderCapture = onPlaceOrderClick;
		document.addEventListener('click', onPlaceOrderClick, true);
	}

	window.bmInitAutopayCardWidgetClassic = initCardWidget;

	if (typeof jQuery !== 'undefined') {
		jQuery(function () {
			initCardWidget();
			syncPlaceOrderButtonState();
		});
		jQuery(document.body).on('updated_checkout', function () {
			initCardWidget();
			syncPlaceOrderButtonState();
		});
	} else {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () {
				initCardWidget();
				syncPlaceOrderButtonState();
			});
		} else {
			initCardWidget();
			syncPlaceOrderButtonState();
		}
	}
})(window, document);
JS;
	wp_add_inline_script( 'autopay_card_widget', $autopay_cw_inline_js, 'after' );
}
