<?php

if (!defined('ABSPATH')) {
    exit;
}

class SafepayGateway extends WC_Payment_Gateway
{

    use SafepayGatewayProperties;
    /**
     * Payment gateway instructions.
     * @var string
     *
     */
    protected $instructions;
    /**
     * Whether the gateway is visible for non-admin users.
     * @var boolean
     *
     */
    protected $hide_for_non_admin_users;
    /**
     * Unique id for the gateway.
     * @var string
     *
     */
    // Unique ID for the gateway
    public $id = 'safepay_gateway';
    public function __construct()
    {
        $this->initialize_gateway_properties();
        $this->initialize_options();
        $this->register_hooks();
        $this->register_rest_routes();
    }
    /**
     * Register WordPress/WooCommerce hooks and filters.
     */
    private function register_hooks()
    {
        add_filter('woocommerce_gateway_icon', [$this, 'safepay_display_woocommerce_icons'], 10, 2);
        add_filter('woocommerce_gateway_title', [$this, 'safepay_payment_method_title'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'safepay_custom_update_order_status'], 10, 3);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'safepay_process_order_request']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_safepay_request_redirect', [$this, 'safepay_gateway_request']);
        add_action('woocommerce_api_safepay_gatewaycallback', [$this, 'safepay_payment_notification']);
        add_action('wp', [$this, 'process_order_place']);
    }
    /**
     * Register REST API routes.
     */
    private function register_rest_routes()
    {
        $namespace = 'safepay/v1';
        $permission_callback = '__return_true';
        add_action('rest_api_init', function () use ($namespace, $permission_callback) {
            $routes = [
                [
                    'route' => '/safepay-transaction-success/(?P<order_id>\d+)',
                    'methods' => 'GET',
                    'callback' => [$this, 'safepay_get_order_success_url'],
                ],
                [
                    'route' => '/safepay-transaction-failed/(?P<order_id>\d+)',
                    'methods' => 'GET',
                    'callback' => [$this, 'safepay_get_order_failed_url'],
                ],
                [
                    'route' => '/order-webhook',
                    'methods' => 'POST',
                    'callback' => [$this, 'safepay_handle_webhook'],
                ],
            ];
            foreach ($routes as $route) {
                register_rest_route($namespace, $route['route'], [
                    'methods' => $route['methods'],
                    'callback' => $route['callback'],
                    'permission_callback' => $permission_callback,
                ]);
            }
        });
    }


    function safepay_display_woocommerce_icons($icon, $id)
    {

        if ($this->id === $id) {
            $imagePath = sprintf("%s/assets/images/card.svg", plugin_dir_url(dirname(__FILE__)));
            $icon = '<img width="25%" src="' . $imagePath . '" alt="safepay" />';
        }
        return $icon;
    }

    
    function safepay_payment_method_title($title, $id)
    {

        if ($this->id === $id) {
            $title = $this->title;
        }

        return $title;
    }

    function my_custom_temporary_page_endpoint()
    {
        add_rewrite_endpoint('my-temp-page', EP_ROOT | EP_PAGES);
    }
    private function get_env_url()
    {
        switch ($this->appEnv) {
            case 'development':
                return  SafepayEndpoints::DEVELOPMENT_BASE_URL->value; // Replace with actual development URL
            case 'sandbox':
                return SafepayEndpoints::SANDBOX_BASE_URL->value; // Replace with actual staging URL
            default:
                return SafepayEndpoints::PRODUCTION_BASE_URL->value; 
                // Replace with actual production  URL
        }
    }

    public function is_valid_for_use()
    {
        return in_array(
            get_woocommerce_currency(),
            apply_filters(
                'woocommerce_paypal_supported_currencies',
                array('PKR', 'USD', 'GBP', 'AED', 'EUR', 'CAD', 'SAR')
            ),
            true
        );
    }


    private function payfast_payment_token() {

        $tokeurl = $this->tokenUrl;
        $tokeurl .= "?MERCHANT_ID=" . $this->gateway->merchant_id . "&SECURED_KEY=" . $this->gateway->security_key;
        return SafepayAPIHandler::fetchToken($tokeurl);
    }


    function safepay_handle_webhook($request)
    {
        ob_start();

        // Get the body data from the request
        $parameters = $request->get_json_params();
    
        // Validate and process the body data
        if (empty($parameters) || !isset($parameters['data'])) {
            return new WP_Error('no_data', 'No data provided', array('status' => 400));
        }
    
        $data = $parameters['data'];
    
        // Sanitize and validate fields
        $tracker = sanitize_text_field($data['tracker'] ?? '');
        $intent = sanitize_text_field($data['intent'] ?? '');
        $state = sanitize_text_field($data['state'] ?? '');
        $net = intval($data['net'] ?? 0);
        $fee = intval($data['fee'] ?? 0);
        $customer_email = sanitize_email($data['customer_email'] ?? '');
        $amount = intval($data['amount'] ?? 0);
        $currency = sanitize_text_field($data['currency'] ?? 'PKR');
        $metadata = $data['metadata'] ?? array();
        $charged_at_seconds = intval($data['charged_at']['seconds'] ?? 0);
        $charged_at_nanos = intval($data['charged_at']['nanos'] ?? 0);
    
        // Fetch Order ID from metadata
        $OrderId = absint($metadata['order_id'] ?? 0);
        $order = wc_get_order($OrderId);
    
        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 400));
        }
    
        // Handle payment state
        if ($state === 'TRACKER_ENDED') {
            $order->update_status('payment-received');
            $order_note_message = 'Payment has been received successfully. Transaction reference ID: ' . $tracker;
        } else {
            $order->update_status('failed');
            $order_note_message = 'Payment has failed. Transaction reference ID: ' . $tracker;
        }
    
        // Update order note and meta data
        $order->add_order_note($order_note_message);
        // Optionally update meta data: $order->update_meta_data('_transaction_ref_id', $tracker);
        $order->save();

        ob_end_flush();
        return array(
            'result' => ($state === 'TRACKER_ENDED') ? 'success' : 'failed',
            'redirect' => $this->get_return_url($order)
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        echo $order_id;
    }

    public function process_order_place()
    {
            ob_start();
		    $OrderId = absint(get_query_var('order-received'));
            // Get the order object
            $order = wc_get_order($OrderId);

    }



}