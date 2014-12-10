<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @package Paynow
 * @version 1.0
 */
/*
Plugin Name: Woocommerce Paynow
Plugin URI: https://www.paynow.cl
Description: Venda en su sitio web, redes sociales y tiendas virtuales.
Armstrong:
Author: Equipo Paynow
Version: 1.0
Author URI: https://github.com/paynowcode
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

		var $live_url = 'http://gtw.paynow.cl';
		var $test_url = 'http://qa.gtw.paynow.cl';

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'paynow';
			$this->icon               = 'http://qa.gtw.paynow.cl/static/images/logotipo-mini-header-c.png';//apply_filters('woocommerce_bacs_icon', '');
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
            $this->businessID = $this->get_option( 'businessID' );
            $this->auth_token = $this->get_option( 'auth_token' );
            $this->rut_control_id = $this->get_option( 'rut_control_id' );
            
            $this->debug = $this->get_option( 'debug', 'yes' ) === 'yes' ? true : false;
            $this->is_test = $this->get_option( 'is_test', 'yes' ) === 'yes' ? true : false;

            $this->gateway_url = $this->is_test ? $this->test_url : $this->live_url;
            $this->post_url = $this->gateway_url . '/neworder/';
            $this->api_url = $this->gateway_url . '/api/';

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

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_gateway_paynow', array( $this, 'check_ipn_response' ) );
		}

        /**
         * Payment form on checkout page
         */
        public function payment_fields() {
            $description = $this->get_description();

            if ( $description ) {
                //echo wpautop( wptexturize( trim( $description ) ) );
            }

			$basic_auth = base64_encode( $this->businessID . ":" . $this->auth_token );

			$args = array (
            	'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array( 
					'Content-Type' => 'application/json',
					'Authorization' => 'Basic ' . $basic_auth 
				),
				'body' => null,
				'cookies' => array()
            );
            
            $response = wp_remote_get( $this->api_url . 'payment-methods/?currency=' . get_woocommerce_currency(), $args );

            if ( is_wp_error( $response ) ) {
				wc_add_notice( __('Payment error: ', 'woothemes') . $response->get_error_message(), 'error' );
				return;
			}

            $result = json_decode( $response[ 'body' ], true );

            $html = '<div><select class="chosen_select" name="payment_app">';
            foreach ( $result[ 'results' ] as $pm ) {
            	
            	$pmName = $pm[ 'pmMethodName' ];
            	$pmDesc = $pm[ 'pmDescription' ];
            	//$pmLogo = $this->gateway_url . '/media/' . $pm[ 'pmLogo' ];
            	
            	$html .= '<option value="' . $pmName . '">' . $pmDesc . '</option>';
            }
            $html .= '</select>';

            print $html;
        }

        /**
		* Return handler
		*/
        public function check_ipn_response(){
        	@ob_clean();

			$ipn_response = ! empty( $_POST ) ? $_POST : false;

			if ( $ipn_response ) {

				if ( $this->is_test ) {

					$result = '';
	        		foreach ($ipn_response as $key => $value) {
	        			$result .= ' & ' . $key . '=' . $value;	
	        		}
					$this->log->add( 'paynow', 'IPN details: ' . $result );
				}

				$order_id = $ipn_response[ "tx_id" ];
				$order_status = $ipn_response[ "tx_status" ];

				if ( $order_id != '' ){

					$order = wc_get_order ( $order_id );

					if ( $order ){

						// Check order not already completed
						if ( $order->has_status( 'completed' ) ) {
							if ( $this->is_test ) {
								$this->log->add( 'paynow', 'Aborting, Order #' . $order->id . ' is already complete.' );
							}
							exit;
						}

						if ( $order_status == "COMPLETED" || $order_status == "CONFIRMED" ){

							$order->update_status( 'completed', __( 'IPN payment completed', 'woocommerce' ) );
							$order->payment_complete( $order->id );

						} else if ( $order_status == "REJECTED" || $order_status == "ERROR" ){

							$order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), $order_status ) );

						}else {

							$order->add_order_note( sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), $order_status ) );

						}

						exit;
					}
				}
			} 
			
			wp_die( "Paynow IPN Request Failure", "Paynow IPN", array( 'response' => 200 ) );
        }

		/**
		* Return handler
		*/
        public function gateway_return_handler( $order_id ){
        	
        	$posted = stripslashes_deep( $_REQUEST );
        	
        	if ( $this->is_test ){
        		
        		$result = '';
        		foreach ($posted as $key => $value) {
        			$result .= ' & ' . $key . '=' . $value;	
        		}
        		
        		$this->log->add( 'paynow', 'Payment return: (' . $result . ')' );
        	}

        	if ( $this->instructions ) {
				echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
			}
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
					'title'       => __( 'Homologación', 'woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Ambiente de pruebas.', 'woocommerce' ),
					'default'     => 'no',
				),
				'debug' => array(
					'title'       => __( 'Trazas', 'woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Activar trazas', 'woocommerce' ),
					'default'     => 'no',
					'description' => sprintf( __( 'Guarda los eventos de Paynow, tales como los requests IPN, en el fichero <code>%s</code>', 'woocommerce' ), wc_get_log_file_path( 'paynow' ) )
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
                'rut_control_id' => array(
                    'title'       => __( 'Nombre del control para el RUT', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Aqui debe especificar el nombre del control donde los clientes epecificarán su RUT. Este valor es usado por algunos métodos de pago. Por ejemplo: Banco de estado.', 'woocommerce' ),
                    'default'     => __( '', 'woocommerce' ),
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
            
            //SI ESTÁ EN HOMOLOGACIÓN, PARÁMETRO qa SELECCIONADO POR DEFECTO
            //SE DEBERÍA UTILIZAR PAYNOW DE QA PARA PROBAR MIENTRAS EL CLIENTE
            //ESTE MONTANDO EL PLUGIN EN SU WordPress.
            //CUANDO FUNCIONE EN QA QUE DESMARQUE EL CHECK EN LAS SETTINGS
            
			$order = wc_get_order( $order_id );

			$post_data = array();

			//Identificar el negocio donde vas a recaudar tus ventas
			$post_data['businessID'] = $this->businessID;
			$post_data['tSource'] = 'API';
			$post_data['tSourceID'] = 'http://store.paynow.cl';
			$post_data['businessLogo'] = '';

			$item_loop = 0;
			if ( sizeof( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! $item['qty'] ) {
						continue;
					}

					$item_loop ++;

					$item_name = $item['name'];
					$item_meta = new WC_Order_Item_Meta( $item['item_meta'] );

					if ( $meta = $item_meta->display( true, true ) ) {
						$item_name .= ' ( ' . $meta . ' )';
					}

					// Detallar la información del producto que tu cliente va a comprar. -->
					$post_data[ 'itemDescription_' . $item_loop ] = $item_name;
					$post_data[ 'itemCurrency_' . $item_loop ] = get_woocommerce_currency();
					$post_data[ 'itemQuantity_' . $item_loop ] = $item['qty'];
					$post_data[ 'itemAmount_' . $item_loop ] = $order->get_item_subtotal( $item, false );
				}
				$post_data[ 'itemCount' ] = $item_loop;
			}

			// Discount
			if ( $order->get_cart_discount() > 0 ) {
				//$args['discount_amount_cart'] = round( $order->get_cart_discount(), 2 );
			}

			// Fees
			if ( sizeof( $order->get_fees() ) > 0 ) {
				foreach ( $order->get_fees() as $item ) {
					// $item_loop ++;
					// $args[ 'item_name_' . $item_loop ] = $this->paypal_item_name( $item['name'] );
					// $args[ 'quantity_' . $item_loop ]  = 1;
					// $args[ 'amount_' . $item_loop ]    = $item['line_total'];

					// if ( $args[ 'amount_' . $item_loop ] < 0 ) {
					// 	return false; // Abort - negative line
					// }
				}
			}

			// Mostrar formulario de despacho y facturación (Y = Solicita, N = NO Solicita)
			$post_data[ 'includeBilling' ] = 'N';

			$post_data[ 'buyerName' ] = $order->billing_first_name;
			$post_data[ 'buyerLastName' ] = $order->billing_last_name;
			$post_data[ 'buyerEmail' ] = $order->billing_email;
			$post_data[ 'buyerPhone' ] = $order->billing_phone;

			if ( $this->rut_control_id != '' ){
				$post_data[ 'buyerRut' ] = $_POST[ $this->rut_control_id ];
			}

			// $post_data[ 'billing_first_name' ] = $order->billing_first_name;
			// $post_data[ 'billing_last_name' ] = $order->billing_last_name;
			// $post_data[ 'billing_company' ] = $order->billing_company;
			// $post_data[ 'billing_address_1' ] = $order->billing_address_1;
			// $post_data[ 'billing_address_2' ] = $order->billing_address_2;
			// $post_data[ 'billing_city' ] = $order->billing_city;
			// $post_data[ 'billing_state' ] = $order->billing_state;
			// $post_data[ 'billing_postcode' ] = $order->billing_postcode;
			// $post_data[ 'billing_country' ] = $order->billing_country;
			// $post_data[ 'billing_email' ] = $order->billing_email;
			// $post_data[ 'billing_phone' ] = $order->billing_phone;

			$post_data[ 'billingComments' ] = '';

			$post_data[ 'paymentComments' ] = $_POST[ 'order_comments' ];

			$post_data[ 'includeShipping' ] = 'N';
			$post_data[ 'shippingAmount' ] = '0';
			if ( $order->get_total_shipping() > 0 ) {
				$post_data[ 'includeShipping' ] = 'Y';
				$post_data[ 'shippingAmount' ] = $order->get_total_shipping();
				$post_data[ 'shippingComments' ] = sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() );
			}

			$post_data[ 'return_url' ] = esc_url( $this->get_return_url( $order ) );
			$post_data[ 'cancel_url' ] = esc_url( $order->get_cancel_order_url() );
			$post_data[ 'notification_url' ] = $this->notify_url;

			$post_data[ 'skip_wizard' ] = 'Y';
			$post_data[ 'payment_app' ] = $_POST[ 'payment_app' ];

			$post_data[ 'internalTransactionID' ] = $order_id;

			$basic_auth = base64_encode( $this->businessID . ":" . $this->auth_token );

			$post_array = array(
				'method' => 'POST',
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array( 
					'Content-Type' => 'application/json',
					'Authorization' => 'Basic ' . $basic_auth 
				),
				'body' => $post_data,
				'cookies' => array()
		    );
		    
		    echo "<pre>";
		    print_r($this->post_url);
		    print_r('<br/>');
            print_r($post_array);

            // print_r($order);
		    // print_r($_POST);
            
            $response = wp_remote_post( $this->post_url, $post_array );

            print_r($response);

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __('Payment error: ', 'woothemes') . $response->get_error_message(), 'error' );
				return false;
			}
			
			$result = json_decode($response[ 'body' ], true);

			echo "<pre>";
            print_r($result);
            echo "</pre>";
			
			if ($result["status"] == 'ok') {
				// Mark as on-hold (we're awaiting the payment)
				$order->update_status( 'on-hold', __( 'Awaiting Paynow payment', 'woocommerce' ) );

				// Reduce stock levels
				$order->reduce_order_stock();

				// Remove cart
				WC()->cart->empty_cart();

				// Return thankyou redirect
				return array(
					'result' 	=> 'success',
					'redirect'	=> $this->post_url . "?token=" . $result["token"]
				);
			}
			else
			{
				wc_add_notice( __('Payment error:', 'woothemes') . $result["message"], 'error' );
				return;
			}
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
