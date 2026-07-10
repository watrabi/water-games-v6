<?php

namespace watrlabs\authentication;

use watrlabs\encryption;

class security {

    // get the last login a user used
    public function getLastLoginIp($username, $encrypted = true){
        global $db;

        $user = $db->table("users")->where("username", $username)->first();
        return $user->LastIP;
        
    }

    // function to detect alts based on a single username
    public function detectAlts(string $ip) {
        global $db;

        $encryption = new encryption();

        $registerAlts = $db->table("users")->where("RegisterIP", $encryption->encrypt($ip))->get();
        $loginAlts = $db->table("users")->where("LastIP", $encryption->encrypt($ip))->get();

        $combinedAlts = array_merge($registerAlts, $loginAlts );
        $combinedAlts = array_unique($combinedAlts);

        return $combinedAlts;

    }

    // returns false if more than 5 alts are found
    public function hasTooManyAlts($ip){
        $alts = $this->detectAlts($ip);

        return count($alts) > 5;

    }

    // Source - https://stackoverflow.com/a/55790
    // Posted by Tim Kennedy, modified by community. See post 'Timeline' for change history
    // Retrieved 2026-07-09, License - CC BY-SA 4.0

    static function getRequestIp(){
        if (!empty($_SERVER['CF-Connecting-IP'])) {
            return $_SERVER['CF-Connecting-IP'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }


}