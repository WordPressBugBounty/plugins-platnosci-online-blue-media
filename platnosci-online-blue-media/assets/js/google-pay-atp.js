/**
 * @author Blue Media S.A.
 * @copyright Blue Media S.A.
 * @version 2.0.0
 * @preserve
 */

/**
 * @constructor
 * @param {string} environment
 * @param {string} authToken
 * @param {string} merchantId
 * @param {string} merchantDomain
 * @param {string} merchantName
 * @param {string} gatewayMerchantId
 * @param {array} supportedCardNetworks
 * @param {array} supportedCardAuthMethods
 */
function GooglePay(
  environment,
  authToken,
  merchantId,
  merchantDomain,
  merchantName,
  gatewayMerchantId,
  supportedCardNetworks,
  supportedCardAuthMethods
) {
  this.environment = environment;
  this.authToken = authToken;
  this.merchantId = merchantId;
  this.merchantDomain = merchantDomain;
  this.merchantName = merchantName;
  this.gatewayMerchantId = gatewayMerchantId;
  this.supportedCardAuthMethods = supportedCardAuthMethods;
  this.supportedCardNetworks = supportedCardNetworks;
  this.requireShippingAddress = false;

  this.client = null;
  this.transactionAmount = 0;
  this.transactionCurrencyCode = '';
  this.transactionCountryCode = '';
  this.transactionStatus = '';

  this.payButtonContainerId = 'js-pay-button-wrapper';
}

/**
 * Tworzy i dodaje do drzewa DOM przycisk Zapłać przez GPay.
 *
 * @private
 * @param {function} onPaidCallback
 * @see {@link https://developers.google.com/pay/api/web/reference/client#createButton}
 */
GooglePay.prototype._createPayButton = function (onPaidCallback) {
  const self = this,
    button = self.client.createButton({
      onClick: function () {
        self._onPayButtonClickCallback(onPaidCallback)
      }
    });

  document.getElementById(self.payButtonContainerId).appendChild(button);
};

/**
 * Zwraca dane do zapytania o płatność.
 *
 * @private
 * @returns {object} {@link https://developers.google.com/pay/api/web/reference/object#PaymentDataRequest}
 */
GooglePay.prototype._getPaymentDataRequestData = function () {
  return {
    apiVersion: 2,
    apiVersionMinor: 0,
    merchantInfo: {
      merchantId: this.merchantId,
      merchantOrigin: this.merchantDomain,
      merchantName: this.merchantName,
      authJwt: this.authToken
    },
    allowedPaymentMethods: [
      {
        type: 'CARD',
        parameters: {
          allowedAuthMethods: this.supportedCardAuthMethods,
          allowedCardNetworks: this.supportedCardNetworks
        },
        tokenizationSpecification: {
          type: 'PAYMENT_GATEWAY',
          parameters: {
            gateway: 'bluemedia',
            gatewayMerchantId: this.gatewayMerchantId
          }
        }
      }
    ],
    transactionInfo: {
      currencyCode: this.transactionCurrencyCode,
      countryCode: this.transactionCountryCode,
      totalPriceStatus: this.transactionStatus,
      totalPrice: this.transactionAmount
    },
    shippingAddressRequired: this.requireShippingAddress,
  };
};

/**
 * Zwraca dane do zapytania o sprawdzenie możliwości płacenia za pomocą Google Pay.
 *
 * @private
 * @returns {object} {@link https://developers.google.com/pay/api/web/reference/request-objects#IsReadyToPayRequest}
 */
GooglePay.prototype._getIsReadyToPayRequestData = function () {
  const requestData = this._getPaymentDataRequestData();

  delete requestData.merchantInfo;
  delete requestData.transactionInfo;
  delete requestData.shippingAddressRequired;

  return requestData;
};

/**
 * Inicjalizuje klienta Google Pay API.
 *
 * @private
 * @see {@link https://developers.google.com/pay/api/web/reference/client}
 */
GooglePay.prototype._initApiClient = function () {
  this.client = new google.payments.api.PaymentsClient({
    environment: this.environment
  });
};

/**
 * Wykonuje się po kliknięciu na przycisk Zapłać przez GPay.
 *
 * @private
 * @param {function} onPaidCallback
 */
GooglePay.prototype._onPayButtonClickCallback = function (onPaidCallback) {
  const self = this;

  self.client.loadPaymentData(self._getPaymentDataRequestData())
    .then(function (data) {
      onPaidCallback(data);
    })
    .catch(function (errorMessage) {
      self.onErrorCallback(errorMessage);
    });
};

/**
 * Pobiera dane dotyczące płatności w celu zwiększenia wydajności.
 *
 * @private
 * @see {@link https://developers.google.com/pay/api/web/reference/client#prefetchPaymentData}
 */
GooglePay.prototype._prefetchTransactionData = function () {
  this.client.prefetchPaymentData(this._getPaymentDataRequestData());
};

/**
 * Inicjalizuje Google Pay.
 *
 * @param {function} onPaidCallback
 * @see {@link https://developers.google.com/pay/api/web/reference/client#isReadyToPay}
 */
GooglePay.prototype.init = function (onPaidCallback) {
  const self = this;

  self._initApiClient();

  self.client.isReadyToPay(self._getIsReadyToPayRequestData())
    .then(function (response) {
      if (response.result) {
        self._createPayButton(onPaidCallback);
        self._prefetchTransactionData();
      } else {
        self.onErrorCallback(response);
      }
    })
    .catch(function (errorMessage) {
      self.onErrorCallback(errorMessage);
    });
};

/**
 * Wyświetlenie komunikatu w przypadku wystąpienia błędu.
 *
 * @param {string} errorMessage
 */
GooglePay.prototype.onErrorCallback = function (errorMessage) {
  console.error(errorMessage);
};

/**
 * Ustawia, czy adres użytkownika ma być zwracany z Google Pay API.
 *
 * @param {boolean} requireShippingAddress
 * @see {@link https://developers.google.com/pay/api/web/reference/request-objects#PaymentDataRequest}
 */
GooglePay.prototype.setRequireShippingAddress = function (requireShippingAddress) {
  this.requireShippingAddress = requireShippingAddress;
};

/**
 * Ustawia kwotę transakcji.
 *
 * @param {numeric} amount
 * @see {@link https://developers.google.com/pay/api/web/reference/object#TransactionInfo}
 */
GooglePay.prototype.setTransactionAmount = function (amount) {
  this.transactionAmount = amount;
};

/**
 * Ustawia walutę transakcji.
 *
 * @param {string} code
 * @see {@link https://developers.google.com/pay/api/web/reference/object#TransactionInfo}
 */
GooglePay.prototype.setTransactionCurrencyCode = function (code) {
  this.transactionCurrencyCode = code;
};

/**
 * Ustawia kraj transakcji.
 *
 * @param {string} code
 * @see {@link https://developers.google.com/pay/api/web/reference/object#TransactionInfo}
 */
GooglePay.prototype.setTransactionCountryCode = function (code) {
  this.transactionCountryCode = code;
};

/**
 * Ustawia status transakcji.
 *
 * @param {string} status
 * @see {@link https://developers.google.com/pay/api/web/reference/object#TransactionInfo}
 */
GooglePay.prototype.setTransactionStatus = function (status) {
  this.transactionStatus = status;
};
