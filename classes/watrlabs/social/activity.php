<?php

namespace watrlabs\social;

// what friends see on their home page: favorites, achievements, comments, new friends.
// people who turned off "share my activity" never show up here
class activity {

    const KEEP_DAYS = 30;

    static function log(int $userId, string $type, ?int $gameId = null, array $data = []){
        global $db;

        // the same thing twice in a row (favorite, unfavorite, favorite) only shows once
        $last = $db->table("activity")->where("userid", $userId)->orderBy("id", "DESC")->first();
        if($last && $last->type === $type && (int) $last->gameid === (int) $gameId && $last->created > time() - 3600){
            return;
        }

        $db->table("activity")->insert([
            "userid"=>$userId,
            "type"=>$type,
            "gameid"=>$gameId,
            "data"=>$data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            "created"=>time(),
        ]);

        if(random_int(1, 200) === 1){
            $db->table("activity")->where("created", "<", time() - self::KEEP_DAYS * 86400)->delete();
        }
    }

    // friends' activity plus games added to the site, newest first
    public function feedFor(int $userId, int $limit = 20){
        global $db;

        $rows = $db->query(
            "SELECT a.id, a.type, a.gameid, a.data, a.created, u.username, u.avatar, g.name AS game_name, g.type AS game_type, g.gameIcon
             FROM activity a
             INNER JOIN users u ON u.id = a.userid AND u.banned = 0 AND u.share_activity = 1
             INNER JOIN friendships f ON f.status = 'accepted'
                AND ((f.requester_id = ? AND f.addressee_id = a.userid) OR (f.addressee_id = ? AND f.requester_id = a.userid))
             LEFT JOIN games g ON g.id = a.gameid
             WHERE a.created > ?
             ORDER BY a.id DESC LIMIT " . max(1, $limit),
            [$userId, $userId, time() - self::KEEP_DAYS * 86400]
        )->get();

        $items = [];
        foreach($rows as $row){
            $item = self::shape($row);
            if($item){
                $items[] = $item;
            }
        }

        // new games, so the feed isn't empty for people without friends yet
        foreach($db->query(
            "SELECT id, name, type, gameIcon, created FROM games WHERE created > ? ORDER BY created DESC LIMIT 5",
            [time() - 14 * 86400]
        )->get() as $game){
            $items[] = [
                "kind"=>"new_game",
                "user"=>null,
                "text"=>"New on the site",
                "game"=>["id"=>(int) $game->id, "name"=>$game->name, "type"=>$game->type, "icon"=>$game->gameIcon],
                "icon"=>"ph-sparkle",
                "created"=>(int) $game->created,
            ];
        }

        usort($items, fn($a, $b) => $b["created"] <=> $a["created"]);

        return self::withReactions(array_slice($items, 0, $limit));
    }

    // one person's own recent activity, for their profile. the caller checks they share it
    public function forUser(int $userId, int $limit = 8){
        global $db;

        $rows = $db->query(
            "SELECT a.id, a.type, a.gameid, a.data, a.created, u.username, u.avatar, g.name AS game_name, g.type AS game_type, g.gameIcon
             FROM activity a
             INNER JOIN users u ON u.id = a.userid
             LEFT JOIN games g ON g.id = a.gameid
             WHERE a.userid = ? AND a.created > ?
             ORDER BY a.id DESC LIMIT " . max(1, $limit),
            [$userId, time() - self::KEEP_DAYS * 86400]
        )->get();

        return self::withReactions(array_values(array_filter(array_map([self::class, "shape"], $rows))));
    }

    // reactions on the items that have an activity row (new games don't)
    private static function withReactions(array $items){
        $summaries = reactions::summaries("activity", array_filter(array_map(fn($i) => $i["id"] ?? null, $items)));
        foreach($items as &$item){
            $item["reactions"] = isset($item["id"]) ? ($summaries[$item["id"]] ?? []) : null;
        }
        return $items;
    }

    // can $viewerId see this activity row? their own, or a friend who shares
    static function visibleTo(int $activityId, int $viewerId): ?int {
        global $db;

        $row = $db->query(
            "SELECT a.userid, u.share_activity FROM activity a INNER JOIN users u ON u.id = a.userid AND u.banned = 0 WHERE a.id = ?",
            [$activityId]
        )->first();
        if(!$row){
            return null;
        }

        $owner = (int) $row->userid;
        if($owner === $viewerId){
            return $owner;
        }
        if(!$row->share_activity || (new friends())->relation($viewerId, $owner) !== "friends"){
            return null;
        }
        return $owner;
    }

    private static function shape($row){
        $data = $row->data ? (json_decode($row->data, true) ?: []) : [];
        $game = $row->gameid && $row->game_name ? ["id"=>(int) $row->gameid, "name"=>$row->game_name, "type"=>$row->game_type, "icon"=>$row->gameIcon] : null;

        switch($row->type){
            case "favorite":
                if(!$game){ return null; }
                [$text, $icon] = ["favorited", "ph-heart"];
                break;
            case "comment":
                if(!$game){ return null; }
                [$text, $icon] = ["commented on", "ph-chat-text"];
                break;
            case "first_play":
                if(!$game){ return null; }
                [$text, $icon] = ["started playing", "ph-play"];
                break;
            case "achievement":
                $achievement = achievements::ALL[$data["code"] ?? ""] ?? null;
                if(!$achievement){ return null; }
                [$text, $icon] = ["earned " . $achievement[0], $achievement[2]];
                break;
            case "friend":
                if(empty($data["with"])){ return null; }
                [$text, $icon] = ["is now friends with " . $data["with"], "ph-handshake"];
                break;
            default:
                return null;
        }

        return [
            "id"=>(int) $row->id,
            "kind"=>$row->type,
            "user"=>["username"=>$row->username, "avatar"=>$row->avatar],
            "text"=>$text,
            "game"=>$game,
            "icon"=>$icon,
            "created"=>(int) $row->created,
        ];
    }
}
