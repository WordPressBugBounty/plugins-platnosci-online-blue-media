/* global jQuery */
(function ($) {
    'use strict';

    $(function () {
        var $wrapper = $('.bm-payment-channels__wrapper');
        if (!$wrapper.length) {
            return;
        }

        // Initialize sortable list
        $wrapper.sortable({
            placeholder: 'bm-sort-placeholder',
            update: saveCurrentOrder,
            // Some browsers trigger only the `stop` event (not `update`) when the last element is moved.
            stop: saveCurrentOrder
        }).disableSelection();

        // Initialize accordion functionality for expandable groups
        initAccordion();

        // Reset button logic simplify
        $('#bm-reset-order').on('click', function () {
            $('#bm_payment_methods_order_field').val('');
            $('#bm_reset_order_field').val('1');

            // No visual reordering; list stays as is. Option will be cleared and page reload after submit shows API order.

            var $form = $('#mainform');
            if ($form.length) {
                if (!$form.find('input[name="save"]').length) {
                    $form.append('<input type="hidden" name="save" value="Save changes" />');
                }
                $form.submit();
            }
        });

        // Ensure correct value on load
        saveCurrentOrder();

        function saveCurrentOrder() {
            var order = [];
            $wrapper.children('li').each(function () {
                var slug = $(this).data('slug');
                if (slug) {
                    order.push(slug);
                }
            });
            $('#bm_payment_methods_order_field').val(order.join(','));

            // Re-enable WooCommerce save button if it was disabled
            var $saveBtn = $('.wc-action-button, .woocommerce-save-button, #save').filter('[disabled]');
            if ($saveBtn.length) {
                $saveBtn.prop('disabled', false);
            }
        }

        function initAccordion() {
            // Add click handler for expandable group names
            $('.bm-group-name').on('click', function() {
                var $group = $(this).closest('.bm-group-expandable');
                var $wrapper = $group.find('.bm-payment-channel__wrapper');
                var $items = $group.find('.bm-payment-channel__item');
                var $groupName = $(this); // The paragraph with bm-group-name class
                
                if ($wrapper.hasClass('show')) {
                    // Collapse
                    $items.slideUp(200, function() {
                        $wrapper.removeClass('show');
                        // Set height to auto for smooth transition
                        $group.css('height', '34px');
                        // Remove padding from group name paragraph
                        $groupName.css('padding-top', '0');
                    });
                } else {
                    // Expand
                    $wrapper.addClass('show');
                    // First set height to auto to expand the block
                    $group.css('height', 'auto');
                    // Add padding to group name paragraph
                    $groupName.css('padding-top', '10px');
                    // Then show the items with animation
                    $items.slideDown(200);
                }
            });

            // Initially hide all items in expandable groups and set fixed height
            $('.bm-group-expandable .bm-payment-channel__item').hide();
            $('.bm-group-expandable').css('height', '34px');
        }
    });
})(jQuery); 