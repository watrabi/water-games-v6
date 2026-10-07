<?php

namespace watrlabs\authentication;

use watrlabs\users\users;
use watrlabs\encryption;
use watrlabs\authentication\security;
use watrlabs\authentication\sessions;
class registration {

    const USERNAME_MIN = 3;
    const USERNAME_MAX = 20;
    const PASSWORD_MIN = 8;

    private static function isAlphanumeric($text){
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $text);
    }

    // returns an error message, or null if the username is fine
    public static function validateUsername($username){

        $users = new users();

        $length = strlen($username);
        if($length < self::USERNAME_MIN || $length > self::USERNAME_MAX){
            return "Usernames have to be between " . self::USERNAME_MIN . " and " . self::USERNAME_MAX . " characters.";
        }

        if(!self::isAlphanumeric($username)){
            return "Usernames can only have letters, numbers and underscores.";
        }

        if($users->getUserByUsername($username)){
            return "That username is taken.";
        }

        return null;

    }

    public static function validatePassword($password){
        if(strlen($password) < self::PASSWORD_MIN){
            return "Passwords need to be at least " . self::PASSWORD_MIN . " characters.";
        }

        // bcrypt ignores anything past 72 bytes
        if(strlen($password) > 72){
            return "Passwords can't be longer than 72 characters.";
        }

        return null;
    }

    public static function createUser($username, $password, $email = null){

        global $db;

        $security = new security();
        $encryption = new encryption();
        $sessions = new sessions();

        $username = trim($username);
        $email = $email ? trim($email) : null;

        $usernameError = self::validateUsername($username);
        if($usernameError){
            http_response_code(400);
            return ["status"=>"error", "message"=>$usernameError];
        }

        $passwordError = self::validatePassword($password);
        if($passwordError){
            http_response_code(400);
            return ["status"=>"error", "message"=>$passwordError];
        }

        if($email){
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                return ["status"=>"error", "message"=>"Email is not valid."];
            }
        }

        $ip = $security::getRequestIp();

        if($security->hasTooManyAlts($ip)){
            http_response_code(429);
            return ["status"=>"error", "message"=>"Too many accounts on this IP Address."];
        }

        $insert = [
            "accountid"=>$encryption->genRandString(30),
            "username"=>$username,
            "email"=>$email,
            "password"=>password_hash($password, PASSWORD_BCRYPT),
            "blurb"=>"My name is $username",
            "RegisterIP"=>$security->encryptIp($ip),
            "LastIP"=>$security->encryptIp($ip),
            "registered"=>time()
        ];

        $insertId = $db->table("users")->insert($insert);


        if($insertId){
            $sessions->authenticateUser($insertId);
            return ["status"=>"okay", "message"=>"user created."];
        }

        http_response_code(500);
        return ["status"=>"error", "message"=>"Something went wrong creating your account."];

    }


}
