<?php

namespace app\models;

class user {
    
    public $id;
    public $username;
    public $email;
    public $robux;
    public $tix;

    public function __construct($identifier = null) {

        global $db;

        if($identifier){

            if(is_int($identifier)){
                $userInfo = $db->table("users")->where("id", $identifier)->first();

                if($userInfo){
                    $this->id = $userInfo->id;
                    $this->username = $userInfo->username;
                    $this->email = $userInfo->email;
                    $this->robux = $userInfo->robux;
                    $this->tix = $userInfo->tix;
                }
                
            }

        }
    }

    private function getUserByUsername(string $username){

    }

    private function getUserById(int $id){
        
    }
}