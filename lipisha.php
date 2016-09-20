<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/*
Plugin Name: Woocommerce Lipisha Credit Card
Plugin URI: https://github.com/moshthepitt/woocommerce-lipisha-credit-card
Description: Allows use of payments with credit cards via Kenyan payment processor Lipisha - https://lipisha.com
Version: 0.1
Author: Kelvin Jayanoris
Author URI: http://jayanoris.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Copyright 2016  Kelvin Jayanoris 

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 3, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USAv
*/
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	// Hooks for adding/ removing the database table, and the wpcron to check them
	register_activation_hook(__FILE__, 'woocommerce_lipisha_cc_install');
	register_uninstall_hook(__FILE__, 'lipisha_cc_on_uninstall');

	define('WOOCOMMERCE_LIPISHA_CC_PLUGIN_VERSION', "0.1");
	define('WOOCOMMERCE_LIPISHA_CC_PLUGIN_URL', plugin_dir_url(__FILE__));
	define('WOOCOMMERCE_LIPISHA_CC_PLUGIN_DIR', WP_PLUGIN_DIR.'/'.dirname(plugin_basename(__FILE__)));	

	define('WOOCOMMERCE_LIPISHA_CC_AUTH_CARD_URL', "https://lipisha.com/payments/accounts/index.php/v2/api/authorize_card_transaction");
	define('WOOCOMMERCE_LIPISHA_CC_TEST_AUTH_CARD_URL', "http://developer.lipisha.com/index.php/v2/api/authorize_card_transaction");

	define('WOOCOMMERCE_LIPISHA_CC_COMPLETE_CARD_URL', "https://lipisha.com/payments/accounts/index.php/v2/api/complete_card_transaction");
	define('WOOCOMMERCE_LIPISHA_CC_TEST_COMPLETE_CARD_URL', "http://developer.lipisha.com/index.php/v2/api/complete_card_transaction");

	function woocommerce_lipisha_cc_install() {
	  global $wpdb;

	  $card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
	  $complete_card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_complete_card_transaction"; 

	  $charset_collate = $wpdb->get_charset_collate();

	  $sql = "
	  CREATE TABLE $card_transaction_table_name (
	    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	    `order_id` int(11) unsigned NOT NULL,
	    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	    `transaction_index` varchar(255) NOT NULL,
	    `transaction_reference` varchar(255) NOT NULL,
	    `status` varchar(50) NOT NULL,
	    `status_code` varchar(50) NOT NULL,
	    `processed` int(1) NOT NULL DEFAULT 0,
	    PRIMARY KEY (`id`),
	    UNIQUE KEY `order_id` (`order_id`)
	  ) $charset_collate;";

		$sql = $sql . "
		CREATE TABLE $complete_card_transaction_table_name (
		  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		  `transaction_index` varchar(255) NOT NULL,
	    `transaction_reference` varchar(255) NOT NULL,
	    `status` varchar(50) NOT NULL,
	    `status_code` varchar(50) NOT NULL,
		  PRIMARY KEY (`id`)
		) $charset_collate;";

	  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	  dbDelta($sql);
	}

	function lipisha_cc_on_uninstall()	{
	  // Clean up i.e. delete the table, wp_cron already removed on deacivate
	  global $wpdb;
	  $card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
	  $complete_card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_complete_card_transaction";  
	  $wpdb->query("DROP TABLE IF EXISTS $card_transaction_table_name");
	  $wpdb->query("DROP TABLE IF EXISTS $complete_card_transaction_table_name");
	} 

	function lipisha_cc_cron_schedules($schedules){
    if(!isset($schedules["5min"])){
      $schedules["5min"] = array(
        'interval' => 10 * 60,
        'display' => __('Once every 5 minutes')
      );
    }
    return $schedules;
	}
	add_filter('cron_schedules','lipisha_cc_cron_schedules');

	function woocommerce_lipisha_cc_ipn_task() {
		$timestamp = wp_next_scheduled('woocommerce_lipisha_cc_ipn_reconciler');
		//If $timestamp == false it hasn't been done previously
	  if ($timestamp == false) {
	    //Schedule the event for right now, then to repeat every 5min
	    wp_schedule_event(time(), '5min', 'woocommerce_lipisha_cc_ipn_reconciler');
	  }
	}

	//Hook our function in
	add_action('woocommerce_lipisha_cc_ipn_reconciler', 'woocommerce_lipisha_cc_reconcile_ipn');
	function woocommerce_lipisha_cc_reconcile_ipn() {
		global $wpdb;
		$card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
		$complete_card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_complete_card_transaction"; 

		$authorized_records = $wpdb->get_results(
			"SELECT * FROM `$card_transaction_table_name`
				 WHERE processed = 0
				 ORDER BY created_at DESC
			"
		);

		if(!empty($authorized_records)) {
			foreach ($authorized_records as $authorized_record) {
				$this_order = wc_get_order($authorized_record->order_id);
				if (is_object($this_order) && ($this_order->get_status() == "pending" || $this_order->get_status() == 'on-hold')) {
					$completed_records = $wpdb->get_results(
						"SELECT * FROM `$complete_card_transaction_table_name`
							 WHERE `transaction_reference` = $authorized_record->transaction_reference
						"
					);
					if(empty($completed_records)) {
						// attempt to complete order using Lipisha
						$lipisha_cc_gateway = new WC_Lipisha_CreditCard_Gateway();
						if (!$lipisha_cc_gateway->testmode) {
							$complete_card_url = WOOCOMMERCE_LIPISHA_CC_COMPLETE_CARD_URL;
						} else {
							$complete_card_url = WOOCOMMERCE_LIPISHA_CC_TEST_COMPLETE_CARD_URL;
						}

						$data = array(
					    'api_key' => $lipisha_cc_gateway->lipisha_api_key,
					    'api_signature' => $lipisha_cc_gateway->lipisha_api_secret,
					    'api_version' => $lipisha_cc_gateway->lipisha_api_version,
					    'api_type' => "Callback",
					    'transaction_index' => $authorized_record->transaction_index,
					    'transaction_reference' => $authorized_record->transaction_reference,
						);

						// Send this data to Lipisha for processing
						$response = wp_remote_post($complete_card_url, array(
							'method'    => 'POST',
							'body'      => http_build_query($data),
							'timeout'   => 180,
							'sslverify' => false,
						));

						if (is_wp_error( $response)) {
							// do nothing, will be retried
						}

						if (empty($response['body'])) {
							// do nothing, will be retried
						}

						$response_body = wp_remote_retrieve_body($response);
						$lipisha_response = json_decode($response_body);

						if (is_object($lipisha_response) && is_object($lipisha_response->status) && is_object($lipisha_response->content)) {
							// Save Lipisha data
				    	$wpdb->insert($complete_card_transaction_table_name, array(
				    	   "transaction_index" => $lipisha_response->content->transaction_index,
				    	   "transaction_reference" => $lipisha_response->content->transaction_reference,
				    	   "status" => $lipisha_response->status->status_description,
				    	   "status_code" => $lipisha_response->status->status_code,
				    	));

							if ($lipisha_response->status->status_code == "0000") {
								// successful
								$this_order->add_order_note(__('Lipisha Credit Card payment completed.', 'kej_lipisha_cc'));
								// Mark order as Paid
								$this_order->payment_complete();
							} else {
								// not successful								
								// Add notice to the cart
								$rejection_reason = (isset($lipisha_response->content->reason)) ? $lipisha_response->content->reason : __('There was an error with the information supplied', 'kej_lipisha_cc');								
								$this_order->add_order_note(__('Lipisha Error: ', 'kej_lipisha_cc') . $rejection_reason);
							}
						} else {
							// do nothing, will be retried
						}
					} 
				}

				// mark this $authorized_record as processed
				$wpdb->update(
			    $card_transaction_table_name,
			    array( 
			      'processed' => 1,
			    ), 
			    array(
			      "id" => $authorized_record->id
			    ) 
				);
			}
		}
	}

	add_action('woocommerce_thankyou', 'woocommerce_lipisha_cc_complete_order');

	function woocommerce_lipisha_cc_complete_order($order_id) {
		global $wpdb;
		$card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
		$complete_card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_complete_card_transaction";

		$this_order = wc_get_order($order_id);

		if (is_object($this_order) && ($this_order->get_status() == "pending" || $this_order->get_status() == 'on-hold')) {
			$authorized_record = $wpdb->get_row("SELECT * FROM $card_transaction_table_name WHERE order_id = $this_order->id" );
			if (!is_null($authorized_record)) {
				$completed_record = $wpdb->get_row("SELECT * FROM $complete_card_transaction_table_name WHERE transaction_reference = $authorized_record->transaction_reference" );	
				if (is_null($completed_record)) {
					// attempt to complete order using Lipisha
					$lipisha_cc_gateway = new WC_Lipisha_CreditCard_Gateway();
					if (!$lipisha_cc_gateway->testmode) {
						$complete_card_url = WOOCOMMERCE_LIPISHA_CC_COMPLETE_CARD_URL;
					} else {
						$complete_card_url = WOOCOMMERCE_LIPISHA_CC_TEST_COMPLETE_CARD_URL;
					}

					$data = array(
				    'api_key' => $lipisha_cc_gateway->lipisha_api_key,
				    'api_signature' => $lipisha_cc_gateway->lipisha_api_secret,
				    'api_version' => $lipisha_cc_gateway->lipisha_api_version,
				    'api_type' => "Callback",
				    'transaction_index' => $authorized_record->transaction_index,
				    'transaction_reference' => $authorized_record->transaction_reference,
					);

					// Send this data to Lipisha for processing
					$response = wp_remote_post($complete_card_url, array(
						'method'    => 'POST',
						'body'      => http_build_query($data),
						'timeout'   => 180,
						'sslverify' => false,
					));

					if (is_wp_error($response)) {
						// do nothing, will be retried
					}

					if (empty($response['body'])) {
						// do nothing, will be retried
					}

					$response_body = wp_remote_retrieve_body($response);
					$lipisha_response = json_decode($response_body);

					if (is_object($lipisha_response) && is_object($lipisha_response->status) && is_object($lipisha_response->content)) {
						// Save Lipisha data
			    	$wpdb->insert($complete_card_transaction_table_name, array(
			    	   "transaction_index" => $lipisha_response->content->transaction_index,
			    	   "transaction_reference" => $lipisha_response->content->transaction_reference,
			    	   "status" => $lipisha_response->status->status_description,
			    	   "status_code" => $lipisha_response->status->status_code,
			    	));

						if ($lipisha_response->status->status_code == "0000") {
							// successful
							$this_order->add_order_note(__('Lipisha Credit Card payment completed.', 'kej_lipisha_cc'));
							// Mark order as Paid
							$this_order->payment_complete();
						} else {
							// not successful								
							// Add notice to the cart
							$rejection_reason = (isset($lipisha_response->content->reason)) ? $lipisha_response->content->reason : __('There was an error with the information supplied', 'kej_lipisha_cc');								
							$this_order->add_order_note(__('Lipisha Error: ', 'kej_lipisha_cc') . $rejection_reason);
						}
					} else {
						// do nothing, will be retried
					}
				}
				// mark this $authorized_record as processed
				$wpdb->update(
			    $card_transaction_table_name,
			    array( 
			      'processed' => 1,
			    ), 
			    array(
			      "id" => $authorized_record->id
			    ) 
				);
			}
		}		
	}

	// Payment Gateway
	add_action('plugins_loaded', 'init_lipisha_cc_gateway');

	function init_lipisha_cc_gateway() {
		class WC_Lipisha_CreditCard_Gateway extends WC_Payment_Gateway_CC {
			function __construct() {
				$this->id           = 'kej_lipisha_cc';
				$this->method_title = __('Lipisha Credit Card', 'kej_lipisha_cc');
				$this->title = (null !== $this->get_option('title') && $this->get_option('title') != "") ? $this->get_option('title') : __('Lipisha Credit Card', 'kej_lipisha_cc');
				$this->icon = null;
				$this->method_description = __('Allows payments through cedit cards, via Lipisha.com.', 'kej_lipisha_cc');
				$this->has_fields   = true;
				$this->testmode     = ($this->get_option('testmode') === 'yes') ? true : false;
				$this->debug	      = $this->get_option('debug');

				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Get settings				
				$this->enable_for_methods 					= $this->get_option('enable_for_methods', array());
				$this->enable_for_virtual 					= $this->get_option('enable_for_virtual', 'yes') === 'yes' ? true : false;
				$this->auto_complete_virtual_orders = $this->get_option('auto_complete_virtual_orders', 'yes') === 'yes' ? true : false;
				$this->lipisha_account_number   		= $this->get_option('lipisha_account_number');
				$this->lipisha_api_key   						= $this->get_option('lipisha_api_key');
				$this->lipisha_api_secret   				= $this->get_option('lipisha_api_secret');
				$this->lipisha_api_version   				= $this->get_option('lipisha_api_version', "1.3.0");				
				$this->testmode 										= $this->get_option('testmode', 'yes') === 'yes' ? true : false;

				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_thankyou_lipisha_cc', array($this, 'thankyou_page'));

				// SSL check
				add_action('admin_notices', array( $this,	'do_ssl_check' ));
			}

			/**
			 * Initialise Gateway Settings Form Fields
			 */
			public function init_form_fields() {
				$shipping_methods = array();

				if (is_admin()){

					foreach (WC()->shipping()->load_shipping_methods() as $method) {
						$shipping_methods[ $method->id ] = $method->get_title();
					}

					$this->form_fields = array(
						'enabled' => array(
							'title'   => __('Enable/Disable', 'kej_lipisha_cc'),
							'type'    => 'checkbox',
							'label'   => __('Enable Lipisha Credit Card', 'kej_lipisha_cc'),
							'default' => 'no'
						),
						'title' => array(
							'title'       => __('Title', 'kej_lipisha_cc'),
							'type'        => 'text',
							'description' => __('This controls the title which the user sees during checkout.', 'kej_lipisha_cc'),
							'default'     => __('Lipisha Credit Card', 'kej_lipisha_cc'),
							'desc_tip'    => true,
						),
						'enable_for_methods' => array(
							'title'             => __('Enable for shipping methods', 'kej_lipisha_cc'),
							'type'              => 'multiselect',
							'class'             => 'wc-enhanced-select',
							'css'               => 'width: 450px;',
							'default'           => '',
							'description'       => __('If Lipisha Credit Card is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'kej_lipisha_cc'),
							'options'           => $shipping_methods,
							'desc_tip'          => true,
							'custom_attributes' => array(
								'data-placeholder' => __('Select shipping methods', 'kej_lipisha_cc')
								)
						),
						'enable_for_virtual' => array(
							'title'             => __('Accept for virtual orders', 'kej_lipisha_cc'),
							'label'             => __('Accept Lipisha Credit Card if the order is virtual', 'kej_lipisha_cc'),
							'type'              => 'checkbox',
							'default'           => 'yes'
						),
						'auto_complete_virtual_orders' => array(
							'title'             => __('Auto-complete for virtual orders', 'kej_lipisha_cc'),
							'label'             => __('Automatically mark virtual orders as completed once payment is received', 'kej_lipisha_cc'),
							'type'              => 'checkbox',
							'default'           => 'no'
						),						
						'lipisha_api_key' => array(
							'title'       => __('Lipisha API Key', 'kej_lipisha_cc'),
							'type'        => 'text',
							'description' => __('The API Key received from Lipisha.com.', 'kej_lipisha_cc'),
							'desc_tip'    => true,
						),
						'lipisha_api_secret' => array(
							'title'       => __('Lipisha API Secret', 'kej_lipisha_cc'),
							'type'        => 'text',
							'description' => __('The API Secret received from Lipisha.com.', 'kej_lipisha_cc'),
							'desc_tip'    => true,
						),
						'lipisha_api_version' => array(
							'title'       => __('Lipisha API Version', 'kej_lipisha_cc'),
							'type'        => 'text',
							'description' => __('The Lipisha API version number.', 'kej_lipisha_cc'),
							'desc_tip'    => true,
						),
						'lipisha_account_number' => array(
							'title'       => __('Lipisha Account Number', 'kej_lipisha_cc'),
							'type'        => 'text',
							'description' => __('The Account Number received from Lipisha.com.', 'kej_lipisha_cc'),
							'desc_tip'    => true,
						),
						'testmode' => array(
							'title'		=> __( 'Lipisha Credit Card Test Mode', 'kej_lipisha_cc' ),
							'label'		=> __( 'Enable Test Mode', 'kej_lipisha_cc' ),
							'type'		=> 'checkbox',
							'description' => __( 'Place the payment gateway in test mode.', 'kej_lipisha_cc' ),
							'default'	=> 'no',
						)
					);
				}
			}

			/**
			 * Check If The Gateway Is Available For Use
			 *
			 * @return bool
			 */
			public function is_available() {
				$order          = null;
				$needs_shipping = false;

				// Test if shipping is needed first
				if (WC()->cart && WC()->cart->needs_shipping()) {
					$needs_shipping = true;
				} elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
					$order_id = absint(get_query_var('order-pay'));
					$order    = wc_get_order($order_id);

					// Test if order needs shipping.
					if (0 < sizeof($order->get_items())) {
						foreach ($order->get_items() as $item) {
							$_product = $order->get_product_from_item($item);
							if ($_product && $_product->needs_shipping()) {
								$needs_shipping = true;
								break;
							}
						}
					}
				}

				$needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

				// Virtual order, with virtual disabled
				if (! $this->enable_for_virtual && ! $needs_shipping) {
					return false;
				}

				// Check methods
				if (! empty($this->enable_for_methods) && $needs_shipping) {

					// Only apply if all packages are being shipped via chosen methods, or order is virtual
					$chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

					if (isset($chosen_shipping_methods_session)) {
						$chosen_shipping_methods = array_unique($chosen_shipping_methods_session);
					} else {
						$chosen_shipping_methods = array();
					}

					$check_method = false;

					if (is_object($order)) {
						if ($order->shipping_method) {
							$check_method = $order->shipping_method;
						}

					} elseif (empty($chosen_shipping_methods) || sizeof($chosen_shipping_methods) > 1) {
						$check_method = false;
					} elseif (sizeof($chosen_shipping_methods) == 1) {
						$check_method = $chosen_shipping_methods[0];
					}

					if (! $check_method) {
						return false;
					}

					$found = false;

					foreach ($this->enable_for_methods as $method_id) {
						if (strpos($check_method, $method_id) === 0) {
							$found = true;
							break;
						}
					}

					if (! $found) {
						return false;
					}
				}

				return parent::is_available();
			}

			/**
			 * Process the payment and return the result
			 *
			 * @param int $order_id
			 * @return array
			 */
			public function process_payment($order_id) {
				$order = wc_get_order($order_id);

				// verify credit card using Lipisha Authorize Card
				$card_number = str_replace(array(' ', '-' ), '', $_POST['kej_lipisha_cc-card-number'] );
				$card_cvc = (isset($_POST['kej_lipisha_cc-card-cvc'])) ? $_POST['kej_lipisha_cc-card-cvc'] : '';
				$lipisha_expiry = str_replace(array( '/', ' '), '', $_POST['kej_lipisha_cc-card-expiry'] );

				if (strlen($lipisha_expiry) == 4) {
				  $lipisha_expiry = substr($lipisha_expiry, 0, 2) . "20" . substr($lipisha_expiry, 2);
				}

				if (!$this->testmode) {
					$auth_card_url = WOOCOMMERCE_LIPISHA_CC_AUTH_CARD_URL;
				} else {
					$auth_card_url = WOOCOMMERCE_LIPISHA_CC_TEST_AUTH_CARD_URL;
				}				

				if ((is_null($order->billing_state) || $order->billing_state == "")) {
					$state_name = "";
				} else {
					$state_name = WC()->countries->states[$order->billing_country][$order->billing_state];
				}
				

				$data = array(
			    'api_key' => $this->lipisha_api_key,
			    'api_signature' => $this->lipisha_api_secret,
			    'api_version' => $this->lipisha_api_version,
			    'api_type' => "Callback",
			    'account_number' => $this->lipisha_account_number,
			    'card_number' => $card_number,
			    'address1' => (is_null($order->billing_address_1) || $order->billing_address_1 == "") ? $state_name : $order->billing_address_1,
			    'address2' => (is_null($order->billing_address_2) || $order->billing_address_2 == "") ? $order->billing_phone : $order->billing_address_2,
			    'expiry' => $lipisha_expiry,
			    'name' => $order->billing_first_name . " " . $order->billing_last_name,
			    'country' => WC()->countries->countries[$order->billing_country],
			    'state' => $state_name,
			    'zip' => (is_null($order->billing_postcode) || $order->billing_postcode == "") ? "00200" : $order->billing_postcode,
			    'security_code' => $card_cvc,
			    'amount' => $order->order_total,
			    'currency' => $order->order_currency,
				);

				// Send this data to Lipisha for processing
				$response = wp_remote_post($auth_card_url, array(
					'method'    => 'POST',
					'body'      => http_build_query($data),
					'timeout'   => 90,
					'sslverify' => false,
				));

				if (is_wp_error($response)) {
					throw new Exception(__( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.', 'kej_lipisha_cc'));
				}

				if (empty($response['body'])) {
					throw new Exception(__( 'Lipisha\'s Response was empty.', 'kej_lipisha_cc'));
				}

				$response_body = wp_remote_retrieve_body($response);
				$lipisha_response = json_decode($response_body);

				if (is_object($lipisha_response) && is_object($lipisha_response->status)) {
					if (is_object($lipisha_response->content)) {
						global $wpdb;
						if ($lipisha_response->status->status_code == "0000") {
							// Payment has been successful
							$order->add_order_note(__('Lipisha Credit Card authorised.', 'kej_lipisha_cc'));															 
							// Mark as processing (payment won't be taken until delivery)
				    	$order->update_status('pending', __('Waiting to verify Lipisha Credit Card payment.', 'kej_lipisha_cc'));
							// Reduce stock levels
				    	$order->reduce_order_stock();
							// Empty the cart (Very important step)
							WC()->cart->empty_cart();			
							// Save Lipisha data
				    	$card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
				    	$wpdb->insert($card_transaction_table_name, array(
				    	  "order_id" => $order->id,
				    	  "transaction_index" => $lipisha_response->content->transaction_index,
				    	  "transaction_reference" => $lipisha_response->content->transaction_reference,
				    	  "status" => $lipisha_response->status->status_description,
				    	  "status_code" => $lipisha_response->status->status_code
				    	));		
							// Redirect to thank you page
							return array(
								'result'   => 'success',
								'redirect' => $this->get_return_url($order),
							);
						} else {
							// not successful
							// Save Lipisha data						
				    	$card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
				    	$wpdb->insert($card_transaction_table_name, array(
				    	   "order_id" => $order->id,
				    	   "transaction_index" => $lipisha_response->content->transaction_index,
				    	   "transaction_reference" => $lipisha_response->content->transaction_reference,
				    	   "status" => $lipisha_response->status->status_description,
				    	   "status_code" => $lipisha_response->status->status_code,
				    	   "processed" => 1
				    	));
							// Add notice to the cart
							$rejection_reason = __('There was an error with the information supplied', 'kej_lipisha_cc');	
							if (isset($lipisha_response->content->reason) and !is_null($lipisha_response->content->reason)) {
								$admin_rejection_reason = $lipisha_response->content->reason;
								$rejection_reason = $lipisha_response->content->reason;
							} elseif ($lipisha_response->status->status_code != "" && $lipisha_response->status->status_description != "") {
								$admin_rejection_reason = "#" . $lipisha_response->status->status_code  . ": " . $lipisha_response->status->status_description;
							} else {
								$admin_rejection_reason = __('There was an error with the information supplied', 'kej_lipisha_cc');	
							}	

							wc_add_notice($rejection_reason, 'error' );
							// Add note to the order for your reference
							$order->add_order_note(__('Lipisha Error: ', 'kej_lipisha_cc') . $admin_rejection_reason);
						}
					} else {
						wc_add_notice(__("There was an error with with this payment provider", 'kej_lipisha_cc') , 'error' );
						// Add note to the order for your reference
						$order->add_order_note(__('Lipisha Error: ', 'kej_lipisha_cc') . "#" .$lipisha_response->status->status_code  . ": " . $lipisha_response->status->status_description);
					}
					
				} else {
					// there was an error
					// Add notice to the cart
					wc_add_notice(__("There was an unidentified error with this payment provider", 'kej_lipisha_cc'), 'error' );
					// Add note to the order for your reference
					$order->add_order_note(__('Error: ', 'kej_lipisha_cc') . __("There was an unidentified error with Lipisha", 'kej_lipisha_cc'));
				}			

			}

			// Validate fields
			public function validate_fields() {
				return true;
			}
				
			// Check if we are forcing SSL on checkout pages
			// Custom function not required by the Gateway
			public function do_ssl_check() {
				if( $this->enabled == "yes" ) {
					if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
						echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";	
					}
				}
			}

		}		
	}

	function add_lipisha_cc_gateway($methods) {
		$methods[] = 'WC_Lipisha_CreditCard_Gateway'; 
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'add_lipisha_cc_gateway');

	// Mark virtual orders as completed automatically
	add_filter('woocommerce_payment_complete_order_status', 'woocommerce_lipisha_cc_virtual_order_completion', 10, 2);
	 
	function woocommerce_lipisha_cc_virtual_order_completion($order_status, $order_id) {
		$lipisha_gateway = new WC_Lipisha_CreditCard_Gateway();
	  $auto_complete_virtual_orders = $lipisha_gateway->auto_complete_virtual_orders;
	  if ($auto_complete_virtual_orders) {
		  $order = new WC_Order($order_id);	 
		  if ('processing' == $order_status &&
		    ('on-hold' == $order->status || 'pending' == $order->status || 'failed' == $order->status)) {	 
		    $virtual_order = null;	 
		    if ( count( $order->get_items() ) > 0 ) {	 
		      foreach( $order->get_items() as $item ) {	 
		        if ( 'line_item' == $item['type'] ) {	 
		          $_product = $order->get_product_from_item($item);	 
		          if (!$_product->is_virtual()) {
		            // once we've found one non-virtual product we know we're done, break out of the loop
		            $virtual_order = false;
		            break;
		          } else {
		            $virtual_order = true;
		          }
		        }
		      }
		    }	 
		    // virtual order, mark as completed
		    if ($virtual_order) {
		      return 'completed';
		    }
		  }	
		} 
	  // non-virtual order, return original status
	  return $order_status;
	}
}