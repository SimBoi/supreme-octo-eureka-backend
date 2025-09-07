<?php
require_once '/var/www/html/prod/utilities.php';
require_once '/var/www/html/prod/Payment.php';
require_once '/var/www/html/prod/Notification.php';
require_once '/var/www/html/prod/Meetings.php';

/**
 * Tests Coupon Code on a total price and returns if the coupon is valid and the new price.
 *
 * @param phone The phone number of the client
 * @param coupon_code The coupon code to apply
 * @param total_price The total price to apply the coupon to
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS,NewPrice in case of success
 * @return Result=INVALID_COUPON_CODE in case the coupon code is invalid
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 */
function test_coupon($conn, $phone, $coupon_code, $total_price)
{
    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Get the coupon code details from the Coupons table
    $sql = "SELECT Value, Percentage, Expiration, MaxUserUses, MaxGlobalUses, GlobalUses FROM Coupons WHERE Code = '" . $coupon_code . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $value = $row['Value'];
            $percentage = $row['Percentage'];
            $expiration = $row['Expiration'];
            $user_uses = $row['MaxUserUses'];
            $global_uses = $row['MaxGlobalUses'];
            $global_uses_used = $row['GlobalUses'];
        }
    } else {
        return '{"Result": "INVALID_COUPON_CODE"}';
    }

    // Check if the coupon code is expired or if the global uses exceeded the max number of uses
    if ($expiration < time() || $global_uses_used >= $global_uses) {
        return '{"Result": "INVALID_COUPON_CODE"}';
    }

    // Get the users coupons list from the Customers table
    $sql = "SELECT Coupons FROM Customers WHERE Phone = '" . $phone . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $coupons = json_decode($row['Coupons'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Check if the coupon code already exceeded the max number of uses
    if (array_key_exists($coupon_code, $coupons)) {
        $user_coupon = $coupons[$coupon_code];
        if ($user_coupon['Uses'] >= $user_uses) {
            return '{"Result": "INVALID_COUPON_CODE"}';
        }
    }

    // Calculate the new price after applying the coupon
    $new_price = $total_price - ($total_price * ($percentage / 100.0)) - $value;
    if ($new_price < 0) $new_price = 0;
    return json_encode(array('Result' => 'SUCCESS', 'NewPrice' => $new_price));
}

/**
 * Use a coupon code. internal use only.
 *
 * @param phone The phone number of the client
 * @param coupon_code The coupon code to use
 * @param total_price The total price to apply the coupon to
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS,NewPrice in case of success
 * @return Result=INVALID_COUPON_CODE in case the coupon code is invalid
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 */
function use_coupon($conn, $phone, $coupon_code, $total_price)
{
    // test the coupon code
    $result = json_decode(test_coupon($conn, $phone, $coupon_code, $total_price), true);
    if ($result['Result'] != 'SUCCESS') {
        return $result;
    }
    $new_price = $result['NewPrice'];

    // Update the coupon code uses in the Coupons table
    $sql = "UPDATE Coupons SET GlobalUses = GlobalUses + 1 WHERE Code = '" . $coupon_code . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    // Get the users coupons list from the Customers table
    $sql = "SELECT Coupons FROM Customers WHERE Phone = '" . $phone . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $coupons = json_decode($row['Coupons'], true);
        }
    }

    // Update the coupons list in the Customers table
    if (array_key_exists($coupon_code, $coupons)) {
        $user_coupon = $coupons[$coupon_code];
        $user_coupon['Uses']++;
        $coupons[$coupon_code] = $user_coupon;
    } else {
        $coupons[$coupon_code] = array(
            "Uses" => 1,
            "FirstUse" => time()
        );
    }
    $sql = "UPDATE Customers SET Coupons='" . json_encode($coupons) . "' WHERE Phone='" . $phone . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    return array('Result' => 'SUCCESS', 'NewPrice' => $new_price);
}

