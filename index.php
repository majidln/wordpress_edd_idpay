<?php
/**
 * Plugin Name: IDPay for Easy Digital Downloads (EDD)
 * Author: IDPay
 * Description: درگاه پرداخت امن <a href="https://idpay.ir">آیدی پی</a> برای پرداخت در افزودنه دانلود دیجیتال آسان
 * Version: 1.0
 * Author URI: https://idpay.ir
 * Author Email: support@idpay.ir
 */

// registers the gateway
function idpay_edd_register_gateway($gateways) {
    if(!isset($_SESSION)) {
        session_start();
    }

    $gateways['idpay_edd_gateway'] = array(
        'admin_label' => 'آیدی پی',
        'checkout_label' => 'درگاه پرداخت آیدی پی',
    );

    return $gateways;
}
add_filter('edd_payment_gateways', 'idpay_edd_register_gateway');

// disable payment form in checkout
function idpay_edd_register_gateway_cc_form() {
    return;
}
add_action('edd_idpay_edd_gateway_cc_form', 'idpay_edd_register_gateway_cc_form');

// adds the settings to the Payment Gateways section
function pw_edd_add_settings($settings) {

    $sample_gateway_settings = array(
        array(
            'id' => 'idpay_edd_gateway_settings',
            'type' => 'header',
            'name' => 'درگاه پرداخت آیدی پی',
        ),
        array(
            'id' => 'idpay_api_key',
            'type' => 'text',
            'name' => 'API Key',
            'size' => 'regular',
        ),
        array(
            'id' => 'idpay_sandbox',
            'type' => 'checkbox',
            'name' => 'آزمایشگاه',
            'default' => 0,
        )
    );

    return array_merge($settings, $sample_gateway_settings);
}
add_filter('edd_settings_gateways', 'pw_edd_add_settings');

//
function gateway_function_to_process_payment($purchase_data) {
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
        'status' => 'pending',
    );

    // record the pending payment
    $payment = edd_insert_payment($payment_data);

    if(empty($payment)) {
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }

    $api_key = empty($edd_options['idpay_api_key']) ? '' : $edd_options['idpay_api_key'];
    $sandbox = empty($edd_options['idpay_sandbox']) ? 'false' : 'true';

    $amount = idpay_edd_get_amount(intval($purchase_data['price']), edd_get_currency());
    $desc = 'سفارش شماره #' . $payment;
    $callback = add_query_arg('verify_idpay_edd_gateway', '1', get_permalink($edd_options['success_page']));

    if (empty($amount)) {
        $message = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        edd_insert_payment_note($payment, $message);
        edd_update_payment_status($payment, 'failed');
        edd_set_error('idpay_connect_error', $message);
        edd_send_back_to_checkout();
        return false;
    }

    $data = array(
        'order_id' => $payment,
        'amount' => $amount,
        'phone' => '',
        'desc' => $desc,
        'callback' => $callback,
    );

    $ch = curl_init('https://api.idpay.ir/v1/payment');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-API-KEY: ' . $api_key,
        'X-SANDBOX: ' . $sandbox,
    ));

    $result = curl_exec($ch);
    $result = json_decode($result);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 201 || empty($result) || empty($result->link)) {
        $message = 'هنگام اتصال به درگاه پرداخت خطا رخ داده است';
        edd_insert_payment_note($payment, $http_status . ' - ' . $message);
        edd_update_payment_status($payment, 'failed');
        edd_set_error('idpay_connect_error', $message);
        edd_send_back_to_checkout();
        return false;
    }

    //save id and link
    edd_insert_payment_note($payment, 'Id: ' . $result->id);
    edd_insert_payment_note($payment, 'در حال انتقال به درگاه پرداخت');
    edd_update_payment_meta($payment, 'idpay_payment_id', $result->id);
    edd_update_payment_meta($payment, 'idpay_payment_link', $result->link);

    $_SESSION['idpay_payment'] = $payment;

    //Redirect to payment form
    //header('Location:' . $result->link);
    wp_redirect($result->link);
}
add_action('edd_gateway_idpay_edd_gateway', 'gateway_function_to_process_payment');

//
function verify_function_to_process_payment(){
    global $edd_options;

    if(empty($_POST['id']) || empty($_POST['order_id'])) return false;

    $payment = edd_get_payment($_SESSION['idpay_payment']);
    unset($_SESSION['idpay_payment']);

    if (!$payment) {
        wp_die('اطلاعات ارسال شده صحیح نمی باشد.');
    }

    if ($payment->status == 'complete') return false;

    $api_key = empty($edd_options['idpay_api_key']) ? '' : $edd_options['idpay_api_key'];
    $sandbox = empty($edd_options['idpay_sandbox']) ? 'false' : 'true';

    // todo: load order_id and id from database!

    $data = array(
        'id' => $_POST['id'],
        'order_id' => $payment->ID,
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-API-KEY: ' . $api_key,
        'X-SANDBOX: ' . $sandbox,
    ));

    $result = curl_exec($ch);
    $result = json_decode($result);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status != 200) {
        $message = 'هنگام استعلام پرداخت خطا رخ داده است';
        edd_insert_payment_note($payment, $http_status . ' - ' . $message);
        edd_update_payment_status($payment, 'failed');
        edd_set_error('idpay_connect_error', $message);
        edd_send_back_to_checkout();
        return false;
    }

    edd_insert_payment_note($payment->ID, 'کد رهگیری آیدی پی: ' . $result->track_id);
    edd_insert_payment_note($payment->ID, $result->status . ' - ' . idpay_edd_get_error_message($result->status));
    edd_update_payment_meta($payment->ID, 'idpay_track_id', $result->track_id);
    edd_update_payment_meta($payment->ID, 'idpay_status', $result->status);

    if ($result->status == 100) {
        edd_empty_cart();
        edd_update_payment_status($payment->ID, 'publish');
        edd_send_to_success_page();
    }
    else {
        edd_update_payment_status($payment->ID, 'failed');
        wp_redirect(get_permalink($edd_options['failure_page']));
    }
}
add_action('edd_verify_idpay_edd_gateway', 'verify_function_to_process_payment');

//
function idpay_edd_get_amount($amount, $currency) {
    switch (strtolower($currency)) {
        case strtolower('IRR'):
        case strtolower('RIAL'):
            return $amount;
        case strtolower('تومان ایران'):
        case strtolower('تومان'):
        case strtolower('IRT'):
        case strtolower('Iranian_TOMAN'):
        case strtolower('Iran_TOMAN'):
        case strtolower('Iranian-TOMAN'):
        case strtolower('Iran-TOMAN'):
        case strtolower('TOMAN'):
        case strtolower('Iran TOMAN'):
        case strtolower('Iranian TOMAN'):
            return $amount * 10;
        case strtolower('IRHT'):
            return $amount * 10000;
        case strtolower('IRHR'):
            return $amount * 1000;
        default:
            return 0;
    }
}

//
function idpay_edd_get_error_message($code) {
    switch ($code) {
        case 1:
            return 'پرداخت انجام نشده است';

        case 2:
            return 'پرداخت ناموفق بوده است';

        case 3:
            return 'خطا رخ داده است';

        case 100:
            return 'پرداخت تایید شده است';

        default:
            return 'کد تعریف نشده است';
    }
}

/**
 * Listen to incoming queries
 *
 * @return void
 */
function listen() {
    if (isset($_GET['verify_idpay_edd_gateway']) && $_GET['verify_idpay_edd_gateway']) {
        do_action('edd_verify_idpay_edd_gateway');
    }
}
add_action('init', 'listen');
