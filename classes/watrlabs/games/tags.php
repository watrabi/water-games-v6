<?php

namespace watrlabs\games;

// categories like Puzzle or Racing. a game can have a few; /games?tag=puzzle filters by one
class tags {

    private static ?array $all = null;

    static function all(){
        global $db;

        if(self::$all === null){
            try {
                self::$all = $db->table("tags")->orderBy("sort", "ASC")->orderBy("name", "ASC")->get();
            } catch (\Throwable $e) {
                self::$all = []; // before migrations
            }
        }
        return self::$all;
    }

    // only tags at least one game of this type uses, so the chips never lead to an empty page
    static function used(string $type = "game"){
        global $db;

        try {
            return $db->query(
                "SELECT t.id, t.slug, t.name, COUNT(*) AS games FROM tags t
                 INNER JOIN game_tags gt ON gt.tagid = t.id INNER JOIN games g ON g.id = gt.gameid AND g.type = ?
                 GROUP BY t.id, t.slug, t.name, t.sort ORDER BY t.sort, t.name",
                [$type]
            )->get();
        } catch (\Throwable $e) {
            return [];
        }
    }

    static function bySlug(string $slug){
        foreach(self::all() as $tag){
            if($tag->slug === $slug){
                return $tag;
            }
        }
        return null;
    }

    static function forGame(int $gameId){
        global $db;

        return $db->query(
            "SELECT t.id, t.slug, t.name FROM tags t INNER JOIN game_tags gt ON gt.tagid = t.id WHERE gt.gameid = ? ORDER BY t.sort, t.name",
            [$gameId]
        )->get();
    }

    static function setForGame(int $gameId, array $tagIds){
        global $db;

        $valid = array_map(fn($t) => (int) $t->id, self::all());
        $tagIds = array_values(array_intersect(array_map("intval", $tagIds), $valid));

        $db->table("game_tags")->where("gameid", $gameId)->delete();
        foreach($tagIds as $tagId){
            $db->table("game_tags")->insert(["gameid"=>$gameId, "tagid"=>$tagId]);
        }
    }

    static function slugify(string $name){
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        return substr($slug, 0, 40);
    }

    static function save(?int $id, string $name, int $sort){
        global $db;

        $name = mb_substr(trim($name), 0, 40);
        $slug = self::slugify($name);
        if($name === "" || $slug === ""){
            throw new \InvalidArgumentException("Give the category a name.");
        }

        $clash = $db->table("tags")->where("slug", $slug)->first();
        if($clash && (int) $clash->id !== (int) $id){
            throw new \InvalidArgumentException("There's already a category called that.");
        }

        if($id){
            $db->table("tags")->where("id", $id)->update(["name"=>$name, "slug"=>$slug, "sort"=>$sort]);
            return $id;
        }

        return (int) $db->table("tags")->insert(["name"=>$name, "slug"=>$slug, "sort"=>$sort]);
    }

    static function delete(int $id){
        global $db;

        $db->table("game_tags")->where("tagid", $id)->delete();
        $db->table("tags")->where("id", $id)->delete();
    }
}
