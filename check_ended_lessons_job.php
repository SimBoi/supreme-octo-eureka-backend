<?php
    require_once '/var/www/html/supreme-octo-eureka-backend/utilities.php';

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
        echo "===============================\n";
    } else {
        die("ERROR: " . mysqli_error($conn) . "\n");
    }
?>
