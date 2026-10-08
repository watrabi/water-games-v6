<?php
use watrlabs\watrkit\csrf;
use watrlabs\watrkit\settings;
use watrlabs\watrkit\themes;
use watrlabs\watrkit\uploads;
use watrlabs\ai\config as aiconfig;
use watrlabs\authentication\sessions;
use watrlabs\encryption;

global $router; // IMPORTANT: KEEP THIS HERE!

// non admins get the normal 404, the panel doesn't advertise itself
function requireAdmin(){
    global $currentuser;
    global $router;

    if(!$currentuser || empty($currentuser->admin)){
        $router->return_status(404);
        exit;
    }
}

function requireAdminPost(){
    requireAdmin();

    if(!csrf::valid($_POST["csrf"] ?? null)){
        http_response_code(400);
        exit("That form was out of date (you might have signed in again since loading it). Go back, refresh and try again.");
    }
}

// short messages shown after a redirect, keyed so the url can't inject text
const ADMIN_MESSAGES = [
    "saved"=>"Saved.",
    "created"=>"Added.",
    "deleted"=>"Deleted.",
    "banned"=>"Banned. They've been signed out.",
    "unbanned"=>"Unbanned.",
    "admin"=>"They're an admin now.",
    "unadmin"=>"They're not an admin anymore.",
    "signedout"=>"Signed out of every device.",
    "dismissed"=>"Report dismissed.",
    "removed"=>"Message removed.",
    "removedbanned"=>"Message removed and the sender is banned.",
];

function adminRedirect(string $url, ?string $message = null){
    if($message){
        // the message goes in the query, which has to come before any #anchor
        $parts = explode("#", $url, 2);
        $url = $parts[0] . (str_contains($parts[0], "?") ? "&" : "?") . "msg=" . $message . (isset($parts[1]) ? "#" . $parts[1] : "");
    }
    header("Location: " . $url);
    exit;
}

function adminRender(string $view, string $section, array $data = []){
    global $twig;

    echo $twig->render("admin/$view.twig", array_merge([
        "section"=>$section,
        "openReports"=>\watrlabs\social\chat::openReports(),
        "flash"=>ADMIN_MESSAGES[$_GET["msg"] ?? ""] ?? null,
    ], $data));
}

function adminPage(){
    return max(1, (int) ($_GET["page"] ?? 1));
}

// "3:25" or "205" -> 205
function parseDuration($value){
    $value = trim((string) $value);
    if($value === ""){
        return null;
    }
    if(preg_match('/^(\d+):([0-5]?\d)$/', $value, $m)){
        return (int) $m[1] * 60 + (int) $m[2];
    }
    return ctype_digit($value) ? (int) $value : null;
}

