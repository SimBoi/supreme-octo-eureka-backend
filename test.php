<?php
require_once '/var/www/html/prod/Notification.php';
require_once '/var/www/html/prod/Payment.php';

echo json_encode(create_payment_request('darrisni_order_id_69', '696969', '', '', '', 1, 0.5), JSON_PRETTY_PRINT);
// echo json_encode(check_payment_request_status('2481abc0-2287-4640-b2a7-016dd3b3d894'), JSON_PRETTY_PRINT);
// echo json_encode(check_payment_request_status('702a1bc6-6c9c-4a68-9895-d1905eb96b3f'), JSON_PRETTY_PRINT);
// echo json_encode(check_payment_request_status('9dc29028-566b-4ae7-96a6-8d186c250c4d'), JSON_PRETTY_PRINT);

// cancel_payment_request('9dc29028-566b-4ae7-96a6-8d186c250c4d');

// $data = array(
//     'ResponseCode' => '0'
// );
// $conn = _connect_to_db();
// $sql = "INSERT INTO tmp (Data) VALUES ('" . mysqli_real_escape_string($conn, json_encode($data)) . "')";
// mysqli_query($conn, $sql);

?>
