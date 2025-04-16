<?php
/**
 * Database handler for WP WhatsApp Order Follow-up
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

class WP_WhatsApp_Database {
    
    /**
     * Table name for follow-up records
     * @var string
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'whatsapp_followup';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_phone varchar(20) NOT NULL,
            product_ids text NOT NULL,
            messages_sent int(11) DEFAULT 0,
            last_message_date datetime DEFAULT NULL,
            has_commented tinyint(1) DEFAULT 0,
            comment_date datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Insert new follow-up record
     * 
     * @param int $order_id
     * @param string $customer_email
     * @param string $customer_phone
     * @param array $product_ids
     * @return int|false The row ID or false on error
     */
    public function insert_record($order_id, $customer_email, $customer_phone, $product_ids) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'order_id' => $order_id,
                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,
                'product_ids' => implode(',', $product_ids),
                'messages_sent' => 0,
                'has_commented' => 0
            )
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update message sent count
     * 
     * @param int $record_id
     * @return bool
     */
    public function increment_message_count($record_id) {
        global $wpdb;
        
        $current_count = $wpdb->get_var($wpdb->prepare(
            "SELECT messages_sent FROM {$this->table_name} WHERE id = %d",
            $record_id
        ));
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'messages_sent' => $current_count + 1,
                'last_message_date' => current_time('mysql')
            ),
            array('id' => $record_id)
        );
        
        return $result !== false;
    }
    
    /**
     * Mark record as commented with improved matching
     * 
     * @param string $email
     * @param int $product_id
     * @return bool
     */
    public function mark_as_commented($email, $product_id) {
        global $wpdb;
        
        // First try an exact match
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
            SET has_commented = 1, comment_date = %s
            WHERE customer_email = %s 
            AND FIND_IN_SET(%d, product_ids)",
            current_time('mysql'),
            $email,
            $product_id
        ));
        
        if ($result > 0) {
            return true;
        }
        
        // If no exact match, try a case-insensitive match
        // This helps with email capitalization differences
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
            SET has_commented = 1, comment_date = %s
            WHERE LOWER(customer_email) = LOWER(%s) 
            AND FIND_IN_SET(%d, product_ids)",
            current_time('mysql'),
            $email,
            $product_id
        ));
        
        return $result > 0;
    }
    
    /**
     * Get records by filter
     * 
     * @param string $filter 'commented' or 'not_commented'
     * @return array
     */
    public function get_records($filter = 'not_commented') {
        global $wpdb;
        
        $where = $filter === 'commented' ? 'has_commented = 1' : 'has_commented = 0';
        
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where}
             ORDER BY last_message_date DESC"
        );
    }
    
    /**
     * Get analytics data
     * 
     * @return array
     */
    public function get_analytics() {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $not_commented = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE has_commented = 0");
        $commented = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE has_commented = 1");
        
        $conversion_rate = $total > 0 ? round(($commented / $total) * 100, 1) : 0;
        
        return array(
            'total' => $total,
            'not_commented' => $not_commented,
            'commented' => $commented,
            'conversion_rate' => $conversion_rate
        );
    }
    
    /**
     * Get record by ID
     * 
     * @param int $record_id
     * @return object|null
     */
    public function get_record($record_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $record_id
        ));
    }
}