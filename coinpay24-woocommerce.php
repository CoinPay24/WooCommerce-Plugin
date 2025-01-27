
<?php
/*
Plugin Name: CoinPay24 Payment Gateway for WooCommerce
Description: A custom payment gateway for WooCommerce to integrate with CoinPay24.
Version: 1.0
Author: CoinPay24
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'coinpay24_woocommerce_init_gateway_class');

function coinpay24_woocommerce_init_gateway_class() {
    class WC_Gateway_CoinPay24 extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'coinpay24';
            $this->method_title = __('CoinPay24', 'coinpay24');
            $this->method_description = __('Accept cryptocurrency payments via CoinPay24.', 'coinpay24');
            $this->supports = array('products');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_key = $this->get_option('api_key');
            $this->callback_url = add_query_arg('wc-api', 'wc_gateway_coinpay24', home_url('/'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_coinpay24', array($this, 'handle_callback'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'coinpay24'),
                    'type' => 'checkbox',
                    'label' => __('Enable CoinPay24 Payment Gateway', 'coinpay24'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'coinpay24'),
                    'type' => 'text',
                    'description' => __('This controls the title shown during checkout.', 'coinpay24'),
                    'default' => __('CoinPay24', 'coinpay24'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'coinpay24'),
                    'type' => 'textarea',
                    'description' => __('This controls the description shown during checkout.', 'coinpay24'),
                    'default' => __('Pay using cryptocurrencies via CoinPay24.', 'coinpay24'),
                ),
                'api_key' => array(
                    'title' => __('API Key', 'coinpay24'),
                    'type' => 'text',
                    'description' => __('Enter your CoinPay24 API key.', 'coinpay24'),
                    'default' => '',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $data = array(
                'api_key' => $this->api_key,
                'order_id' => $order_id,
                'price_amount' => $order->get_total(),
                'price_currency' => get_woocommerce_currency(),
                'title' => 'Order #' . $order_id,
                'callback_url' => $this->callback_url,
                'cancel_url' => $order->get_cancel_order_url(),
                'success_url' => $this->get_return_url($order),
            );

            $url = 'https://api.coinpay24.com/v1/invoices/create';
            $response = wp_remote_post($url, array(
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
                'body' => json_encode($data),
                'timeout' => 45,
            ));

            if (is_wp_error($response)) {
                wc_add_notice(__('Payment error:', 'coinpay24') . $response->get_error_message(), 'error');
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['payment_url'])) {
                return array(
                    'result' => 'success',
                    'redirect' => $body['payment_url'],
                );
            } else {
                wc_add_notice(__('Payment error: ', 'coinpay24') . $body['error_message'], 'error');
                return;
            }
        }

        public function handle_callback() {
            $postData = $_POST;

            if (!isset($postData['verify_hash'])) {
                status_header(400);
                exit('Invalid Callback');
            }

            $generatedHash = hash_hmac('sha256', http_build_query($postData), $this->api_key);

            if ($generatedHash !== $postData['verify_hash']) {
                status_header(400);
                exit('Invalid Hash');
            }

            $order_id = $postData['order_id'];
            $order = wc_get_order($order_id);

            if ($postData['status'] === 'completed') {
                $order->payment_complete();
                $order->add_order_note(__('Payment completed via CoinPay24.', 'coinpay24'));
                status_header(200);
                exit('Callback Handled');
            } else {
                $order->update_status('failed', __('Payment failed or was not completed.', 'coinpay24'));
                status_header(200);
                exit('Callback Handled');
            }
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_coinpay24_gateway_class');
function add_coinpay24_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_CoinPay24';
    return $gateways;
}
?>
