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
delete_option('oxprocessing_db_version');

// Delete transients
delete_transient('oxprocessing_currencies');

// Drop custom table
$table_name = esc_sql($wpdb->prefix . 'oxprocessing_payments');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete all order meta keys
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_oxprocessing_%'");

// If HPOS is enabled, also clean from orders table
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'") === $wpdb->prefix . 'wc_orders_meta') {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_oxprocessing_%'");
}

// Clear any scheduled hooks (in case we add cron jobs later)
wp_clear_scheduled_hook('oxprocessing_cleanup_old_payments');