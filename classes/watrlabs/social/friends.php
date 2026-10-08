<?php

namespace watrlabs\social;

// friend requests, friends and blocks. a friendship is one row per pair:
// requester_id asked addressee_id, status is pending until it's accepted
class friends {

    const MAX_FRIENDS = 200;
    const MAX_PENDING = 50;
    const ONLINE_SECONDS = 70;

    // how $me and $other are related: self | none | friends | outgoing | incoming | blocked | blockedby
    public function relation(int $me, int $other){
        global $db;

        if($me === $other){
            return "self";
        }

        if($db->table("blocks")->where("blocker_id", $me)->where("blocked_id", $other)->first()){
            return "blocked";
        }

        if($db->table("blocks")->where("blocker_id", $other)->where("blocked_id", $me)->first()){
            return "blockedby";
        }

        $row = $this->row($me, $other);

        if(!$row){
            return "none";
        }

        if($row->status === "accepted"){
            return "friends";
        }

        return (int) $row->requester_id === $me ? "outgoing" : "incoming";
    }

    public function areFriends(int $a, int $b){
        $row = $this->row($a, $b);
        return $row && $row->status === "accepted";
    }

    private function row(int $a, int $b){
        global $db;

        return $db->table("friendships")->where(function($q) use ($a, $b){
            $q->where(function($q) use ($a, $b){
                $q->where("requester_id", $a)->where("addressee_id", $b);
            })->orWhere(function($q) use ($a, $b){
                $q->where("requester_id", $b)->where("addressee_id", $a);
            });
        })->first();
    }

    private function deleteRow(int $a, int $b){
        global $db;

        $db->table("friendships")->where("requester_id", $a)->where("addressee_id", $b)->delete();
        $db->table("friendships")->where("requester_id", $b)->where("addressee_id", $a)->delete();
    }

    private function countFriends(int $userId){
        global $db;

        return $db->table("friendships")->where("status", "accepted")->where(function($q) use ($userId){
            $q->where("requester_id", $userId)->orWhere("addressee_id", $userId);
        })->count();
    }

    // runs one of the friend actions, returns the new relation or throws with a message for the person.
    // both people's trays get told to refresh their lists
    public function act(int $me, int $other, string $action){
        $before = $this->relation($me, $other);
        $relation = $this->apply($me, $other, $action);
        realtime::publish([$me, $other], ["type"=>"friends"]);

        if($before !== $relation){
            if($relation === "outgoing"){
                notifications::send($other, "friend_request", $me);
            } elseif($relation === "friends"){
                notifications::send($other, "friend_accept", $me);
                activity::log($me, "friend", null, ["with"=>self::nameOf($other)]);
                activity::log($other, "friend", null, ["with"=>self::nameOf($me)]);
                achievements::checkFriends($me);
                achievements::checkFriends($other);
            }
        }

        return $relation;
    }

    private static function nameOf(int $id){
        global $db;

        return $db->table("users")->select(["username"])->where("id", $id)->first()->username ?? "";
    }

    private function apply(int $me, int $other, string $action){
        global $db;

        $target = $db->table("users")->select(["id", "banned"])->where("id", $other)->first();
        if(!$target || $me === $other){
            throw new \InvalidArgumentException("That person doesn't exist.");
        }

        $relation = $this->relation($me, $other);

        switch($action){
            case "request":
                if($relation === "blocked"){
                    throw new \InvalidArgumentException("Unblock them first.");
                }
                if($relation === "blockedby" || !empty($target->banned)){
                    throw new \InvalidArgumentException("You can't add this person.");
                }
                if($relation === "friends" || $relation === "outgoing"){
                    return $relation;
                }
                if($relation === "incoming"){
                    return $this->apply($me, $other, "accept");
                }
                if($this->countFriends($me) >= self::MAX_FRIENDS){
                    throw new \InvalidArgumentException("You've hit the limit of " . self::MAX_FRIENDS . " friends.");
                }
                if($db->table("friendships")->where("requester_id", $me)->where("status", "pending")->count() >= self::MAX_PENDING){
                    throw new \InvalidArgumentException("You have a lot of requests waiting. Give people a chance to answer.");
                }
                $db->table("friendships")->insert(["requester_id"=>$me, "addressee_id"=>$other, "status"=>"pending", "created"=>time(), "updated"=>time()]);
                return "outgoing";

            case "accept":
                if($relation !== "incoming"){
                    return $relation;
                }
                if($this->countFriends($me) >= self::MAX_FRIENDS){
                    throw new \InvalidArgumentException("You've hit the limit of " . self::MAX_FRIENDS . " friends.");
                }
                $db->table("friendships")->where("requester_id", $other)->where("addressee_id", $me)->update(["status"=>"accepted", "updated"=>time()]);
                return "friends";

            case "decline":
            case "cancel":
            case "remove":
                if(in_array($relation, ["incoming", "outgoing", "friends"], true)){
                    $this->deleteRow($me, $other);
                }
                return $this->relation($me, $other);

            case "block":
                $this->deleteRow($me, $other);
                if($relation !== "blocked"){
                    $db->table("blocks")->insert(["blocker_id"=>$me, "blocked_id"=>$other, "created"=>time()]);
                }
                return "blocked";

            case "unblock":
                $db->table("blocks")->where("blocker_id", $me)->where("blocked_id", $other)->delete();
                return $this->relation($me, $other);
        }

        throw new \InvalidArgumentException("Unknown action.");
    }

