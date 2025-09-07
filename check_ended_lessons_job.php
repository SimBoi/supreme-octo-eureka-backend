<?php
require_once '/var/www/html/prod/utilities.php';
require_once '/var/www/html/prod/Notification.php';
require_once '/var/www/html/prod/Meetings.php';
// TODO cancel pending live lessons if it becomes overlapping with another lesson
$start_time = time();


echo "=============================== " . date('d/m/Y H:i:s', time()) . "\n";
$conn = _connect_to_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_begin_transaction($conn);

try {
    // Get all the active lessons from the ActiveLessons table that have ended
    echo "Checking for ended lessons...\n";
    echo "-----------------------\n";
    $sql = "SELECT OrderID, Details FROM ActiveLessons WHERE EndTimeStamp < " . time() . " AND IsPending = 0";
    $result_lessons = mysqli_query($conn, $sql);
    if (!$result_lessons) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result_lessons)) {
        end_fulfilled_lesson($conn, $row['OrderID'], json_decode($row['Details'], true));
    }
    echo "finished in " . (time() - $start_time) . " seconds\n";
    echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n";


    // get all lessons that are about to start in 20 <= minutes <= 30
    $start_time = time();
    echo "Checking for lessons about to start...\n";
    echo "-----------------------\n";
    $sql = "SELECT OrderID, Details FROM ActiveLessons WHERE StartTimeStamp <= " . (time() + 1800) . " AND StartTimeStamp >= " . (time() + 1200) . " AND IsPending = 0";
    $result_lessons = mysqli_query($conn, $sql);
    if (!$result_lessons) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result_lessons)) {
        create_lesson_meeting($conn, $row['OrderID'], json_decode($row['Details'], true));
    }
    echo "finished in " . (time() - $start_time) . " seconds\n";
    echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n";

    // get all pending lessons that have started
    $start_time = time();
    echo "Checking for pending lessons that have started...\n";
    echo "-----------------------\n";
    $sql = "SELECT OrderID, Details FROM ActiveLessons WHERE StartTimeStamp <= " . time() . " AND IsPending = 1";
    $result_lessons = mysqli_query($conn, $sql);
    if (!$result_lessons) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result_lessons)) {
        cancel_unfulfilled_lesson($conn, $row['OrderID'], json_decode($row['Details'], true));
    }
    echo "finished in " . (time() - $start_time) . " seconds\n";

    // Commit the transaction if everything is successful
    mysqli_commit($conn);
} catch (Exception $e) {
    // Roll back the transaction on error.
    mysqli_rollback($conn);
    echo "ERROR: " . $e->getMessage() . "\n";
} finally {
    mysqli_close($conn);
}

function end_fulfilled_lesson($conn, $order_id, $details)
{
    echo "OrderID: " . $order_id . "\n";

    // Move the lesson to the LessonsHistory table
    $sql = "INSERT INTO LessonsHistory (OrderID, StudentID, TeacherID, Details) VALUES ('" . $order_id . "', '" . $details['StudentID'] . "', '" . $details['TeacherID'] . "', '" . mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE)) . "')";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    echo "Added LessonsHistory table entry...\n";

    // Remove the lesson from the ActiveLessons table
    $sql = "DELETE FROM ActiveLessons WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    echo "Removed ActiveLessons table entry...\n";

    // Remove the lesson from the user's CurrentAppointments
    $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '" . $details['StudentID'] . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result)) {
        $current_appointments = json_decode($row['CurrentAppointments'], true);

        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['OrderID'] == $order_id) {
                unset($current_appointments[$key]);
                break;
            }
        }

        $sql = "UPDATE Customers SET CurrentAppointments='" . mysqli_real_escape_string($conn, json_encode(array_values($current_appointments), JSON_UNESCAPED_UNICODE)) . "' WHERE ID='" . $details['StudentID'] . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    }
    echo "Removed lesson from student's CurrentAppointments...\n";

    // Remove the lesson from the teacher's CurrentAppointments
    $sql = "SELECT CurrentAppointments FROM Teachers WHERE ID = '" . $details['TeacherID'] . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result)) {
        $current_appointments = json_decode($row['CurrentAppointments'], true);

        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['OrderID'] == $order_id) {
                unset($current_appointments[$key]);
                break;
            }
        }

        $sql = "UPDATE Teachers SET CurrentAppointments='" . mysqli_real_escape_string($conn, json_encode(array_values($current_appointments), JSON_UNESCAPED_UNICODE)) . "' WHERE ID='" . $details['TeacherID'] . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    }
    echo "Removed lesson from teacher's CurrentAppointments...\n";
    echo "Ended lesson successfully!\n-----------------------\n";
}

