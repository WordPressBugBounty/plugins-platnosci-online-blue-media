function addCurrentClass(el) {
  var canContinue = false;

  const current_payment_block = el.closest('#payment');
  const elements = current_payment_block.querySelectorAll(".bm-payment-channel-item > label > input[type='radio']");

  const bank_group_wrap = current_payment_block.querySelector(".bm-group-expandable-wrapper");
  const bank_group_radio = current_payment_block.querySelector("#bm-gateway-bank-group");
  if (elements) {
    elements.forEach((element) => {
      if (element.checked) {
        let closestItem = element.closest(".bm-payment-channel-item");

        if (closestItem && closestItem.classList) {
          closestItem.classList.toggle("selected");
          canContinue = closestItem.classList.contains("selected");
        }


        if (!canContinue) {
          element.checked = false
        }

        // hide list of "PRZELEW INTERNETOWY"
        if (!isChild(element, current_payment_block.querySelector("div.bm-group-expandable-wrapper"))) {

          if (bank_group_wrap && bank_group_wrap.classList) {
            bank_group_wrap.classList.remove('active');
          }


          if (bank_group_radio && bank_group_radio.checked) {
            bank_group_radio.checked = !bank_group_radio.checked;

          }
        }
      }
      current_payment_block.querySelectorAll(".bm-payment-channel-item > label > input[type='radio']").forEach((element) => {
        if (element.checked === false) {
          element.closest(".bm-payment-channel-item").classList.remove("selected");
        }
      })
    });
  }


  setTimeout(function() {
    if (isGpaySelected()) {
      BmDeactivateNewOrderButton()
    } else if (canContinue) {
      BmActivateNewOrderButton();
    } else {
      BmDeactivateNewOrderButton();
    }
  }, 10);
}

function checkAndUpdateButton() {
  if (isGpaySelected() && !bm_global_gpay_can_continue) {
    BmDeactivateNewOrderButton()
  } else {
    const placeOrderBtn = document.querySelector('#place_order');
    if (placeOrderBtn) {
      const anyPaymentSelected = document.querySelector('input[name="bm-payment-channel"]:checked') ||
                                  document.querySelector('#bm-gateway-bank-group:checked');

      if (anyPaymentSelected) {
        const gpayRadio = document.querySelector('#bm-gateway-id-1512');
        if (!gpayRadio || !gpayRadio.checked) {
          BmActivateNewOrderButton();
        }
      }
    }
  }
}

var bm_global_update_checkout_in_progress = 1;
var bm_global_timer = null;
var bm_global_gpay_can_continue = false;
var bm_checkout_locked_by = '';

function isGpaySelected() {
  const gpayRadio = document.querySelector('#bm-gateway-id-1512');
  if (!gpayRadio) return false;
  if (gpayRadio.checked) return true;
  const gpayItem = gpayRadio.closest('.bm-payment-channel-item');
  if (gpayItem && gpayItem.classList.contains('selected')) return true;
  return false;
}

function BmHideNewOrderButton() {

  const placeOrderBtn = document.querySelector('#place_order');
  if (placeOrderBtn) {
    placeOrderBtn.style.setProperty('display', 'none', 'important');
    placeOrderBtn.style.setProperty('visibility', 'hidden', 'important');
    jQuery(placeOrderBtn).prop("disabled", true);
  }
}

