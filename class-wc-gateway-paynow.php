<?php

/**
 * @package Paynow
 * @version 1.0
 */
/*
Plugin Name: Paynow
Plugin URI: http://paynow.com
Description: Blaaaa 
Armstrong: Blaaa
Author: Paynow Team
Version: 1.0
Author URI: http://paynow.tt/
*/

add_action( 'plugins_loaded', 'init_WC_Gateway_PAYNOW_class' );
function init_WC_Gateway_PAYNOW_class() {
	/**
	 * Paynow
	 *
	 * Provee integración con la pasarela de pago PayNow
	 *
	 * @class 		WC_Gateway_PAYNOW
	 * @extends		WC_Payment_Gateway
	 * @version		1.0.0
	 * @package		WooCommerce/Classes/Payment
	 * @author 		Paynow Team
	 */ 
	class WC_Gateway_PAYNOW extends WC_Payment_Gateway {

		var $notify_url;
		
		var $live_url = 'http://gtw.paynow.cl/neworder/';
		var $test_url = 'http://qa.gtw.paynow.cl/neworder/';

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'paynow';
			$this->icon               = '';//apply_filters('woocommerce_bacs_icon', '');
			$this->has_fields         = true;
			$this->method_title       = __( 'Paynow', 'woocommerce' );
			$this->method_description = __( 'Permite realizar pagos a través de Paynow.', 'woocommerce' );
			$this->notify_url = WC()->api_request_url( 'WC_Gateway_PAYNOW' );
            $this->supports           = array(
                'subscriptions',
                'products',
                'payment_method'
            );
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
            $this->businessID = $this->get_option('businessID');
            $this->auth_token = $this->get_option('auth_token');
            
            $this->debug = $this->get_option( 'debug', 'yes' ) === 'yes' ? true : false;
            $this->is_test = $this->get_option( 'is_test', 'yes' ) === 'yes' ? true : false;

            $this->gateway_url = $this->is_test ? $this->live_url : $this->test_url;

            // Logs
			if ( 'yes' == $this->debug ) {
				$this->log = new WC_Logger();
			}

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			//add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_account_details' ) );		
			add_action( 'woocommerce_thankyou_paynow', array( $this, 'thankyou_page' ) );

			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

		}

        /**
         * Payment form on checkout page
         */
        public function payment_fields() {
            $description = $this->get_description();

            if ( $description ) {
                echo wpautop( wptexturize( trim( $description ) ) );
            }
            echo "<select class='chosen_select' name='payment_app'><option value='bchile'>bchile</option><option value='bestado'>bestado</option></select>";
        }

		/**
		 * Initialise Gateway Settings Form Fields
		 */
		public function init_form_fields() {
		
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Habilitar/Deshabilitar', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar Paynow', 'woocommerce' ),
					'default' => 'yes'
				),
				'is_test' => array(
					'title'       => __( 'Paynow sandbox', 'woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable Paynow sandbox', 'woocommerce' ),
					'default'     => 'no',
					'description' => sprintf( __( 'Paynow sandbox can be used to test payments. Sign up for a developer account <a href="%s">here</a>.', 'woocommerce' ), 'https://qa.paynow.cl/' ),
				),
				'debug' => array(
					'title'       => __( 'Debug Log', 'woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'woocommerce' ),
					'default'     => 'no',
					'description' => sprintf( __( 'Log Paynow events, such as IPN requests, inside <code>%s</code>', 'woocommerce' ), wc_get_log_file_path( 'paynow' ) )
				),
				'title' => array(
					'title'       => __( 'Título', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Método de pago Paynow', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Descripción', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Pagar con Paynow.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instrucciones', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
                'businessID' => array(
                    'title'       => __( 'Identificador de la empresa', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'UUID que identifica al negocio.', 'woocommerce' ),
                    'default'     => __( '', 'woocommerce' ),
                    'desc_tip'    => true,
                ),
                'auth_token' => array(
                    'title'       => __( 'Token de autenticación', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Token nesesario para la autenciación en paynow.', 'woocommerce' ),
                    'default'     => __( '', 'woocommerce' ),
                    'desc_tip'    => true,
                ),
			);
		}

		
		/**
		 * Output for the order received page.
		 */
		public function thankyou_page( $order_id ) {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
			}        
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 * @return void
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( ! $sent_to_admin && 'bacs' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
				if ( $this->instructions ) {
					echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
				}			
			}
		}
		
		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			//productos
            echo "<pre>";
            var_dump($this);
            echo "</pre>";

            //productos
            echo "<pre>";
            var_dump($order->get_items());
            echo "</pre>";

            //payment_app
            echo "<pre>";
            $payment_app = $_POST['payment_app'];
            echo "App seleccionada:  ".$payment_app;
            echo "</pre>";

			$postData = array();
			
			//Identificar el negocio donde vas a recaudar tus ventas
			$postData['businessID'] = $this->businessID;
			$postData['tSource'] = 'API';
			$postData['tSourceID'] = 'http://store.paynow.cl';
			$postData['businessLogo'] = '';
			
			// Mostrar formulario de despacho y facturación (Y = Solicita, N = NO Solicita)
			$postData['includeShipping'] = 'N';
			$postData['shippingAmount'] = '0';
			$postData['shippingComments'] = '';
			$postData['includeBilling'] = 'N';
			$postData['billingComments'] = '';
			$postData['paymentComments'] = '';

			// Detallar la información del producto que tu cliente va a comprar. -->
			$postData['itemDescription'] = 'Producto 1';
			$postData['itemCurrency'] = 'CLP';
			$postData['itemQuantity'] = '1';
			$postData['itemAmount'] = '100';
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_USERPWD, $this->businessID . ":" . $this->token);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
			$response = curl_exec($ch);
			curl_close($ch);
			$result = json_decode($response, true);

			echo "<pre>";
            var_dump($postData);
            echo "</pre>";
            
            die();
			
			if ($result["status"] == 'ok')
			{
				header("location:$url"."?token=".$result["token"]);
			}
			else
			{
				echo $result["message"];
			}

			// Mark as on-hold (we're awaiting the payment)
			$order->update_status( 'on-hold', __( 'Awaiting Paynow payment', 'woocommerce' ) );

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order )
			);
		}
	}
}
/**
 * Add the gateway to WooCommerce
 **/
function add_paynow_gateway( $methods ) {
    $methods[] = 'WC_Gateway_PAYNOW'; 
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'add_paynow_gateway' );
