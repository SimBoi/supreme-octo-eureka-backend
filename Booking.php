<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/Payment.php';


    /**
     * Create a lesson payment request and return the payment link and order ID.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param Title The title of the lesson
     * @param StartTimestamp The start timestamp of the lesson
     * @param DurationMinutes The duration of the lesson
     * @param Language The language of the payment page
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS,PaymentLink,OrderID in case of success
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function create_order_request($conn, $phone, $password, $title, $start_timestamp, $duration_minutes, $language)
    {
        global $hourly_rate;

        $output = array('Result' => 'None');

        $start_timestamp = intval($start_timestamp);
        $duration_minutes = intval($duration_minutes);

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');
        // Check if the duration is greater than 10, if not, end the script
        if ($duration_minutes < 10) die('{"Result": "ERROR: Duration is less than 10 minutes"}');
        // Check if the start timestamp is at least 30 minutes in the future, if not, end the script
        if ($start_timestamp < (time() + 1800)) die('{"Result": "ERROR: Start timestamp is less than 30 minutes in the future"}');

        // Check if the password is correct and get the user's ID, name, Orders list, and CurrentAppointments list
        $sql = "SELECT ID, Username, Orders, CurrentAppointments, Password FROM Customers WHERE Phone = '" . $phone . "'";
        $result = mysqli_query($conn, $sql);
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $id = $row['ID'];
                $name = $row['Username'];
                $orders = json_decode($row['Orders'], true);
                $current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Get the auto-incremented order id
        $sql = "INSERT INTO PaymentRequests () VALUES ()";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');
        $order_id = mysqli_insert_id($conn);

        // generate the order details
        $order_timestamp = time();
        $price = $hourly_rate * $duration_minutes / 60;
        $status = 0; // 0 = pending, 1 = paid, 2 = canceled
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
            'StartTimestamp' => $start_timestamp,
            'DurationMinutes' => $duration_minutes,
            'EndTimestamp' => $end_timestamp,
            'IsPending' => true,
            'Link' => ""
        );

        // Check if the user has any overlapping appointments
        foreach ($current_appointments as $appointment) {
            if ($start_timestamp >= $appointment['StartTimestamp'] && $start_timestamp < $appointment['EndTimestamp']) die('{"Result": "ERROR: Overlapping lesson"}');
            if ($end_timestamp > $appointment['StartTimestamp'] && $end_timestamp <= $appointment['EndTimestamp']) die('{"Result": "ERROR: Overlapping lesson"}');
        }

        // Send a payment request POST request to the payment server api and get the payment link
        $payment_link = create_payment_request($order_id, $name, $phone, $duration_minutes, $price, $language);
        if ($payment_link == null) die('{"Result": "ERROR: Payment request failed"}');

        // Add the order to the PaymentRequests table
        $sql = "UPDATE PaymentRequests SET OrderTimestamp='" . $order_timestamp . "', StudentID='" . $id . "', DurationMinutes='" . $duration_minutes . "', Status='" . $status . "', PaymentLink='" . $payment_link . "', LessonDetails='" . json_encode($details) . "' WHERE OrderID='" . $order_id . "'";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');

        // Add the order to the user's Orders list
        $order = array(
            'OrderID' => $order_id,
            'OrderTimestamp' => $order_timestamp,
            'DurationMinutes' => $duration_minutes,
            'Status' => $status,
            'ReceiptURL' => ""
        );
        array_push($orders, $order);
        $sql = "UPDATE Customers SET Orders='" . json_encode($orders) . "' WHERE Phone='" . $phone . "'";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');

        // return the payment link and order id
        $output = array('Result' => 'SUCCESS', 'PaymentLink' => $payment_link, 'OrderID' => $order_id);
        return json_encode($output);
    }

    /**
     * Confirm a payment for a lesson and make the lesson available to teachers.
     * return the payment receipt URL.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param OrderID The order ID
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS,ReceiptURL in case of success
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function confirm_order($conn, $phone, $password, $order_id)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct and get the user's Orders list and CurrentAppointments list
        $sql = "SELECT ID, Username, Orders, CurrentAppointments, Password FROM Customers WHERE Phone = '" . $phone . "'";
        $result = mysqli_query($conn, $sql);
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $orders = json_decode($row['Orders'], true);
                $current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Confirm the payment using the order id and the payment server api
        $payment_status = verify_payment($order_id);
        if ($payment_status['status'] != 1) die('{"Result": "ERROR: Payment verification failed"}');
        $receipt_url = $payment_status['receipt_url'];

        // Get the order details from the PaymentRequests table and update the order status
        $sql = "SELECT LessonDetails FROM PaymentRequests WHERE OrderID = '" . $order_id . "'";
        $result = mysqli_query($conn, $sql);
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $details = json_decode($row['LessonDetails'], true);
                $status = 1; // 0 = pending, 1 = paid, 2 = canceled

                $sql = "UPDATE PaymentRequests SET Status='" . $status . "' WHERE OrderID='" . $order_id . "'";
                if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');
            }
        } else {
            die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');
        }

        // Update the order in the Orders list
        foreach ($orders as $key => $order) {
            if ($order['OrderID'] == $order_id) {
                $orders[$key]['Status'] = $status;
                $orders[$key]['ReceiptURL'] = $receipt_url;
                break;
            }
        }
        $sql = "UPDATE Customers SET Orders='" . json_encode($orders) . "' WHERE Phone='" . $phone . "'";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: ' . mysqli_error($conn) . '"}');

        // Add a new row to the PendingLessons table (studentID:int, details:json)
        $sql = "INSERT INTO PendingLessons (StudentID, Details) VALUES ('".$details['StudentID']."', '".json_encode($details)."')";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        // Add the new lesson to the user's CurrentAppointments
        array_push($current_appointments, $details);
        $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        $output = array('Result' => 'SUCCESS', 'ReceiptURL' => $receipt_url);
        return json_encode($output);
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
     * @return Result=ERROR in case of failure
     */
    function get_pending_lessons($conn, $phone, $password)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct
        $sql = "SELECT Password FROM Teachers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) {
                    die('{"Result": "WRONG_PASSWORD"}');
                }
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Get all the pending lessons from the PendingLessons table
        $sql = "SELECT Details FROM PendingLessons";
        $result = mysqli_query($conn ,$sql);
        if ($result) {
            $pending_lessons = array();
            while ($row = mysqli_fetch_assoc($result)) {
                array_push($pending_lessons, json_decode($row['Details'], true));
            }
            return json_encode(array('Result' => 'SUCCESS', 'PendingLessons' => json_encode($pending_lessons)));
        } else {
            die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
        }
    }

    /**
     * Cancel a lesson.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param StartTimestamp The start timestamp of the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function cancel_lesson($conn, $phone, $password, $start_timestamp)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct, and get the user's ID and CurrentAppointments
        $sql = "SELECT ID, Password, CurrentAppointments FROM Customers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $id = $row['ID'];
                $current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Find the lesson to cancel in the user's CurrentAppointments
        $found = false;
        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['StartTimestamp'] == $start_timestamp) {
                $found = true;
                $details = $appointment;
                $pending = $details['IsPending'];
                unset($current_appointments[$key]);
            }
        }
        if (!$found) die('{"Result": "ERROR: Lesson not found"}');

        // Remove the lesson from the user's CurrentAppointments
        $sql = "UPDATE Customers SET CurrentAppointments='".json_encode(array_values($current_appointments))."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        if ($pending) {
            // Remove the lesson from the PendingLessons table
            $sql = "DELETE FROM PendingLessons WHERE StudentID='".$id."' AND Details LIKE '%\"StartTimestamp\": ".$start_timestamp."%'";
            if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
        } else {
            // Remove the lesson from the Teacher's CurrentAppointments
            $teacher_id = $details['TeacherID'];
            $sql = "SELECT CurrentAppointments FROM Teachers WHERE ID = '".$teacher_id."'";
            $result = mysqli_query($conn ,$sql);
            if(mysqli_num_rows($result) > 0) {
                while($row = mysqli_fetch_assoc($result)) {
                    $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);

                    foreach ($teacher_current_appointments as $key => $appointment) {
                        if ($appointment['StartTimestamp'] == $start_timestamp) {
                            unset($teacher_current_appointments[$key]);
                        }
                    }

                    $sql = "UPDATE Teachers SET CurrentAppointments='".json_encode(array_values($teacher_current_appointments))."' WHERE ID='".$teacher_id."'";
                    if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
                }
            } else {
                die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
            }
        }

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
    }

    /**
     * Accept a lesson.
     *
     * @param Phone The teachers's phone number
     * @param Password The teacher's password
     * @param StudentID The student's ID
     * @param StartTimestamp The start timestamp of the lesson
     * @param Link The link to the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=LESSON_DOESNT_EXIST in case the lesson is not in the database
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function accept_lesson($conn, $phone, $password, $student_id, $start_timestamp, $link)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct, and get the teacher's ID, name and CurrentAppointments
        $sql = "SELECT ID, Username, Password, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $id = $row['ID'];
                $name = $row['Username'];
                $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Find the lesson to accept in the PendingLessons table
        $sql = "SELECT Details FROM PendingLessons WHERE StudentID = '".$student_id."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                $details = json_decode($row['Details'], true);
                if ($details['StartTimestamp'] == $start_timestamp) break;
            }
        } else {
            die('{"Result": "LESSON_DOESNT_EXIST"}');
        }

        // Remove the lesson from the PendingLessons table
        $sql = "DELETE FROM PendingLessons WHERE StudentID='".$student_id."' AND Details LIKE '%\"StartTimestamp\": ".$start_timestamp."%'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        $details['IsPending'] = false;
        $details['TeacherID'] = intval($id);
        $details['TeacherName'] = $name;
        $details['TeacherPhone'] = $phone;
        $details['Link'] = $link;

        // Add the lesson to the teacher's CurrentAppointments
        array_push($teacher_current_appointments, $details);
        $sql = "UPDATE Teachers SET CurrentAppointments='".json_encode($teacher_current_appointments)."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        // Update the lesson in the user's CurrentAppointments
        $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '".$student_id."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                $current_appointments = json_decode($row['CurrentAppointments'], true);

                foreach ($current_appointments as $key => $appointment) {
                    if ($appointment['StartTimestamp'] == $start_timestamp) {
                        $current_appointments[$key] = $details;
                        break;
                    }
                }

                $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE ID='".$student_id."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
            }
        } else {
            die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
        }

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
    }

    /**
     * Edit an accepted lesson link.
     *
     * @param Phone The teachers's phone number
     * @param Password The teacher's password
     * @param StudentID The student's ID
     * @param StartTimestamp The start timestamp of the lesson
     * @param NewLink The new link to the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=LESSON_DOESNT_EXIST in case the lesson is not in the database
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function edit_lesson_link($conn, $phone, $password, $student_id, $start_timestamp, $new_link)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct, and get the CurrentAppointments
        $sql = "SELECT Password, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Find the lesson to edit in the teacher's CurrentAppointments
        $found = false;
        foreach ($teacher_current_appointments as $key => $appointment) {
            if ($appointment['StudentID'] == $student_id && $appointment['StartTimestamp'] == $start_timestamp) {
                $found = true;
                $teacher_current_appointments[$key]['Link'] = $new_link;
            }
        }
        if (!$found) die('{"Result": "LESSON_DOESNT_EXIST"}');

        // Update the lesson in the teacher's CurrentAppointments
        $sql = "UPDATE Teachers SET CurrentAppointments='".json_encode(array_values($teacher_current_appointments))."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        // Update the lesson in the user's CurrentAppointments
        $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '".$student_id."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                $current_appointments = json_decode($row['CurrentAppointments'], true);

                foreach ($current_appointments as $key => $appointment) {
                    if ($appointment['StartTimestamp'] == $start_timestamp) {
                        $current_appointments[$key]['Link'] = $new_link;
                        break;
                    }
                }

                $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE ID='".$student_id."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
            }
        } else {
            die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
        }

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
    }

    /**
     * Reject an accepted lesson. The lesson will be added back to the pending lessons.
     *
     * @param Phone The teachers's phone number
     * @param Password The teacher's password
     * @param StudentID The student's ID
     * @param StartTimestamp The start timestamp of the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=LESSON_DOESNT_EXIST in case the lesson is not in the database
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function reject_lesson($conn, $phone, $password, $student_id, $start_timestamp)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');

        // Check if the password is correct, and get the teacher's ID and CurrentAppointments
        $sql = "SELECT ID, Password, CurrentAppointments FROM Teachers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $id = $row['ID'];
                $teacher_current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        // Find the lesson to reject in the teacher's CurrentAppointments
        $found = false;
        foreach ($teacher_current_appointments as $key => $appointment) {
            if ($appointment['StudentID'] == $student_id && $appointment['StartTimestamp'] == $start_timestamp) {
                $found = true;
                $details = $appointment;
                $details['IsPending'] = true;
                $details['TeacherID'] = 0;
                $details['TeacherName'] = "";
                $details['TeacherPhone'] = "";
                $details['Link'] = "";
                unset($teacher_current_appointments[$key]);
            }
        }
        if (!$found) die('{"Result": "LESSON_DOESNT_EXIST"}');

        // Remove the lesson from the teacher's CurrentAppointments
        $sql = "UPDATE Teachers SET CurrentAppointments='".json_encode(array_values($teacher_current_appointments))."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        // Update the lesson in the user's CurrentAppointments
        $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '".$student_id."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                $current_appointments = json_decode($row['CurrentAppointments'], true);

                foreach ($current_appointments as $key => $appointment) {
                    if ($appointment['StartTimestamp'] == $start_timestamp) {
                        $current_appointments[$key] = $details;
                        break;
                    }
                }

                $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE ID='".$student_id."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
            }
        } else {
            die('{"Result": "ERROR: '.mysqli_error($conn).'"}');
        }

        // Add the lesson back to the PendingLessons table
        $sql = "INSERT INTO PendingLessons (StudentID, Details) VALUES ('".$student_id."', '".json_encode($details)."')";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
    }
?>
