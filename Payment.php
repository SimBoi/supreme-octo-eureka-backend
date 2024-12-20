<?php
    require_once '/var/www/html/supreme-octo-eureka-backend/utilities.php';


    /**
     * Creates a payment request and returns the payment link
     *
     * @param order_id The order ID
     * @param name The name of the client
     * @param phone The phone number of the client
     * @param duration_minutes The duration of the lesson in minutes
     * @param price The price of the lessonf
     *
     * @return string The payment link
     * @return null If the request was not successful
     */
    function create_payment_request($order_id, $name, $phone, $duration_minutes, $price)
    {
        return 'example.com';
    }

    /**
     * Verifies the payment and returns the status and receipt URL.
     * The status can be: 0 – payment failed or wasn’t conducted, 1 – successful payment, 3 – refunded.
     *
     * @param order_id The order ID
     *
     * @return array The status and receipt URL
     */
    function verify_payment($order_id)
    {
        return ['status' => 1, 'receipt_url' => 'example.com'];
    }
?>
