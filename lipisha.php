<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/*
Plugin Name: Woocommerce Lipisha
Plugin URI: https://github.com/moshthepitt/woocommerce-lipisha
Description: Allows use of Kenyan payment processor Lipisha - https://lipisha.com
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
	register_activation_hook(__FILE__, 'woocommerce_lipisha_install');
	register_uninstall_hook(__FILE__, 'lipisha_on_uninstall');

	define('WOOCOMMERCE_LIPISHA_PLUGIN_VERSION', "0.1");
	define('WOOCOMMERCE_LIPISHA_PLUGIN_URL', plugin_dir_url(__FILE__));
	define('WOOCOMMERCE_LIPISHA_PLUGIN_DIR', WP_PLUGIN_DIR.'/'.dirname(plugin_basename(__FILE__)));	

	function woocommerce_lipisha_install() {
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

	function lipisha_on_uninstall()	{
	  // Clean up i.e. delete the table, wp_cron already removed on deacivate
	  global $wpdb;
	  $card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_authorize_card_transaction";
	  $complete_card_transaction_table_name = $wpdb->prefix . "woocommerce_lipisha_complete_card_transaction";  
	  $wpdb->query("DROP TABLE IF EXISTS $card_transaction_table_name");
	  $wpdb->query("DROP TABLE IF EXISTS $complete_card_transaction_table_name");
	} 

	function set_up_styles() {
		wp_register_style('woocommerce_lipisha_basic_css', WOOCOMMERCE_LIPISHA_PLUGIN_URL . '/css/lipisha.css');
		wp_enqueue_style('woocommerce_lipisha_basic_css');
	}

	function set_up_js() {
		wp_register_script('woocommerce_lipisha_jquery_payment', WOOCOMMERCE_LIPISHA_PLUGIN_URL . '/js/jquery.payment.js', array('jquery'), WOOCOMMERCE_LIPISHA_PLUGIN_VERSION);
		wp_register_script('woocommerce_lipisha_basic_js', WOOCOMMERCE_LIPISHA_PLUGIN_URL . '/js/lipisha.js', array('jquery'), WOOCOMMERCE_LIPISHA_PLUGIN_VERSION);		
		wp_enqueue_script('woocommerce_lipisha_jquery_payment');
		wp_enqueue_script('woocommerce_lipisha_basic_js');
	}

	//Scripts and Styles
	add_action('wp_enqueue_scripts', 'set_up_styles');
	add_action('wp_enqueue_scripts', 'set_up_js');

	// Payment Gateway
	add_action('plugins_loaded', 'init_lipisha_gateway');

	function init_lipisha_gateway() {
		class WC_Lipisha_Gateway extends WC_Payment_Gateway {
			function __construct() {
				$this->id           = 'lipisha';
				$this->method_title = __('Lipisha', 'woocommerce');
				$this->method_description = __('Allows payments through Lipisha.', 'woocommerce');
				$this->has_fields   = true;
				$this->testmode     = ($this->get_option('testmode') === 'yes') ? true : false;
				$this->debug	      = $this->get_option('debug');

				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Get settings				
				$this->description        					= $this->get_option('description');
				$this->instructions       					= $this->get_option('instructions');
				$this->enable_for_methods 					= $this->get_option('enable_for_methods', array());
				$this->enable_for_virtual 					= $this->get_option('enable_for_virtual', 'yes') === 'yes' ? true : false;
				$this->auto_complete_virtual_orders = $this->get_option('auto_complete_virtual_orders', 'yes') === 'yes' ? true : false;
				$this->lipisha_account_number   		= $this->get_option('lipisha_account_number');
				$this->lipisha_api_key   						= $this->get_option('lipisha_api_key');
				$this->lipisha_api_secret   				= $this->get_option('lipisha_api_secret');
				$this->lipisha_api_version   				= $this->get_option('lipisha_api_version', "1.3.0");				

				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_thankyou_lipisha', array($this, 'thankyou_page'));

				// Customer Emails
				add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
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

					$lipisha_instructions = 'Please fill in the form.';

					$this->form_fields = array(
						'enabled' => array(
							'title'   => __('Enable/Disable', 'woocommerce'),
							'type'    => 'checkbox',
							'label'   => __('Enable Lipisha', 'woocommerce'),
							'default' => 'no'
							),
						'title' => array(
							'title'       => __('Title', 'woocommerce'),
							'type'        => 'text',
							'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
							'default'     => __('Credit Card', 'woocommerce'),
							'desc_tip'    => true,
							),
						'description' => array(
							'title'       => __('Description', 'woocommerce'),
							'type'        => 'textarea',
							'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
							'default'     => $lipisha_instructions,
							'desc_tip'    => true,
							),
						'instructions' => array(
							'title'       => __('Instructions', 'woocommerce'),
							'type'        => 'textarea',
							'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce'),
							'default'     => $lipisha_instructions,
							'desc_tip'    => true,
							),
						'enable_for_methods' => array(
							'title'             => __('Enable for shipping methods', 'woocommerce'),
							'type'              => 'multiselect',
							'class'             => 'wc-enhanced-select',
							'css'               => 'width: 450px;',
							'default'           => '',
							'description'       => __('If Lipisha is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce'),
							'options'           => $shipping_methods,
							'desc_tip'          => true,
							'custom_attributes' => array(
								'data-placeholder' => __('Select shipping methods', 'woocommerce')
								)
							),
						'enable_for_virtual' => array(
							'title'             => __('Accept for virtual orders', 'woocommerce'),
							'label'             => __('Accept Lipisha if the order is virtual', 'woocommerce'),
							'type'              => 'checkbox',
							'default'           => 'yes'
							),
						'auto_complete_virtual_orders' => array(
							'title'             => __('Auto-complete for virtual orders', 'woocommerce'),
							'label'             => __('Automatically mark virtual orders as completed once payment is received', 'woocommerce'),
							'type'              => 'checkbox',
							'default'           => 'no'
							),						
						'lipisha_api_key' => array(
							'title'       => __('Lipisha API Key', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The API Key received from Lipisha.com.', 'woocommerce'),
							'desc_tip'    => true,
							),
						'lipisha_api_secret' => array(
							'title'       => __('Lipisha API Secret', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The API Secret received from Lipisha.com.', 'woocommerce'),
							'desc_tip'    => true,
							),
						'lipisha_api_version' => array(
							'title'       => __('Lipisha API Version', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The Lipisha API version number.', 'woocommerce'),
							'desc_tip'    => true,
							),
						'lipisha_account_number' => array(
							'title'       => __('Lipisha Account Number', 'woocommerce'),
							'type'        => 'text',
							'description' => __('The Account Number received from Lipisha.com.', 'woocommerce'),
							'desc_tip'    => true,
							),
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

			public function payment_fields() {
				if ($description = $this->get_description()) {
				  echo wpautop(wptexturize($description));
				}

				$output = '
					<p class="lipisha form-row form-row form-row-wide woocommerce-validated" id="cc-name_field" data-o_class="form-row form-row form-row-wide">
						<label for="cc-name" class="">Name on Card <abbr class="required" title="required">*</abbr> <small class="text-muted">[<span class="cc-brand"></span>]</small></label>
						<input type="text" class="input-text cc-name" name="cc-name" id="cc-name" placeholder="Name on Card" />
					</p>
					<p class="lipisha form-row form-row form-row-wide woocommerce-validated" id="cc-number_field" data-o_class="form-row form-row form-row-wide">
						<label for="cc-number" class="">Credit Card Number <abbr class="required" title="required">*</abbr></label>
						<input type="tel" class="input-text cc-number" name="cc-number" id="cc-number" placeholder="•••• •••• •••• ••••" autocomplete="cc-number" />
					</p>
					<p class="lipisha form-row form-row form-row-wide woocommerce-validated" id="cc-exp-month_field" data-o_class="form-row form-row form-row-wide">
						<label for="cc-exp-month" class="">Expiry Date <abbr class="required" title="required">*</abbr></label>
						<select name="cc-exp-month" id="cc-exp-month" class="input-select">
						  <option value="">Select Month</option>';
						  
						  foreach(range(1,12) as $month) {
						  	$output .= "<option value='$month'>$month</option>";
						  }						  
						
					$output .=	'</select>
					</p>
					<p class="lipisha form-row form-row form-row-wide woocommerce-validated" id="cc-exp-year_field" data-o_class="form-row form-row form-row-wide">
						<label for="cc-exp-year" class="">Expiry Date <abbr class="required" title="required">*</abbr></label>
						<select name="cc-exp-month" id="cc-exp-month" class="input-select">
						  <option value="">Select Year</option>';

						  foreach(range((int)date("Y"), (int)date("Y") + 12) as $year) {
						  	$output .= "<option value='$year'>$year</option>";
						  }	

						$output .= '</select>
					</p>
					<p class="lipisha form-row form-row form-row-wide woocommerce-validated" id="cc-cvc_field" data-o_class="form-row form-row form-row-wide">
						<label for="cc-cvc" class="">Security Code <abbr class="required" title="required">*</abbr></label>
						<input type="tel" class="input-text cc-cvc" name="cc-cvc" id="cc-cvc" placeholder="•••" autocomplete="off"/>
					</p>
					<h2 class="lipisha-validation"></h2>
				';
				echo $output;
			}

		}

		function add_lipisha_gateway($methods) {
			$methods[] = 'WC_Lipisha_Gateway'; 
			return $methods;
		}

		add_filter('woocommerce_payment_gateways', 'add_lipisha_gateway');
	}
}