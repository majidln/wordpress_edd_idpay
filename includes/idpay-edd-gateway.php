<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IDPAY_EDD_GATEWAY', 'idpay_edd_gateway' );

// registers the gateway
function idpay_edd_register_gateway( $gateways ) {
	if ( ! isset( $_SESSION ) ) {
		session_start();
	}

	$gateways[ IDPAY_EDD_GATEWAY ] = array(
		'admin_label'    => __( 'IDPay', 'idpay-for-edd' ),
		'checkout_label' => __( 'IDPay payment gateway', 'idpay-for-edd' ),
	);

	return $gateways;
}

/**
 * Hooks a function into the filter 'edd_payment_gateways' which is defined by
 * the EDD's core plugin.
 */
add_filter( 'edd_payment_gateways', 'idpay_edd_register_gateway' );

/**
 * Adds noting to the credit card form in the checkout page. In the other hand,
 * We want to disable the credit card form in the checkout.
 *
 * Therefore we just return.
 */
function idpay_edd_gateway_cc_form() {
	return;
}

/**
 * Hooks into edd_{payment gateway ID}_cc_form which is defined
 * by the EDD's core plugin.
 */
add_action( 'edd_' . IDPAY_EDD_GATEWAY . '_cc_form', 'idpay_edd_gateway_cc_form' );

/**
 * Adds the IDPay gateway settings to the Payment Gateways section.
 *
 * @param $settings
 *
 * @return array
 */
function idpay_edd_add_settings( $settings ) {

	$idpay_gateway_settings = array(
		array(
			'id'   => 'idpay_edd_gateway_settings',
			'type' => 'header',
			'name' => __( 'IDPay payment gateway', 'idpay-for-edd' ),
		),
		array(
			'id'   => 'idpay_api_key',
			'type' => 'text',
			'name' => 'API Key',
			'size' => 'regular',
			'desc' => __( 'You can create an API Key by going to your <a href="https://idpay.ir/dashboard/web-services">IDPay account</a>.', 'idpay-for-edd' ),
		),
		array(
			'id'      => 'idpay_sandbox',
			'type'    => 'checkbox',
			'name'    => __( 'Sandbox', 'idpay-for-edd' ),
			'default' => 0,
			'desc'    => __( 'If you check this option, the gateway will work in Test (Sandbox) mode.', 'idpay-for-edd' ),
		),
	);

	return array_merge( $settings, $idpay_gateway_settings );
}

/**
 * Hooks a function into the filter 'edd_settings_gateways' which is defined by
 * the EDD's core plugin.
 */
add_filter( 'edd_settings_gateways', 'idpay_edd_add_settings' );

/**
 * Creates a payment on the gateway.
 * See https://idpay.ir/web-service for more information.
 *
 * @param $purchase_data
 *  The argument which will be passed to
 *  the hook edd_gateway_{payment gateway ID}
 *
 * @return bool
 */
