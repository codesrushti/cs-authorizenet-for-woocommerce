<?php
/**
 * Functions for interfacing with Authorize.Net's API
 *
 * @class       CS_AuthorizeNet_API
 * @author      Velmurugan Kuberan
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists('CS_AuthorizeNet_API' )) {
    class CS_AuthorizeNet_API {
        public static $sanbox_api_endpoint = 'https://apitest.authorize.net/xml/v1/request.api';
		public static $live_api_endpoint = 'https://api.authorize.net/xml/v1/request.api';
	}
}