/**
 * Create a lesson payment request and return the payment link and order ID.
 *
 * @param Phone The user's phone number
 * @param Password The user's password
 * @param Title The title of the lesson
 * @param Subject The subject of the lesson
 * @param Grade The grade of the lesson
 * @param IsImmediate Whether the lesson is immediate or not
 * @param StartTimestamp The start timestamp of the lesson
 * @param DurationMinutes The duration of the lesson
 * @param CouponCode The coupon code to apply
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS,PaymentLink,OrderID in case of success
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 * @return Result=OVERLAPPING_APPOINTMENT in case the lesson overlaps with an existing appointment
 * @return Result=LIVE_LESSON_EXISTS in case a live lesson already exists
 */
function create_order_request($conn, $phone, $password, $title, $subject, $grade, $is_immediate, $start_timestamp, $duration_minutes, $coupon_code)
{
    // TODO: add a check for overlapping orders, not just appointments
    global $hourly_rate, $STATUS_UNPAID, $verification_code_length;
    if ($coupon_code == null) $coupon_code = "";

    $is_immediate = $is_immediate == "true" ? true : false;
    $start_timestamp = intval($start_timestamp);
    $duration_minutes = intval($duration_minutes);

    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');
    // Check if the duration is greater than 10, if not, end the script
    if ($duration_minutes < 10) throw new Exception('Duration is less than 10 minutes');
    // Check if the start timestamp is at least 15 minutes in the future, if not, end the script
    if ($start_timestamp < (time() + 900)) throw new Exception('Start timestamp is less than 15 minutes in the future');

    // Check if the password is correct and get the user's ID, name, Orders list, and CurrentAppointments list
    $sql = "SELECT ID, Username, Orders, CurrentAppointments, Password FROM Customers WHERE Phone = '" . $phone . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) return '{"Result": "WRONG_PASSWORD"}';

            $id = $row['ID'];
            $name = $row['Username'];
            $orders = json_decode($row['Orders'], true);
            $current_appointments = json_decode($row['CurrentAppointments'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Get the auto-incremented order id
    $sql = "INSERT INTO PaymentRequests () VALUES ()";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    $order_id = mysqli_insert_id($conn);

    // Apply the coupon code if it exists
    $price = $hourly_rate * $duration_minutes / 60;
    if ($coupon_code != "") {
        $result = use_coupon($conn, $phone, $coupon_code, $price);
        if ($result['Result'] != 'SUCCESS') {
            return $result;
        }
        $price = $result['NewPrice'];
    }

    // generate the order details
    if ($is_immediate) {
        // set the start timestamp to a 10 years in the future, this will be updated upon a teacher accepting the lesson
        $start_timestamp = time() + 315360000;
    }
    $order_timestamp = time();
    $status = $STATUS_UNPAID;
    $end_timestamp = $start_timestamp + ($duration_minutes * 60);
    $details = array(
        'OrderID' => $order_id,
        'StudentID' => intval($id),
        'StudentName' => $name,
        'StudentPhone' => $phone,
        'TeacherID' => 0,
        'TeacherName' => "",
        'TeacherPhone' => "",
        'Title' => $title,
        'Subject' => intval($subject),
        'Grade' => intval($grade),
        'StartTimestamp' => $start_timestamp,
        'DurationMinutes' => $duration_minutes,
        'EndTimestamp' => $end_timestamp,
        'IsPending' => true,
        'IsImmediate' => $is_immediate,
        'Link' => ""
    );

    // Check if the user has any overlapping appointments
    foreach ($current_appointments as $appointment) {
        if ($appointment['IsImmediate'] == true && $is_immediate == true) return '{"Result": "LIVE_LESSON_EXISTS"}';
        if ($start_timestamp >= $appointment['StartTimestamp'] && $start_timestamp < $appointment['EndTimestamp']) return '{"Result": "OVERLAPPING_APPOINTMENT"}';
        if ($end_timestamp > $appointment['StartTimestamp'] && $end_timestamp <= $appointment['EndTimestamp']) return '{"Result": "OVERLAPPING_APPOINTMENT"}';
    }

    // Send a payment request POST request to the payment server api and get the payment link
    $verification_code = rand(pow(10, $verification_code_length - 1), pow(10, $verification_code_length) - 1);
    $payment_request = create_payment_request($order_id, $verification_code, $name, $phone, '', $duration_minutes, $price);
    $payment_link = isset($payment_request['Url']) ? $payment_request['Url'] : null;
    if ($payment_link == null) throw new Exception('Failed to create payment request');
    $low_profile_id = $payment_request['LowProfileId'];

    // Add the order to the PaymentRequests table
    $sql = "UPDATE PaymentRequests SET VerificationCode ='" . password_hash($verification_code, PASSWORD_DEFAULT) . "', LowProfileId='" . $low_profile_id . "', OrderTimestamp='" . $order_timestamp . "', StudentID='" . $id . "', DurationMinutes='" . $duration_minutes . "', Coupon='" . $coupon_code . "', Price='" . $price . "', Status='" . $status . "', PaymentLink='" . $payment_link . "', LessonDetails='" . mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE)) . "' WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    // Add the order to the user's Orders list
    $order = array(
        'OrderID' => $order_id,
        'OrderTimestamp' => $order_timestamp,
        'DurationMinutes' => $duration_minutes,
        'IsImmediate' => $is_immediate,
        'LessonTimestamp' => $start_timestamp,
        'Price' => $price,
        'Status' => $status
    );
    array_push($orders, $order);
    $sql = "UPDATE Customers SET Orders='" . json_encode($orders) . "' WHERE Phone='" . $phone . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    return json_encode(
        array('Result' => 'SUCCESS', 'PaymentLink' => $payment_link, 'OrderID' => $order_id)
    );
}