$router->group('/admin', function($router){

    // ---------- dashboard ----------

    $router->get("/", function(){
        global $db;

        $one = fn($sql, $bindings = []) => (int) ($db->query($sql, $bindings)->get()[0]->total ?? 0);

        $stats = [
            "users"=>$one("SELECT COUNT(*) AS total FROM users"),
            "newUsers"=>$one("SELECT COUNT(*) AS total FROM users WHERE registered > ?", [time() - 7 * 86400]),
            "games"=>$one("SELECT COUNT(*) AS total FROM games WHERE type = 'game'"),
            "apps"=>$one("SELECT COUNT(*) AS total FROM games WHERE type = 'app'"),
            "plays"=>$one("SELECT COALESCE(SUM(plays), 0) AS total FROM games"),
            "tracks"=>$one("SELECT COUNT(*) AS total FROM tracks"),
            "listens"=>$one("SELECT COALESCE(SUM(plays), 0) AS total FROM tracks"),
            "favorites"=>$one("SELECT COUNT(*) AS total FROM favorites"),
        ];

        $ai = null;
        try {
            $ai = [
                "chats"=>$one("SELECT COUNT(*) AS total FROM ai_chats"),
                "today"=>$one("SELECT COUNT(*) AS total FROM ai_messages WHERE role = 'user' AND visible = 1 AND created > ?", [time() - 86400]),
            ];
        } catch (\Throwable $e) {}

        adminRender("dashboard", "dashboard", [
            "stats"=>$stats,
            "ai"=>$ai,
            "aiEnabledNow"=>aiconfig::enabled(),
            "recentUsers"=>$db->table("users")->select(["id", "username", "registered"])->orderBy("id", "DESC")->limit(6)->get(),
            "topGames"=>$db->table("games")->select(["id", "name", "plays", "type"])->orderBy("plays", "DESC")->limit(6)->get(),
            "season"=>themes::currentSeason(),
            "siteTheme"=>themes::get(themes::siteTheme()),
        ]);
    });

    // ---------- games + apps ----------

    $router->get("/games", function(){
        global $db;

        $type = ($_GET["type"] ?? "game") === "app" ? "app" : "game";
        $search = trim($_GET["q"] ?? "");
        $page = adminPage();

        $query = $db->table("games")->where("type", $type);
        if($search !== ""){
            $query->where("name", "LIKE", "%" . addcslashes($search, "%_\\") . "%");
        }

        $total = $query->count();
        $rows = $query->orderBy("id", "DESC")->limit(50)->offset(($page - 1) * 50)->get();

        adminRender("games", $type === "app" ? "apps" : "games", [
            "type"=>$type,
            "rows"=>$rows,
            "search"=>$search,
            "page"=>$page,
            "pages"=>max(1, (int) ceil($total / 50)),
            "total"=>$total,
        ]);
    });

    $router->get("/games/new", function(){
        $type = ($_GET["type"] ?? "game") === "app" ? "app" : "game";
        adminRender("game-form", $type === "app" ? "apps" : "games", ["game"=>(object) ["id"=>null, "type"=>$type, "name"=>"", "description"=>"", "gamePath"=>"", "gameIcon"=>"", "plays"=>0]]);
    });

    $router->get("/games/{id}", function($id){
        global $db;
        global $router;

        $game = ctype_digit($id) ? $db->table("games")->where("id", (int) $id)->first() : null;
        if(!$game){
            return $router->return_status(404);
        }

        adminRender("game-form", $game->type === "app" ? "apps" : "games", ["game"=>$game]);
    });

    $router->post("/games/save", function(){
        global $db;
        requireAdminPost();

        $id = (int) ($_POST["id"] ?? 0);
        $existing = $id ? $db->table("games")->where("id", $id)->first() : null;

        $game = (object) [
            "id"=>$existing->id ?? null,
            "type"=>($_POST["type"] ?? "game") === "app" ? "app" : "game",
            "name"=>trim($_POST["name"] ?? ""),
            "description"=>trim($_POST["description"] ?? ""),
            "gamePath"=>trim($_POST["gamePath"] ?? ""),
            "gameIcon"=>trim($_POST["gameIcon"] ?? ""),
            "plays"=>$existing->plays ?? 0,
        ];

        $error = null;

        try {
            if(uploads::sent("iconFile")){
                $game->gameIcon = uploads::store("iconFile", "icons");
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if(!$error && $game->name === ""){
            $error = "Give it a name.";
        } elseif(!$error && $game->gamePath === ""){
            $error = "It needs a game path, the URL the player loads (like /game-files/slope/index.html).";
        }

        if($error){
            return adminRender("game-form", $game->type === "app" ? "apps" : "games", ["game"=>$game, "error"=>$error]);
        }

        $values = [
            "type"=>$game->type,
            "name"=>$game->name,
            "description"=>$game->description,
            "gamePath"=>$game->gamePath,
            "gameIcon"=>$game->gameIcon,
        ];

        if(!empty($_POST["resetPlays"])){
            $values["plays"] = 0;
        }

        if($existing){
            if($existing->gameIcon !== $game->gameIcon){
                uploads::delete($existing->gameIcon);
            }
            $db->table("games")->where("id", $existing->id)->update($values);
            adminRedirect("/admin/games/" . $existing->id, "saved");
        }

        $values["plays"] = 0;
        $newId = $db->table("games")->insert($values);
        adminRedirect("/admin/games/" . $newId, "created");
    });

    $router->post("/games/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $game = $db->table("games")->where("id", (int) $id)->first();
        if($game){
            uploads::delete($game->gameIcon);
            $db->table("favorites")->where("gameid", $game->id)->delete();
            $db->table("games")->where("id", $game->id)->delete();
        }

        adminRedirect("/admin/games?type=" . ($game->type ?? "game"), "deleted");
    });

    // ---------- music ----------

    $router->get("/music", function(){
        global $db;

        $search = trim($_GET["q"] ?? "");
        $page = adminPage();

        $query = $db->table("tracks")->leftJoin("users", "users.id", "=", "tracks.uploader_id");
        if($search !== ""){
            $like = "%" . addcslashes($search, "%_\\") . "%";
            $query->where(function($q) use ($like){
                $q->where("tracks.title", "LIKE", $like)->orWhere("tracks.artist", "LIKE", $like)->orWhere("users.username", "LIKE", $like);
            });
        }

        $total = $query->count();

        adminRender("music", "music", [
            "rows"=>$query->select(["tracks.*", "users.username"])->orderBy("tracks.id", "DESC")->limit(50)->offset(($page - 1) * 50)->get(),
            "search"=>$search,
            "page"=>$page,
            "pages"=>max(1, (int) ceil($total / 50)),
            "total"=>$total,
        ]);
    });

    $router->get("/music/new", function(){
        adminRender("track-form", "music", ["track"=>(object) ["id"=>null, "title"=>"", "artist"=>"", "filePath"=>"", "coverPath"=>"", "duration"=>null, "plays"=>0]]);
    });

    $router->get("/music/{id}", function($id){
        global $db;
        global $router;

        $track = ctype_digit($id) ? $db->table("tracks")->where("id", (int) $id)->first() : null;
        if(!$track){
            return $router->return_status(404);
        }

        adminRender("track-form", "music", ["track"=>$track]);
    });

    $router->post("/music/save", function(){
        global $db;
        requireAdminPost();

        $id = (int) ($_POST["id"] ?? 0);
        $existing = $id ? $db->table("tracks")->where("id", $id)->first() : null;

        $track = (object) [
            "id"=>$existing->id ?? null,
            "title"=>trim($_POST["title"] ?? ""),
            "artist"=>trim($_POST["artist"] ?? ""),
            "filePath"=>trim($_POST["filePath"] ?? ""),
            "coverPath"=>trim($_POST["coverPath"] ?? ""),
            "duration"=>parseDuration($_POST["duration"] ?? ""),
            "plays"=>$existing->plays ?? 0,
        ];

        $error = null;

        try {
            if(uploads::sent("audioFile")){
                $track->filePath = uploads::store("audioFile", "music");
            }
            if(uploads::sent("coverFile")){
                $track->coverPath = uploads::store("coverFile", "covers");
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if(!$error && $track->title === ""){
            $error = "Give it a title.";
        } elseif(!$error && $track->filePath === ""){
            $error = "It needs an audio file, upload one or paste its URL.";
        }

        if($error){
            return adminRender("track-form", "music", ["track"=>$track, "error"=>$error]);
        }

        $values = [
            "title"=>$track->title,
            "artist"=>$track->artist !== "" ? $track->artist : null,
            "filePath"=>$track->filePath,
            "coverPath"=>$track->coverPath !== "" ? $track->coverPath : null,
            "duration"=>$track->duration,
        ];

        if($existing){
            if($existing->filePath !== $track->filePath){
                uploads::delete($existing->filePath);
            }
            if($existing->coverPath && $existing->coverPath !== $track->coverPath){
                uploads::delete($existing->coverPath);
            }
            // a different song now, so look its lyrics up again on the next play
            if($existing->title !== $values["title"] || $existing->artist !== $values["artist"] || $existing->duration != $values["duration"]){
                $values += ["lyrics"=>null, "lyricsSynced"=>0, "lyricsChecked"=>null];
            }
            $db->table("tracks")->where("id", $existing->id)->update($values);
            adminRedirect("/admin/music/" . $existing->id, "saved");
        }

        $values["plays"] = 0;
        $values["created"] = time();
        $newId = $db->table("tracks")->insert($values);
        adminRedirect("/admin/music/" . $newId, "created");
    });

    $router->post("/music/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $track = $db->table("tracks")->where("id", (int) $id)->first();
        if($track){
            uploads::delete($track->filePath);
            uploads::delete($track->coverPath);
            $db->table("tracks")->where("id", $track->id)->delete();
        }

        adminRedirect("/admin/music", "deleted");
    });

    // ---------- users ----------

    $router->get("/users", function(){
        global $db;

        $search = trim($_GET["q"] ?? "");
        $filter = $_GET["filter"] ?? "all";
        $page = adminPage();

        $query = $db->table("users")->select(["id", "username", "email", "registered", "admin", "banned"]);
        if($search !== ""){
            $query->where("username", "LIKE", "%" . addcslashes($search, "%_\\") . "%");
        }
        if($filter === "admins"){
            $query->where("admin", 1);
        } elseif($filter === "banned"){
            $query->where("banned", 1);
        } else {
            $filter = "all";
        }

        $total = $query->count();

        adminRender("users", "users", [
            "rows"=>$query->orderBy("id", "DESC")->limit(50)->offset(($page - 1) * 50)->get(),
            "search"=>$search,
            "filter"=>$filter,
            "page"=>$page,
            "pages"=>max(1, (int) ceil($total / 50)),
            "total"=>$total,
        ]);
    });

    $router->post("/users/{id}/action", function($id){
        global $db;
        global $currentuser;
        requireAdminPost();

        $user = $db->table("users")->where("id", (int) $id)->first();
        $action = $_POST["action"] ?? "";
        $back = "/admin/users" . (isset($_POST["back"]) && str_starts_with($_POST["back"], "?") ? $_POST["back"] : "");

        if(!$user){
            adminRedirect($back);
        }

        // no locking yourself out by accident
        if((int) $user->id === (int) $currentuser->id && in_array($action, ["ban", "unadmin"], true)){
            adminRedirect($back);
        }

        $sessions = new sessions();

        switch($action){
            case "ban":
                $db->table("users")->where("id", $user->id)->update(["banned"=>1]);
                $db->table("sessions")->where("userid", $user->id)->delete();
                adminRedirect($back, "banned");
            case "unban":
                $db->table("users")->where("id", $user->id)->update(["banned"=>0]);
                adminRedirect($back, "unbanned");
            case "admin":
                $db->table("users")->where("id", $user->id)->update(["admin"=>1]);
                adminRedirect($back, "admin");
            case "unadmin":
                $db->table("users")->where("id", $user->id)->update(["admin"=>0]);
                adminRedirect($back, "unadmin");
            case "signout":
                $db->table("sessions")->where("userid", $user->id)->delete();
                adminRedirect($back, "signedout");
        }

        adminRedirect($back);
    });

    // ---------- site settings + themes ----------

    $router->get("/settings", function(){
        adminRender("settings", "settings", [
            "s"=>[
                "theme_default"=>settings::get("theme_default", "deep"),
                "seasonal_mode"=>settings::get("seasonal_mode", "auto"),
                "seasons_enabled"=>themes::enabledSeasons(),
                "seasonal_greeting"=>settings::bool("seasonal_greeting", true),
                "announcement"=>settings::get("announcement", ""),
                "chat_filter"=>settings::get("chat_filter", ""),
                "comments_enabled"=>settings::bool("comments_enabled", true),
            ],
            "currentSeason"=>themes::currentSeason(),
            "siteTheme"=>themes::get(themes::siteTheme()),
        ]);
    });

    $router->post("/settings", function(){
        requireAdminPost();

        $default = $_POST["theme_default"] ?? "deep";
        settings::set("theme_default", isset(themes::BASE[$default]) ? $default : "deep");

        $mode = $_POST["seasonal_mode"] ?? "auto";
        settings::set("seasonal_mode", ($mode === "auto" || $mode === "off" || isset(themes::SEASONAL[$mode])) ? $mode : "auto");

        $seasons = array_values(array_filter((array) ($_POST["seasons"] ?? []), fn($id) => isset(themes::SEASONAL[$id])));
        settings::set("seasons_enabled", implode(",", $seasons));

        settings::set("seasonal_greeting", !empty($_POST["seasonal_greeting"]));
        settings::set("announcement", mb_substr(trim(preg_replace('/\s+/', ' ', $_POST["announcement"] ?? "")), 0, 300));
        settings::set("chat_filter", mb_substr(trim($_POST["chat_filter"] ?? ""), 0, 20000));
        settings::set("comments_enabled", !empty($_POST["comments_enabled"]));

        adminRedirect("/admin/settings", "saved");
    });

    // ---------- chat reports ----------

    $router->get("/reports", function(){
        global $db;

        $status = $_GET["status"] ?? "open";
        $status = in_array($status, ["open", "dismissed", "actioned"], true) ? $status : "open";
        $page = adminPage();

        $openChat = $db->table("chat_reports")->where("status", "open")->count();
        $openComments = \watrlabs\games\comments::openReports();

        // game comment reports, same statuses and actions as chat ones
        if(($_GET["kind"] ?? "") === "comments"){
            $total = $db->table("comment_reports")->where("status", $status)->count();
            $rows = $db->query(
                "SELECT r.*, c.userid AS sender_id, c.body, c.created AS sent, c.deleted, c.gameid, g.name AS game_name, g.type AS game_type,
                        s.username AS sender_name, s.banned AS sender_banned, rp.username AS reporter_name, h.username AS handler_name
                 FROM comment_reports r
                 INNER JOIN game_comments c ON c.id = r.comment_id
                 LEFT JOIN games g ON g.id = c.gameid
                 LEFT JOIN users s ON s.id = c.userid
                 LEFT JOIN users rp ON rp.id = r.reporter_id
                 LEFT JOIN users h ON h.id = r.handled_by
                 WHERE r.status = ? ORDER BY r.created " . ($status === "open" ? "ASC" : "DESC") . " LIMIT 25 OFFSET " . (($page - 1) * 25),
                [$status]
            )->get();

            adminRender("reports", "reports", [
                "kind"=>"comments",
                "rows"=>$rows,
                "status"=>$status,
                "reasons"=>\watrlabs\social\chat::REPORT_REASONS,
                "page"=>$page,
                "pages"=>max(1, (int) ceil($total / 25)),
                "total"=>$total,
                "openChat"=>$openChat,
                "openComments"=>$openComments,
            ]);
            return;
        }

        $total = $db->table("chat_reports")->where("status", $status)->count();
        $rows = $db->query(
            "SELECT r.*, m.sender_id, m.recipient_id, m.body, m.image_id, m.created AS sent, m.deleted,
                    s.username AS sender_name, s.banned AS sender_banned, rp.username AS reporter_name, h.username AS handler_name
             FROM chat_reports r
             INNER JOIN chat_messages m ON m.id = r.message_id
             LEFT JOIN users s ON s.id = m.sender_id
             LEFT JOIN users rp ON rp.id = r.reporter_id
             LEFT JOIN users h ON h.id = r.handled_by
             WHERE r.status = ? ORDER BY r.created " . ($status === "open" ? "ASC" : "DESC") . " LIMIT 25 OFFSET " . (($page - 1) * 25),
            [$status]
        )->get();

        // the few messages either side, so the moderator sees what it was in reply to
        foreach($rows as $row){
            $around = $db->query(
                "SELECT m.id, m.sender_id, m.body, m.image_id, m.created, m.deleted, u.username FROM chat_messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
                 AND m.id BETWEEN ? AND ? ORDER BY m.id ASC",
                [$row->sender_id, $row->recipient_id, $row->recipient_id, $row->sender_id, $row->message_id - 400, $row->message_id + 400]
            )->get();

            $index = array_search((int) $row->message_id, array_map(fn($m) => (int) $m->id, $around));
            $row->context = $index === false ? [] : array_slice($around, max(0, $index - 5), 11);
        }

        adminRender("reports", "reports", [
            "kind"=>"chat",
            "openChat"=>$openChat,
            "openComments"=>$openComments,
            "rows"=>$rows,
            "status"=>$status,
            "reasons"=>\watrlabs\social\chat::REPORT_REASONS,
            "page"=>$page,
            "pages"=>max(1, (int) ceil($total / 25)),
            "total"=>$total,
        ]);
    });

    $router->post("/reports/comments/{id}/action", function($id){
        global $db;
        global $currentuser;
        requireAdminPost();

        $report = $db->table("comment_reports")->where("id", (int) $id)->first();
        $action = $_POST["action"] ?? "";
        $back = "/admin/reports?kind=comments";

        if(!$report){
            adminRedirect($back);
        }

        $comment = $db->table("game_comments")->where("id", $report->comment_id)->first();
        $handled = ["handled_by"=>$currentuser->id, "handled_at"=>time()];

        if($action === "dismiss"){
            $db->table("comment_reports")->where("id", $report->id)->update(["status"=>"dismissed", "action"=>"dismissed"] + $handled);
            adminRedirect($back, "dismissed");
        }

        if(($action === "remove" || $action === "ban") && $comment){
            $db->table("game_comments")->where("id", $comment->id)->update(["deleted"=>1]);

            // every open report on this comment is settled by the same decision
            $db->table("comment_reports")->where("comment_id", $comment->id)->where("status", "open")
                ->update(["status"=>"actioned", "action"=>$action === "ban" ? "removed, author banned" : "removed"] + $handled);

            if($action === "ban" && (int) $comment->userid !== (int) $currentuser->id){
                $db->table("users")->where("id", $comment->userid)->update(["banned"=>1]);
                $db->table("sessions")->where("userid", $comment->userid)->delete();
                adminRedirect($back, "removedbanned");
            }

            adminRedirect($back, "removed");
        }

        adminRedirect($back);
    });

    $router->post("/reports/{id}/action", function($id){
        global $db;
        global $currentuser;
        requireAdminPost();

        $report = $db->table("chat_reports")->where("id", (int) $id)->first();
        $action = $_POST["action"] ?? "";

        if(!$report){
            adminRedirect("/admin/reports");
        }

        $message = $db->table("chat_messages")->where("id", $report->message_id)->first();
        $handled = ["handled_by"=>$currentuser->id, "handled_at"=>time()];

        if($action === "dismiss"){
            $db->table("chat_reports")->where("id", $report->id)->update(["status"=>"dismissed", "action"=>"dismissed"] + $handled);
            adminRedirect("/admin/reports", "dismissed");
        }

        if(($action === "remove" || $action === "ban") && $message){
            $db->table("chat_messages")->where("id", $message->id)->update(["deleted"=>1]);
            \watrlabs\social\realtime::publish([$message->sender_id, $message->recipient_id], ["type"=>"deleted", "id"=>(int) $message->id]);

            // every open report on this message is settled by the same decision
            $db->table("chat_reports")->where("message_id", $message->id)->where("status", "open")
                ->update(["status"=>"actioned", "action"=>$action === "ban" ? "removed, sender banned" : "removed"] + $handled);

            if($action === "ban" && (int) $message->sender_id !== (int) $currentuser->id){
                $db->table("users")->where("id", $message->sender_id)->update(["banned"=>1]);
                $db->table("sessions")->where("userid", $message->sender_id)->delete();
                adminRedirect("/admin/reports", "removedbanned");
            }

            adminRedirect("/admin/reports", "removed");
        }

        adminRedirect("/admin/reports");
    });

    // ---------- ai: settings, providers, models ----------

    $router->get("/ai", function(){
        $providers = aiconfig::providers();

        $allModels = [];
        foreach($providers as $provider){
            foreach($provider["models"] as $model){
                if($provider["enabled"] && $model["enabled"]){
                    $allModels[] = ["id"=>$model["id"], "label"=>$model["label"] . " · " . $provider["name"]];
                }
            }
        }

        $default = aiconfig::defaultModel();

        adminRender("ai", "ai", [
            "providers"=>$providers,
            "allModels"=>$allModels,
            "s"=>[
                "enabled"=>filter_var(aiconfig::setting("ai_enabled", "AI_ENABLED", false), FILTER_VALIDATE_BOOLEAN),
                "daily_limit"=>aiconfig::dailyLimit(),
                "daily_uploads"=>aiconfig::dailyUploads(),
                "tools"=>aiconfig::toolsPicked(),
                "exa_key"=>(bool) aiconfig::exaKey(),
                "exa_key_stored"=>(bool) settings::get("ai_exa_key"),
                "system_prompt"=>aiconfig::systemExtra(),
                "default_model"=>$default["id"] ?? null,
            ],
            "toolNames"=>aiconfig::TOOL_NAMES,
            "aiLive"=>aiconfig::enabled(),
        ]);
    });

    $router->post("/ai", function(){
        requireAdminPost();

        settings::set("ai_enabled", !empty($_POST["enabled"]));
        settings::set("ai_daily_limit", max(0, (int) ($_POST["daily_limit"] ?? 100)));
        settings::set("ai_daily_uploads", max(0, (int) ($_POST["daily_uploads"] ?? 50)));

        $tools = array_values(array_filter((array) ($_POST["tools"] ?? []), fn($t) => isset(aiconfig::TOOL_NAMES[$t])));
        // an empty string would fall back to .env, so "none" is stored as a comma
        settings::set("ai_tools", $tools ? implode(",", $tools) : ",");

        settings::set("ai_name", mb_substr(trim($_POST["ai_name"] ?? ""), 0, 40));
        settings::set("ai_system_prompt", mb_substr(trim($_POST["system_prompt"] ?? ""), 0, 4000));

        // stored encrypted like provider keys, a blank field keeps the one we have
        $exaKey = trim($_POST["exa_key"] ?? "");
        if($exaKey !== ""){
            settings::set("ai_exa_key", (new encryption())->encrypt($exaKey));
        } elseif(!empty($_POST["exa_key_clear"])){
            settings::set("ai_exa_key", "");
        }

        $default = $_POST["default_model"] ?? "";
        if(aiconfig::model($default)){
            settings::set("ai_default_model", $default);
        }

        adminRedirect("/admin/ai", "saved");
    });

    $router->get("/ai/providers/new", function(){
        $type = $_GET["type"] ?? "openai";
        $type = isset(aiconfig::TYPES[$type]) ? $type : "openai";

        adminRender("ai-provider", "ai", [
            "provider"=>["id"=>null, "dbId"=>null, "name"=>"", "type"=>$type, "url"=>"", "hasKey"=>false, "options"=>[], "enabled"=>true, "models"=>[]],
            "types"=>aiconfig::TYPES,
            "defaultUrls"=>aiconfig::DEFAULT_URLS,
        ]);
    });

    $router->get("/ai/providers/{id}", function($id){
        global $router;

        $provider = aiconfig::provider("db-" . (int) $id);
        if(!$provider || !ctype_digit($id)){
            return $router->return_status(404);
        }

        adminRender("ai-provider", "ai", [
            "provider"=>$provider,
            "types"=>aiconfig::TYPES,
            "defaultUrls"=>aiconfig::DEFAULT_URLS,
        ]);
    });

    $router->post("/ai/providers/save", function(){
        global $db;
        requireAdminPost();

        $id = (int) ($_POST["id"] ?? 0);
        $existing = $id ? $db->table("ai_providers")->where("id", $id)->first() : null;

        $type = $_POST["type"] ?? "";
        $name = trim($_POST["name"] ?? "");
        $url = rtrim(trim($_POST["base_url"] ?? ""), "/");

        $form = [
            "id"=>$existing ? "db-" . $existing->id : null,
            "dbId"=>$existing->id ?? null,
            "name"=>$name,
            "type"=>$type,
            "url"=>$url,
            "hasKey"=>$existing && $existing->api_key,
            "options"=>(array) ($_POST["options"][$type] ?? []),
            "enabled"=>!empty($_POST["enabled"]),
            "models"=>$existing ? (aiconfig::provider("db-" . $existing->id)["models"] ?? []) : [],
        ];

        $error = null;
        if(!isset(aiconfig::TYPES[$type])){
            $error = "Pick what kind of API it is.";
        } elseif($name === ""){
            $error = "Give it a name, it's what shows up in this list.";
        } elseif($url !== "" && !preg_match('#^https?://[^\s/]+#i', $url)){
            $error = "The base URL has to start with http:// or https://.";
        }

        if($error){
            return adminRender("ai-provider", "ai", ["provider"=>$form, "types"=>aiconfig::TYPES, "defaultUrls"=>aiconfig::DEFAULT_URLS, "error"=>$error]);
        }

        // only keep the options that make sense for this kind of provider
        $allowed = [
            "anthropic"=>["max_tokens", "effort", "thinking", "fallbacks"],
            "openai"=>["max_tokens"],
            "ollama"=>["keep_alive"],
        ][$type];

        $options = [];
        $sent = (array) ($_POST["options"][$type] ?? []);
        foreach($allowed as $key){
            $value = trim((string) ($sent[$key] ?? ""));
            if($key === "fallbacks"){
                $value = !empty($sent["fallbacks"]) ? "true" : "false";
            }
            if($value !== ""){
                $options[$key] = $value;
            }
        }

        $values = [
            "name"=>$name,
            "type"=>$type,
            "base_url"=>$url !== "" ? $url : aiconfig::DEFAULT_URLS[$type],
            "options"=>json_encode($options),
            "enabled"=>!empty($_POST["enabled"]) ? 1 : 0,
        ];

        // keys are stored encrypted, a blank field means keep the one we have
        $key = trim($_POST["api_key"] ?? "");
        if($key !== ""){
            $values["api_key"] = (new encryption())->encrypt($key);
        } elseif(!empty($_POST["clear_key"])){
            $values["api_key"] = null;
        }

        if($existing){
            $db->table("ai_providers")->where("id", $existing->id)->update($values);
            adminRedirect("/admin/ai/providers/" . $existing->id, "saved");
        }

        $values["created"] = time();
        $newId = $db->table("ai_providers")->insert($values);
        adminRedirect("/admin/ai/providers/" . $newId, "created");
    });

    $router->post("/ai/providers/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $db->table("ai_models")->where("provider_id", (int) $id)->delete();
        $db->table("ai_providers")->where("id", (int) $id)->delete();

        adminRedirect("/admin/ai", "deleted");
    });

    // asks the provider what models it has (json for the "fetch models" button)
    $router->get("/ai/providers/{id}/remote-models", function($id){
        $provider = aiconfig::provider("db-" . (int) $id);
        if(!$provider){
            http_response_code(404);
            return ["status"=>"error", "message"=>"That provider doesn't exist."];
        }

        try {
            $models = aiconfig::remoteModels($provider);
        } catch (\Throwable $e) {
            http_response_code(502);
            return ["status"=>"error", "message"=>$e->getMessage()];
        }

        $have = array_column($provider["models"], "name");

        return ["status"=>"okay", "models"=>array_map(fn($m) => $m + ["added"=>in_array($m["name"], $have, true)], $models)];
    });

    $router->post("/ai/models/save", function(){
        global $db;
        requireAdminPost();

        $providerId = (int) ($_POST["provider_id"] ?? 0);
        if(!$db->table("ai_providers")->where("id", $providerId)->first()){
            adminRedirect("/admin/ai");
        }

        $name = trim($_POST["name"] ?? "");
        if($name === ""){
            adminRedirect("/admin/ai/providers/$providerId");
        }

        $values = [
            "provider_id"=>$providerId,
            "name"=>mb_substr($name, 0, 160),
            "label"=>mb_substr(trim($_POST["label"] ?? "") ?: $name, 0, 120),
            "vision"=>in_array($_POST["vision"] ?? "", ["yes", "no"], true) ? $_POST["vision"] : "auto",
            "tools"=>in_array($_POST["tools"] ?? "", ["yes", "no"], true) ? $_POST["tools"] : "auto",
            "enabled"=>isset($_POST["enabled"]) ? (!empty($_POST["enabled"]) ? 1 : 0) : 1,
            "sort"=>(int) ($_POST["sort"] ?? 0),
        ];

        $id = (int) ($_POST["id"] ?? 0);
        if($id && $db->table("ai_models")->where("id", $id)->where("provider_id", $providerId)->first()){
            $db->table("ai_models")->where("id", $id)->update($values);
            adminRedirect("/admin/ai/providers/$providerId#models", "saved");
        }

        $db->table("ai_models")->insert($values);
        adminRedirect("/admin/ai/providers/$providerId#models", "created");
    });

    $router->post("/ai/models/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $model = $db->table("ai_models")->where("id", (int) $id)->first();
        if($model){
            $db->table("ai_models")->where("id", $model->id)->delete();
            adminRedirect("/admin/ai/providers/" . $model->provider_id . "#models", "deleted");
        }

        adminRedirect("/admin/ai");
    });

}, 'requireAdmin');
