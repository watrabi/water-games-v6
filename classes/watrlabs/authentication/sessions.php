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
    
    // create session, and get its id returned.
    public function createSession(){

        global $db;
        $encryption = new encryption();
        
        $token = $encryption->genRandString(100);
        $insert = [
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

    // assigns someone a session
    public function assignSession($sessionId = null){

        if(!$sessionId){
            $sessionId = $this->createSession(); 
        }

        setcookie($_ENV["COOKIE_NAME"], $sessionId, $this->cookieTime, "/", "." . $_ENV["APP_DOMAIN"]); // jank but ok
    }

    // destroys a session
    public function destroySession($sessionId){
        global $db;
        
        setcookie($_ENV["COOKIE_NAME"], $sessionId, -$this->cookieTime, "/", "." . $_ENV["APP_DOMAIN"]); // jank but ok
        $db->table("sessions")->where("session", $sessionId)->delete();

    }

    // get session info, all of it
    public function getSessionInfo($sessionId){
        global $db;

        return $db->table("sessions")->where("session", $sessionId)->first();
    }

    // expands the lifespan of a session
    private function extendLease($sessionId){
        global $db;

        $sessionInfo = $this->getSessionInfo($sessionId);
    
        if($sessionInfo){

            // updates the session if its past 24 hours
            // might make it reroll the actual session id in the future
            if($sessionInfo->expiration < time() - 86400){
                $this->assignSession($sessionId);
                
                $update = [
                    "expiration"=>$this->cookieTime
                ];

                $db->table("sessions")->where("session", $sessionId)->update($update);
            }            
        }
        
        return false;

    }

    // checks if a session is still valid, if so extend it.
    public function checkSession($sessionId){
        if(isset($_COOKIE[$_ENV["COOKIE_NAME"]])){
            $session = $_COOKIE[$_ENV["COOKIE_NAME"]]; // this is stupid and there's a better way of doing this but im fat and lazy

            $sessionInfo = $this->getSessionInfo($session);

            if($sessionInfo){
                $this->assignSession($sessionId);
            }

            $this->extendLease();
        }
    }

    // checks if session is linked to user, if so returns their id
    public function isUser($session){
        global $db;
        $session = $db->table("sessions")->where("session", $session)->first();

        return $session->userid;
    }

    public function getUserInfoFromCookie(){

        $users = new users();

        if(isset($_COOKIE[$_ENV["COOKIE_NAME"]])){
            $sessionId = $_COOKIE[$_ENV["COOKIE_NAME"]];

            $sessionInfo = $this->getSessionInfo($sessionId);

            if($sessionInfo){
                if($sessionInfo->userid){
                    return $users->getUserInfo($sessionInfo->userid);
                }
            }

        }

        return false;

    }

}