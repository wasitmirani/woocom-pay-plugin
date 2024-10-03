<?php

/*
  Plugin Name:  Safepay for WooCommerce
  Plugin URI:   https://github.com/getsafepay/safepay-woocommerce
  Description:  Safepay Payment Gateway Integration for WooCommerce.
  Version:      2.2
  Author:       Team Safepay
  Author URI:   https://getsafepay.com
  License:      GPL-2.0+
  License URI:  http://www.gnu.org/licenses/gpl-2.0.txt
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safepay WooCommerce Payment Integration
 * 
 * @class SafepayPaymentGateway
 */
class SafepayPaymentGateway {

    /**
     * Initialize the plugin.
     */
    public static function safepay_init() {
        add_action('plugins_loaded', array(__CLASS__, 'safepay_includes'), 0);
        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'safepay_add_gateway'));
        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'safepay_woocommerce_block_support'));
        add_action('safepay_wc_process_payment_order_status', array(__CLASS__, 'safepay_pending_order_status'));
        add_action('init', 'safepay_register_order_status');
        add_filter('wc_order_statuses', 'safepay_custom_order_status');
    }

    /**
     * Add Safepay gateway to WooCommerce gateways.
     */
    public static function safepay_add_gateway($gateways) {
        $options = get_option('safepay_gateway_settings', array());

        $hide_for_non_admin_users = isset($options['hide_for_non_admin_users']) ? $options['hide_for_non_admin_users'] : 'no';

        if (( 'yes' === $hide_for_non_admin_users && current_user_can('manage_options')) || 'no' === $hide_for_non_admin_users) {
            $gateways[] = 'Safepay_WC_Gateway';
        }

        return $gateways;
    }

    /**
     * Plugin includes.
     */
    public static function safepay_includes() {
        if (class_exists('WC_Payment_Gateway')) {
            require_once 'includes/safepay-gateway.php';
            // require_once 'includes/class-safepay-wc-request.php';
            // require_once 'includes/class-safepay-wc-response.php';
        }
    }

    /**
     * Get plugin URL.
     *
     * @return string
     */
    public static function safepay_plugin_url() {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get plugin absolute path.
     *
     * @return string
     */
    public static function safepay_plugin_abspath() {
        return trailingslashit(plugin_dir_path(__FILE__));
    }

}

function safepay_register_order_status() {
    register_post_status('wc-payment-received', array(
        'label'                     => 'Payment Received',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Payment Received <span class="count">(%s)</span>', 'Payment Received <span class="count">(%s)</span>')
    ));
}

function safepay_custom_order_status($order_statuses) {
    $order_statuses['wc-payment-received'] = 'Payment Received';
    return $order_statuses;
}

// Initialize Safepay Plugin
SafepayPaymentGateway::safepay_init();
