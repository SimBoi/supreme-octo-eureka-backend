<?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/supreme-octo-eureka-backend/utilities.php';


    /**
     * Sends a verification code to the user's phone number
     *
     * @param DatabaseName The database in which the profile is stored, either 'Customers' or 'Teachers'
     * @param Phone The user's phone number
     * @param Language The language of the message
     *
     * @return JSON Object with the result of the operation, additional information will be returned based on the result of the operation
     * @return Result=SUCCESS,Cooldown,ExpiresIn in case of success
     * @return Result=COOLDOWN,Cooldown in case of failure to send the code due to cooldown
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     */
    function request_verification_code($conn, $database_name, $phone, $language)
    {
        global $verification_code_length, $verification_code_cooldown, $verification_code_expiration;
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');

        // Generate a random code
        $verification_code = rand(pow(10, $verification_code_length - 1), pow(10, $verification_code_length) - 1);

        // Check if the phone number is in the database
        $sql = "SELECT VerificationTimeStamp FROM ".$database_name." WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                // throttle the verification code requests
                $time_since_last_msg = strtotime("now") - $row['VerificationTimeStamp'];
                if ($time_since_last_msg < $verification_code_cooldown) {
                    $cooldown = $verification_code_cooldown - $time_since_last_msg;
                    die('{"Result": "COOLDOWN", "Cooldown": '.$cooldown.'}');
                }

                _send_whatsapp_verification_code($phone, $verification_code, $language);

                // Update the verification code and the timestamp in the database
                $sql = "UPDATE ".$database_name." SET VerificationCode='".password_hash($verification_code, PASSWORD_DEFAULT)."', VerificationTimeStamp=".strtotime("now")." WHERE Phone='".$phone."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

                // return success and the cooldown time until a new code can be requested and the time until the code expires
                $output = array(
                    'Result' => 'SUCCESS',
                    'Cooldown' => $verification_code_cooldown,
                    'ExpiresIn' => $verification_code_expiration
                );
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        return json_encode($output);
    }

    /**
     * Verifies the user's phone number, if successful, a password will be generated and given to the user
     *
     * @param DatabaseName The database in which the profile is stored, either 'Customers' or 'Teachers'
     * @param Phone The user's phone number
     * @param VerificationCode The verification code input by the user
     *
     * @return JSON Object with the result of the operation
     * @return Result=SUCCESS,GeneratedPassword in case of success, the generated password will be returned
     * @return Result=CODE_EXPIRED in case the code is expired
     * @return Result=WRONG_CODE in case the code is incorrect
     * @return Result=PHONE_DOESNT_EXIST in case the phone number is not in the database
     * @return Result=ERROR in case of failure
     */
    function verify_phone($conn, $database_name, $phone, $verification_code)
    {
        global $verification_code_expiration;
        $output = array('Result' => 'None');

        // Check if the phone number is 12 characters long, if not, end the script
        if (strlen($phone) != 12) die('{"Result": "ERROR"}');

        // Get the hashed verification code from the database and check if it matches the input
        $sql = "SELECT VerificationTimeStamp, VerificationCode FROM ".$database_name." WHERE Phone = '".$phone."'";
        $result = mysqli_query($conn ,$sql);
        if(mysqli_num_rows($result) > 0) {
            while($row = mysqli_fetch_assoc($result)) {
                // check if the code is expired
                $time_since_last_msg = strtotime("now") - $row['VerificationTimeStamp'];
                if ($time_since_last_msg > $verification_code_expiration) die('{"Result": "CODE_EXPIRED"}');

                if (!password_verify($verification_code, $row['VerificationCode'])) die('{"Result": "WRONG_CODE"}');

                $generated_password = bin2hex(random_bytes(8));

                // reset the message timestamp and save the generated password in the database
                $sql = "UPDATE ".$database_name." SET VerificationTimeStamp=0, Password='".password_hash($generated_password, PASSWORD_DEFAULT)."' WHERE Phone='".$phone."'";
                if (!mysqli_query($conn ,$sql)) die('{"Result": "ERROR"}');

                $output = array(
                    'Result' => 'SUCCESS',
                    'GeneratedPassword' => $generated_password
                );
            }
        } else {
            die('{"Result": "PHONE_DOESNT_EXIST"}');
        }

        return json_encode($output);
    }

    /**
     * Sends a WhatsApp message with a verification code
     *
     * @param Phone The phone number to send the message to
     * @param Code The verification code to send
     * @param Language The language of the message
     *
     * @return String The response from the API
     */
    function _send_whatsapp_verification_code($phone, $code, $language) {
        return _send_whatsapp_template('{
            "messaging_product": "whatsapp",
            "to": "'.$phone.'",
            "type": "template",
            "template": {
                "name": "verify_phone",
                "language": {
                    "code": "'.$language.'"
                },
                "components": [
                    {
                        "type": "body",
                        "parameters": [
                            {
                                "type": "text",
                                "text": "'.$code.'"
                            }
                        ]
                    },
                    {
                        "type": "button",
                        "sub_type": "url",
                        "index": "0",
                        "parameters": [
                            {
                                "type": "text",
                                "text": "'.$code.'"
                            }
                        ]
                    }
                ]
            }
        }');
    }

    /**
     * Sends a WhatsApp message with a template
     *
     * @param PostFields The fields to send in the POST request
     *
     * @return String The response from the API
     */
    function _send_whatsapp_template($post_fields) {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://graph.facebook.com/v18.0/161868910346814/messages',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>$post_fields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            'Authorization: Bearer EAAO4eN0z2rcBO67WXvplAGmumZA3PWwdiMMOBlbPFtSP37eDewNfgzsYLbec48MT5QrTahCQM7GDqiFDpikMYujot3nDUBs9RDj45TtBjZBVZAowOya9UKDZByM0CY62yOfx1nybNUsbWLNuSqa81WZAoSTOjtB3a0SWgCbPxrDpeiLMZB2Ukg8vzSdlGzp4edy0aervQAZA4JWYQIbwCSCtGJsGWb9IZBvzlmAZD'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;
    }
?>