jQuery(document).ready(function () {
  checkAndUpdateButton();

  jQuery(document).on('change', 'input[name="bm-payment-channel"]', function() {
    setTimeout(function() {
      checkAndUpdateButton();
    }, 10);
  });

  jQuery(document).on('change', '#bm-gateway-bank-group', function() {
    setTimeout(function() {
      checkAndUpdateButton();
    }, 10);
  });

  setInterval(function() {
    checkAndUpdateButton();
  }, 100);
  jQuery('body').on('update_checkout', function () {
    bm_global_update_checkout_in_progress = 1;
    const bm_payment_channel_items = document.querySelectorAll(".bm-payment-channels-wrapper .bm-payment-channel-item > label > input[type='radio']");
    const bm_gateway_bank_group = document.querySelector("#bm-gateway-bank-group");

    if (bm_gateway_bank_group && bm_gateway_bank_group.checked) {
      const checkUpdateComplete = setInterval(function () {
        if (bm_global_update_checkout_in_progress === 0) {
          const bm_payment_channel_group_item = document.querySelector(".bm-group-przelew-internetowy .bm-payment-channel-group-item");
          const bm_group_expandable_wrapper = document.querySelector(".bm-group-przelew-internetowy  .bm-group-expandable-wrapper");

          if (bm_payment_channel_group_item && bm_group_expandable_wrapper) {
            clearInterval(checkUpdateComplete);
            bm_payment_channel_group_item.classList.add('bm-selected-group');
            bm_group_expandable_wrapper.classList.add('active');
            checkAndUpdateButton();
          }
        }
      }, 100);
    }

    if (bm_payment_channel_items) {
      bm_payment_channel_items.forEach((element) => {
        if (element.checked) {
          const checkUpdateComplete = setInterval(function () {
            if (bm_global_update_checkout_in_progress === 0) {
              clearInterval(checkUpdateComplete);
              checkAndUpdateButton();
            }
          }, 100);
        }
      });
    }

    setTimeout(function() {
      checkAndUpdateButton();
    }, 50);
  });

  if (typeof window.blueMedia !== 'undefined') {
    if (typeof blue_media_ga4_tasks !== 'undefined' && typeof blueMedia.ga4TrackingId !== 'undefined') {
      window.dataLayer = window.dataLayer || [];


      function gtag() {
        dataLayer.push(arguments);
      }

      gtag('js', new Date());
      gtag('config', blueMedia.ga4TrackingId);
      let events = JSON.parse(blue_media_ga4_tasks)[0].events;

      events.forEach((event) => {
        gtag('event', event.name,
          {
            'items': event.params.items
          }
        )
      });
    }
  }
})

function blueMediaRadioShow() {
  jQuery('.payment_box.payment_method_bluemedia .payment_box.payment_method_bacs').css('display', 'block');

}

function blueMediaRadioHide() {
  jQuery('.payment_box.payment_method_bluemedia .payment_box.payment_method_bacs').css('display', 'none');
};

function blueMediaRadioTest() {
  if (jQuery('#payment_method_bluemedia').is(':checked')) {
    blueMediaRadioShow();
  }
};

document.addEventListener('click', function (e) {
  e = e || window.event;
  var target = e.target || e.srcElement;

  const bank_group_wrap = document.querySelector(".bm-group-expandable-wrapper");
  // click on PRZELEW INTERNETOWY
  if (target.hasAttribute('id') && target.getAttribute('id') == 'bm-gateway-bank-group') {
    if (target.checked) {
      BmSelectGroupedLi()

      document.querySelectorAll(".bm-group-expandable-wrapper").forEach((element) => {
        if (element && element.classList) {
          element.classList.add('active');
        }
      });

      document.querySelectorAll(".bm-payment-channel-item > label > input[type='radio']").forEach((element) => {
        if (element.checked) {
          let closestItem = element.closest(".bm-payment-channel-item");
          if (closestItem && closestItem.classList) {
            closestItem.classList.remove("selected");
          }
          element.checked = !element.checked;
        }
      });

      setTimeout(function() {
        checkAndUpdateButton();
      }, 10);
    }
  } else {
    if (target.hasAttribute('class') && target.getAttribute('class') !== 'bm-payment-channel-group-in-group') {
      BmDeselectGroupedLi()
    }

    if (target.type === 'radio' && (target.name === 'bm-payment-channel' || target.id === 'bm-gateway-bank-group')) {
      setTimeout(function() {
        checkAndUpdateButton();
      }, 10);
    }
  }
});

function isChild(obj, parentObj) {
  while (obj != undefined && obj != null && obj.tagName.toUpperCase() != 'BODY') {
    if (obj == parentObj) {
      return true;
    }
    obj = obj.parentNode;
  }
  return false;
}

function BmDeactivateNewOrderButton() {
  const placeOrderBtn = document.querySelector('#place_order');
  if (placeOrderBtn && !placeOrderBtn.disabled) {
    jQuery(placeOrderBtn).prop('disabled', true);
  }
}

function BmActivateNewOrderButton() {
  if (isGpaySelected()) {
    return
  }
  const placeOrderBtn = document.querySelector('#place_order');
  if (placeOrderBtn) {
    placeOrderBtn.style.setProperty('display', '', 'important');
    placeOrderBtn.style.setProperty('visibility', '', 'important');
    placeOrderBtn.style.removeProperty('display');
    placeOrderBtn.style.removeProperty('visibility');
    jQuery(placeOrderBtn).show().prop("disabled", false);
  }
}

function BmSelectGroupedLi() {
  jQuery('.bm-payment-channel-group-item').addClass('bm-selected-group')
}

function BmDeselectGroupedLi() {
  jQuery('.bm-payment-channel-group-item').removeClass('bm-selected-group')
}
