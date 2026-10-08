<?php

namespace watrlabs\social;

// badges on profiles. each one is checked when the thing it's about happens, never on a timer
class achievements {

    // code => [name, what you did, phosphor icon]
    const ALL = [
        "first_play"=>["First play", "Played a game for a minute", "ph-play"],
        "hour"=>["Settling in", "An hour of playtime", "ph-clock"],
        "ten_hours"=>["Regular", "Ten hours of playtime", "ph-clock-countdown"],
        "hundred_hours"=>["Lives here", "A hundred hours of playtime", "ph-house-line"],
        "explorer"=>["Explorer", "Played 10 different games", "ph-compass"],
        "collector"=>["Collector", "Favorited 10 games", "ph-heart"],
        "first_comment"=>["Critic", "Left a comment", "ph-chat-text"],
        "first_friend"=>["Not alone", "Made a friend", "ph-handshake"],
        "popular"=>["Popular", "Have 10 friends", "ph-users-three"],
        "dj"=>["DJ", "Made a playlist", "ph-playlist"],
        "requester"=>["Good taste", "Asked for a game that got added", "ph-lightbulb"],
        "secure"=>["Locked down", "Turned on two-factor sign in", "ph-shield-check"],
        "streak_7"=>["On a roll", "Played 7 days in a row", "ph-fire"],
        "streak_30"=>["Can't stop", "Played 30 days in a row", "ph-fire-simple"],
        "top_score"=>["Top of the board", "Number one on a leaderboard", "ph-crown"],
    ];

    // awards a code once. returns true if it was new
    static function award(int $userId, string $code){
        global $db;

        if(!isset(self::ALL[$code])){
            return false;
        }

        [$inserted] = $db->statement("INSERT IGNORE INTO user_achievements (userid, code, created) VALUES (?, ?, ?)", [$userId, $code, time()]);
        if(!$inserted->rowCount()){
            return false;
        }

        $user = $db->table("users")->select(["username"])->where("id", $userId)->first();
        notifications::send($userId, "achievement", null, ["code"=>$code, "name"=>self::ALL[$code][0], "username"=>$user->username ?? ""]);
        activity::log($userId, "achievement", null, ["code"=>$code]);
        \watrlabs\users\progress::recalc($userId);

        return true;
    }

    private static function has(int $userId){
        global $db;

        return array_map(fn($row) => $row->code, $db->table("user_achievements")->select(["code"])->where("userid", $userId)->get());
    }

    static function checkPlaytime(int $userId){
        global $db;

        $have = self::has($userId);
        $want = array_diff(["first_play", "hour", "ten_hours", "hundred_hours", "explorer"], $have);
        if(!$want){
            return;
        }

        $row = $db->query("SELECT COALESCE(SUM(seconds), 0) AS total, SUM(seconds >= 60) AS games FROM playtime WHERE userid = ?", [$userId])->first();
        $total = (int) $row->total;

        foreach(["first_play"=>60, "hour"=>3600, "ten_hours"=>36000, "hundred_hours"=>360000] as $code => $needed){
            if(in_array($code, $want, true) && $total >= $needed){
                self::award($userId, $code);
            }
        }

        if(in_array("explorer", $want, true) && (int) $row->games >= 10){
            self::award($userId, "explorer");
        }
    }

    static function checkFavorites(int $userId){
        global $db;

        if($db->table("favorites")->where("userid", $userId)->count() >= 10){
            self::award($userId, "collector");
        }
    }

    static function checkFriends(int $userId){
        global $db;

        $count = $db->table("friendships")->where("status", "accepted")->where(function($q) use ($userId){
            $q->where("requester_id", $userId)->orWhere("addressee_id", $userId);
        })->count();

        if($count >= 1){
            self::award($userId, "first_friend");
        }
        if($count >= 10){
            self::award($userId, "popular");
        }
    }

    // for profiles: every achievement, earned ones first with when
    static function forUser(int $userId){
        global $db;

        $earned = [];
        foreach($db->table("user_achievements")->where("userid", $userId)->orderBy("created", "ASC")->get() as $row){
            $earned[$row->code] = (int) $row->created;
        }

        $list = [];
        foreach(self::ALL as $code => [$name, $text, $icon]){
            $list[] = ["code"=>$code, "name"=>$name, "text"=>$text, "icon"=>$icon, "earned"=>$earned[$code] ?? null];
        }

        usort($list, fn($a, $b) => [$a["earned"] === null, $a["earned"]] <=> [$b["earned"] === null, $b["earned"]]);

        return ["list"=>$list, "count"=>count($earned)];
    }
}
