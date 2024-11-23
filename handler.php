<?php
    // router for the different actions in the app

    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';
    $conn = _connect_to_db();

    $action = $_POST['Action'];

    if ($action == 'GetAccountType') {
        include 'Accounts.php';
        echo get_account_type($conn, $_POST["Phone"]);
    } else if ($action == 'Login') {
        include 'Accounts.php';
        echo login($conn, $_POST["Phone"], $_POST["Password"], $_POST["OneSignalID"]);
    } else {
        $account_type = $_POST['AccountType'];

        if ($account_type == 'Customer') {
            $database_name = 'Customers';

            switch ($action) {
                case 'RequestVerificationCode':
                    include 'Verification.php';
                    echo request_verification_code($conn, $database_name, $_POST["Phone"], $_POST["Language"]);
                    break;
                case 'VerifyPhone':
                    include 'Verification.php';
                    echo verify_phone($conn, $database_name, $_POST["Phone"], $_POST["VerificationCode"]);
                    break;
                case 'Signup':
                    include 'Accounts.php';
                    echo signup($conn, $_POST["Phone"], $_POST["Username"], $_POST["OneSignalID"]);
                    break;
                case 'DeleteAccount':
                    include 'Accounts.php';
                    echo delete_account($conn, $_POST["Phone"], $_POST["Password"]);
                    break;
                case 'UpdateProfile':
                    include 'Accounts.php';
                    echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                    break;
                case 'CreateOrderRequest':
                    include 'Booking.php';
                    echo create_order_request($conn, $_POST["Phone"], $_POST["Password"], $_POST["Title"] , $_POST["StartTimestamp"], $_POST["DurationMinutes"], $_POST["Language"]);
                    break;
                case 'ConfirmOrder':
                    include 'Booking.php';
                    echo confirm_order($conn, $_POST["Phone"], $_POST["Password"], $_POST["OrderID"]);
                    break;
                case 'CancelLesson':
                    include 'Booking.php';
                    echo cancel_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StartTimestamp"]);
                    break;
                default:
                    die('{"Result": "ERROR: Invalid action"}');
            }
        } else {
            $database_name = 'Teachers';

            switch ($action) {
                case 'RequestVerificationCode':
                    include 'Verification.php';
                    echo request_verification_code($conn, $database_name, $_POST["Phone"], $_POST["Language"]);
                    break;
                case 'VerifyPhone':
                    include 'Verification.php';
                    echo verify_phone($conn, $database_name, $_POST["Phone"], $_POST["VerificationCode"]);
                    break;
                case 'UpdateProfile':
                    include 'Accounts.php';
                    echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                    break;
                case 'GetPendingLessons':
                    include 'Booking.php';
                    echo get_pending_lessons($conn, $_POST["Phone"], $_POST["Password"]);
                    break;
                case 'AcceptLesson':
                    include 'Booking.php';
                    echo accept_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StudentID"], $_POST["StartTimestamp"], $_POST["Link"]);
                    break;
                case 'EditLessonLink':
                    include 'Booking.php';
                    echo edit_lesson_link($conn, $_POST["Phone"], $_POST["Password"], $_POST["StudentID"], $_POST["StartTimestamp"], $_POST["NewLink"]);
                    break;
                case 'RejectLesson':
                    include 'Booking.php';
                    echo reject_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StudentID"], $_POST["StartTimestamp"]);
                    break;
                default:
                    die('{"Result": "ERROR: Invalid action"}');
            }
        }
    }
?>