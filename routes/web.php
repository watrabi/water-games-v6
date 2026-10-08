<?php
use watrlabs\encryption;
use watrlabs\authentication\sessions;
use watrlabs\games\games;
use watrlabs\games\comments;
use watrlabs\users\users;
use watrlabs\music\music;
use watrlabs\games\playtime;
use watrlabs\games\tags;
use watrlabs\games\cloudsaves;
use watrlabs\games\requests;
use watrlabs\social\activity;
use watrlabs\social\achievements;

global $router; // IMPORTANT: KEEP THIS HERE!
global $pagebuilder;

// sends people to sign in if they aren't, used on pages that need an account
function requireAccount(){
    global $currentuser;

    if(!$currentuser){
        header("Location: /auth/sign-in");
        die();
    }
}

function redirectIfSignedIn(){
    global $currentuser;

    if($currentuser){
        header("Location: /home");
        die();
    }
}

$router->set404(function(){
    global $twig;

    echo $twig->render('statusCodes/404.twig');
});

// debug stuff, don't want these hanging around on the live site
if(($_ENV["APP_DEBUG"] ?? "false") === "true"){

    $router->get("/randTest", function(){
        header("Content-type: text/plain");
        $encryption = new encryption();
        echo $encryption->genRandString(10000000, true);

    });

    $router->get("/auth/isAuthed", function(){

        $sessions = new sessions();

        $userInfo = $sessions->getUserInfoFromCookie();

        if($userInfo){
            echo "You are authenticated.<br>";
            echo htmlspecialchars($userInfo->username);
        }

    });

}

$router->get("/", function() {
    global $twig;

    redirectIfSignedIn();

    $games = new games();

    echo $twig->render('default.twig', [
        "popular"=>$games->list("popular", null, 9),
        "gameCount"=>$games->count(),
        "appCount"=>$games->count("app"),
        "trackCount"=>(new music())->count(),
    ]);
});

$router->get('/home', function(){
    global $twig;
    global $currentuser;

    requireAccount();

    $games = new games();
    $playtime = new playtime();
    $me = (int) $currentuser->id;

    // friends who are in a game right now
    $playingNow = array_values(array_filter((new \watrlabs\social\friends())->list($me), fn($f) => $f["playing"]));

    echo $twig->render('home.twig', [
        "recent"=>$playtime->recent($me, 6),
        "favorites"=>$games->favoritesFor($me, 12),
        "trending"=>$games->list("trending", null, 12),
        "newest"=>$games->list("newest", null, 6),
        "feed"=>(new activity())->feedFor($me, 12),
        "playingNow"=>array_slice($playingNow, 0, 8),
        "totalPlaytime"=>$playtime->totalFor($me),
    ]);
});

$router->get("/games", function(){
    global $twig;

    $games = new games();

    $sort = $_GET["sort"] ?? "popular";
    if(!in_array($sort, games::sortOptions())){
        $sort = "popular";
    }

    $search = trim($_GET["q"] ?? "");
    $tag = tags::bySlug((string) ($_GET["tag"] ?? ""));

    echo $twig->render('games.twig', [
        "games"=>$games->list($sort, $search, 200, "game", $tag ? (int) $tag->id : null),
        "sort"=>$sort,
        "search"=>$search,
        "tag"=>$tag,
        "tags"=>tags::used("game"),
    ]);

});

// games and apps share the player page, only the type + wording changes
function renderPlayer($id, $type){
    global $twig;
    global $router;
    global $currentuser;

    $games = new games();
    $game = ctype_digit($id) ? $games->get((int) $id, $type) : null;

    if(!$game){
        return $router->return_status(404);
    }

    $games->addPlay($game->id);
    $game->plays++;

    $played = null;
    $myVote = 0;
    if($currentuser){
        $playtime = new playtime();
        $played = $playtime->rowFor((int) $currentuser->id, (int) $game->id);
        if(!$played){
            activity::log((int) $currentuser->id, "first_play", (int) $game->id);
        }
        $playtime->start($currentuser, (int) $game->id);
        $myVote = $games->voteOf((int) $currentuser->id, (int) $game->id);
    }

    $commentsOn = comments::enabled();
    $comments = new comments();

    echo $twig->render('play.twig', [
        "game"=>$game,
        "favorited"=>$currentuser ? $games->isFavorited($currentuser->id, $game->id) : false,
        "related"=>$games->related($game->id, $type),
        "tags"=>tags::forGame((int) $game->id),
        "played"=>$played,
        "myVote"=>$myVote,
        "rating"=>games::rating($game->likes, $game->dislikes),
        "brokenReasons"=>requests::BROKEN_REASONS,
        "cloudOn"=>$currentuser && cloudsaves::supported($game),
        // what play.js needs: the tracker, cloud saves and fullscreen
        "playData"=>json_encode([
            "game"=>(int) $game->id,
            "signedIn"=>(bool) $currentuser,
            "cloud"=>$currentuser && cloudsaves::supported($game),
            "beatEvery"=>playtime::BEAT_EVERY,
            "seconds"=>$played ? (int) $played->seconds : 0,
            "siteKeys"=>cloudsaves::SITE_KEYS,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        "commentsOn"=>$commentsOn,
        "commentCount"=>$commentsOn ? $comments->count((int) $game->id) : 0,
        "commentMax"=>comments::MAX_LENGTH,
        // comment text ends up in a script tag, so < > & and quotes are escaped
        "commentData"=>$commentsOn ? json_encode([
            "game"=>(int) $game->id,
            "signedIn"=>(bool) $currentuser,
            "reasons"=>\watrlabs\social\chat::REPORT_REASONS,
        ] + $comments->list((int) $game->id, $currentuser), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) : null,
    ]);
}

$router->get("/games/{id}", function($id){
    return renderPlayer($id, "game");
});

$router->get("/apps", function(){
    global $twig;

    $games = new games();
    $search = trim($_GET["q"] ?? "");

    echo $twig->render('apps.twig', [
        "apps"=>$games->list("popular", $search, 200, "app"),
        "search"=>$search,
    ]);
});

$router->get("/apps/{id}", function($id){
    return renderPlayer($id, "app");
});

$router->get("/music", function(){
    global $twig;

    $music = new music();

    $sort = $_GET["sort"] ?? "popular";
    if(!in_array($sort, music::sortOptions())){
        $sort = "popular";
    }

    $search = trim($_GET["q"] ?? "");

    echo $twig->render('music.twig', [
        "tracks"=>$music->list($sort, $search),
        "sort"=>$sort,
        "search"=>$search,
    ]);
});

$router->get("/music/upload", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render('music-upload.twig', [
        "uploads"=>(new music())->uploadsBy((int) $currentuser->id),
        "perDay"=>music::UPLOADS_PER_DAY,
    ]);
});

$router->get("/favorites", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    $games = new games();

    echo $twig->render('favorites.twig', [
        "games"=>$games->favoritesFor($currentuser->id),
    ]);
});