function idpay_edd_create_payment( $purchase_data ) {
	global $edd_options;

	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => $edd_options['currency'],
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status'       => 'pending',
	);

	// record the pending payment
	$payment_id = edd_insert_payment( $payment_data );

	if ( empty( $payment_id ) ) {
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}

	$api_key = empty( $edd_options['idpay_api_key'] ) ? '' : $edd_options['idpay_api_key'];
	$sandbox = empty( $edd_options['idpay_sandbox'] ) ? 'false' : 'true';

	$amount   = idpay_edd_get_amount( intval( $purchase_data['price'] ), edd_get_currency() );
	$desc     = __( 'Order number #', 'idpay-for-edd' ) . $payment_id;
	$callback = add_query_arg( 'verify_idpay_edd_gateway', '1', get_permalink( $edd_options['success_page'] ) );

	if ( empty( $amount ) ) {
		$message = __( 'Selected currency is not supported.', 'idpay-for-edd' );
		edd_insert_payment_note( $payment_id, $message );
		edd_update_payment_status( $payment_id, 'failed' );
		edd_set_error( 'idpay_connect_error', $message );
		edd_send_back_to_checkout();

		return FALSE;
	}

	$user_info = $purchase_data['user_info'];
	$name      = $user_info['first_name'] . ' ' . $user_info['last_name'];
	$mail      = $user_info['email'];

	$data = array(
		'order_id' => $payment_id,
		'amount'   => $amount,
		'name'     => $name,
		'phone'    => '',
		'mail'     => $mail,
		'desc'     => $desc,
		'callback' => $callback,
	);

	$headers = array(
		'Content-Type' => 'application/json',
		'X-API-KEY'    => $api_key,
		'X-SANDBOX'    => $sandbox,
	);

	$args     = array(
		'body'    => json_encode( $data ),
		'headers' => $headers,
		'timeout' => 15,
	);
	$response = idpay_edd_call_gateway_endpoint( 'https://api.idpay.ir/v1.1/payment', $args );
	if ( is_wp_error( $response ) ) {
		$note = $response->get_error_message();
		edd_insert_payment_note( $payment_id, $note );

		return FALSE;
	}
	$http_status = wp_remote_retrieve_response_code( $response );
	$result      = wp_remote_retrieve_body( $response );
	$result      = json_decode( $result );

	if ( $http_status != 201 || empty( $result ) || empty( $result->link ) ) {
		$message = $result->error_message;
		edd_insert_payment_note( $payment_id, $http_status . ' - ' . $message );
		edd_update_payment_status( $payment_id, 'failed' );
		edd_set_error( 'idpay_connect_error', $message );
		edd_send_back_to_checkout();

		return FALSE;
	}

	// Saves transaction id and link
	edd_insert_payment_note( $payment_id, __( 'Transaction ID: ', 'idpay-for-edd' ) . $result->id );
	edd_insert_payment_note( $payment_id, __( 'Redirecting to the payment gateway.', 'idpay-for-edd' ) );

	edd_update_payment_meta( $payment_id, '_idpay_edd_transaction_id', $result->id );
	edd_update_payment_meta( $payment_id, '_idpay_edd_transaction_link', $result->link );

	$_SESSION['idpay_payment'] = $payment_id;

	wp_redirect( $result->link );
}

/**
 * Hooks into edd_gateway_{payment gateway ID} which is defined in the
 * EDD's core plugin.
 */
add_action( 'edd_gateway_' . IDPAY_EDD_GATEWAY, 'idpay_edd_create_payment' );

/**
 * Verify the payment created on the gateway.
 *
 * See https://idpay.ir/web-service for more information.
 */
