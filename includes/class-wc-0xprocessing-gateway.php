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
        $this->method_title       = __('0xProcessing Crypto', 'wc-0xprocessing');
        $this->method_description = __(
            'Accept cryptocurrency payments via 0xProcessing. Supports Bitcoin, Ethereum, USDT, and 50+ other cryptocurrencies.',
            'wc-0xprocessing'
        );
        $this->has_fields = true;
        $this->icon       = WC_OXPROCESSING_PLUGIN_URL . 'assets/img/crypto-icon.svg';

        // We do NOT support refunds through the API — they must be handled
        // in the 0xProcessing dashboard.  Declaring 'refunds' would show a
        // non-functional button in WooCommerce.
        $this->supports = array('products');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Read settings
        $this->title       = $this->get_option('title', __('Cryptocurrency via 0xProcessing', 'wc-0xprocessing'));
        $this->description = $this->get_option('description', __('Pay with Bitcoin, Ethereum, USDT, and other cryptocurrencies.', 'wc-0xprocessing'));
        $this->enabled     = $this->get_option('enabled');
        $this->test_mode   = $this->get_option('test_mode') === 'yes';

        // Initialize API
        $this->api = new WC_0xProcessing_API();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_payment_status'), 10);
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
                'title'   => __('Enable/Disable', 'wc-0xprocessing'),
                'type'    => 'checkbox',
                'label'   => __('Enable 0xProcessing Payment Gateway', 'wc-0xprocessing'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', 'wc-0xprocessing'),
                'type'        => 'text',
                'description' => __('Title the customer sees during checkout.', 'wc-0xprocessing'),
                'default'     => __('Cryptocurrency via 0xProcessing', 'wc-0xprocessing'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-0xprocessing'),
                'type'        => 'textarea',
                'description' => __('Description the customer sees during checkout.', 'wc-0xprocessing'),
                'default'     => __('Pay with Bitcoin, Ethereum, USDT, and other cryptocurrencies.', 'wc-0xprocessing'),
                'desc_tip'    => true,
            ),

            // --- API credentials ---
            'api_settings' => array(
                'title'       => __('API Settings', 'wc-0xprocessing'),
                'type'        => 'title',
                'description' => __(
                    'Enter your 0xProcessing API credentials. You can find them in your merchant dashboard under Merchant → API → General Settings.',
                    'wc-0xprocessing'
                ),
            ),
            'merchant_id' => array(
                'title'    => __('Merchant ID', 'wc-0xprocessing'),
                'type'     => 'text',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Found in Merchant → Settings → Merchant Management.', 'wc-0xprocessing'),
            ),
            'api_key' => array(
                'title'    => __('API Key', 'wc-0xprocessing'),
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Generated in Merchant → API → General Settings. Keep this secure!', 'wc-0xprocessing'),
            ),
            'webhook_password' => array(
                'title'    => __('Webhook Password', 'wc-0xprocessing'),
                'type'     => 'password',
                'default'  => '',
                'desc_tip' => true,
                'description' => __('Must match the password set in Merchant → API → Webhook URL.', 'wc-0xprocessing'),
            ),

            // --- Advanced ---
            'advanced_settings' => array(
                'title'       => __('Advanced Settings', 'wc-0xprocessing'),
                'type'        => 'title',
                'description' => __('Configure advanced payment options.', 'wc-0xprocessing'),
            ),
            'test_mode' => array(
                'title'       => __('Test Mode', 'wc-0xprocessing'),
                'type'        => 'checkbox',
                'label'       => __('Enable Test Mode', 'wc-0xprocessing'),
                'description' => __('Test payments are not real. You must be logged into your 0xProcessing merchant account.', 'wc-0xprocessing'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'order_status' => array(
                'title'       => __('Successful Order Status', 'wc-0xprocessing'),
                'type'        => 'select',
                'description' => __('Order status after a successful crypto payment.', 'wc-0xprocessing'),
                'default'     => 'processing',
                'options'     => array(
                    'processing' => __('Processing', 'wc-0xprocessing'),
                    'completed'  => __('Completed', 'wc-0xprocessing'),
                ),
                'desc_tip'    => true,
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
        echo '<p><strong>' . esc_html__('Webhook Configuration Required', 'wc-0xprocessing') . '</strong></p>';
        echo '<p>' . esc_html__('Set this Webhook URL in your 0xProcessing dashboard (Merchant → API → Webhook URL):', 'wc-0xprocessing') . '</p>';
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
            echo '<label for="oxprocessing_currency">' . esc_html__('Select Cryptocurrency:', 'wc-0xprocessing') . '</label>';
            echo '<select name="oxprocessing_currency" id="oxprocessing_currency" class="select" style="width:100%">';
            echo '<option value="">' . esc_html__('-- Select Currency --', 'wc-0xprocessing') . '</option>';

            foreach ($currencies as $currency) {
                $name = is_array($currency) ? ($currency['currency'] ?? '') : $currency;
                if (empty($name)) {
                    continue;
                }
                echo '<option value="' . esc_attr($name) . '">' . esc_html($name) . '</option>';
            }

            echo '</select>';
            echo '<p class="description">' . esc_html__('Choose your preferred cryptocurrency.', 'wc-0xprocessing') . '</p>';
            echo '</div>';
        }

        // Test mode banner
        if ($this->test_mode) {
            echo '<div class="oxprocessing-test-mode-notice">';
            echo '<strong>' . esc_html__('TEST MODE ENABLED', 'wc-0xprocessing') . '</strong><br>';
            echo esc_html__('No real funds will be processed.', 'wc-0xprocessing');
            echo '</div>';
        }

        // Webhook URL hint for site admins
        if (current_user_can('manage_options')) {
            $wh = rest_url('oxprocessing/v1/webhook');
            echo '<div class="oxprocessing-webhook-info">';
            echo '<strong>' . esc_html__('Admin — Webhook URL:', 'wc-0xprocessing') . '</strong><br>';
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
        $currency = isset($_POST['oxprocessing_currency'])
            ? wc_clean(wp_unslash($_POST['oxprocessing_currency']))
            : '';

        if (empty($currency)) {
            wc_add_notice(__('Please select a cryptocurrency for payment.', 'wc-0xprocessing'), 'error');
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
        $currency = wc_clean(wp_unslash($_POST['oxprocessing_currency'] ?? ''));

        if (empty($currency)) {
            wc_add_notice(__('Please select a cryptocurrency.', 'wc-0xprocessing'), 'error');
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
                __('Payment creation failed. Please try again or contact support.', 'wc-0xprocessing'),
                'error'
            );
            return array('result' => 'failure');
        }

        if (!isset($response['redirectUrl'], $response['id'])) {
            $this->api->log('error', 'Unexpected API response for order #' . $order_id, $response);
            wc_add_notice(__('Unexpected response from payment provider.', 'wc-0xprocessing'), 'error');
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

        // Mark order as pending, reduce stock, clear cart
        $order->update_status('pending', __('Awaiting cryptocurrency payment via 0xProcessing.', 'wc-0xprocessing'));
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

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
            echo '<p>' . esc_html__('Redirecting you to the payment page…', 'wc-0xprocessing') . '</p>';
            echo '<p><a href="' . esc_url($redirect_url) . '">'
                . esc_html__('Click here if not redirected automatically', 'wc-0xprocessing')
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
        echo '<h2 class="woocommerce-order-payment-status__title">' . esc_html__('Payment Status', 'wc-0xprocessing') . '</h2>';

        if ($status === 'pending') {
            echo '<div class="woocommerce-message woocommerce-message--info oxprocessing-status-pending">';
            echo '<p><strong>' . esc_html__('⏳ Waiting for Payment Confirmation', 'wc-0xprocessing') . '</strong></p>';
            echo '<p>' . esc_html__('Your cryptocurrency payment is being processed. Once confirmed on the blockchain, your order will be automatically updated.', 'wc-0xprocessing') . '</p>';
            if ($payment_id) {
                echo '<p class="oxprocessing-payment-id"><small>' . sprintf(esc_html__('Payment ID: %s', 'wc-0xprocessing'), esc_html($payment_id)) . '</small></p>';
            }
            echo '</div>';
        } elseif (in_array($status, array('processing', 'completed'), true)) {
            echo '<div class="woocommerce-message woocommerce-message--success oxprocessing-status-completed">';
            echo '<p><strong>' . esc_html__('✓ Payment Confirmed!', 'wc-0xprocessing') . '</strong></p>';
            echo '<p>' . esc_html__('Your cryptocurrency payment has been confirmed and your order is being processed.', 'wc-0xprocessing') . '</p>';
            echo '</div>';
        } elseif ($status === 'failed') {
            echo '<div class="woocommerce-error oxprocessing-status-failed">';
            echo '<p><strong>' . esc_html__('✗ Payment Failed', 'wc-0xprocessing') . '</strong></p>';
            echo '<p>' . esc_html__('Your payment could not be processed. Please try again or contact support.', 'wc-0xprocessing') . '</p>';
            echo '</div>';
        } elseif ($status === 'cancelled') {
            echo '<div class="woocommerce-info oxprocessing-status-cancelled">';
            echo '<p><strong>' . esc_html__('Order Cancelled', 'wc-0xprocessing') . '</strong></p>';
            echo '<p>' . esc_html__('This order has been cancelled.', 'wc-0xprocessing') . '</p>';
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

            set_transient('oxprocessing_currencies', $active, HOUR_IN_SECONDS);
            return $active;
        }

        return $cached;
    }
}