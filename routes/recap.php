<?php
use watrlabs\users\recap;

global $router; // IMPORTANT: KEEP THIS HERE!

// last week, or ?week=2026-40 for an earlier one (up to 12 weeks back)
$router->get("/recap", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    $last = recap::lastWeek();
    $week = isset($_GET["week"]) ? recap::week((string) $_GET["week"]) : $last;
    if(!$week || $week["start"] > $last["start"] || $week["start"] < date("Y-m-d", strtotime($last["start"] . " -12 weeks"))){
        $week = $last;
    }

    $previous = date("o-W", strtotime($week["start"] . " -7 days"));
    $next = date("o-W", strtotime($week["start"] . " +7 days"));

    echo $twig->render("recap.twig", [
        "week"=>$week,
        "recap"=>recap::build((int) $currentuser->id, $week),
        "previous"=>$week["start"] > date("Y-m-d", strtotime($last["start"] . " -12 weeks")) ? $previous : null,
        "next"=>$week["start"] < $last["start"] ? $next : null,
    ]);
});
