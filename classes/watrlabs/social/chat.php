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

    public function send(int $me, int $other, string $body, ?int $imageId){
        global $db;

        if(!$this->friends->areFriends($me, $other) || $this->friends->relation($me, $other) !== "friends"){
            throw new \InvalidArgumentException("You can only message friends.");
        }

        $body = trim(str_replace("\r\n", "\n", $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        if($body === "" && !$imageId){
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

        $id = $db->table("chat_messages")->insert([
            "sender_id"=>$me,
            "recipient_id"=>$other,
            "body"=>$body !== "" ? self::filter($body) : null,
            "image_id"=>$imageId ?: null,
            "created"=>time(),
        ]);

        return $this->shape($db->table("chat_messages")->where("id", $id)->first());
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

        return ["messages"=>array_map([$this, "shape"], $rows), "more"=>$more];
    }

    public function markRead(int $me, int $other){
        global $db;

        $db->table("chat_messages")
            ->where("sender_id", $other)
            ->where("recipient_id", $me)
            ->whereNull("read_at")
            ->update(["read_at"=>time()]);
    }

    // everything new since the last check, plus who's online. also marks you as online
    public function poll(int $me, int $since){
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
        foreach($this->friends->list($me) as $friend){
            if($friend["online"]){
                $online[] = $friend["id"];
            }
        }

        return [
            "messages"=>array_map([$this, "shape"], $rows),
            "unread"=>(object) $unread,
            "seen"=>(object) $seen,
            "online"=>$online,
            "requests"=>count($this->friends->requests($me)["incoming"]),
        ];
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
            "created"=>(int) $row->created,
            "read"=>$row->read_at !== null,
            "deleted"=>$deleted,
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

        return $used ? $image : null;
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

        if($db->table("chat_reports")->where("message_id", $messageId)->where("reporter_id", $me)->first()){
            return; // already reported, nothing more to do
        }

        $db->table("chat_reports")->insert([
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
            return $db->table("chat_reports")->where("status", "open")->count();
        } catch (\Throwable $e) {
            return 0; // before migrations
        }
    }
}
