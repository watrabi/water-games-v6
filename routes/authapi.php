<?php
use watrlabs\authentication\sessions;
use watrlabs\authentication\registration;
use watrlabs\authentication\security;
use watrlabs\games\games;
use watrlabs\users\users;
use watrlabs\music\music;

global $router; // IMPORTANT: KEEP THIS HERE!
global $pagebuilder;

function apiError($message, $code = 400){
    http_response_code($code);
    return ["status"=>"error", "message"=>$message];
}

$router->group('/api/v1/auth', function($router) {
    
    $router->post("/login", function () {
        global $db;
        $sessions = new sessions();
        $security = new security();

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if($username === "" || $password === ""){
            return apiError("Please make sure both fields are filled out.");
        }

        $userInfo = $db->table("users")->where("username", $username)->first();

        // same message either way so you can't fish for usernames
        if(!$userInfo || !password_verify($password, $userInfo->password)){
            return apiError("Username or password incorrect.", 401);
        }

        if(!empty($userInfo->banned)){
            return apiError("This account has been banned.", 403);
        }

        $db->table("users")->where("id", $userInfo->id)->update([
            "LastIP"=>$security->encryptIp($security::getRequestIp())
        ]);

        $sessions->authenticateUser($userInfo->id);
        return ["status"=>"okay", "message"=>"login success"];
    });

    $router->post("/register", function () {
        $username = $_POST["username"] ?? "";
        $password = $_POST["password"] ?? "";

        if($username === "" || $password === ""){
            return apiError("Please make sure both fields are filled out.");
        }

        if(!security::verifyCaptcha($_POST["cf-turnstile-response"] ?? null)){
            return apiError("Captcha failed, try again.");
        }

        $email = $_POST["email"] ?? null;

        return registration::createUser($username, $password, $email ?: null);
    });
    
});

$router->group('/api/v1/games', function($router) {

    $router->post("/favorite", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in to favorite games.", 401);
        }

        $gameId = $_POST["id"] ?? "";
        $games = new games();

        if(!ctype_digit((string) $gameId) || !$games->get((int) $gameId)){
            return apiError("That game doesn't exist.", 404);
        }

        $favorited = $games->toggleFavorite($currentuser->id, (int) $gameId);

        return [
            "status"=>"okay",
            "favorited"=>$favorited,
            "favorites"=>$games->favoriteCount((int) $gameId),
        ];
    });

});

$router->group('/api/v1/music', function($router) {

    // the player calls this once each time a track starts
    $router->post("/play", function(){
        $trackId = $_POST["id"] ?? "";
        $music = new music();

        if(!ctype_digit((string) $trackId) || !$music->get((int) $trackId)){
            return apiError("That track doesn't exist.", 404);
        }

        $music->addPlay((int) $trackId);

        return ["status"=>"okay"];
    });

});

$router->post("/api/v1/theme", function(){
    global $currentuser;

    $theme = $_POST["theme"] ?? "auto";
    if($theme !== "auto" && !\watrlabs\watrkit\themes::exists($theme)){
        return apiError("That theme doesn't exist.");
    }

    setcookie("wg_theme", $theme, ["expires"=>time() + 31536000, "path"=>"/", "samesite"=>"Lax"]);

    if(isset($_POST["effects"])){
        setcookie("wg_effects", $_POST["effects"] === "off" ? "off" : "on", ["expires"=>time() + 31536000, "path"=>"/", "samesite"=>"Lax"]);
    }

    if($currentuser){
        $users = new users();
        $users->update($currentuser->id, ["theme"=>$theme === "auto" ? null : $theme]);
    }

    return ["status"=>"okay", "theme"=>$theme];
});

$router->group('/api/v1/account', function($router) {

    $router->post("/blurb", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $blurb = trim($_POST["blurb"] ?? "");

        if(mb_strlen($blurb) > 255){
            return apiError("Blurbs can be 255 characters at most.");
        }

        $users = new users();
        $users->update($currentuser->id, ["blurb"=>$blurb]);

        return ["status"=>"okay", "message"=>"Blurb saved."];
    });

    $router->post("/password", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $current = $_POST["current"] ?? "";
        $new = $_POST["new"] ?? "";

        if(!password_verify($current, $currentuser->password)){
            return apiError("Your current password is wrong.");
        }

        $passwordError = registration::validatePassword($new);
        if($passwordError){
            return apiError($passwordError);
        }

        $users = new users();
        $users->update($currentuser->id, ["password"=>password_hash($new, PASSWORD_BCRYPT)]);

        // kick every other session out, then sign this one back in
        $sessions = new sessions();
        $sessions->destroyAllSessions($currentuser->id);
        $sessions->authenticateUser($currentuser->id);

        return ["status"=>"okay", "message"=>"Password changed. Other devices have been signed out."];
    });

});
