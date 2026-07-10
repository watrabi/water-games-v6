<?php

namespace watrlabs\authentication;

use watrlabs\users\users;
use watrlabs\encryption;
use watrlabs\authentication\security;
use watrlabs\authentication\sessions;

class registration {
    private static function hasSpecialCharacters($text){

        $isSpecial = preg_match('/^[a-zA-Z0-9]+$/',$text);

        return $isSpecial;
    }


    private static function isValidUsername($username){

        $users = new users();

        $usernameOkay = true;

        $usernameOkay = self::hasSpecialCharacters($username);
        //$usernameOkay = $users->getUserInfo($username);

        return $usernameOkay;
        
    }

    public static function createUser($username, $password, $email = null){

        global $db;

        $security = new security();
        $encryption = new encryption();
        $sessions = new sessions();

        $isValidUsername = self::isValidUsername($username);

        if(!$isValidUsername){
            return ["status"=>"error", "message"=>"This username is taken or contains special characters."];
        }

        $insert = [
            "accountid"=>$encryption->genRandString(30),
            "username"=>$username,
            "email"=>$email,
            "password"=>password_hash($password, PASSWORD_BCRYPT),
            "blurb"=>"My name is $username",
            "RegisterIP"=>$security::getRequestIp(),
            "LastIP"=>$security::getRequestIp(),
            "registered"=>time()
        ];

        $insertId = $db->table("users")->insert($insert);


        if($insertId){
            $sessionId = $sessions->createSession();
            $sessions->assignSession($sessionId);
            $sessions->assignUserIdToSession($sessionId, $insertId);
            return ["status"=>"okay", "message"=>"user created."];
        }

    }


}