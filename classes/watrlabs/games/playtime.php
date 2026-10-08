<?php

namespace watrlabs\games;

use watrlabs\social\achievements;
use watrlabs\social\realtime;

// how long people actually play. the game page counts seconds only while its tab is visible and the
// window has focus, and sends them in heartbeats. the server never gives more credit than the time that
// passed since the person's last heartbeat (across all their tabs), so leaving two games open doesn't
// double up and a tampered client can't send "3 hours" in one go
class playtime {

    const BEAT_EVERY = 15;      // seconds, what the browser aims for
    const MAX_PER_BEAT = 60;    // most one heartbeat can ever add
    const PRESENCE_FOR = 90;    // "Playing X" goes away this long after the last heartbeat

    // how many of the claimed seconds to count. pure so it can be tested
    static function credit(int $claimed, ?int $lastBeat, int $now): int {
        $claimed = max(0, min($claimed, self::MAX_PER_BEAT));

        if($lastBeat !== null && $lastBeat > 0){
            // a little slack for request latency
            $claimed = min($claimed, max(0, $now - $lastBeat) + 2);
        }

        return $claimed;
    }

    // a game page opened. counts towards "recently played" even before any focused seconds come in
    // $newSession is false for a refresh inside the play counter's window, which shouldn't count as another session
    public function start($user, int $gameId, bool $newSession = true){
        global $db;

        $now = time();
        $db->query(
            "INSERT INTO playtime (userid, gameid, seconds, sessions, first_played, last_played) VALUES (?, ?, 0, 1, ?, ?)
             ON DUPLICATE KEY UPDATE sessions = sessions + ?, last_played = VALUES(last_played)",
            [(int) $user->id, $gameId, $now, $now, $newSession ? 1 : 0]
        );
    }

    // one heartbeat: $seconds of focused play since the last one. returns the person's total for this game
    public function beat($user, int $gameId, int $seconds){
        global $db;

        $now = time();
        $userId = (int) $user->id;
        $fresh = $db->table("users")->select(["play_beat", "playing_game_id", "share_activity"])->where("id", $userId)->first();
        $credit = self::credit($seconds, $fresh->play_beat !== null ? (int) $fresh->play_beat : null, $now);

        if($credit > 0){
            $db->query(
                "INSERT INTO playtime (userid, gameid, seconds, sessions, first_played, last_played) VALUES (?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds), last_played = VALUES(last_played)",
                [$userId, $gameId, $credit, $now, $now]
            );
            $db->query(
                "INSERT INTO game_daily (gameid, day, plays, seconds) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds)",
                [$gameId, date("Y-m-d", $now), $credit]
            );
        }

        $update = ["play_beat"=>$now];
        $switched = (int) ($fresh->playing_game_id ?? 0) !== $gameId;
        if($switched){
            $update["playing_game_id"] = $gameId;
            $update["playing_since"] = $now;
        }
        $db->table("users")->where("id", $userId)->update($update);

        if($switched && !empty($fresh->share_activity)){
            self::announce($userId);
        }

        if($credit > 0){
            achievements::checkPlaytime($userId);
            \watrlabs\users\progress::played($userId, $gameId, $credit, $now);
        }

        return $this->secondsFor($userId, $gameId);
    }

    // the game page closed (or you navigated away)
    public function stop($user, int $gameId){
        global $db;

        $updated = $db->table("users")->where("id", (int) $user->id)->where("playing_game_id", $gameId)->update(["playing_game_id"=>null, "playing_since"=>null]);
        if($updated && $updated->rowCount()){
            self::announce((int) $user->id);
        }
    }

    // tells friends' chat trays to refresh what everyone's playing
    private static function announce(int $userId){
        if(!realtime::enabled()){
            return;
        }

        global $db;
        $ids = array_map(fn($row) => (int) $row->other, $db->query(
            "SELECT CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END AS other FROM friendships
             WHERE (requester_id = ? OR addressee_id = ?) AND status = 'accepted'",
            [$userId, $userId, $userId]
        )->get());

        if($ids){
            realtime::publish($ids, ["type"=>"presence", "user"=>$userId]);
        }
    }

