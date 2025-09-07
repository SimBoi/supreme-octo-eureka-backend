<?php
// router for the different actions in the app

require_once '/var/www/html/prod/utilities.php';
header("Access-Control-Allow-Origin: *");
$conn = _connect_to_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_begin_transaction($conn);

try {
    $action = $_POST['Action'];
    $output = '';

    if ($action == 'GetAccountType') {
        include 'Accounts.php';
        $output = get_account_type($conn, $_POST["Phone"]);
    } else if ($action == 'Login') {
        include 'Accounts.php';
        $output = login($conn, $_POST["Phone"], $_POST["Password"]);
    } else if ($action == 'LongPollLiveLessonLink') {
        include 'Booking.php';
        $output = long_poll_live_lesson_link($conn, $_POST["OrderID"], 10);
    } else {
        $account_type = $_POST['AccountType'];

        if ($account_type == 'Customer') {
            $database_name = 'Customers';

            switch ($action) {
                case 'RequestVerificationCode':
                    include 'Verification.php';
                    $output = request_verification_code($conn, $database_name, $_POST["Phone"], $_POST["Language"]);
                    break;
                case 'VerifyPhone':
                    include 'Verification.php';
                    $output = verify_phone($conn, $database_name, $_POST["Phone"], $_POST["VerificationCode"]);
                    break;
                case 'Signup':
                    include 'Accounts.php';
                    $output = signup($conn, $_POST["Phone"], $_POST["Username"]);
                    break;
                case 'DeleteAccount':
                    include 'Accounts.php';
                    $output = delete_account($conn, $_POST["Phone"], $_POST["Password"]);
                    break;
                case 'UpdateProfile':
                    include 'Accounts.php';
                    $output = update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                    break;
                case 'TestCoupon':
                    include 'Booking.php';
                    $output = test_coupon($conn, $_POST["Phone"], $_POST["CouponCode"], $_POST["TotalPrice"]);
                    break;
                case 'CreateOrderRequest':
                    include 'Booking.php';
                    $output = create_order_request($conn, $_POST["Phone"], $_POST["Password"], $_POST["Title"], $_POST["Subject"], $_POST["Grade"], $_POST["IsImmediate"], $_POST["StartTimestamp"], $_POST["DurationMinutes"], $_POST["CouponCode"]);
                    break;
                case 'LongPollActiveLesson':
                    include 'Booking.php';
                    $output = long_poll_active_lesson($conn, $_POST["OrderID"], 10);
                    break;
                case 'CancelLesson':
                    include 'Booking.php';
                    $output = cancel_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["OrderID"]);
                    break;
                default:
                    throw new Exception('Invalid action');
            }
        } else {
            $database_name = 'Teachers';

            switch ($action) {
                case 'RequestVerificationCode':
                    include 'Verification.php';
                    $output = request_verification_code($conn, $database_name, $_POST["Phone"], $_POST["Language"]);
                    break;
                case 'VerifyPhone':
                    include 'Verification.php';
                    $output = verify_phone($conn, $database_name, $_POST["Phone"], $_POST["VerificationCode"]);
                    break;
                case 'UpdateProfile':
                    include 'Accounts.php';
                    $output = update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                    break;
                case 'GetPendingLessons':
                    include 'Booking.php';
                    $output = get_pending_lessons($conn, $_POST["Phone"], $_POST["Password"]);
                    break;
                case 'GetLessonsHistory':
                    include 'Booking.php';
                    $output = get_teachers_lesson_history($conn, $_POST["Phone"], $_POST["Password"]);
                    break;
                case 'AcceptLesson':
                    include 'Booking.php';
                    $output = accept_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["OrderID"]);
                    break;
                case 'RejectLesson':
                    include 'Booking.php';
                    $output = reject_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["OrderID"]);
                    break;
                default:
                    throw new Exception('Invalid action');
            }
        }
    }

    // If the output json array has a key "Result" with the value "SUCCESS", commit the transaction.
    // Otherwise, roll back the transaction.
    $result = json_decode($output, true);
    if (isset($result['Result']) && ($result['Result'] == 'SUCCESS' || $result['Result'] == 'CUSTOMER' || $result['Result'] == 'TEACHER')) {
        mysqli_commit($conn);
    } else {
        mysqli_rollback($conn);
    }

    echo $output;
} catch (Exception $e) {
    // Roll back the transaction on error.
    mysqli_rollback($conn);
    echo json_encode(['Result' => "ERROR: " . $e->getMessage()]);
} finally {
    mysqli_close($conn);
}
?>
