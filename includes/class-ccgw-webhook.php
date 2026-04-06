<?php
/**
 * Cyphercodes Crypto Gateway — Webhook Handler
 *
 * Processes incoming payment status webhooks from 0xProcessing.
 * Per 0xProcessing docs: MUST respond with HTTP 200 within 3 seconds or
 * they will retry 31 times at 15-second intervals.
 *
 * @package CCGW
 */

if (!defined('ABSPATH')) {
    exit;
}

class CCGW_Webhook {

    /**
     * Handle webhook POST (called by REST route ccgw/v1/webhook).
     *
     * CRITICAL: Per 0xProcessing docs, we MUST return HTTP 200 to acknowledge
     * receipt — even if validation fails or orders are missing. Otherwise they
     * will retry 31 times. We log failures but still return 200.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response
     */
    public static function handle_webhook($request) {
        // 0xProcessing times out after 3 seconds. If they disconnect, PHP shouldn't abort, 
        // otherwise payment_complete() will leave the order halfway completed.
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $body = $request->get_body();
        $data = json_decode($body, true);

        self::log('info', 'Webhook received', $data);

        // Basic validation — ensure required fields exist
        if (empty($data) || !isset($data['PaymentId'], $data['Status'], $data['Signature'])) {
            self::log('warning', 'Webhook missing required fields (PaymentId, Status, Signature)', $data);
            // Still return 200 per docs!
            return new WP_REST_Response(array('status' => 'ok', 'error' => 'missing_fields'), 200);
        }

        // Signature verification
        $settings = get_option('woocommerce_ccgw_settings');
        if (!is_array($settings)) {
            $settings = array();
        }
        $webhook_password = $settings['webhook_password'] ?? '';

        if (!self::verify_signature($data, $webhook_password)) {
            self::log('error', 'Webhook signature verification FAILED', $data);
            // Still return 200 per docs to avoid repeated retries
            return new WP_REST_Response(array('status' => 'ok', 'error' => 'invalid_signature'), 200);
        }

        // Handle test webhooks
        if (isset($data['Test']) && filter_var($data['Test'], FILTER_VALIDATE_BOOLEAN)) {
            self::log('info', 'Test webhook received — not updating real orders', $data);
            // Important: return early so test webhooks never affect real orders
            return new WP_REST_Response(array('status' => 'ok', 'test' => true), 200);
        }

        // Locate the order -------------------------------------------------------
        // Race condition: webhook can arrive before process_payment() saves to DB.
        // Fallback 1: check BillingID (= order_id)
        // Fallback 2: check order meta for payment_id
        $database = new CCGW_Database();
        $payment_record = $database->get_payment_by_payment_id($data['PaymentId']);

        $order_id = null;

        if ($payment_record) {
            $order_id = $payment_record->order_id;
        } elseif (!empty($data['BillingID'])) {
            // Fallback via BillingID
            $order_id = absint($data['BillingID']);
            self::log('info', 'Payment not in DB yet — using BillingID fallback', array('order_id' => $order_id));
        } else {
            // Last resort: search all orders with matching payment_id
            $orders = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_ccgw_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for payment lookup.
                'meta_value' => $data['PaymentId'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for payment lookup.
            ));
            if ($orders && count($orders) > 0) {
                $order_id = $orders[0]->get_id();
                self::log('info', 'Payment found via meta search', array('order_id' => $order_id));
            }
        }

        if (!$order_id) {
            self::log('warning', 'Cannot locate order for PaymentId', $data);
            return new WP_REST_Response(array('status' => 'ok', 'error' => 'order_not_found'), 200);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            self::log('warning', 'Order ID invalid or deleted', array('order_id' => $order_id));
            return new WP_REST_Response(array('status' => 'ok', 'error' => 'order_invalid'), 200);
        }

        // Process payment status -------------------------------------------------
        $status         = $data['Status'];
        $is_insufficient = isset($data['Insufficient']) && filter_var($data['Insufficient'], FILTER_VALIDATE_BOOLEAN);

        switch ($status) {
            case 'Success':
                self::handle_success($order, $data, $is_insufficient);
                break;
            case 'Canceled':
                self::handle_canceled($order, $data);
                break;
            case 'Insufficient':
                self::handle_insufficient($order, $data);
                break;
            default:
                self::log('warning', 'Unknown payment status', $data);
                break;
        }

