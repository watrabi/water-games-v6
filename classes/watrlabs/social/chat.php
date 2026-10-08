<?php

namespace watrlabs\social;

use watrlabs\watrkit\settings;
use watrlabs\watrkit\uploads;

// direct messages between friends, chat images and reports
class chat {

    const MAX_LENGTH = 1000;
    const PER_MINUTE = 20;
    const IMAGES_PER_DAY = 40;
    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    const EDIT_WINDOW = 3600; // your own messages can be edited or deleted for an hour

    // deleted: 0 = there, 1 = removed by a moderator, 2 = deleted by whoever sent it
    const DELETED_BY_MOD = 1;
    const DELETED_BY_SENDER = 2;

    const REPORT_REASONS = [
        "harassment"=>"Bullying or harassment",
        "inappropriate"=>"Inappropriate or adult content",
        "personal_info"=>"Asking for or sharing personal info",
        "scam"=>"Scam or suspicious link",
        "spam"=>"Spam",
        "other"=>"Something else",
    ];

    private friends $friends;

    function __construct(){
        $this->friends = new friends();
    }

    // ---------- messages ----------

    public function send(int $me, int $other, string $body, ?int $imageId, ?int $gameId = null){
        global $db;

        \watrlabs\users\moderation::requireUnmutedId($me);
        if(!$this->friends->areFriends($me, $other) || $this->friends->relation($me, $other) !== "friends"){
            throw new \InvalidArgumentException("You can only message friends.");
        }

        $body = trim(str_replace("\r\n", "\n", $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        if($body === "" && !$imageId && !$gameId){
            throw new \InvalidArgumentException("Type something first.");
        }

        if(mb_strlen($body) > self::MAX_LENGTH){
            throw new \InvalidArgumentException("Messages can be " . self::MAX_LENGTH . " characters at most.");
        }

        $recent = $db->table("chat_messages")->where("sender_id", $me)->where("created", ">", time() - 60)->count();
        if($recent >= self::PER_MINUTE){
            throw new \InvalidArgumentException("Slow down a little, you're sending messages really fast.");
        }

        if($imageId && !$db->table("chat_images")->where("id", $imageId)->where("userid", $me)->first()){
            throw new \InvalidArgumentException("That image couldn't be found, try attaching it again.");
        }

        self::requireGame($gameId);

        $id = $db->table("chat_messages")->insert([
            "sender_id"=>$me,
            "recipient_id"=>$other,
            "body"=>$body !== "" ? self::filter($body) : null,
            "image_id"=>$imageId ?: null,
            "game_id"=>$gameId ?: null,
            "created"=>time(),
        ]);

        $message = $this->shape($db->table("chat_messages")->where("id", $id)->first());

        // both sides: the other person, and your own other tabs
        realtime::publish([$me, $other], ["type"=>"message", "message"=>$message]);

        return $message;
    }

    // swaps blocked words for #### (whole words, any case). the list is set in the admin panel
    static function filter(string $text){
        $words = array_filter(array_map("trim", preg_split('/[\n,]+/', (string) settings::get("chat_filter", ""))));

        foreach($words as $word){
            $text = preg_replace_callback('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', fn($m) => str_repeat("#", mb_strlen($m[0])), $text);
        }

        return $text;
    }

    // a page of the conversation, oldest first. $before is a message id for loading further back
    public function history(int $me, int $other, ?int $before = null, int $limit = 40){
        global $db;

        $sql = "SELECT * FROM chat_messages WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))";
        $bindings = [$me, $other, $other, $me];

        if($before){
            $sql .= " AND id < ?";
            $bindings[] = $before;
        }

        $rows = $db->query($sql . " ORDER BY id DESC LIMIT " . ($limit + 1), $bindings)->get();
        $more = count($rows) > $limit;
        $rows = array_reverse(array_slice($rows, 0, $limit));

        return ["messages"=>reactions::attach("dm", array_map([$this, "shape"], $rows)), "more"=>$more];
    }

    // ---------- editing and deleting your own ----------

    // the old text is kept in message_edits, so a reported message can't be edited away
    static function keepOld(string $kind, int $id, ?string $body){
        global $db;

        $db->table("message_edits")->insert(["kind"=>$kind, "item_id"=>$id, "body"=>$body, "created"=>time()]);
    }

    // shared checks for DM and group messages
    static function cleanEdit(string $body, $message): string {
        $body = trim(str_replace("\r\n", "\n", $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        if($body === "" && !$message->image_id && empty($message->game_id)){
            throw new \InvalidArgumentException("Type something, or delete the message instead.");
        }
        if(mb_strlen($body) > self::MAX_LENGTH){
            throw new \InvalidArgumentException("Messages can be " . self::MAX_LENGTH . " characters at most.");
        }
        return $body;
    }

    static function ownRecent($message, int $me){
        if(!$message || (int) $message->sender_id !== $me || (int) $message->deleted !== 0){
            throw new \InvalidArgumentException("You can only change your own messages.");
        }
        if((int) $message->created < time() - self::EDIT_WINDOW){
            throw new \InvalidArgumentException("Messages can only be changed for an hour after sending.");
        }
    }

    public function edit(int $me, int $id, string $body){
        global $db;

        \watrlabs\users\moderation::requireUnmutedId($me);
        $message = $db->table("chat_messages")->where("id", $id)->first();
        self::ownRecent($message, $me);
        $body = self::cleanEdit($body, $message);

        if($body === (string) $message->body){
            return $this->shape($message);
        }

        self::keepOld("dm", $id, $message->body);
        $db->table("chat_messages")->where("id", $id)->update(["body"=>$body !== "" ? self::filter($body) : null, "edited"=>time()]);

        $shaped = reactions::attach("dm", [$this->shape($db->table("chat_messages")->where("id", $id)->first())])[0];
        realtime::publish([(int) $message->sender_id, (int) $message->recipient_id], ["type"=>"edited", "kind"=>"dm", "message"=>$shaped]);
        return $shaped;
    }

    public function deleteOwn(int $me, int $id){
        global $db;

        $message = $db->table("chat_messages")->where("id", $id)->first();
        self::ownRecent($message, $me);

        self::keepOld("dm", $id, $message->body);
        $db->table("chat_messages")->where("id", $id)->update(["deleted"=>self::DELETED_BY_SENDER]);

        $shaped = $this->shape($db->table("chat_messages")->where("id", $id)->first());
        realtime::publish([(int) $message->sender_id, (int) $message->recipient_id], ["type"=>"edited", "kind"=>"dm", "message"=>$shaped]);
        return $shaped;
    }

    public function markRead(int $me, int $other){
        global $db;

        $unread = $db->table("chat_messages")->where("sender_id", $other)->where("recipient_id", $me)->whereNull("read_at")->count();
        if(!$unread){
            return;
        }

        $db->table("chat_messages")
            ->where("sender_id", $other)
            ->where("recipient_id", $me)
            ->whereNull("read_at")
            ->update(["read_at"=>time()]);

        // lets the sender's chat show "Seen" right away, and clears your unread count in other tabs
        $last = $db->query("SELECT MAX(id) AS lastid FROM chat_messages WHERE sender_id = ? AND recipient_id = ?", [$other, $me])->get();
        realtime::publish([$other, $me], ["type"=>"read", "by"=>$me, "of"=>$other, "lastId"=>(int) ($last[0]->lastid ?? 0)]);
    }

    // everything new since the last check, plus who's online. also marks you as online
    public function poll(int $me, int $since, int $groupSince = 0){
        global $db;

        $rows = $db->query(
            "SELECT * FROM chat_messages WHERE (sender_id = ? OR recipient_id = ?) AND id > ? ORDER BY id ASC LIMIT 200",
            [$me, $me, $since]
        )->get();

        $unread = [];
        foreach($db->query("SELECT sender_id, COUNT(*) AS total FROM chat_messages WHERE recipient_id = ? AND read_at IS NULL AND deleted = 0 GROUP BY sender_id", [$me])->get() as $row){
            $unread[(int) $row->sender_id] = (int) $row->total;
        }

        // messages you sent that the other person has read since, so "seen" can update
        $seen = [];
        foreach($db->query("SELECT recipient_id, MAX(id) AS lastread FROM chat_messages WHERE sender_id = ? AND read_at IS NOT NULL GROUP BY recipient_id", [$me])->get() as $row){
            $seen[(int) $row->recipient_id] = (int) $row->lastread;
        }

        $online = [];
        $playing = [];
        foreach($this->friends->list($me) as $friend){
            if($friend["online"]){
                $online[] = $friend["id"];
            }
            if($friend["playing"]){
                $playing[$friend["id"]] = $friend["playing"];
            }
        }

        $groups = new groups();

        return [
            "messages"=>array_map([$this, "shape"], $rows),
            "groupMessages"=>$groups->since($me, $groupSince),
            "playing"=>(object) $playing,
            "notifications"=>(new notifications())->unread($me),
            "unread"=>(object) $unread,
            "seen"=>(object) $seen,
            "online"=>$online,
            "requests"=>count($this->friends->requests($me)["incoming"]),
        ];
    }

    // ---------- shared games ----------

    private static array $cards = [];

    static function requireGame(?int $gameId){
        if($gameId && !self::gameCard($gameId)){
            throw new \InvalidArgumentException("That game isn't on the site anymore.");
        }
    }

    // what a shared game looks like in a chat: {id, name, type, icon, url}. null if it's gone
    static function gameCard($gameId): ?array {
        global $db;

        $gameId = (int) $gameId;
        if(!$gameId){
            return null;
        }

        if(!array_key_exists($gameId, self::$cards)){
            $game = $db->table("games")->select(["id", "name", "type", "gameIcon"])->where("id", $gameId)->first();
            self::$cards[$gameId] = $game ? [
                "id"=>(int) $game->id,
                "name"=>$game->name,
                "type"=>$game->type,
                "icon"=>$game->gameIcon ?: null,
                "url"=>($game->type === "app" ? "/apps/" : "/item/") . (int) $game->id,
            ] : null;
        }

        return self::$cards[$gameId];
    }

    static function touchPresence($user){
        global $db;

        if(!$user || (int) ($user->last_seen ?? 0) > time() - 30){
            return;
        }

        $db->table("users")->where("id", $user->id)->update(["last_seen"=>time()]);
    }

    public function shape($row){
        $deleted = (bool) $row->deleted;

        return [
            "id"=>(int) $row->id,
            "from"=>(int) $row->sender_id,
            "to"=>(int) $row->recipient_id,
            "body"=>$deleted ? null : $row->body,
            "image"=>!$deleted && $row->image_id ? "/chat/images/" . (int) $row->image_id : null,
            "game"=>!$deleted ? self::gameCard($row->game_id ?? null) : null,
            "created"=>(int) $row->created,
            "read"=>$row->read_at !== null,
            "deleted"=>$deleted,
            "removedBy"=>$deleted ? ((int) $row->deleted === self::DELETED_BY_SENDER ? "sender" : "mod") : null,
            "edited"=>!$deleted && !empty($row->edited),
        ];
    }

    // ---------- images ----------

    public function storeImage(int $me, array $file){
        global $db;

        if($db->table("chat_images")->where("userid", $me)->where("created", ">", time() - 86400)->count() >= self::IMAGES_PER_DAY){
            throw new \InvalidArgumentException("You've sent a lot of images today. Try again tomorrow.");
        }

        if(($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
            throw new \InvalidArgumentException("The upload didn't go through.");
        }

        if($file["size"] > self::MAX_IMAGE_BYTES){
            throw new \InvalidArgumentException("Images have to be under 5MB.");
        }

        $info = uploads::imageInfo($file["tmp_name"]);
        $ext = $info ? (uploads::IMAGES[$info["mime"]] ?? null) : null;
        if(!$ext){
            throw new \InvalidArgumentException("That isn't a JPEG, PNG, GIF or WebP image.");
        }

        $dir = __DIR__ . "/../../../storage/private/chat/" . $me;
        if(!is_dir($dir)){
            mkdir($dir, 0750, true);
        }

        $name = bin2hex(random_bytes(16)) . "." . $ext;
        if(!move_uploaded_file($file["tmp_name"], "$dir/$name")){
            throw new \RuntimeException("Couldn't save the image.");
        }

        $id = $db->table("chat_images")->insert(["userid"=>$me, "path"=>"$me/$name", "mime"=>$info["mime"], "created"=>time()]);

        return ["id"=>(int) $id, "url"=>"/chat/images/" . (int) $id];
    }

    // the image row, if $viewer is allowed to see it (uploader, either side of a message using it, or an admin)
    public function imageFor(int $imageId, $viewer){
        global $db;

        $image = $db->table("chat_images")->where("id", $imageId)->first();
        if(!$image || !$viewer){
            return null;
        }

        if((int) $image->userid === (int) $viewer->id || !empty($viewer->admin)){
            return $image;
        }

        $used = $db->table("chat_messages")
            ->where("image_id", $imageId)
            ->where("deleted", 0)
            ->where(function($q) use ($viewer){
                $q->where("sender_id", $viewer->id)->orWhere("recipient_id", $viewer->id);
            })->first();

        if($used){
            return $image;
        }

        // or a group message in a group you're in
        $inGroup = $db->query(
            "SELECT 1 FROM chat_group_messages x INNER JOIN chat_group_members m ON m.groupid = x.groupid AND m.userid = ?
             WHERE x.image_id = ? AND x.deleted = 0 LIMIT 1",
            [(int) $viewer->id, $imageId]
        )->first();

        return $inGroup ? $image : null;
    }

    static function imageFile($image){
        return __DIR__ . "/../../../storage/private/chat/" . $image->path;
    }

    // ---------- reports ----------

    public function report(int $me, int $messageId, string $reason, string $details){
        global $db;

        $message = $db->table("chat_messages")->where("id", $messageId)->first();

        // you can only report messages that were sent to you
        if(!$message || (int) $message->recipient_id !== $me){
            throw new \InvalidArgumentException("You can't report that message.");
        }

        if(!isset(self::REPORT_REASONS[$reason])){
            throw new \InvalidArgumentException("Pick a reason.");
        }

        if($db->table("chat_reports")->where("kind", "dm")->where("message_id", $messageId)->where("reporter_id", $me)->first()){
            return; // already reported, nothing more to do
        }

        $db->table("chat_reports")->insert([
            "kind"=>"dm",
            "message_id"=>$messageId,
            "reporter_id"=>$me,
            "reason"=>$reason,
            "details"=>mb_substr(trim($details), 0, 1000) ?: null,
            "status"=>"open",
            "created"=>time(),
        ]);
    }

    static function openReports(){
        global $db;

        try {
            $chat = $db->table("chat_reports")->where("status", "open")->count();
        } catch (\Throwable $e) {
            $chat = 0; // before migrations
        }

        // game comment reports land on the same admin page
        return $chat + \watrlabs\games\comments::openReports() + \watrlabs\games\requests::openBroken();
    }
}
