<?php
use watrlabs\watrkit\csrf;
use watrlabs\watrkit\settings;
use watrlabs\watrkit\themes;
use watrlabs\watrkit\uploads;
use watrlabs\watrkit\adminlog;
use watrlabs\games\tags;
use watrlabs\games\requests;
use watrlabs\ai\config as aiconfig;
use watrlabs\authentication\sessions;
use watrlabs\encryption;
use watrlabs\users\moderation;

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
    "muted"=>"Muted. They've been told.",
    "unmuted"=>"Unmuted.",
    "wiped"=>"Their scores are gone.",
    "admin"=>"They're an admin now.",
    "unadmin"=>"They're not an admin anymore.",
    "signedout"=>"Signed out of every device.",
    "dismissed"=>"Report dismissed.",
    "removed"=>"Message removed.",
    "removedbanned"=>"Message removed and the sender is banned.",
    "fixed"=>"Marked as fixed.",
    "added"=>"Marked as added. They've been told.",
    "declined"=>"Declined. They've been told.",
    "sent"=>"Sent to everyone.",
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
        "openRequests"=>requests::openCount(),
        "flash"=>ADMIN_MESSAGES[$_GET["msg"] ?? ""] ?? null,
    ], $data));
}

function adminPage(){
    return max(1, (int) ($_GET["page"] ?? 1));
}

// the "game of the day" box on the game form: pins it for today, or unpins it if it was the pinned one
function adminPin(int $gameId){
    $pinned = \watrlabs\games\featured::pinnedToday();
    if(!empty($_POST["pin"]) && $pinned !== $gameId){
        \watrlabs\games\featured::pin($gameId);
        adminlog::add("game.pin", "game", $gameId, null);
    } elseif(empty($_POST["pin"]) && $pinned === $gameId){
        \watrlabs\games\featured::pin(null);
    }
}

