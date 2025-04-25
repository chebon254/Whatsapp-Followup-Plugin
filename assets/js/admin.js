jQuery(document).ready(function($) {
    // Store the current filter status globally
    let currentFilterStatus = 'all';
    
    // Handle Send WhatsApp Message button
    $('.send-whatsapp').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const phone = button.data('phone');
        const products = button.data('products');
        const orderId = button.data('order-id');
        
        // Construct the WhatsApp message
        let message = "😊We'd love to hear your feedback about the product(s) you bought, here on whatsapp or the same on our product page review section";
        
        // Add product information to the message
        if (products && products.length > 0) {
            products.forEach(product => {
                const productUrl = window.location.origin + '/?p=' + product.id;
                message += "\n\n• " + product.name + " - " + productUrl;
            });
        }
        
        message += "\n\nThank you!";
        
        // Encode the message for URL
        const encodedMessage = encodeURIComponent(message);
        
        // Create WhatsApp desktop app URL (whatsapp:// protocol)
        const whatsappUrl = "whatsapp://send?phone=" + phone + "&text=" + encodedMessage;
        
        // Open WhatsApp desktop app using the protocol
        window.location.href = whatsappUrl;
    });
    
    // Handle Mark as Completed button
    $('.mark-completed').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const orderId = button.data('order-id');
        const row = button.closest('tr');
        
        // Disable the button while processing
        button.prop('disabled', true).text('Processing...');
        
        // Send Ajax request to mark review as complete
        $.ajax({
            url: wcWhatsAppReviews.ajax_url,
            type: 'POST',
            data: {
                action: 'mark_review_complete',
                order_id: orderId,
                nonce: wcWhatsAppReviews.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the UI
                    row.find('.review-status').text('Completed');
                    
                    // Replace buttons with completed text
                    const actionsCell = row.find('.actions');
                    actionsCell.html('<span class="completed-text">Review Completed</span>');
                    
                    // Show success notification
                    showNotification('Success', 'Review marked as complete', 'success');
                } else {
                    // Re-enable the button on error
                    button.prop('disabled', false).text('Completed');
                    
                    // Show error notification
                    showNotification('Error', response.data || 'Failed to update status', 'error');
                }
            },
            error: function() {
                // Re-enable the button on error
                button.prop('disabled', false).text('Completed');
                
                // Show error notification
                showNotification('Error', 'An error occurred while processing your request', 'error');
            }
        });
    });
    
    // Handle filter button
    $('#filter-submit').on('click', function(e) {
        e.preventDefault();
        currentFilterStatus = $('#filter-order-status').val();
        loadOrdersTable(1, currentFilterStatus);
    });
    
    // Handle search button
    $('#search-submit').on('click', function(e) {
        e.preventDefault();
        searchCustomers();
    });
    
    // Handle search on Enter key
    $('#search-customer').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            searchCustomers();
        }
    });
    
    // Function to search customers
    function searchCustomers() {
        const searchTerm = $('#search-customer').val().trim();
        
        if (searchTerm === '') {
            // If search is empty, reload the table with current filter
            loadOrdersTable(1, currentFilterStatus);
            return;
        }
        
        // Show loading indicator
        $('#wc-whatsapp-reviews-table-body').html('<tr><td colspan="7">Searching...</td></tr>');
        
        // Hide pagination when showing search results
        $('#wc-whatsapp-reviews-pagination').empty();
        
        // Send Ajax request to search customers
        $.ajax({
            url: wcWhatsAppReviews.ajax_url,
            type: 'POST',
            data: {
                action: 'search_whatsapp_reviews',
                search: searchTerm,
                nonce: wcWhatsAppReviews.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the table with search results
                    $('#wc-whatsapp-reviews-table-body').html(response.data.table_html);
                    
                    // Re-initialize event handlers for new elements
                    initEventHandlers();
                } else {
                    // Show error message
                    $('#wc-whatsapp-reviews-table-body').html(
                        '<tr><td colspan="7">Error searching customers: ' + 
                        (response.data || 'Unknown error') + '</td></tr>'
                    );
                }
            },
            error: function() {
                // Show error message
                $('#wc-whatsapp-reviews-table-body').html(
                    '<tr><td colspan="7">An error occurred while searching customers</td></tr>'
                );
            }
        });
    }
    
    // Function to load orders table with pagination
    function loadOrdersTable(page, status) {
        // Store the status parameter for pagination
        if (status) {
            currentFilterStatus = status;
        }
        
        // Show loading indicator
        $('#wc-whatsapp-reviews-table-body').html('<tr><td colspan="7">Loading...</td></tr>');
        
        // Send Ajax request to filter orders
        $.ajax({
            url: wcWhatsAppReviews.ajax_url,
            type: 'POST',
            data: {
                action: 'filter_whatsapp_reviews',
                status: currentFilterStatus,
                page: page,
                nonce: wcWhatsAppReviews.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the table with filtered results
                    $('#wc-whatsapp-reviews-table-body').html(response.data.table_html);
                    $('#wc-whatsapp-reviews-pagination').html(response.data.pagination_html);
                    
                    // Re-initialize event handlers for new elements
                    initEventHandlers();
                    initPaginationHandlers();
                } else {
                    // Show error message
                    $('#wc-whatsapp-reviews-table-body').html(
                        '<tr><td colspan="7">Error loading orders: ' + 
                        (response.data || 'Unknown error') + '</td></tr>'
                    );
                }
            },
            error: function() {
                // Show error message
                $('#wc-whatsapp-reviews-table-body').html(
                    '<tr><td colspan="7">An error occurred while loading orders</td></tr>'
                );
            }
        });
    }
    
    // Initialize pagination handlers
    function initPaginationHandlers() {
        // Handle pagination clicks
        $('#wc-whatsapp-reviews-pagination a').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            const status = $(this).data('status') || currentFilterStatus;
            loadOrdersTable(page, status);
        });
    }
    
    // Initialize pagination on page load
    initPaginationHandlers();
    
    // Helper function to initialize event handlers for dynamically added elements
    function initEventHandlers() {
        // Re-attach event handlers for Send WhatsApp button
        $('.send-whatsapp').off('click').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const phone = button.data('phone');
            const products = button.data('products');
            const orderId = button.data('order-id');
            
            // Construct the WhatsApp message
            let message = "😊We'd love to hear your feedback about the product(s) you bought, here on whatsapp or the same on our product page review section";
            
            // Add product information to the message
            if (products && products.length > 0) {
                products.forEach(product => {
                    const productUrl = window.location.origin + '/?p=' + product.id;
                    message += "\n\n• " + product.name + " - " + productUrl;
                });
            }
            
            message += "\n\nThank you!";
            
            // Encode the message for URL
            const encodedMessage = encodeURIComponent(message);
            
            // Create WhatsApp desktop app URL (whatsapp:// protocol)
            const whatsappUrl = "whatsapp://send?phone=" + phone + "&text=" + encodedMessage;
            
            // Open WhatsApp desktop app using the protocol
            window.location.href = whatsappUrl;
        });
        
        // Re-attach event handlers for Mark as Completed button
        $('.mark-completed').off('click').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const orderId = button.data('order-id');
            const row = button.closest('tr');
            
            // Disable the button while processing
            button.prop('disabled', true).text('Processing...');
            
            // Send Ajax request to mark review as complete
            $.ajax({
                url: wcWhatsAppReviews.ajax_url,
                type: 'POST',
                data: {
                    action: 'mark_review_complete',
                    order_id: orderId,
                    nonce: wcWhatsAppReviews.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update the UI
                        row.find('.review-status').text('Completed');
                        
                        // Replace buttons with completed text
                        const actionsCell = row.find('.actions');
                        actionsCell.html('<span class="completed-text">Review Completed</span>');
                        
                        // Show success notification
                        showNotification('Success', 'Review marked as complete', 'success');
                    } else {
                        // Re-enable the button on error
                        button.prop('disabled', false).text('Completed');
                        
                        // Show error notification
                        showNotification('Error', response.data || 'Failed to update status', 'error');
                    }
                },
                error: function() {
                    // Re-enable the button on error
                    button.prop('disabled', false).text('Completed');
                    
                    // Show error notification
                    showNotification('Error', 'An error occurred while processing your request', 'error');
                }
            });
        });
    }
    
    // Helper function to show notifications
    function showNotification(title, message, type) {
        // Create notification element
        const notification = $('<div class="wc-whatsapp-reviews-notification ' + type + '">' +
            '<strong>' + title + '</strong>' +
            '<p>' + message + '</p>' +
            '<span class="close-notification">×</span>' +
            '</div>');
        
        // Add notification to the page
        $('body').append(notification);
        
        // Show notification with animation
        setTimeout(function() {
            notification.addClass('show');
        }, 10);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 5000);
        
        // Close button handler
        notification.find('.close-notification').on('click', function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        });
    }
});