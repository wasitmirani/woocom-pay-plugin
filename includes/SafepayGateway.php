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


}