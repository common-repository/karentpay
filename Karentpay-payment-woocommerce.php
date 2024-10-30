<?php

/*
Plugin Name: KarentPay
Description: A WooCommerce plugin for Bangladesh Payment Gateway solution using KarentPay API.
Version: 1.0.0
Author: Bangladeshisoftware
License: GPLv2 or later
Author URI: https://www.bangladeshisoftware.com
© 2024 | All Rights Reserved by Bangladeshi Software™
*/



add_action('plugins_loaded', 'karentpay_init');

function karentpay_init()
{
    // Ensure WC_Payment_Gateway class is loaded
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Define the KarentPay payment gateway class
    class WC_Gateway_KarentPay extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'karentpay';
            $this->method_title = 'KarentPay';
            $this->method_description = 'Integrate with KarentPay payment gateway.';
            $this->icon = apply_filters('karentpay_icon', plugins_url('assets/logo.png', __FILE__));
            $this->has_fields = false;

            // Initialize form fields and settings
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');

            // Save admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        }

        // Define the settings form fields
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable KarentPay',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'KarentPay'
                ),
                'api_endpoint' => array(
                    'title' => 'API Endpoint',
                    'type' => 'text',
                    'description' => 'The endpoint URL for the KarentPay API.',
                    'default' => 'https://api.karentpay.com/api/v1/create_payment'
                ),
                'x_secret_key' => array(
                    'title' => 'X-SECRET-KEY',
                    'type' => 'text',
                    'description' => 'The secret key for authentication.',
                    'default' => ''
                )
            );
        }

        // Process the payment
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $response = $this->make_payment_request($order);

            if (is_wp_error($response)) {
                wc_add_notice(__('Payment error:', 'woocommerce') . $response->get_error_message(), 'error');
                return;
            }

            $payment_url = isset($response->data->payment_url) ? $response->data->payment_url : '';

            if (!$payment_url) {
                wc_add_notice(__('Payment error: Invalid payment URL.', 'woocommerce'), 'error');
                return;
            }

            // Mark as on-hold (waiting for payment)
            $order->update_status('on-hold', __('Awaiting KarentPay payment', 'woocommerce'));

            // Return success and redirect to payment URL
            return array(
                'result' => 'success',
                'redirect' => $payment_url
            );
        }

        // Display the receipt page
        public function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay with KarentPay.', 'woocommerce') . '</p>';
        }

        // Send payment request to KarentPay API
        private function make_payment_request($order)
        {
            $api_endpoint = $this->get_option('api_endpoint');
            $x_secret_key = $this->get_option('x_secret_key');

            // Correctly format the `product` field as a JSON string
            $product_data = array(
                'order_number' => $order->get_order_number(),
                'items' => array()
            );

            foreach ($order->get_items() as $item_id => $item) {
                $product_data['items'][] = array(
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total()
                );
            }

            $body = json_encode(array(
                'currency' => 'BDT',
                'amount' => $order->get_total(),
                'reference' => $order->get_order_number() . '-' . time(),
                'callback_url' => home_url() . "/index.php/karentpay/callback",
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
                'customer_address' => $order->get_billing_address_1(),
                'product' => json_encode($product_data),
                'note' => ''
            ));

            $response = wp_remote_post($api_endpoint, array(
                'method' => 'POST',
                'body' => $body,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-SECRET-KEY' => $x_secret_key
                )
            ));

            // Logging request and response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('KarentPay Request Body: ' . $body);
                error_log('KarentPay Response: ' . print_r($response, true));
            }

            if (is_wp_error($response)) {
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            $response_data = json_decode($response_body);

            if (isset($response_data->data->payment_url)) {
                return $response_data;
            } else {
                return new WP_Error('payment_error', __('Invalid response from KarentPay API.', 'woocommerce'));
            }
        }
    }

    // Add KarentPay to WooCommerce payment gateways
    function add_karentpay_gateway($methods)
    {
        $methods[] = 'WC_Gateway_KarentPay';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_karentpay_gateway');

    // Callback handler
    add_action('init', 'karentpay_callback_handler');

    function karentpay_callback_handler()
    {

        if (isset($_GET['status']) && isset($_GET['reference'])) {
            $status = sanitize_text_field($_GET['status']);
            $reference = sanitize_text_field($_GET['reference']);

            $order_id = explode('-', $reference)[0];

            $order = wc_get_order($order_id);
            if ($order) {
                switch ($status) {
                    case 'Success':
                        $order->update_status('completed', __('Payment received, order completed.', 'woocommerce'));
                        $redirect_url = home_url('/index.php/thankyou'); // Redirect to the thank you page
                        break;

                    case 'Pending':
                        $order->update_status('on-hold', __('Awaiting payment confirmation.', 'woocommerce'));
                        $redirect_url = home_url('/index.php/pending'); // Redirect to the pending page
                        break;

                    case 'Failed':
                        $order->update_status('failed', __('Payment failed, order canceled.', 'woocommerce'));
                        $redirect_url = home_url('/index.php/failed'); // Redirect to the failed page
                        break;

                    default:
                        // Handle unexpected status
                        error_log('Unexpected status received: ' . $status);
                        $redirect_url = home_url('/index.php/unexpected'); // Redirect to the home page in case of unexpected status
                        break;
                }
            } else {
                error_log('Order not found for reference: ' . $reference);
                $redirect_url = home_url('/index.php/not-found'); // Redirect to the home page if the order is not found
            }

            // Redirect to the appropriate URL
            wp_redirect($redirect_url);
            exit;
        }
    }
}