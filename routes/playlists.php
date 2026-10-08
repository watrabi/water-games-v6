<?php
use watrlabs\music\playlists;

global $router; // IMPORTANT: KEEP THIS HERE!

// the shape the music player wants
function playlistTrack($track){
    return ["id"=>(int) $track->id, "title"=>$track->title, "artist"=>$track->artist, "src"=>$track->filePath, "cover"=>$track->coverPath];
}

$router->get("/music/playlists", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render("playlists.twig", [
        "playlists"=>(new playlists())->listFor((int) $currentuser->id),
    ]);
});

$router->get("/music/playlists/{id}", function($id){
    global $twig;
    global $router;
    global $currentuser;

    $playlists = new playlists();
    $playlist = ctype_digit($id) ? $playlists->get((int) $id) : null;
    if(!$playlist){
        return $router->return_status(404);
    }

    $tracks = $playlists->tracks((int) $playlist->id);

    echo $twig->render("playlist.twig", [
        "playlist"=>$playlist,
        "tracks"=>$tracks,
        "mine"=>$currentuser && (int) $currentuser->id === (int) $playlist->userid,
        "duration"=>array_sum(array_map(fn($t) => (int) $t->duration, $tracks)),
    ]);
});

$router->group('/api/v1/playlists', function($router){

    // yours, for the "add to playlist" menu
    $router->get("/", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        return ["status"=>"okay", "playlists"=>array_map(fn($p) => ["id"=>(int) $p->id, "name"=>$p->name, "tracks"=>(int) $p->tracks], (new playlists())->listFor((int) $currentuser->id))];
    });

    $router->post("/", function(){
        \watrlabs\watrkit\ratelimit::guard("playlists", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 20, 3600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        try {
            $playlists = new playlists();
            $id = $playlists->create((int) $currentuser->id, (string) ($_POST["name"] ?? ""));
            if(!empty($_POST["track"])){
                $playlists->add((int) $currentuser->id, $id, (int) $_POST["track"]);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "id"=>$id];
    });

    // the tracks, to play a playlist from anywhere
    $router->get("/{id}/tracks", function($id){
        $playlists = new playlists();
        $playlist = ctype_digit($id) ? $playlists->get((int) $id) : null;
        if(!$playlist){
            return apiError("That playlist doesn't exist.", 404);
        }

        return ["status"=>"okay", "tracks"=>array_map("playlistTrack", $playlists->tracks((int) $playlist->id))];
    });

    // add | remove | move | rename | delete
    $router->post("/{id}/{action}", function($id, $action){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $playlists = new playlists();
        $me = (int) $currentuser->id;
        $id = (int) $id;

        try {
            switch($action){
                case "add":
                    $playlists->add($me, $id, (int) ($_POST["track"] ?? 0));
                    return ["status"=>"okay", "message"=>"Added."];
                case "remove":
                    $playlists->removeEntry($me, $id, (int) ($_POST["entry"] ?? 0));
                    break;
                case "move":
                    $playlists->move($me, $id, (int) ($_POST["entry"] ?? 0), (int) ($_POST["direction"] ?? 1));
                    break;
                case "rename":
                    $playlists->rename($me, $id, (string) ($_POST["name"] ?? ""));
                    break;
                case "delete":
                    $playlists->delete($me, $id);
                    break;
                default:
                    return apiError("Unknown action.", 404);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay"];
    });

});
