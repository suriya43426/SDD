<?php
/**
 * Plugin Name: WooCommerce LINEPay Gateway
 * Plugin URI: https://pay.line.me
 * Description: Payments are received through the LINE Pay gateway, which supports USD, JPY, TWD, and THB. In order to use LINE Pay, you must have a Channel ID and Channel SecretKey.
 * Author: LINEPay
 * Author URI: https://pay.line.me
 * Developer: donggyu-seo@linecorp.com
 * Version: 1.0.0
 *
 * Copyright (c) 2015 LINEPay
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin updates
 * Localization
 */
load_plugin_textdomain( 'woocommerce-gateway-linepay', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) . '/languages' ) );

/**
 * 
 * WC_Gateway_LINEPay_Handler 클래스는 아래의 역할을 담당한다.
 * 1. woocommerce에 WC_Gateway_LINEPay 등록 
 * 2. 사용자 계정 탭에서 환불 가능하도록 추가
 * 3. 콜백 요청을 처리
 * 
 * @class 		WC_Gateway_LINEPay_Handler
 * @version		1.0.0
 * @author 		LINEPay
 */
class WC_Gateway_LINEPay_Handler {
	
	/**
	 * @var	WC_Gateway_LINEpay_Logger 인스턴스
	 */
	private static $LOGGER;
	
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_wc_gateway_linepay_handler' ) );
	}
	
	/**
	 * WC_Gateway_LINEPay_Handler를 초기화한다.
	 */
	function init_wc_gateway_linepay_handler() {
		
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		
		include_once( 'includes/class-wc-gateway-linepay-const.php' );
		include_once( 'includes/class-wc-gateway-linepay-logger.php' );
		include_once( 'includes/class-wc-gateway-linepay.php' );
		
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_filter( 'woocommerce_my_account_my_orders_title', array( $this, 'append_script_for_refund_action' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions',  array( $this, 'change_customer_order_action' ), 10, 2 );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_callback' ) );		
		
		// linepay setting 등록
		$this->linepay_settings = get_option('woocommerce_' . WC_Gateway_LINEPay_Const::ID . '_settings');
		
		// logger 등록
		$linepay_log_info		= array(
				'enabled'	=> WC_Gateway_LINEPay_Const::TYPE_YES === $this->linepay_settings[ 'log_enabled' ],
				'level'		=> ( $this->linepay_settings[ 'log_enabled' ] !== '' ) ? $this->linepay_settings[ 'log_enabled' ] : WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE 
		);
		static::$LOGGER = WC_Gateway_LINEPay_Logger::get_instance( $linepay_log_info );
	}
	
	/**
	 * WooCommerce에 LINEPay payment provider를 등록한다.
	 * 
	 * @see woocommerce::filter - woocommerce_payment_gateways
	 * @param array $methods
	 * @return array
	 */
	function add_gateway( $methods ) {
		$methods[] = 'WC_Gateway_LINEPay';
	
		return $methods;
	}

	/**
	 * LINEPay payment provider에서 보내는 Callback 요청을 처리한다.
	 * 
	 * 콜백은 아래 상태에 대해서만 처리할 수 있다.
	 * payment status	: reserved
	 * 	-> request type	: confirm, cancel
	 * 
	 * payment status	: confirmed
	 * 	-> request type	: refund
	 * 
	 * 처리하지 못할 경우 에러로그를 남긴다.
	 * 
	 * @see woocommerce::action - woocommerce_api_
	 */
	function handle_callback() {
		
		try {
			$order_id		= wc_get_order_id_by_order_key( $_GET[ 'order_key' ] );
			if ( empty( $order_id ) ) {
				
				throw new Exception( sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) ); 
			}
			
			$request_type	= $_GET[ 'request_type' ];
			$payment_status	= get_post_meta( $order_id, '_linepay_payment_status', true );
			
			$linepay_gateway = new WC_Gateway_LINEPay();
			if ( $payment_status == WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED ) {
					
				switch ( $request_type ) {
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM :
						$linepay_gateway->process_payment_confirm( $order_id );
						break;
			
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL :
						$linepay_gateway->process_payment_cancel( $order_id );
						break;
				}
					
			}
			else if ($payment_status == WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED ) {
					
				switch ( $request_type ) {
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND :
						$this->process_refund_by_customer( $linepay_gateway, $order_id );
						break;
				}
					
			}
			
			static::$LOGGER->error( 'handle_callback', sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_REQUREST, $order_id, $payment_status, $request_type, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
		}
		
		catch ( Exception $e ) {

			// error log 남기기
			static::$LOGGER->error( 'handle_callback', $e->getMessage() );
		}
		
	}
	
	/**
	 * 사용자의 환불 요청을 처리한다.
	 * 
	 * 환불을 사용자가 요청할 경우 관리자가 요청할 때 실행하는 WC_AJAX::refund_line_items()을
	 * 먼저 호출할 수 없기 때문에 이를 담당할 메소드를 새로 정의한다.
	 * 
	 * 환불주문을 생성하여 수량, 총 금액, 세금을 복귀하고 환불을 요청한다.
	 * 
	 * @see		WC_AJAX::refund_line_items()
	 * @param	WC_Gateway_LINEPay $linepay_gateway
	 * @param	int $order_id
	 * @throws	Exception
	 */
	function process_refund_by_customer( $linepay_gateway, $order_id ) {
		$order			= wc_get_order( $order_id );
		$refund_amount	= wc_format_decimal( sanitize_text_field( $_GET[ 'cancel_amount' ] ) );
		/*
		 * 사용자가 환불할 때 환불 사유를 작성할 경우 활성화 필요
		 * $refund_reason	= sanitize_text_field ($_GET[ 'reason' ] );
		 */
		
		$line_items = array();
		$items				= $order->get_items();
		$shipping_methods	= $order->get_shipping_methods();
		
		// items
		foreach ( $items as $item_id => $item ) {
			$line_tax_data = unserialize( $item[ 'line_tax_data' ] );
			$line_item = array( 'qty' => $item[ 'qty' ], 'refund_total' => wc_format_decimal( $item[ 'line_total' ] ), 'refund_tax' => $line_tax_data[ 'total' ] );
			$line_items[ $item_id ] = $line_item;
		}
		
		// shipping
		foreach ( $shipping_methods as $shipping_id => $shipping ) {
			$line_item = array( 'refund_total' => wc_format_decimal( $shipping[ 'cost' ] ), 'refund_tax' => unserialize( $shipping[ 'taxes' ] ) ); 
			$line_items[ $shipping_id ] = $line_item;
		}
		
		try {
			$refund = wc_create_refund( array(
					'amount'     => $refund_amount,
					'reason'     => $refund_reason,
					'order_id'   => $order_id,
					'line_items' => $line_items
			) );
			
			if ( is_wp_error( $refund ) ) {
				
				throw new Exception( $refund->get_error_message() );
			}
			
			// 환불처리
			$result = $linepay_gateway->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER, $order_id, $refund_amount, $refund_reason );
			
			if ( is_wp_error( $result ) || ! $result ) {
				static::$LOGGER->error( 'process_refund_request_by_customer', $result );
				
				throw new Exception( $result->get_error_message() );
			}
			
			// 아이템 수량 복귀
			foreach ( $items as $item_id => $item ) {
				$qty = $item[ 'qty' ];
				$_product   = $order->get_product_from_item( $item );
				
				if ( $_product && $_product->exists() && $_product->managing_stock() ) {
					$old_stock    = wc_stock_amount( $_product->stock );
					$new_quantity = $_product->increase_stock( $qty );
						
					$order->add_order_note( sprintf( __( 'Item #%s stock increased from %s to %s.', 'woocommerce' ), $order_item['product_id'], $old_stock, $new_quantity ) );
						
					do_action( 'woocommerce_restock_refunded_item', $_product->id, $old_stock, $new_quantity, $order );
				}
				
			}

			wc_delete_shop_order_transients( $order_id );
			
			wc_add_notice( __( 'Refund complete.', 'woocommerce_gateway_linepay' ) );
			wp_send_json_success( array( 'info' => 'fully_refunded' ) );
			
		} catch ( Exception $e ) {
			
			if ( $refund && is_a( $refund, 'WC_Order_Refund' ) ) {
				wp_delete_post( $refund->id, true );
			}
		
			wc_add_wp_error_notices( new WP_Error( 'process_refund_by_customer', __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) ) );
			wp_send_json_error( array( 'info' => $e->getMessage() ) );
		}
		
	}
	
	/**
	 * 소비자의 환불 처리를 도와줄 스크립트 파일과 내부적으로 사용할 언어 정보를 담을 스크립트를 등록한다.
	 * 나의 계정이 로드될 때 최초 1회만 등록하기 위해 woocommerce_my_account_my_orders_title 필터를 사용했다.
	 * 따라서 title에 대한 별다른 변경을 하지 않는다.
	 * 
	 * @see woocommerce::filter - woocommerce_my_account_my_orders_title
	 * @param String $title
	 * @return String
	 */
	function append_script_for_refund_action( $title ) {
		
		// 소비자 환불처리 스크립트 등록
		wp_register_script( 'wc-gateway-linepay-customer-refund-action', untrailingslashit( plugins_url( '/', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_CUSTOMER_REFUND_ACTION );
		wp_enqueue_script( 'wc-gateway-linepay-customer-refund-action' );
		
		// 스크립트에서 사용할 언어정보 등록
		$lang_process_refund	= __( 'Processing refund...', 'woocommerce-gateway-linepay' );
		$lang_request_refund	= __( 'Request refund for order {order_id}', 'woocommerce-gateway-linepay' );
		$lang_cancel			= __( 'Cancel', 'woocommerce-gateway-linepay' );
		
		$lang_script = '<script>
					function linepay_lang_pack() {
						return { \'process_refund\': \'' . $lang_process_refund . '\',
								\'request_refund\':\'' . $lang_request_refund . '\',
								\'cancel\':\'' . $lang_cancel . '\'
							};
					}
				</script>';
		echo $lang_script;
		
		return $title;
	}
	
	
	/**
	 * 
	 * 나의 계정의 주문 내역마다 사용자의 환불 액션을 추가한다.
	 * admin 설정에서 사용자 환불 상태로 변경할 수 있다.
	 * 라인페이 결제 실패시 재구매 혹은 취소할 수 있는 액션을 제거한다.
	 * 
	 * @see woocommerce:filter - woocommerce_my_account_my_orders_actions 
	 * @param array $actions
	 * @param WC_Order $order
	 * @return array
	 */
	function change_customer_order_action( $actions, $order ) {
		$order_status = $order->get_status();
		
		switch ( $order_status ) {
			case 'failed' :
				$payment_method = get_post_meta( $order->id, '_payment_method' );
				if ($payment_method[0] !== WC_Gateway_LINEPay_Const::ID ) {
					break;
				}
				
				unset ( $actions[ 'pay' ] );
				unset ( $actions[ 'cancel' ] );
				
				break;
		}
	
		if ( in_array( 'wc-' . $order_status, $this->linepay_settings[ 'customer_refund' ] ) ) {
			$actions[ 'cancel' ] = array(
					'url'	=> esc_url_raw( add_query_arg( array( 'request_type' => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND, 'order_key' => $order->order_key, 'cancel_amount' => $order->get_total() ), home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER ) ) ),
					'name'	=> __( 'Cancel', 'woocommerce-gateway-linepay' )
			);
		}
	
		return $actions;
	}
}

$GLOBALS['wc_gateway_linepay_handler'] = new WC_Gateway_LINEPay_Handler();
