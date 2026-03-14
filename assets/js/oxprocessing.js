/**
 * 0xProcessing for WooCommerce - Frontend JavaScript
 * Enhanced with Select2 for modern searchable dropdown
 */

(function ($) {
    'use strict';

    // Main object
    var OXProcessing = {

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initCurrencySelector();
        },

        /**
         * Bind events
         */
        bindEvents: function () {
            $(document).on('change', '#oxprocessing_currency', this.handleCurrencyChange.bind(this));
            $(document.body).on('updated_checkout', this.initCurrencySelector.bind(this));
        },

        /**
         * Initialize currency selector with Select2
         */
        initCurrencySelector: function () {
            var $selector = $('#oxprocessing_currency');

            if ($selector.length === 0) {
                return;
            }

            // Add dark theme class to original select
            $selector.addClass('oxprocessing-dark-select');

            // Destroy existing Select2 instance if exists
            if ($selector.hasClass('select2-hidden-accessible')) {
                $selector.select2('destroy');
            }

            // Initialize Select2 with custom styling
            $selector.select2({
                placeholder: '-- Select Currency --',
                allowClear: false,
                width: '100%',
                dropdownCssClass: 'oxprocessing-select2-dropdown',
                containerCssClass: 'oxprocessing-select2-container',
                minimumResultsForSearch: 5
            });

            // If only one currency option (besides placeholder), auto-select it
            var $options = $selector.find('option');
            if ($options.length === 2) {
                $selector.val($options.last().val()).trigger('change');
            }
        },

        /**
         * Handle currency selection change
         */
        handleCurrencyChange: function (e) {
            var currency = $(e.target).val();
            // Currency changed - you can add custom logic here if needed
        },

        /**
         * Validate form before submission
         */
        validateForm: function () {
            var $currency = $('#oxprocessing_currency');

            if ($currency.length && !$currency.val()) {
                this.showError('Please select a cryptocurrency for payment.');
                return false;
            }

            return true;
        },

        /**
         * Show error message
         */
        showError: function (message) {
            // Remove existing notices
            $('.woocommerce-error').remove();

            // Add new notice
            var $notice = $('<div class="woocommerce-error">' + message + '</div>');
            $('.woocommerce-notices-wrapper').prepend($notice);

            // Scroll to notice
            $('html, body').animate({
                scrollTop: $notice.offset().top - 100
            }, 500);
        },

        /**
         * Show success message
         */
        showSuccess: function (message) {
            // Remove existing notices
            $('.woocommerce-message').remove();

            // Add new notice
            var $notice = $('<div class="woocommerce-message">' + message + '</div>');
            $('.woocommerce-notices-wrapper').prepend($notice);
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        OXProcessing.init();
    });

    // Expose to global scope for debugging
    window.OXProcessing = OXProcessing;

})(jQuery);