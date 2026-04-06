<?php
/**
 * Cyphercodes Crypto Gateway — API Client
 *
 * Handles all HTTP communication with the 0xProcessing REST API.
 *
 * @package CCGW
 */

if (!defined('ABSPATH')) {
    exit;
}

class CCGW_API {

    /** @var string */
    private $api_url;

    /** @var string */
    private $api_key;

    /** @var string */
    private $merchant_id;

    /** @var bool */
    private $test_mode;

    /** @var WC_Logger|null */
    private $logger;

    /**
     * Constructor — loads settings defensively so it never crashes when options
     * have not been saved yet.
     */
    public function __construct() {
        $this->api_url = CCGW_API_URL;

        $settings = get_option('woocommerce_ccgw_settings');
        if (!is_array($settings)) {
            $settings = array();
        }

        $this->api_key     = $settings['api_key']     ?? '';
        $this->merchant_id = $settings['merchant_id']  ?? '';
        $this->test_mode   = ($settings['test_mode']   ?? 'no') === 'yes';

        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Standard headers for form-encoded requests (payment creation).
     *
     * @return array
     */
    private function get_form_headers() {
        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        );
        if (!empty($this->api_key)) {
            $headers['APIKEY'] = $this->api_key;
        }
        return $headers;
    }

    /**
     * Headers for JSON-body requests (wallet creation etc.).
     *
     * @return array
     */
    private function get_json_headers() {
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );
        if (!empty($this->api_key)) {
            $headers['APIKEY'] = $this->api_key;
        }
        return $headers;
    }

    /**
     * Headers for GET requests (coins, conversion, etc.).
     *
     * @return array
     */
    private function get_get_headers() {
        $headers = array(
            'Accept' => 'application/json',
        );
        if (!empty($this->api_key)) {
            $headers['APIKEY'] = $this->api_key;
        }
        return $headers;
    }

    /**
     * Decode a JSON response body, returning WP_Error on failure.
     *
     * @param WP_Error|array $response wp_remote_* response.
     * @param string         $context  Human-readable context for error messages.
     * @return array|WP_Error
     */
    private function decode_response($response, $context = 'API call') {
        if (is_wp_error($response)) {
            $this->log('error', $context . ' HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);

        if ($http_code < 200 || $http_code >= 300) {
            $this->log('error', sprintf('%s returned HTTP %d: %s', $context, $http_code, $body));
            return new WP_Error(
                'http_error',
                /* translators: %d: HTTP status code */
                sprintf(__('0xProcessing returned HTTP %d', 'cyphercodes-crypto-gateway'), $http_code)
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', $context . ' JSON decode error: ' . json_last_error_msg());
            return new WP_Error('json_error', __('Failed to parse API response', 'cyphercodes-crypto-gateway'));
        }

        return $data;
    }

    // ------------------------------------------------------------------
    //  Payment endpoints
    // ------------------------------------------------------------------

    /**
     * Create a payment with fixed amount.
     *
     * Content Type: x-www-form-urlencoded, form-data
     * Endpoint:     POST https://app.0xprocessing.com/Payment
     *
     * @param array $params Payment parameters.
     * @return array|WP_Error Parsed response or error.
     */
    public function create_payment($params) {
        $params = wp_parse_args($params, array(
            'MerchantId' => $this->merchant_id,
            'Test'       => $this->test_mode ? 'true' : 'false',
            'ReturnUrl'  => 'true',
        ));

        // Log payment params (redact sensitive data)
        $log_params = $params;
        unset($log_params['Email']);
        $this->log('info', 'Creating payment', $log_params);

        $response = wp_remote_post($this->api_url . '/Payment', array(
            'headers' => $this->get_form_headers(),
            'body'    => $params,
            'timeout' => 30,
        ));

        return $this->decode_response($response, 'create_payment');
    }

    /**
     * Create a payment without fixed amount.
     *
     * Content Type: x-www-form-urlencoded, form-data
     * Endpoint:     POST https://app.0xprocessing.com/Payment
     *
     * @param array $params Payment parameters (no Amount / AmountUSD).
     * @return array|WP_Error
     */
    public function create_payment_variable($params) {
        $params = wp_parse_args($params, array(
            'MerchantId' => $this->merchant_id,
            'Test'       => $this->test_mode ? 'true' : 'false',
        ));

        $this->log('info', 'Creating variable payment', $params);

        $response = wp_remote_post($this->api_url . '/Payment', array(
            'headers' => $this->get_form_headers(),
            'body'    => $params,
            'timeout' => 30,
        ));

        return $this->decode_response($response, 'create_payment_variable');
    }

    // ------------------------------------------------------------------
    //  Informational endpoints
    // ------------------------------------------------------------------

    /**
     * Get all supported coins with metadata.
     *
     * Endpoint: GET https://app.0xprocessing.com/Api/Coins
     *
     * @return array|false Array of coin objects or false on error.
     */
    public function get_coins() {
        $response = wp_remote_get($this->api_url . '/Api/Coins', array(
            'headers' => $this->get_get_headers(),
            'timeout' => 30,
        ));

        $data = $this->decode_response($response, 'get_coins');
        return is_wp_error($data) ? false : $data;
    }

    /**
     * Get info for a specific coin.
     *
     * Endpoint: GET https://app.0xprocessing.com/Api/CoinInfo/{id}
     *
     * @param string $currency Currency code (e.g. "BTC", "USDT (ERC20)").
     * @return array|false
     */
    public function get_coin_info($currency) {
        $response = wp_remote_get(
            $this->api_url . '/Api/CoinInfo/' . rawurlencode($currency),
            array(
                'headers' => $this->get_get_headers(),
                'timeout' => 30,
            )
        );

        $data = $this->decode_response($response, 'get_coin_info');
        return is_wp_error($data) ? false : $data;
    }

    // ------------------------------------------------------------------
    //  Conversion endpoints
    // ------------------------------------------------------------------

    /**
     * Convert fiat to crypto.
     *
     * Endpoint: GET https://app.0xprocessing.com/Api/ConvertToCrypto
     *
     * @param float  $amount          Amount in fiat.
     * @param string $fiat_currency   Fiat code (e.g. "USD").
     * @param string $crypto_currency Crypto code (e.g. "BTC").
     * @return float|false
     */
    public function convert_to_crypto($amount, $fiat_currency, $crypto_currency) {
        $url = add_query_arg(array(
            'InCurrency'  => $fiat_currency,
            'OutCurrency' => $crypto_currency,
            'InAmount'    => $amount,
        ), $this->api_url . '/Api/ConvertToCrypto');

        $response = wp_remote_get($url, array(
            'headers' => $this->get_get_headers(),
            'timeout' => 30,
        ));

        $data = $this->decode_response($response, 'convert_to_crypto');
        if (is_wp_error($data) || !isset($data['result'])) {
            return false;
        }
        return (float) $data['result'];
    }

    /**
     * Convert crypto to fiat.
     *
     * Endpoint: GET https://app.0xprocessing.com/Api/ConvertCryptoToFiat
     *
     * @param float  $amount          Crypto amount.
     * @param string $crypto_currency Crypto code.
     * @param string $fiat_currency   Fiat code.
     * @return float|false
     */
    public function convert_to_fiat($amount, $crypto_currency, $fiat_currency) {
        $url = add_query_arg(array(
            'InCurrency'  => $crypto_currency,
            'OutCurrency' => $fiat_currency,
            'InAmount'    => $amount,
        ), $this->api_url . '/Api/ConvertCryptoToFiat');

        $response = wp_remote_get($url, array(
            'headers' => $this->get_get_headers(),
            'timeout' => 30,
        ));

        $data = $this->decode_response($response, 'convert_to_fiat');
        if (is_wp_error($data) || !isset($data['result'])) {
            return false;
        }
        return (float) $data['result'];
    }

    /**
     * Convert between fiat currencies (useful for non-USD stores).
     *
     * Endpoint: GET https://app.0xprocessing.com/Api/Convert
     *
     * @param float  $amount       Source amount.
     * @param string $from_fiat    Source fiat code.
     * @param string $to_fiat      Target fiat code.
     * @return float|false
     */
    public function convert_fiat($amount, $from_fiat, $to_fiat) {
        $url = add_query_arg(array(
            'InCurrency'  => $from_fiat,
            'OutCurrency' => $to_fiat,
            'InAmount'    => $amount,
        ), $this->api_url . '/Api/Convert');

        $response = wp_remote_get($url, array(
            'headers' => $this->get_get_headers(),
            'timeout' => 30,
        ));

        $data = $this->decode_response($response, 'convert_fiat');
        if (is_wp_error($data) || !isset($data['result'])) {
            return false;
        }
        return (float) $data['result'];
    }

    // ------------------------------------------------------------------
    //  Static wallet
    // ------------------------------------------------------------------

    /**
     * Create a static wallet for a user.
     *
     * Content Type: application/json
     * Endpoint:     POST https://app.0xprocessing.com/Api/CreateClientWallet
     *
     * @param string $client_id User ID on your platform.
     * @param string $currency  Currency code.
     * @return array|WP_Error
     */
    public function create_static_wallet($client_id, $currency) {
        $response = wp_remote_post($this->api_url . '/Api/CreateClientWallet', array(
            'headers' => $this->get_json_headers(),
            'body'    => wp_json_encode(array(
                'ClientId' => $client_id,
                'Currency' => $currency,
            )),
            'timeout' => 30,
        ));

        return $this->decode_response($response, 'create_static_wallet');
    }

    // ------------------------------------------------------------------
    //  Signature verification
    // ------------------------------------------------------------------

    /**
     * Verify a deposit webhook signature.
     *
     * Signature string format:
     *   PaymentId:MerchantId:Email:Currency:WebhookPassword
     *
     * @param array  $data     Webhook payload.
     * @param string $password Webhook password from settings.
     * @return bool
     */
    public static function verify_deposit_signature($data, $password) {
        $required = array('PaymentId', 'MerchantId', 'Email', 'Currency', 'Signature');
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                return false;
            }
        }

        $raw = sprintf(
            '%s:%s:%s:%s:%s',
            $data['PaymentId'],
            $data['MerchantId'],
            $data['Email'],
            $data['Currency'],
            $password
        );

        return hash_equals(md5($raw), strtolower($data['Signature']));
    }

    /**
     * Verify a withdrawal webhook signature.
     *
     * Signature string format:
     *   ID:MerchantID:Address:Currency:WebhookPassword
     *
     * @param array  $data     Webhook payload.
     * @param string $password Webhook password from settings.
     * @return bool
     */
    public static function verify_withdrawal_signature($data, $password) {
        $required = array('ID', 'MerchantID', 'Address', 'Currency', 'Signature');
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                return false;
            }
        }

        $raw = sprintf(
            '%s:%s:%s:%s:%s',
            $data['ID'],
            $data['MerchantID'],
            $data['Address'],
            $data['Currency'],
            $password
        );

        return hash_equals(md5($raw), strtolower($data['Signature']));
    }

    // ------------------------------------------------------------------
    //  Logging
    // ------------------------------------------------------------------

    /**
     * Log a message through WooCommerce logger (and optionally PHP error_log).
     *
     * @param string $level   WC log level: debug|info|notice|warning|error|critical.
     * @param string $message Human-readable message.
     * @param mixed  $data    Optional data to include.
     */
    public function log($level, $message, $data = null) {
        $full = '[CCGW] ' . $message;
        if ($data !== null) {
            $full .= ' | ' . wp_json_encode($data);
        }

        if ($this->logger) {
            $this->logger->log($level, $full, array('source' => 'ccgw'));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($full);
        }
    }
}
