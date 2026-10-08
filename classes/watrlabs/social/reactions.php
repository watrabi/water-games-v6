<?php

namespace watrlabs\social;

use watrlabs\users\moderation;

// emoji reactions on DMs, group messages and comments. a fixed set, so there's no free text to moderate
class reactions {

    const EMOJI = ["👍", "❤️", "😂", "😮", "😢", "🔥", "👀", "🎉"];
    const KINDS = ["dm", "group", "comment"];

    // can $userId see (and so react to) this item? returns who else should hear about it, or null
    private static function audience(string $kind, int $itemId, int $userId): ?array {
        global $db;

        if($kind === "dm"){
            $message = $db->table("chat_messages")->where("id", $itemId)->where("deleted", 0)->first();
            if(!$message || !in_array($userId, [(int) $message->sender_id, (int) $message->recipient_id], true)){
                return null;
            }
            $other = (int) $message->sender_id === $userId ? (int) $message->recipient_id : (int) $message->sender_id;
            if(in_array((new friends())->relation($userId, $other), ["blocked", "blockedby"], true)){
                return null;
            }
            return [(int) $message->sender_id, (int) $message->recipient_id];
        }

        if($kind === "group"){
            $message = $db->table("chat_group_messages")->where("id", $itemId)->where("deleted", 0)->first();
            $groups = new groups();
            if(!$message || !$message->sender_id || !$groups->isMember((int) $message->groupid, $userId)){
                return null;
            }
            return $groups->memberIds((int) $message->groupid);
        }

        if($kind === "comment"){
            $comment = $db->query(
                "SELECT c.id FROM game_comments c INNER JOIN users u ON u.id = c.userid WHERE c.id = ? AND c.deleted = 0 AND u.banned = 0",
                [$itemId]
            )->first();
            return $comment ? [] : null;
        }

        return null;
    }

    // adds the reaction, or takes it away if you'd already done that one. returns the item's new summary
    public function toggle($user, string $kind, int $itemId, string $emoji): array {
        global $db;

        moderation::requireUnmuted($user);

        if(!in_array($kind, self::KINDS, true) || !in_array($emoji, self::EMOJI, true)){
            throw new \InvalidArgumentException("You can't react with that.");
        }

        $userId = (int) $user->id;
        $audience = self::audience($kind, $itemId, $userId);
        if($audience === null){
            throw new \InvalidArgumentException("That message isn't there anymore.");
        }

        $existing = $db->table("reactions")->where("kind", $kind)->where("item_id", $itemId)->where("userid", $userId)->where("emoji", $emoji)->first();
        if($existing){
            $db->table("reactions")->where("id", $existing->id)->delete();
        } else {
            // a handful each, so nobody fills a message with every emoji
            if($db->table("reactions")->where("kind", $kind)->where("item_id", $itemId)->where("userid", $userId)->count() >= 3){
                throw new \InvalidArgumentException("Three reactions each is plenty.");
            }
            $db->query("INSERT IGNORE INTO reactions (kind, item_id, userid, emoji, created) VALUES (?, ?, ?, ?, ?)", [$kind, $itemId, $userId, $emoji, time()]);
        }

        $summary = self::summaries($kind, [$itemId])[$itemId] ?? [];
        if($audience){
            realtime::publish($audience, ["type"=>"reaction", "kind"=>$kind, "id"=>$itemId, "reactions"=>$summary]);
        }

        return $summary;
    }

    // for a page of items: id => [{emoji, count, users: [ids]}], in the fixed emoji order
    static function summaries(string $kind, array $ids): array {
        global $db;

        $ids = array_values(array_unique(array_filter(array_map("intval", $ids))));
        if(!$ids){
            return [];
        }

        $grouped = [];
        foreach($db->query(
            "SELECT item_id, emoji, userid FROM reactions WHERE kind = ? AND item_id IN (" . implode(",", $ids) . ") ORDER BY id",
            [$kind]
        )->get() as $row){
            $grouped[(int) $row->item_id][$row->emoji][] = (int) $row->userid;
        }

        $out = [];
        foreach($grouped as $itemId => $byEmoji){
            foreach(self::EMOJI as $emoji){
                if(!empty($byEmoji[$emoji])){
                    $out[$itemId][] = ["emoji"=>$emoji, "count"=>count($byEmoji[$emoji]), "users"=>$byEmoji[$emoji]];
                }
            }
        }
        return $out;
    }

    // adds "reactions" to each shaped message/comment in a list
    static function attach(string $kind, array $items): array {
        $summaries = self::summaries($kind, array_map(fn($i) => $i["id"], $items));
        foreach($items as &$item){
            $item["reactions"] = $summaries[$item["id"]] ?? [];
        }
        return $items;
    }
}