        // Update DB record if one exists
        if ($payment_record) {
            $database->update_payment_status($data['PaymentId'], array(
                'status'        => strtolower($status),
                'amount_crypto' => $data['Amount'] ?? 0,
                'amount_usd'    => $data['AmountUSD'] ?? 0,
                'tx_hashes'     => isset($data['TxHashes']) ? wp_json_encode($data['TxHashes']) : null,
                'is_insufficient' => $is_insufficient ? 1 : 0,
                'updated_at'    => current_time('mysql'),
            ));
        }

        // Always return 200 per 0xProcessing specs
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }

    // ------------------------------------------------------------------------
    //  Payment status handlers
    // ------------------------------------------------------------------------

    /**
     * Handle successful payment
     *
     * @param WC_Order $order          The WooCommerce order.
     * @param array    $data           Webhook data.
     * @param bool     $is_insufficient True if this is an underpayment that was
     *                                 manually approved.
     */
    private static function handle_success($order, $data, $is_insufficient) {
        $settings     = get_option('woocommerce_ccgw_settings');
        $order_status = ($settings['order_status'] ?? 'processing');
        $current_status = $order->get_status();

        // Check if already processed to avoid duplicate updates.
        if ($current_status === $order_status || $current_status === 'completed') {
            self::log('info', 'Order already reached target status — skipping duplicate', array('order_id' => $order->get_id(), 'status' => $current_status));
            return;
        }

        // If the order is currently 'processing' but our target is 'completed', 
        // it means a previous webhook crashed half-way or payment_complete() left it in processing.
        if ($current_status === 'processing' && $order_status === 'completed') {
            $order->update_status('completed', __('0xProcessing Webhook: Upgraded status from processing to completed via retry.', 'cyphercodes-crypto-gateway'));
            self::log('info', 'Order status upgraded on webhook retry', array('order_id' => $order->get_id()));
            return;
        }

        // Build note
        $amount     = $data['Amount'] ?? '0';
        $currency   = $data['Currency'] ?? 'N/A';
        $amount_usd = isset($data['AmountUSD']) ? number_format((float) $data['AmountUSD'], 2) : 'N/A';
        $tx_hash    = isset($data['TxHashes'][0]) ? $data['TxHashes'][0] : 'N/A';

        if ($is_insufficient) {
            $note = sprintf(
                /* translators: %1$s: crypto amount, %2$s: currency name, %3$s: USD value, %4$s: transaction hash */
                __('0xProcessing payment confirmed (UNDERPAID). Received: %1$s %2$s (~$%3$s USD). Transaction: %4$s', 'cyphercodes-crypto-gateway'),
                $amount,
                $currency,
                $amount_usd,
                $tx_hash
            );
        } else {
            $note = sprintf(
                /* translators: %1$s: crypto amount, %2$s: currency name, %3$s: USD value, %4$s: transaction hash */
                __('0xProcessing payment successful. Received: %1$s %2$s (~$%3$s USD). Transaction: %4$s', 'cyphercodes-crypto-gateway'),
                $amount,
                $currency,
                $amount_usd,
                $tx_hash
            );
        }

        $order->add_order_note($note);

        // Persist payment metadata (HPOS-compatible)
        $order->update_meta_data('_ccgw_payment_status', 'success');
        $order->update_meta_data('_ccgw_amount_paid', $amount);
        $order->update_meta_data('_ccgw_amount_usd', $data['AmountUSD'] ?? 0);
        $order->update_meta_data('_ccgw_tx_hash', $tx_hash !== 'N/A' ? $tx_hash : '');
        $order->save();

        // Complete the payment. For subscription renewals, payment_complete() is
        // the canonical method WooCommerce Subscriptions listens for.
        $transaction_id = $tx_hash !== 'N/A' ? $tx_hash : '';
        $order->payment_complete( $transaction_id );

        // If the merchant chose 'completed' as the target status and payment_complete()
        // set it to 'processing', upgrade it now.
        if ( $order_status === 'completed' && $order->get_status() !== 'completed' ) {
            $order->update_status( 'completed', '' );
        }

        self::log('info', 'Payment success processed', array(
            'order_id'       => $order->get_id(),
            'new_status'     => $order_status,
            'is_insufficient' => $is_insufficient,
        ));
    }

    /**
     * Handle canceled payment (payment window expired)
     *
     * @param WC_Order $order The WooCommerce order.
     * @param array    $data  Webhook data.
     */
    private static function handle_canceled($order, $data) {
        $note = sprintf(
            /* translators: %s: cryptocurrency name */
            __('0xProcessing payment CANCELED. Payment window expired without funds. Currency: %s', 'cyphercodes-crypto-gateway'),
            $data['Currency'] ?? 'N/A'
        );

        $order->add_order_note($note);
        $order->update_meta_data('_ccgw_payment_status', 'canceled');
        $order->save();

        // Restore stock — but not for subscription renewals (stock belongs to the parent order)
        $is_renewal = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
        if ( ! $is_renewal && function_exists( 'wc_increase_stock_levels' ) ) {
            wc_increase_stock_levels( $order->get_id() );
        }

        // Cancel order if still pending
        if ($order->get_status() === 'pending') {
            $order->update_status('cancelled', __('Crypto payment window expired.', 'cyphercodes-crypto-gateway'));
        }

        self::log('info', 'Payment canceled', array('order_id' => $order->get_id()));
    }

    /**
     * Handle insufficient payment (underpaid — awaiting manual confirmation)
     *
     * @param WC_Order $order The WooCommerce order.
     * @param array    $data  Webhook data.
     */
    private static function handle_insufficient($order, $data) {
        $note = sprintf(
            /* translators: %1$s: crypto amount, %2$s: currency name, %3$s: USD value */
            __('0xProcessing payment INSUFFICIENT. Received: %1$s %2$s (~$%3$s USD). Awaiting merchant confirmation.', 'cyphercodes-crypto-gateway'),
            $data['Amount'] ?? '0',
            $data['Currency'] ?? 'N/A',
            isset($data['AmountUSD']) ? number_format((float) $data['AmountUSD'], 2) : 'N/A'
        );

        $order->add_order_note($note);
        $order->update_meta_data('_ccgw_payment_status', 'insufficient');
        $order->update_meta_data('_ccgw_amount_received', $data['Amount'] ?? '0');
        $order->save();
        $order->update_status('on-hold', __('Awaiting confirmation of underpayment.', 'cyphercodes-crypto-gateway'));

        // Notify admin
        $admin_email = get_option('admin_email');
        /* translators: %1$s: site name, %2$s: order number */
        $subject     = sprintf(__('[%1$s] Insufficient payment for order #%2$s', 'cyphercodes-crypto-gateway'), get_bloginfo('name'), $order->get_order_number());
        wp_mail($admin_email, $subject, $note);

        self::log('info', 'Insufficient payment', array('order_id' => $order->get_id()));
    }

    // ------------------------------------------------------------------------
    //  Signature verification
    // ------------------------------------------------------------------------

    /**
     * Verify webhook signature (deposit webhooks).
     *
     * Signature format: PaymentId:MerchantId:Email:Currency:Password
     *
     * @param array  $data     Webhook data.
     * @param string $password Webhook password from settings.
     * @return bool
     */
    private static function verify_signature($data, $password) {
        // If no password set, skip verification (insecure but we'll log a warning)
        if (empty($password)) {
            self::log('warning', 'No webhook password set — skipping signature verification (insecure!)');
            return true;
        }

        $required = array('PaymentId', 'MerchantId', 'Email', 'Currency', 'Signature');
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                self::log('warning', "Signature verification failed: missing $key");
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

        $expected = md5($raw);
        $received = strtolower($data['Signature']);

        return hash_equals(strtolower($expected), $received);
    }

    // ------------------------------------------------------------------------
    //  Logging
    // ------------------------------------------------------------------------

    /**
     * Log a webhook event using WooCommerce logger and optionally error_log.
     *
     * @param string $level   Log level (info, warning, error, etc.).
     * @param string $message Human-readable message.
     * @param mixed  $data    Optional data to include.
     */
    private static function log($level, $message, $data = null) {
        $full = '[CCGW Webhook] ' . $message;
        if ($data !== null) {
            $full .= ' | ' . wp_json_encode($data);
        }

        // WooCommerce logger
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $full, array('source' => 'ccgw-webhook'));
        }

        // Fallback to PHP error_log if WP_DEBUG enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($full);
        }
    }
}
