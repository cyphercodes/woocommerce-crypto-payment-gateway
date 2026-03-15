<?php
/**
 * Plugin Name: 0xProcessing for WooCommerce
 * Plugin URI: https://github.com/cyphercodes/woocommerce-crypto-payment-gateway
 * Description: Accept cryptocurrency payments via 0xProcessing in your WooCommerce store
 * Version: 1.0.0
 * Author: Rayan Salhab
 * Author URI: https://github.com/cyphercodes
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: 0xprocessing-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.6
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_OXPROCESSING_VERSION', '1.0.0');
define('WC_OXPROCESSING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_OXPROCESSING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_OXPROCESSING_PLUGIN_FILE', __FILE__);
define('WC_OXPROCESSING_API_URL', 'https://app.0xprocessing.com');

/**
 * Class WC_0xProcessing_Main
 * Main plugin class — singleton bootstrap
 */
class WC_0xProcessing_Main {

    /**
     * Single instance of the class
     *
     * @var WC_0xProcessing_Main|null
     */
    private static $instance = null;

    /**
     * Get single instance
     *
     * @return WC_0xProcessing_Main
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Declare HPOS compatibility (WooCommerce 7.1+)
        add_action('before_woocommerce_init', function () {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }

    /**
     * Initialize plugin — fires on plugins_loaded so WC is available
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load required files
        $this->includes();

        // Add payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));

        // Add REST API endpoint for webhooks
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once WC_OXPROCESSING_PLUGIN_DIR . 'includes/class-wc-0xprocessing-api.php';
        require_once WC_OXPROCESSING_PLUGIN_DIR . 'includes/class-wc-0xprocessing-gateway.php';
        require_once WC_OXPROCESSING_PLUGIN_DIR . 'includes/class-wc-0xprocessing-webhook.php';
        require_once WC_OXPROCESSING_PLUGIN_DIR . 'includes/class-wc-0xprocessing-database.php';
    }

    /**
     * Add payment gateway to WooCommerce
     *
     * @param array $gateways Registered gateways.
     * @return array
     */
    public function add_gateway($gateways) {
        $gateways[] = 'WC_0xProcessing_Gateway';
        return $gateways;
    }

    /**
     * Register webhook REST endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route('oxprocessing/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array('WC_0xProcessing_Webhook', 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('oxprocessing/v1', '/currencies', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_currencies_ajax'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * REST handler: return supported currencies
     *
     * @return WP_REST_Response
     */
    public function get_currencies_ajax() {
        $api        = new WC_0xProcessing_API();
        $currencies = $api->get_coins();

        if ($currencies) {
            return new WP_REST_Response($currencies, 200);
        }

        return new WP_REST_Response(array('error' => 'Failed to fetch currencies'), 500);
    }

    /**
     * Enqueue frontend scripts only on checkout
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        // Enqueue Select2 (WooCommerce already includes it)
        wp_enqueue_style('select2');
        wp_enqueue_script('select2');

        wp_enqueue_style(
            'wc-oxprocessing-style',
            WC_OXPROCESSING_PLUGIN_URL . 'assets/css/oxprocessing.css',
            array('select2'),
            WC_OXPROCESSING_VERSION
        );

        wp_enqueue_script(
            'wc-oxprocessing-script',
            WC_OXPROCESSING_PLUGIN_URL . 'assets/js/oxprocessing.js',
            array('jquery', 'select2'),
            WC_OXPROCESSING_VERSION,
            true
        );

        wp_localize_script('wc-oxprocessing-script', 'oxprocessing_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('oxprocessing/v1/'),
            'nonce'    => wp_create_nonce('wp_rest'),
        ));
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page hook.
     */
    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        wp_enqueue_style(
            'wc-oxprocessing-admin-style',
            WC_OXPROCESSING_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_OXPROCESSING_VERSION
        );
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_settings_link($links) {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=oxprocessing');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', '0xprocessing-for-woocommerce') . '</a>');
        return $links;
    }

    /**
     * Admin notice when WooCommerce is missing
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>'
            . esc_html__('0xProcessing for WooCommerce requires WooCommerce to be installed and active.', '0xprocessing-for-woocommerce')
            . '</p></div>';
    }

    /**
     * Admin notice when webhook password is not configured
     */
    public function webhook_password_missing_notice() {
        // Only show on WooCommerce settings pages
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') {
            return;
        }

        $settings = get_option('woocommerce_oxprocessing_settings');
        if (!is_array($settings)) {
            return;
        }

        // Only show if gateway is enabled but password is empty
        if (($settings['enabled'] ?? 'no') === 'yes' && empty($settings['webhook_password'])) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('⚠️ 0xProcessing: Webhook password is not set. Webhook signature verification is disabled, which is insecure. Please set a webhook password in ', '0xprocessing-for-woocommerce')
                . '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=oxprocessing')) . '">'
                . esc_html__('payment settings', '0xprocessing-for-woocommerce')
                . '</a>.</p></div>';
        }
    }

    /**
     * Plugin activation — load DB class directly since plugins_loaded has not fired
     */
    public function activate() {
        // The includes() method hasn't run yet during activation, so load explicitly
        require_once WC_OXPROCESSING_PLUGIN_DIR . 'includes/class-wc-0xprocessing-database.php';

        $database = new WC_0xProcessing_Database();
        $database->create_table();

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize plugin
WC_0xProcessing_Main::get_instance();