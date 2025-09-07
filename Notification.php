<?php
require_once '/var/www/html/prod/utilities.php';

$app_id = "e7d7a2bd-815c-4bd9-92dd-9b1f772b20c9";
$rest_api_key = "os_v2_app_47l2fpmblrf5tew5tmpxokzazga76yn4nxvef4e5knnfeapguqhe7b5yvdug72afea6iv4a7ifslgy25c4ktcb2c7bysajaywt3g6oa";

/**
 * Send a notification using OneSignal Rest API, takes an array of external IDs to send the notification to
 *
 * @param array $externalIDs An array of external IDs to send the notification to
 * @param String $content_en The English content of the notification
 * @param String $content_ar The Arabic content of the notification
 * @param String $content_he The Hebrew content of the notification
 *
 * @return String The response from the API
 */
function send_notification($externalIDs, $content_en, $content_ar, $content_he) {
    global $app_id, $rest_api_key;

    // remove any null or empty values from the array
    $externalIDs = array_filter($externalIDs);

    // convert all elements to strings
    $externalIDs = array_map('strval', $externalIDs);

    $curl = curl_init();

    $post_fields = array(
        "app_id" => $app_id,
        "target_channel" => "push",
        "contents" => array(
            "en" => $content_en,
            "ar" => $content_ar,
            "he" => $content_he
        ),
        "include_aliases" => array(
            "external_id" => $externalIDs
        )
    );

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.onesignal.com/notifications',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($post_fields), // might need to use JSON_UNESCAPE_UNICODE here
        CURLOPT_HTTPHEADER => array(
            'Authorization: Key ' . $rest_api_key,
            'accept: application/json',
            'content-type: application/json'
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return $response;
}

/**
 * Notify about meeting creation
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 * @param int $start_timestamp The start timestamp of the lesson
 *
 * @return String The response from the API
 */
function notify_meeting_creation($externalIDs, $start_timestamp)
{
    $lesson_date_string = date('d/m H:i', $start_timestamp);
    $content_en = "Your upcoming lesson at " . $lesson_date_string . " is starting soon! A virtual meeting has been set up for you and can be accessed in your homepage.";
    $content_ar = "الدرس القادم في " . $lesson_date_string . " على وشك البدء! تم إعداد اجتماع افتراضي لك ويمكن الوصول إليه في صفحتك الرئيسية.";
    $content_he = "השיעור הקרוב שלך ב-" . $lesson_date_string . " מתחיל בקרוב! פגישה וירטואלית הוקמה עבורך וניתן לגשת אליה בדף הבית שלך.";
    return send_notification($externalIDs, $content_en, $content_ar, $content_he);
}

/**
 * Notify about lesson cancellation
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 *
 * @return String The response from the API
 */
function notify_no_teacher_available($externalIDs)
{
    $content_en = "Unfortunately, your lesson has been cancelled because no teacher was available.";
    $content_ar = "للأسف، تم إلغاء درسك لأنه لم يكن هناك معلم متاح.";
    $content_he = "לצערנו, השיעור שלך בוטל כי לא היה מורה זמין.";
    return send_notification($externalIDs, $content_en, $content_ar, $content_he);
}

/**
 * Notify about lesson cancellation by student
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 * @param int $start_timestamp The start timestamp of the lesson
 *
 * @return String The response from the API
 */
function notify_lesson_cancelled_by_student($externalIDs, $start_timestamp)
{
    $lesson_date_string = date('d/m H:i', $start_timestamp);
    $content_en = "The lesson on " . $lesson_date_string . " has been cancelled by the student.";
    $content_ar = "تم إلغاء الدرس في " . $lesson_date_string . " من قبل الطالب.";
    $content_he = "השיעור ב-" . $lesson_date_string . " בוטל על ידי התלמיד.";
    return send_notification($externalIDs, $content_en, $content_ar, $content_he);
}

/**
 * Notify about lesson cancellation by teacher
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 * @param int $start_timestamp The start timestamp of the lesson
 *
 * @return String The response from the API
 */
function notify_lesson_cancelled_by_teacher($externalIDs, $start_timestamp)
{
    $lesson_date_string = date('d/m H:i', $start_timestamp);
    $content_en = "The teacher assigned to the lesson on " . $lesson_date_string . " has cancelled. A new teacher will be assigned.";
    $content_ar = "ألغى المعلم المعين للدرس في " . $lesson_date_string . ". سيتم تعيين معلم جديد.";
    $content_he = "המורה שהוקצה לשיעור ב-" . $lesson_date_string . " ביטל. מורה חדש יוקצה.";
    return send_notification($externalIDs, $content_en, $content_ar, $content_he);
}

/**
 * Notify about assignment of a new teacher
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 * @param int $start_timestamp The start timestamp of the lesson
 *
 * @return String The response from the API
 */
function notify_new_teacher_assigned($externalIDs, $start_timestamp)
{
    $lesson_date_string = date('d/m H:i', $start_timestamp);
    $content_en = "A teacher has been assigned to the lesson on " . $lesson_date_string . ".";
    $content_ar = "تم تعيين معلم للدرس في " . $lesson_date_string . ".";
    $content_he = "מורה הוקצה לשיעור ב-" . $lesson_date_string . ".";
    return send_notification($externalIDs, $content_en, $content_ar, $content_he);
}

/**
 * Notify teachers that a new scheduled lesson request is available
 *
 * @param array $externalIDs An array of external IDs of the users to notify
 * @param int $start_timestamp The start timestamp of the lesson
 *
 * @return String The response from the API
 */
?>
