<?php



if (!defined('ABSPATH')) {
    exit;
}


class SafepayGateway extends WC_Payment_Gateway
{

    public $icon;
    public $has_fields;
    public $supports;
    public $method_title;
    public $method_description;
    public $title;
    public $description;
    public $instructions;
    public $hide_for_non_admin_users;
    public $merchantId;
    public $securedKey;
    public $storeId;
    public $baseUrl;
    public $appEnv;
    public $siteUrl;

    public function exampleMethod() {
        // Custom method logic
        echo "hello world";
    }
    /**
     * Initialize Safepay gateway properties.
     */
    public function initialize_gateway_properties()
    {
      
        $this->icon = apply_filters('woocommerce_safepay_gateway_icon', '');
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->method_title = _x('Safepay', 'Safepay Gateway', 'woocommerce-safepay-gateway');
        $this->method_description = __('Pay via Credit / Debit Cards, Bank Accounts / Wallets', 'woocommerce-safepay-gateway');
    }

    /**
     * Initialize Safepay gateway options.
     */
    public function initialize_options()
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users');

        $this->merchantId = $this->get_option('merchant_id');
        $this->securedKey = $this->get_option('security_key');
        $this->storeId = $this->get_option('store_id');
        $this->baseUrl = $this->get_option('base_url');
        $this->appEnv = $this->get_option('app_env');
        $this->siteUrl = get_site_url();
    }

  
    /**
     * Unique id for the gateway.
     * @var string
     *
     */
    // Unique ID for the gateway
    public $id = 'safepay_gateway';
    public function __construct()
    {
     
        $this->initialize_options();
        // You can use other trait methods
        $this->initialize_gateway_properties();
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
        // add_action('woocommerce_order_status_changed', [$this, 'safepay_custom_update_order_status'], 10, 3);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'safepay_process_order_request']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        // add_action('woocommerce_api_safepay_request_redirect', [$this, 'safepay_gateway_request']);
        // add_action('woocommerce_api_safepay_gatewaycallback', [$this, 'safepay_payment_notification']);
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
        if ($this->id == $id) {
            $imagePath = sprintf("%s/assets/images/logo.svg", plugin_dir_url(dirname(__FILE__)));
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
       
           ob_start(); $order = wc_get_order($order_id);

     


        $order->payment_complete();

        // Remove cart
        WC()->cart->empty_cart();
        ob_end_flush();
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
  
    public function process_order_place()
    {
        
        ob_start();
        $OrderId = absint(get_query_var('order-received'));
        // Get the order object
        $order = wc_get_order($OrderId);
        // Get the payment method
        $payment_method ="";
        if($order)
           $payment_method = $order->get_payment_method() ;

        if (is_order_received_page() && ('safepay_gateway' == $payment_method) && $order->status != 'failed') {
            $safepayApiHandler = new SafepayAPIHandler();
          
             // Prepare the arguments for the API call
             $args["amount"] = (int) ($order->get_total() * 100) ?? 0;
             $args["intent"] = "CYBERSOURCE";
             $args["mode"] = "payment";
             $args["currency"] = get_woocommerce_currency() ?? 'PKR';
             $args["merchant_api_key"] = $this->merchantId;
             $args['order_id'] = $OrderId;
             $args['source'] = 'woocommerce';
     
             // Get the base URL for the environment
             $baseURL = $this->get_env_url();
     
             // Call the API to get the token
             list($success, $userToken, $result) =   $safepayApiHandler->fetchToken( $this->securedKey,$args,$baseURL);
            print_r($result);
            // Get the tracker token from the result
            $tracker = $result['data']['tracker']['token'];
    
      
            // Get the site URL
            $this->siteUrl = get_site_url();
            // Extract the user token from the result
            $userToken = $userToken['data'];
            // Prepare the URLs for success, failure, and backend callback
            $site_url = sprintf("%s/index.php/wp-json/safepay/v1/safepay-transaction-success/%s?", get_site_url(), $order->get_id());
            $successUrl = $site_url . "&order_id=" . $order->get_id() . '&ispaid=true';
            $failUrl =  sprintf("%s/index.php/wp-json/safepay/v1/safepay-transaction-failed/%s?", get_site_url(), $order->get_id()) . "&order_id=" . $order->get_id() . '&ispaid=false';
            $backend_callback = $site_url . "order_id=" . $order->get_id();
    
            // Get the current date and time
            $orderDate = date('Y-m-d H:i:s', time());
    
            // Get the base URL for the redirect
            $url = $this->get_env_url();
    
            // Check if on the order received page and the payment method is 'safepay_gateway'
            if (is_order_received_page() && ('safepay_gateway' == $payment_method)) {
                // If API call was successful
                if (isset($success) && $success) {
                    // Get the token from the result
                    $token = $result['token'] ?? null;
                    // If token is present, stop further execution
                    if ($token)
                        return die();
                    // Prepare the redirect URL with necessary parameters
                    $requestRedirectUrl = $url . "/embedded/?tbt=$userToken&tracker=$tracker&order_id=$OrderId&environment=$this->appEnv&source=woocommerce&redirect_url=$successUrl&cancel_url=$failUrl";
    
                    // Redirect to the prepared URL
                    wp_redirect($requestRedirectUrl);
                    ob_end_flush();
                    die();
                    
                } else {
                    // Log API call failure if WP_DEBUG is enabled
                    if (WP_DEBUG) {
                        error_log("API call failed: " . $result);
                    }
                    wp_redirect(get_site_url());
                    ob_end_flush();
                    die();
                }
            }
 

        
        }
    }


    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-safepay-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Safepay Payment Gateway', 'woocommerce-safepay-gateway'),
                'description' => __('Enable or disable the gateway.', 'woocommerce-safepay-gateway'),
                'desc_tip' => false,
                'default' => 'yes'
            ),
            'app_env' => array(
                'title' => __('Environment', 'woocommerce-safepay-gateway'),
                'type' => 'select',
                'label' => __('Environment', 'woocommerce-safepay-gateway'),
                'description' => __('Choose the environment.', 'woocommerce-safepay-gateway'),
                'desc_tip' => false,
                'default' => 'yes',
                'options' => array(
                    'development' => __('Development', 'woocommerce-safepay-gateway'),
                    'sandbox' => __('Sandbox', 'woocommerce-safepay-gateway'),
                    'production' => __('Production', 'woocommerce-safepay-gateway')
                )
            ),
            'title' => array(
                'title' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'description' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'Safepay checkout'
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Description', 'woocommerce-safepay-gateway'),
                'description' => __('Description', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'Pay via Credit / Debit Cards, Bank Accounts / Wallets'
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Registered Merchant ID at Safepay', 'woocommerce-safepay-gateway'),
                'description' => __('Registered Merchant ID at Safepay.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'security_key' => array(
                'title' => __('Merchant Secured Key', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Merchant\'s security key.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'base_url' => array(
                'title' => __('Gateway Base URL', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Gateway Base URL', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'https://www.getsafepay.pk'
            ),
          'production_webhook_secret' => array(
                'title' => __('Production Webhook Secret', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' =>
                    // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                    __('Using webhook secret keys allows Safepay to verify each payment. To get your live webhook key:')
                    . '<br /><br />' .

                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __('1. Navigate to your Live Safepay dashboard by clicking <a target="_blank" href="https://getsafepay.com/dashboard/webhooks">here</a>')

                    . '<br />' .

                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf(__('2. Click \'Add an endpoint\' and paste the following URL: %s', 'Safepay'), add_query_arg('/wp-json/safepay/v1/order-webhook?', 'WC_Safepay', get_site_url()))

                    . '<br />' .

                    // translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
                    __('3. Make sure to select "Send me all events", to receive all payment updates.', 'Safepay')

                    . '<br />' .

                    // translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
                    __('4. Click "Show shared secret" and paste into the box above.', 'Safepay'),

                'desc_tip' => false,
                'default' => sprintf("%s/wp-json/safepay/v1/order-webhook", get_site_url()),
            ),
            'sandbox_webhook_secret' => array(
                'title' => __('Sandbox Webhook Secret', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' =>
                    // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                    __('Using webhook secret keys allows Safepay to verify each payment. To get your live webhook key:')
                    . '<br /><br />' .

                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __('1. Navigate to your Live Safepay dashboard by clicking <a target="_blank" href="https://getsafepay.com/dashboard/webhooks">here</a>')

                    . '<br />' .

                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf(__('2. Click \'Add an endpoint\' and paste the following URL: %s', 'Safepay'), add_query_arg('/wp-json/safepay/v1/order-webhook/', 'WC_Safepay', get_site_url()))

                    . '<br />' .

                    // translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
                    __('3. Make sure to select "Send me all events", to receive all payment updates.', 'Safepay')

                    . '<br />' .

                    // translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
                    __('4. Click "Show shared secret" and paste into the box above.', 'Safepay'),

                'desc_tip' => false,
                'default' => sprintf("%s/wp-json/safepay/v1/order-webhook", get_site_url()),
            ),
        );
    }



}