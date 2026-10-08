<?php

namespace watrlabs\games;

use watrlabs\social\chat;
use watrlabs\watrkit\settings;

// comments under games and apps. uses chat's word filter and report reasons, hides banned people, and hides
// people you've blocked (and people who blocked you) from each other
class comments {

    const MAX_LENGTH = 500;
    const PER_PAGE = 20;
    const PER_MINUTE = 3;
    const PER_DAY = 50;

    static function enabled(){
        return settings::bool("comments_enabled", true);
    }

    // newest first. $before is the id of the oldest comment already shown, for "show older"
    public function list(int $gameId, $viewer, ?int $before = null){
        global $db;

        $sql = "SELECT c.id, c.userid, c.body, c.created, u.username, u.avatar
                FROM game_comments c INNER JOIN users u ON u.id = c.userid
                WHERE c.gameid = ? AND c.deleted = 0 AND u.banned = 0";
        $params = [$gameId];

        if($before){
            $sql .= " AND c.id < ?";
            $params[] = $before;
        }

        if($viewer){
            $sql .= " AND c.userid NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
                      AND c.userid NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)";
            $params[] = (int) $viewer->id;
            $params[] = (int) $viewer->id;
        }

        $rows = $db->query($sql . " ORDER BY c.id DESC LIMIT " . (self::PER_PAGE + 1), $params)->get();

        return [
            "comments"=>array_map(fn($row) => self::shape($row, $viewer), array_slice($rows, 0, self::PER_PAGE)),
            "more"=>count($rows) > self::PER_PAGE,
        ];
    }

    public function count(int $gameId){
        global $db;

        return (int) $db->query(
            "SELECT COUNT(*) AS n FROM game_comments c INNER JOIN users u ON u.id = c.userid WHERE c.gameid = ? AND c.deleted = 0 AND u.banned = 0",
            [$gameId]
        )->first()->n;
    }

    public function add($user, int $gameId, string $body){
        global $db;

        $body = trim(str_replace("\r\n", "\n", $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);

        if($body === ""){
            throw new \InvalidArgumentException("Type something first.");
        }
        if(mb_strlen($body) > self::MAX_LENGTH){
            throw new \InvalidArgumentException("Comments can be " . self::MAX_LENGTH . " characters at most.");
        }

        $userId = (int) $user->id;
        $recent = $db->table("game_comments")->where("userid", $userId)->where("created", ">", time() - 60)->count();
        if($recent >= self::PER_MINUTE){
            throw new \InvalidArgumentException("Slow down a little, wait a minute before commenting again.");
        }
        $today = $db->table("game_comments")->where("userid", $userId)->where("created", ">", time() - 86400)->count();
        if($today >= self::PER_DAY){
            throw new \InvalidArgumentException("That's " . self::PER_DAY . " comments today, try again tomorrow.");
        }

        $id = $db->table("game_comments")->insert([
            "gameid"=>$gameId,
            "userid"=>$userId,
            "body"=>chat::filter($body),
            "created"=>time(),
        ]);

        $row = $db->query(
            "SELECT c.id, c.userid, c.body, c.created, u.username, u.avatar FROM game_comments c INNER JOIN users u ON u.id = c.userid WHERE c.id = ?",
            [$id]
        )->first();

        \watrlabs\social\activity::log($userId, "comment", $gameId);
        \watrlabs\social\achievements::award($userId, "first_comment");
        $this->notifyMentions($user, $gameId, $row->body);

        return self::shape($row, $user);
    }

    // "@sam" pings sam, unless one of you blocked the other. a few per comment at most
    private function notifyMentions($user, int $gameId, string $body){
        global $db;

        if(!preg_match_all('/(?<![\w@])@([a-zA-Z0-9_]{3,20})/', $body, $matches)){
            return;
        }

        $game = $db->table("games")->select(["name", "type"])->where("id", $gameId)->first();
        $friends = new \watrlabs\social\friends();

        foreach(array_slice(array_unique(array_map("strtolower", $matches[1])), 0, 5) as $name){
            $target = $db->table("users")->select(["id", "banned"])->where("username", $name)->first();
            if(!$target || $target->banned || (int) $target->id === (int) $user->id){
                continue;
            }
            if(in_array($friends->relation((int) $user->id, (int) $target->id), ["blocked", "blockedby"], true)){
                continue;
            }
            \watrlabs\social\notifications::send((int) $target->id, "mention", (int) $user->id, [
                "game"=>$game->name ?? "a game", "gameid"=>$gameId, "type"=>$game->type ?? "game",
            ]);
        }
    }

    // your own comments, or anyone's if you're an admin
    public function delete($user, int $id): bool {
        global $db;

        $comment = $db->table("game_comments")->where("id", $id)->where("deleted", 0)->first();
        if(!$comment || ((int) $comment->userid !== (int) $user->id && empty($user->admin))){
            return false;
        }

        $db->table("game_comments")->where("id", $id)->update(["deleted"=>1]);
        return true;
    }

    public function report(int $me, int $id, string $reason, string $details){
        global $db;

        $comment = $db->table("game_comments")->where("id", $id)->where("deleted", 0)->first();
        if(!$comment || (int) $comment->userid === $me){
            throw new \InvalidArgumentException("You can't report that comment.");
        }

        if(!isset(chat::REPORT_REASONS[$reason])){
            throw new \InvalidArgumentException("Pick a reason.");
        }

        if($db->table("comment_reports")->where("comment_id", $id)->where("reporter_id", $me)->first()){
            return; // already reported
        }

        $db->table("comment_reports")->insert([
            "comment_id"=>$id,
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
            return $db->table("comment_reports")->where("status", "open")->count();
        } catch (\Throwable $e) {
            return 0; // before migrations
        }
    }

    // what the browser gets
    private static function shape($row, $viewer){
        $mine = $viewer && (int) $viewer->id === (int) $row->userid;

        return [
            "id"=>(int) $row->id,
            "body"=>$row->body,
            "created"=>(int) $row->created,
            "user"=>["username"=>$row->username, "avatar"=>$row->avatar],
            "mine"=>$mine,
            "canDelete"=>$mine || ($viewer && !empty($viewer->admin)),
            "canReport"=>$viewer && !$mine,
        ];
    }
}
