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
    public $merchantApiKey;
    public $merchantSecret;
    public $storeId;
    public $baseUrl;
    public $appEnv;
    public $siteUrl;


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

        $this->merchantApiKey = $this->get_option('merchant_api_key');
        $this->merchantSecret = $this->get_option('merchant_secret_key');
        $this->storeId = $this->get_option('store_id');
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
        add_action('woocommerce_receipt_' . $this->id, [$this, 'safepay_process_order_request']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
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

    public function validate_webhook($parameters)
    {
        $merchant_api_key =$parameters['merchant_api_key'] ?? null;
        // Ensure the signature header exists
        if (!isset($merchant_api_key)) {
            return false;
        }

        $merchantApiKey = $this->merchantApiKey;
        // Compare signatures
        if (hash_equals($merchant_api_key, $merchantApiKey)) {
            return true;
        }

        // Optionally log signature mismatches for debugging
        error_log('Webhook signature validation failed.');

        return false;
    }

     function safepay_handle_webhook($request)
    {
        ob_start();

        // Get the body data from the request
        $parameters = $request->get_json_params();
        // Validate the webhook signature
        if (!$this->validate_webhook($parameters)) {
            return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 403));
        }

        

        // Validate and process the body data
        if (empty($parameters) || !isset($parameters['data'])) {
            return new WP_Error('no_data', 'No data provided', array('status' => 400));
        }

        $data = $parameters['data'];

        // Sanitize and validate fields
        $tracker = sanitize_text_field($data['tracker'] ?? '');
        $state = sanitize_text_field($data['state'] ?? '');
        $metadata = $data['metadata'] ?? array();

        // Fetch Order ID from metadata
        $OrderId = absint($metadata['order_id'] ?? 0);
        $order = wc_get_order($OrderId);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 400));
        }

        // Handle payment state
        if ($state === 'TRACKER_ENDED') {
            $order_note_message = 'Payment has been received successfully. Transaction reference ID: ' . $tracker;
            $order->payment_complete();
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

    public function prepareApiArguments($order)
    {
        return [
            "amount" => (int) ($order->get_total() * 100),  // Convert to smallest currency unit
            "intent" => "CYBERSOURCE",
            "mode" => "payment",
            "currency" => get_woocommerce_currency() ?? 'PKR',  // Default to PKR if no currency is set
            "merchant_api_key" => $this->merchantApiKey,
            "order_id" => $order->get_id(),
            "source" => 'woocommerce'
        ];
    }

    public function prepareRedirectUrl($order, $userToken, $tracker)
    {
        // Store site URL and order ID to avoid multiple calls
        $siteUrl = get_site_url();
        $order_id = $order->get_id();
        // Prepare URLs for success and failure
        // $baseCallbackUrl = sprintf("%s/index.php/wp-json/safepay/v1/safepay-transaction-%s/%s?", $siteUrl, $order_id);
        $redirect_url = esc_url_raw($order->get_checkout_order_received_url()); // Use esc_url_raw for encoding URLs without outputting them immediately
        $cancel_url = esc_url_raw($order->get_cancel_order_url());
        // Construct the request redirect URL
        return sprintf(
            '%s/embedded/?tbt=%s&tracker=%s&order_id=%s&environment=%s&source=woocommerce&redirect_url=%s&cancel_url=%s',
            esc_url($this->appEnv == 'production' ? SafepayEndpoints::PRODUCTION_URL->value : $this->get_env_url()),
            esc_html($userToken),
            esc_html($tracker),
            esc_html($order_id),
            esc_html($this->appEnv),
            urlencode($redirect_url),
            urlencode($cancel_url)
        );
    }
    public function generateSafepayRedirect($order)
    {
        $safepayApiHandler = new SafepayAPIHandler();
        // Prepare arguments for API call
        $args = self::prepareApiArguments($order);
        // Get the base URL for the environment
        $baseURL = self::get_env_url();
        // Call the API to get the token
        list($success, $userToken, $result) = $safepayApiHandler->fetchToken($this->merchantSecret, $args, $baseURL);
        $tracker = $result['data']['tracker']['token'] ?? null;
        // Proceed if the API call was successful
        if ($success && !empty($tracker)) {
            // Get tracker token and user token
            $userToken = $userToken['data'] ?? null;
            // If user token is missing, handle error
            return self::prepareRedirectUrl($order, $userToken, $tracker);
        } else {
            // Handle API failure or missing tracker token
            wp_die('Error: Your session has expired. Please refresh the page and try again');
            return null;
        }
    }
    public function process_payment($order_id)
    {

   
        $order = wc_get_order($order_id);
        // Get the payment method
        $payment_method = "";
        if ($order)
            $payment_method = $order->get_payment_method();

        if ($order && ('safepay_gateway' == $payment_method) && $order->status != 'failed') {
            ob_start();
            // Redirect to the prepared URL
            $requestRedirectUrl = self::generateSafepayRedirect($order);
            $order->update_status('pending', 'Order is awaiting payment'); // Set the status to "pending payment"
            $order->save();
            ob_end_flush();
            WC()->cart->empty_cart();
            return array(
                'result' => 'success',
                'redirect' => $requestRedirectUrl,
            );
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
            'merchant_api_key' => array(
                'title' => __('Merchant API Key', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Registered Merchant API Key at Safepay', 'woocommerce-safepay-gateway'),
                'description' => __('Registered Merchant API Key at Safepay.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'merchant_secret_key' => array(
                'title' => __('Merchant Secured Key', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Merchant\'s security key.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'production_webhook_secret' => array(
                'title' => __('Webhook URL', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' =>
                // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                __('Using webhook secret keys allows Safepay to verify each payment. To get your live webhook key:')
                    . '<br /><br />' .

                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __('1. Navigate to your Live Safepay dashboard by clicking <a target="_blank" href="https://getsafepay.com/dashboard/webhooks">here</a>')

                    . '<br />' .

                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf(__('2. Click \'Add an endpoint\' and paste the following URL: %s', 'Safepay'), add_query_arg('/wp-json/safepay/v1/order-webhook?', '', get_site_url()))

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
