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
            $icon_url = apply_filters( 'cs_an_icon_url', plugins_url( 'assets/images/credits.png', dirname(__FILE__) ) );
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
		
        /**
         * Check if this gateway is enabled and all dependencies are fine.
         * Disable the plugin if dependencies fail.
         *
         * @access      public
         * @return      bool
         */
        public function is_available() {
            global $csan4wc;

            if ( $this->enabled === 'no' ) {
                return false;
            }

            // Authorize.Net won't work without login id and transaction keys (both test and live)
			if ($csan4wc->settings['testmode'] === 'yes') {
                if ( ! $csan4wc->settings['test_login_id'] && ! $csan4wc->settings['test_transaction_key'] ) {
                    return false;
                }
            } else {
                if ( ! $csan4wc->settings['live_login_id'] && ! $csan4wc->settings['live_transaction_key'] ) {
                    return false;
                }
            }

            //Disable plugin if we don't use ssl
            if ( ! is_ssl() && $csan4wc->settings['testmode'] === 'no' ) {
                return false;
            }

            // Allow smaller orders to process for WooCommerce Bookings
            if ( is_checkout_pay_page() ) {
                $order_key = urldecode( $_GET['key'] );
                $order_id  = absint( get_query_var( 'order-pay' ) );
                $order     = new WC_Order( $order_id );

                if ( $order->id == $order_id && $order->order_key == $order_key && $this->get_order_total() * 100 < 50) {
                    return false;
                }
            }

            return true;
        }
		
        /**
         * Send notices to users if requirements fail, or for any other reason
         *
         * @access      public
         * @return      bool
         */
        public function admin_notices() {
            global $csan4wc, $pagenow, $wpdb;

            if ( $this->enabled == 'no') {
                return false;
            }

            // Authorize.Net won't work without login id and transaction keys (both test and live)
			if ($csan4wc->settings['testmode'] === 'yes') {
                if ( ! $csan4wc->settings['test_login_id'] && ! $csan4wc->settings['test_transaction_key'] ) {
                    echo '<div class="error"><p>' . __( 'Authorize.Net needs test login id & transaction keys to work, Please fill those informations.' ) . '</p></div>';
                    return false;
                }
            } else {
                if ( ! $csan4wc->settings['live_login_id'] && ! $csan4wc->settings['live_transaction_key'] ) {
                    echo '<div class="error"><p>' . __( 'Authorize.Net needs login id & transaction keys to work, Please fill those informations.' ) . '</p></div>';
                    return false;
                }
            }
			
            // Force SSL on production
            if ( $this->settings['testmode'] == 'no' && get_option( 'woocommerce_force_ssl_checkout' ) == 'no' ) {
                    echo '<div class="error"><p>' . __( 'Authorize.Net needs SSL in order to be secure. Read mode about forcing SSL on checkout in <a href="http://docs.woothemes.com/document/ssl-and-https/" target="_blank">the WooCommerce docs</a>.', 'cs-authorizenet-for-woocommerce' ) . '</p></div>';
                    return false;
            }

            // Add notices for admin page
            if ( $pagenow === 'admin.php' ) {
                $options_base = 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( get_class( $this ) );

                if ( ! empty( $_GET['action'] ) && ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'cs_an4wc_action' ) ) {

                    // Delete all test data
                    if ( $_GET['action'] === 'delete_test_data' ) {

                        // Delete test data if the action has been confirmed
                        if ( ! empty( $_GET['confirm'] ) && $_GET['confirm'] === 'yes' ) {

                            $result = $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_cs_an4wc_test_customer_info' ) );

                            if ( $result !== false ) :
                        ?>
                                <div class="updated">
                                    <p><?php _e( 'Authorize.Net Test Data successfully deleted.', 'cs-authorizenet-for-woocommerce' ); ?></p>
                                </div>
                        <?php
                            else :
                        ?>
                                <div class="error">
                                    <p><?php _e( 'Unable to delete Authorize.Net Test Data', 'cs-authorizenet-for-woocommerce' ); ?></p>
                                </div>
                        <?php
                            endif;
                        }

                        // Ask for confimation before we actually delete data
                        else {
                        ?>
                            <div class="error">
                                <p><?php _e( 'Are you sure you want to delete all test data? This action cannot be undone.', 'cs-authorizenet-for-woocommerce' ); ?></p>
                                <p>
                                    <a href="<?php echo wp_nonce_url( admin_url( $options_base . '&action=delete_test_data&confirm=yes' ), 'cs_an4wc_action' ); ?>" class="button"><?php _e( 'Delete', 'cs-authorizenet-for-woocommerce' ); ?></a>
                                    <a href="<?php echo admin_url( $options_base ); ?>" class="button"><?php _e( 'Cancel', 'cs-authorizenet-for-woocommerce' ); ?></a>
                                </p>
                            </div>
                        <?php
                        }
                    }
                }
            }
		}

	}
}