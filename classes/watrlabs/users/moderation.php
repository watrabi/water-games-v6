<?php

namespace watrlabs\users;

use watrlabs\social\notifications;

// timed (or forever) bans and mutes, with a reason the person gets to see.
// banned: signed out and can't sign in. muted: can still play, but can't chat, comment, react, or make things
// other people see. both lift themselves once their time is up
class moderation {

    // what the admin panel offers. 0 = forever
    const DURATIONS = [
        3600=>"1 hour",
        86400=>"1 day",
        259200=>"3 days",
        604800=>"7 days",
        2592000=>"30 days",
        0=>"Forever",
    ];

    const REASON_MAX = 255;

    // true while the ban is in force. an expired timed ban is lifted on the spot
    static function isBanned($user, ?int $now = null): bool {
        if(!$user || empty($user->banned)){
            return false;
        }

        $until = $user->banned_until ?? null;
        if($until !== null && (int) $until <= ($now ?? time())){
            self::lift((int) $user->id, "ban");
            $user->banned = 0;
            $user->banned_until = null;
            return false;
        }

        return true;
    }

    static function isMuted($user, ?int $now = null): bool {
        return $user && !empty($user->muted_until) && (int) $user->muted_until > ($now ?? time());
    }

    // "until Oct 9, 4:00pm" or "for good"
    static function untilText($until): string {
        return $until ? "until " . date("M j, g:ia", (int) $until) . " UTC" : "for good";
    }

    static function banMessage($user): string {
        $text = "This account is banned " . self::untilText($user->banned_until ?? null) . ".";
        if(!empty($user->ban_reason)){
            $text .= " Reason: " . $user->ban_reason;
        }
        return $text;
    }

    static function muteMessage($user): string {
        $text = "You're muted " . self::untilText($user->muted_until ?? null) . ", so you can't post or chat right now.";
        if(!empty($user->mute_reason)){
            $text .= " Reason: " . $user->mute_reason;
        }
        return $text;
    }

    // for anything that posts: throws the mute message, which routes already show as an error
    static function requireUnmuted($user){
        if(self::isMuted($user)){
            throw new \InvalidArgumentException(self::muteMessage($user));
        }
    }

    // same check by id, for classes that only get an id
    static function requireUnmutedId(int $userId){
        global $db;

        $user = $db->table("users")->select(["id", "muted_until", "mute_reason"])->where("id", $userId)->first();
        self::requireUnmuted($user);
    }

    private static function cleanReason(?string $reason){
        $reason = trim(preg_replace('/\s+/', ' ', (string) $reason));
        return $reason === "" ? null : mb_substr($reason, 0, self::REASON_MAX);
    }

    // $seconds 0 = forever
    static function ban(int $userId, int $seconds, ?string $reason, ?int $byId){
        global $db;

        $until = $seconds > 0 ? time() + $seconds : null;
        $reason = self::cleanReason($reason);

        $db->table("users")->where("id", $userId)->update(["banned"=>1, "banned_until"=>$until, "ban_reason"=>$reason]);
        $db->table("sessions")->where("userid", $userId)->delete();
        self::log($userId, "ban", $reason, $until, $byId);
    }

    // a mute is always timed, the longest is 30 days (forever is what bans are for)
    static function mute(int $userId, int $seconds, ?string $reason, ?int $byId){
        global $db;

        $seconds = $seconds > 0 ? $seconds : 2592000;
        $until = time() + $seconds;
        $reason = self::cleanReason($reason);

        $db->table("users")->where("id", $userId)->update(["muted_until"=>$until, "mute_reason"=>$reason]);
        self::log($userId, "mute", $reason, $until, $byId);
        notifications::send($userId, "muted", null, ["until"=>$until, "reason"=>$reason]);
    }

    static function unban(int $userId, ?int $byId){
        self::lift($userId, "ban", $byId);
    }

    static function unmute(int $userId, ?int $byId){
        self::lift($userId, "mute", $byId);
    }

    // $byId null = it ran out on its own
    private static function lift(int $userId, string $what, ?int $byId = null){
        global $db;

        if($what === "ban"){
            $db->table("users")->where("id", $userId)->update(["banned"=>0, "banned_until"=>null, "ban_reason"=>null]);
        } else {
            $db->table("users")->where("id", $userId)->update(["muted_until"=>null, "mute_reason"=>null]);
        }
        self::log($userId, "un" . $what, $byId ? null : "Time ran out", null, $byId);
    }

    private static function log(int $userId, string $action, ?string $reason, ?int $until, ?int $byId){
        global $db;

        $db->table("moderation_log")->insert([
            "userid"=>$userId,
            "action"=>$action,
            "reason"=>$reason,
            "until"=>$until,
            "by_id"=>$byId,
            "created"=>time(),
        ]);
    }

    // for the cron: everything whose time is up. returns how many
    static function liftExpired(?int $now = null): int {
        global $db;

        $now = $now ?? time();
        $count = 0;
        foreach($db->table("users")->select(["id"])->where("banned", 1)->whereNotNull("banned_until")->where("banned_until", "<=", $now)->get() as $row){
            self::lift((int) $row->id, "ban");
            $count++;
        }
        foreach($db->table("users")->select(["id"])->whereNotNull("muted_until")->where("muted_until", "<=", $now)->get() as $row){
            self::lift((int) $row->id, "mute");
            $count++;
        }
        return $count;
    }

    static function history(int $userId, int $limit = 30){
        global $db;

        return $db->query(
            "SELECT m.*, u.username AS by_name FROM moderation_log m LEFT JOIN users u ON u.id = m.by_id
             WHERE m.userid = ? ORDER BY m.id DESC LIMIT " . max(1, $limit),
            [$userId]
        )->get();
    }
}
