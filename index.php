<?php

add_action( 'rcl_payments_gateway_init', 'rcl_gateway_walletone_init', 10 );
function rcl_gateway_walletone_init() {
	rcl_gateway_register( 'walletone', 'Rcl_Walletone_Payment' );
}

class Rcl_Walletone_Payment extends Rcl_Gateway_Core {

	public $payments_json;
	public $curs = array( 'RUB' => 643, 'UAH' => 980, 'KZT' => 398, 'USD' => 840, 'EUR' => 978 );

	function __construct() {
		parent::__construct( array(
			'request'	 => 'WMI_PAYMENT_NO',
			'name'		 => rcl_get_commerce_option( 'wo_custom_name', 'WalletOne' ),
			'submit'	 => __( 'Оплатить через WalletOne' ),
			'icon'		 => rcl_addon_url( 'icon.jpg', __FILE__ )
		) );
	}

	function get_options() {

		return array(
			array(
				'type'			 => 'text',
				'slug'			 => 'wo_custom_name',
				'title'			 => __( 'Наименование платежной системы' ),
				'placeholder'	 => 'WalletOne'
			),
			array(
				'type'	 => 'text',
				'slug'	 => 'WO_MERCHANT_ID',
				'title'	 => __( 'Merchant ID' )
			),
			array(
				'type'	 => 'password',
				'slug'	 => 'WO_SECRET_KEY',
				'title'	 => __( 'Секретный ключ' )
			),
			array(
				'type'		 => 'select',
				'slug'		 => 'WO_FN',
				'title'		 => __( 'Фискализация платежа' ),
				'values'	 => array(
					__( 'Отключено' ),
					__( 'Включено' )
				),
				'childrens'	 => array(
					1 => array(
						array(
							'type'	 => 'select',
							'slug'	 => 'WO_NDS',
							'title'	 => __( 'Ставка НДС' ),
							'values' => array(
								'tax_ru_1'	 => __( 'без НДС' ),
								'tax_ru_2'	 => __( 'НДС по ставке 0%' ),
								'tax_ru_3'	 => __( 'НДС по ставке 10%' ),
								'tax_ru_4'	 => __( 'НДС по ставке 18%' ),
								'tax_ru_5'	 => __( 'НДС по ставке 10/110' ),
								'tax_ru_6'	 => __( 'НДС по ставке 18/118' )
							)
						)
					)
				)
			)
		);
	}

	function get_form( $data ) {

		$merchant_id = rcl_get_commerce_option( 'WO_MERCHANT_ID' );
		$secret_key	 = rcl_get_commerce_option( 'WO_SECRET_KEY' );

		$fields = array(
			'WMI_MERCHANT_ID'	 => $merchant_id,
			'WMI_PAYMENT_AMOUNT' => round( str_replace( ',', '.', $data->pay_summ ), 2 ),
			'WMI_CURRENCY_ID'	 => (isset( $this->curs[$data->currency] )) ? $this->curs[$data->currency] : 643,
			'WMI_PAYMENT_NO'	 => $data->pay_id,
			'WMI_DESCRIPTION'	 => "BASE64:" . base64_encode( $data->description ),
			'WMI_SUCCESS_URL'	 => add_query_arg( array(
				'payment-type' => $data->pay_type
				), get_permalink( $data->page_successfully ) ),
			'WMI_FAIL_URL'		 => get_permalink( $data->page_fail ),
			'WO_USER_ID'		 => $data->user_id,
			'WO_TYPE_PAY'		 => $data->pay_type,
			'WO_BAGGAGE_DATA'	 => $data->baggage_data,
			'CMS'				 => 30
		);

		if ( rcl_get_commerce_option( 'WO_FN' ) ) {

			$items = array();

			if ( $data->pay_type == 1 ) {

				$orderData[] = array(
					"Title"		 => __( 'Пополнение личного счета' ),
					"Quantity"	 => 1,
					"UnitPrice"	 => $data->pay_summ,
					"SubTotal"	 => $data->pay_summ,
					"TaxType"	 => rcl_get_commerce_option( 'WO_NDS' ),
					"Tax"		 => $this->get_tax( $data->pay_summ )
				);
			} else if ( $data->pay_type == 2 ) {

				$order = rcl_get_order( $data->pay_id );

				if ( $order ) {

					foreach ( $order->products as $product ) {

						$total = $product->product_price * $product->product_amount;

						$orderData[] = array(
							"Title"		 => get_the_title( $product->product_id ),
							"Quantity"	 => $product->product_amount,
							"UnitPrice"	 => $product->product_price,
							"SubTotal"	 => $total,
							"TaxType"	 => rcl_get_commerce_option( 'WO_NDS' ),
							"Tax"		 => $this->get_tax( $total )
						);
					}
				}
			} else {

				$orderData[] = array(
					"Title"		 => $data->description,
					"Quantity"	 => 1,
					"UnitPrice"	 => $data->pay_summ,
					"SubTotal"	 => $data->pay_summ,
					"TaxType"	 => rcl_get_commerce_option( 'WO_NDS' ),
					"Tax"		 => $this->get_tax( $data->pay_summ )
				);
			}

			$fields['WMI_ORDER_ITEMS']		 = json_encode( $orderData );
			$fields['WMI_CUSTOMER_EMAIL']	 = get_the_author_meta( 'user_email', $data->user_id );
		}

		//Сортировка значений внутри полей
		foreach ( $fields as $name => $val ) {
			if ( is_array( $val ) ) {
				usort( $val, "strcasecmp" );
				$fields[$name] = $val;
			}
		}

		// Формирование сообщения, путем объединения значений формы,
		// отсортированных по именам ключей в порядке возрастания.
		uksort( $fields, "strcasecmp" );
		$fieldValues = "";

		foreach ( $fields as $value ) {
			if ( is_array( $value ) )
				foreach ( $value as $v ) {
					//Конвертация из текущей кодировки (UTF-8)
					//необходима только если кодировка магазина отлична от Windows-1251
					$v = iconv( "utf-8", "windows-1251", $v );
					$fieldValues .= $v;
				} else {
				//Конвертация из текущей кодировки (UTF-8)
				//необходима только если кодировка магазина отлична от Windows-1251
				$value = iconv( "utf-8", "windows-1251", $value );
				$fieldValues .= $value;
			}
		}

		// Формирование значения параметра WMI_SIGNATURE, путем
		// вычисления отпечатка, сформированного выше сообщения,
		// по алгоритму MD5 и представление его в Base64
		$signature = base64_encode( pack( "H*", md5( $fieldValues . $secret_key ) ) );

		//Добавление параметра WMI_SIGNATURE в словарь параметров формы
		$fields["WMI_SIGNATURE"] = $signature;

		return parent::construct_form( array(
				'action' => 'https://wl.walletone.com/checkout/checkout/Index',
				'fields' => $fields
			) );
	}

