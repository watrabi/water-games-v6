<?php

namespace watrlabs\music;

use watrlabs\watrkit\uploads;

class music {

    private const SORTS = [
        "popular"=>"plays DESC, id DESC",
        "newest"=>"id DESC",
        "title"=>"title ASC",
    ];

    const UPLOADS_PER_DAY = 20;
    const MAX_AUDIO_BYTES = 40 * 1024 * 1024;

    public static function sortOptions(){
        return array_keys(self::SORTS);
    }

    public function list(string $sort = "popular", ?string $search = null, int $limit = 500){
        global $db;

        $order = self::SORTS[$sort] ?? self::SORTS["popular"];
        $sql = "SELECT id, title, artist, filePath, coverPath, duration, plays, lyrics IS NOT NULL AS hasLyrics FROM tracks";
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

    // a signed in person's upload, files come from $_FILES["audio"] / ["cover"], $input is $_POST. returns the new track row
    public function upload(int $userId, array $input){
        global $db;

        if($db->table("tracks")->where("uploader_id", $userId)->where("created", ">", time() - 86400)->count() >= self::UPLOADS_PER_DAY){
            throw new \InvalidArgumentException("You've uploaded a lot today. Try again tomorrow.");
        }

        $title = trim($input["title"] ?? "");
        $artist = trim($input["artist"] ?? "");

        if($title === ""){
            throw new \InvalidArgumentException("Give it a title.");
        }
        if(mb_strlen($title) > 255 || mb_strlen($artist) > 255){
            throw new \InvalidArgumentException("Titles and artist names can be 255 characters at most.");
        }
        if(!uploads::sent("audio")){
            throw new \InvalidArgumentException("Pick an audio file to upload.");
        }
        if($_FILES["audio"]["size"] > self::MAX_AUDIO_BYTES){
            throw new \InvalidArgumentException("Audio files have to be under 40MB.");
        }

        // the browser works the length out from the file, it helps line the lyrics up
        $duration = (int) ($input["duration"] ?? 0);
        $duration = $duration > 0 && $duration < 4 * 3600 ? $duration : null;

        $filePath = uploads::store("audio", "music");

        try {
            $coverPath = uploads::sent("cover") ? uploads::store("cover", "covers") : null;
        } catch (\Throwable $e) {
            uploads::delete($filePath);
            throw $e;
        }

        $id = $db->table("tracks")->insert([
            "title"=>$title,
            "artist"=>$artist !== "" ? $artist : null,
            "filePath"=>$filePath,
            "coverPath"=>$coverPath,
            "duration"=>$duration,
            "plays"=>0,
            "uploader_id"=>$userId,
            "created"=>time(),
        ]);

        $track = $this->get((int) $id);
        $this->lyricsFor($track); // look them up now so the first play doesn't wait
        return $this->get((int) $id);
    }

    public function uploadsBy(int $userId){
        global $db;

        return $db->table("tracks")
            ->select(["id", "title", "artist", "filePath", "coverPath", "duration", "plays", "created", "lyricsSynced", $db->raw("lyrics IS NOT NULL AS hasLyrics")])
            ->where("uploader_id", $userId)
            ->orderBy("id", "DESC")
            ->get();
    }

    // uploader or an admin can delete
    public function deleteTrack(int $id, $user){
        global $db;

        $track = $this->get($id);
        if(!$track || !$user || ((int) $track->uploader_id !== (int) $user->id && empty($user->admin))){
            return false;
        }

        uploads::delete($track->filePath);
        uploads::delete($track->coverPath);
        $db->table("tracks")->where("id", $id)->delete();
        return true;
    }

    // ["lyrics"=>?string, "synced"=>bool, "lines"=>?array, "words"=>bool, "source"=>?string]. looked up once and saved,
    // tracks with nothing get asked again after a week. lrc.red goes first (word by word timing for most songs), then
    // lrclib. "lines" comes from the Lyricsfile either way (see lyricsfile.php)
    public function lyricsFor($track){
        global $db;

        $stale = $track->lyricsChecked === null
            || ($track->lyrics === null && $track->lyricsChecked < time() - lyrics::RETRY_AFTER);

        if($stale){
            try {
                $duration = $track->duration ? (int) $track->duration : null;
                $found = null;
                $lrcredDown = false;
                try {
                    $found = lrcred::find($track->title, $track->artist, $duration);
                } catch (\RuntimeException $e) {
                    $lrcredDown = true;
                }
                if(!$found){
                    $found = lyrics::find($track->title, $track->artist, $duration);
                    if($found){
                        $found["source"] = "lrclib";
                    }
                }

                // lrc.red was down and lrclib had nothing: ask both again next time rather than saving "nothing"
                if(!$found && $lrcredDown){
                    throw new \RuntimeException("lrc.red is down");
                }

                $track->lyrics = $found["lyrics"] ?? null;
                $track->lyricsSynced = $found["synced"] ?? false;
                $track->lyricsFile = $found["file"] ?? null;
                $track->lyricsSource = $found["source"] ?? null;
                $db->table("tracks")->where("id", $track->id)->update([
                    "lyrics"=>$track->lyrics,
                    "lyricsSynced"=>$track->lyricsSynced ? 1 : 0,
                    "lyricsFile"=>$track->lyricsFile,
                    "lyricsSource"=>$track->lyricsSource,
                    "lyricsChecked"=>time(),
                ]);
            } catch (\RuntimeException $e) {
                // lrclib is down, leave it unchecked so the next play tries again (and keep whatever we had)
            }
        }

        $file = lyricsfile::parse($track->lyricsFile ?? null);

        return [
            "lyrics"=>$track->lyrics,
            "synced"=>(bool) $track->lyricsSynced,
            "lines"=>$file && $file["lines"] ? $file["lines"] : null,
            "words"=>$file ? $file["words"] : false,
            "source"=>$track->lyrics !== null ? ($track->lyricsSource ?? "lrclib") : null,
        ];
    }
}
