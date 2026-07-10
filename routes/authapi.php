<?php
use watrlabs\router\Routing;
use watrlabs\watrkit\sanitize;
use watrlabs\encryption;
use watrlabs\authentication\sessions;
use watrlabs\authentication\registration;

global $router; // IMPORTANT: KEEP THIS HERE!
global $pagebuilder;

$router->group('/api/v1/auth', function($router) {
    
    $router->post("/login", function () {
        global $db;
        $sessions = new sessions();

        if(isset($_POST["username"]) && isset($_POST["password"])){
            $username = $_POST["username"];
            $password = $_POST["password"];

            $userInfo = $db->table("users")->where("username", $username)->first();
            if($userInfo){
                if(password_verify($password, $userInfo->password)){
                    $sessions->authenticateUser($userInfo->id);
                    return ["status"=>"okay", "message"=>"login success"];
                }
            } else {
                http_response_code(400);
                return ["status"=>"error", "message"=>"Username or password incorrect."];
            }

        } else {
            http_response_code(400);
            return ["status"=>"error", "message"=>"Please make sure both fields are filled out."];
        }
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

        } else {
            http_response_code(400);
            return ["status"=>"error", "message"=>"Please make sure both fields are filled out."];
        }
    });
    
});
