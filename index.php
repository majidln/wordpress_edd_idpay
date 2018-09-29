<?php
/**
 * Plugin Name: IDPay for EDD
 * Author: Majid LotfiNia
 * Description: درگاه پرداخت امن آیدی پی برای پرداخت در افزودنه دانلود دیجیتال آسان
 * Version: 0.1
 * Author URI: majidlotfinia.ir
 * Author Email: majidlotfinia12@gmail.com
 */

// registers the gateway
function idpay_edd_register_gateway($gateways) {
    if(!isset($_SESSION))
    {
        session_start();
    }
    $gateways['idpay_edd_gateway'] = array('admin_label' => 'آیدی پی', 'checkout_label' => __('پرداخت آنلاین آیدی پی', 'idpay_edd_gateway'));
    return $gateways;
}
add_filter('edd_payment_gateways', 'idpay_edd_register_gateway');

//disable payment form in checkout
function idpay_edd_register_gateway_cc_form() {
    // register the action to remove default CC form
    return;
}
add_action('edd_idpay_edd_gateway_cc_form', 'idpay_edd_register_gateway_cc_form');

// adds the settings to the Payment Gateways section
function pw_edd_add_settings($settings) {

    $sample_gateway_settings = array(
        array(
            'id' => 'idpay_edd_gateway_settings',
            'name' => '<strong>' . __('درگاه پرداخت آیدی پی', 'idpay_edd_gateway') . '</strong>',
            'desc' => __('تنظیمات درگاه پرداخت آیدی پی', 'idpay_edd_gateway'),
            'type' => 'header'
        ),
        array(
            'id' => 'idpay_api_key',
            'name' => __('API Key', 'idpay_edd_gateway'),
            'desc' => __('API Key وب سرویس', 'idpay_edd_gateway'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'idpay_sandbox',
            'name' => __( 'آزمایشگاه', 'idpay_edd_gateway' ),
            'desc' => __( 'کارکرد درگاه پرداخت بصورت آزمایشی', 'idpay_edd_gateway' ),
            'type' => 'checkbox',
            'default' => 'no'
        )
    );

    return array_merge($settings, $sample_gateway_settings);
}
add_filter('edd_settings_gateways', 'pw_edd_add_settings');

function gateway_function_to_process_payment($purchase_data) {
    // payment processing happens here
    global $edd_options;

    $payment_data = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => $edd_options['currency'],
        'downloads' => $purchase_data['downloads'],
        'user_info' => $purchase_data['user_info'],
        'cart_details' => $purchase_data['cart_details'],
        'status' => 'pending'
    );

    // record the pending payment
    $payment = edd_insert_payment( $payment_data );


    if($payment){
        //pending payment done

        $apiKey = ( isset( $edd_options[ 'idpay_api_key' ] ) ? $edd_options[ 'idpay_api_key' ] : '' );
        $sandBox = $edd_options[ 'idpay_sandbox' ] === 'no' ? 'false' : 'true';

        $desc = 'پرداخت شماره #' . $payment;

        $callback = add_query_arg( 'verify_idpay_edd_gateway', '1', get_permalink( $edd_options['success_page'] ) );

        $phone = '09331754802';

        //TODO currency change amount in Toman and Rial
        $amount = intval( $purchase_data['price'] );
        if ( edd_get_currency() == 'IRT' )
            $amount = $amount * 10; // Return back to original one.

        $data = array(
            'order_id' 			=>	$payment,
            'amount' 			=>	$amount,
            'desc' 			    =>	$desc,
            'callback' 			=>	$callback,
            'phone'             =>  $phone
        ) ;

        var_dump($data);

//        $ch = curl_init( 'https://api.idpay.ir/v1/payment' );
//        curl_setopt( $ch, CURLOPT_USERAGENT, 'IDPay Easy Digital Download' );
//        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
//        curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
//        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'Content-Type: application/json',
//            'X-API-KEY:' . $apiKey,
//            'X-SANDBOX:' . $sandBox
//        ));
//


        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
            'X-SANDBOX: ' . $sandBox
        ));


        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result);
        var_dump($result);

        if (empty($result) || empty($result->link)) {
            //TODO show error code in message
            edd_insert_payment_note( $payment, 'خطا در انتقال به درگاه' );
            edd_update_payment_status( $payment, 'failed' );
            edd_set_error( 'idpay_connect_error', 'در اتصال به درگاه مشکلی پیش آمد.' );
            edd_send_back_to_checkout();
            return false;
        }

        //save id and link
        edd_insert_payment_note( $payment, 'در حال انتقال به درگاه پرداخت: ' );
        edd_insert_payment_note( $payment, 'کد   تراکنش آیدی پی: ' . $result->id );
        edd_update_payment_meta( $payment, 'idpay_payment_id', $result->id );
        edd_update_payment_meta( $payment, 'idpay_payment_link', $result->link );
        //edd_update_payment_meta( $payment, 'zarinpal_authority', $result['Authority'] );

        $_SESSION['idpay_payment'] = $payment;

        //Redirect to payment form
        //header('Location:' . $result->link);
        wp_redirect( $result->link );


    }else{
        //payment problem
        edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
    }

}
add_action('edd_gateway_idpay_edd_gateway', 'gateway_function_to_process_payment');

function verify_function_to_process_payment(){
    global $edd_options;

    if(isset($_POST['order_id'])){

        $payment = edd_get_payment( $_SESSION['idpay_payment'] );
        //var_dump($payment);
        unset( $_SESSION['idpay_payment'] );

        if ( ! $payment ) {
            wp_die( 'رکورد پرداخت موردنظر وجود ندارد!' );
        }
        if ( $payment->status == 'complete' ) return false;
        $amount = intval( edd_get_payment_amount( $payment->ID ) );
        if ( edd_get_currency() == 'IRT' ){
            $amount = $amount * 10; // Return back to original one.
        }

        //clear cart
        edd_empty_cart();

        $apiKey = ( isset( $edd_options[ 'idpay_api_key' ] ) ? $edd_options[ 'idpay_api_key' ] : '' );
        $sandBox = $edd_options[ 'idpay_sandbox' ] === 'no' ? 'false' : 'true';

        $data = array(
            'id' => $_POST['id'],
            'order_id' => $_POST['order_id']
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
            'X-SANDBOX: ' . $sandBox
        ));


        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result);

        if ( $result->status == 100 ) {
            edd_insert_payment_note( $payment->ID, 'شماره تراکنش بانکی: ' . $result->track_id );
            edd_update_payment_meta( $payment->ID, 'idpay_track_id', $result->track_id );
            edd_update_payment_status( $payment->ID, 'publish' );
            edd_send_to_success_page();
        } else {
            edd_update_payment_status( $payment->ID, 'failed' );
            wp_redirect( get_permalink( $edd_options['failure_page'] ) );
        }
        return;
    }

}
add_action('edd_verify_idpay_edd_gateway', 'verify_function_to_process_payment');


//function verify_function_to_process_payment2(){
//    echo 'in verify_function_to_process_payment2';
//    var_dump($_POST);
//}
//add_action('edd_payment_receipt_after', 'verify_function_to_process_payment2');

/**
 * Listen to incoming queries
 *
 * @return 			void
 */
function listen() {
    if ( isset( $_GET[ 'verify_idpay_edd_gateway' ] ) && $_GET[ 'verify_idpay_edd_gateway' ] ) {
        do_action( 'edd_verify_idpay_edd_gateway' );
    }
}
add_action( 'init', 'listen' );