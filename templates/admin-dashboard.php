<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;
?>

<div class="wrap wpwaf-dashboard">
    <h1><?php _e('WhatsApp Order Follow-up', 'wp-whatsapp-followup'); ?></h1>
    <div class="import-section">
        <button id="import-orders-btn" class="button button-primary">
            <?php _e('Import Existing Orders', 'wp-whatsapp-followup'); ?>
        </button>
        <span id="import-status" style="margin-left: 10px; display: none;"></span>
    </div>
    
    <div class="nav-tab-wrapper">
        <a href="?page=whatsapp-followup&filter=not_commented" class="nav-tab <?php echo $filter === 'not_commented' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Need Follow-up', 'wp-whatsapp-followup'); ?>
        </a>
        <a href="?page=whatsapp-followup&filter=commented" class="nav-tab <?php echo $filter === 'commented' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Completed', 'wp-whatsapp-followup'); ?>
        </a>
    </div>
    
    <div class="analytics-summary">
        <div class="analytics-box">
            <h3><?php _e('Total Orders', 'wp-whatsapp-followup'); ?></h3>
            <p class="analytics-number"><?php echo $analytics['total']; ?></p>
        </div>
        <div class="analytics-box">
            <h3><?php _e('Awaiting Comments', 'wp-whatsapp-followup'); ?></h3>
            <p class="analytics-number"><?php echo $analytics['not_commented']; ?></p>
        </div>
        <div class="analytics-box">
            <h3><?php _e('Conversion Rate', 'wp-whatsapp-followup'); ?></h3>
            <p class="analytics-number"><?php echo $analytics['conversion_rate']; ?>%</p>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Order', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Customer', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Product(s)', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Messages Sent', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Last Sent', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Status', 'wp-whatsapp-followup'); ?></th>
                <th><?php _e('Actions', 'wp-whatsapp-followup'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records)): ?>
                <tr>
                    <td colspan="7"><?php _e('No records found.', 'wp-whatsapp-followup'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $record->order_id . '&action=edit'); ?>" target="_blank">
                                #<?php echo $record->order_id; ?>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html($record->customer_email); ?><br>
                            <?php echo esc_html($record->customer_phone); ?>
                        </td>
                        <td>
                            <?php 
                            $product_ids = explode(',', $record->product_ids);
                            foreach ($product_ids as $product_id) {
                                $product = wc_get_product($product_id);
                                if ($product) {
                                    echo '<a href="' . get_permalink($product_id) . '" target="_blank">' 
                                         . esc_html($product->get_name()) . '</a><br>';
                                }
                            }
                            ?>
                        </td>
                        <td><?php echo $record->messages_sent; ?>/4</td>
                        <td>
                            <?php echo $record->last_message_date ? human_time_diff(strtotime($record->last_message_date), current_time('timestamp')) . ' ' . __('ago', 'wp-whatsapp-followup') : __('Never', 'wp-whatsapp-followup'); ?>
                        </td>
                        <td>
                            <?php if ($record->has_commented): ?>
                                <span class="status-badge status-complete"><?php _e('Commented', 'wp-whatsapp-followup'); ?></span>
                            <?php else: ?>
                                <span class="status-badge status-pending"><?php _e('Pending', 'wp-whatsapp-followup'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$record->has_commented && $record->messages_sent < 4): ?>
                                <?php 
                                $whatsapp_link = $this->generate_whatsapp_link(
                                    $record->customer_phone, 
                                    $record->order_id, 
                                    $record->product_ids
                                ); 
                                ?>
                                <a href="<?php echo esc_url($whatsapp_link); ?>" 
                                   class="button send-whatsapp-btn" 
                                   data-record-id="<?php echo $record->id; ?>"
                                   target="_blank">
                                    <?php _e('Send WhatsApp', 'wp-whatsapp-followup'); ?>
                                </a>
                                <a href="#" 
                                class="button button-secondary mark-commented-btn" 
                                data-record-id="<?php echo $record->id; ?>">
                                    <?php _e('Mark as Commented', 'wp-whatsapp-followup'); ?>
                                </a>
                            <?php elseif ($record->messages_sent >= 4): ?>
                                <span class="message-limit-reached"><?php _e('Message limit reached', 'wp-whatsapp-followup'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>