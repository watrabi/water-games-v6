<?php
use watrlabs\games\featured;
use watrlabs\watrkit\site;

global $router; // IMPORTANT: KEEP THIS HERE!

// ---------- random ----------

// ?unplayed=1 only picks games you haven't tried. ?not=12 avoids landing on the one you're on
function randomRedirect(string $type){
    global $currentuser;

    $unplayed = !empty($_GET["unplayed"]) && $currentuser ? (int) $currentuser->id : null;
    $not = ctype_digit((string) ($_GET["not"] ?? "")) ? (int) $_GET["not"] : null;

    $id = featured::randomId($type, $unplayed, $not) ?? featured::randomId($type, null, $not);

    header("Cache-Control: no-store");
    header("Location: " . ($type === "app" ? ($id ? "/apps/" . $id : "/apps") : ($id ? "/play/" . $id : "/discover")));
    exit;
}

$router->get("/play/random", fn() => randomRedirect("game"));
$router->get("/games/random", fn() => movedTo("/play/random"));
$router->get("/apps/random", fn() => randomRedirect("app"));

// ---------- for search engines ----------

$router->get("/robots.txt", function(){
    header("Content-Type: text/plain; charset=utf-8");

    echo implode("\n", [
        "User-agent: *",
        "Disallow: /admin",
        "Disallow: /api/",
        "Disallow: /ai",
        "Disallow: /settings",
        "Disallow: /notifications",
        "Disallow: /auth/reset",
        "Disallow: /play/random",
        "Disallow: /games/random",
        "Disallow: /apps/random",
        "Disallow: /chat/",
        "Disallow: /game-files/",
        "",
        "Sitemap: " . site::url() . "/sitemap.xml",
        "",
    ]);
    exit;
});

// games, apps, public collections and the plain pages. profiles are left out on purpose
$router->get("/sitemap.xml", function(){
    global $db;

    $base = site::url();
    $urls = [];
    $add = function(string $path, ?int $modified = null, string $priority = "0.5") use (&$urls, $base){
        $urls[] = "  <url><loc>" . htmlspecialchars($base . $path, ENT_XML1) . "</loc>"
            . ($modified ? "<lastmod>" . date("Y-m-d", $modified) . "</lastmod>" : "")
            . "<priority>$priority</priority></url>";
    };

    foreach(["/"=>"1.0", "/discover"=>"0.9", "/apps"=>"0.7", "/music"=>"0.6", "/collections"=>"0.6", "/terms"=>"0.1", "/privacy"=>"0.1", "/credits"=>"0.1"] as $path => $priority){
        $add($path, null, $priority);
    }

    foreach($db->table("games")->select(["id", "type", "created"])->orderBy("id")->get() as $game){
        $add("/" . ($game->type === "app" ? "apps" : "play") . "/" . (int) $game->id, $game->created ? (int) $game->created : null, $game->type === "app" ? "0.6" : "0.8");
    }

    try {
        foreach($db->query("SELECT c.id, c.updated FROM collections c INNER JOIN users u ON u.id = c.userid WHERE c.public = 1 AND u.banned = 0 ORDER BY c.id")->get() as $collection){
            $add("/collections/" . (int) $collection->id, (int) $collection->updated, "0.5");
        }
    } catch (\Throwable $e) {} // before migrations

    header("Content-Type: application/xml; charset=utf-8");
    header("Cache-Control: public, max-age=3600");
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . implode("\n", $urls) . "\n</urlset>\n";
    exit;
});

// ---------- for uptime monitors ----------

// 200 when the database answers, 503 when it doesn't. nothing else is said, the details are in the admin panel
$router->get("/health", function(){
    global $db;

    header("Cache-Control: no-store");
    try {
        $db->query("SELECT 1")->get();
        return ["status"=>"okay"];
    } catch (\Throwable $e) {
        http_response_code(503);
        return ["status"=>"down"];
    }
});