	function get_tax( $total ) {

		$taxes = array(
			'tax_ru_1'	 => 'round(%.2f * 0 / 100,2)',
			'tax_ru_2'	 => 'round(%.2f * 0 / 100,2)',
			'tax_ru_3'	 => 'round(%.2f * 10 / 100,2)',
			'tax_ru_4'	 => 'round(%.2f * 18 / 100,2)',
			'tax_ru_5'	 => 'round(%.2f * 10 / 110,2)',
			'tax_ru_6'	 => 'round(%.2f * 18 / 118,2)',
		);

		$tax_formula = isset( $taxes[rcl_get_commerce_option( 'WO_NDS' )] ) ? $taxes[rcl_get_commerce_option( 'WO_NDS' )] : '0';

		return eval( 'return ' . sprintf( $tax_formula, $total ) . ';' );
	}

	function result( $data ) {

		$secret_key = rcl_get_commerce_option( 'WO_SECRET_KEY' );

		if ( ! isset( $_REQUEST["WMI_SIGNATURE"] ) )
			$this->print_answer( "Retry", "Отсутствует параметр WMI_SIGNATURE" );

		if ( ! isset( $_REQUEST["WMI_PAYMENT_NO"] ) )
			$this->print_answer( "Retry", "Отсутствует параметр WMI_PAYMENT_NO" );

		if ( ! isset( $_REQUEST["WMI_ORDER_STATE"] ) )
			$this->print_answer( "Retry", "Отсутствует параметр WMI_ORDER_STATE" );

		// Извлечение всех параметров POST-запроса, кроме WMI_SIGNATURE
		foreach ( $_REQUEST as $name => $value ) {
			if ( $name !== "WMI_SIGNATURE" )
				$params[$name] = wp_unslash( $value );
		}

		// Сортировка массива по именам ключей в порядке возрастания
		// и формирование сообщения, путем объединения значений формы
		uksort( $params, "strcasecmp" );
		$values = "";

		foreach ( $params as $name => $value ) {
			//Конвертация из текущей кодировки (UTF-8)
			//необходима только если кодировка магазина отлична от Windows-1251
			//$value = iconv("utf-8", "windows-1251", $value);
			$values .= $value;
		}

		// Формирование подписи для сравнения ее с параметром WMI_SIGNATURE
		$signature = base64_encode( pack( "H*", md5( $values . $secret_key ) ) );

		//Сравнение полученной подписи с подписью W1
		if ( $signature == $_REQUEST["WMI_SIGNATURE"] ) {
			if ( strtoupper( $_REQUEST["WMI_ORDER_STATE"] ) == "ACCEPTED" ) {

				if ( ! parent::get_payment( $POST["WMI_PAYMENT_NO"] ) ) {

					parent::insert_payment( array(
						'pay_id'		 => $POST["WMI_PAYMENT_NO"],
						'pay_summ'		 => $POST['WMI_PAYMENT_AMOUNT'],
						'user_id'		 => $POST["WO_USER_ID"],
						'pay_type'		 => $POST["WO_TYPE_PAY"],
						'baggage_data'	 => $POST["WO_BAGGAGE_DATA"]
					) );

					print "WMI_RESULT=" . strtoupper( "Ok" ) . "&";
					print "WMI_DESCRIPTION=" . urlencode( "Заказ #" . $_POST["WMI_PAYMENT_NO"] . " оплачен!" );
					exit;
				}
			} else {
				// Случилось что-то странное, пришло неизвестное состояние заказа
				$this->print_answer( "Retry", "Неверное состояние " . $_REQUEST["WMI_ORDER_STATE"] );
			}
		} else {
			// Подпись не совпадает, возможно вы поменяли настройки интернет-магазина
			$this->print_answer( "Retry", "Неверная подпись " . $_REQUEST["WMI_SIGNATURE"], $signature );
		}
	}

	function print_answer( $result, $description, $signature = false ) {
		print "WMI_RESULT=" . strtoupper( $result ) . "&";
		print "WMI_DESCRIPTION=" . urlencode( $description );
		rcl_mail_payment_error( $signature );
		exit();
	}

}
