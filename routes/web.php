<?php
use watrlabs\router\Routing;
use watrlabs\watrkit\sanitize;
use watrlabs\encryption;
use watrlabs\authentication\sessions;

global $router; // IMPORTANT: KEEP THIS HERE!
global $pagebuilder;

$router->get("/randTest", function(){
    header("Content-type: text/plain");
    $encryption = new encryption();
    echo $encryption->genRandString(10000000, true);

});

$router->get("/", function() {
    global $twig;
    global $currentuser;

    if($currentuser){
        header("Location: /home");
        die();
    }

    echo $twig->render('default.twig');
});

$router->get("/auth/sign-up", function() {
    global $twig;
    global $currentuser;

    if($currentuser){
        header("Location: /home");
        die();
    }

    echo $twig->render('auth/sign-up.twig');
});

$router->get("/auth/isAuthed", function(){

    $sessions = new sessions();

    $userInfo = $sessions->getUserInfoFromCookie();

    if($userInfo){
        echo "You are authenticated.<br>";
        echo $userInfo->username;
    }

});

$router->get("/auth/sign-in", function() {
    global $twig;
    global $currentuser;

    if($currentuser){
        header("Location: /home");
        die();
    }

    echo $twig->render('auth/sign-in.twig');
});


/*
$router->group('/group', function($router) {
    
    $router->get("/hi", function () {
        echo "test<br>";
    });
    
});
*/
