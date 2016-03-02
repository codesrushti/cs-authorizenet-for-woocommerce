<?php
/**
 * Authorize.Net Gateway
 *
 * Provides a Authorize.Net Payment Gateway.
 *
 * @class       CS_AuthorizeNet_Gateway
 * @extends     WC_Payment_Gateway
 * @package     WooCommerce/Classes/Payment
 * @author      Vinothkumar Parthasarathy
 *
 * Special thanks to : Stephen Zuniga // http://stephenzuniga.com
 * Foundation built by: Sean Voss // https://github.com/seanvoss/striper
 *
 */
 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists('CS_AuthorizeNet_Gateway' )) {
    class  CS_AuthorizeNet_Gateway extends WC_Payment_Gateway {
        protected $order                     = null;
        protected $form_data                 = null;
        protected $transaction_id            = null;
        protected $transaction_error_message = null;
    
	    public function __construct() {
            global $csan4wc;

            $this->id           = 'csan4wc';
            $this->method_title = 'Authorize.Net for WooCommerce';
            $this->has_fields   = true;
            $this->supports     = array(
                'default_credit_card_form',
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'refunds'
            );

            // Init settings
            $this->init_form_fields();
            $this->init_settings();

            // Use settings
            $this->enabled     = $this->settings['enabled'];
            $this->title       = $this->settings['title'];
            $this->description = $this->settings['description'];

            // Get current user information
            $this->authorizenet_customer_info = get_user_meta( get_current_user_id(), $csan4wc->settings['cs_an_db_location'], true );

            // Add an icon with a filter for customization
            $icon_url = apply_filters( 'cs_an__icon_url', plugins_url( 'assets/images/credits.png', dirname(__FILE__) ) );
            if ( $icon_url ) {
                $this->icon = $icon_url;
            }

            // Hooks
            add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'admin_notices', array( $this, 'admin_notices' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );
            add_action( 'woocommerce_credit_card_form_start', array( $this, 'before_cc_form' ) );
            add_action( 'woocommerce_credit_card_form_end', array( $this, 'after_cc_form' ) );
	    }

	}
}
