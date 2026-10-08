<?php

namespace watrlabs\users;

use watrlabs\watrkit\uploads;

// "download my data" and "delete my account"
class accountdata {

    private static function rows(string $sql, array $params){
        global $db;

        return array_map(fn($row) => (array) $row, $db->query($sql, $params)->get());
    }

    // everything tied to the account, as one json-able array. no password hash, no 2FA secret, no IPs
    public function export(int $userId){
        global $db;

        $user = $db->table("users")->where("id", $userId)->first();
        $id = [$userId];

        return [
            "exported"=>date("c"),
            "site"=>$_ENV["APP_NAME"] ?? "Water Games",
            "account"=>[
                "username"=>$user->username,
                "email"=>$user->email,
                "blurb"=>$user->blurb,
                "registered"=>date("c", (int) $user->registered),
                "theme"=>$user->theme,
                "avatar"=>$user->avatar,
                "twoFactor"=>(bool) $user->totp_enabled,
                "shareActivity"=>(bool) $user->share_activity,
            ],
            "favorites"=>self::rows("SELECT g.name, f.created FROM favorites f INNER JOIN games g ON g.id = f.gameid WHERE f.userid = ?", $id),
            "playtime"=>self::rows("SELECT g.name, p.seconds, p.sessions, p.first_played, p.last_played FROM playtime p INNER JOIN games g ON g.id = p.gameid WHERE p.userid = ?", $id),
            "votes"=>self::rows("SELECT g.name, v.vote, v.created FROM game_votes v INNER JOIN games g ON g.id = v.gameid WHERE v.userid = ?", $id),
            "cloudSaves"=>self::rows("SELECT g.name, c.data, c.updated FROM cloud_saves c INNER JOIN games g ON g.id = c.gameid WHERE c.userid = ?", $id),
            "comments"=>self::rows("SELECT g.name AS game, c.body, c.created, c.deleted FROM game_comments c LEFT JOIN games g ON g.id = c.gameid WHERE c.userid = ?", $id),
            "gameRequests"=>self::rows("SELECT name, url, notes, status, created FROM game_requests WHERE userid = ?", $id),
            "friends"=>self::rows(
                "SELECT u.username, f.status, f.created FROM friendships f INNER JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
                 WHERE f.requester_id = ? OR f.addressee_id = ?", [$userId, $userId, $userId]),
            "blocked"=>self::rows("SELECT u.username, b.created FROM blocks b INNER JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ?", $id),
            "messages"=>self::rows(
                "SELECT s.username AS sender, r.username AS recipient, m.body, m.image_id IS NOT NULL AS hadImage, m.created FROM chat_messages m
                 LEFT JOIN users s ON s.id = m.sender_id LEFT JOIN users r ON r.id = m.recipient_id
                 WHERE (m.sender_id = ? OR m.recipient_id = ?) AND m.deleted = 0 ORDER BY m.id", [$userId, $userId]),
            "groupMessages"=>self::rows(
                "SELECT g.name AS groupName, m.body, m.created FROM chat_group_messages m INNER JOIN chat_groups g ON g.id = m.groupid
                 WHERE m.sender_id = ? AND m.deleted = 0 ORDER BY m.id", $id),
            "playlists"=>array_map(function($playlist){
                $playlist["tracks"] = self::rows("SELECT t.title, t.artist FROM playlist_tracks pt INNER JOIN tracks t ON t.id = pt.trackid WHERE pt.playlistid = ? ORDER BY pt.position", [$playlist["id"]]);
                unset($playlist["id"]);
                return $playlist;
            }, self::rows("SELECT id, name, created FROM playlists WHERE userid = ?", $id)),
            "uploadedTracks"=>self::rows("SELECT title, artist, filePath, created FROM tracks WHERE uploader_id = ?", $id),
            "achievements"=>self::rows("SELECT code, created FROM user_achievements WHERE userid = ?", $id),
            "notifications"=>self::rows("SELECT type, data, created, read_at FROM notifications WHERE userid = ?", $id),
            "aiChats"=>array_map(function($chat){
                $chat["messages"] = self::rows("SELECT role, content, model, created FROM ai_messages WHERE chatid = ? AND visible = 1 ORDER BY id", [$chat["id"]]);
                unset($chat["id"]);
                return $chat;
            }, self::rows("SELECT id, title, model, created FROM ai_chats WHERE userid = ?", $id)),
            "aiMemories"=>self::rows("SELECT content, created FROM ai_memories WHERE userid = ?", $id),
            "themes"=>self::rows("SELECT name, input, vars, created FROM user_themes WHERE userid = ?", $id),
            "sessions"=>self::rows("SELECT user_agent, created, last_used FROM sessions WHERE userid = ?", $id),
        ];
    }

    // removes the account and everything that's only theirs. messages they sent to other people go too,
    // since they're the author. files on disk are deleted as well
    public function delete(int $userId){
        global $db;

        $user = $db->table("users")->where("id", $userId)->first();
        if(!$user){
            return;
        }

        // leave groups the normal way so ownership passes on
        $groups = new \watrlabs\social\groups();
        foreach($db->table("chat_group_members")->where("userid", $userId)->get() as $membership){
            try {
                $groups->leave($userId, (int) $membership->groupid);
            } catch (\Throwable $e) {}
        }

        uploads::delete($user->avatar ?? null);
        foreach($db->table("tracks")->where("uploader_id", $userId)->get() as $track){
            uploads::delete($track->filePath);
            uploads::delete($track->coverPath);
            $db->table("playlist_tracks")->where("trackid", $track->id)->delete();
        }

        $root = __DIR__ . "/../../../storage/private";
        foreach(["chat", "ai"] as $folder){
            self::removeDir("$root/$folder/$userId");
        }

        foreach($db->table("playlists")->where("userid", $userId)->get() as $playlist){
            $db->table("playlist_tracks")->where("playlistid", $playlist->id)->delete();
        }
        foreach($db->table("ai_chats")->where("userid", $userId)->get() as $chat){
            $db->table("ai_messages")->where("chatid", $chat->id)->delete();
        }
        foreach($db->table("ai_artifacts")->where("userid", $userId)->get() as $artifact){
            $db->table("ai_artifact_versions")->where("artifactid", $artifact->id)->delete();
        }

        $byUser = [
            "sessions"=>"userid", "favorites"=>"userid", "game_comments"=>"userid", "comment_reports"=>"reporter_id",
            "chat_images"=>"userid", "chat_reports"=>"reporter_id", "chat_group_messages"=>"sender_id",
            "ai_chats"=>"userid", "ai_attachments"=>"userid", "ai_artifacts"=>"userid", "ai_memories"=>"userid", "user_themes"=>"userid",
            "tracks"=>"uploader_id", "playtime"=>"userid", "cloud_saves"=>"userid", "game_votes"=>"userid", "game_requests"=>"userid",
            "game_reports"=>"userid", "notifications"=>"userid", "user_achievements"=>"userid", "activity"=>"userid",
            "playlists"=>"userid", "password_resets"=>"userid", "login_challenges"=>"userid",
        ];
        foreach($byUser as $table => $column){
            $db->table($table)->where($column, $userId)->delete();
        }

        $db->table("notifications")->where("actor_id", $userId)->delete();
        $db->table("chat_messages")->where("sender_id", $userId)->orWhere("recipient_id", $userId)->delete();
        $db->table("friendships")->where("requester_id", $userId)->orWhere("addressee_id", $userId)->delete();
        $db->table("blocks")->where("blocker_id", $userId)->orWhere("blocked_id", $userId)->delete();
        $db->table("users")->where("id", $userId)->delete();
    }

    private static function removeDir(string $dir){
        if(!is_dir($dir)){
            return;
        }
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file){
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
