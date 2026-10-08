<?php
use watrlabs\games\games;
use watrlabs\games\playtime;
use watrlabs\games\cloudsaves;
use watrlabs\games\requests;

global $router; // IMPORTANT: KEEP THIS HERE!

// the game (or app) an api call is about, or a 404
function playGame($id){
    $game = ctype_digit((string) $id) ? (new games())->get((int) $id) : null;

    if(!$game){
        http_response_code(404);
        header("Content-Type: application/json");
        exit(json_encode(["status"=>"error", "message"=>"That game doesn't exist."]));
    }

    return $game;
}

function playUser(){
    global $currentuser;

    if(!$currentuser){
        http_response_code(401);
        header("Content-Type: application/json");
        exit(json_encode(["status"=>"error", "message"=>"You need to be signed in."]));
    }

    return $currentuser;
}

// sendBeacon posts can't set headers, and arrive as text/plain json or form data
function playInput(){
    if($_POST){
        return $_POST;
    }
    $json = json_decode(file_get_contents("php://input") ?: "", true);
    return is_array($json) ? $json : [];
}

$router->group('/api/v1/play', function($router){

    // ---------- playtime ----------

    $router->post("/{id}/beat", function($id){
        $me = playUser();
        $game = playGame($id);
        $input = playInput();

        $total = (new playtime())->beat($me, (int) $game->id, (int) ($input["seconds"] ?? 0));

        if(!empty($input["stop"])){
            (new playtime())->stop($me, (int) $game->id);
        }

        return ["status"=>"okay", "seconds"=>$total];
    });

    $router->post("/{id}/stop", function($id){
        $me = playUser();
        $game = playGame($id);

        (new playtime())->stop($me, (int) $game->id);
        return ["status"=>"okay"];
    });

    // ---------- cloud saves ----------

    $router->get("/{id}/save", function($id){
        $me = playUser();
        $game = playGame($id);

        if(!cloudsaves::supported($game)){
            return apiError("This game can't be saved to your account.", 400);
        }

        return ["status"=>"okay", "save"=>(new cloudsaves())->get((int) $me->id, (int) $game->id)];
    });

    $router->post("/{id}/save", function($id){
        $me = playUser();
        $game = playGame($id);
        $input = playInput();

        if(!cloudsaves::supported($game)){
            return apiError("This game can't be saved to your account.", 400);
        }

        $data = $input["data"] ?? null;
        if(is_string($data)){
            $data = json_decode($data, true);
        }
        if(!is_array($data)){
            return apiError("Nothing to save.");
        }

        try {
            return ["status"=>"okay"] + (new cloudsaves())->put((int) $me->id, (int) $game->id, $data);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage(), 413);
        }
    });

    $router->post("/{id}/save/delete", function($id){
        $me = playUser();
        $game = playGame($id);

        (new cloudsaves())->delete((int) $me->id, (int) $game->id);
        return ["status"=>"okay"];
    });

    // ---------- votes + reports ----------

    $router->post("/{id}/vote", function($id){
        $me = playUser();
        $game = playGame($id);

        $vote = (int) ($_POST["vote"] ?? 0);
        $counts = (new games())->vote((int) $me->id, (int) $game->id, max(-1, min(1, $vote)));

        return ["status"=>"okay", "vote"=>max(-1, min(1, $vote))] + $counts + ["rating"=>games::rating($counts["likes"], $counts["dislikes"])];
    });

    $router->post("/{id}/report", function($id){
        $me = playUser();
        $game = playGame($id);

        try {
            (new requests())->reportBroken((int) $me->id, (int) $game->id, (string) ($_POST["reason"] ?? ""), (string) ($_POST["details"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>"Thanks! We'll take a look."];
    });

});

// ---------- game requests ----------

$router->get("/games/request", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render("game-request.twig", [
        "mine"=>(new requests())->mine((int) $currentuser->id),
        "perDay"=>requests::PER_DAY,
    ]);
});

$router->post("/api/v1/games/request", function(){
    $me = playUser();

    try {
        (new requests())->request((int) $me->id, (string) ($_POST["name"] ?? ""), (string) ($_POST["url"] ?? ""), (string) ($_POST["notes"] ?? ""));
    } catch (\InvalidArgumentException $e) {
        return apiError($e->getMessage());
    }

    return ["status"=>"okay", "message"=>"Got it! You'll get a notification when we've looked at it."];
});
