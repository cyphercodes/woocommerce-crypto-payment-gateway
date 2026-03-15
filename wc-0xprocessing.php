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
 * Requires at least: 6.2
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

        // Clear currency cache when gateway settings are saved
        add_action('woocommerce_update_options_payment_gateways_oxprocessing', array($this, 'clear_currency_cache'));
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
     * Clear cached currency list (used when settings are saved)
     */
    public function clear_currency_cache() {
        delete_transient('oxprocessing_currencies');
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

        // Inject theme customization CSS from gateway settings
        $this->enqueue_theme_overrides();
    }

    /**
     * Output inline CSS for theme color overrides from gateway settings.
     * Theme CSS (Additional CSS) will naturally override these since it loads later.
     */
    private function enqueue_theme_overrides() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways['oxprocessing'])) {
            return;
        }
        $gateway = $gateways['oxprocessing'];

        // Preset definitions
        $presets = array(
            'light' => array(
                'theme_accent_color'         => '#4a6cf7',
                'theme_text_color'           => '#333333',
                'theme_text_secondary_color' => '#666666',
                'theme_text_muted_color'     => '#999999',
                'theme_bg_color'             => '#ffffff',
                'theme_bg_alt_color'         => '#f8f9fa',
                'theme_input_bg_color'       => '#ffffff',
                'theme_border_color'         => '#e0e0e0',
                'theme_border_radius'        => '8px',
            ),
            'dark' => array(
                'theme_accent_color'         => '#6c8cff',
                'theme_text_color'           => '#e0e0e0',
                'theme_text_secondary_color' => '#a0a0a0',
                'theme_text_muted_color'     => '#707070',
                'theme_bg_color'             => '#1a1a1a',
                'theme_bg_alt_color'         => '#222222',
                'theme_input_bg_color'       => '#2a2a2a',
                'theme_border_color'         => '#333333',
                'theme_border_radius'        => '8px',
            ),
        );

        $preset = $gateway->get_option('theme_preset', 'light');

        // Map of setting key => CSS variable
        $color_map = array(
            'theme_accent_color'         => '--oxp-accent',
            'theme_text_color'           => '--oxp-text',
            'theme_text_secondary_color' => '--oxp-text-light',
            'theme_text_muted_color'     => '--oxp-text-muted',
            'theme_bg_color'             => '--oxp-bg',
            'theme_bg_alt_color'         => '--oxp-bg-alt',
            'theme_input_bg_color'       => '--oxp-bg-input',
            'theme_border_color'         => '--oxp-border',
            'theme_border_radius'        => '--oxp-radius',
        );

        // Light defaults (CSS defaults in oxprocessing.css)
        $light_defaults = $presets['light'];

        $vars = array();

        if ($preset === 'custom') {
            // Custom: use individual saved settings, only override non-defaults
            foreach ($color_map as $key => $css_var) {
                $default = $light_defaults[$key];
                $value   = $gateway->get_option($key, $default);
                if (!empty($value) && $value !== $default) {
                    $vars[] = $css_var . ': ' . sanitize_text_field($value);
                }
            }
        } elseif ($preset === 'dark') {
            // Dark: apply the full dark palette
            foreach ($color_map as $key => $css_var) {
                $vars[] = $css_var . ': ' . $presets['dark'][$key];
            }
            // Dark theme needs adapted shadows
            $vars[] = '--oxp-shadow: 0 2px 8px rgba(0, 0, 0, 0.3)';
            $vars[] = '--oxp-shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.4)';
        }
        // 'light' = no overrides needed, CSS defaults are light

        // Compute accent-hover and accent-rgb
        $accent_default = $light_defaults['theme_accent_color'];
        if ($preset === 'dark') {
            $accent = $presets['dark']['theme_accent_color'];
        } elseif ($preset === 'custom') {
            $accent = $gateway->get_option('theme_accent_color', $accent_default);
        } else {
            $accent = $accent_default;
        }

        if ($accent !== $accent_default) {
            $vars[] = '--oxp-accent-hover: ' . self::darken_hex($accent, 15);
            $rgb = self::hex_to_rgb($accent);
            if ($rgb) {
                $vars[] = '--oxp-accent-rgb: ' . implode(', ', $rgb);
            }
        }

        // Icon size CSS
        $icon_size = $gateway->get_option('theme_icon_size', 'small');
        $icon_sizes = array('small' => '24px', 'medium' => '32px', 'large' => '40px');
        $icon_css = '';
        if (isset($icon_sizes[$icon_size])) {
            $px = $icon_sizes[$icon_size];
            $icon_css = '.payment_method_oxprocessing img { max-height: ' . $px . '; width: auto; }';
        }

        $css = '';
        if (!empty($vars)) {
            $css .= ':root { ' . implode('; ', $vars) . '; }';
        }
        if (!empty($icon_css)) {
            $css .= ' ' . $icon_css;
        }

        // Dark mode: add aggressive overrides for Select2 and WooCommerce elements
        // These use !important because Select2's default styles override CSS variables
        if ($preset === 'dark' || ($preset === 'custom' && $this->is_dark_bg($gateway))) {
            $bg     = $preset === 'dark' ? $presets['dark']['theme_bg_color'] : $gateway->get_option('theme_bg_color', '#ffffff');
            $bg_alt = $preset === 'dark' ? $presets['dark']['theme_bg_alt_color'] : $gateway->get_option('theme_bg_alt_color', '#f8f9fa');
            $bg_inp = $preset === 'dark' ? $presets['dark']['theme_input_bg_color'] : $gateway->get_option('theme_input_bg_color', '#ffffff');
            $border = $preset === 'dark' ? $presets['dark']['theme_border_color'] : $gateway->get_option('theme_border_color', '#e0e0e0');
            $text   = $preset === 'dark' ? $presets['dark']['theme_text_color'] : $gateway->get_option('theme_text_color', '#333333');
            $muted  = $preset === 'dark' ? $presets['dark']['theme_text_muted_color'] : $gateway->get_option('theme_text_muted_color', '#999999');

            $css .= '
            /* === Dark mode overrides === */
            .payment_method_oxprocessing .payment_box {
                background: ' . $bg . ' !important;
                color: ' . $text . ' !important;
                border-color: ' . $border . ' !important;
            }
            .payment_method_oxprocessing .payment_box::before,
            .payment_method_oxprocessing .payment_box::after {
                border-bottom-color: ' . $bg . ' !important;
                border-top-color: ' . $bg . ' !important;
            }
            .payment_method_oxprocessing .oxprocessing-currency-selector {
                background: ' . $bg_alt . ' !important;
                border-color: ' . $border . ' !important;
            }
            .payment_method_oxprocessing .oxprocessing-currency-selector label {
                color: var(--oxp-accent) !important;
            }
            /* Select2 closed state — scoped by payment method parent */
            .payment_method_oxprocessing .select2-container .select2-selection--single {
                background: ' . $bg_inp . ' !important;
                border-color: ' . $border . ' !important;
                color: ' . $text . ' !important;
            }
            .payment_method_oxprocessing .select2-container .select2-selection__rendered {
                color: ' . $text . ' !important;
            }
            .payment_method_oxprocessing .select2-container .select2-selection__placeholder {
                color: ' . $muted . ' !important;
            }
            .payment_method_oxprocessing .select2-container .select2-selection__arrow b {
                border-color: ' . $muted . ' transparent transparent transparent !important;
            }
            .payment_method_oxprocessing .select2-container--open .select2-selection__arrow b {
                border-color: transparent transparent ' . $muted . ' transparent !important;
            }
            /* Select2 dropdown panel (appended to body — uses dropdownCssClass) */
            .oxprocessing-select2-dropdown,
            .oxprocessing-select2-dropdown .select2-results,
            .oxprocessing-select2-dropdown .select2-search--dropdown {
                background: ' . $bg . ' !important;
                border-color: ' . $border . ' !important;
            }
            .oxprocessing-select2-dropdown .select2-search__field {
                background: ' . $bg_inp . ' !important;
                border-color: ' . $border . ' !important;
                color: ' . $text . ' !important;
            }
            .oxprocessing-select2-dropdown .select2-search__field::placeholder {
                color: ' . $muted . ' !important;
            }
            .oxprocessing-select2-dropdown .select2-results__option {
                background: ' . $bg . ' !important;
                color: ' . $text . ' !important;
                border-bottom-color: ' . $border . ' !important;
            }
            .oxprocessing-select2-dropdown .select2-results__option--highlighted,
            .oxprocessing-select2-dropdown .select2-results__option--highlighted.select2-results__option--selectable {
                background: ' . $bg_alt . ' !important;
                color: ' . $text . ' !important;
            }
            .oxprocessing-select2-dropdown .select2-results__option--selected,
            .oxprocessing-select2-dropdown .select2-results__option[aria-selected="true"] {
                background: var(--oxp-accent) !important;
                color: #ffffff !important;
            }
            /* WooCommerce description text */
            .payment_method_oxprocessing .payment_box p {
                color: ' . $text . ' !important;
            }
            .payment_method_oxprocessing .oxprocessing-currency-selector .description {
                color: ' . $muted . ' !important;
            }
            ';
        }

        if (!empty($css)) {
            wp_add_inline_style('wc-oxprocessing-style', $css);
        }
    }

    /**
     * Darken a hex color by a percentage.
     *
     * @param string $hex    Hex color (e.g. #4a6cf7).
     * @param int    $percent Percent to darken (0-100).
     * @return string Darkened hex color.
     */
    private static function darken_hex($hex, $percent) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = max(0, (int) round(hexdec(substr($hex, 0, 2)) * (1 - $percent / 100)));
        $g = max(0, (int) round(hexdec(substr($hex, 2, 2)) * (1 - $percent / 100)));
        $b = max(0, (int) round(hexdec(substr($hex, 4, 2)) * (1 - $percent / 100)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Convert hex to RGB array.
     *
     * @param string $hex Hex color.
     * @return array|false Array of [r, g, b] or false.
     */
    private static function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return false;
        }
        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    /**
     * Check if the custom background color is dark (needs aggressive overrides).
     *
     * @param WC_Payment_Gateway $gateway Gateway instance.
     * @return bool True if background is dark.
     */
    private function is_dark_bg($gateway) {
        $bg = $gateway->get_option('theme_bg_color', '#ffffff');
        $rgb = self::hex_to_rgb($bg);
        if (!$rgb) {
            return false;
        }
        // Perceived luminance: if below 128, it's dark
        $luminance = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]);
        return $luminance < 128;
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

        // Inline JS for theme preset auto-fill
        $preset_js = "
        jQuery(function($) {
            var presets = {
                light: {
                    theme_accent_color: '#4a6cf7',
                    theme_text_color: '#333333',
                    theme_text_secondary_color: '#666666',
                    theme_text_muted_color: '#999999',
                    theme_bg_color: '#ffffff',
                    theme_bg_alt_color: '#f8f9fa',
                    theme_input_bg_color: '#ffffff',
                    theme_border_color: '#e0e0e0',
                    theme_border_radius: '8px'
                },
                dark: {
                    theme_accent_color: '#6c8cff',
                    theme_text_color: '#e0e0e0',
                    theme_text_secondary_color: '#a0a0a0',
                    theme_text_muted_color: '#707070',
                    theme_bg_color: '#1a1a1a',
                    theme_bg_alt_color: '#222222',
                    theme_input_bg_color: '#2a2a2a',
                    theme_border_color: '#333333',
                    theme_border_radius: '8px'
                }
            };

            $('#woocommerce_oxprocessing_theme_preset').on('change', function() {
                var preset = $(this).val();
                if (preset === 'custom') return;
                var values = presets[preset];
                if (!values) return;
                $.each(values, function(key, val) {
                    var field = $('#woocommerce_oxprocessing_' + key);
                    if (field.length) {
                        field.val(val).trigger('change');
                        // Update WP color picker swatch if present
                        field.closest('.wp-picker-container')
                             .find('.wp-color-result')
                             .css('background-color', val);
                    }
                });
            });
        });
        ";
        wp_add_inline_script('jquery', $preset_js);
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