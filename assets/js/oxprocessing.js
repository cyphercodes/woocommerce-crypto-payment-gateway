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
         * Parse a currency string like "USDT (TRC20)" into { symbol: "USDT", network: "TRC20" }
         */
        parseCurrency: function (text) {
            if (!text) return { symbol: '', network: '' };
            var match = text.match(/^([A-Z0-9]+)\s*\(([^)]+)\)$/i);
            if (match) {
                return { symbol: match[1].toUpperCase(), network: match[2] };
            }
            return { symbol: text.toUpperCase(), network: '' };
        },

        /**
         * Format a Select2 result item with a coin badge
         */
        formatCurrency: function (item) {
            if (!item.id) {
                return item.text; // Placeholder
            }

            var parsed = OXProcessing.parseCurrency(item.text);
            var initials = parsed.symbol.substring(0, 3);

            var $badge = $('<span class="oxprocessing-currency-icon-small">' + initials + '</span>');
            var label = parsed.network
                ? '<strong>' + parsed.symbol + '</strong> <span style="opacity:0.6;">(' + parsed.network + ')</span>'
                : '<strong>' + parsed.symbol + '</strong>';
            var $label = $('<span class="oxprocessing-currency-name">' + label + '</span>');

            var $container = $('<span class="oxprocessing-currency-option"></span>');
            $container.append($badge).append($label);
            return $container;
        },

        /**
         * Format selected item (compact view)
         */
        formatCurrencySelection: function (item) {
            if (!item.id) {
                return item.text;
            }
            var parsed = OXProcessing.parseCurrency(item.text);
            if (parsed.network) {
                return parsed.symbol + ' (' + parsed.network + ')';
            }
            return parsed.symbol;
        },

        /**
         * Initialize currency selector with Select2
         */
        initCurrencySelector: function () {
            var $selector = $('#oxprocessing_currency');

            if ($selector.length === 0) {
                return;
            }

            // Destroy existing Select2 instance if exists
            if ($selector.hasClass('select2-hidden-accessible')) {
                $selector.select2('destroy');
            }

            // Initialize Select2 with custom styling and templates
            $selector.select2({
                placeholder: '-- Select Currency --',
                allowClear: false,
                width: '100%',
                dropdownCssClass: 'oxprocessing-select2-dropdown',
                containerCssClass: 'oxprocessing-select2-container',
                minimumResultsForSearch: 5,
                templateResult: this.formatCurrency,
                templateSelection: this.formatCurrencySelection
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