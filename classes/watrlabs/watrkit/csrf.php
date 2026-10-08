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

    // every POST/PUT/DELETE has to come from one of our own pages. SameSite=Lax still lets a sibling subdomain, a
    // sandboxed AI artifact (origin "null") or a top level form from another site through in some browsers, and
    // the api routes have no token. browsers say where a request came from in Sec-Fetch-Site (all current ones)
    // or Origin (older ones). neither header means it isn't a browser, which can't carry someone's cookie anyway
    static function sameOrigin(string $method, array $server): bool {
        if(in_array($method, ["GET", "HEAD", "OPTIONS"], true)){
            return true;
        }

        $site = strtolower($server["HTTP_SEC_FETCH_SITE"] ?? "");
        if($site !== ""){
            return $site === "same-origin" || $site === "none";
        }

        $origin = $server["HTTP_ORIGIN"] ?? "";
        if($origin === ""){
            return true;
        }

        $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? "");
        $port = parse_url($origin, PHP_URL_PORT);
        return $host !== "" && ($port ? "$host:$port" : $host) === strtolower($server["HTTP_HOST"] ?? "");
    }
}
