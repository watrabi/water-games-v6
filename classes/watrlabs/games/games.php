<?php

namespace watrlabs\games;

class games {

    // every list query goes through this so cards always have a favorites count
    private const SELECT = "SELECT g.id, g.type, g.name, g.description, g.gamePath, g.gameIcon, g.plays, g.created,
        g.controls, g.screenshots, g.scores, g.score_label, g.score_format, g.score_max,
        (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites,
        (SELECT COUNT(*) FROM game_votes v WHERE v.gameid = g.id AND v.vote = 1) AS likes,
        (SELECT COUNT(*) FROM game_votes v WHERE v.gameid = g.id AND v.vote = -1) AS dislikes
        FROM games g";

    // trending is plays over the last week, from game_daily
    private const SORTS = [
        "popular"=>"g.plays DESC, g.id DESC",
        "trending"=>"(SELECT COALESCE(SUM(d.plays), 0) FROM game_daily d WHERE d.gameid = g.id AND d.day >= CURDATE() - INTERVAL 7 DAY) DESC, g.plays DESC",
        "newest"=>"g.id DESC",
        "name"=>"g.name ASC",
        "liked"=>"(SELECT COALESCE(SUM(v.vote), 0) FROM game_votes v WHERE v.gameid = g.id) DESC, g.plays DESC",
    ];

    public static function sortOptions(){
        return array_keys(self::SORTS);
    }

    // type is 'game' or 'app'
    public function list(string $sort = "popular", ?string $search = null, int $limit = 200, string $type = "game", ?int $tagId = null){
        global $db;

        $order = self::SORTS[$sort] ?? self::SORTS["popular"];
        $sql = self::SELECT . " WHERE g.type = ?";
        $bindings = [$type];

        if($tagId){
            $sql .= " AND g.id IN (SELECT gameid FROM game_tags WHERE tagid = ?)";
            $bindings[] = $tagId;
        }

        if($search !== null && $search !== ""){
            $sql .= " AND g.name LIKE ?";
            $bindings[] = "%" . addcslashes($search, "%_\\") . "%";
        }

        $sql .= " ORDER BY $order LIMIT " . max(1, $limit);

        return $db->query($sql, $bindings)->get();
    }

    public function get(int $id, ?string $type = null){
        global $db;

        $sql = self::SELECT . " WHERE g.id = ?";
        $bindings = [$id];

        if($type){
            $sql .= " AND g.type = ?";
            $bindings[] = $type;
        }

        $result = $db->query($sql, $bindings)->get();

        return $result[0] ?? null;
    }

    // a few others of the same type to show next to the one being played: the most categories in common
    // first, then the most played
    public function related(int $excludeId, string $type = "game", int $limit = 6){
        global $db;

        return $db->query(
            self::SELECT . " WHERE g.id <> ? AND g.type = ?
            ORDER BY (SELECT COUNT(*) FROM game_tags a INNER JOIN game_tags b ON b.tagid = a.tagid AND b.gameid = ? WHERE a.gameid = g.id) DESC, g.plays DESC
            LIMIT " . max(1, $limit),
            [$excludeId, $type, $excludeId]
        )->get();
    }

    public function favoritesFor(int $userId, int $limit = 200){
        global $db;

        return $db->query(
            self::SELECT . " INNER JOIN favorites mine ON mine.gameid = g.id AND mine.userid = ?
            ORDER BY mine.id DESC LIMIT " . max(1, $limit),
            [$userId]
        )->get();
    }

    public function count(string $type = "game"){
        global $db;

        return $db->table("games")->where("type", $type)->count();
    }

    public function addPlay(int $id){
        global $db;

        $db->table("games")->where("id", $id)->update(["plays"=>$db->raw("plays + 1")]);
        $db->query(
            "INSERT INTO game_daily (gameid, day, plays, seconds) VALUES (?, ?, 1, 0) ON DUPLICATE KEY UPDATE plays = plays + 1",
            [$id, date("Y-m-d")]
        );
    }

    // ---------- thumbs up / down ----------

    public function voteOf(int $userId, int $gameId){
        global $db;

        $row = $db->table("game_votes")->select(["vote"])->where("userid", $userId)->where("gameid", $gameId)->first();
        return $row ? (int) $row->vote : 0;
    }

    // 1, -1, or 0 to take it back. returns the new counts
    public function vote(int $userId, int $gameId, int $vote){
        global $db;

        if($vote === 0){
            $db->table("game_votes")->where("userid", $userId)->where("gameid", $gameId)->delete();
        } else {
            $db->query(
                "INSERT INTO game_votes (userid, gameid, vote, created) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)",
                [$userId, $gameId, $vote > 0 ? 1 : -1, time()]
            );
        }

        return $this->voteCounts($gameId);
    }

    public function voteCounts(int $gameId){
        global $db;

        $row = $db->query("SELECT COALESCE(SUM(vote = 1), 0) AS likes, COALESCE(SUM(vote = -1), 0) AS dislikes FROM game_votes WHERE gameid = ?", [$gameId])->first();
        return ["likes"=>(int) $row->likes, "dislikes"=>(int) $row->dislikes];
    }

    // "92%", or null with too few votes to mean anything
    static function rating($likes, $dislikes){
        $total = (int) $likes + (int) $dislikes;
        return $total < 1 ? null : (int) round((int) $likes / $total * 100);
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

        \watrlabs\social\activity::log($userId, "favorite", $gameId);
        \watrlabs\social\achievements::checkFavorites($userId);

        return true;
    }

    public function favoriteCount(int $gameId){
        global $db;

        return $db->table("favorites")->where("gameid", $gameId)->count();
    }
}
