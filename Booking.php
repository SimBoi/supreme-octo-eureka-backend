<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/utilities.php';


    /**
     * Order a lesson at the given time.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param StartTimestamp The start timestamp of the lesson
     * @param Duration The duration of the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=ERROR in case of failure
     */
    function order_lesson($conn, $phone, $password, $start_timestamp, $duration_minutes)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');
        // Check if the duration is greater than 10, if not, end the script
        if ($duration_minutes < 10) die('{"Result": "ERROR"}');
        // Check if the start timestamp is in the future, if not, end the script
        if ($start_timestamp < time()) die('{"Result": "ERROR"}');

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

        $end_timestamp = $start_timestamp + ($duration_minutes * 60);
        $details = array(
            'StudentID' => $id,
            'StartTimestamp' => $start_timestamp,
            'DurationMinutes' => $duration_minutes,
            'EndTimestamp' => $end_timestamp,
            'IsPending' => true
        );

        // Check if the user has any overlapping appointments
        foreach ($current_appointments as $appointment) {
            if ($start_timestamp >= $appointment['StartTimestamp'] && $start_timestamp < $appointment['EndTimestamp']) die('{"Result": "ERROR"}');
            if ($end_timestamp > $appointment['StartTimestamp'] && $end_timestamp <= $appointment['EndTimestamp']) die('{"Result": "ERROR"}');
        }

        // Add a new row to the PendingLessons table (studentID:int, details:json)
        $sql = "INSERT INTO PendingLessons (StudentID, Details) VALUES ('".$id."', '".json_encode($details)."')";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR"}');

        // Add the new lesson to the user's CurrentAppointments
        array_push($current_appointments, $details);
        $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
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
     * @return Result=ERROR in case of failure
     */
    function cancel_lesson($conn, $phone, $password, $start_timestamp)
    {
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');

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
                unset($current_appointments[$key]);
            }
        }
        if (!$found) die('{"Result": "ERROR"}');

        // Remove the lesson from the user's CurrentAppointments
        $sql = "UPDATE Customers SET CurrentAppointments='".json_encode(array_values($current_appointments))."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

        if ($pending) {
            // Remove the lesson from the PendingLessons table
            $sql = "DELETE FROM PendingLessons WHERE StudentID='".$id."' AND Details LIKE '%\"StartTimestamp\":".$start_timestamp."%'";
            if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');
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
                    if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');
                }
            } else {
                die('{"Result": "ERROR"}');
            }
        }

        $output = array('Result' => 'SUCCESS');
        return json_encode($output);
    }
?>
