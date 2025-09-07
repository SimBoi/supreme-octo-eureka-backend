<?php
date_default_timezone_set("Israel");

// MySQL credentials
$host = 'db';
$user = 'test_user';
$pass = 'test_user';
$db = 'heq_db';

// Verification code settings
$verification_code_length = 6;
$verification_code_cooldown = 30; // 30 seconds in seconds
$verification_code_expiration = 10 * 60; // 10 minutes in seconds

// password reset code settings
$password_reset_code_length = 6;
$password_reset_code_cooldown = 30; // 30 seconds in seconds
$password_reset_code_expiration = 10 * 60; // 10 minutes in seconds

// Payment settings
$hourly_rate = 60; // ILs per hour
$STATUS_UNPAID = 0;
$STATUS_PROCESSING = 1;
$STATUS_PAID = 2;
$STATUS_PENDING_REFUND = 3;
$STATUS_PROCESSING_REFUND = 4;
$STATUS_REFUNDED = 5;

/**
 * Creates a connection to the database.
 *
 * @return mysqli The connection to the database
 */
function _connect_to_db() {
    global $host, $user, $pass, $db;
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die('{"Result": "ERROR: Connection to db failed: ' . $conn->connect_error . '"}');
    }
    return $conn;
}
?>
