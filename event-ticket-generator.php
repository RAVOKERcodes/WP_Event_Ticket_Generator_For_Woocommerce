<?php
/*
Plugin Name: Event Ticket Generator
Description: Generates event tickets with QR codes after WooCommerce product purchase and displays them for the user.
Version: 1.2
Author: Dhruv Shridhar
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Hook into WooCommerce order status change to 'completed'
    add_action('woocommerce_order_status_completed', 'generate_event_ticket', 10, 1);

    function generate_event_ticket($order_id) {
        if (!$order_id) {
            return;
        }

        // Get the WooCommerce order object
        $order = wc_get_order($order_id);
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            // Check if the product is virtual
            if ($product && $product->is_virtual()) {
                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $ticket_data = $order_id . '|' . $customer_name;

                // Generate QR code URL
                $qr_code_url = generate_qr_code($ticket_data);

                // Debug: Log the QR code URL and order ID
                error_log('Order ID: ' . $order_id . ' - QR Code URL: ' . $qr_code_url);

                // Save QR code URL to order meta
                $order->update_meta_data('_qr_code_url', $qr_code_url);
                $order->save();
            }
        }
    }

    // Function to generate QR code URL using a third-party service
    function generate_qr_code($data) {
        $encoded_data = urlencode($data);
        return 'https://api.qrserver.com/v1/create-qr-code/?data=' . $encoded_data . '&size=150x150';
    }

    // Display the ticket on the WooCommerce 'order-received' page
    add_action('woocommerce_thankyou', 'display_ticket_on_order_received', 10, 1);
    function display_ticket_on_order_received($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $qr_code_url = $order->get_meta('_qr_code_url');
            if ($qr_code_url) {
                echo '<h2>Your Event Ticket</h2>';
                echo '<p>Thank you for your purchase! Here is your event ticket:</p>';
                echo '<img src="' . esc_url($qr_code_url) . '" alt="QR Code">';
            }
        }
    }

    // Create a default page for viewing all tickets when the plugin is activated
    register_activation_hook(__FILE__, 'create_ticket_view_page');
    function create_ticket_view_page() {
        if (get_page_by_title('My Event Tickets') === NULL) {
            $page_data = array(
                'post_title' => 'My Event Tickets',
                'post_content' => '[display_tickets]',
                'post_status' => 'publish',
                'post_type' => 'page'
            );
            wp_insert_post($page_data);
        }
    }

    // Shortcode to display tickets for logged-in users
    add_shortcode('display_tickets', 'display_user_tickets');
    function display_user_tickets() {
        if (!is_user_logged_in()) {
            return 'Please log in to view your tickets.';
        }

        $current_user_id = get_current_user_id();
        $customer_orders = wc_get_orders(array(
            'customer_id' => $current_user_id,
            'status' => array('completed'),
            'limit' => -1,
        ));

        if (empty($customer_orders)) {
            return 'No tickets found.';
        }

        $output = '<ul>';
        foreach ($customer_orders as $order) {
            $qr_code_url = $order->get_meta('_qr_code_url');
            if ($qr_code_url) {
                $output .= '<li><strong>Order #' . esc_html($order->get_id()) . ':</strong><br>';
                $output .= '<img src="' . esc_url($qr_code_url) . '" alt="QR Code"><br></li>';
            }
        }
        $output .= '</ul>';

        return $output;
    }
}
?>