    // accepted friends with presence, unread counts and when we last talked
    public function list(int $me){
        global $db;

        $rows = $db->query(
            "SELECT u.id, u.username, u.avatar, u.last_seen, u.playing_game_id, u.play_beat, u.share_activity, g.name AS playing_name, g.type AS playing_type
             FROM friendships f
             INNER JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
             LEFT JOIN games g ON g.id = u.playing_game_id
             WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted' AND u.banned = 0",
            [$me, $me, $me]
        )->get();

        $unread = [];
        foreach($db->query("SELECT sender_id, COUNT(*) AS total FROM chat_messages WHERE recipient_id = ? AND read_at IS NULL AND deleted = 0 GROUP BY sender_id", [$me])->get() as $row){
            $unread[(int) $row->sender_id] = (int) $row->total;
        }

        $last = [];
        foreach($db->query(
            "SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS other, MAX(id) AS lastid
             FROM chat_messages WHERE sender_id = ? OR recipient_id = ? GROUP BY other",
            [$me, $me, $me]
        )->get() as $row){
            $last[(int) $row->other] = (int) $row->lastid;
        }

        $connected = realtime::onlineIds();

        $friends = array_map(fn($row) => [
            "id"=>(int) $row->id,
            "username"=>$row->username,
            "avatar"=>$row->avatar,
            "playing"=>\watrlabs\games\playtime::nowPlaying($row) && $row->playing_name
                ? ["id"=>(int) $row->playing_game_id, "name"=>$row->playing_name, "type"=>$row->playing_type] : null,
            "online"=>self::isOnline($row->last_seen) || in_array((int) $row->id, $connected, true),
            "lastSeen"=>$row->last_seen ? (int) $row->last_seen : null,
            "unread"=>$unread[(int) $row->id] ?? 0,
            "lastMessage"=>$last[(int) $row->id] ?? 0,
        ], $rows);

        // people you talked to most recently first, then whoever's online, then alphabetical
        usort($friends, fn($a, $b) =>
            [$b["lastMessage"], $b["online"], strtolower($a["username"])] <=> [$a["lastMessage"], $a["online"], strtolower($b["username"])]
        );

        return $friends;
    }

    public function requests(int $me){
        global $db;

        $incoming = $db->query(
            "SELECT u.id, u.username, u.avatar, f.created FROM friendships f INNER JOIN users u ON u.id = f.requester_id
             WHERE f.addressee_id = ? AND f.status = 'pending' AND u.banned = 0 ORDER BY f.created DESC",
            [$me]
        )->get();

        $outgoing = $db->query(
            "SELECT u.id, u.username, u.avatar, f.created FROM friendships f INNER JOIN users u ON u.id = f.addressee_id
             WHERE f.requester_id = ? AND f.status = 'pending' ORDER BY f.created DESC",
            [$me]
        )->get();

        $shape = fn($row) => ["id"=>(int) $row->id, "username"=>$row->username, "avatar"=>$row->avatar];

        return ["incoming"=>array_map($shape, $incoming), "outgoing"=>array_map($shape, $outgoing)];
    }

    public function blockedList(int $me){
        global $db;

        return array_map(fn($row) => ["id"=>(int) $row->id, "username"=>$row->username, "avatar"=>$row->avatar], $db->query(
            "SELECT u.id, u.username, u.avatar FROM blocks b INNER JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY u.username",
            [$me]
        )->get());
    }

    // username search for "add friend", with how each result relates to you
    public function search(int $me, string $query){
        global $db;

        if(strlen($query) < 2){
            return [];
        }

        $rows = $db->table("users")
            ->select(["id", "username", "avatar"])
            ->where("username", "LIKE", addcslashes($query, "%_\\") . "%")
            ->where("banned", 0)
            ->where("id", "!=", $me)
            ->orderBy("username", "ASC")
            ->limit(8)
            ->get();

        $results = [];
        foreach($rows as $row){
            $relation = $this->relation($me, (int) $row->id);
            if($relation === "blockedby"){
                continue; // don't show people who blocked you
            }
            $results[] = ["id"=>(int) $row->id, "username"=>$row->username, "avatar"=>$row->avatar, "relation"=>$relation];
        }

        return $results;
    }

    static function isOnline($lastSeen){
        return $lastSeen && (int) $lastSeen > time() - self::ONLINE_SECONDS;
    }
}