/**
 * Confirm a payment for a lesson and make the lesson available to teachers.
 *
 * @param Phone The user's phone number
 * @param OrderID The order ID
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS in case of success
 * @return Result=ORDER_DOESNT_EXIST in case the order ID is not in the database
 * @return Result=WRONG_CODE in case the verification code is incorrect
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=UNPAID in case the payment was not successful
 */
function _confirm_order($conn, $phone, $order_id, $verification_code)
{
    global $STATUS_PAID, $STATUS_PENDING_REFUND;

    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check validity of the order confirmation
    $sql = "SELECT LowProfileId, VerificationCode, TranzactionId FROM PaymentRequests WHERE OrderID = '" . $order_id . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Check if the callback is from the payment server
            if (!password_verify($verification_code, $row['VerificationCode'])) {
                return '{"Result": "WRONG_CODE"}';
            }

            // Avoid multiple confirmations of the same order
            if ($row['TranzactionId'] != null && $row['TranzactionId'] != "") {
                throw new Exception('Order already confirmed');
            }

            $low_profile_id = $row['LowProfileId'];
        }
    } else {
        return '{"Result": "ORDER_DOESNT_EXIST"}';
    }

    // Get the user's Orders list and CurrentAppointments list
    $sql = "SELECT ID, Username, Orders, CurrentAppointments FROM Customers WHERE Phone = '" . $phone . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $orders = json_decode($row['Orders'], true);
            $current_appointments = json_decode($row['CurrentAppointments'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Confirm the payment using the order id and the payment server api
    $tranzaction_id = check_payment_request_status($low_profile_id);
    if ($tranzaction_id == null) {
        return '{"Result": "UNPAID"}';
    }

    // Get the order details from the PaymentRequests table and update the order status
    $sql = "SELECT LessonDetails FROM PaymentRequests WHERE OrderID = '" . $order_id . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $details = json_decode($row['LessonDetails'], true);
        }
    } else {
        return '{"Result": "ORDER_DOESNT_EXIST"}';
    }

    // mark for refund if the payment was late
    $status = $details['StartTimestamp'] < time() ? $STATUS_PENDING_REFUND : $STATUS_PAID;
    foreach ($orders as $key => $order) {
        if ($order['OrderID'] == $order_id) {
            $orders[$key]['Status'] = $status;
            break;
        }
    }

    // Update the order in the PaymentRequests table
    $sql = "UPDATE PaymentRequests SET TranzactionId='" . $tranzaction_id . "', Status='" . $status . "', LessonDetails='" . mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE)) . "' WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    // Update the order in the customers Orders list
    $sql = "UPDATE Customers SET Orders='" . json_encode($orders) . "' WHERE Phone='" . $phone . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    // Publish the lesson to the teachers
    if ($status == $STATUS_PAID) {
        // Add a new row to the ActiveLessons table
        $sql = "INSERT INTO ActiveLessons (OrderID, IsImmediate, StartTimeStamp, EndTimeStamp, Details) VALUES ('" . $order_id . "', " . (int)$details['IsImmediate'] . ", '" . $details['StartTimestamp'] . "', '" . $details['EndTimestamp'] . "', '" . mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE)) . "')";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

        // Add the new lesson to the user's CurrentAppointments
        array_push($current_appointments, $details);
        $sql = "UPDATE Customers SET CurrentAppointments='".mysqli_real_escape_string($conn, json_encode($current_appointments, JSON_UNESCAPED_UNICODE))."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));
    }

    return json_encode(
        array('Result' => 'SUCCESS')
    );
}

