<?php
    date_default_timezone_set("Israel");

    // MySQL credentials
    $host = 'db';
    $user = 'test_user';
    $pass = 'test_user';
    $db = 'supreme_octo_eureka_db';

    // Verification code settings
    $verification_code_length = 6;
    $verification_code_cooldown = 30; // 30 seconds in seconds
    $verification_code_expiration = 10 * 60; // 10 minutes in seconds

    // password reset code settings
    $password_reset_code_length = 6;
    $password_reset_code_cooldown = 30; // 30 seconds in seconds
    $password_reset_code_expiration = 10 * 60; // 10 minutes in seconds

    // Payment settings
    $allpay_login = 'YOUR API LOGIN';
    $allpay_key = 'YOUR API KEY';
    $hourly_rate = 50; // 50 ILs per hour

    /**
     * Creates a connection to the database.
     *
     * @return mysqli The connection to the database
     */
    function _connect_to_db() {
        global $host, $user, $pass, $db;
        $conn = new mysqli($host, $user, $pass, $db);
        if ($conn->connect_error) {
            die("Connection failed: ".$conn->connect_error);
        }
        return $conn;
    }
?>
