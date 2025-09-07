<?php
require_once '/var/www/html/prod/utilities.php';


$terminal_number = '168289';
$api_username = 'qftirhGeSKgCWfhY5hLK';
$api_password = 'bckdHTl3heiOUwCMKQp2';

/**
 * Creates a payment request and returns the payment link and the low profile ID.
 *
 * @param order_id The order ID
 * @param verification_code The verification code
 * @param name The name of the client
 * @param phone The phone number of the client
 * @param email The email of the client
 * @param duration_minutes The duration of the lesson in minutes
 * @param price The price of the lessonf
 *
 * @return Url,LowProfileId The payment link and the low profile ID
 * @return null If the request was not successful
 */
function create_payment_request($order_id, $verification_code, $name, $phone, $email, $duration_minutes, $price) {
    global $terminal_number, $api_username, $api_password;
    $verification_code = $verification_code . '';

    $request = [
        'TerminalNumber' => $terminal_number,
        'ApiName' => $api_username,
        'ReturnValue' => json_encode([
            'Phone' => $phone,
            'OrderId' => $order_id,
            'VerificationCode' => $verification_code
        ]),
        'Amount' => 0.5,
        // 'Amount' => $price,
        'Operation' => 'ChargeAndCreateToken',
        'SuccessRedirectUrl' => 'https://darrisni.com/#/payment_success', // TODO
        'FailedRedirectUrl' => 'https://darrisni.com/#/payment_failed', // TODO
        'WebHookUrl' => 'https://api.darrisni.com/prod/confirm_payment_callback.php',
        'Document' => [
            'To' => $name,
            'Email' => $email,
            'Mobile' => $phone,
            'IsAllowEditDocument' => true,
            'Products' => [[
                    'Description' => 'שיעור ' . $duration_minutes . ' דקות',
                    'UnitCost' => $price
                ]
            ]
        ]
    ];
    $request_str = json_encode($request);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://secure.cardcom.solutions/api/v11/LowProfile/Create');
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_str);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($request_str),
        'Authorization: Basic ' . base64_encode($api_username . ':' . $api_password)
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($result, true);

    if (isset($data['ResponseCode']) && $data['ResponseCode'] == '0') {
        return [
            'Url' => $data['Url'],
            'LowProfileId' => $data['LowProfileId']
        ];
    } else {
        return null;
    }
}

/**
 * Checks the payment status.
 *
 * @param low_profile_id The ID of the payment request
 *
 * @return tranzaction_id The transaction ID in the Cardcom system in case of success
 * @return null If the request was not successful
 */
function check_payment_request_status($low_profile_id) {
    global $terminal_number, $api_username, $api_password;

    $request = [
        'TerminalNumber' => $terminal_number,
        'ApiName' => $api_username,
        'LowProfileId' => $low_profile_id
    ];
    $request_str = json_encode($request);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://secure.cardcom.solutions/api/v11/LowProfile/GetLpResult');
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_str);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($request_str),
        'Authorization: Basic ' . base64_encode($api_username . ':' . $api_password)
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($result, true);
    if (isset($data['ResponseCode']) && $data['ResponseCode'] == '0') {
        // 'TokenInfo' => isset($data['TokenInfo']) ? $data['TokenInfo'] : null,
        return $data['TranzactionId'];
    } else {
        return null;
    }
}

/**
 * Refund a transaction
 *
 * @param tranzaction_id The transaction ID in the Cardcom system
 *
 * @return bool True if the refund was successful, false otherwise
 */
function refund_transaction($tranzaction_id) {
    // TODO: Implement refund transaction
}
?>
