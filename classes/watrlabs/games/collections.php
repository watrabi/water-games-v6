<?php

namespace watrlabs\games;

use watrlabs\social\chat;
use watrlabs\users\moderation;

// lists of games and apps people put together, like playlists for music. public ones show on profiles and
// /collections. admins can mark theirs as staff picks, which show on /discover and /home
class collections {

    const MAX_PER_USER = 30;
    const MAX_GAMES = 200;
    const NAME_MAX = 60;
    const DESCRIPTION_MAX = 300;

    // with a count and up to 4 icons for the cover
    private const SELECT = "SELECT c.id, c.userid, c.name, c.description, c.public, c.staff, c.created, c.updated, u.username,
        (SELECT COUNT(*) FROM collection_games x WHERE x.collectionid = c.id) AS games
        FROM collections c INNER JOIN users u ON u.id = c.userid";

    private static function withCovers(array $rows): array {
        global $db;

        if(!$rows){
            return $rows;
        }

        $ids = implode(",", array_map(fn($r) => (int) $r->id, $rows));
        $covers = [];
        foreach($db->query(
            "SELECT x.collectionid, g.id, g.name, g.gameIcon FROM collection_games x INNER JOIN games g ON g.id = x.gameid
             WHERE x.collectionid IN ($ids) ORDER BY x.position, x.id"
        )->get() as $row){
            if(count($covers[(int) $row->collectionid] ?? []) < 4){
                $covers[(int) $row->collectionid][] = ["name"=>$row->name, "icon"=>$row->gameIcon];
            }
        }

        foreach($rows as $row){
            $row->covers = $covers[(int) $row->id] ?? [];
        }
        return $rows;
    }

    public function get(int $id){
        global $db;

        $row = $db->query(self::SELECT . " WHERE c.id = ? AND u.banned = 0", [$id])->first();
        return $row ?: null;
    }

    // what someone can see: public ones, or private ones that are theirs
    public function visible(int $id, $viewer){
        $collection = $this->get($id);
        if(!$collection){
            return null;
        }
        if(!$collection->public && (!$viewer || (int) $viewer->id !== (int) $collection->userid)){
            return null;
        }
        return $collection;
    }

    public function games(int $collectionId){
        global $db;

        // same columns as games::list so the tiles work
        return $db->query(
            "SELECT g.id, g.type, g.name, g.description, g.gamePath, g.gameIcon, g.plays, g.created,
                    (SELECT COUNT(*) FROM favorites f WHERE f.gameid = g.id) AS favorites
             FROM collection_games x INNER JOIN games g ON g.id = x.gameid
             WHERE x.collectionid = ? ORDER BY x.position, x.id",
            [$collectionId]
        )->get();
    }

    public function listFor(int $userId, bool $publicOnly = false){
        global $db;

        $sql = self::SELECT . " WHERE c.userid = ?" . ($publicOnly ? " AND c.public = 1" : "") . " ORDER BY c.updated DESC";
        return self::withCovers($db->query($sql, [$userId])->get());
    }

    public function staffPicks(int $limit = 6){
        global $db;

        return self::withCovers($db->query(
            self::SELECT . " WHERE c.staff = 1 AND c.public = 1 AND u.banned = 0
            HAVING games > 0 ORDER BY c.updated DESC LIMIT " . max(1, $limit)
        )->get());
    }

    // public ones from everyone, the ones with the most games recently touched first
    public function browse(int $limit = 48){
        global $db;

        return self::withCovers($db->query(
            self::SELECT . " WHERE c.public = 1 AND c.staff = 0 AND u.banned = 0
            HAVING games >= 2 ORDER BY c.updated DESC LIMIT " . max(1, $limit)
        )->get());
    }

    // for the play page's menu: yours, and whether each has this game
    public function menuFor(int $userId, int $gameId){
        global $db;

        return array_map(fn($row) => [
            "id"=>(int) $row->id,
            "name"=>$row->name,
            "public"=>(bool) $row->public,
            "games"=>(int) $row->games,
            "has"=>(bool) $row->has,
        ], $db->query(
            "SELECT c.id, c.name, c.public, (SELECT COUNT(*) FROM collection_games x WHERE x.collectionid = c.id) AS games,
                    EXISTS(SELECT 1 FROM collection_games x WHERE x.collectionid = c.id AND x.gameid = ?) AS has
             FROM collections c WHERE c.userid = ? ORDER BY c.updated DESC",
            [$gameId, $userId]
        )->get());
    }

