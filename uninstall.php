<?php
/**
 * Cyphercodes Crypto Gateway — Uninstall
 *
 * Removes all plugin data when uninstalled via the WordPress admin.
 *
 * @package CCGW
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Delete plugin options
delete_option('woocommerce_ccgw_settings');
delete_option('ccgw_db_version');

// 2. Drop custom database table
$table_name = $wpdb->prefix . 'ccgw_payments';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// 3. Delete transients
delete_transient('ccgw_currencies');

// 4. Delete order meta (HPOS-compatible via wc_get_orders + delete_meta_data)
$meta_keys = array(
    '_ccgw_payment_id',
    '_ccgw_currency',
    '_ccgw_redirect_url',
    '_ccgw_payment_status',
    '_ccgw_amount_paid',
    '_ccgw_amount_usd',
    '_ccgw_tx_hash',
    '_ccgw_amount_received',
);

// Process in batches to avoid memory issues on large stores
$page = 1;
$batch_size = 100;

do {
    $orders = wc_get_orders(array(
        'limit'          => $batch_size,
        'page'           => $page,
        'payment_method' => 'ccgw',
    ));

    foreach ($orders as $order) {
        foreach ($meta_keys as $key) {
            $order->delete_meta_data($key);
        }
        $order->save();
    }

    $page++;
} while (count($orders) === $batch_size);