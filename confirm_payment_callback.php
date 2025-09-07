<?php
require_once '/var/www/html/prod/utilities.php';
require_once '/var/www/html/prod/Booking.php';

header('Content-Type: application/json');
$input = file_get_contents('php://input');
$input = json_decode($input, true);
if ($input === null) {
    echo "Invalid JSON\n";
    http_response_code(400);
    exit;
}
$payment_data = json_decode($input['ReturnValue'], true);
$phone = $payment_data['Phone'];
$order_id = $payment_data['OrderId'];
$verification_code = $payment_data['VerificationCode'] . '';

$conn = _connect_to_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_begin_transaction($conn);

try {
    $output = _confirm_order($conn, $phone, $order_id, $verification_code);
    $result = json_decode($output, true);

    if (isset($result['Result']) && $result['Result'] == 'SUCCESS') {
        mysqli_commit($conn);
    } else {
        mysqli_rollback($conn);
    }

    echo json_encode($result, JSON_PRETTY_PRINT);
    http_response_code(200);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(array('Result' => 'ERROR', 'Message' => $e->getMessage()), JSON_PRETTY_PRINT);
    http_response_code(500);
} finally {
    mysqli_close($conn);
}
?>
