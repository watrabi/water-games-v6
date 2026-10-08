<?php

namespace watrlabs\users;

class users {

    // returns user data straight from the database using userid
    public function getUserInfo($userId){

        global $db;

        return $db->table("users")->where("id", $userId)->first();

    }

    public function getUserByUsername($username){
        global $db;

        return $db->table("users")->where("username", $username)->first();
    }

    // the stuff that's safe to show other people
    public function getPublicProfile($username){
        global $db;

        return $db->table("users")
            ->select(["id", "username", "blurb", "registered", "avatar", "xp", "level", "streak", "streak_best", "streak_day"])
            ->where("username", $username)
            ->first();
    }

    public function update(int $id, array $values){
        global $db;

        return $db->table("users")->where("id", $id)->update($values);
    }
}
