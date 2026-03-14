<?php
/**
 * 0xProcessing Database Handler
 * Manages custom database table for payment tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_0xProcessing_Database {

    /**
     * Table name
     */
    private $table_name;

    /**
     * Current database schema version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'oxprocessing_payments';
    }

    /**
     * Create database table
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            payment_id bigint(20) unsigned NOT NULL,
            currency varchar(50) NOT NULL,
            amount_fiat decimal(20,8) NOT NULL DEFAULT 0.00000000,
            fiat_currency varchar(10) NOT NULL DEFAULT 'USD',
            amount_crypto decimal(20,8) DEFAULT NULL,
            amount_usd decimal(20,8) DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            is_insufficient tinyint(1) DEFAULT 0,
            tx_hashes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY payment_id (payment_id),
            KEY order_id (order_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Track database version
        update_option('oxprocessing_db_version', self::DB_VERSION);

        // Log table creation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[0xProcessing] Database table created/updated: ' . $this->table_name);
        }
    }

    /**
     * Check if database needs upgrading
     *
     * @return bool
     */
    public function needs_upgrade() {
        $installed_version = get_option('oxprocessing_db_version', '0');
        return version_compare($installed_version, self::DB_VERSION, '<');
    }

    /**
     * Save payment record
     *
     * @param array $data
     * @return int|bool Insert ID or false on error
     */
    public function save_payment($data) {
        global $wpdb;

        $defaults = array(
            'order_id' => 0,
            'payment_id' => 0,
            'currency' => '',
            'amount_fiat' => 0,
            'fiat_currency' => 'USD',
            'amount_crypto' => null,
            'amount_usd' => null,
            'status' => 'pending',
            'is_insufficient' => 0,
            'tx_hashes' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $data = wp_parse_args($data, $defaults);

        // Format data for database
        $insert_data = array(
            'order_id' => absint($data['order_id']),
            'payment_id' => absint($data['payment_id']),
            'currency' => sanitize_text_field($data['currency']),
            'amount_fiat' => floatval($data['amount_fiat']),
            'fiat_currency' => sanitize_text_field($data['fiat_currency']),
            'amount_crypto' => $data['amount_crypto'] !== null ? floatval($data['amount_crypto']) : null,
            'amount_usd' => $data['amount_usd'] !== null ? floatval($data['amount_usd']) : null,
            'status' => sanitize_text_field($data['status']),
            'is_insufficient' => $data['is_insufficient'] ? 1 : 0,
            'tx_hashes' => $data['tx_hashes'] !== null ? sanitize_text_field($data['tx_hashes']) : null,
            'created_at' => sanitize_text_field($data['created_at']),
            'updated_at' => sanitize_text_field($data['updated_at'])
        );

        $formats = array('%d', '%d', '%s', '%f', '%s', '%f', '%f', '%s', '%d', '%s', '%s', '%s');

        $result = $wpdb->insert($this->table_name, $insert_data, $formats);

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[0xProcessing] Database insert error: ' . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update payment status
     *
     * @param int $payment_id
     * @param array $data
     * @return bool
     */
    public function update_payment_status($payment_id, $data) {
        global $wpdb;

        $update_data = array();
        $formats = array();

        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
            $formats[] = '%s';
        }

        if (isset($data['amount_crypto'])) {
            $update_data['amount_crypto'] = floatval($data['amount_crypto']);
            $formats[] = '%f';
        }

        if (isset($data['amount_usd'])) {
            $update_data['amount_usd'] = floatval($data['amount_usd']);
            $formats[] = '%f';
        }

        if (isset($data['is_insufficient'])) {
            $update_data['is_insufficient'] = $data['is_insufficient'] ? 1 : 0;
            $formats[] = '%d';
        }

        if (isset($data['tx_hashes'])) {
            $update_data['tx_hashes'] = sanitize_text_field($data['tx_hashes']);
            $formats[] = '%s';
        }

        if (isset($data['updated_at'])) {
            $update_data['updated_at'] = sanitize_text_field($data['updated_at']);
            $formats[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('payment_id' => absint($payment_id)),
            $formats,
            array('%d')
        );

        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[0xProcessing] Database update error: ' . $wpdb->last_error);
        }

        return $result !== false;
    }

    /**
     * Get payment by payment ID
     *
     * @param int $payment_id
     * @return object|null
     */
    public function get_payment_by_payment_id($payment_id) {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE payment_id = %d",
            absint($payment_id)
        ));

        return $result;
    }

    /**
     * Get payment by order ID
     *
     * @param int $order_id
     * @return object|null
     */
    public function get_payment_by_order_id($order_id) {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            absint($order_id)
        ));

        return $result;
    }

    /**
     * Get payments by status
     *
     * @param string $status
     * @param int $limit
     * @return array
     */
    public function get_payments_by_status($status, $limit = 100) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
            sanitize_text_field($status),
            absint($limit)
        ));

        return $results;
    }

    /**
     * Get all payments
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_all_payments($limit = 100, $offset = 0) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            absint($limit),
            absint($offset)
        ));

        return $results;
    }

    /**
     * Delete payment record
     *
     * @param int $payment_id
     * @return bool
     */
    public function delete_payment($payment_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('payment_id' => absint($payment_id)),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Get payment statistics
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        $stats = array(
            'total_payments' => 0,
            'total_amount_fiat' => 0,
            'status_counts' => array()
        );

        // Total payments
        $stats['total_payments'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        // Total fiat amount
        $stats['total_amount_fiat'] = $wpdb->get_var("SELECT SUM(amount_fiat) FROM {$this->table_name} WHERE status = 'success'");

        // Status counts
        $status_results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status"
        );

        foreach ($status_results as $row) {
            $stats['status_counts'][$row->status] = $row->count;
        }

        return $stats;
    }

    /**
     * Clean old pending payments
     *
     * @param int $days Number of days to keep
     * @return int Number of rows deleted
     */
    public function clean_old_pending_payments($days = 7) {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status = 'pending' 
             AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            absint($days)
        ));

        return $result;
    }

    /**
     * Drop table (for uninstall)
     */
    public function drop_table() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }
}