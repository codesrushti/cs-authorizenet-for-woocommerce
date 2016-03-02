<?php
/*
 * Plugin Name: Authorize.Net for WooCommerce - Code Srushti.
 * Plugin URI: http://codesrushti.com/category/wordpress-plugins
 * Description: Use this Plugin for collecting credit card payments on WooCommerce System.
 * Version: 1.0
 * Author: Velmurugan Kuberan, Thiyagarajan P & Vinothkumar Parthasarathy
 * Author URI: http://codesrushti.com/category/wordpress-plugins
 * Requires at least: 3.8
 * Tested up to: 4.4
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Special thanks to : Stephen Zuniga // http://stephenzuniga.com
 * Foundation built by: Sean Voss // https://github.com/seanvoss/striper
 */

/*
 * Short Forms Explanations
 * cs => codesrushti
 * an => Authorize.Net
 */
 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists('CS_AuthorizeNet4WC' )) {
    
    class CS_AuthorizeNet4WC {
        public function __construct() {
            global $wpdb;

			// Include AuthorizeNet Methods
            include_once( 'classes/class-cs-an4wc_api.php' ); 

			// Include Database Manipulation Methods
            include_once( 'classes/class-cs-an4wc_db.php' );

			// Include Customer Profile Methods
            include_once( 'classes/class-cs-an4wc_customer.php' );
			
            // Grab settings
            $this->settings = get_option( 'cs_an4wc_settings', array() );

            // Add default values for fresh installs
            $this->settings['testmode']             = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : 'yes';
            $this->settings['test_login_id']        = isset( $this->settings['test_login_id'] ) ? $this->settings['test_login_id'] : '';
            $this->settings['test_transaction_key'] = isset( $this->settings['test_transaction_key'] ) ? $this->settings['test_transaction_key'] : '';
            $this->settings['live_login_id']        = isset( $this->settings['live_login_id'] ) ? $this->settings['live_login_id'] : '';
            $this->settings['live_transaction_key'] = isset( $this->settings['live_transaction_key'] ) ? $this->settings['live_transaction_key'] : '';
            $this->settings['saved_cards']          = isset( $this->settings['saved_cards'] ) ? $this->settings['saved_cards'] : 'yes';

            // Database info location
            $this->settings['cs_an_db_location']    = $this->settings['testmode'] == 'yes' ? '_cs_an_test_customer_info' : '_cs_an_live_customer_info';

            // Hooks
            add_filter( 'woocommerce_payment_gateways', array( $this, 'add_cs_an_gateway' ) );
            add_action( 'woocommerce_order_status_processing_to_completed', array( $this, 'order_status_completed' ) );

            // Localization
            load_plugin_textdomain( 'cs-authorizenet-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
			
		}

		/**
         * Add AuthorizeNet Gateway to WooCommerces list of Gateways
         *
         * @access      public
         * @param       array $methods
         * @return      array
         */
		public function add_cs_an_gateway( $methods ) {
            if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
                return;
            }

            // Include payment gateway
            include_once( 'classes/class-cs-an4wc_gateway.php' );

            if ( class_exists( 'WC_Subscriptions_Order' ) ) {
                include_once( 'classes/class-cs-an4wc_subscriptions_gateway.php' );

                $methods[] = 'CS_AuthorizeNet4WC_Subscriptions_Gateway';
            } else {
                $methods[] = 'CS_AuthorizeNet4WC_Gateway';
            }

            return $methods;
        }

		/**
		 * Localize AuthorizeNet error messages
		 *
		 * @access      protected
		 * @param       Exception $e
		 * @return      string
		 */
		public function get_error_message( $e ) {

				switch ( $e->getMessage() ) {

                    // Messages from Stripe API
                    case 'incorrect_number':
                        $message = __( 'Your card number is incorrect.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'invalid_number':
                        $message = __( 'Your card number is not a valid credit card number.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'invalid_expiry_month':
                        $message = __( 'Your card\'s expiration month is invalid.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'invalid_expiry_year':
                        $message = __( 'Your card\'s expiration year is invalid.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'invalid_cvc':
                        $message = __( 'Your card\'s security code is invalid.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'expired_card':
                        $message = __( 'Your card has expired.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'incorrect_cvc':
                        $message = __( 'Your card\'s security code is incorrect.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'incorrect_zip':
                        $message = __( 'Your zip code failed validation.', 'cs-authorizenet-for-woocommerce' );
                        break;
                    case 'card_declined':
                        $message = __( 'Your card was declined.', 'cs-authorizenet-for-woocommerce' );
                        break;

                    // Messages from S4WC
                    case 'cs_an4wc_problem_connecting':
                    case 'cs_an4wc_empty_response':
                    case 'cs_an4wc_invalid_response':
                        $message = __( 'There was a problem connecting to the payment gateway.', 'cs-authorizenet-for-woocommerce' );
                        break;

                    // Generic failed order
                    default:
                        $message = __( 'Failed to process the order, please try again later.', 'cs-authorizenet-for-woocommerce' );
			    }

			    return $message;
	    }

        /**
         * Process the captured payment when changing order status to completed
         *
         * @access      public
         * @param       int $order_id
         * @return      mixed
         */
        public function order_status_completed( $order_id = null ) {

            if ( ! $order_id ) {
                $order_id = $_POST['order_id'];
            }

            // `_s4wc_capture` added in 1.35, let `capture` last for a few more updates before removing
            if ( get_post_meta( $order_id, '_cs_an4wc_capture', true ) || get_post_meta( $order_id, 'capture', true ) ) {

                $order = new WC_Order( $order_id );
                $params = array(
                    'amount' => isset( $_POST['amount'] ) ? $_POST['amount'] : $order->order_total * 100,
                    'expand[]' => 'balance_transaction',
                );

                try {
                    $charge = S4WC_API::capture_charge( $order->transaction_id, $params );

                    if ( $charge ) {
                        $order->add_order_note(
                            sprintf(
                                __( '%s payment captured.', 'cs-authorizenet-for-woocommerce' ),
                                get_class( $this )
                            )
                        );

                        // Save Stripe fee
                        if ( isset( $charge->balance_transaction ) && isset( $charge->balance_transaction->fee ) ) {
                            $stripe_fee = number_format( $charge->balance_transaction->fee / 100, 2, '.', '' );
                            update_post_meta( $order_id, 'Authorize.Net Fee', $stripe_fee );
                        }
                    }
                } catch ( Exception $e ) {
                    $order->add_order_note(
                        sprintf(
                            __( '%s payment failed to capture. %s', 'cs-authorizenet-for-woocommerce' ),
                            get_class( $this ),
                           $this->get_error_message( $e )
                        )
                    );
                }
            }
        }

	}
}