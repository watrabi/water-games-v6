<?php

namespace watrlabs\games;

class games {

    // every list query goes through this so cards always have a favorites count
    private const SELECT = "SELECT g.id, g.name, g.description, g.gamePath, g.gameIcon, g.plays,
        (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites
        FROM games g";

    private const SORTS = [
        "popular"=>"g.plays DESC, g.id DESC",
        "newest"=>"g.id DESC",
        "name"=>"g.name ASC",
    ];

    public static function sortOptions(){
        return array_keys(self::SORTS);
    }

    public function list(string $sort = "popular", ?string $search = null, int $limit = 200){
        global $db;

        $order = self::SORTS[$sort] ?? self::SORTS["popular"];
        $sql = self::SELECT;
        $bindings = [];

        if($search !== null && $search !== ""){
            $sql .= " WHERE g.name LIKE ?";
            $bindings[] = "%" . addcslashes($search, "%_\\") . "%";
        }

        $sql .= " ORDER BY $order LIMIT " . max(1, $limit);

        return $db->query($sql, $bindings)->get();
    }

    public function get(int $id){
        global $db;

        $result = $db->query(self::SELECT . " WHERE g.id = ?", [$id])->get();

        return $result[0] ?? null;
    }

    // a few other games to show next to the one being played
    public function related(int $excludeId, int $limit = 6){
        global $db;

        return $db->query(self::SELECT . " WHERE g.id <> ? ORDER BY g.plays DESC LIMIT " . max(1, $limit), [$excludeId])->get();
    }

    public function favoritesFor(int $userId, int $limit = 200){
        global $db;

        return $db->query(
            self::SELECT . " INNER JOIN favorites mine ON mine.gameid = g.id AND mine.userid = ?
            ORDER BY mine.id DESC LIMIT " . max(1, $limit),
            [$userId]
        )->get();
    }

    public function count(){
        global $db;

        return $db->table("games")->count();
    }

    public function addPlay(int $id){
        global $db;

        $db->table("games")->where("id", $id)->update(["plays"=>$db->raw("plays + 1")]);
    }

    public function isFavorited(int $userId, int $gameId){
        global $db;

        return (bool) $db->table("favorites")->where("userid", $userId)->where("gameid", $gameId)->first();
    }

    // flips the favorite and returns the new state
    public function toggleFavorite(int $userId, int $gameId){
        global $db;

        if($this->isFavorited($userId, $gameId)){
            $db->table("favorites")->where("userid", $userId)->where("gameid", $gameId)->delete();
            return false;
        }

        $db->table("favorites")->insert([
            "userid"=>$userId,
            "gameid"=>$gameId,
            "created"=>time(),
        ]);

        return true;
    }

    public function favoriteCount(int $gameId){
        global $db;

        return $db->table("favorites")->where("gameid", $gameId)->count();
    }
}
