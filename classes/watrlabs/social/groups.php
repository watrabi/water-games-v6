<?php

namespace watrlabs\social;

// group chats. like DMs, you can only bring in people you're friends with. anyone in the group can add
// their own friends and rename it; the owner can also remove people. if the owner leaves, the person
// who's been there longest takes over, and a group with nobody left is deleted
class groups {

    const MAX_MEMBERS = 20;
    const MAX_OWNED = 20;
    const NAME_MAX = 50;

    private friends $friends;

    function __construct(){
        $this->friends = new friends();
    }

    private function group(int $groupId){
        global $db;

        return $db->table("chat_groups")->where("id", $groupId)->first();
    }

    public function isMember(int $groupId, int $userId){
        global $db;

        return (bool) $db->table("chat_group_members")->where("groupid", $groupId)->where("userid", $userId)->first();
    }

    public function memberIds(int $groupId){
        global $db;

        return array_map(fn($r) => (int) $r->userid, $db->table("chat_group_members")->select(["userid"])->where("groupid", $groupId)->get());
    }

    private function requireMember(int $groupId, int $me){
        $group = $this->group($groupId);
        if(!$group || !$this->isMember($groupId, $me)){
            throw new \InvalidArgumentException("That group doesn't exist, or you're not in it anymore.");
        }
        return $group;
    }

