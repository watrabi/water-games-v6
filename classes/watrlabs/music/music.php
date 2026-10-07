<?php

namespace watrlabs\music;

class music {

    private const SORTS = [
        "popular"=>"plays DESC, id DESC",
        "newest"=>"id DESC",
        "title"=>"title ASC",
    ];

    public static function sortOptions(){
        return array_keys(self::SORTS);
    }

    public function list(string $sort = "popular", ?string $search = null, int $limit = 500){
        global $db;

        $order = self::SORTS[$sort] ?? self::SORTS["popular"];
        $sql = "SELECT id, title, artist, filePath, coverPath, duration, plays FROM tracks";
        $bindings = [];

        if($search !== null && $search !== ""){
            $like = "%" . addcslashes($search, "%_\\") . "%";
            $sql .= " WHERE title LIKE ? OR artist LIKE ?";
            $bindings = [$like, $like];
        }

        $sql .= " ORDER BY $order LIMIT " . max(1, $limit);

        return $db->query($sql, $bindings)->get();
    }

    public function get(int $id){
        global $db;

        return $db->table("tracks")->where("id", $id)->first();
    }

    public function count(){
        global $db;

        return $db->table("tracks")->count();
    }

    public function addPlay(int $id){
        global $db;

        $db->table("tracks")->where("id", $id)->update(["plays"=>$db->raw("plays + 1")]);
    }
}
