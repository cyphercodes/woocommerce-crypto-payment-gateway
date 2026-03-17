<?php
/**
 * 0xProcessing WooCommerce Payment Gateway
 *
 * Extends WC_Payment_Gateway to provide cryptocurrency checkout.
 *
 * @package WC_0xProcessing
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_0xProcessing_Gateway extends WC_Payment_Gateway {

    /** @var WC_0xProcessing_API */
    private $api;

    /** @var bool */
    private $test_mode;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'oxprocessing';
        $this->method_title       = __('0xProcessing Crypto', '0xprocessing-for-woocommerce');
        $this->method_description = __(
            'Accept cryptocurrency payments via 0xProcessing. Supports Bitcoin, Ethereum, USDT, and 50+ other cryptocurrencies.',
            '0xprocessing-for-woocommerce'
        );
        $this->has_fields = true;
        $this->icon       = WC_OXPROCESSING_PLUGIN_URL . 'assets/img/crypto-icon.svg';

        // We do NOT support refunds through the API — they must be handled
        // in the 0xProcessing dashboard.  Declaring 'refunds' would show a
        // non-functional button in WooCommerce.
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer',
            'multiple_subscriptions',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Read settings
        $this->title       = $this->get_option('title', __('Cryptocurrency via 0xProcessing', '0xprocessing-for-woocommerce'));
        $this->description = $this->get_option('description', __('Pay with Bitcoin, Ethereum, USDT, and other cryptocurrencies.', '0xprocessing-for-woocommerce'));
        $this->enabled     = $this->get_option('enabled');
        $this->test_mode   = $this->get_option('test_mode') === 'yes';

        // Initialize API
        $this->api = new WC_0xProcessing_API();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_payment_status'), 10);
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'display_payment_status_thankyou'), 10);

        // Admin notice for incomplete configuration
        add_action('admin_notices', array($this, 'configuration_notice'));

        // WooCommerce Subscriptions: handle scheduled renewal payments (manual mode)
        if (class_exists('WC_Subscriptions_Order')) {
            add_action(
                'woocommerce_scheduled_subscription_payment_' . $this->id,
                array($this, 'scheduled_subscription_payment'),
                10,
                2
            );
        }
    }

    // ------------------------------------------------------------------
    //  Availability guard
    // ------------------------------------------------------------------

    /**
     * Only show the gateway on checkout when all required settings are configured.
     *
     * @return bool
     */
    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }

        // Require Merchant ID, API Key, and Webhook Password
        if (empty($this->get_option('merchant_id'))
            || empty($this->get_option('api_key'))
            || empty($this->get_option('webhook_password'))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Show admin notice when the gateway is enabled but not fully configured.
     */
    public function configuration_notice() {
        if ($this->enabled !== 'yes') {
            return;
        }

        $missing = array();
        if (empty($this->get_option('merchant_id'))) {
            $missing[] = __('Merchant ID', '0xprocessing-for-woocommerce');
        }
        if (empty($this->get_option('api_key'))) {
            $missing[] = __('API Key', '0xprocessing-for-woocommerce');
        }
        if (empty($this->get_option('webhook_password'))) {
            $missing[] = __('Webhook Password', '0xprocessing-for-woocommerce');
        }

        if (empty($missing)) {
            return;
        }

        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=oxprocessing');
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('0xProcessing Crypto', '0xprocessing-for-woocommerce') . ':</strong> ';
        echo sprintf(
            /* translators: %1$s: comma-separated list of missing fields, %2$s: opening link tag, %3$s: closing link tag */
            esc_html__('The gateway is enabled but will not appear at checkout until you configure: %1$s. %2$sConfigure now%3$s', '0xprocessing-for-woocommerce'),
            '<strong>' . esc_html(implode(', ', $missing)) . '</strong>',
            '<a href="' . esc_url($url) . '">',
            '</a>'
        );
        echo '</p></div>';
    }

    // ------------------------------------------------------------------
    //  Admin settings
    // ------------------------------------------------------------------

    /**
     * Define admin settings fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', '0xprocessing-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable 0xProcessing Payment Gateway', '0xprocessing-for-woocommerce'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', '0xprocessing-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Title the customer sees during checkout.', '0xprocessing-for-woocommerce'),
                'default'     => __('Cryptocurrency via 0xProcessing', '0xprocessing-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', '0xprocessing-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Description the customer sees during checkout.', '0xprocessing-for-woocommerce'),
                'default'     => __('Pay with Bitcoin, Ethereum, USDT, and other cryptocurrencies.', '0xprocessing-for-woocommerce'),
                'desc_tip'    => true,
            ),

            // --- API credentials ---
            'api_settings' => array(
                'title'       => __('API Settings', '0xprocessing-for-woocommerce'),
                'type'        => 'title',
                'description' => __(
                    'Enter your 0xProcessing API credentials. You can find them in your merchant dashboard under Merchant → API → General Settings.',
                    '0xprocessing-for-woocommerce'
                ),
            ),
            'merchant_id' => array(
                'title'    => __('Merchant ID', '0xprocessing-for-woocommerce'),
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Found in Merchant → Settings → Merchant Management.', '0xprocessing-for-woocommerce'),
            ),
            'api_key' => array(
                'title'    => __('API Key', '0xprocessing-for-woocommerce'),
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Generated in Merchant → API → General Settings. Keep this secure!', '0xprocessing-for-woocommerce'),
            ),
            'webhook_password' => array(
                'title'    => __('Webhook Password', '0xprocessing-for-woocommerce'),
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Must match the password set in Merchant → API → Webhook URL.', '0xprocessing-for-woocommerce'),
            ),

            // --- Advanced ---
            'advanced_settings' => array(
                'title'       => __('Advanced Settings', '0xprocessing-for-woocommerce'),
                'type'        => 'title',
                'description' => __('Configure advanced payment options.', '0xprocessing-for-woocommerce'),
            ),
            'test_mode' => array(
                'title'       => __('Test Mode', '0xprocessing-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Enable Test Mode', '0xprocessing-for-woocommerce'),
                'description' => __('Test payments are not real. You must be logged into your 0xProcessing merchant account.', '0xprocessing-for-woocommerce'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'order_status' => array(
                'title'       => __('Successful Order Status', '0xprocessing-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Order status after a successful crypto payment.', '0xprocessing-for-woocommerce'),
                'default'     => 'processing',
                'options'     => array(
                    'processing' => __('Processing', '0xprocessing-for-woocommerce'),
                    'completed'  => __('Completed', '0xprocessing-for-woocommerce'),
                ),
                'desc_tip'    => true,
            ),

            // --- Theme Customization ---
            'theme_settings' => array(
                'title'       => __('Theme Customization', '0xprocessing-for-woocommerce'),
                'type'        => 'title',
                'description' => __(
                    'Customize the look and feel of the payment form on checkout. Select a preset or pick custom colors. CSS overrides (via Appearance → Customize → Additional CSS) will always take precedence over these settings.',
                    '0xprocessing-for-woocommerce'
                ),
            ),
            'theme_preset' => array(
                'title'       => __('Theme Preset', '0xprocessing-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Quick-apply a color scheme. "Custom" lets you pick individual colors below.', '0xprocessing-for-woocommerce'),
                'default'     => 'light',
                'options'     => array(
                    'light'  => __('Default (Light)', '0xprocessing-for-woocommerce'),
                    'dark'   => __('Dark', '0xprocessing-for-woocommerce'),
                    'custom' => __('Custom', '0xprocessing-for-woocommerce'),
                ),
                'desc_tip'    => true,
            ),
            'theme_icon_size' => array(
                'title'       => __('Payment Icon Size', '0xprocessing-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Size of the crypto icon shown next to the payment method name.', '0xprocessing-for-woocommerce'),
                'default'     => 'small',
                'options'     => array(
                    'small'  => __('Small (24px)', '0xprocessing-for-woocommerce'),
                    'medium' => __('Medium (32px)', '0xprocessing-for-woocommerce'),
                    'large'  => __('Large (40px)', '0xprocessing-for-woocommerce'),
                ),
                'desc_tip'    => true,
            ),
            'theme_accent_color' => array(
                'title'       => __('Accent Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Primary accent color for buttons, focus states, and highlights.', '0xprocessing-for-woocommerce'),
                'default'     => '#4a6cf7',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_text_color' => array(
                'title'       => __('Text Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Primary text color.', '0xprocessing-for-woocommerce'),
                'default'     => '#333333',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_text_secondary_color' => array(
                'title'       => __('Secondary Text Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Color for descriptions and secondary labels.', '0xprocessing-for-woocommerce'),
                'default'     => '#666666',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_text_muted_color' => array(
                'title'       => __('Muted Text Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Color for placeholders and hints.', '0xprocessing-for-woocommerce'),
                'default'     => '#999999',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_bg_color' => array(
                'title'       => __('Background Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Main background for the payment form and dropdowns.', '0xprocessing-for-woocommerce'),
                'default'     => '#ffffff',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_bg_alt_color' => array(
                'title'       => __('Alternate Background', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Background for the currency selector container.', '0xprocessing-for-woocommerce'),
                'default'     => '#f8f9fa',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_input_bg_color' => array(
                'title'       => __('Input Background', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Background for the currency dropdown and search field.', '0xprocessing-for-woocommerce'),
                'default'     => '#ffffff',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_border_color' => array(
                'title'       => __('Border Color', '0xprocessing-for-woocommerce'),
                'type'        => 'color',
                'description' => __('Border color for inputs and containers.', '0xprocessing-for-woocommerce'),
                'default'     => '#e0e0e0',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
            'theme_border_radius' => array(
                'title'       => __('Border Radius', '0xprocessing-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Corner rounding (e.g. 8px, 12px, 0). Default: 8px.', '0xprocessing-for-woocommerce'),
                'default'     => '8px',
                'desc_tip'    => true,
                'css'         => 'width: 80px;',
            ),
        );
    }

    /**
     * Custom admin page with webhook URL reminder
     */
    public function admin_options() {
        echo '<h2>' . esc_html($this->method_title) . '</h2>';
        echo '<p>' . esc_html($this->method_description) . '</p>';

        $webhook_url = rest_url('oxprocessing/v1/webhook');
        echo '<div class="notice notice-info" style="margin:15px 0;padding:12px;">';
        echo '<p><strong>' . esc_html__('Webhook Configuration Required', '0xprocessing-for-woocommerce') . '</strong></p>';
        echo '<p>' . esc_html__('Set this Webhook URL in your 0xProcessing dashboard (Merchant → API → Webhook URL):', '0xprocessing-for-woocommerce') . '</p>';
        echo '<code style="background:#f0f0f0;padding:5px 10px;display:inline-block;margin:5px 0;">'
            . esc_html($webhook_url)
            . '</code>';
        echo '</div>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    // ------------------------------------------------------------------
    //  Checkout — payment fields
    // ------------------------------------------------------------------

    /**
     * Render payment fields on checkout (currency selector, test-mode badge,
     * and webhook URL reminder for admins).
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop($this->description));
        }

        // Currency selector
        $currencies = $this->get_available_currencies();

        if (!empty($currencies)) {
            echo '<div class="oxprocessing-currency-selector">';
            echo '<label for="oxprocessing_currency">' . esc_html__('Select Cryptocurrency:', '0xprocessing-for-woocommerce') . '</label>';
            echo '<select name="oxprocessing_currency" id="oxprocessing_currency" class="select" style="width:100%">';
            echo '<option value="">' . esc_html__('-- Select Currency --', '0xprocessing-for-woocommerce') . '</option>';

            foreach ($currencies as $currency) {
                $name = is_array($currency) ? ($currency['currency'] ?? '') : $currency;
                if (empty($name)) {
                    continue;
                }
                echo '<option value="' . esc_attr($name) . '">' . esc_html($name) . '</option>';
            }

            echo '</select>';
            echo '<p class="description">' . esc_html__('Choose your preferred cryptocurrency.', '0xprocessing-for-woocommerce') . '</p>';
            echo '</div>';
        }

        // Test mode banner
        if ($this->test_mode) {
            echo '<div class="oxprocessing-test-mode-notice">';
            echo '<strong>' . esc_html__('TEST MODE ENABLED', '0xprocessing-for-woocommerce') . '</strong><br>';
            echo esc_html__('No real funds will be processed.', '0xprocessing-for-woocommerce');
            echo '</div>';
        }

        // Webhook URL hint for site admins
        if (current_user_can('manage_options')) {
            $wh = rest_url('oxprocessing/v1/webhook');
            echo '<div class="oxprocessing-webhook-info">';
            echo '<strong>' . esc_html__('Admin — Webhook URL:', '0xprocessing-for-woocommerce') . '</strong><br>';
            echo '<code>' . esc_html($wh) . '</code>';
            echo '</div>';
        }
    }

    /**
     * Validate checkout fields (runs server-side before process_payment).
     *
     * @return bool
     */
    public function validate_fields() {
        // Nonce is verified by WooCommerce core in WC_Checkout::process_checkout().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $currency = isset($_POST['oxprocessing_currency'])
            ? sanitize_text_field(wp_unslash($_POST['oxprocessing_currency'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            : '';

        if (empty($currency)) {
            wc_add_notice(__('Please select a cryptocurrency for payment.', '0xprocessing-for-woocommerce'), 'error');
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    //  Process payment
    // ------------------------------------------------------------------

    /**
     * Create the 0xProcessing payment and redirect to their hosted form.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array WC payment result array.
     */
    public function process_payment($order_id) {
        $order    = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found. Please try again.', '0xprocessing-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }
        // Nonce is verified by WooCommerce core in WC_Checkout::process_checkout().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $currency = isset($_POST['oxprocessing_currency'])
            ? sanitize_text_field(wp_unslash($_POST['oxprocessing_currency'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : '';

        if (empty($currency)) {
            wc_add_notice(__('Please select a cryptocurrency.', '0xprocessing-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $amount        = $order->get_total();
        $store_currency = $order->get_currency();
        $email         = $order->get_billing_email();
        $client_id     = $order->get_user_id() ? (string) $order->get_user_id() : $email;

        // Build payment data ------------------------------------------------
        // The 0xProcessing fixed-amount endpoint accepts:
        //   - Amount      → payment amount in cryptocurrency
        //   - AmountUSD   → payment amount in USD equivalent
        // If the store currency is USD we send AmountUSD directly.
        // Otherwise we convert to USD first via their conversion API.
        $payment_data = array(
            'Currency'   => $currency,
            'Email'      => $email,
            'FirstName'  => $order->get_billing_first_name(),
            'LastName'   => $order->get_billing_last_name(),
            'ClientId'   => $client_id,
            'BillingID'  => (string) $order_id,
            'SuccessUrl' => $this->get_return_url($order),
            'CancelUrl'  => $order->get_cancel_order_url(),
            'AutoReturn' => 'true',
        );

        if (strtoupper($store_currency) === 'USD') {
            $payment_data['AmountUSD'] = $amount;
        } else {
            // Convert store fiat to USD via 0xProcessing conversion API
            $usd_amount = $this->api->convert_fiat($amount, $store_currency, 'USD');
            if ($usd_amount === false) {
                // Fallback: send as AmountUSD and log a warning —
                // 0xProcessing may still handle it acceptably.
                $this->api->log('warning', sprintf(
                    'Could not convert %s %s to USD for order #%d — sending raw amount as AmountUSD',
                    $amount,
                    $store_currency,
                    $order_id
                ));
                $payment_data['AmountUSD'] = $amount;
            } else {
                $payment_data['AmountUSD'] = round($usd_amount, 2);
            }
        }

        // API call -----------------------------------------------------------
        $response = $this->api->create_payment($payment_data);

        if (is_wp_error($response)) {
            $this->api->log('error', 'Payment creation failed for order #' . $order_id, $response->get_error_message());
            wc_add_notice(
                __('Payment creation failed. Please try again or contact support.', '0xprocessing-for-woocommerce'),
                'error'
            );
            return array('result' => 'failure');
        }

        if (!isset($response['redirectUrl'], $response['id'])) {
            $this->api->log('error', 'Unexpected API response for order #' . $order_id, $response);
            wc_add_notice(__('Unexpected response from payment provider.', '0xprocessing-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        // Persist payment metadata (HPOS-compatible) -------------------------
        $order->update_meta_data('_oxprocessing_payment_id', (int) $response['id']);
        $order->update_meta_data('_oxprocessing_currency', $currency);
        $order->update_meta_data('_oxprocessing_redirect_url', esc_url_raw($response['redirectUrl']));
        $order->save();

        // Save to custom tracking table
        $database = new WC_0xProcessing_Database();
        $database->save_payment(array(
            'order_id'      => $order_id,
            'payment_id'    => (int) $response['id'],
            'currency'      => $currency,
            'amount_fiat'   => $amount,
            'fiat_currency' => $store_currency,
            'status'        => 'pending',
        ));

        // Mark order as pending
        $order->update_status('pending', __('Awaiting cryptocurrency payment via 0xProcessing.', '0xprocessing-for-woocommerce'));

        // Reduce stock and clear cart only for non-renewal orders.
        // Renewal orders already had stock reduced on the original subscription order.
        if (!$this->is_subscription_renewal($order)) {
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();
        }

        return array(
            'result'   => 'success',
            'redirect' => $response['redirectUrl'],
        );
    }

    // ------------------------------------------------------------------
    //  Receipt page (fallback redirect)
    // ------------------------------------------------------------------

    /**
     * If the customer lands on the receipt page instead of being redirected,
     * show a link and auto-redirect via JS.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function receipt_page($order_id) {
        $order        = wc_get_order($order_id);
        $redirect_url = $order->get_meta('_oxprocessing_redirect_url');

        if ($redirect_url) {
            echo '<p>' . esc_html__('Redirecting you to the payment page…', '0xprocessing-for-woocommerce') . '</p>';
            echo '<p><a href="' . esc_url($redirect_url) . '">'
                . esc_html__('Click here if not redirected automatically', '0xprocessing-for-woocommerce')
                . '</a></p>';
            echo '<script>window.location.href=' . wp_json_encode(esc_url($redirect_url)) . ';</script>';
        }
    }

    /**
     * Display payment status banner on order details / thank you page
     *
     * @param WC_Order $order
     */
    public function display_payment_status($order) {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $status = $order->get_status();
        $payment_id = $order->get_meta('_oxprocessing_payment_id');

        echo '<section class="woocommerce-order-payment-status">';
        echo '<h2 class="woocommerce-order-payment-status__title">' . esc_html__('Payment Status', '0xprocessing-for-woocommerce') . '</h2>';

        if ($status === 'pending') {
            echo '<div class="woocommerce-message woocommerce-message--info oxprocessing-status-pending">';
            echo '<p><strong>' . esc_html__('⏳ Waiting for Payment Confirmation', '0xprocessing-for-woocommerce') . '</strong></p>';
            echo '<p>' . esc_html__('Your cryptocurrency payment is being processed. Once confirmed on the blockchain, your order will be automatically updated.', '0xprocessing-for-woocommerce') . '</p>';
            if ($payment_id) {
                /* translators: %s: payment ID from 0xProcessing */
                echo '<p class="oxprocessing-payment-id"><small>' . sprintf(esc_html__('Payment ID: %s', '0xprocessing-for-woocommerce'), esc_html($payment_id)) . '</small></p>';
            }
            echo '</div>';
        } elseif (in_array($status, array('processing', 'completed'), true)) {
            echo '<div class="woocommerce-message woocommerce-message--success oxprocessing-status-completed">';
            echo '<p><strong>' . esc_html__('✓ Payment Confirmed!', '0xprocessing-for-woocommerce') . '</strong></p>';
            echo '<p>' . esc_html__('Your cryptocurrency payment has been confirmed and your order is being processed.', '0xprocessing-for-woocommerce') . '</p>';
            echo '</div>';
        } elseif ($status === 'failed') {
            echo '<div class="woocommerce-error oxprocessing-status-failed">';
            echo '<p><strong>' . esc_html__('✗ Payment Failed', '0xprocessing-for-woocommerce') . '</strong></p>';
            echo '<p>' . esc_html__('Your payment could not be processed. Please try again or contact support.', '0xprocessing-for-woocommerce') . '</p>';
            echo '</div>';
        } elseif ($status === 'cancelled') {
            echo '<div class="woocommerce-info oxprocessing-status-cancelled">';
            echo '<p><strong>' . esc_html__('Order Cancelled', '0xprocessing-for-woocommerce') . '</strong></p>';
            echo '<p>' . esc_html__('This order has been cancelled.', '0xprocessing-for-woocommerce') . '</p>';
            echo '</div>';
        }

        echo '</section>';
    }

    /**
     * Display payment status on thank you page (alternative hook)
     *
     * @param int $order_id
     */
    public function display_payment_status_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $this->display_payment_status($order);
        }
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Fetch active currencies (cached for 1 hour).
     * Only currencies with Active === true are returned.
     *
     * @return array Simple list of currency name strings.
     */
    private function get_available_currencies() {
        $cached = get_transient('oxprocessing_currencies');

        if (false === $cached) {
            $coins = $this->api->get_coins();
            if (!$coins || !is_array($coins)) {
                // Fallback when API is unreachable
                return array('BTC', 'ETH', 'LTC', 'USDT (ERC20)', 'USDT (TRC20)', 'USDC (ERC20)');
            }

            // Filter to only active currencies
            $active = array();
            foreach ($coins as $coin) {
                if (is_array($coin)) {
                    if (!empty($coin['active']) || !isset($coin['active'])) {
                        $active[] = $coin;
                    }
                } else {
                    $active[] = $coin;
                }
            }

            // Sort: popular coins first, then alphabetically
            $active = self::sort_currencies($active);

            set_transient('oxprocessing_currencies', $active, HOUR_IN_SECONDS);
            return $active;
        }

        return $cached;
    }

    /**
     * Sort currencies so popular coins appear first.
     *
     * @param array $currencies List of currency strings or arrays.
     * @return array Sorted list.
     */
    private static function sort_currencies($currencies) {
        // Priority tokens (case-insensitive prefix match)
        $priority_order = array(
            'USDT',
            'BTC',
            'ETH',
            'USDC',
            'LTC',
            'BNB',
            'SOL',
            'TRX',
            'DOGE',
            'XRP',
            'MATIC',
            'DAI',
            'WBTC',
            'WETH',
        );

        usort($currencies, function ($a, $b) use ($priority_order) {
            $name_a = is_array($a) ? strtoupper($a['currency'] ?? '') : strtoupper($a);
            $name_b = is_array($b) ? strtoupper($b['currency'] ?? '') : strtoupper($b);

            $prio_a = PHP_INT_MAX;
            $prio_b = PHP_INT_MAX;

            foreach ($priority_order as $idx => $token) {
                if ($prio_a === PHP_INT_MAX && strpos($name_a, $token) === 0) {
                    $prio_a = $idx;
                }
                if ($prio_b === PHP_INT_MAX && strpos($name_b, $token) === 0) {
                    $prio_b = $idx;
                }
            }

            if ($prio_a !== $prio_b) {
                return $prio_a - $prio_b;
            }

            return strcmp($name_a, $name_b);
        });

        return $currencies;
    }

    // ------------------------------------------------------------------
    //  WooCommerce Subscriptions — manual renewal
    // ------------------------------------------------------------------

    /**
     * Handle a scheduled subscription renewal payment.
     *
     * Since crypto is push-based (customer must send), we operate in "manual
     * renewal" mode: the renewal order stays pending so WooCommerce
     * Subscriptions sends the customer an invoice with a Pay link.  When the
     * customer pays via the normal checkout flow, the webhook marks the
     * renewal order as paid, which reactivates the subscription automatically.
     *
     * @param float    $renewal_total The renewal amount.
     * @param WC_Order $renewal_order The renewal order.
     */
    public function scheduled_subscription_payment( $renewal_total, $renewal_order ) {
        $renewal_order->add_order_note(
            __( '0xProcessing: Subscription renewal due — awaiting manual crypto payment.', '0xprocessing-for-woocommerce' )
        );

        $this->api->log( 'info', sprintf(
            'Subscription renewal pending for order #%d (total: %s)',
            $renewal_order->get_id(),
            $renewal_total
        ) );

        // The renewal order stays 'pending'. WooCommerce Subscriptions will
        // put the subscription on-hold and email the customer an invoice.
        // When the customer pays (webhook comes back), handle_success() will
        // call payment_complete(), which WC Subscriptions listens for to
        // reactivate the subscription.
    }

    /**
     * Check if an order is a WooCommerce Subscriptions renewal order.
     *
     * @param WC_Order $order The order to check.
     * @return bool
     */
    private function is_subscription_renewal( $order ) {
        return function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
    }
}