<?php

namespace watrlabs\users;

class users {

    // returns user data straight from the database if a userid or username is provided
    public function getUserInfo($User, $Multiple = false){

        global $db;

        $query = $db->table("users");

        if(is_int($User)){
            $query = $query->where("id", $User);
        } else {
            $query = $query->where("username", $User);
        }

        if($query && $Multiple){
            return $query->get();
        } else {
            return $query->first();
        } 

        return null;
    }

    public function update(int $id, $value, $key){
        global $db;
        
        return $db->table("users")->where("id", $id)->update($value, $key);
    }
}