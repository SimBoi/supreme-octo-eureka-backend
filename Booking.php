<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';


    /**
     * Order a lesson at the given time.
     *
     * @param Phone The user's phone number
     * @param Password The user's password
     * @param Title The title of the lesson
     * @param StartTimestamp The start timestamp of the lesson
     * @param Duration The duration of the lesson
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS in case of success
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=WRONG_PASSWORD in case the password is incorrect
     * @return Result=ERROR in case of failure
     */
    function order_lesson($conn, $phone, $password, $title, $start_timestamp, $duration_minutes)
    {
        $output = array('Result' => 'None');

        $start_timestamp = intval($start_timestamp);
        $duration_minutes = intval($duration_minutes);

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR: Phone number is not 12 characters long"}');
        // Check if the duration is greater than 10, if not, end the script
        if ($duration_minutes < 10) die('{"Result": "ERROR: Duration is less than 10 minutes"}');
        // Check if the start timestamp is at least 30 minutes in the future, if not, end the script
        if ($start_timestamp < (time() + 1800)) die('{"Result": "ERROR: Start timestamp is less than 30 minutes in the future"}');

        // Check if the password is correct, and get the user's ID, name and CurrentAppointments
        $sql = "SELECT ID, Username, Password, CurrentAppointments FROM Customers WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                if (!password_verify($password, $row['Password'])) die('{"Result": "WRONG_PASSWORD"}');

                $id = $row['ID'];
                $name = $row['Username'];
                $current_appointments = json_decode($row['CurrentAppointments'], true);
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        $end_timestamp = $start_timestamp + ($duration_minutes * 60);
        $details = array(
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

        // Add a new row to the PendingLessons table (studentID:int, details:json)
        $sql = "INSERT INTO PendingLessons (StudentID, Details) VALUES ('".$id."', '".json_encode($details)."')";
        if (!mysqli_query($conn, $sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

        // Add the new lesson to the user's CurrentAppointments
        array_push($current_appointments, $details);
        $sql = "UPDATE Customers SET CurrentAppointments='".json_encode($current_appointments)."' WHERE Phone='".$phone."'";
        if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR: '.mysqli_error($conn).'"}');

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
        $details['TeacherID'] = $id;
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
                $details['TeacherID'] = "";
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
