<?php

namespace watrlabs\ai;

// saved chats, their messages and image attachments
class chats {

    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    const MAX_IMAGES_PER_MESSAGE = 4;
    const ALLOWED_MIME = ["image/jpeg", "image/png", "image/gif", "image/webp"];

    static function attachmentDir(int $userId){
        return __DIR__ . "/../../../storage/private/ai/" . $userId;
    }

    public function listFor(int $userId, int $limit = 100){
        global $db;

        return $db->table("ai_chats")
            ->select(["id", "title", "model", "updated"])
            ->where("userid", $userId)
            ->orderBy("updated", "DESC")
            ->limit($limit)
            ->get();
    }

    // only returns the chat if it belongs to this user
    public function get(int $chatId, int $userId){
        global $db;

        return $db->table("ai_chats")->where("id", $chatId)->where("userid", $userId)->first();
    }

    public function create(int $userId, string $title, string $model){
        global $db;

        return $db->table("ai_chats")->insert([
            "userid"=>$userId,
            "title"=>$title,
            "model"=>$model,
            "created"=>time(),
            "updated"=>time(),
        ]);
    }

    public function touch(int $chatId, string $model){
        global $db;

        $db->table("ai_chats")->where("id", $chatId)->update(["updated"=>time(), "model"=>$model]);
    }

    public function rename(int $chatId, string $title){
        global $db;

        $db->table("ai_chats")->where("id", $chatId)->update(["title"=>$title]);
    }

    public function delete(int $chatId, int $userId){
        global $db;

        // images that were only used in this chat go too
        foreach($this->messages($chatId) as $message){
            foreach($message["content"] as $block){
                if($block["type"] === "image"){
                    $this->deleteAttachment((int) $block["attachment"], $userId);
                }
            }
        }

        $db->table("ai_messages")->where("chatid", $chatId)->delete();
        (new artifacts())->deleteChat($chatId);
        $db->table("ai_chats")->where("id", $chatId)->where("userid", $userId)->delete();
    }

    public function addMessage(int $chatId, string $role, array $content, ?string $model = null, bool $visible = true){
        global $db;

        return $db->table("ai_messages")->insert([
            "chatid"=>$chatId,
            "role"=>$role,
            "content"=>json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "model"=>$model,
            "visible"=>$visible ? 1 : 0,
            "created"=>time(),
        ]);
    }

    public function messages(int $chatId){
        global $db;

        $rows = $db->table("ai_messages")->where("chatid", $chatId)->orderBy("id", "ASC")->get();

        return array_map(fn($row) => [
            "id"=>(int) $row->id,
            "role"=>$row->role,
            "content"=>json_decode($row->content, true) ?: [],
            "model"=>$row->model,
            "visible"=>(bool) $row->visible,
            "created"=>(int) $row->created,
        ], $rows);
    }

    // drops everything after the last thing the user typed, for regenerate / retry
    public function trimAfterLastPrompt(int $chatId){
        global $db;

        $last = $db->table("ai_messages")
            ->where("chatid", $chatId)
            ->where("role", "user")
            ->where("visible", 1)
            ->orderBy("id", "DESC")
            ->first();

        if(!$last){
            return false;
        }

        $db->table("ai_messages")->where("chatid", $chatId)->where("id", ">", $last->id)->delete();
        (new artifacts())->forgetMessagesAfter($chatId, (int) $last->id);
        return true;
    }

    // prompts sent in the last 24 hours, for the daily limit
    public function promptsToday(int $userId){
        global $db;

        $result = $db->query(
            "SELECT COUNT(*) AS total FROM ai_messages m INNER JOIN ai_chats c ON c.id = m.chatid
             WHERE c.userid = ? AND m.role = 'user' AND m.visible = 1 AND m.created > ?",
            [$userId, time() - 86400]
        )->get();

        return (int) ($result[0]->total ?? 0);
    }

    // ---------- attachments ----------

    public function uploadsToday(int $userId){
        global $db;

        return $db->table("ai_attachments")->where("userid", $userId)->where("created", ">", time() - 86400)->count();
    }

    // checks an uploaded file really is an image and stores it outside the web root
    public function storeUpload(int $userId, array $file){
        if(($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
            throw new \InvalidArgumentException("The upload didn't go through.");
        }

        if($file["size"] > self::MAX_IMAGE_BYTES){
            throw new \InvalidArgumentException("Images have to be under 5MB.");
        }

        $info = \watrlabs\watrkit\uploads::imageInfo($file["tmp_name"]);
        if(!$info || !in_array($info["mime"], self::ALLOWED_MIME, true)){
            throw new \InvalidArgumentException("That isn't a JPEG, PNG, GIF or WebP image.");
        }

        $ext = ["image/jpeg"=>"jpg", "image/png"=>"png", "image/gif"=>"gif", "image/webp"=>"webp"][$info["mime"]];
        $dir = self::attachmentDir($userId);

        if(!is_dir($dir)){
            mkdir($dir, 0750, true);
        }

        $name = bin2hex(random_bytes(16)) . "." . $ext;

        if(!move_uploaded_file($file["tmp_name"], $dir . "/" . $name)){
            throw new \RuntimeException("Couldn't save the image.");
        }

        global $db;
        $id = $db->table("ai_attachments")->insert([
            "userid"=>$userId,
            "path"=>$userId . "/" . $name,
            "mime"=>$info["mime"],
            "size"=>(int) $file["size"],
            "created"=>time(),
        ]);

        return ["id"=>(int) $id, "mime"=>$info["mime"]];
    }

    public function attachment(int $id, int $userId){
        global $db;

        return $db->table("ai_attachments")->where("id", $id)->where("userid", $userId)->first();
    }

    public function attachmentFile($attachment){
        return __DIR__ . "/../../../storage/private/ai/" . $attachment->path;
    }

    public function deleteAttachment(int $id, int $userId){
        global $db;

        $attachment = $this->attachment($id, $userId);
        if(!$attachment){
            return;
        }

        $file = $this->attachmentFile($attachment);
        if(is_file($file)){
            unlink($file);
        }

        $db->table("ai_attachments")->where("id", $id)->delete();
    }
}
