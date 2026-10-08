<?php

namespace watrlabs\users;

use watrlabs\social\achievements;

// streaks, XP and levels. all of it comes from playtime, so there's nothing to farm that isn't already
// limited by playtime::credit().
//
//   streak:  days in a row with at least a minute of play (server time). missing a day starts it over
//   xp:      1 per minute played + 50 per achievement + 10 per day you played
//   level:   floor(sqrt(xp / 25)) + 1, so level 2 at 25 xp, 5 at 400, 10 at 2025 (about 30 hours)
class progress {

    const DAY_COUNTS_AT = 60; // seconds of play before a day counts

    static function levelFor(int $xp): int {
        return (int) floor(sqrt(max(0, $xp) / 25)) + 1;
    }

    // xp where a level starts
    static function xpForLevel(int $level): int {
        return 25 * ($level - 1) ** 2;
    }

    // how far through the current level, 0 to 100
    static function levelProgress(int $xp): int {
        $level = self::levelFor($xp);
        $from = self::xpForLevel($level);
        $to = self::xpForLevel($level + 1);
        return (int) floor(($xp - $from) / max(1, $to - $from) * 100);
    }

    // what the streak is today: a streak whose last day was before yesterday is over
    static function currentStreak($user, ?string $today = null): int {
        if(empty($user->streak_day)){
            return 0;
        }
        $today = $today ?? date("Y-m-d");
        $yesterday = date("Y-m-d", strtotime($today . " -1 day"));
        return in_array($user->streak_day, [$today, $yesterday], true) ? (int) $user->streak : 0;
    }

    // the next streak, from the last day that counted. pure so it can be tested
    static function nextStreak(?string $lastDay, int $streak, string $today): int {
        if($lastDay === $today){
            return $streak;
        }
        $yesterday = date("Y-m-d", strtotime($today . " -1 day"));
        return $lastDay === $yesterday ? $streak + 1 : 1;
    }

    // called by playtime::beat for every heartbeat that counted
    static function played(int $userId, int $gameId, int $seconds, int $now){
        global $db;

        $day = date("Y-m-d", $now);
        $db->query(
            "INSERT INTO user_game_daily (userid, gameid, day, seconds) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds)",
            [$userId, $gameId, $day, $seconds]
        );

        $today = (int) $db->query("SELECT COALESCE(SUM(seconds), 0) AS n FROM user_game_daily WHERE userid = ? AND day = ?", [$userId, $day])->first()->n;
        $user = $db->table("users")->select(["streak", "streak_best", "streak_day"])->where("id", $userId)->first();

        if($today >= self::DAY_COUNTS_AT && $user->streak_day !== $day){
            $streak = self::nextStreak($user->streak_day, (int) $user->streak, $day);
            $db->table("users")->where("id", $userId)->update([
                "streak"=>$streak,
                "streak_best"=>max($streak, (int) $user->streak_best),
                "streak_day"=>$day,
            ]);

            if($streak >= 7){
                achievements::award($userId, "streak_7");
            }
            if($streak >= 30){
                achievements::award($userId, "streak_30");
            }
        }

        self::recalc($userId);
    }

    // xp from scratch. cheap enough to run on every heartbeat (three indexed sums)
    static function recalc(int $userId){
        global $db;

        $minutes = intdiv((int) $db->query("SELECT COALESCE(SUM(seconds), 0) AS n FROM playtime WHERE userid = ?", [$userId])->first()->n, 60);
        $achievements = (int) $db->table("user_achievements")->where("userid", $userId)->count();
        $days = (int) $db->query(
            "SELECT COUNT(*) AS n FROM (SELECT day FROM user_game_daily WHERE userid = ? GROUP BY day HAVING SUM(seconds) >= ?) d",
            [$userId, self::DAY_COUNTS_AT]
        )->first()->n;

        $xp = $minutes + 50 * $achievements + 10 * $days;
        $db->table("users")->where("id", $userId)->update(["xp"=>$xp, "level"=>self::levelFor($xp)]);

        return $xp;
    }
}
