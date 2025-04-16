<?php
/**
 * Main plugin class for WP WhatsApp Order Follow-up
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

class WP_WhatsApp_Followup {
    
    /**
     * Database handler instance
     * @var WP_WhatsApp_Database
     */
    private $db;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new WP_WhatsApp_Database();

        // Check if product comments are enabled
        $this->ensure_product_comments_enabled();
        
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_completed', array($this, 'track_completed_order'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Track comments on products
        add_action('wp_insert_comment', array($this, 'track_product_comment'), 10, 2);
        
        // Register AJAX handlers
        add_action('wp_ajax_log_whatsapp_message', array($this, 'log_message_sent'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Simplify comment form
        add_filter('comment_form_default_fields', array($this, 'simplify_product_comment_form'));

        // Add manual override handler
        add_action('wp_ajax_mark_as_commented_manually', array($this, 'mark_as_commented_manually'));

        // Add import orders handler
       add_action('wp_ajax_import_existing_orders', array($this, 'import_existing_orders'));   
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin page
        if ($hook != 'toplevel_page_whatsapp-followup') {
            return;
        }
        
        wp_enqueue_style(
            'wpwaf-admin-styles',
            WPWAF_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            WPWAF_VERSION
        );
        
        wp_enqueue_script(
            'wpwaf-admin-scripts',
            WPWAF_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WPWAF_VERSION,
            true
        );
        
        wp_localize_script('wpwaf-admin-scripts', 'wpwaf_vars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('whatsapp_message_nonce')
        ));
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_menu_page(
            __('WhatsApp Follow-up', 'wp-whatsapp-followup'),
            __('WhatsApp Follow-up', 'wp-whatsapp-followup'),
            'manage_options',
            'whatsapp-followup',
            array($this, 'admin_page'),
            'dashicons-whatsapp',
            30
        );
    }
    
    /**
     * Display admin page
     */
    public function admin_page() {
        // Get filter from URL
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'not_commented';
        
        // Get records for display
        $records = $this->db->get_records($filter);
        
        // Get analytics data
        $analytics = $this->db->get_analytics();
        
        // Load template
        include(WPWAF_PLUGIN_DIR . 'templates/admin-dashboard.php');
    }
    
    /**
     * Track completed orders
     * 
     * @param int $order_id
     */
    public function track_completed_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) return;
        
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        
        // Get product IDs from order
        $product_ids = array();
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
        }
        
        // Insert into tracking table
        $this->db->insert_record($order_id, $customer_email, $customer_phone, $product_ids);
    }
    
    /**
     * Track product comments from both guests and logged-in users
     * 
     * @param int $comment_id
     * @param object $comment_object
     */
    public function track_product_comment($comment_id, $comment_object) {
        // Check if comment is on a product
        if (get_post_type($comment_object->comment_post_ID) !== 'product') {
            return;
        }
        
        $comment_email = $comment_object->comment_author_email;
        $product_id = $comment_object->comment_post_ID;
        
        // Skip if no email (though this should rarely happen)
        if (empty($comment_email)) {
            return;
        }
        
        // Update records where this email and product match
        $this->db->mark_as_commented($comment_email, $product_id);
        
        // Log this action for debugging
        error_log(sprintf(
            'WhatsApp Follow-up: Comment detected for email %s on product %d',
            $comment_email,
            $product_id
        ));
    }
    
    /**
     * Handle AJAX request to log message sent
     */
    public function log_message_sent() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'whatsapp_message_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Validate record ID
        if (!isset($_POST['record_id']) || !is_numeric($_POST['record_id'])) {
            wp_send_json_error(array('message' => 'Invalid record ID'));
            return;
        }
        
        $record_id = intval($_POST['record_id']);
        
        // Increment message count
        $success = $this->db->increment_message_count($record_id);
        
        if ($success) {
            wp_send_json_success(array('message' => 'Message recorded successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to record message'));
        }
    }
    
    /**
     * Generate WhatsApp click-to-chat link
     * 
     * @param string $phone
     * @param int $order_id
     * @param string $product_ids Comma-separated
     * @return string
     */
    public function generate_whatsapp_link($phone, $order_id, $product_ids) {
        // Format phone (remove spaces, add country code if needed)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add default country code if missing
        // You may want to make this customizable via settings
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
        
        // Get product information for the message
        $product_names = array();
        $product_url = '';
        
        foreach (explode(',', $product_ids) as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_names[] = $product->get_name();
                // Use the URL of the first product in the list
                if (empty($product_url)) {
                    $product_url = get_permalink($product_id);
                }
            }
        }
        
        $product_text = implode(', ', $product_names);
        
        // Create message text
        $message = sprintf(
            __('Hello! Thank you for your recent order #%d. We hope you\'re enjoying your %s. We\'d love to hear your feedback! %s', 'wp-whatsapp-followup'),
            $order_id,
            $product_text,
            $product_url
        );
        
        // URL encode the message
        $encoded_message = urlencode($message);
        
        return "https://wa.me/$phone?text=$encoded_message";
    }

    /**
     * Ensure product comments are enabled __construct
     */
    public function ensure_product_comments_enabled() {
        // Check if product comments are open
        $product_comment_status = get_option('woocommerce_enable_reviews', 'yes');
        
        if ($product_comment_status !== 'yes') {
            // Log a warning
            error_log('WhatsApp Follow-up: WooCommerce product reviews are disabled. This plugin requires reviews to be enabled.');
            
            // Add an admin notice
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning">
                    <p><?php _e('WhatsApp Follow-up: WooCommerce product reviews are currently disabled. Please enable them for proper tracking of customer feedback.', 'wp-whatsapp-followup'); ?></p>
                </div>
                <?php
            });
        }
    }

    /**
     * Simplify the comment form for guests
     */
    public function simplify_product_comment_form($fields) {
        // Only modify for product pages
        if (!is_product()) {
            return $fields;
        }
        
        // Remove the website field (optional)
        if (isset($fields['url'])) {
            unset($fields['url']);
        }
        
        // Add a note about email address
        if (isset($fields['email'])) {
            $fields['email']['label'] = __('Email (same as order)', 'wp-whatsapp-followup');
        }
        
        return $fields;
    }

    /**
     * Handle manual override to mark as commented
     */
    public function mark_as_commented_manually() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'whatsapp_message_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Validate record ID
        if (!isset($_POST['record_id']) || !is_numeric($_POST['record_id'])) {
            wp_send_json_error(array('message' => 'Invalid record ID'));
            return;
        }
        
        $record_id = intval($_POST['record_id']);
        
        // Update the record
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'whatsapp_followup',
            array(
                'has_commented' => 1,
                'comment_date' => current_time('mysql')
            ),
            array('id' => $record_id)
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Record marked as commented'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update record'));
        }
    }

    /**
     * Import existing completed orders
     */
    public function import_existing_orders() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'whatsapp_message_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Get existing orders in the tracking table to avoid duplicates
        global $wpdb;
        $existing_order_ids = $wpdb->get_col("SELECT order_id FROM {$wpdb->prefix}whatsapp_followup");
        
        // Query completed orders
        $args = array(
            'status' => 'completed',
            'limit' => -1, // All orders
            'return' => 'ids',
        );
        
        $order_ids = wc_get_orders($args);
        $imported_count = 0;
        
        foreach ($order_ids as $order_id) {
            // Skip if already imported
            if (in_array($order_id, $existing_order_ids)) {
                continue;
            }
            
            $order = wc_get_order($order_id);
            
            if (!$order) continue;
            
            $customer_email = $order->get_billing_email();
            $customer_phone = $order->get_billing_phone();
            
            // Skip if no phone number
            if (empty($customer_phone)) {
                continue;
            }
            
            // Get product IDs from order
            $product_ids = array();
            foreach ($order->get_items() as $item) {
                $product_ids[] = $item->get_product_id();
            }
            
            // Skip if no products
            if (empty($product_ids)) {
                continue;
            }
            
            // Insert into tracking table
            $inserted = $this->db->insert_record($order_id, $customer_email, $customer_phone, $product_ids);
            
            if ($inserted) {
                $imported_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d orders imported successfully', 'wp-whatsapp-followup'), $imported_count),
            'count' => $imported_count
        ));
    }
}