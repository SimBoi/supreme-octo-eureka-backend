<?php
    require_once '/var/www/html/supreme-octo-eureka-backend/utilities.php';

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
            CURLOPT_POSTFIELDS => json_encode($post_fields),
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
?>
