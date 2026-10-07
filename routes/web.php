<?php
use watrlabs\encryption;
use watrlabs\authentication\sessions;
use watrlabs\games\games;
use watrlabs\users\users;

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
    ]);
});

$router->get('/home', function(){
    global $twig;
    global $currentuser;

    requireAccount();

    $games = new games();

    echo $twig->render('home.twig', [
        "favorites"=>$games->favoritesFor($currentuser->id, 12),
        "popular"=>$games->list("popular", null, 12),
        "newest"=>$games->list("newest", null, 6),
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

    echo $twig->render('games.twig', [
        "games"=>$games->list($sort, $search),
        "sort"=>$sort,
        "search"=>$search,
    ]);

});

$router->get("/games/{id}", function($id){
    global $twig;
    global $router;
    global $currentuser;

    $games = new games();
    $game = ctype_digit($id) ? $games->get((int) $id) : null;

    if(!$game){
        return $router->return_status(404);
    }

    $games->addPlay($game->id);
    $game->plays++;

    echo $twig->render('play.twig', [
        "game"=>$game,
        "favorited"=>$currentuser ? $games->isFavorited($currentuser->id, $game->id) : false,
        "related"=>$games->related($game->id),
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

    $games = new games();

    echo $twig->render('profile.twig', [
        "profile"=>$profile,
        "favorites"=>$games->favoritesFor($profile->id, 24),
    ]);
});

$router->get("/settings", function(){
    global $twig;

    requireAccount();

    echo $twig->render('settings.twig');
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

// sidebar stuff that isn't built yet
$comingSoon = [
    "/proxy"=>"Proxy",
    "/apps"=>"Apps",
    "/music"=>"Music",
];

foreach($comingSoon as $path => $name){
    $router->get($path, function() use ($name) {
        global $twig;

        echo $twig->render('soon.twig', ["feature"=>$name]);
    });
}

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
