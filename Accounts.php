<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';


    /**
     * Adds a new customer to the database.
     *
     * @param Phone The phone number of the customer
     * @param Password The password of the customer
     * @param Username The username of the customer
     * @param OneSignalID The OneSignal ID of the customer
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=PHONE_EXISTS in case the phone number is already in the database
     * @return Result=ERROR in case of failure
     */
    function signup($conn, $phone, $password, $username, $onesignal_id)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');

        // Check if the phone number is already in the database
        $sql = "SELECT Phone FROM Customers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) die('{"Result": "PHONE_EXISTS"}');

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert the new customer into the database
        $sql = "INSERT INTO Customers (Password, Phone, Username, OneSignalID, CurrentAppointments) VALUES ('".$hashed_password."', '".$phone."', '".$username."', '".$onesignal_id."', '[]')";
        if (mysqli_query($conn, $sql)) $output = array('Result' => 'SUCCESS');
        else $output = array('Result' => 'ERROR');

        return json_encode($output);
    }

    /**
     * Logs the user in and updates the OneSignalID in the database.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param OneSignalID The user's OneSignal ID
     *
     * @return JSON Object with the result of the operation, additional information will be returned based on the result of the operation
     * @return Result=CUSTOMER,ID,Username,IsVerified in case the user is a customer
     * @return Result=TEACHER,ID,Username,TimeBetweenAppointments in case the user is a teacher
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function login($conn, $phone, $password, $onesignal_id)
    {
        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 10 characters long"}');

        // Check if the phone number is in the Teachers database
        $output = _login($conn, 'Teachers', $phone, $password, $onesignal_id);
        if ($output != '{"Result": "PHONE_DOESNT_EXIST"}') return $output;

        // Check if the phone number is in the Customers database
        $output = _login($conn, 'Customers', $phone, $password, $onesignal_id);
        if ($output != '{"Result": "PHONE_DOESNT_EXIST"}') return $output;

        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    function _login($conn, $database_name, $phone, $password, $onesignal_id)
    {
        $needs_rehash = false;
        $output = array('Result' => 'None');

        // Prepare the SQL query based on the DatabaseName parameter
        if ($database_name == 'Customers') {
            $sql = "SELECT Password, ID, Username, IsVerified, CurrentAppointments FROM Customers WHERE Phone = '".$phone."'";
        } else {
            $sql = "SELECT Password, ID, Username, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
        }

        // Fetch the user's data from the database
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) return('{"Result": "WRONG_PASSWORD"}');

                // Check if the password needs to be rehashed with a stronger algorithm
                if (password_needs_rehash($row['Password'], PASSWORD_DEFAULT)) $needs_rehash = true;

                // different json output based on the DatabaseName parameter
                if ($database_name == 'Customers') {
                    $output = array(
                        'Result' => 'CUSTOMER',
                        'ID' => $row['ID'],
                        'Username' => $row['Username'],
                        'IsVerified' => $row['IsVerified'],
                        'CurrentAppointments' => $row['CurrentAppointments']
                    );
                } else {
                    $output = array(
                        'Result' => 'TEACHER',
                        'ID' => $row['ID'],
                        'Username' => $row['Username'],
                        'CurrentAppointments' => $row['CurrentAppointments']
                    );
                }
            }
        } else {
            return('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Update the OneSignalID in the database, and rehash the password if needed
        if ($needs_rehash) {
            $sql = "UPDATE ".$database_name." SET Password='".password_hash($password, PASSWORD_DEFAULT)."', OneSignalID='".$onesignal_id."' WHERE Phone='".$phone."'";
        } else {
            $sql = "UPDATE ".$database_name." SET OneSignalID='".$onesignal_id."' WHERE Phone='".$phone."'";
        }
        if (!mysqli_query($conn ,$sql)) return('{"Result": "ERROR: Could not update OneSignalID"}');

        return json_encode($output);
    }

    /**
     * Updates the user's profile information in the database.
     * currently there is only one column in the database columns to update:
     * Username: a varchar(20) column.
     * in the future, there may be more columns to update.
     *
     * @param DatabaseName The database in which the profile is stored, either 'Customers' or 'Teachers'
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param NewUsername The new username to update
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=ERROR in case of failure
     */
    function update_profile($conn, $database_name, $phone, $password, $new_username) {
        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');
        // Check if the username is between 1 and 20 characters long, if not, end the script
        if (strlen($new_username) < 1 || strlen($new_username) > 20) die('{"Result": "ERROR"}');

        // Check if the password is correct
        $sql = "SELECT Password FROM ".$database_name." WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Update the user's profile in the database
        $sql = "UPDATE ".$database_name." SET Username='".$new_username."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

        return '{"Result": "SUCCESS"}';
    }

    /**
     * Deletes the user's account from the database.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=ERROR in case of failure
     */
    function delete_account($conn, $phone, $password)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');

        // Check if the password is correct
        $sql = "SELECT Password FROM Customers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                // Delete the user's account from the database
                $sql = "DELETE FROM Customers WHERE Phone='".$phone."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

                $output = array(
                    'Result' => 'SUCCESS'
                );
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        return json_encode($output);
    }
?>
