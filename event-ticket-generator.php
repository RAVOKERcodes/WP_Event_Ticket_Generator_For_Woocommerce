<?php
/**
 * Plugin Name: Event Ticket Generator with QR for WooCommerce
 * Description: Generates event tickets with QR codes for WooCommerce virtual products and provides features such as custom templates, expiry dates, validation, and email reminders.
 * Version: 3.0.1
 * Author: Dhruv Shridhar
 */

// Generate tickets with QR for each product in the order
add_action('woocommerce_order_status_completed', 'generate_event_ticket_per_product', 10, 1);
function generate_event_ticket_per_product($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    $expiry_date = date('Y-m-d H:i:s', strtotime('+30 days'));

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->is_virtual()) {
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $ticket_data = $order_id . '|' . $customer_name . '|' . $item->get_id(); // Unique ticket data
            $qr_code_url = generate_qr_code($ticket_data);
            $item->update_meta_data('_qr_code_url', $qr_code_url);
            $item->update_meta_data('_ticket_expiry_date', $expiry_date);
        }
    }
    $order->save();
}

// Generate QR code URL function
function generate_qr_code($data) {
    $encoded_data = urlencode($data);
    return 'https://api.qrserver.com/v1/create-qr-code/?data=' . $encoded_data . '&size=150x150';
}

// Display ticket per product on the 'order-received' page
add_action('woocommerce_thankyou', 'display_ticket_on_order_received', 10, 1);
function display_ticket_on_order_received($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        echo '<h2>Your Event Tickets</h2>';
        foreach ($order->get_items() as $item) {
            $qr_code_url = $item->get_meta('_qr_code_url');
            $expiry_date = $item->get_meta('_ticket_expiry_date');
            if ($qr_code_url) {
                echo '<div class="ticket">';
                echo '<p><strong>Product:</strong> ' . esc_html($item->get_name()) . '</p>';
                echo '<p><strong>Expiry Date:</strong> ' . esc_html($expiry_date) . '</p>';
                echo '<img src="' . esc_url($qr_code_url) . '" alt="QR Code"><br>';
                echo '</div>';
            }
        }
    }
}

// Add a shortcode to display user tickets
add_shortcode('my_event_tickets', 'display_user_tickets');
function display_user_tickets() {
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your tickets.</p>';
    }

    $user_id = get_current_user_id();
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => 'completed',
    ));

    if (empty($orders)) {
        return '<p>No tickets found.</p>';
    }

    $output = '<h2>Your Event Tickets</h2>';
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $qr_code_url = $item->get_meta('_qr_code_url');
            $expiry_date = $item->get_meta('_ticket_expiry_date');

            if ($qr_code_url) {
                $output .= '<div class="ticket">';
                $output .= '<p><strong>Product:</strong> ' . esc_html($item->get_name()) . '</p>';
                $output .= '<p><strong>Expiry Date:</strong> ' . esc_html($expiry_date) . '</p>';
                $output .= '<img src="' . esc_url($qr_code_url) . '" alt="QR Code">';
                $output .= '</div>';
            }
        }
    }

    return $output;
}

// Add a ticket validation page in the admin dashboard
add_action('admin_menu', 'add_ticket_validation_page');
function add_ticket_validation_page() {
    add_submenu_page(
        'woocommerce', // Parent menu (WooCommerce)
        'Validate Ticket', // Page title
        'Ticket Validation', // Menu title
        'manage_options', // Capability required to access the page
        'validate-ticket', // Menu slug
        'ticket_validation_page' // Callback function to display the page content
    );
}

// Callback function for displaying the ticket validation form
function ticket_validation_page() {
    if (isset($_POST['validate_ticket'])) {
        $ticket_id = sanitize_text_field($_POST['ticket_id']);
        $validation_result = validate_ticket($ticket_id);

        if ($validation_result) {
            echo '<div class="notice notice-success"><p>' . $validation_result . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Invalid or not found ticket ID.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Validate Ticket</h1>
        <form method="post" action="">
            <label for="ticket_id">Enter Ticket ID or Scan QR:</label><br>
            <input type="text" name="ticket_id" id="ticket_id" required>
            <input type="submit" name="validate_ticket" class="button-primary" value="Validate Ticket">
        </form>
    </div>
    <?php
}

// Function to validate a ticket by ID or QR data
function validate_ticket($ticket_id) {
    $args = array(
        'post_type' => 'shop_order',
        'post_status' => 'wc-completed',
        'posts_per_page' => -1,
    );

    $orders = get_posts($args);

    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);

        foreach ($order->get_items() as $item) {
            if ($item->get_id() == $ticket_id || $item->get_meta('_qr_code_url') == $ticket_id) {
                $product_name = $item->get_name();
                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $expiry_date = $item->get_meta('_ticket_expiry_date');
                $current_date = date('Y-m-d H:i:s');

                if ($expiry_date && $current_date > $expiry_date) {
                    return "Ticket ID $ticket_id for $product_name (Customer: $customer_name) has expired.";
                } else {
                    return "Ticket ID $ticket_id for $product_name (Customer: $customer_name) is valid and active.";
                }
            }
        }
    }

    return false;
}

// Add an admin page to list all sold tickets
add_action('admin_menu', 'add_ticket_dashboard_menu');
function add_ticket_dashboard_menu() {
    add_submenu_page(
        'woocommerce', // Parent menu (WooCommerce)
        'Sold Event Tickets', // Page title
        'Sold Tickets', // Menu title
        'manage_options', // Capability required to access the page
        'sold-event-tickets', // Menu slug
        'sold_event_tickets_page' // Callback function to display the page content
    );
}

// Callback function to display sold tickets in the admin dashboard
function sold_event_tickets_page() {
    ?>
    <div class="wrap">
        <h1>Sold Event Tickets</h1>
        <p>Below is a list of all sold event tickets.</p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer Name</th>
                    <th>Product Name</th>
                    <th>Ticket ID</th>
                    <th>Expiry Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $args = array(
                    'post_type' => 'shop_order',
                    'post_status' => 'wc-completed',
                    'posts_per_page' => -1,
                );

                $orders = get_posts($args);

                foreach ($orders as $order_post) {
                    $order = wc_get_order($order_post->ID);

                    foreach ($order->get_items() as $item) {
                        $ticket_id = $item->get_id();
                        $qr_code_url = $item->get_meta('_qr_code_url');
                        $expiry_date = $item->get_meta('_ticket_expiry_date');
                        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                        $product_name = $item->get_name();

                        if ($qr_code_url) {
                            echo '<tr>';
                            echo '<td>' . esc_html($order->get_id()) . '</td>';
                            echo '<td>' . esc_html($customer_name) . '</td>';
                            echo '<td>' . esc_html($product_name) . '</td>';
                            echo '<td>' . esc_html($ticket_id) . '</td>';
                            echo '<td>' . esc_html($expiry_date) . '</td>';
                            echo '</tr>';
                        }
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>