/**
 * Get all the pending lessons from the pending lessons table.
 *
 * @param Phone The teacher's phone number
 * @param Password The teacher's password
 *
 * @return JSON Object with the pending lessons
 * @return Result=SUCCESS in case of success
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 */
function get_pending_lessons($conn, $phone, $password)
{
    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check if the password is correct
    $sql = "SELECT Password FROM Teachers WHERE Phone = '".$phone."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) {
                return '{"Result": "WRONG_PASSWORD"}';
            }
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Get all the pending lessons from the ActiveLessons table
    $sql = "SELECT Details FROM ActiveLessons WHERE IsPending = 1";
    $result = mysqli_query($conn ,$sql);
    if ($result) {
        $pending_lessons = array();
        while ($row = mysqli_fetch_assoc($result)) {
            array_push($pending_lessons, json_decode($row['Details'], true));
        }
        return json_encode(array('Result' => 'SUCCESS', 'PendingLessons' => json_encode($pending_lessons)));
    } else {
        throw new Exception(mysqli_error($conn));
    }
}

/**
 * Long-poll for a live lesson link until it’s ready or a timeout is reached.
 *
 * @param order_id
 * @param timeout seconds to wait before giving up
 *
 * @return JSON Object
 * @return Result=SUCCESS,Details when link is ready
 * @return Result=LESSON_DOESNT_EXIST if no such lesson
 * @return Result=LINK_NOT_READY if timeout expires
 * @throws Exception if lesson is not marked live or on query error
 */
function long_poll_live_lesson_link($conn, $order_id, $timeout)
{
    $deadline = time() + intval($timeout);
    while (time() <= $deadline) {
        $sql = "SELECT Details FROM ActiveLessons WHERE OrderID = '" . $order_id . "'";
        $result = mysqli_query($conn, $sql);
        if (!$result) throw new Exception(mysqli_error($conn));
        if (mysqli_num_rows($result) === 0) return '{"Result": "LESSON_DOESNT_EXIST"}';

        $row = mysqli_fetch_assoc($result);
        $details = json_decode($row['Details'], true);

        if (empty($details['IsImmediate'])) throw new Exception('Lesson is not live');
        if (!empty($details['Link'])) return json_encode(['Result' => 'SUCCESS', 'Details' => $details]);

        sleep(1);
    }
    return '{"Result": "LINK_NOT_READY"}';
}

