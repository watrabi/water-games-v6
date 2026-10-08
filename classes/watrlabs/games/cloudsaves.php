<?php

namespace watrlabs\games;

// a game's localStorage kept on your account. games in /game-files share the site's origin, so the play
// page can see which keys a game writes (storage events) and copy just those up here. this class only
// stores what it's given; play.js decides which keys belong to the game
class cloudsaves {

    const MAX_BYTES = 1024 * 1024;
    const MAX_KEYS = 200;

    // the site's own keys never go into a game's save, even if a game happens to touch them
    const SITE_KEYS = ["sidebarClosed", "aiModel", "wgMusic", "wgLyrics", "wgChat", "watrUploadMsg", "wgShortcutsSeen"];

    // keeps string keys and values, drops the site's own, returns null if it's too big. pure, tested
    static function clean($data): ?array {
        if(!is_array($data)){
            return [];
        }

        $clean = [];
        foreach($data as $key => $value){
            if(!is_string($key) || $key === "" || strlen($key) > 200 || !is_string($value)){
                continue;
            }
            if(in_array($key, self::SITE_KEYS, true) || str_starts_with($key, "wg_") || str_starts_with($key, "watr")){
                continue;
            }
            $clean[$key] = $value;
        }

        if(count($clean) > self::MAX_KEYS || strlen(json_encode($clean, JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES){
            return null;
        }

        return $clean;
    }

    // only games served from this site can be saved (anything else has its own localStorage)
    static function supported($game){
        return isset($game->gamePath) && str_starts_with((string) $game->gamePath, "/");
    }

    public function get(int $userId, int $gameId){
        global $db;

        $row = $db->table("cloud_saves")->where("userid", $userId)->where("gameid", $gameId)->first();
        if(!$row){
            return null;
        }

        return ["data"=>json_decode($row->data, true) ?: [], "updated"=>(int) $row->updated, "bytes"=>(int) $row->bytes];
    }

    public function put(int $userId, int $gameId, array $data){
        global $db;

        $clean = self::clean($data);
        if($clean === null){
            throw new \InvalidArgumentException("That save is bigger than the 1MB cloud saves can hold.");
        }

        // merge with what's there, so a device that only knows some of the keys doesn't wipe the rest
        $existing = $this->get($userId, $gameId);
        $merged = self::clean(array_merge($existing["data"] ?? [], $clean));
        if($merged === null){
            throw new \InvalidArgumentException("That save is bigger than the 1MB cloud saves can hold.");
        }

        $json = json_encode((object) $merged, JSON_UNESCAPED_UNICODE);
        $now = time();

        $db->query(
            "INSERT INTO cloud_saves (userid, gameid, data, bytes, updated) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), bytes = VALUES(bytes), updated = VALUES(updated)",
            [$userId, $gameId, $json, strlen($json), $now]
        );

        return ["updated"=>$now, "bytes"=>strlen($json)];
    }

    public function delete(int $userId, int $gameId){
        global $db;

        $db->table("cloud_saves")->where("userid", $userId)->where("gameid", $gameId)->delete();
    }

    // for settings
    public function listFor(int $userId){
        global $db;

        return $db->query(
            "SELECT c.gameid, c.bytes, c.updated, g.name, g.type, g.gameIcon FROM cloud_saves c
             INNER JOIN games g ON g.id = c.gameid WHERE c.userid = ? ORDER BY c.updated DESC",
            [$userId]
        )->get();
    }
}
