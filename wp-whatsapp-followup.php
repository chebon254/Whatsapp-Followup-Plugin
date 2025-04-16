<?php
/**
 * Plugin Name: WP WhatsApp Order Follow-up
 * Description: Track orders and manage manual WhatsApp follow-ups
 * Version: 2.0
 * Author: Chebon Kibet Kelvin
 * Text Domain: wp-whatsapp-followup
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Define plugin constants
define('WPWAF_VERSION', '1.0');
define('WPWAF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPWAF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPWAF_PLUGIN_FILE', __FILE__);

// Include required files
require_once WPWAF_PLUGIN_DIR . 'includes/class-wp-whatsapp-database.php';
require_once WPWAF_PLUGIN_DIR . 'includes/class-wp-whatsapp-followup.php';

// Initialize plugin
function wp_whatsapp_followup_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wp_whatsapp_followup_woocommerce_notice');
        return;
    }
    
    // Initialize the main plugin class
    $wp_whatsapp_followup = new WP_WhatsApp_Followup();
}
add_action('plugins_loaded', 'wp_whatsapp_followup_init');

// Admin notice for WooCommerce requirement
function wp_whatsapp_followup_woocommerce_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WP WhatsApp Order Follow-up requires WooCommerce to be installed and activated.', 'wp-whatsapp-followup'); ?></p>
    </div>
    <?php
}

// Activation hook
register_activation_hook(__FILE__, 'wp_whatsapp_followup_activate');

function wp_whatsapp_followup_activate() {
    // Initialize database tables
    $database = new WP_WhatsApp_Database();
    $database->create_tables();
    
    // Set version
    update_option('wp_whatsapp_followup_version', WPWAF_VERSION);
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wp_whatsapp_followup_deactivate');

function wp_whatsapp_followup_deactivate() {
    // Cleanup if needed
}