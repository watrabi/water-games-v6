<?php

namespace watrlabs\authentication;

use watrlabs\watrkit\mail;

// "forgot password". only works for accounts with an email on them. the link carries a random token,
// only its hash is stored, and it works once within an hour
class passwordreset {

    const LIFETIME = 3600;
    const PER_HOUR = 3;

    // by username or email. says nothing about whether the account exists (the route always answers the same)
    public function start(string $who){
        global $db;

        $who = trim($who);
        if($who === ""){
            return;
        }

        $user = str_contains($who, "@")
            ? $db->table("users")->where("email", $who)->first()
            : $db->table("users")->where("username", $who)->first();

        if(!$user || empty($user->email) || !empty($user->banned)){
            return;
        }

        if($db->table("password_resets")->where("userid", $user->id)->where("created", ">", time() - 3600)->count() >= self::PER_HOUR){
            return;
        }

        $token = bin2hex(random_bytes(32));
        $db->table("password_resets")->insert([
            "userid"=>$user->id,
            "token_hash"=>hash("sha256", $token),
            "created"=>time(),
            "expires"=>time() + self::LIFETIME,
        ]);

        $link = mail::siteUrl() . "/auth/reset?token=" . $token;
        $name = $_ENV["APP_NAME"] ?? "Water Games";

        mail::send($user->email, "Reset your $name password",
            "Hi $user->username,\n\n" .
            "Someone (hopefully you) asked to reset your $name password. This link works for an hour:\n\n" .
            "$link\n\n" .
            "If that wasn't you, you can ignore this email. Your password won't change.\n"
        );
    }

    // the reset row for a token if it's still good
    public function find(string $token){
        global $db;

        if(!preg_match('/^[a-f0-9]{64}$/', $token)){
            return null;
        }

        $row = $db->table("password_resets")->where("token_hash", hash("sha256", $token))->first();
        if(!$row || $row->used || $row->expires < time()){
            return null;
        }
        return $row;
    }

    // sets the new password and signs every device out. throws with a message for the person
    public function finish(string $token, string $password){
        global $db;

        $row = $this->find($token);
        if(!$row){
            throw new \InvalidArgumentException("That link has expired or was already used. Ask for a new one.");
        }

        $error = registration::validatePassword($password);
        if($error){
            throw new \InvalidArgumentException($error);
        }

        $db->table("users")->where("id", $row->userid)->update(["password"=>password_hash($password, PASSWORD_BCRYPT)]);
        $db->table("password_resets")->where("userid", $row->userid)->update(["used"=>1]);
        $db->table("sessions")->where("userid", $row->userid)->delete();

        return (int) $row->userid;
    }
}
