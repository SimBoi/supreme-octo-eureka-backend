<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';

    Function create_payment_request($order_id, $name, $phone, $duration_minutes, $price, $language)
    {
        return 'example.com';
    }

    Function verify_payment($order_id)
    {
        return ['status' => 1, 'receipt_url' => 'example.com'];
    }

    /**
     * Creates a payment request and returns the payment link
     *
     * @param order_id The order ID
     * @param name The name of the client
     * @param phone The phone number of the client
     * @param duration_minutes The duration of the lesson in minutes
     * @param price The price of the lesson
     * @param language The language of the payment page
     *
     * @return string The payment link
     * @return null If the request was not successful
     */
    function _create_payment_request($order_id, $name, $phone, $duration_minutes, $price, $language)
    {
        global $allpay_login, $allpay_key;

        $request = [
            'items' => [
                [
                    'name' => $duration_minutes . ' minutes lesson',
                    'price' => $price,
                    'qty' => 1,
                    'tax' => 1  // VAT 18% included
                ]
            ],
            'login' => $allpay_login,
            'order_id' => $order_id,
            'currency' => 'ILS',
            'lang' => $language,
            'notifications_url' => 'https://site.com/checkout-confirm', // TODO: change this to the correct URL
            'client_name' => $name,
            'client_email' => 'joe@doe.com', // TODO: get the user's email
            'client_phone' => '+' . $phone,
            'expire' => time() + 3600   // the link will be valid for 1 hour
        ];
        $sign = getApiSignature($request, $allpay_key);
        $request['sign'] = $sign;
        $request_str = json_encode($request);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://allpay.to/app/?show=getpayment&mode=api6");
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_str);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($request_str)
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result, true);

        // check if the request was successful
        if ($data['status'] != 'ok') {
            return null;
        }

        return $data['payment_url'];
    }

    /**
     * Verifies the payment and returns the status and receipt URL.
     * The status can be: 0 – payment failed or wasn’t conducted, 1 – successful payment, 3 – refunded.
     *
     * @param order_id The order ID
     *
     * @return array The status and receipt URL
     */
    function _verify_payment($order_id)
    {
        global $allpay_login, $allpay_key;

        $request = [
            'login' => $allpay_login,
            'order_id' => $order_id,
        ];
        $sign = getApiSignature($request, $allpay_key);
        $request['sign'] = $sign;
        $request_str = json_encode($request);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://allpay.to/app/?show=paymentstatus&mode=api6");
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_str);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($request_str)
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result, true);

        $output = [
            'status' => $data['status'],
            'receipt' => $data['receipt']
        ];
        return $output;
    }

    /**
     * Generates an API signature
     *
     * @param params The parameters to sign
     * @param apikey The API key
     *
     * @return string The signature
     */
    function getApiSignature($params, $apikey)
    {
        ksort($params);
        $chunks = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        ksort($item);
                        foreach ($item as $name => $val) {
                            $val = trim($val);
                            if ($val !== '') {
                                $chunks[] = $val;
                            }
                        }
                    }
                }
            } else {
                $v = trim($v);
                if ($v !== '' && $k != 'sign') {
                    $chunks[] = $v;
                }
            }
        }
        $signature = implode(':', $chunks) . ':' . $apikey;
        $signature = hash('sha256', $signature);
        return $signature;
    }
?>
