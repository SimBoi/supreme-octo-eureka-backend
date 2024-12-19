<?php
    require_once '/var/www/html/supreme-octo-eureka-backend/utilities.php';
    require_once '/var/www/html/supreme-octo-eureka-backend/Notification.php';

    $start_time = time();

    echo "===============================\n";
    echo "Checking for ended lessons...\n";
    echo "-----------------------\n";

    $conn = _connect_to_db();

    // Get all the active lessons from the ActiveLessons table
    $sql = "SELECT OrderID, Details FROM ActiveLessons WHERE EndTimeStamp < " . time();
    $result_lessons = mysqli_query($conn, $sql);
    if ($result_lessons) {
        while ($row = mysqli_fetch_assoc($result_lessons)) {
            $order_id = $row['OrderID'];
            $details_json = $row['Details'];
            $details = json_decode($details_json, true);
            $is_pending = $details['IsPending'];

            echo "OrderID: " . $order_id . ", IsPending: " . $is_pending . "\n";

            // Move the lesson to the LessonsHistory table
            $sql = "INSERT INTO LessonsHistory (OrderID, Details) VALUES ('" . $order_id . "', '" . $details_json . "')";
            if (!mysqli_query($conn, $sql)) die("ERROR: " . mysqli_error($conn) . "\n");
            echo "Added LessonsHistory table entry...\n";

            // Remove the lesson from the ActiveLessons table
            $sql = "DELETE FROM ActiveLessons WHERE OrderID='" . $order_id . "'";
            if (!mysqli_query($conn, $sql)) die("ERROR: " . mysqli_error($conn) . "\n");
            echo "Removed ActiveLessons table entry...\n";

            // Remove the lesson from the user's CurrentAppointments
            $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '" . $details['StudentID'] . "'";
            $result = mysqli_query($conn, $sql);
            if (mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $current_appointments = json_decode($row['CurrentAppointments'], true);

                    foreach ($current_appointments as $key => $appointment) {
                        if ($appointment['OrderID'] == $order_id) {
                            unset($current_appointments[$key]);
                            break;
                        }
                    }

                    $sql = "UPDATE Customers SET CurrentAppointments='" . json_encode(array_values($current_appointments)) . "' WHERE ID='" . $details['StudentID'] . "'";
                    if (!mysqli_query($conn, $sql)) die("ERROR: " . mysqli_error($conn) . "\n");
                }
            } else {
                die("ERROR: " . mysqli_error($conn) . "\n");
            }
            echo "Removed lesson from student's CurrentAppointments...\n";

            if (!$is_pending) {
                // Remove the lesson from the teacher's CurrentAppointments
                $sql = "SELECT CurrentAppointments FROM Teachers WHERE ID = '" . $details['TeacherID'] . "'";
                $result = mysqli_query($conn, $sql);
                if (mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $current_appointments = json_decode($row['CurrentAppointments'], true);

                        foreach ($current_appointments as $key => $appointment) {
                            if ($appointment['OrderID'] == $order_id) {
                                unset($current_appointments[$key]);
                                break;
                            }
                        }

                        $sql = "UPDATE Teachers SET CurrentAppointments='" . json_encode(array_values($current_appointments)) . "' WHERE ID='" . $details['TeacherID'] . "'";
                        if (!mysqli_query($conn, $sql)) die("ERROR: " . mysqli_error($conn) . "\n");
                    }
                } else {
                    die("ERROR: " . mysqli_error($conn) . "\n");
                }
                echo "Removed lesson from teacher's CurrentAppointments...\n";
            }

            echo "Ended lesson successfully!\n-----------------------\n";
        }
        echo "finished in " . (time() - $start_time) . " seconds\n";
        echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n";
    } else {
        die("ERROR: " . mysqli_error($conn) . "\n");
    }

    $start_time = time();

    echo "Checking for lessons about to start...\n";
    echo "-----------------------\n";

    // get all lessons that are about to start in 20 <= minutes <= 30
    $sql = "SELECT OrderID, Details FROM ActiveLessons WHERE StartTimeStamp <= " . (time() + 1800) . " AND StartTimeStamp >= " . (time() + 1200);
    $result_lessons = mysqli_query($conn, $sql);
    if ($result_lessons) {
        while ($row = mysqli_fetch_assoc($result_lessons)) {
            $order_id = $row['OrderID'];
            $details_json = $row['Details'];
            $details = json_decode($details_json, true);
            // if ($details['IsPending']) {
            //     continue;
            // }
            $is_pending = $details['IsPending'];
            $student_id = $details['StudentID'];
            $teacher_id = $details['TeacherID'];

            echo "OrderID: " . $order_id . "\n";

            $lesson_date_string = date('d/m H:i', $details['StartTimestamp']);
            // Send a notification to the student
            send_notification(
                array(strval($student_id)),
                "Your lesson at " . $lesson_date_string . " is starting soon!",
                "درسك في " . $lesson_date_string . " سيبدأ قريباً!",
                "השיעור שלך ב-" . $lesson_date_string . " יתחיל בקרוב!"
            );
            echo "Notified student " . $student_id . "\n";
            // Send a notification to the teacher
            // send_notification(
            //     array($teacher_id),
            //     "Your lesson at " . $lesson_date_string . " is starting soon!",
            //     "درسك في " . $lesson_date_string . " سيبدأ قريباً!",
            //     "השיעור שלך ב-" . $lesson_date_string . " יתחיל בקרוב!"
            // );
            if (!$is_pending) {
                send_notification(
                    array($teacher_id),
                    "Your lesson at " . $lesson_date_string . " is starting soon!",
                    "درسك في " . $lesson_date_string . " سيبدأ قريباً!",
                    "השיעור שלך ב-" . $lesson_date_string . " יתחיל בקרוב!"
                );
                echo "Notified teacher " . $teacher_id . "\n";
            }

            // echo "Notified teacher " . $teacher_id . "\n";
            echo "-----------------------\n";

        }
        echo "finished in " . (time() - $start_time) . " seconds\n";
        echo "===============================\n";
    } else {
        die("ERROR: " . mysqli_error($conn) . "\n");
    }
?>
