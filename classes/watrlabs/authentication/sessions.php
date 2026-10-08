<?php

namespace watrlabs\authentication;

use watrlabs\encryption;
use watrlabs\users\users;

class sessions {

    private $cookieTime = null;

    function __construct() {
        // doing it like this so I can easily change it or make it read from db
        $this->cookieTime = time() + 2629743; // about a month
    }

    public function authenticateUser($userId){
        $sessionId = $this->createSession($userId);
        $this->assignSession($sessionId);

        return $sessionId;
    }

    // create session, and get its id returned.
    // userid goes in with the insert since the column isn't nullable
    public function createSession($userId){

        global $db;
        $encryption = new encryption();

        $token = $encryption->genRandString(100);
        $insert = [
            "userid"=>$userId,
            "session"=>$token,
            "expiration"=>$this->cookieTime,
            "created"=>time(),
            "last_used"=>time(),
            "user_agent"=>mb_substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255) ?: null,
        ];

        $db->table("sessions")->insert($insert);

        return $token;

    }

    // assigns user id to session
    public function assignUserIdToSession($sessionId, $userId){
        global $db;

        $sessionInfo = $this->getSessionInfo($sessionId);

        if($sessionInfo){
            $update = [
                "userid"=>$userId
            ];

            $db->table("sessions")->where("session", $sessionId)->update($update);

            return true;

        }

        return false;
    }

    // sets the session cookie
    public function assignSession($sessionId){
        $this->setCookie($sessionId, $this->cookieTime);
    }

    private function setCookie($value, $expires){
        $options = [
            "expires"=>$expires,
            "path"=>"/",
            "secure"=>!empty($_SERVER["HTTPS"]) || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https",
            "httponly"=>true,
            "samesite"=>"Lax",
        ];

        // browsers drop cookies for ".localhost" or an ip, so those stay host-only
        $domain = preg_replace('/:\d+$/', '', $_ENV["APP_DOMAIN"] ?? "");
        if($domain !== "" && $domain !== "localhost" && !filter_var($domain, FILTER_VALIDATE_IP)){
            $options["domain"] = "." . $domain; // jank but ok
        }

        setcookie($_ENV["COOKIE_NAME"], $value, $options);
    }

    // destroys a session
    public function destroySession($sessionId){
        global $db;

        $this->setCookie("", time() - 3600);
        $db->table("sessions")->where("session", $sessionId)->delete();

    }

    // signs out every session a user has
    public function destroyAllSessions($userId){
        global $db;

        $this->setCookie("", time() - 3600);
        $db->table("sessions")->where("userid", $userId)->delete();
    }

    // every device someone's signed in on, newest first. never hands out the session tokens themselves
    public function listFor(int $userId){
        global $db;

        $current = $this->getCurrentSessionId();

        return array_map(fn($row) => [
            "id"=>(int) $row->id,
            "device"=>self::describeAgent($row->user_agent ?? ""),
            "created"=>$row->created ? (int) $row->created : null,
            "lastUsed"=>$row->last_used ? (int) $row->last_used : null,
            "current"=>$current !== null && hash_equals((string) $row->session, (string) $current),
        ], $db->table("sessions")->where("userid", $userId)->where("expiration", ">", time())->orderBy("last_used", "DESC")->get());
    }

    // signs out one of your own sessions
    public function revoke(int $userId, int $id){
        global $db;

        return $db->table("sessions")->where("userid", $userId)->where("id", $id)->delete()->rowCount() > 0;
    }

    // everything except the one you're using
    public function revokeOthers(int $userId){
        global $db;

        $current = $this->getCurrentSessionId();
        $query = $db->table("sessions")->where("userid", $userId);
        if($current){
            $query->where("session", "!=", $current);
        }
        $query->delete();
    }

    // "Firefox on Windows". rough on purpose, it only has to jog someone's memory
    static function describeAgent(string $agent){
        if($agent === ""){
            return "Unknown device";
        }

        $browser = "Browser";
        foreach(["Edg/"=>"Edge", "OPR/"=>"Opera", "SamsungBrowser"=>"Samsung Internet", "Firefox/"=>"Firefox", "CriOS"=>"Chrome", "Chrome/"=>"Chrome", "Safari/"=>"Safari"] as $needle => $name){
            if(str_contains($agent, $needle)){
                $browser = $name;
                break;
            }
        }

        $os = null;
        foreach(["CrOS"=>"ChromeOS", "Android"=>"Android", "iPhone"=>"iPhone", "iPad"=>"iPad", "Windows"=>"Windows", "Mac OS X"=>"Mac", "Linux"=>"Linux"] as $needle => $name){
            if(str_contains($agent, $needle)){
                $os = $name;
                break;
            }
        }

        return $os ? "$browser on $os" : $browser;
    }

    public function getCurrentSessionId(){
        return $_COOKIE[$_ENV["COOKIE_NAME"]] ?? null;
    }

    // get session info, all of it
    public function getSessionInfo($sessionId){
        global $db;

        return $db->table("sessions")->where("session", $sessionId)->first();
    }

    // expands the lifespan of a session
    // only touches the db + cookie if it hasn't been refreshed in the last day
    private function extendLease($sessionInfo){
        global $db;

        // for the "where you're signed in" list. a few minutes off is fine
        if((int) ($sessionInfo->last_used ?? 0) < time() - 300){
            $db->table("sessions")->where("id", $sessionInfo->id)->update(["last_used"=>time()]);
        }

        if($sessionInfo->expiration < $this->cookieTime - 86400){
            $this->assignSession($sessionInfo->session);

            $db->table("sessions")->where("session", $sessionInfo->session)->update([
                "expiration"=>$this->cookieTime
            ]);
        }
    }

    // checks if session is linked to user, if so returns their id
    public function isUser($session){
        $session = $this->getSessionInfo($session);

        return $session ? $session->userid : null;
    }

    public function getUserInfoFromCookie(){

        $users = new users();
        $sessionId = $this->getCurrentSessionId();

        if($sessionId){
            $sessionInfo = $this->getSessionInfo($sessionId);

            if($sessionInfo && $sessionInfo->userid){

                if($sessionInfo->expiration && $sessionInfo->expiration < time()){
                    $this->destroySession($sessionId);
                    return false;
                }

                $this->extendLease($sessionInfo);

                $user = $users->getUserInfo($sessionInfo->userid);

                // banned accounts get signed out
                if(!$user || !empty($user->banned)){
                    $this->destroySession($sessionId);
                    return false;
                }

                return $user;
            }

        }

        return false;

    }

}