// what a reported message said before it was edited (or deleted by whoever sent it), oldest first
function adminEdits(string $kind, array $rows, string $idField){
    global $db;

    foreach($rows as $row){
        $row->edits = $db->table("message_edits")->where("kind", $kind)->where("item_id", (int) $row->$idField)->orderBy("id", "ASC")->get();
    }
    return $rows;
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

        // the last 30 days, one entry per day even when nothing happened
        $days = [];
        for($i = 29; $i >= 0; $i--){
            $days[date("Y-m-d", strtotime("-$i days"))] = ["signups"=>0, "plays"=>0, "seconds"=>0];
        }
        $since = strtotime(array_key_first($days));
        foreach($db->query("SELECT DATE(FROM_UNIXTIME(registered)) AS day, COUNT(*) AS n FROM users WHERE registered >= ? GROUP BY day", [$since])->get() as $row){
            if(isset($days[$row->day])){ $days[$row->day]["signups"] = (int) $row->n; }
        }
        foreach($db->query("SELECT day, SUM(plays) AS plays, SUM(seconds) AS seconds FROM game_daily WHERE day >= ? GROUP BY day", [array_key_first($days)])->get() as $row){
            if(isset($days[$row->day])){ $days[$row->day]["plays"] = (int) $row->plays; $days[$row->day]["seconds"] = (int) $row->seconds; }
        }

        $topPlaytime = $db->query(
            "SELECT g.id, g.name, g.type, SUM(d.seconds) AS seconds, SUM(d.plays) AS plays FROM game_daily d INNER JOIN games g ON g.id = d.gameid
             WHERE d.day >= ? GROUP BY g.id, g.name, g.type HAVING seconds > 0 ORDER BY seconds DESC LIMIT 8",
            [array_key_first($days)]
        )->get();

        // messages are saved with a model id, every model belongs to a provider (deleted ones count as "removed")
        $aiProviders = [];
        try {
            $owner = [];
            foreach(aiconfig::providers() as $provider){
                foreach($provider["models"] as $model){
                    $owner[$model["id"]] = $provider["name"];
                }
            }
            foreach($db->query(
                "SELECT model, COUNT(*) AS n FROM ai_messages WHERE role = 'assistant' AND created >= ? AND model IS NOT NULL GROUP BY model",
                [$since]
            )->get() as $row){
                $name = $owner[$row->model] ?? "A removed model";
                $aiProviders[$name] = ($aiProviders[$name] ?? 0) + (int) $row->n;
            }
            arsort($aiProviders);
            $aiProviders = array_map(fn($name, $n) => ["name"=>$name, "messages"=>$n], array_keys($aiProviders), $aiProviders);
        } catch (\Throwable $e) {}

        adminRender("dashboard", "dashboard", [
            "days"=>$days,
            "topPlaytime"=>$topPlaytime,
            "aiProviders"=>$aiProviders,
            "openRequestsCount"=>requests::openCount(),
            "stats"=>$stats,
            "ai"=>$ai,
            "aiEnabledNow"=>aiconfig::enabled(),
            "recentUsers"=>$db->table("users")->select(["id", "username", "registered"])->orderBy("id", "DESC")->limit(6)->get(),
            "topGames"=>$db->table("games")->select(["id", "name", "plays", "type"])->orderBy("plays", "DESC")->limit(6)->get(),
            "season"=>themes::currentSeason(),
            "siteTheme"=>themes::get(themes::siteTheme()),
            "health"=>\watrlabs\watrkit\health::checks(),
            "alertsOn"=>\watrlabs\watrkit\health::alertsConfigured(),
            "cronRan"=>is_file(dirname(__DIR__) . "/storage/cron/health.json") ? filemtime(dirname(__DIR__) . "/storage/cron/health.json") : null,
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
        // "add this game" from a request fills in what we know
        $request = ctype_digit((string) ($_GET["request"] ?? "")) ? $GLOBALS["db"]->table("game_requests")->where("id", (int) $_GET["request"])->first() : null;

        adminRender("game-form", $type === "app" ? "apps" : "games", [
            "game"=>(object) ["id"=>null, "type"=>$type, "name"=>$request->name ?? "", "description"=>"", "gamePath"=>"", "gameIcon"=>"", "plays"=>0, "controls"=>"", "screenshots"=>null, "scores"=>"off", "score_label"=>"", "score_format"=>"number", "score_max"=>null],
            "pinned"=>false,
            "allTags"=>tags::all(),
            "gameTags"=>[],
            "request"=>$request,
        ]);
    });

    $router->get("/games/{id}", function($id){
        global $db;
        global $router;

        $game = ctype_digit($id) ? $db->table("games")->where("id", (int) $id)->first() : null;
        if(!$game){
            return $router->return_status(404);
        }

        adminRender("game-form", $game->type === "app" ? "apps" : "games", [
            "game"=>$game,
            "allTags"=>tags::all(),
            "gameTags"=>array_map(fn($t) => (int) $t->id, tags::forGame((int) $game->id)),
            "pinned"=>\watrlabs\games\featured::pinnedToday() === (int) $game->id,
            "isGotd"=>\watrlabs\games\featured::gameOfDayId() === (int) $game->id,
            "scoreCount"=>(int) $db->table("game_scores")->where("gameid", $game->id)->where("period", "all")->count(),
        ]);
    });

    $router->post("/games/save", function(){
        global $db;
        requireAdminPost();

        $id = (int) ($_POST["id"] ?? 0);
        $existing = $id ? $db->table("games")->where("id", $id)->first() : null;

        $scoreMax = trim((string) ($_POST["score_max"] ?? ""));
        $game = (object) [
            "id"=>$existing->id ?? null,
            "type"=>($_POST["type"] ?? "game") === "app" ? "app" : "game",
            "name"=>trim($_POST["name"] ?? ""),
            "description"=>trim($_POST["description"] ?? ""),
            "gamePath"=>trim($_POST["gamePath"] ?? ""),
            "gameIcon"=>trim($_POST["gameIcon"] ?? ""),
            "plays"=>$existing->plays ?? 0,
            "controls"=>mb_substr(trim($_POST["controls"] ?? ""), 0, 2000),
            "scores"=>in_array($_POST["scores"] ?? "off", ["off", "high", "low"], true) ? $_POST["scores"] : "off",
            "score_label"=>mb_substr(trim($_POST["score_label"] ?? ""), 0, 20),
            "score_format"=>($_POST["score_format"] ?? "number") === "time" ? "time" : "number",
            "score_max"=>ctype_digit($scoreMax) ? (int) $scoreMax : null,
        ];

        // screenshots: the ones still ticked, then new uploads, 6 at most
        $before = $existing && $existing->screenshots ? (json_decode($existing->screenshots, true) ?: []) : [];
        $kept = array_values(array_intersect($before, (array) ($_POST["keepShots"] ?? [])));
        $shots = $kept;

        $error = null;

        try {
            if(uploads::sent("iconFile")){
                $game->gameIcon = uploads::store("iconFile", "icons");
            }
            foreach(uploads::many("shotFiles") as $file){
                if(count($shots) >= 6){
                    $error = "That's more than 6 screenshots, the extra ones weren't added.";
                    break;
                }
                $shots[] = uploads::storeFile($file, "screenshots");
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $game->screenshots = $shots ? json_encode($shots) : null;

        if(!$error && $game->name === ""){
            $error = "Give it a name.";
        } elseif(!$error && $game->gamePath === ""){
            $error = "It needs a game path, the URL the player loads (like /game-files/slope/index.html).";
        }

        if($error){
            // new screenshots that made it are thrown away with the failed save
            foreach(array_diff($shots, $kept) as $path){
                uploads::delete($path);
            }
            $game->screenshots = $kept ? json_encode($kept) : null;
            return adminRender("game-form", $game->type === "app" ? "apps" : "games", ["game"=>$game, "error"=>$error, "allTags"=>tags::all(), "gameTags"=>array_map("intval", (array) ($_POST["tags"] ?? [])), "pinned"=>!empty($_POST["pin"])]);
        }

        foreach(array_diff($before, $kept) as $path){
            uploads::delete($path);
        }

        $values = [
            "type"=>$game->type,
            "name"=>$game->name,
            "description"=>$game->description,
            "gamePath"=>$game->gamePath,
            "gameIcon"=>$game->gameIcon,
            "controls"=>$game->controls !== "" ? $game->controls : null,
            "screenshots"=>$game->screenshots,
            "scores"=>$game->scores,
            "score_label"=>$game->score_label !== "" ? $game->score_label : null,
            "score_format"=>$game->score_format,
            "score_max"=>$game->score_max,
        ];

        if(!empty($_POST["resetPlays"])){
            $values["plays"] = 0;
        }

        if($existing){
            if($existing->gameIcon !== $game->gameIcon){
                uploads::delete($existing->gameIcon);
            }
            $db->table("games")->where("id", $existing->id)->update($values);
            tags::setForGame((int) $existing->id, (array) ($_POST["tags"] ?? []));
            adminPin((int) $existing->id);
            adminlog::add("game.edit", "game", (int) $existing->id, $game->name);
            adminRedirect("/admin/games/" . $existing->id, "saved");
        }

        $values["plays"] = 0;
        $values["created"] = time();
        $newId = $db->table("games")->insert($values);
        tags::setForGame((int) $newId, (array) ($_POST["tags"] ?? []));
        adminPin((int) $newId);
        adminlog::add("game.add", "game", (int) $newId, $game->name);

        // a request for this game can be closed off from here
        if(ctype_digit((string) ($_POST["request"] ?? ""))){
            (new requests())->resolve((int) $_POST["request"], "added", (int) $newId, "", (int) $GLOBALS["currentuser"]->id);
        }

        adminRedirect("/admin/games/" . $newId, "created");
    });

    $router->post("/games/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $game = $db->table("games")->where("id", (int) $id)->first();
        if($game){
            uploads::delete($game->gameIcon);
            foreach(json_decode((string) $game->screenshots, true) ?: [] as $path){
                uploads::delete($path);
            }
            foreach(["favorites", "game_tags", "game_votes", "playtime", "game_daily", "cloud_saves", "game_reports", "game_scores", "challenges", "user_game_daily", "collection_games"] as $table){
                $db->table($table)->where("gameid", $game->id)->delete();
            }
            $db->table("games")->where("id", $game->id)->delete();
            adminlog::add("game.delete", null, (int) $game->id, $game->name);
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
                $values += ["lyrics"=>null, "lyricsSynced"=>0, "lyricsFile"=>null, "lyricsChecked"=>null];
            }
            $db->table("tracks")->where("id", $existing->id)->update($values);
            adminlog::add("track.edit", "track", (int) $existing->id, $values["title"]);
            adminRedirect("/admin/music/" . $existing->id, "saved");
        }

        $values["plays"] = 0;
        $values["created"] = time();
        $newId = $db->table("tracks")->insert($values);
        adminlog::add("track.add", "track", (int) $newId, $values["title"]);
        adminRedirect("/admin/music/" . $newId, "created");
    });

    $router->post("/music/{id}/delete", function($id){
        global $db;
        requireAdminPost();

        $track = $db->table("tracks")->where("id", (int) $id)->first();
        if($track){
            uploads::delete($track->filePath);
            uploads::delete($track->coverPath);
            $db->table("playlist_tracks")->where("trackid", $track->id)->delete();
            $db->table("tracks")->where("id", $track->id)->delete();
            adminlog::add("track.delete", null, (int) $track->id, $track->title);
        }

        adminRedirect("/admin/music", "deleted");
    });

    // ---------- users ----------

    $router->get("/users", function(){
        global $db;

        $search = trim($_GET["q"] ?? "");
        $filter = $_GET["filter"] ?? "all";
        $page = adminPage();

        $query = $db->table("users")->select(["id", "username", "email", "registered", "admin", "banned", "banned_until", "muted_until"]);
        if($search !== ""){
            $query->where("username", "LIKE", "%" . addcslashes($search, "%_\\") . "%");
        }
        if($filter === "admins"){
            $query->where("admin", 1);
        } elseif($filter === "banned"){
            $query->where("banned", 1);
        } elseif($filter === "muted"){
            $query->where("muted_until", ">", time());
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
            "now"=>time(),
        ]);
    });

    // one person: ban / mute with a time and a reason, and what's happened to them before
    $router->get("/users/{id}", function($id){
        global $db;
        global $router;

        $user = ctype_digit($id) ? $db->table("users")->where("id", (int) $id)->first() : null;
        if(!$user){
            return $router->return_status(404);
        }
        moderation::isBanned($user); // lifts a ban that ran out

        $one = fn($sql) => (int) ($db->query($sql, [$user->id])->first()->n ?? 0);

        adminRender("user", "users", [
            "user"=>$user,
            "muted"=>moderation::isMuted($user),
            "durations"=>moderation::DURATIONS,
            "history"=>moderation::history((int) $user->id),
            "counts"=>[
                "comments"=>$one("SELECT COUNT(*) AS n FROM game_comments WHERE userid = ? AND deleted = 0"),
                "messages"=>$one("SELECT COUNT(*) AS n FROM chat_messages WHERE sender_id = ?") + $one("SELECT COUNT(*) AS n FROM chat_group_messages WHERE sender_id = ?"),
                "reports"=>$one("SELECT COUNT(*) AS n FROM chat_reports r INNER JOIN chat_messages m ON m.id = r.message_id AND r.kind = 'dm' WHERE m.sender_id = ?")
                    + $one("SELECT COUNT(*) AS n FROM comment_reports r INNER JOIN game_comments c ON c.id = r.comment_id WHERE c.userid = ?"),
                "playtime"=>$one("SELECT COALESCE(SUM(seconds), 0) AS n FROM playtime WHERE userid = ?"),
            ],
        ]);
    });

    $router->post("/users/{id}/action", function($id){
        global $db;
        global $currentuser;
        requireAdminPost();

        $user = $db->table("users")->where("id", (int) $id)->first();
        $action = $_POST["action"] ?? "";
        $back = isset($_POST["back"]) && preg_match('#^(\?[^#]*|/admin/users/\d+)$#', (string) $_POST["back"])
            ? (str_starts_with($_POST["back"], "?") ? "/admin/users" . $_POST["back"] : $_POST["back"])
            : "/admin/users";

        if(!$user){
            adminRedirect($back);
        }

        // no locking yourself out by accident
        if((int) $user->id === (int) $currentuser->id && in_array($action, ["ban", "unadmin", "mute"], true)){
            adminRedirect($back);
        }

        $me = (int) $currentuser->id;
        $seconds = (int) ($_POST["duration"] ?? 0);
        if(!array_key_exists($seconds, moderation::DURATIONS)){
            $seconds = 0;
        }
        $reason = (string) ($_POST["reason"] ?? "");

        if(in_array($action, ["ban", "unban", "mute", "unmute", "admin", "unadmin", "signout"], true)){
            adminlog::add("user." . $action, "user", (int) $user->id, $user->username . (in_array($action, ["ban", "mute"], true) ? " (" . moderation::DURATIONS[$seconds] . ")" : ""));
        }

        switch($action){
            case "ban":
                moderation::ban((int) $user->id, $seconds, $reason, $me);
                adminRedirect($back, "banned");
            case "unban":
                moderation::unban((int) $user->id, $me);
                adminRedirect($back, "unbanned");
            case "mute":
                moderation::mute((int) $user->id, $seconds, $reason, $me);
                adminRedirect($back, "muted");
            case "unmute":
                moderation::unmute((int) $user->id, $me);
                adminRedirect($back, "unmuted");
            case "admin":
                $db->table("users")->where("id", $user->id)->update(["admin"=>1]);
                adminRedirect($back, "admin");
            case "unadmin":
                $db->table("users")->where("id", $user->id)->update(["admin"=>0]);
                adminRedirect($back, "unadmin");
            case "signout":
                $db->table("sessions")->where("userid", $user->id)->delete();
                adminRedirect($back, "signedout");
            case "wipescores":
                \watrlabs\games\scores::wipeUser((int) $user->id);
                adminlog::add("user.wipescores", "user", (int) $user->id, $user->username);
                adminRedirect($back, "wiped");
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

        adminlog::add("settings.save");
        adminRedirect("/admin/settings", "saved");
    });

    // ---------- chat reports ----------

    $router->get("/reports", function(){
        global $db;

        $status = $_GET["status"] ?? "open";
        $status = in_array($status, ["open", "dismissed", "actioned"], true) ? $status : "open";
        $page = adminPage();

        $openChat = $db->table("chat_reports")->where("kind", "dm")->where("status", "open")->count();
        $openGroups = $db->table("chat_reports")->where("kind", "group")->where("status", "open")->count();
        $openComments = \watrlabs\games\comments::openReports();
        $openGames = requests::openBroken();
        $counts = ["openChat"=>$openChat, "openGroups"=>$openGroups, "openComments"=>$openComments, "openGames"=>$openGames];

        // "this game is broken" reports
        if(($_GET["kind"] ?? "") === "games"){
            $total = $db->table("game_reports")->where("status", $status)->count();
            $rows = $db->query(
                "SELECT r.*, g.name AS game_name, g.type AS game_type, g.gamePath, u.username AS reporter_name, h.username AS handler_name
                 FROM game_reports r LEFT JOIN games g ON g.id = r.gameid LEFT JOIN users u ON u.id = r.userid LEFT JOIN users h ON h.id = r.handled_by
                 WHERE r.status = ? ORDER BY r.created " . ($status === "open" ? "ASC" : "DESC") . " LIMIT 25 OFFSET " . (($page - 1) * 25),
                [$status]
            )->get();

            return adminRender("reports", "reports", $counts + [
                "kind"=>"games", "rows"=>$rows, "status"=>$status, "reasons"=>requests::BROKEN_REASONS,
                "page"=>$page, "pages"=>max(1, (int) ceil($total / 25)), "total"=>$total,
            ]);
        }

        // group chat messages, with the messages around each one
        if(($_GET["kind"] ?? "") === "groups"){
            $total = $db->table("chat_reports")->where("kind", "group")->where("status", $status)->count();
            $rows = $db->query(
                "SELECT r.*, m.sender_id, m.groupid, m.body, m.image_id, m.created AS sent, m.deleted, gr.name AS group_name,
                        s.username AS sender_name, s.banned AS sender_banned, rp.username AS reporter_name, h.username AS handler_name
                 FROM chat_reports r
                 INNER JOIN chat_group_messages m ON m.id = r.message_id
                 LEFT JOIN chat_groups gr ON gr.id = m.groupid
                 LEFT JOIN users s ON s.id = m.sender_id
                 LEFT JOIN users rp ON rp.id = r.reporter_id
                 LEFT JOIN users h ON h.id = r.handled_by
                 WHERE r.kind = 'group' AND r.status = ? ORDER BY r.created " . ($status === "open" ? "ASC" : "DESC") . " LIMIT 25 OFFSET " . (($page - 1) * 25),
                [$status]
            )->get();

            foreach($rows as $row){
                $before = $db->query("SELECT m.id, m.sender_id, m.body, m.image_id, m.created, m.deleted, u.username FROM chat_group_messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.groupid = ? AND m.id < ? ORDER BY m.id DESC LIMIT 5", [$row->groupid, $row->message_id])->get();
                $after = $db->query("SELECT m.id, m.sender_id, m.body, m.image_id, m.created, m.deleted, u.username FROM chat_group_messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.groupid = ? AND m.id >= ? ORDER BY m.id ASC LIMIT 6", [$row->groupid, $row->message_id])->get();
                $row->context = array_merge(array_reverse($before), $after);
            }
            adminEdits("group", $rows, "message_id");

            return adminRender("reports", "reports", $counts + [
                "kind"=>"groups", "rows"=>$rows, "status"=>$status, "reasons"=>\watrlabs\social\chat::REPORT_REASONS,
                "page"=>$page, "pages"=>max(1, (int) ceil($total / 25)), "total"=>$total,
            ]);
        }

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

            adminEdits("comment", $rows, "comment_id");
            adminRender("reports", "reports", [
                "kind"=>"comments",
                "rows"=>$rows,
                "status"=>$status,
                "reasons"=>\watrlabs\social\chat::REPORT_REASONS,
                "page"=>$page,
                "pages"=>max(1, (int) ceil($total / 25)),
                "total"=>$total,
            ] + $counts);
            return;
        }

        $total = $db->table("chat_reports")->where("kind", "dm")->where("status", $status)->count();
        $rows = $db->query(
            "SELECT r.*, m.sender_id, m.recipient_id, m.body, m.image_id, m.created AS sent, m.deleted,
                    s.username AS sender_name, s.banned AS sender_banned, rp.username AS reporter_name, h.username AS handler_name
             FROM chat_reports r
             INNER JOIN chat_messages m ON m.id = r.message_id
             LEFT JOIN users s ON s.id = m.sender_id
             LEFT JOIN users rp ON rp.id = r.reporter_id
             LEFT JOIN users h ON h.id = r.handled_by
             WHERE r.kind = 'dm' AND r.status = ? ORDER BY r.created " . ($status === "open" ? "ASC" : "DESC") . " LIMIT 25 OFFSET " . (($page - 1) * 25),
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
        adminEdits("dm", $rows, "message_id");

        adminRender("reports", "reports", $counts + [
            "kind"=>"chat",
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

        adminlog::add("comment_report." . $action, "user", $comment ? (int) $comment->userid : null, $comment ? mb_substr((string) $comment->body, 0, 200) : null);

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
                moderation::ban((int) $comment->userid, 0, "A comment that broke the rules", (int) $currentuser->id);
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

        $isGroup = ($report->kind ?? "dm") === "group";
        $back = $isGroup ? "/admin/reports?kind=groups" : "/admin/reports";
        $message = $db->table($isGroup ? "chat_group_messages" : "chat_messages")->where("id", $report->message_id)->first();
        $handled = ["handled_by"=>$currentuser->id, "handled_at"=>time()];

        adminlog::add("chat_report." . $action, "user", $message ? (int) $message->sender_id : null, $message ? mb_substr((string) $message->body, 0, 200) : null);

        if($action === "dismiss"){
            $db->table("chat_reports")->where("id", $report->id)->update(["status"=>"dismissed", "action"=>"dismissed"] + $handled);
            adminRedirect($back, "dismissed");
        }

        if(($action === "remove" || $action === "ban") && $message){
            if($isGroup){
                $db->table("chat_group_messages")->where("id", $message->id)->update(["deleted"=>1]);
                \watrlabs\social\realtime::publish((new \watrlabs\social\groups())->memberIds((int) $message->groupid), ["type"=>"group_deleted", "id"=>(int) $message->id, "group"=>(int) $message->groupid]);
            } else {
                $db->table("chat_messages")->where("id", $message->id)->update(["deleted"=>1]);
                \watrlabs\social\realtime::publish([$message->sender_id, $message->recipient_id], ["type"=>"deleted", "id"=>(int) $message->id]);
            }

            // every open report on this message is settled by the same decision
            $db->table("chat_reports")->where("kind", $isGroup ? "group" : "dm")->where("message_id", $message->id)->where("status", "open")
                ->update(["status"=>"actioned", "action"=>$action === "ban" ? "removed, sender banned" : "removed"] + $handled);

            if($action === "ban" && (int) $message->sender_id !== (int) $currentuser->id){
                moderation::ban((int) $message->sender_id, 0, "A chat message that broke the rules", (int) $currentuser->id);
                $db->table("sessions")->where("userid", $message->sender_id)->delete();
                adminRedirect($back, "removedbanned");
            }

            adminRedirect($back, "removed");
        }

        adminRedirect($back);
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

        adminlog::add("ai.settings");
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

        adminlog::add($existing ? "ai.provider.edit" : "ai.provider.add", null, $existing ? (int) $existing->id : null, $name);

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
        adminlog::add("ai.provider.delete", null, (int) $id);

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

        adminlog::add("ai.model.save", null, null, $values["name"]);

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

    // ---------- game requests ----------

    $router->get("/requests", function(){
        global $db;

        $status = in_array($_GET["status"] ?? "open", ["open", "added", "declined"], true) ? ($_GET["status"] ?? "open") : "open";
        $page = adminPage();

        $total = $db->table("game_requests")->where("status", $status)->count();

        // how many people asked for the same name, so popular ones stand out
        $rows = $db->query(
            "SELECT r.*, u.username, h.username AS handler_name, g.name AS game_name,
                    (SELECT COUNT(*) FROM game_requests x WHERE x.status = 'open' AND LOWER(x.name) = LOWER(r.name)) AS asks
             FROM game_requests r LEFT JOIN users u ON u.id = r.userid LEFT JOIN users h ON h.id = r.handled_by LEFT JOIN games g ON g.id = r.gameid
             WHERE r.status = ? ORDER BY " . ($status === "open" ? "asks DESC, r.created ASC" : "r.handled_at DESC") . " LIMIT 50 OFFSET " . (($page - 1) * 50),
            [$status]
        )->get();

        adminRender("requests", "requests", [
            "rows"=>$rows,
            "status"=>$status,
            "page"=>$page,
            "pages"=>max(1, (int) ceil($total / 50)),
            "total"=>$total,
            "games"=>$db->table("games")->select(["id", "name", "type"])->orderBy("name")->get(),
        ]);
    });

    $router->post("/requests/{id}/action", function($id){
        global $currentuser;
        requireAdminPost();

        $action = $_POST["action"] ?? "";
        $status = $action === "added" ? "added" : ($action === "declined" ? "declined" : null);
        if($status){
            $gameId = ctype_digit((string) ($_POST["game"] ?? "")) ? (int) $_POST["game"] : null;
            (new requests())->resolve((int) $id, $status, $gameId, (string) ($_POST["note"] ?? ""), (int) $currentuser->id);
            adminlog::add("request." . $status, "game", $gameId, null);
            adminRedirect("/admin/requests", $status);
        }

        adminRedirect("/admin/requests");
    });

    // ---------- broken game reports ----------

    $router->post("/reports/games/{id}/action", function($id){
        global $db;
        global $currentuser;
        requireAdminPost();

        $report = $db->table("game_reports")->where("id", (int) $id)->first();
        $action = $_POST["action"] ?? "";
        $back = "/admin/reports?kind=games";

        if(!$report || !in_array($action, ["fixed", "dismiss"], true)){
            adminRedirect($back);
        }

        $handled = ["status"=>$action === "fixed" ? "actioned" : "dismissed", "handled_by"=>$currentuser->id, "handled_at"=>time()];

        // fixing a game settles every open report on it
        if($action === "fixed"){
            $db->table("game_reports")->where("gameid", $report->gameid)->where("status", "open")->update($handled);
        } else {
            $db->table("game_reports")->where("id", $report->id)->update($handled);
        }

        adminlog::add("game_report." . $action, "game", (int) $report->gameid);
        adminRedirect($back, $action === "fixed" ? "fixed" : "dismissed");
    });

    // ---------- categories ----------

    $router->get("/tags", function(){
        global $db;

        adminRender("tags", "games", [
            "tags"=>$db->query("SELECT t.*, (SELECT COUNT(*) FROM game_tags gt WHERE gt.tagid = t.id) AS games FROM tags t ORDER BY t.sort, t.name")->get(),
        ]);
    });

    $router->post("/tags/save", function(){
        requireAdminPost();

        $id = ctype_digit((string) ($_POST["id"] ?? "")) ? (int) $_POST["id"] : null;
        try {
            $saved = tags::save($id, (string) ($_POST["name"] ?? ""), (int) ($_POST["sort"] ?? 0));
        } catch (\InvalidArgumentException $e) {
            global $db;
            return adminRender("tags", "games", [
                "tags"=>$db->query("SELECT t.*, (SELECT COUNT(*) FROM game_tags gt WHERE gt.tagid = t.id) AS games FROM tags t ORDER BY t.sort, t.name")->get(),
                "error"=>$e->getMessage(),
            ]);
        }

        adminlog::add($id ? "tag.edit" : "tag.add", null, (int) $saved, (string) ($_POST["name"] ?? ""));
        adminRedirect("/admin/tags", $id ? "saved" : "created");
    });

    $router->post("/tags/{id}/delete", function($id){
        requireAdminPost();

        tags::delete((int) $id);
        adminlog::add("tag.delete", null, (int) $id);
        adminRedirect("/admin/tags", "deleted");
    });

    // ---------- audit log ----------

    $router->get("/log", function(){
        $action = (string) ($_GET["action"] ?? "");
        $actions = adminlog::actions();
        $action = in_array($action, $actions, true) ? $action : null;
        $page = adminPage();

        adminRender("log", "log", adminlog::page($page, $action) + [
            "action"=>$action,
            "actions"=>$actions,
            "page"=>$page,
        ]);
    });

    // ---------- notify everyone ----------

    $router->post("/broadcast", function(){
        global $currentuser;
        requireAdminPost();

        $text = mb_substr(trim(preg_replace('/\s+/', ' ', $_POST["text"] ?? "")), 0, 300);
        $link = trim($_POST["link"] ?? "");
        // only links on this site, so a notification can't send people somewhere dodgy
        $link = ($link !== "" && str_starts_with($link, "/") && !str_starts_with($link, "//")) ? mb_substr($link, 0, 200) : null;

        if($text !== ""){
            \watrlabs\social\notifications::broadcast($text, $link, (int) $currentuser->id);
            adminlog::add("broadcast", null, null, $text);
            adminRedirect("/admin/settings", "sent");
        }

        adminRedirect("/admin/settings");
    });

}, 'requireAdmin');
