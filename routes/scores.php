<?php
use watrlabs\games\scores;

global $router; // IMPORTANT: KEEP THIS HERE!

// playUser(), playGame() and playInput() live in routes/play.php
$router->group('/api/v1/play', function($router){

    // a game finished a run. comes from play.js, which got it from the game's frame
    $router->post("/{id}/score", function($id){
        $me = playUser();
        $game = playGame($id);
        $input = playInput();

        try {
            return ["status"=>"okay"] + (new scores())->submit($me, $game, $input["score"] ?? null);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }
    });

    // all | week | friends
    $router->get("/{id}/scores", function($id){
        global $currentuser;
        $game = playGame($id);

        if(!scores::enabled($game)){
            return apiError("This game doesn't have a leaderboard.", 404);
        }

        $period = in_array($_GET["period"] ?? "all", ["all", "week", "friends"], true) ? $_GET["period"] : "all";
        return ["status"=>"okay", "period"=>$period] + (new scores())->board($game, $period, $currentuser ?: null);
    });

    $router->post("/{id}/challenge", function($id){
        $me = playUser();
        $game = playGame($id);

        try {
            (new scores())->challenge($me, $game, (int) ($_POST["friend"] ?? 0));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>"Challenge sent!"];
    });

});