    public function secondsFor(int $userId, int $gameId){
        global $db;

        $row = $db->table("playtime")->select(["seconds"])->where("userid", $userId)->where("gameid", $gameId)->first();
        return $row ? (int) $row->seconds : 0;
    }

    public function rowFor(int $userId, int $gameId){
        global $db;

        return $db->table("playtime")->where("userid", $userId)->where("gameid", $gameId)->first();
    }

    // newest first, with each game's tile info and your time in it
    public function recent(int $userId, int $limit = 12, string $type = "game"){
        global $db;

        return $db->query(
            "SELECT g.id, g.type, g.name, g.gameIcon, g.plays, p.seconds, p.last_played,
                    (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites
             FROM playtime p INNER JOIN games g ON g.id = p.gameid
             WHERE p.userid = ? AND g.type = ? ORDER BY p.last_played DESC LIMIT " . max(1, $limit),
            [$userId, $type]
        )->get();
    }

    public function most(int $userId, int $limit = 6){
        global $db;

        return $db->query(
            "SELECT g.id, g.type, g.name, g.gameIcon, g.plays, p.seconds, p.last_played,
                    (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites
             FROM playtime p INNER JOIN games g ON g.id = p.gameid
             WHERE p.userid = ? AND p.seconds > 0 ORDER BY p.seconds DESC LIMIT " . max(1, $limit),
            [$userId]
        )->get();
    }

    public function totalFor(int $userId){
        global $db;

        return (int) ($db->query("SELECT COALESCE(SUM(seconds), 0) AS total FROM playtime WHERE userid = ?", [$userId])->first()->total ?? 0);
    }

    public function gamesPlayed(int $userId){
        global $db;

        return $db->table("playtime")->where("userid", $userId)->count();
    }

    // friends who've played this game (and share their activity), most time first
    public function friendsOn(int $userId, int $gameId, int $limit = 8){
        global $db;

        $ids = (new \watrlabs\social\friends())->ids($userId);
        if(!$ids){
            return [];
        }

        return $db->query(
            "SELECT u.id, u.username, u.avatar, u.level, p.seconds, p.last_played
             FROM playtime p INNER JOIN users u ON u.id = p.userid
             WHERE p.gameid = ? AND u.banned = 0 AND u.share_activity = 1 AND u.id IN (" . implode(",", $ids) . ")
             ORDER BY p.seconds DESC, p.last_played DESC LIMIT " . max(1, $limit),
            [$gameId]
        )->get();
    }

    // games two people have both played, by the time they've put in together
    public function inCommon(int $a, int $b, int $limit = 6){
        global $db;

        return $db->query(
            "SELECT g.id, g.type, g.name, g.gameIcon, g.plays, pa.seconds AS mine, pb.seconds AS theirs,
                    (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites
             FROM playtime pa
             INNER JOIN playtime pb ON pb.gameid = pa.gameid AND pb.userid = ?
             INNER JOIN games g ON g.id = pa.gameid
             WHERE pa.userid = ?
             ORDER BY pa.seconds + pb.seconds DESC LIMIT " . max(1, $limit),
            [$b, $a]
        )->get();
    }

    // what a person is playing right now, if they're sharing it and it's recent
    static function nowPlaying($row){
        if(empty($row->playing_game_id) || empty($row->share_activity) || (int) ($row->play_beat ?? 0) < time() - self::PRESENCE_FOR){
            return null;
        }
        return (int) $row->playing_game_id;
    }

    // "3h 12m", "45m", "20s"
    static function format(int $seconds): string {
        if($seconds < 60){
            return $seconds . "s";
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if($hours === 0){
            return $minutes . "m";
        }
        return $minutes ? "{$hours}h {$minutes}m" : "{$hours}h";
    }
}