function create_lesson_meeting($conn, $order_id, $details)
{
    if ($details['Link'] != "") return;

    $student_id = $details['StudentID'];
    $teacher_id = $details['TeacherID'];

    echo "OrderID: " . $order_id . "\n";

    $details['Link'] = create_meeting();

    // update the customer's CurrentAppointments
    $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '" . $student_id . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result)) {
        $current_appointments = json_decode($row['CurrentAppointments'], true);

        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['OrderID'] == $order_id) {
                $current_appointments[$key] = $details;
                break;
            }
        }

        $sql = "UPDATE Customers SET CurrentAppointments='" . mysqli_real_escape_string($conn, json_encode($current_appointments, JSON_UNESCAPED_UNICODE)) . "' WHERE ID='" . $student_id . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    }

    // update the teacher's CurrentAppointments
    $sql = "SELECT CurrentAppointments FROM Teachers WHERE ID = '" . $teacher_id . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result)) {
        $current_appointments = json_decode($row['CurrentAppointments'], true);

        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['OrderID'] == $order_id) {
                $current_appointments[$key] = $details;
                break;
            }
        }

        $sql = "UPDATE Teachers SET CurrentAppointments='" . mysqli_real_escape_string($conn, json_encode($current_appointments, JSON_UNESCAPED_UNICODE)) . "' WHERE ID='" . $teacher_id . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    }

    // update the ActiveLessons table
    $sql = "UPDATE ActiveLessons SET Details='" . mysqli_real_escape_string($conn, json_encode($details, JSON_UNESCAPED_UNICODE)) . "' WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

    echo "Created meeting for lesson...\n";

    // Send a notification
    notify_meeting_creation([$student_id, $teacher_id], $details['StartTimestamp']);
    echo "Notified student and teacher...\n";

    echo "-----------------------\n";
}

function cancel_unfulfilled_lesson($conn, $order_id, $details)
{
    global $STATUS_PAID, $STATUS_PENDING_REFUND;

    $student_id = $details['StudentID'];

    echo "OrderID: " . $order_id . "\n";

    // get the payment status, if its Paid, change it to pending refund
    $sql = "SELECT Status FROM PaymentRequests WHERE OrderID = '" . $order_id . "'";
    $res = mysqli_query($conn, $sql);
    if (!$res) throw new Exception(mysqli_error($conn));
    if (mysqli_num_rows($res) <= 0) throw new Exception(mysqli_error($conn));
    $row = mysqli_fetch_assoc($res);
    $status = $row['Status'];
    if ($status == $STATUS_PAID) {
        $status = $STATUS_PENDING_REFUND;

        // update the PaymentRequests table
        $sql = "UPDATE PaymentRequests SET Status='" . $status . "' WHERE OrderID='" . $order_id . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

        // update the users orders list
        $sql = "SELECT Orders FROM Customers WHERE ID = '" . $student_id . "'";
        $result = mysqli_query($conn, $sql);
        if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
        while ($row = mysqli_fetch_assoc($result)) {
            $orders = json_decode($row['Orders'], true);

            foreach ($orders as $key => $order) {
                if ($order['OrderID'] == $order_id) {
                    $orders[$key]['Status'] = $status;
                    break;
                }
            }

            $sql = "UPDATE Customers SET Orders='" . json_encode($orders) . "' WHERE ID='" . $student_id . "'";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
        }

        echo "Changed payment status to pending refund...\n";
    }

    // delete the lesson from the ActiveLessons table
    $sql = "DELETE FROM ActiveLessons WHERE OrderID='" . $order_id . "'";
    if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    echo "Removed ActiveLessons table entry...\n";

    // delete the lesson from the user's CurrentAppointments
    $sql = "SELECT CurrentAppointments FROM Customers WHERE ID = '" . $student_id . "'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) <= 0) throw new Exception(mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($result)) {
        $current_appointments = json_decode($row['CurrentAppointments'], true);

        foreach ($current_appointments as $key => $appointment) {
            if ($appointment['OrderID'] == $order_id) {
                unset($current_appointments[$key]);
                break;
            }
        }

        $sql = "UPDATE Customers SET CurrentAppointments='" . mysqli_real_escape_string($conn, json_encode(array_values($current_appointments), JSON_UNESCAPED_UNICODE)) . "' WHERE ID='" . $student_id . "'";
        if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
    }

    // notify the user that the lesson has been cancelled because no teacher was available
    notify_no_teacher_available([$student_id]);
    echo "Notified student...\n";

    echo "-----------------------\n";
}
?>
