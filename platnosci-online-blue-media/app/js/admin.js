jQuery(document).ready(function () {
  const $ = jQuery

  submitCurrencyEdit();

  //var radio = jQuery('#woocommerce_bluemedia_testmode');

  function api_fields_logic(radio_val) {
    switch (radio_val) {
      case 'yes':
        setupView(true);
        break;
      default:
        setupView(false);
        break;
    }
  }

  api_fields_logic(jQuery('input[type=radio][name=woocommerce_bluemedia_testmode]:checked').val());

  jQuery('input[type=radio][name=woocommerce_bluemedia_testmode]').on('change', function () {
    api_fields_logic(jQuery(this).val());
  });


  function setupView(isChecked) {
    let test_service_id_input = jQuery('.woocommerce_bluemedia_test_service_id_i')
    let test_private_key_input = jQuery('.woocommerce_bluemedia_test_private_key_i')
    let prod_service_id_input = jQuery('.woocommerce_bluemedia_service_id_i')
    let prod_private_key_input = jQuery('.woocommerce_bluemedia_private_key_i')

    if (true === isChecked) {

      prod_service_id_input.removeAttr('required');
      prod_private_key_input.removeAttr('required');
      test_service_id_input.attr('required', '');
      test_private_key_input.attr('required', '');
      prod_service_id_input.val(prod_service_id_input.data('origin_value'))
      prod_private_key_input.val(prod_private_key_input.data('origin_value'))
      jQuery('.woocommerce_bluemedia_service_id-tr').hide();
      jQuery('.woocommerce_bluemedia_private_key-tr').hide();
      jQuery('.woocommerce_bluemedia_test_service_id-tr').show();
      jQuery('.woocommerce_bluemedia_test_private_key-tr').show();
    } else {
      prod_service_id_input.attr('required', '');
      prod_private_key_input.attr('required', '');
      test_service_id_input.removeAttr('required');
      test_private_key_input.removeAttr('required');
      jQuery('.woocommerce_bluemedia_service_id-tr').show();
      jQuery('.woocommerce_bluemedia_private_key-tr').show();
      test_service_id_input.val(test_service_id_input.data('origin_value'))
      test_private_key_input.val(test_private_key_input.data('origin_value'))
      jQuery('.woocommerce_bluemedia_test_service_id-tr').hide();
      jQuery('.woocommerce_bluemedia_test_private_key-tr').hide();
    }
  }


  jQuery('.bm_ga_help_modal').click(function (e) {
    e.preventDefault();
    let target = jQuery(this).attr('data-modal');
    if (target) {
      let targetSelector = '.' + target
      let targetObj = jQuery(targetSelector)

      if (targetObj.length) {
        targetObj.show();
        targetObj.addClass('active');
        jQuery('body').toggleClass('bm-modal-background');
        jQuery('.bm-modal-overlay').toggleClass('active');
      }
    }
  });

  jQuery('.bm-modal-content .bm-close, .bm-modal-overlay').click(function () {
    jQuery('.bm-modal-content').hide();
    jQuery('.bm-modal-content').removeClass('active');
    jQuery('body').toggleClass('bm-modal-background');
    jQuery('.bm-modal-overlay').toggleClass('active');
  });


  const bluemedia_whitelabel_el = jQuery('input[name="woocommerce_bluemedia_whitelabel"]');
  const bluemedia_blik0_el = jQuery('.woocommerce_bluemedia_blik_type-tr');
  const bluemedia_blik0_title_el = jQuery('.section-woocommerce_bluemedia_blik_type_title');

  function updateDescription() {
    const selectedValue = bluemedia_whitelabel_el.filter(':checked').val();
    const $description = jQuery('.woocommerce_bluemedia_whitelabel-tr').find('.description');
    if (selectedValue === 'no') {
      $description.text(blueMedia.whitelabel_description.no);
      //bluemedia_blik0_el.hide()
      //bluemedia_blik0_title_el.hide()

      toggleDisableJqueryElement(bluemedia_blik0_title_el)
      toggleDisableJqueryElement(bluemedia_blik0_el)

    } else if (selectedValue === 'yes') {
      $description.text(blueMedia.whitelabel_description.yes);
      //bluemedia_blik0_el.show()
      //bluemedia_blik0_title_el.show()
      toggleDisableJqueryElement(bluemedia_blik0_title_el)
      toggleDisableJqueryElement(bluemedia_blik0_el)
    }
  }

  bluemedia_whitelabel_el.change(updateDescription);

  jQuery('.autopay-settings-section.section-payment_settings .bm-group-przelew-internetowy > .bm-payment-channel__wrapper').on('click', function () {
    jQuery('.autopay-settings-section.section-payment_settings .bm-group-przelew-internetowy > .bm-payment-channel__wrapper').toggleClass('show');
  });


  function toggleDisableJqueryElement(element) {
    if (element.hasClass('autopay_disabled')) {
      element.removeClass('autopay_disabled');
    } else {
      element.addClass('autopay_disabled');
    }
  }

  function submitCurrencyEdit() {

    jQuery('.autopay-select-currency-item').click(function (e) {
      e.preventDefault()
      jQuery("[name='autopay_currency_edit[currency_code]']").val(jQuery(this).data('cur'));
      jQuery("[name='autopay_currency_edit[action]']").val('add');
      jQuery('#autopay_form_currency').submit();
      return false
    });

    jQuery('.autopay-add-currency-tab').click(function (e) {
      e.preventDefault()
      jQuery('.autopay-select-currency-list').toggleClass('hidden');
      return false
    });

    jQuery('.autopay-remove-currency-item').click(function (e) {
      e.preventDefault()
      jQuery("[name='autopay_currency_edit[currency_code]']").val(jQuery(this).data('cur'));
      jQuery("[name='autopay_currency_edit[action]']").val('remove');
      jQuery('#autopay_form_currency').submit();
      return false
    });

  }
})
;
