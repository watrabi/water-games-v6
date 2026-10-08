<?php

namespace watrlabs\games;

use watrlabs\watrkit\settings;

// game of the day, and the random game button.
//
// the day's game is picked the first time someone asks that day and remembered (settings "gotd_history"), so
// it's the same for everyone and doesn't repeat for 30 days while there are enough games. games people mostly
// dislike are skipped. admins can pin a game for today (settings "gotd_pin" = "Y-m-d:id")
class featured {

    const NO_REPEAT_DAYS = 30;

    static function today(): string {
        return date("Y-m-d");
    }

    // the game's id for a day, picking (and remembering) one if nobody has asked yet
    static function gameOfDayId(?string $day = null): ?int {
        global $db;

        $day = $day ?? self::today();

        $pin = (string) settings::get("gotd_pin", "");
        if(str_starts_with($pin, $day . ":")){
            return (int) substr($pin, strlen($day) + 1);
        }

        $history = json_decode((string) settings::get("gotd_history", "{}"), true) ?: [];
        if(isset($history[$day])){
            return (int) $history[$day];
        }

        $recent = array_map("intval", array_values($history));
        $pick = self::pick($day, $recent) ?? self::pick($day, []);
        if(!$pick){
            return null;
        }

        // keep the last 40 days
        $history[$day] = $pick;
        ksort($history);
        $history = array_slice($history, -40, null, true);
        settings::set("gotd_history", json_encode($history));

        return $pick;
    }

    // same day + same games = same answer, so two requests racing at midnight agree
    private static function pick(string $day, array $exclude): ?int {
        global $db;

        $sql = "SELECT g.id FROM games g WHERE g.type = 'game'
                AND (SELECT COUNT(*) FROM game_votes v WHERE v.gameid = g.id AND v.vote = -1)
                    <= (SELECT COUNT(*) FROM game_votes v WHERE v.gameid = g.id AND v.vote = 1) + 2";
        $params = [];
        if($exclude){
            $sql .= " AND g.id NOT IN (" . implode(",", array_map("intval", $exclude)) . ")";
        }
        $sql .= " ORDER BY CRC32(CONCAT(g.id, '-', ?)) LIMIT 1";
        $params[] = $day;

        $row = $db->query($sql, $params)->first();
        return $row ? (int) $row->id : null;
    }

    static function gameOfDay(){
        try {
            $id = self::gameOfDayId();
        } catch (\Throwable $e) {
            return null; // before migrations
        }
        return $id ? (new games())->get($id, "game") : null;
    }

    static function pin(?int $gameId){
        settings::set("gotd_pin", $gameId ? self::today() . ":" . $gameId : "");
    }

    static function pinnedToday(): ?int {
        $pin = (string) settings::get("gotd_pin", "");
        return str_starts_with($pin, self::today() . ":") ? (int) substr($pin, 11) : null;
    }

    // any game (or app), or one you haven't played yet when $unplayedFor is a user id
    static function randomId(string $type = "game", ?int $unplayedFor = null, ?int $not = null): ?int {
        global $db;

        $sql = "SELECT id FROM games WHERE type = ?";
        $params = [$type];
        if($unplayedFor){
            $sql .= " AND id NOT IN (SELECT gameid FROM playtime WHERE userid = ?)";
            $params[] = $unplayedFor;
        }
        if($not){
            $sql .= " AND id <> ?";
            $params[] = $not;
        }

        // ORDER BY RAND() is fine at this size (hundreds of games, not millions)
        $row = $db->query($sql . " ORDER BY RAND() LIMIT 1", $params)->first();
        return $row ? (int) $row->id : null;
    }
}