$router->get("/users/{username}", function($username){
    global $twig;
    global $router;

    $users = new users();
    $profile = $users->getPublicProfile(urldecode($username));

    if(!$profile){
        return $router->return_status(404);
    }

    global $currentuser;
    global $db;
    $games = new games();
    $playtime = new playtime();
    $isMe = $currentuser && (int) $currentuser->id === (int) $profile->id;

    $relation = null;
    $friendCount = 0;
    try {
        $relation = $currentuser ? (new \watrlabs\social\friends())->relation((int) $currentuser->id, (int) $profile->id) : null;
        $friendCount = $db->table("friendships")->where("status", "accepted")->where(function($q) use ($profile){
            $q->where("requester_id", $profile->id)->orWhere("addressee_id", $profile->id);
        })->count();
    } catch (\Throwable $e) {
        // friends tables arrive with the migration
    }

    // playtime is private unless they share their activity (you always see your own)
    $shares = $isMe || !empty($db->table("users")->select(["share_activity"])->where("id", $profile->id)->first()->share_activity);

    echo $twig->render('profile.twig', [
        "profile"=>$profile,
        "favorites"=>$games->favoritesFor($profile->id, 24),
        "achievements"=>achievements::forUser((int) $profile->id),
        "shares"=>$shares,
        "totalPlaytime"=>$shares ? $playtime->totalFor((int) $profile->id) : 0,
        "gamesPlayed"=>$shares ? $playtime->gamesPlayed((int) $profile->id) : 0,
        "mostPlayed"=>$shares ? $playtime->most((int) $profile->id, 6) : [],
        "relation"=>$relation,
        "friendCount"=>$friendCount,
    ]);
});

$router->get("/settings", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render('settings.twig', [
        "sessions"=>(new sessions())->listFor((int) $currentuser->id),
        "cloudSaves"=>(new cloudsaves())->listFor((int) $currentuser->id),
        "mailAvailable"=>\watrlabs\watrkit\mail::available(),
        "recoveryLeft"=>$currentuser->recovery_codes ? count(json_decode($currentuser->recovery_codes, true) ?: []) : 0,
    ]);
});

$router->get("/auth/sign-up", function() {
    global $twig;

    redirectIfSignedIn();

    echo $twig->render('auth/sign-up.twig');
});

$router->get("/auth/sign-in", function() {
    global $twig;

    redirectIfSignedIn();

    echo $twig->render('auth/sign-in.twig');
});

$router->get("/auth/logout", function(){
    $sessions = new sessions();
    $sessionId = $sessions->getCurrentSessionId();

    if($sessionId){
        $sessions->destroySession($sessionId);
    }

    header("Location: /");
    die();
});

$router->get("/auth/forgot", function(){
    global $twig;

    redirectIfSignedIn();

    echo $twig->render('auth/forgot.twig', ["mailAvailable"=>\watrlabs\watrkit\mail::available()]);
});

$router->get("/auth/reset", function(){
    global $twig;

    $token = (string) ($_GET["token"] ?? "");

    echo $twig->render('auth/reset.twig', [
        "token"=>$token,
        "valid"=>(bool) (new \watrlabs\authentication\passwordreset())->find($token),
    ]);
});

$router->get("/notifications", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render('notifications.twig', (new \watrlabs\social\notifications())->list((int) $currentuser->id));
});

// offline page for the service worker
$router->get("/offline", function(){
    global $twig;
    echo $twig->render('offline.twig');
});

$router->get("/terms", function(){
    global $twig;
    echo $twig->render('legal/terms.twig');
});

$router->get("/privacy", function(){
    global $twig;
    echo $twig->render('legal/privacy.twig');
});

$router->get("/credits", function(){
    global $twig;
    echo $twig->render('legal/credits.twig');
});
