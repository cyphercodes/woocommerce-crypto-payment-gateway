<?php
/**
 * Uninstall script for 0xProcessing for WooCommerce
 *
 * This script runs when the plugin is deleted via the WordPress admin.
 * It removes custom database tables and plugin options.
 *
 * @package WC_0xProcessing
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load WP database globals
global $wpdb;

// Delete plugin options
delete_option('woocommerce_oxprocessing_settings');

// Delete transients
delete_transient('oxprocessing_currencies');

// Drop custom table
$table_name = $wpdb->prefix . 'oxprocessing_payments';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete all order meta keys
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_oxprocessing_%'");

// If HPOS is enabled, also clean from orders table
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'") === $wpdb->prefix . 'wc_orders_meta') {
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_oxprocessing_%'");
}

// Clear any scheduled hooks (in case we add cron jobs later)
wp_clear_scheduled_hook('oxprocessing_cleanup_old_payments');