/**
 * Long-poll for a lesson until it becomes active (until it enters the ActiveLessons table).
 *
 * @param order_id
 * @param timeout seconds to wait before giving up
 *
 * @return JSON Object
 * @return Result=SUCCESS,Details when the lesson is active
 * @return Result=LESSON_DOESNT_EXIST if no such lesson
 */
function long_poll_active_lesson($conn, $order_id, $timeout)
{
    $deadline = time() + intval($timeout);
    while (time() <= $deadline) {
        $sql = "SELECT Details FROM ActiveLessons WHERE OrderID = '" . $order_id . "'";
        $result = mysqli_query($conn, $sql);
        if (!$result) throw new Exception(mysqli_error($conn));

        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $details = json_decode($row['Details'], true);
            return json_encode(['Result' => 'SUCCESS', 'Details' => $details]);
        }

        sleep(1);
    }
    return '{"Result": "LESSON_DOESNT_EXIST"}';
}

/**
 * Get teachers lesson history.
 *
 * @param Phone The teacher's phone number
 * @param Password The teacher's password
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS,History[ID,Details] in case of success
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 */
function get_teachers_lesson_history($conn, $phone, $password)
{
    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check if the password is correct
    $sql = "SELECT ID, Password FROM Teachers WHERE Phone = '".$phone."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) {
                return '{"Result": "WRONG_PASSWORD"}';
            }
            $id = $row['ID'];
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Get all the lessons from the LessonsHistory table
    $sql = "SELECT ID, Details FROM LessonsHistory WHERE TeacherID = '".$id."'";
    $result = mysqli_query($conn ,$sql);
    if ($result) {
        $history = array();
        while ($row = mysqli_fetch_assoc($result)) {
            array_push($history, array('ID' => $row['ID'], 'Details' => json_decode($row['Details'], true)));
        }
        return json_encode(array('Result' => 'SUCCESS', 'History' => $history));
    } else {
        throw new Exception(mysqli_error($conn));
    }
}

/**
 * Cancel a lesson.
 *
 * @param Phone The user's phone number
 * @param Password The user's password
 * @param OrderID The order ID
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS in case of success
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=LESSON_ALREADY_STARTED in case the lesson has already started
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 */
function cancel_lesson($conn, $phone, $password, $order_id)
{
    $output = array('Result' => 'None');

    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check if the password is correct, and get the user's CurrentAppointments
    $sql = "SELECT ID, Password, CurrentAppointments FROM Customers WHERE Phone = '".$phone."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) return '{"Result": "WRONG_PASSWORD"}';

            $current_appointments = json_decode($row['CurrentAppointments'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Find the lesson to cancel in the user's CurrentAppointments
    $found = false;
    foreach ($current_appointments as $key => $appointment) {
        if ($appointment['OrderID'] == $order_id) {
            $found = true;
            $details = $appointment;
            $pending = $details['IsPending'];
            unset($current_appointments[$key]);
        }
    }
    if (!$found) throw new Exception('Lesson not found in CurrentAppointments');

    // cant cancel a lesson that has already started
    if ($details['StartTimestamp'] < time()) return '{"Result": "LESSON_ALREADY_STARTED"}';

    // Remove the lesson from the user's CurrentAppointments
    $sql = "UPDATE Customers SET CurrentAppointments='".mysqli_real_escape_string($conn, json_encode(array_values($current_appointments), JSON_UNESCAPED_UNICODE))."' WHERE Phone='".$phone."'";
    if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));

    if (!$pending) {
        // Remove the lesson from the Teacher's CurrentAppointments
        $teacher_id = $details['TeacherID'];
        $sql = "SELECT CurrentAppointments FROM Teachers WHERE ID = '".$teacher_id."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);

                // remove the lesson from the teacher's CurrentAppointments
                foreach ($teacher_current_appointments as $key => $appointment) {
                    if ($appointment['OrderID'] == $order_id) {
                        unset($teacher_current_appointments[$key]);
                    }
                }
                $sql = "UPDATE Teachers SET CurrentAppointments='".mysqli_real_escape_string($conn, json_encode(array_values($teacher_current_appointments), JSON_UNESCAPED_UNICODE))."' WHERE ID='".$teacher_id."'";
                if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));
            }
        } else {
            throw new Exception('Teacher not found');
        }
    }

    // Remove the lesson from the ActiveLessons table
    $sql = "DELETE FROM ActiveLessons WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    if (!$pending) notify_lesson_cancelled_by_student([$teacher_id], $details['StartTimestamp']);

    $output = array('Result' => 'SUCCESS');
    return json_encode($output);
}

