<?php
use watrlabs\router\Routing;
use watrlabs\watrkit\sanitize;
use watrlabs\encryption;
use watrlabs\authentication\registration;

global $router; // IMPORTANT: KEEP THIS HERE!
global $pagebuilder;

$router->group('/api/v1/auth', function($router) {
    
    $router->post("/login", function () {
        return [
            "status"=>"Error",
            "message"=>"Currently Unavailable."
        ];
    });

    $router->post("/register", function () {
        if(isset($_POST["username"]) && isset($_POST["password"])){

            $email = null;
            $username = $_POST["username"];
            $password = $_POST["password"];

            if(isset($_POST["email"])){
                $email = $_POST["email"];
            }

            $auth = new registration();

            return $auth->createUser($username, $password, $email);

        }
    });
    
});
