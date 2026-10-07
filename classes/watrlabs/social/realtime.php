<?php

namespace watrlabs\social;

// talks to the node websocket server in realtime/. everything still works without it (the chat tray
// falls back to polling), so a failed publish is never an error
//
// .env:
//   REALTIME_URL           what browsers connect to, e.g. wss://watr.lol/ws
//   REALTIME_INTERNAL_URL  where PHP reaches node, default http://127.0.0.1:3001
//   REALTIME_SECRET        shared with node, 16+ characters
class realtime {

    private static ?array $online = null;

    static function enabled(){
        return !empty($_ENV["REALTIME_URL"]) && strlen($_ENV["REALTIME_SECRET"] ?? "") >= 16;
    }

    private static function internal(){
        return rtrim($_ENV["REALTIME_INTERNAL_URL"] ?? "http://127.0.0.1:3001", "/");
    }

    // what the chat tray needs to connect, or null when realtime isn't set up
    static function clientConfig(int $userId){
        if(!self::enabled()){
            return null;
        }

        $expires = time() + 12 * 3600;
        $signature = hash_hmac("sha256", $userId . "." . $expires, $_ENV["REALTIME_SECRET"]);

        return ["url"=>$_ENV["REALTIME_URL"], "token"=>"$userId.$expires.$signature"];
    }

    // pushes an event to every open tab of these users. quick timeout, failures are ignored
    static function publish(array $userIds, array $event){
        if(!self::enabled()){
            return;
        }

        $ch = curl_init(self::internal() . "/publish");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(["to"=>array_values(array_unique(array_map("intval", $userIds))), "event"=>$event]),
            CURLOPT_HTTPHEADER=>["Content-Type: application/json", "X-Realtime-Secret: " . $_ENV["REALTIME_SECRET"]],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT_MS=>300,
            CURLOPT_TIMEOUT_MS=>800,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // user ids with a socket open right now (asked once per request)
    static function onlineIds(){
        if(self::$online !== null){
            return self::$online;
        }

        self::$online = [];
        if(!self::enabled()){
            return self::$online;
        }

        $ch = curl_init(self::internal() . "/online");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER=>["X-Realtime-Secret: " . $_ENV["REALTIME_SECRET"]],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT_MS=>300,
            CURLOPT_TIMEOUT_MS=>600,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $ids = $body ? (json_decode($body, true)["online"] ?? []) : [];
        return self::$online = array_map("intval", is_array($ids) ? $ids : []);
    }
}