    private static function cleanName(string $name){
        return mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, self::NAME_MAX);
    }

    // a line like "sam added alex" in the conversation
    private function note(int $groupId, string $text){
        global $db;

        $id = $db->table("chat_group_messages")->insert(["groupid"=>$groupId, "sender_id"=>0, "body"=>$text, "created"=>time()]);
        $message = $this->shape($db->table("chat_group_messages")->where("id", $id)->first());
        realtime::publish($this->memberIds($groupId), ["type"=>"group_message", "message"=>$message]);
    }

    private function changed(array $userIds){
        realtime::publish($userIds, ["type"=>"groups"]);
    }

    private static function username(int $id){
        global $db;

        return $db->table("users")->select(["username"])->where("id", $id)->first()->username ?? "someone";
    }

    // only friends who aren't banned and haven't been blocked either way
    private function addable(int $me, array $userIds){
        $ok = [];
        foreach(array_unique(array_map("intval", $userIds)) as $id){
            if($id !== $me && $this->friends->relation($me, $id) === "friends"){
                $ok[] = $id;
            }
        }
        return $ok;
    }

    public function create(int $me, string $name, array $memberIds){
        global $db;

        $members = $this->addable($me, $memberIds);
        if(count($members) < 2){
            throw new \InvalidArgumentException("Pick at least two friends for a group. For one, just message them.");
        }
        if(count($members) + 1 > self::MAX_MEMBERS){
            throw new \InvalidArgumentException("Groups can have " . self::MAX_MEMBERS . " people at most.");
        }
        if($db->table("chat_groups")->where("owner_id", $me)->count() >= self::MAX_OWNED){
            throw new \InvalidArgumentException("You've made a lot of groups. Leave some first.");
        }

        $name = self::cleanName($name);
        if($name === ""){
            $names = array_map([self::class, "username"], array_slice($members, 0, 3));
            $name = mb_substr(self::username($me) . ", " . implode(", ", $names), 0, self::NAME_MAX);
        }

        $now = time();
        $groupId = (int) $db->table("chat_groups")->insert(["name"=>chat::filter($name), "owner_id"=>$me, "created"=>$now]);
        foreach(array_merge([$me], $members) as $userId){
            $db->table("chat_group_members")->insert(["groupid"=>$groupId, "userid"=>$userId, "joined"=>$now, "last_read"=>0]);
        }

        foreach($members as $userId){
            notifications::send($userId, "group_added", $me, ["name"=>$name]);
        }

        $this->note($groupId, self::username($me) . " made the group");
        $this->changed(array_merge([$me], $members));

        return $groupId;
    }

    public function add(int $me, int $groupId, array $userIds){
        global $db;

        $group = $this->requireMember($groupId, $me);
        $current = $this->memberIds($groupId);
        $new = array_values(array_diff($this->addable($me, $userIds), $current));

        if(!$new){
            throw new \InvalidArgumentException("Pick friends who aren't in the group yet.");
        }
        if(count($current) + count($new) > self::MAX_MEMBERS){
            throw new \InvalidArgumentException("Groups can have " . self::MAX_MEMBERS . " people at most.");
        }

        // start them at the latest message, so they don't get a wall of unread history
        $last = (int) ($db->query("SELECT MAX(id) AS lastid FROM chat_group_messages WHERE groupid = ?", [$groupId])->first()->lastid ?? 0);
        foreach($new as $userId){
            $db->table("chat_group_members")->insert(["groupid"=>$groupId, "userid"=>$userId, "joined"=>time(), "last_read"=>$last]);
            notifications::send($userId, "group_added", $me, ["name"=>$group->name]);
        }

        $this->note($groupId, self::username($me) . " added " . implode(", ", array_map([self::class, "username"], $new)));
        $this->changed(array_merge($current, $new));
    }

    public function remove(int $me, int $groupId, int $userId){
        global $db;

        $group = $this->requireMember($groupId, $me);
        if((int) $group->owner_id !== $me){
            throw new \InvalidArgumentException("Only the group's owner can remove people.");
        }
        if($userId === $me){
            return $this->leave($me, $groupId);
        }

        $members = $this->memberIds($groupId);
        $db->table("chat_group_members")->where("groupid", $groupId)->where("userid", $userId)->delete();
        $this->note($groupId, self::username($me) . " removed " . self::username($userId));
        $this->changed($members);
    }

    public function leave(int $me, int $groupId){
        global $db;

        $group = $this->requireMember($groupId, $me);
        $members = $this->memberIds($groupId);

        $db->table("chat_group_members")->where("groupid", $groupId)->where("userid", $me)->delete();
        $left = array_values(array_diff($members, [$me]));

        if(!$left){
            $this->deleteGroup($groupId);
            $this->changed([$me]);
            return;
        }

        if((int) $group->owner_id === $me){
            $next = $db->table("chat_group_members")->where("groupid", $groupId)->orderBy("joined", "ASC")->orderBy("id", "ASC")->first();
            $db->table("chat_groups")->where("id", $groupId)->update(["owner_id"=>$next->userid]);
        }

        $this->note($groupId, self::username($me) . " left");
        $this->changed($members);
    }

    private function deleteGroup(int $groupId){
        global $db;

        $db->table("chat_group_messages")->where("groupid", $groupId)->delete();
        $db->table("chat_group_members")->where("groupid", $groupId)->delete();
        $db->table("chat_groups")->where("id", $groupId)->delete();
    }

    public function rename(int $me, int $groupId, string $name){
        global $db;

        $this->requireMember($groupId, $me);
        $name = self::cleanName($name);
        if($name === ""){
            throw new \InvalidArgumentException("Give the group a name.");
        }

        $db->table("chat_groups")->where("id", $groupId)->update(["name"=>chat::filter($name)]);
        $this->note($groupId, self::username($me) . " renamed the group to " . chat::filter($name));
        $this->changed($this->memberIds($groupId));
    }

    // ---------- messages ----------

    public function send(int $me, int $groupId, string $body, ?int $imageId){
        global $db;

        $this->requireMember($groupId, $me);

        $body = trim(str_replace("\r\n", "\n", $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        if($body === "" && !$imageId){
            throw new \InvalidArgumentException("Type something first.");
        }
        if(mb_strlen($body) > chat::MAX_LENGTH){
            throw new \InvalidArgumentException("Messages can be " . chat::MAX_LENGTH . " characters at most.");
        }

        // shares the DM rate limit
        $recent = $db->table("chat_messages")->where("sender_id", $me)->where("created", ">", time() - 60)->count()
            + $db->table("chat_group_messages")->where("sender_id", $me)->where("created", ">", time() - 60)->count();
        if($recent >= chat::PER_MINUTE){
            throw new \InvalidArgumentException("Slow down a little, you're sending messages really fast.");
        }

        if($imageId && !$db->table("chat_images")->where("id", $imageId)->where("userid", $me)->first()){
            throw new \InvalidArgumentException("That image couldn't be found, try attaching it again.");
        }

        $id = $db->table("chat_group_messages")->insert([
            "groupid"=>$groupId,
            "sender_id"=>$me,
            "body"=>$body !== "" ? chat::filter($body) : null,
            "image_id"=>$imageId ?: null,
            "created"=>time(),
        ]);
        $db->table("chat_group_members")->where("groupid", $groupId)->where("userid", $me)->update(["last_read"=>$id]);

        $message = $this->shape($db->table("chat_group_messages")->where("id", $id)->first());
        realtime::publish($this->memberIds($groupId), ["type"=>"group_message", "message"=>$message]);

        return $message;
    }

    public function history(int $me, int $groupId, ?int $before = null, int $limit = 40){
        global $db;

        $this->requireMember($groupId, $me);

        $sql = "SELECT m.*, u.username, u.avatar FROM chat_group_messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.groupid = ?";
        $params = [$groupId];
        if($before){
            $sql .= " AND m.id < ?";
            $params[] = $before;
        }

        $rows = $db->query($sql . " ORDER BY m.id DESC LIMIT " . ($limit + 1), $params)->get();
        $more = count($rows) > $limit;

        return ["messages"=>array_map([$this, "shape"], array_reverse(array_slice($rows, 0, $limit))), "more"=>$more];
    }

    public function markRead(int $me, int $groupId){
        global $db;

        $last = (int) ($db->query("SELECT MAX(id) AS lastid FROM chat_group_messages WHERE groupid = ?", [$groupId])->first()->lastid ?? 0);
        $db->table("chat_group_members")->where("groupid", $groupId)->where("userid", $me)->where("last_read", "<", $last)->update(["last_read"=>$last]);
        realtime::publish([$me], ["type"=>"group_read", "group"=>$groupId]);
    }

    // your groups with members, unread counts and the newest message id (for sorting)
    public function listFor(int $me){
        global $db;

        $groups = $db->query(
            "SELECT g.id, g.name, g.owner_id, m.last_read,
                    (SELECT MAX(id) FROM chat_group_messages x WHERE x.groupid = g.id) AS lastid,
                    (SELECT COUNT(*) FROM chat_group_messages x WHERE x.groupid = g.id AND x.id > m.last_read AND x.sender_id NOT IN (0, ?) AND x.deleted = 0) AS unread
             FROM chat_groups g INNER JOIN chat_group_members m ON m.groupid = g.id AND m.userid = ?",
            [$me, $me]
        )->get();

        if(!$groups){
            return [];
        }

        $ids = array_map(fn($g) => (int) $g->id, $groups);
        $members = [];
        foreach($db->query(
            "SELECT m.groupid, u.id, u.username, u.avatar FROM chat_group_members m INNER JOIN users u ON u.id = m.userid
             WHERE m.groupid IN (" . implode(",", $ids) . ") ORDER BY m.joined, m.id"
        )->get() as $row){
            $members[(int) $row->groupid][] = ["id"=>(int) $row->id, "username"=>$row->username, "avatar"=>$row->avatar];
        }

        $list = array_map(fn($g) => [
            "id"=>(int) $g->id,
            "name"=>$g->name,
            "owner"=>(int) $g->owner_id,
            "members"=>$members[(int) $g->id] ?? [],
            "unread"=>(int) $g->unread,
            "lastMessage"=>(int) $g->lastid,
        ], $groups);

        usort($list, fn($a, $b) => $b["lastMessage"] <=> $a["lastMessage"]);
        return $list;
    }

    // new group messages since an id, for the polling fallback
    public function since(int $me, int $since){
        global $db;

        return array_map([$this, "shape"], $db->query(
            "SELECT x.*, u.username, u.avatar FROM chat_group_messages x
             INNER JOIN chat_group_members m ON m.groupid = x.groupid AND m.userid = ?
             LEFT JOIN users u ON u.id = x.sender_id
             WHERE x.id > ? ORDER BY x.id ASC LIMIT 200",
            [$me, $since]
        )->get());
    }

    public function lastIdFor(int $me){
        global $db;

        return (int) ($db->query(
            "SELECT MAX(x.id) AS lastid FROM chat_group_messages x INNER JOIN chat_group_members m ON m.groupid = x.groupid AND m.userid = ?",
            [$me]
        )->first()->lastid ?? 0);
    }

    public function shape($row){
        global $db;

        // realtime sends skip the join, so look the sender up
        if($row->sender_id && !isset($row->username)){
            $user = $db->table("users")->select(["username", "avatar"])->where("id", $row->sender_id)->first();
            $row->username = $user->username ?? null;
            $row->avatar = $user->avatar ?? null;
        }

        $deleted = (bool) $row->deleted;
        return [
            "id"=>(int) $row->id,
            "group"=>(int) $row->groupid,
            "from"=>(int) $row->sender_id,
            "fromName"=>$row->username ?? null,
            "fromAvatar"=>$row->avatar ?? null,
            "system"=>(int) $row->sender_id === 0,
            "body"=>$deleted ? null : $row->body,
            "image"=>!$deleted && $row->image_id ? "/chat/images/" . (int) $row->image_id : null,
            "created"=>(int) $row->created,
            "deleted"=>$deleted,
        ];
    }

    public function report(int $me, int $messageId, string $reason, string $details){
        global $db;

        $message = $db->table("chat_group_messages")->where("id", $messageId)->first();
        if(!$message || !$message->sender_id || (int) $message->sender_id === $me || !$this->isMember((int) $message->groupid, $me)){
            throw new \InvalidArgumentException("You can't report that message.");
        }
        if(!isset(chat::REPORT_REASONS[$reason])){
            throw new \InvalidArgumentException("Pick a reason.");
        }
        if($db->table("chat_reports")->where("kind", "group")->where("message_id", $messageId)->where("reporter_id", $me)->first()){
            return;
        }

        $db->table("chat_reports")->insert([
            "kind"=>"group",
            "message_id"=>$messageId,
            "reporter_id"=>$me,
            "reason"=>$reason,
            "details"=>mb_substr(trim($details), 0, 1000) ?: null,
            "status"=>"open",
            "created"=>time(),
        ]);
    }
}
