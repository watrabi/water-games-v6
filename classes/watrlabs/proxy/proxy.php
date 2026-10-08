<?php

namespace watrlabs\proxy;

// the web proxy at /proxy. the work happens in the browser (Scramjet) and the node service in proxy/, which serves
// Scramjet's files and the Wisp websocket under /proxy/ on the same domain. PHP only signs who may connect.
//
// .env:
//   PROXY_SECRET   16+ characters, the same value in the node service's environment. empty = no proxy
class proxy {

    static function enabled(){
        return strlen($_ENV["PROXY_SECRET"] ?? "") >= 16;
    }

    // changes whenever the node packages do, so the browser can cache their files forever in between
    static function version(){
        $lock = __DIR__ . "/../../../proxy/package-lock.json";
        return is_file($lock) ? substr(md5_file($lock), 0, 10) : "dev";
    }

    // what /proxy needs to connect. the token is the same shape as realtime's: "<id>.<expires>.<hmac>"
    static function clientConfig(int $userId){
        $expires = time() + 12 * 3600;
        $signature = hash_hmac("sha256", $userId . "." . $expires, $_ENV["PROXY_SECRET"]);

        return [
            "wisp"=>"/proxy/wisp/$userId.$expires.$signature/",
            "version"=>self::version(),
        ];
    }
}
