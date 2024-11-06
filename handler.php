<?php
    // router for the different actions in the app

    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';
    $conn = _connect_to_db();

    $action = $_POST['Action'];
    $account_type = $_POST['AccountType'];

    if ($action == 'Login') {
        include 'Accounts.php';
        echo login($conn, $_POST["Phone"], $_POST["Password"], $_POST["OneSignalID"]);
    } else if ($account_type == 'Customer') {
        $database_name = 'Customers';

        switch ($action) {
            case 'Signup':
                include 'Accounts.php';
                echo signup($conn, $_POST["Phone"], $_POST["Password"], $_POST["Username"], $_POST["OneSignalID"]);
                break;
            case 'DeleteAccount':
                include 'Accounts.php';
                echo delete_account($conn, $_POST["Phone"], $_POST["Password"]);
                break;
            case 'UpdateProfile':
                include 'Accounts.php';
                echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                break;
            case 'OrderLesson':
                include 'Booking.php';
                echo order_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["Title"] , $_POST["StartTimestamp"], $_POST["DurationMinutes"]);
                break;
            case 'CancelLesson':
                include 'Booking.php';
                echo cancel_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StartTimestamp"]);
                break;
            default:
                die('{"Result": "ERROR"}');
        }
    } else {
        $database_name = 'Teachers';

        switch ($action) {
            case 'UpdateProfile':
                include 'Accounts.php';
                echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["NewUsername"]);
                break;
            case 'acceptLesson':
                include 'Booking.php';
                echo accept_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StudentID"], $_POST["StartTimestamp"], $_POST["Link"]);
                break;
            case 'rejectLesson':
                include 'Booking.php';
                echo reject_lesson($conn, $_POST["Phone"], $_POST["Password"], $_POST["StudentID"], $_POST["StartTimestamp"]);
                break;
            default:
                die('{"Result": "ERROR"}');
        }
    }
?>