/**
 * Accept a lesson. The lesson will no longer be available for other teachers to accept.
 *
 * @param Phone The teachers's phone number
 * @param Password The teacher's password
 * @param OrderID The order ID
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS in case of success for non-immediate lessons
 * @return Result=SUCCESS,Details in case of success for immediate lessons
 * @return Result=LESSON_DOESNT_EXIST in case the lesson is not in the database
 * @return Result=LESSON_ALREADY_ACCEPTED in case the lesson is already accepted
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 */
function accept_lesson($conn, $phone, $password, $order_id)
{
    $output = array('Result' => 'None');

    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check if the password is correct, and get the teacher's ID, name and CurrentAppointments
    $sql = "SELECT ID, Username, Password, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) return '{"Result": "WRONG_PASSWORD"}';

            $id = $row['ID'];
            $name = $row['Username'];
            $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Find the lesson to accept in the ActiveLessons table
    $sql = "SELECT Details FROM ActiveLessons WHERE OrderID = '".$order_id."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            $details = json_decode($row['Details'], true);
            $student_id = $details['StudentID'];
        }
    } else {
        return '{"Result": "LESSON_DOESNT_EXIST"}';
    }

    // Check if the lesson is already accepted
    if ($details['IsPending'] == false) {
        return '{"Result": "LESSON_ALREADY_ACCEPTED"}';
    }

    // update the lesson details with the teacher's information
    $details['IsPending'] = false;
    $details['TeacherID'] = intval($id);
    $details['TeacherName'] = $name;
    $details['TeacherPhone'] = $phone;
    // update the Start and End timestamps to the current time + 5 minutes for immediate lessons
    $created_meeting = false;
    if ($details['IsImmediate'] == 1) {
        $details['StartTimestamp'] = time() + 300;
        $details['EndTimestamp'] = $details['StartTimestamp'] + ($details['DurationMinutes'] * 60);
        $details['Link'] = create_meeting();
        $created_meeting = true;

        $output['Details'] = $details;
    } else if ($details['StartTimestamp'] < (time() + 1800)) {
        $details['Link'] = create_meeting();
        $created_meeting = true;
    }

    // Update the lesson in the ActiveLessons table, set IsPending to false and update the details
    $sql = "UPDATE ActiveLessons SET IsPending=0, StartTimeStamp='".$details['StartTimestamp']."', EndTimeStamp='".$details['EndTimestamp']."', Details='".mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE))."' WHERE OrderID='".$order_id."'";
    if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));

    // Add the lesson to the teacher's CurrentAppointments
    array_push($teacher_current_appointments, $details);
    $sql = "UPDATE Teachers SET CurrentAppointments='".mysqli_real_escape_string($conn, json_encode($teacher_current_appointments, JSON_UNESCAPED_UNICODE))."' WHERE Phone='".$phone."'";
    if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));

    // Update the lesson in the user's CurrentAppointments and send a notification
    $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '".$student_id."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            $current_appointments = json_decode($row['CurrentAppointments'], true);

            // update the lesson in the user's CurrentAppointments
            foreach ($current_appointments as $key => $appointment) {
                if ($appointment['OrderID'] == $order_id) {
                    $current_appointments[$key] = $details;
                    break;
                }
            }

            $sql = "UPDATE Customers SET CurrentAppointments='".mysqli_real_escape_string($conn, json_encode($current_appointments, JSON_UNESCAPED_UNICODE))."' WHERE ID='".$student_id."'";
            if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));
        }
    } else {
        throw new Exception('Student not found');
    }

    notify_new_teacher_assigned([$student_id], $details['StartTimestamp']);
    if ($created_meeting) notify_meeting_creation([$student_id, $id], $details['StartTimestamp']);

    $output['Result'] = 'SUCCESS';
    return json_encode($output);
}

