<?php

namespace watrlabs\games;

use watrlabs\social\notifications;
use watrlabs\social\achievements;

// "please add this game" from players, and "this game is broken" reports
class requests {

    const PER_DAY = 5;
    const OPEN_PER_USER = 10;

    const BROKEN_REASONS = [
        "wont_load"=>"It won't load",
        "black_screen"=>"Black or blank screen",
        "controls"=>"Controls don't work",
        "sound"=>"No sound or broken sound",
        "saves"=>"Progress doesn't save",
        "other"=>"Something else",
    ];

    // ---------- game requests ----------

    public function request(int $userId, string $name, string $url, string $notes){
        global $db;

        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 100);
        $url = trim($url);
        $notes = mb_substr(trim($notes), 0, 1000);

        if($name === ""){
            throw new \InvalidArgumentException("What's the game called?");
        }
        if($url !== "" && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))){
            throw new \InvalidArgumentException("That link doesn't look right. It should start with https://");
        }
        if($db->table("game_requests")->where("userid", $userId)->where("created", ">", time() - 86400)->count() >= self::PER_DAY){
            throw new \InvalidArgumentException("That's " . self::PER_DAY . " requests today, try again tomorrow.");
        }
        if($db->table("game_requests")->where("userid", $userId)->where("status", "open")->count() >= self::OPEN_PER_USER){
            throw new \InvalidArgumentException("You have a lot of requests waiting already. Give us a chance to get through them.");
        }

        // asking for something that's already here
        $existing = $db->table("games")->where("name", $name)->first();
        if($existing){
            throw new \InvalidArgumentException("$existing->name is already on the site!");
        }

        $db->table("game_requests")->insert([
            "userid"=>$userId,
            "name"=>$name,
            "url"=>$url !== "" ? mb_substr($url, 0, 500) : null,
            "notes"=>$notes !== "" ? $notes : null,
            "status"=>"open",
            "created"=>time(),
        ]);
    }

    public function mine(int $userId){
        global $db;

        return $db->query(
            "SELECT r.*, g.type AS game_type FROM game_requests r LEFT JOIN games g ON g.id = r.gameid
             WHERE r.userid = ? ORDER BY r.id DESC LIMIT 50",
            [$userId]
        )->get();
    }

    static function openCount(){
        global $db;

        try {
            return $db->table("game_requests")->where("status", "open")->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // admin: mark it added (linking the game) or declined (with an optional note for the person)
    public function resolve(int $requestId, string $status, ?int $gameId, string $note, int $adminId){
        global $db;

        $request = $db->table("game_requests")->where("id", $requestId)->first();
        if(!$request || !in_array($status, ["added", "declined"], true)){
            return false;
        }

        $game = $gameId ? $db->table("games")->where("id", $gameId)->first() : null;

        $db->table("game_requests")->where("id", $requestId)->update([
            "status"=>$status,
            "gameid"=>$game ? $game->id : null,
            "handled_by"=>$adminId,
            "handled_at"=>time(),
        ]);

        if($status === "added"){
            notifications::send((int) $request->userid, "request_added", null, [
                "name"=>$game->name ?? $request->name, "gameid"=>$game ? (int) $game->id : null, "type"=>$game->type ?? "game",
            ]);
            achievements::award((int) $request->userid, "requester");
        } else {
            notifications::send((int) $request->userid, "request_declined", null, ["name"=>$request->name, "note"=>mb_substr(trim($note), 0, 200)]);
        }

        return true;
    }

    // ---------- broken game reports ----------

    public function reportBroken(int $userId, int $gameId, string $reason, string $details){
        global $db;

        if(!isset(self::BROKEN_REASONS[$reason])){
            throw new \InvalidArgumentException("Pick what's wrong.");
        }

        // one open report per person per game is plenty
        if($db->table("game_reports")->where("gameid", $gameId)->where("userid", $userId)->where("status", "open")->first()){
            return;
        }
        if($db->table("game_reports")->where("userid", $userId)->where("created", ">", time() - 86400)->count() >= 20){
            throw new \InvalidArgumentException("You've sent a lot of reports today. Thanks, we'll get to them.");
        }

        $db->table("game_reports")->insert([
            "gameid"=>$gameId,
            "userid"=>$userId,
            "reason"=>$reason,
            "details"=>mb_substr(trim($details), 0, 1000) ?: null,
            "status"=>"open",
            "created"=>time(),
        ]);
    }

    static function openBroken(){
        global $db;

        try {
            return $db->table("game_reports")->where("status", "open")->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
