<?php

namespace watrlabs\social;

// the bell in the top bar. each row is one thing that happened to someone; the text is built when it's
// shown, so renaming a game or a user doesn't leave stale notifications behind
class notifications {

    const KEEP_DAYS = 60;
    const PAGE = 30;

    // type => [icon, link builder, text builder]. data is the decoded json
    static function describe(string $type, array $data, ?string $actor): array {
        $who = $actor ?? "Someone";

        switch($type){
            case "friend_request":
                return ["ph-user-plus", "$who sent you a friend request.", $actor ? "/users/" . rawurlencode(strtolower($actor)) : null];
            case "friend_accept":
                return ["ph-handshake", "$who accepted your friend request.", $actor ? "/users/" . rawurlencode(strtolower($actor)) : null];
            case "mention":
                $game = $data["game"] ?? "a game";
                return ["ph-at", "$who mentioned you in a comment on $game.", isset($data["gameid"]) ? self::gameLink($data) . "#comments" : null];
            case "achievement":
                return ["ph-trophy", "You earned " . ($data["name"] ?? "an achievement") . ".", "/users/" . rawurlencode(strtolower($data["username"] ?? "")) . "#achievements"];
            case "request_added":
                return ["ph-check-circle", ($data["name"] ?? "A game you asked for") . " is on the site now. Thanks for the request!", isset($data["gameid"]) ? self::gameLink($data) : "/games"];
            case "request_declined":
                return ["ph-x-circle", "Your request for " . ($data["name"] ?? "a game") . " wasn't added." . (!empty($data["note"]) ? " " . $data["note"] : ""), "/games/request"];
            case "group_added":
                return ["ph-users-three", "$who added you to " . ($data["name"] ?? "a group chat") . ".", null];
            case "announcement":
                return ["ph-megaphone", $data["text"] ?? "", $data["link"] ?? null];
            case "muted":
                return ["ph-speaker-slash", "A moderator muted you " . \watrlabs\users\moderation::untilText($data["until"] ?? null) . "." . (!empty($data["reason"]) ? " Reason: " . $data["reason"] : ""), null];
            case "challenge":
                return ["ph-sword", "$who challenged you to beat " . ($data["score"] ?? "their score") . " in " . ($data["game"] ?? "a game") . ".", isset($data["gameid"]) ? self::gameLink($data) . "#scores" : null];
            case "challenge_beaten":
                return ["ph-trophy", "$who beat your " . ($data["score"] ?? "score") . " in " . ($data["game"] ?? "a game") . " with " . ($data["theirs"] ?? "a better one") . ".", isset($data["gameid"]) ? self::gameLink($data) . "#scores" : null];
            case "recap":
                return ["ph-calendar-check", "Your week: " . ($data["summary"] ?? "see how it went") . ".", "/recap"];
        }

        return ["ph-bell", "Something happened.", null];
    }

    private static function gameLink(array $data){
        return "/" . (($data["type"] ?? "game") === "app" ? "apps" : "games") . "/" . (int) $data["gameid"];
    }

    static function send(int $userId, string $type, ?int $actorId = null, array $data = []){
        global $db;

        if($actorId && $actorId === $userId){
            return;
        }

        $id = $db->table("notifications")->insert([
            "userid"=>$userId,
            "type"=>$type,
            "actor_id"=>$actorId,
            "data"=>$data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            "created"=>time(),
        ]);

        realtime::publish([$userId], ["type"=>"notification", "id"=>(int) $id]);
    }

    // one for everyone who isn't banned. used by the admin panel's "notify everyone"
    static function broadcast(string $text, ?string $link, int $adminId){
        global $db;

        $data = json_encode(["text"=>$text, "link"=>$link], JSON_UNESCAPED_UNICODE);
        $db->query(
            "INSERT INTO notifications (userid, type, actor_id, data, created) SELECT id, 'announcement', ?, ?, ? FROM users WHERE banned = 0",
            [$adminId, $data, time()]
        );

        // everyone with the site open gets their bell bumped. node only knows who's connected, so ask it
        $online = realtime::onlineIds();
        if($online){
            realtime::publish($online, ["type"=>"notification"]);
        }
    }

    public function unread(int $userId){
        global $db;

        return $db->table("notifications")->where("userid", $userId)->whereNull("read_at")->count();
    }

    public function list(int $userId, ?int $before = null, int $limit = self::PAGE){
        global $db;

        $sql = "SELECT n.*, u.username AS actor_name, u.avatar AS actor_avatar FROM notifications n
                LEFT JOIN users u ON u.id = n.actor_id WHERE n.userid = ?";
        $params = [$userId];
        if($before){
            $sql .= " AND n.id < ?";
            $params[] = $before;
        }

        $rows = $db->query($sql . " ORDER BY n.id DESC LIMIT " . ($limit + 1), $params)->get();

        return [
            "items"=>array_map([self::class, "shape"], array_slice($rows, 0, $limit)),
            "more"=>count($rows) > $limit,
        ];
    }

    static function shape($row){
        $data = $row->data ? (json_decode($row->data, true) ?: []) : [];
        [$icon, $text, $link] = self::describe($row->type, $data, $row->actor_name ?? null);

        return [
            "id"=>(int) $row->id,
            "type"=>$row->type,
            "icon"=>$icon,
            "text"=>$text,
            "link"=>$link,
            "actor"=>$row->actor_name ? ["username"=>$row->actor_name, "avatar"=>$row->actor_avatar] : null,
            "read"=>$row->read_at !== null,
            "created"=>(int) $row->created,
        ];
    }

    public function markRead(int $userId, ?int $id = null){
        global $db;

        $query = $db->table("notifications")->where("userid", $userId)->whereNull("read_at");
        if($id){
            $query->where("id", $id);
        }
        $query->update(["read_at"=>time()]);

        realtime::publish([$userId], ["type"=>"notification"]);
    }

    // old read ones go away; called now and then from requests rather than needing a cron job
    static function prune(){
        global $db;

        if(random_int(1, 200) !== 1){
            return;
        }
        $db->table("notifications")->whereNotNull("read_at")->where("created", "<", time() - self::KEEP_DAYS * 86400)->delete();
    }
}