/**
 * Reject an accepted lesson. The lesson will be available for other teachers to accept.
 *
 * @param Phone The teachers's phone number
 * @param Password The teacher's password
 * @param OrderID The order ID
 *
 * @return JSON Object with the result of the operation
 * @return Result=SUCCESS in case of success
 * @return Result=LESSON_DOESNT_EXIST in case the lesson is not in the database
 * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
 * @return Result=LESSON_ALREADY_STARTED in case the lesson has already started
 * @return Result=WRONG_PASSWORD in case the password is incorrect
 */
function reject_lesson($conn, $phone, $password, $order_id)
{
    $output = array('Result' => 'None');

    // Check if the phone number is 12 characters long, if not, end the script
    if (strlen($phone) != 12) throw new Exception('Phone number is not in international format');

    // Check if the password is correct, and get the teacher's CurrentAppointments
    $sql = "SELECT ID, Password, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            if (!password_verify($password, $row['Password'])) return '{"Result": "WRONG_PASSWORD"}';

            $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);
        }
    } else {
        return '{"Result": "PHONE_DOESNT_EXIST"}';
    }

    // Find the lesson to reject in the teacher's CurrentAppointments
    $found = false;
    foreach ($teacher_current_appointments as $key => $appointment) {
        if ($appointment['OrderID'] == $order_id) {
            $found = true;
            $details = $appointment;
            $student_id = $details['StudentID'];

            $details['IsPending'] = true;
            $details['TeacherID'] = 0;
            $details['TeacherName'] = "";
            $details['TeacherPhone'] = "";
            $details['Link'] = "";

            unset($teacher_current_appointments[$key]);
        }
    }
    if (!$found) return '{"Result": "LESSON_DOESNT_EXIST"}';

    // cant reject a lesson that has already started
    if ($details['StartTimestamp'] < time()) return '{"Result": "LESSON_ALREADY_STARTED"}';

    // Remove the lesson from the teacher's CurrentAppointments
    $sql = "UPDATE Teachers SET CurrentAppointments='". mysqli_real_escape_string($conn, json_encode(array_values($teacher_current_appointments), JSON_UNESCAPED_UNICODE))."' WHERE Phone='".$phone."'";
    if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));

    // Update the lesson in the user's CurrentAppointments and send a notification
    $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '".$student_id."'";
    $result = mysqli_query($conn ,$sql);
    if(mysqli_num_rows($result) > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            $current_appointments = json_decode($row['CurrentAppointments'], true);

            // update the lesson in the user's CurrentAppointments
            foreach ($current_appointments as $key => $appointment) {
                if ($appointment['OrderID'] == $order_id) {
                    $current_appointments[$key] = $details;
                    break;
                }
            }

            $sql = "UPDATE Customers SET CurrentAppointments='". mysqli_real_escape_string($conn, json_encode($current_appointments, JSON_UNESCAPED_UNICODE))."' WHERE ID='".$student_id."'";
            if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));
        }
    } else {
        throw new Exception('Student not found');
    }

    // Update the lesson in the ActiveLessons table
    $sql = "UPDATE ActiveLessons SET IsPending=1, Details='". mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE))."' WHERE OrderID='".$order_id."'";
    if (!mysqli_query($conn ,$sql)) throw new Exception(mysqli_error($conn));

    notify_lesson_cancelled_by_teacher([$student_id], $details['StartTimestamp']);

    $output = array('Result' => 'SUCCESS');
    return json_encode($output);
}
?>
