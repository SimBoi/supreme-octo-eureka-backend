<?php
    require_once '/var/www/html/supreme-octo-eureka-backend/utilities.php';


    /**
     * Creates a meeting on Zoom
     *
     * @return String The url of the created meeting
     */
    function create_meeting()
    {
        return "https://p2p.mirotalk.com/join/" . _generate_random_string(32);
    }

    function _generate_random_string($length)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz-';

        $charactersLength = strlen($characters);

        $randomString = '';

        for ($i = 0; $i < $length; $i++) {

            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }
?>
