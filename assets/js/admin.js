/**
 * Admin JavaScript for WP WhatsApp Order Follow-up
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Handle WhatsApp message button clicks
        $('.send-whatsapp-btn').on('click', function (e) {
            var recordId = $(this).data('record-id');

            // Send AJAX request to log the message
            $.ajax({
                type: 'POST',
                url: wpwaf_vars.ajaxurl,
                data: {
                    action: 'log_whatsapp_message',
                    record_id: recordId,
                    nonce: wpwaf_vars.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Increment the message count in the UI
                        var countCell = $(e.target).closest('tr').find('td:nth-child(4)');
                        var countParts = countCell.text().split('/');
                        var newCount = parseInt(countParts[0]) + 1;
                        countCell.text(newCount + '/4');

                        // Update last sent time
                        $(e.target).closest('tr').find('td:nth-child(5)').text('just now');

                        // If we reached the limit, replace the button
                        if (newCount >= 4) {
                            $(e.target).replaceWith('<span class="message-limit-reached">Message limit reached</span>');
                        }
                    }
                }
            });
        });
    });

    // Handle manual override button
    $('.mark-commented-btn').on('click', function (e) {
        e.preventDefault();

        var recordId = $(this).data('record-id');
        var row = $(this).closest('tr');

        if (confirm('Are you sure you want to mark this order as commented?')) {
            $.ajax({
                type: 'POST',
                url: wpwaf_vars.ajaxurl,
                data: {
                    action: 'mark_as_commented_manually',
                    record_id: recordId,
                    nonce: wpwaf_vars.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Remove the row or update its status
                        row.fadeOut(400, function () {
                            row.remove();
                        });
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                }
            });
        }
    });

    // Handle import orders button
    $('#import-orders-btn').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var statusSpan = $('#import-status');

        if (confirm('Do you want to import all existing completed orders?')) {
            // Disable button and show loading
            button.prop('disabled', true).text('Importing...');
            statusSpan.text('Please wait...').show();

            $.ajax({
                type: 'POST',
                url: wpwaf_vars.ajaxurl,
                data: {
                    action: 'import_existing_orders',
                    nonce: wpwaf_vars.nonce
                },
                success: function (response) {
                    if (response.success) {
                        statusSpan.text(response.data.message);

                        // If orders were imported, reload the page after 2 seconds
                        if (response.data.count > 0) {
                            setTimeout(function () {
                                location.reload();
                            }, 2000);
                        } else {
                            button.prop('disabled', false).text('Import Existing Orders');
                        }
                    } else {
                        statusSpan.text('Error: ' + response.data.message);
                        button.prop('disabled', false).text('Import Existing Orders');
                    }
                },
                error: function () {
                    statusSpan.text('Error: Server error occurred');
                    button.prop('disabled', false).text('Import Existing Orders');
                }
            });
        }
    });

})(jQuery);