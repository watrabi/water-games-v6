<?php

namespace watrlabs\watrkit;

use watrlabs\authentication\security;

// fixed window counters in the database, so they hold across php workers and restarts.
//
//   ratelimit::hit("login", ratelimit::ip(), 10, 900)    true while under 10 per 15 minutes
//   ratelimit::guard("vote", $user, 60, 60)              same, but answers 429 and stops the request when over
//
// keys are "action:ip<hash>" or "action:u<id>". the address itself is never stored
class ratelimit {

    // who's asking: "u12" for an account, a keyed hash of the address for guests
    static function who($user = null): string {
        if($user && !empty($user->id)){
            return "u" . (int) $user->id;
        }
        return self::ip();
    }

    static function ip(): string {
        $ip = security::getRequestIp() ?: "unknown";
        return "ip" . substr(hash_hmac("sha256", $ip, ($_ENV["encryptionKey"] ?? "") . "|limits"), 0, 32);
    }

    // counts one and says whether that's still allowed
    static function hit(string $action, string $who, int $max, int $window, ?int $now = null): bool {
        global $db;

        $now = $now ?? time();
        $bucket = substr($action . ":" . $who, 0, 120);

        // a passed window starts again at 1, otherwise add one
        $db->statement(
            "INSERT INTO rate_limits (bucket, hits, reset) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = IF(reset <= ?, 1, hits + 1), reset = IF(reset <= ?, VALUES(reset), reset)",
            [$bucket, $now + $window, $now, $now]
        );

        $row = $db->table("rate_limits")->where("bucket", $bucket)->first();
        return $row && (int) $row->hits <= $max;
    }

    // how many hits are in the current window, without counting one
    static function count(string $action, string $who, ?int $now = null): int {
        global $db;

        $row = $db->table("rate_limits")->where("bucket", substr($action . ":" . $who, 0, 120))->first();
        if(!$row || (int) $row->reset <= ($now ?? time())){
            return 0;
        }
        return (int) $row->hits;
    }

    // seconds until the window resets
    static function retryAfter(string $action, string $who): int {
        global $db;

        $row = $db->table("rate_limits")->where("bucket", substr($action . ":" . $who, 0, 120))->first();
        return $row ? max(1, (int) $row->reset - time()) : 1;
    }

    static function clear(string $action, string $who){
        global $db;

        $db->table("rate_limits")->where("bucket", substr($action . ":" . $who, 0, 120))->delete();
    }

    // for api routes: over the limit answers 429 with a json error and ends the request
    static function guard(string $action, $userOrWho, int $max, int $window, string $message = "Slow down a little and try again in a bit."){
        $who = is_string($userOrWho) ? $userOrWho : self::who($userOrWho);

        if(self::hit($action, $who, $max, $window)){
            return;
        }

        $wait = self::retryAfter($action, $who);
        http_response_code(429);
        header("Retry-After: " . $wait);
        header("Content-Type: application/json");
        exit(json_encode(["status"=>"error", "message"=>$message, "retryAfter"=>$wait]));
    }

    // old windows, from the cron (or now and then from a request)
    static function prune(){
        global $db;

        $db->table("rate_limits")->where("reset", "<", time() - 3600)->delete();
    }
}
