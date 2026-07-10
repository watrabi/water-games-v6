<?php

namespace watrlabs\users;

class users {

    // returns user data straight from the database using userid
    public function getUserInfo($userId){

        global $db;

        return $db->table("users")->where("id", $userId)->first();
        
    }

    public function update(int $id, $value, $key){
        global $db;
        
        return $db->table("users")->where("id", $id)->update($value, $key);
    }
}