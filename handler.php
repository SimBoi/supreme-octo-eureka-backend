<?php
    // router for the different actions in the app

    require_once $_SERVER['DOCUMENT_ROOT'] . '/utilities.php';
    $conn = _connect_to_db();

    $action = $_POST['Action'];
    $account_type = $_POST['AccountType'];

    if ($account_type == 'Customer') {
        $database_name = 'Customers';

        switch ($action) {
            case 'Login':
                include 'Accounts.php';
                echo login($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["OneSignalID"]);
                break;
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
                echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Username"], $_POST["NewUsername"]);
                break;
            default:
                die('{"Result": "ERROR"}');
        }
    } else {
        $database_name = 'Teachers';

        switch ($action) {
            case 'Login':
                include 'Accounts.php';
                echo login($conn, $database_name, $_POST["Phone"], $_POST["Password"], $_POST["OneSignalID"]);
                break;
            case 'UpdateProfile':
                include 'Accounts.php';
                echo update_profile($conn, $database_name, $_POST["Phone"], $_POST["Username"], $_POST["NewUsername"]);
                break;
            default:
                die('{"Result": "ERROR"}');
        }
    }
?>