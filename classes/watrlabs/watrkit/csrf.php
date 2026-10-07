<?php

namespace watrlabs\watrkit;

// a per-session token for admin forms. SameSite cookies already stop most cross site posts,
// this is the belt to go with those braces
class csrf {

    static function token(){
        $session = $_COOKIE[$_ENV["COOKIE_NAME"] ?? ""] ?? "";
        $secret = ($_ENV["encryptionKey"] ?? "") . ($_ENV["APP_NAME"] ?? "") . "csrf";
        return hash_hmac("sha256", $session, $secret);
    }

    static function valid($token){
        return is_string($token) && hash_equals(self::token(), $token);
    }
}
