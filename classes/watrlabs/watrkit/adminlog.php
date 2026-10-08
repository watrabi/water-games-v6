<?php

namespace watrlabs\watrkit;

// who did what in the admin panel
class adminlog {

    static function add(string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null){
        global $db;
        global $currentuser;

        if(!$currentuser){
            return;
        }

        try {
            $db->table("admin_log")->insert([
                "admin_id"=>(int) $currentuser->id,
                "action"=>$action,
                "target_type"=>$targetType,
                "target_id"=>$targetId,
                "details"=>$details !== null ? mb_substr($details, 0, 500) : null,
                "created"=>time(),
            ]);
        } catch (\Throwable $e) {
            // the log never gets in the way of the action itself
        }
    }

    static function page(int $page, ?string $action = null, int $perPage = 50){
        global $db;

        $where = $action ? "WHERE l.action = ?" : "";
        $params = $action ? [$action] : [];

        $total = (int) $db->query("SELECT COUNT(*) AS n FROM admin_log l $where", $params)->first()->n;
        $rows = $db->query(
            "SELECT l.*, u.username AS admin_name,
                    CASE WHEN l.target_type = 'user' THEN (SELECT username FROM users WHERE id = l.target_id)
                         WHEN l.target_type = 'game' THEN (SELECT name FROM games WHERE id = l.target_id)
                         WHEN l.target_type = 'track' THEN (SELECT title FROM tracks WHERE id = l.target_id) END AS target_name
             FROM admin_log l LEFT JOIN users u ON u.id = l.admin_id $where
             ORDER BY l.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $params
        )->get();

        return ["rows"=>$rows, "total"=>$total, "pages"=>max(1, (int) ceil($total / $perPage))];
    }

    static function actions(){
        global $db;

        return array_map(fn($r) => $r->action, $db->query("SELECT DISTINCT action FROM admin_log ORDER BY action")->get());
    }
}