function idpay_edd_verify_payment() {
	global $edd_options;


	$status         = sanitize_text_field( $_POST['status'] );
	$track_id       = sanitize_text_field( $_POST['track_id'] );
	$id             = sanitize_text_field( $_POST['id'] );
	$order_id       = sanitize_text_field( $_POST['order_id'] );
	$amount         = sanitize_text_field( $_POST['amount'] );
	$card_no        = sanitize_text_field( $_POST['card_no'] );
	$hashed_card_no = sanitize_text_field( $_POST['hashed_card_no'] );
	$date           = sanitize_text_field( $_POST['date'] );

	if ( empty( $id ) || empty( $order_id ) ) {

		return FALSE;
	}

	$payment = edd_get_payment( $_SESSION['idpay_payment'] );
	unset( $_SESSION['idpay_payment'] );

	if ( ! $payment ) {
		wp_die( __( 'The information sent is not correct.', 'idpay-for-edd' ) );
	}

	if ( idpay_edd_double_spending_occurred( $payment->ID, $id ) ) {
		wp_die( __( 'The information sent is not correct.', 'idpay-for-edd' ) );
	}

	if ( $payment->status != 'pending' ) {
		return FALSE;
	}


	// Stores payment's meta data.
	edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_status', $status );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_track_id', $track_id );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_id', $id );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_order_id', $order_id );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_amount', $amount );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_card_no', $card_no );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_hashed_card_no', $hashed_card_no );
	edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_date', $date );


	if ( isset( $status ) && $status == 10 ) {


		$api_key = empty( $edd_options['idpay_api_key'] ) ? '' : $edd_options['idpay_api_key'];
		$sandbox = empty( $edd_options['idpay_sandbox'] ) ? 'false' : 'true';

		$data = array(
			'id'       => $_POST['id'],
			'order_id' => $payment->ID,
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'X-API-KEY'    => $api_key,
			'X-SANDBOX'    => $sandbox,
		);

		$args = array(
			'body'    => json_encode( $data ),
			'headers' => $headers,
			'timeout' => 15,
		);

		$response = idpay_edd_call_gateway_endpoint( 'https://api.idpay.ir/v1.1/payment/verify', $args );
		if ( is_wp_error( $response ) ) {
			$note = $response->get_error_message();
			edd_insert_payment_note( $payment->ID, $note );

			return FALSE;
		}
		$http_status = wp_remote_retrieve_response_code( $response );
		$result      = wp_remote_retrieve_body( $response );
		$result      = json_decode( $result );

		if ( $http_status != 200 ) {
			$message = $result->error_message;
			edd_insert_payment_note( $payment->ID, $http_status . ' - ' . $message );
			edd_update_payment_status( $payment->ID, 'failed' );
			edd_set_error( 'idpay_connect_error', $message );
			edd_send_back_to_checkout();

			return FALSE;
		}

		edd_insert_payment_note( $payment->ID, $result->status . ' - ' . idpay_edd_get_verification_status_message( $result->status ) );
		edd_insert_payment_note( $payment->ID, __( 'IDPay tracking id: ', 'idpay-for-edd' ) . $result->track_id );
		if ( ! empty( $result->payment ) ) {
			edd_insert_payment_note( $payment->ID, __( 'Payer card number: ', 'idpay-for-edd' ) . $result->payment->card_no );
		}

		// Updates payment's meta data.
		edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_status', $result->status );
		edd_update_payment_meta( $payment->ID, '_idpay_edd_track_id', $result->track_id );
		edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_id', $result->id );
		edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_order_id', $result->order_id );
		edd_update_payment_meta( $payment->ID, '_idpay_edd_transaction_amount', $result->amount );
		if ( ! empty( $result->payment ) ) {
			edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_card_no', $result->payment->card_no );
			edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_hashed_card_no', $result->payment->hashed_card_no );
			edd_update_payment_meta( $payment->ID, '_idpay_edd_payment_date', $result->payment->date );
		}

		if ( $result->status >= 100 ) {
			edd_empty_cart();
			edd_update_payment_status( $payment->ID, 'publish' );
			edd_send_to_success_page();
		} else {
			edd_update_payment_status( $payment->ID, 'failed' );
			wp_redirect( get_permalink( $edd_options['failure_page'] ) );
		}
	} else {
		edd_insert_payment_note( $payment->ID, $status . ' - ' . idpay_edd_get_verification_status_message( $status ) );
		edd_insert_payment_note( $payment->ID, __( 'IDPay tracking id: ', 'idpay-for-edd' ) . $track_id );
		edd_insert_payment_note( $payment->ID, __( 'Payer card number: ', 'idpay-for-edd' ) . $card_no );

		edd_update_payment_status( $payment->ID, 'failed' );
		wp_redirect( get_permalink( $edd_options['failure_page'] ) );

		exit;
	}
}

/**
 * Hooks into our custom hook in order to verifying the payment.
 */
add_action( 'idpay_edd_verify', 'idpay_edd_verify_payment' );

