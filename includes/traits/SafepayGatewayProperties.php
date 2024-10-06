<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

trait SafepayGatewayProperties
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
}
