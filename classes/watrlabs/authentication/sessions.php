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
        setcookie($_ENV["COOKIE_NAME"], $value, [
            "expires"=>$expires,
            "path"=>"/",
            "domain"=>"." . $_ENV["APP_DOMAIN"], // jank but ok
            "secure"=>!empty($_SERVER["HTTPS"]) || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https",
            "httponly"=>true,
            "samesite"=>"Lax",
        ]);
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
