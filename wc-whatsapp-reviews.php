<?php
/**
 * Plugin Name: WooCommerce WhatsApp Reviews
 * Description: Send WhatsApp messages to customers asking for product reviews
 * Version: 1.2
 * Author: <a href="https://chebonkelvin.com">Chebon Kelvin</a>
 * Text Domain: wc-whatsapp-reviews
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_WhatsApp_Reviews {
    /**
     * Constructor
     */
    public function __construct() {
        // Plugin activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Ajax handlers
        add_action('wp_ajax_mark_review_complete', array($this, 'mark_review_complete'));
        add_action('wp_ajax_filter_whatsapp_reviews', array($this, 'filter_whatsapp_reviews'));
    }
    
    /**
     * Filter orders based on review status (AJAX handler)
     */
    public function filter_whatsapp_reviews() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-whatsapp-reviews-nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Get filter status
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';
        
        // Store output in buffer
        ob_start();
        
        // Get WooCommerce orders
        $args = array(
            'status' => 'completed',
            'limit' => 20,
            'paged' => isset($_POST['page']) ? absint($_POST['page']) : 1,
        );
        
        $orders = wc_get_orders($args);
        
        global $wpdb;
        $review_status_table = $wpdb->prefix . 'whatsapp_review_status';
        
        // Display filtered orders
        if (empty($orders)) {
            echo '<tr><td colspan="7">' . esc_html__('No completed orders found.', 'wc-whatsapp-reviews') . '</td></tr>';
        } else {
            foreach ($orders as $order) {
                $order_id = $order->get_id();
                
                // Check review status
                $review_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM $review_status_table WHERE order_id = %d",
                    $order_id
                ));
                
                // If no record exists, set status to pending and create record
                if (null === $review_status) {
                    $review_status = 'pending';
                    $wpdb->insert(
                        $review_status_table,
                        array(
                            'order_id' => $order_id,
                            'status' => $review_status
                        )
                    );
                }
                
                // Apply filter
                if ($status !== 'all' && $review_status !== $status) {
                    continue;
                }
                
                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $phone = $this->format_phone_number($order->get_billing_phone());
                
                // Get order items
                $items = $order->get_items();
                $products = array();
                
                foreach ($items as $item) {
                    $product_id = $item->get_product_id();
                    $product_name = $item->get_name();
                    $products[] = array(
                        'id' => $product_id,
                        'name' => $product_name
                    );
                }
                
                ?>
                <tr data-order-id="<?php echo esc_attr($order_id); ?>">
                    <td>#<?php echo esc_html($order_id); ?></td>
                    <td><?php echo esc_html($customer_name); ?></td>
                    <td><?php echo esc_html($phone); ?></td>
                    <td>
                        <?php 
                        $product_names = array_map(function($product) {
                            return $product['name'];
                        }, $products);
                        echo esc_html(implode(', ', $product_names)); 
                        ?>
                    </td>
                    <td><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                    <td class="review-status"><?php echo esc_html(ucfirst($review_status)); ?></td>
                    <td class="actions">
                        <?php if ($review_status !== 'completed') : ?>
                            <button class="button send-whatsapp" 
                                data-phone="<?php echo esc_attr($phone); ?>"
                                data-products="<?php echo esc_attr(json_encode($products)); ?>"
                                data-order-id="<?php echo esc_attr($order_id); ?>"
                            >
                                <?php esc_html_e('Send Message', 'wc-whatsapp-reviews'); ?>
                            </button>
                            <button class="button mark-completed" 
                                data-order-id="<?php echo esc_attr($order_id); ?>"
                            >
                                <?php esc_html_e('Completed', 'wc-whatsapp-reviews'); ?>
                            </button>
                        <?php else : ?>
                            <span class="completed-text"><?php esc_html_e('Review Completed', 'wc-whatsapp-reviews'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
        }
        
        $table_html = ob_get_clean();
        
        // Get pagination HTML
        ob_start();
        
        // Calculate total filtered orders for pagination
        $all_orders_args = array(
            'status' => 'completed',
            'limit' => -1,
            'return' => 'ids',
        );
        
        $all_orders = wc_get_orders($all_orders_args);
        
        if ($status !== 'all') {
            // Only count orders with matching review status
            $filtered_orders = array();
            
            foreach ($all_orders as $order_id) {
                $review_status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM $review_status_table WHERE order_id = %d",
                    $order_id
                ));
                
                if (null === $review_status) {
                    $review_status = 'pending';
                }
                
                if ($review_status === $status) {
                    $filtered_orders[] = $order_id;
                }
            }
            
            $total_orders = count($filtered_orders);
        } else {
            $total_orders = count($all_orders);
        }
        
        $orders_per_page = 20;
        $total_pages = ceil($total_orders / $orders_per_page);
        $current_page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        
        if ($total_orders > 0) {
            echo '<span class="displaying-num">' . sprintf(
                _n('%s item', '%s items', $total_orders, 'wc-whatsapp-reviews'),
                number_format_i18n($total_orders)
            ) . '</span>';
            
            if ($total_pages > 1) {
                $page_links = array();
                
                // First page
                if ($current_page > 1) {
                    $page_links[] = '<a class="first-page" href="#" data-page="1"><span class="screen-reader-text">' . 
                        __('First page', 'wc-whatsapp-reviews') . '</span><span aria-hidden="true">&laquo;</span></a>';
                }
                
                // Previous page
                if ($current_page > 1) {
                    $page_links[] = '<a class="prev-page" href="#" data-page="' . ($current_page - 1) . '"><span class="screen-reader-text">' . 
                        __('Previous page', 'wc-whatsapp-reviews') . '</span><span aria-hidden="true">‹</span></a>';
                }
                
                // Page numbers
                $start = max(1, min($current_page - 2, $total_pages - 4));
                $end = min($total_pages, max($current_page + 2, 5));
                
                for ($i = $start; $i <= $end; $i++) {
                    if ($i === $current_page) {
                        $page_links[] = '<span class="tablenav-pages-navspan current" aria-current="page">' . $i . '</span>';
                    } else {
                        $page_links[] = '<a class="page-numbers" href="#" data-page="' . $i . '">' . $i . '</a>';
                    }
                }
                
                // Next page
                if ($current_page < $total_pages) {
                    $page_links[] = '<a class="next-page" href="#" data-page="' . ($current_page + 1) . '"><span class="screen-reader-text">' . 
                        __('Next page', 'wc-whatsapp-reviews') . '</span><span aria-hidden="true">›</span></a>';
                }
                
                // Last page
                if ($current_page < $total_pages) {
                    $page_links[] = '<a class="last-page" href="#" data-page="' . $total_pages . '"><span class="screen-reader-text">' . 
                        __('Last page', 'wc-whatsapp-reviews') . '</span><span aria-hidden="true">&raquo;</span></a>';
                }
                
                echo '<span class="pagination-links">' . join('', $page_links) . '</span>';
            }
        }
        
        $pagination_html = ob_get_clean();
        
        // Send response
        wp_send_json_success(array(
            'table_html' => $table_html,
            'pagination_html' => $pagination_html
        ));
        
        exit;
    }

    /**
     * Plugin activation
     */
    public function activate_plugin() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'whatsapp_review_status';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add plugin menu to WooCommerce submenu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'WhatsApp Reviews',
            'WhatsApp Reviews',
            'manage_woocommerce',
            'wc-whatsapp-reviews',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin page
        if ($hook != 'woocommerce_page_wc-whatsapp-reviews') {
            return;
        }
        
        wp_enqueue_style(
            'wc-whatsapp-reviews-styles', 
            plugin_dir_url(__FILE__) . 'assets/css/admin.css', 
            array(), 
            '1.0.0'
        );
        
        wp_enqueue_script(
            'wc-whatsapp-reviews-scripts',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script(
            'wc-whatsapp-reviews-scripts',
            'wcWhatsAppReviews',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc-whatsapp-reviews-nonce')
            )
        );
    }

    /**
     * Render the admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap wc-whatsapp-reviews">
            <h1><?php esc_html_e('WooCommerce WhatsApp Reviews', 'wc-whatsapp-reviews'); ?></h1>
            
            <div class="wc-whatsapp-reviews-container">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select id="filter-order-status">
                            <option value="pending"><?php esc_html_e('Pending Reviews', 'wc-whatsapp-reviews'); ?></option>
                            <option value="completed"><?php esc_html_e('Completed Reviews', 'wc-whatsapp-reviews'); ?></option>
                            <option value="all"><?php esc_html_e('All Orders', 'wc-whatsapp-reviews'); ?></option>
                        </select>
                        <input type="submit" id="filter-submit" class="button" value="<?php esc_attr_e('Filter', 'wc-whatsapp-reviews'); ?>">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order ID', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Customer', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Phone', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Products', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Order Date', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Status', 'wc-whatsapp-reviews'); ?></th>
                            <th><?php esc_html_e('Actions', 'wc-whatsapp-reviews'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wc-whatsapp-reviews-table-body">
                        <?php $this->render_orders_table(); ?>
                    </tbody>
                </table>
                
                <div class="tablenav bottom">
                    <div class="tablenav-pages" id="wc-whatsapp-reviews-pagination">
                        <?php $this->render_pagination(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render orders table
     */
    private function render_orders_table() {
        // Get completed orders
        $args = array(
            'status' => 'completed',
            'limit' => 20,
            'paged' => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
        );
        
        $orders = wc_get_orders($args);
        
        if (empty($orders)) {
            echo '<tr><td colspan="7">' . esc_html__('No completed orders found.', 'wc-whatsapp-reviews') . '</td></tr>';
            return;
        }
        
        global $wpdb;
        $review_status_table = $wpdb->prefix . 'whatsapp_review_status';
        
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $phone = $this->format_phone_number($order->get_billing_phone());
            
            // Get order items
            $items = $order->get_items();
            $products = array();
            
            foreach ($items as $item) {
                $product_id = $item->get_product_id();
                $product_name = $item->get_name();
                $products[] = array(
                    'id' => $product_id,
                    'name' => $product_name
                );
            }
            
            // Check review status
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $review_status_table WHERE order_id = %d",
                $order_id
            ));
            
            // If no record exists, set status to pending and create record
            if (null === $status) {
                $status = 'pending';
                $wpdb->insert(
                    $review_status_table,
                    array(
                        'order_id' => $order_id,
                        'status' => $status
                    )
                );
            }
            
            ?>
            <tr data-order-id="<?php echo esc_attr($order_id); ?>">
                <td>#<?php echo esc_html($order_id); ?></td>
                <td><?php echo esc_html($customer_name); ?></td>
                <td><?php echo esc_html($phone); ?></td>
                <td>
                    <?php 
                    $product_names = array_map(function($product) {
                        return $product['name'];
                    }, $products);
                    echo esc_html(implode(', ', $product_names)); 
                    ?>
                </td>
                <td><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></td>
                <td class="review-status"><?php echo esc_html(ucfirst($status)); ?></td>
                <td class="actions">
                    <?php if ($status !== 'completed') : ?>
                        <button class="button send-whatsapp" 
                            data-phone="<?php echo esc_attr($phone); ?>"
                            data-products="<?php echo esc_attr(json_encode($products)); ?>"
                            data-order-id="<?php echo esc_attr($order_id); ?>"
                        >
                            <?php esc_html_e('Send Message', 'wc-whatsapp-reviews'); ?>
                        </button>
                        <button class="button mark-completed" 
                            data-order-id="<?php echo esc_attr($order_id); ?>"
                        >
                            <?php esc_html_e('Completed', 'wc-whatsapp-reviews'); ?>
                        </button>
                    <?php else : ?>
                        <span class="completed-text"><?php esc_html_e('Review Completed', 'wc-whatsapp-reviews'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }
    
    /**
     * Render pagination
     */
    private function render_pagination() {
        $args = array(
            'status' => 'completed',
            'limit' => -1,
            'return' => 'ids',
        );
        
        $all_orders = wc_get_orders($args);
        $total_orders = count($all_orders);
        $orders_per_page = 20;
        $total_pages = ceil($total_orders / $orders_per_page);
        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        
        if ($total_pages <= 1) {
            return;
        }
        
        echo '<span class="displaying-num">' . sprintf(
            _n('%s item', '%s items', $total_orders, 'wc-whatsapp-reviews'),
            number_format_i18n($total_orders)
        ) . '</span>';
        
        $page_links = paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $total_pages,
            'current' => $current_page,
            'type' => 'array'
        ));
        
        if ($page_links) {
            echo '<span class="pagination-links">' . join("\n", $page_links) . '</span>';
        }
    }
    
    /**
     * Format phone number for WhatsApp
     */
    private function format_phone_number($phone) {
        // Remove non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // WhatsApp requires the country code without the + sign
        // For Kenya numbers (assuming your numbers are Kenyan)
        if (strlen($phone) <= 9) {
            // Add Kenya country code (254) for local numbers
            $phone = '254' . ltrim($phone, '0');
        } else if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            // For numbers that start with 0, replace with country code
            $phone = '254' . substr($phone, 1);
        }
        
        return $phone;
    }
    
    /**
     * Mark review as complete (AJAX handler)
     */
    public function mark_review_complete() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-whatsapp-reviews-nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Check order ID
        if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
            wp_send_json_error('Missing order ID');
            exit;
        }
        
        $order_id = intval($_POST['order_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'whatsapp_review_status';
        
        $updated = $wpdb->update(
            $table_name,
            array('status' => 'completed'),
            array('order_id' => $order_id),
            array('%s'),
            array('%d')
        );
        
        if (false === $updated) {
            wp_send_json_error('Failed to update status');
            exit;
        }
        
        wp_send_json_success(array(
            'message' => 'Review marked as complete',
            'order_id' => $order_id
        ));
        
        exit;
    }
}

// Initialize the plugin
$wc_whatsapp_reviews = new WC_WhatsApp_Reviews();