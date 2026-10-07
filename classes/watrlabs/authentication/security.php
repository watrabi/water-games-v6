<?php

namespace watrlabs\authentication;

use watrlabs\encryption;

class security {

    // get the last login a user used
    public function getLastLoginIp($username, $encrypted = true){
        global $db;

        $user = $db->table("users")->where("username", $username)->first();

        if(!$user){
            return null;
        }

        if($encrypted){
            return $user->LastIP;
        }

        $encryption = new encryption();
        return $encryption->decrypt($user->LastIP);

    }

    // IPs get stored encrypted, this is what goes in the db
    public function encryptIp(string $ip){
        $encryption = new encryption();
        return $encryption->encrypt($ip);
    }

    // function to detect alts based on a single ip
    public function detectAlts(string $ip) {
        global $db;

        $encryptedIp = $this->encryptIp($ip);

        $alts = $db->table("users")
            ->select(["id", "username"])
            ->where("RegisterIP", $encryptedIp)
            ->orWhere("LastIP", $encryptedIp)
            ->get();

        // dedupe by id since someone can match both columns
        $unique = [];
        foreach($alts as $alt){
            $unique[$alt->id] = $alt;
        }

        return array_values($unique);

    }

    // returns true if more than 5 alts are found
    public function hasTooManyAlts($ip){
        $alts = $this->detectAlts($ip);

        return count($alts) > 5;

    }

    // Source - https://stackoverflow.com/a/55790
    // Posted by Tim Kennedy, modified by community. See post 'Timeline' for change history
    // Retrieved 2026-07-09, License - CC BY-SA 4.0

    static function getRequestIp(){
        // php exposes request headers as HTTP_*, so CF-Connecting-IP lands here
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    // captcha only runs when it's turned on AND both turnstile keys are filled in
    static function captchaActive(){
        return filter_var($_ENV["CONFIG_CaptchaEnabled"] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !empty($_ENV["TurnstileSiteKey"])
            && !empty($_ENV["PrivateTurnstileKey"]);
    }

    // checks a cloudflare turnstile token, skipped if captcha isn't active
    static function verifyCaptcha($token){
        if(!self::captchaActive()){
            return true;
        }

        if(!$token){
            return false;
        }

        $context = stream_context_create([
            "http"=>[
                "method"=>"POST",
                "header"=>"Content-Type: application/x-www-form-urlencoded",
                "content"=>http_build_query([
                    "secret"=>$_ENV["PrivateTurnstileKey"],
                    "response"=>$token,
                    "remoteip"=>self::getRequestIp(),
                ]),
                "timeout"=>5,
            ]
        ]);

        $response = @file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, $context);

        if($response === false){
            return false;
        }

        $result = json_decode($response, true);

        return !empty($result["success"]);
    }


}
