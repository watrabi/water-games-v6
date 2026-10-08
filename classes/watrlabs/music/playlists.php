<?php

namespace watrlabs\music;

// your own lists of tracks. public: anyone with the link can see and play them
class playlists {

    const MAX_PER_USER = 50;
    const MAX_TRACKS = 500;
    const NAME_MAX = 60;

    public function listFor(int $userId){
        global $db;

        return $db->query(
            "SELECT p.id, p.name, p.updated, COUNT(pt.id) AS tracks,
                    (SELECT t.coverPath FROM playlist_tracks x INNER JOIN tracks t ON t.id = x.trackid
                     WHERE x.playlistid = p.id AND t.coverPath IS NOT NULL ORDER BY x.position LIMIT 1) AS cover
             FROM playlists p LEFT JOIN playlist_tracks pt ON pt.playlistid = p.id
             WHERE p.userid = ? GROUP BY p.id, p.name, p.updated ORDER BY p.updated DESC",
            [$userId]
        )->get();
    }

    public function get(int $id){
        global $db;

        return $db->query(
            "SELECT p.*, u.username, u.avatar FROM playlists p INNER JOIN users u ON u.id = p.userid WHERE p.id = ? AND u.banned = 0",
            [$id]
        )->first();
    }

    public function tracks(int $playlistId){
        global $db;

        return $db->query(
            "SELECT pt.id AS entry, t.id, t.title, t.artist, t.filePath, t.coverPath, t.duration, t.plays
             FROM playlist_tracks pt INNER JOIN tracks t ON t.id = pt.trackid
             WHERE pt.playlistid = ? ORDER BY pt.position, pt.id",
            [$playlistId]
        )->get();
    }

    private function owned(int $userId, int $playlistId){
        global $db;

        $playlist = $db->table("playlists")->where("id", $playlistId)->where("userid", $userId)->first();
        if(!$playlist){
            throw new \InvalidArgumentException("That playlist isn't yours.");
        }
        return $playlist;
    }

    private static function cleanName(string $name){
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, self::NAME_MAX);
        if($name === ""){
            throw new \InvalidArgumentException("Give the playlist a name.");
        }
        return \watrlabs\social\chat::filter($name);
    }

    public function create(int $userId, string $name){
        global $db;

        if($db->table("playlists")->where("userid", $userId)->count() >= self::MAX_PER_USER){
            throw new \InvalidArgumentException("You have " . self::MAX_PER_USER . " playlists already. Delete one first.");
        }

        $id = (int) $db->table("playlists")->insert(["userid"=>$userId, "name"=>self::cleanName($name), "created"=>time(), "updated"=>time()]);
        \watrlabs\social\achievements::award($userId, "dj");
        return $id;
    }

    public function rename(int $userId, int $playlistId, string $name){
        global $db;

        $this->owned($userId, $playlistId);
        $db->table("playlists")->where("id", $playlistId)->update(["name"=>self::cleanName($name), "updated"=>time()]);
    }

    public function delete(int $userId, int $playlistId){
        global $db;

        $this->owned($userId, $playlistId);
        $db->table("playlist_tracks")->where("playlistid", $playlistId)->delete();
        $db->table("playlists")->where("id", $playlistId)->delete();
    }

    public function add(int $userId, int $playlistId, int $trackId){
        global $db;

        $this->owned($userId, $playlistId);
        if(!$db->table("tracks")->where("id", $trackId)->first()){
            throw new \InvalidArgumentException("That track doesn't exist.");
        }
        if($db->table("playlist_tracks")->where("playlistid", $playlistId)->where("trackid", $trackId)->first()){
            throw new \InvalidArgumentException("It's already in that playlist.");
        }
        if($db->table("playlist_tracks")->where("playlistid", $playlistId)->count() >= self::MAX_TRACKS){
            throw new \InvalidArgumentException("Playlists can hold " . self::MAX_TRACKS . " tracks.");
        }

        $position = (int) ($db->query("SELECT COALESCE(MAX(position), 0) AS p FROM playlist_tracks WHERE playlistid = ?", [$playlistId])->first()->p ?? 0) + 1;
        $db->table("playlist_tracks")->insert(["playlistid"=>$playlistId, "trackid"=>$trackId, "position"=>$position, "added"=>time()]);
        $db->table("playlists")->where("id", $playlistId)->update(["updated"=>time()]);
    }

    public function removeEntry(int $userId, int $playlistId, int $entryId){
        global $db;

        $this->owned($userId, $playlistId);
        $db->table("playlist_tracks")->where("playlistid", $playlistId)->where("id", $entryId)->delete();
        $db->table("playlists")->where("id", $playlistId)->update(["updated"=>time()]);
    }

    // moves one entry up or down a spot
    public function move(int $userId, int $playlistId, int $entryId, int $direction){
        global $db;

        $this->owned($userId, $playlistId);
        $entries = $db->table("playlist_tracks")->where("playlistid", $playlistId)->orderBy("position")->orderBy("id")->get();
        $ids = array_map(fn($e) => (int) $e->id, $entries);
        $index = array_search($entryId, $ids, true);
        $swap = $index === false ? false : $index + ($direction < 0 ? -1 : 1);

        if($index === false || $swap < 0 || $swap >= count($ids)){
            return;
        }

        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
        foreach($ids as $position => $id){
            $db->table("playlist_tracks")->where("id", $id)->update(["position"=>$position + 1]);
        }
    }
}