    private function owned($user, int $collectionId){
        global $db;

        $collection = $db->table("collections")->where("id", $collectionId)->first();
        // admins can tidy up anyone's
        if(!$collection || ((int) $collection->userid !== (int) $user->id && empty($user->admin))){
            throw new \InvalidArgumentException("That collection isn't yours.");
        }
        return $collection;
    }

    private static function cleanName(string $name){
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, self::NAME_MAX);
        if($name === ""){
            throw new \InvalidArgumentException("Give the collection a name.");
        }
        return chat::filter($name);
    }

    private static function cleanDescription(string $text){
        $text = mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text))), 0, self::DESCRIPTION_MAX);
        return $text === "" ? null : chat::filter($text);
    }

    public function create($user, string $name, string $description = "", bool $public = true){
        global $db;

        moderation::requireUnmuted($user);

        if($db->table("collections")->where("userid", $user->id)->count() >= self::MAX_PER_USER){
            throw new \InvalidArgumentException("You have " . self::MAX_PER_USER . " collections already. Delete one first.");
        }

        return (int) $db->table("collections")->insert([
            "userid"=>(int) $user->id,
            "name"=>self::cleanName($name),
            "description"=>self::cleanDescription($description),
            "public"=>$public ? 1 : 0,
            "staff"=>0,
            "created"=>time(),
            "updated"=>time(),
        ]);
    }

    // name, description, public, and staff (admins only)
    public function update($user, int $collectionId, array $input){
        global $db;

        moderation::requireUnmuted($user);
        $collection = $this->owned($user, $collectionId);

        $values = [
            "name"=>self::cleanName((string) ($input["name"] ?? $collection->name)),
            "description"=>self::cleanDescription((string) ($input["description"] ?? "")),
            "public"=>!empty($input["public"]) ? 1 : 0,
            "updated"=>time(),
        ];
        if(!empty($user->admin)){
            $values["staff"] = !empty($input["staff"]) && $values["public"] ? 1 : 0;
        }

        $db->table("collections")->where("id", $collectionId)->update($values);
    }

    public function delete($user, int $collectionId){
        global $db;

        $this->owned($user, $collectionId);
        $db->table("collection_games")->where("collectionid", $collectionId)->delete();
        $db->table("collections")->where("id", $collectionId)->delete();
    }

    public function add($user, int $collectionId, int $gameId){
        global $db;

        moderation::requireUnmuted($user);
        $this->owned($user, $collectionId);

        if(!$db->table("games")->where("id", $gameId)->first()){
            throw new \InvalidArgumentException("That game doesn't exist.");
        }
        $count = $db->table("collection_games")->where("collectionid", $collectionId)->count();
        if($count >= self::MAX_GAMES){
            throw new \InvalidArgumentException("Collections can have " . self::MAX_GAMES . " games at most.");
        }

        $db->query(
            "INSERT IGNORE INTO collection_games (collectionid, gameid, position, added) VALUES (?, ?, ?, ?)",
            [$collectionId, $gameId, $count, time()]
        );
        $db->table("collections")->where("id", $collectionId)->update(["updated"=>time()]);
    }

    public function remove($user, int $collectionId, int $gameId){
        global $db;

        $this->owned($user, $collectionId);
        $db->table("collection_games")->where("collectionid", $collectionId)->where("gameid", $gameId)->delete();
        $db->table("collections")->where("id", $collectionId)->update(["updated"=>time()]);
    }

    // a list of game ids in the new order (from drag and drop or the up/down buttons)
    public function reorder($user, int $collectionId, array $gameIds){
        global $db;

        $this->owned($user, $collectionId);
        foreach(array_values(array_map("intval", $gameIds)) as $position => $gameId){
            $db->table("collection_games")->where("collectionid", $collectionId)->where("gameid", $gameId)->update(["position"=>$position]);
        }
    }

    // public collections a game is in, for its page
    public function containing(int $gameId, int $limit = 4){
        global $db;

        return $db->query(
            "SELECT c.id, c.name, u.username, c.staff FROM collection_games x INNER JOIN collections c ON c.id = x.collectionid
             INNER JOIN users u ON u.id = c.userid
             WHERE x.gameid = ? AND c.public = 1 AND u.banned = 0 ORDER BY c.staff DESC, c.updated DESC LIMIT " . max(1, $limit),
            [$gameId]
        )->get();
    }
}