/**
 * Helper function to obtain the amount by considering whether a unit price is
 * in Iranian Rial Or Iranian Toman unit.
 *
 * As the IDPay gateway accepts orders with IRR unit price, We must convert
 * Tomans into Rials by multiplying them by 10.
 *
 * @param $amount
 * @param $currency
 *
 * @return float|int
 */
function idpay_edd_get_amount( $amount, $currency ) {
	switch ( strtolower( $currency ) ) {
		case strtolower( 'IRR' ):
		case strtolower( 'RIAL' ):
			return $amount;

		case strtolower( 'تومان ایران' ):
		case strtolower( 'تومان' ):
		case strtolower( 'IRT' ):
		case strtolower( 'Iranian_TOMAN' ):
		case strtolower( 'Iran_TOMAN' ):
		case strtolower( 'Iranian-TOMAN' ):
		case strtolower( 'Iran-TOMAN' ):
		case strtolower( 'TOMAN' ):
		case strtolower( 'Iran TOMAN' ):
		case strtolower( 'Iranian TOMAN' ):
			return $amount * 10;

		case strtolower( 'IRHT' ):
			return $amount * 10000;

		case strtolower( 'IRHR' ):
			return $amount * 1000;

		default:
			return 0;
	}
}

/**
 * Helper function to the obtain gateway messages at the verification endpoint
 * according to their codes.
 *
 * for more information refer to the gateway documentation:
 * https://idpay.ir/web-service
 *
 * @param $code
 *
 * @return string
 */
function idpay_edd_get_verification_status_message( $code ) {
	switch ( $code ) {
		case 1:
			return __( 'Payment has not been made.', 'idpay-for-edd' );

		case 2:
			return __( 'Payment has been unsuccessful.', 'idpay-for-edd' );

		case 3:
			return __( 'An error occurred.', 'idpay-for-edd' );

		case 4:
			return __( 'Payment has been blocked.', 'idpay-for-edd' );

		case 5:
			return __( 'Returned to the payer.', 'idpay-for-edd' );

		case 6:
			return __( 'System returned.', 'idpay-for-edd' );

		case 10:
			return __( 'Pending verification.', 'idpay-for-edd' );

		case 100:
			return __( 'Payment has been verified.', 'idpay-for-edd' );

		case 101:
			return __( 'Payment has already been verified.', 'idpay-for-edd' );

		case 200:
			return __( 'To the payee was deposited.', 'idpay-for-edd' );

		default:
			return __( 'The code has not been defined.', 'idpay-for-edd' );
	}
}

/**
 * Checks if double-spending has been occurred.
 *
 * @param $payment_id
 * @param $remote_id
 *
 * @return bool
 */
function idpay_edd_double_spending_occurred( $payment_id, $remote_id ) {
	if ( get_post_meta( $payment_id, '_idpay_edd_transaction_id', TRUE ) != $remote_id ) {
		return TRUE;
	}

	return FALSE;
}

/**
 * Calls the gateway endpoints.
 *
 * Tries to get response from the gateway for 4 times.
 *
 * @param $url
 * @param $args
 *
 * @return array|\WP_Error
 */
function idpay_edd_call_gateway_endpoint( $url, $args ) {
	$number_of_connection_tries = 4;
	while ( $number_of_connection_tries ) {
		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			$number_of_connection_tries --;
			continue;
		} else {
			break;
		}
	}

	return $response;
}


/**
 * Listen to incoming queries
 *
 * @return void
 */
function idpay_edd_listen() {
	$verify_param = sanitize_text_field( $_GET[ 'verify_' . IDPAY_EDD_GATEWAY ] );
	if ( isset( $verify_param ) && $verify_param ) {

		// Executes the function(s) hooked into our custom hook for verifying the payment.
		do_action( 'idpay_edd_verify' );
	}
}

/**
 * Hooks the idpay_edd_listen() function into the Wordpress initializing
 * process.
 */
add_action( 'init', 'idpay_edd_listen' );
