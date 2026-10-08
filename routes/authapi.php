<?php
use watrlabs\authentication\sessions;
use watrlabs\authentication\registration;
use watrlabs\authentication\security;
use watrlabs\games\games;
use watrlabs\games\comments;
use watrlabs\users\users;
use watrlabs\music\music;
use watrlabs\watrkit\uploads;
use watrlabs\watrkit\ratelimit;
use watrlabs\users\moderation;

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

        // wrong passwords are counted per address and per username. after a few from one address it
        // needs a captcha too, after more it waits
        $ip = ratelimit::ip();
        $nameKey = "n" . substr(hash("sha256", strtolower($username)), 0, 32);
        if(ratelimit::count("login_fail", $ip) >= 10 || ratelimit::count("login_fail_name", $nameKey) >= 5){
            http_response_code(429);
            return ["status"=>"error", "message"=>"Too many wrong passwords. Wait 15 minutes and try again.", "captcha"=>security::captchaActive()];
        }
        if(security::captchaActive() && ratelimit::count("login_fail", $ip) >= 3){
            if(!security::verifyCaptcha($_POST["cf-turnstile-response"] ?? null)){
                http_response_code(400);
                return ["status"=>"error", "message"=>"Finish the captcha first.", "captcha"=>true];
            }
        }

        $userInfo = $db->table("users")->where("username", $username)->first();

        // same message either way so you can't fish for usernames
        if(!$userInfo || !password_verify($password, $userInfo->password)){
            ratelimit::hit("login_fail", $ip, 10, 900);
            ratelimit::hit("login_fail_name", $nameKey, 5, 900);
            http_response_code(401);
            return ["status"=>"error", "message"=>"Username or password incorrect.", "captcha"=>security::captchaActive() && ratelimit::count("login_fail", $ip) >= 3];
        }

        if(moderation::isBanned($userInfo)){
            return apiError(moderation::banMessage($userInfo), 403);
        }

        ratelimit::clear("login_fail_name", $nameKey);

        $db->table("users")->where("id", $userInfo->id)->update([
            "LastIP"=>$security->encryptIp($security::getRequestIp())
        ]);

        // right password, but there's a second step
        if(!empty($userInfo->totp_enabled)){
            return ["status"=>"2fa", "token"=>\watrlabs\authentication\totp::challenge((int) $userInfo->id)];
        }

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

        ratelimit::guard("register", ratelimit::ip(), 5, 3600, "That's a lot of new accounts from here. Try again later.");

        $email = $_POST["email"] ?? null;

        return registration::createUser($username, $password, $email ?: null);
    });
    
});

$router->group('/api/v1/games', function($router) {

    $router->post("/favorite", function(){
        \watrlabs\watrkit\ratelimit::guard("favorite", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 60, 60);
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

    // ---------- comments ----------

    $router->get("/{id}/comments", function($id){
        global $currentuser;

        if(!comments::enabled()){
            return apiError("Comments are switched off.", 403);
        }
        if(!ctype_digit($id) || !(new games())->get((int) $id)){
            return apiError("That game doesn't exist.", 404);
        }

        $before = ctype_digit((string) ($_GET["before"] ?? "")) ? (int) $_GET["before"] : null;
        return ["status"=>"okay"] + (new comments())->list((int) $id, $currentuser, $before);
    });

    $router->post("/{id}/comments", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in to comment.", 401);
        }
        if(!comments::enabled()){
            return apiError("Comments are switched off.", 403);
        }
        if(!ctype_digit($id) || !(new games())->get((int) $id)){
            return apiError("That game doesn't exist.", 404);
        }

        try {
            $comment = (new comments())->add($currentuser, (int) $id, (string) ($_POST["body"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "comment"=>$comment];
    });

    $router->post("/comments/{id}/delete", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        if(!ctype_digit($id) || !(new comments())->delete($currentuser, (int) $id)){
            return apiError("You can't delete that comment.", 404);
        }

        return ["status"=>"okay"];
    });

    $router->post("/comments/{id}/edit", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        ratelimit::guard("comment_change", $currentuser, 30, 60);

        try {
            return ["status"=>"okay", "comment"=>(new comments())->edit($currentuser, (int) $id, (string) ($_POST["body"] ?? ""))];
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }
    });

    $router->post("/comments/{id}/react", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        ratelimit::guard("react", $currentuser, 60, 60);

        try {
            return ["status"=>"okay", "reactions"=>(new \watrlabs\social\reactions())->toggle($currentuser, "comment", (int) $id, (string) ($_POST["emoji"] ?? ""))];
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }
    });

    $router->post("/comments/{id}/report", function($id){
        \watrlabs\watrkit\ratelimit::guard("report", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 20, 3600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        try {
            (new comments())->report((int) $currentuser->id, ctype_digit($id) ? (int) $id : 0, (string) ($_POST["reason"] ?? ""), (string) ($_POST["details"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay"];
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

        // replaying (or calling this in a loop) only counts once per song every 10 minutes
        global $currentuser;
        if(\watrlabs\watrkit\playcounter::shouldCount("track", (int) $trackId, \watrlabs\watrkit\playcounter::viewer($currentuser))){
            $music->addPlay((int) $trackId);
        }

        return ["status"=>"okay"];
    });

    $router->post("/upload", function(){
        \watrlabs\watrkit\ratelimit::guard("upload", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 30, 3600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in to upload music.", 401);
        }
        if(moderation::isMuted($currentuser)){
            return apiError(moderation::muteMessage($currentuser), 403);
        }

        // over post_max_size php throws the whole body away, so there's nothing to look at
        if(empty($_FILES) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0){
            return apiError("That file is bigger than the server allows.", 413);
        }

        try {
            $track = (new music())->upload((int) $currentuser->id, $_POST);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return [
            "status"=>"okay",
            "track"=>["id"=>(int) $track->id, "title"=>$track->title, "artist"=>$track->artist, "src"=>$track->filePath, "cover"=>$track->coverPath],
            "lyrics"=>$track->lyrics === null ? "none" : ($track->lyricsSynced ? "synced" : "plain"),
        ];
    });

    $router->post("/{id}/delete", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        if(!ctype_digit((string) $id) || !(new music())->deleteTrack((int) $id, $currentuser)){
            return apiError("You can only delete tracks you uploaded.", 403);
        }

        return ["status"=>"okay"];
    });

    // the player asks for these whenever a track starts
    $router->get("/{id}/lyrics", function($id){
        $music = new music();
        $track = ctype_digit((string) $id) ? $music->get((int) $id) : null;

        if(!$track){
            return apiError("That track doesn't exist.", 404);
        }

        return ["status"=>"okay"] + $music->lyricsFor($track);
    });

});

$router->post("/api/v1/theme", function(){
    global $currentuser;

    $theme = $_POST["theme"] ?? "auto";
    if(!is_string($theme) || !\watrlabs\watrkit\themes::usable($currentuser, $theme)){
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

// a theme someone made for themselves
$router->post("/api/v1/themes/custom/{id}/delete", function($id){
    global $currentuser;

    if(!$currentuser){
        return apiError("You need to be signed in.", 401);
    }

    if(!ctype_digit($id) || !\watrlabs\watrkit\userthemes::delete((int) $currentuser->id, (int) $id)){
        return apiError("That theme doesn't exist.", 404);
    }

    if(($_COOKIE["wg_theme"] ?? "") === \watrlabs\watrkit\userthemes::PREFIX . $id){
        setcookie("wg_theme", "auto", ["expires"=>time() + 31536000, "path"=>"/", "samesite"=>"Lax"]);
    }

    return ["status"=>"okay"];
});

$router->group('/api/v1/account', function($router) {

    $router->post("/blurb", function(){
        \watrlabs\watrkit\ratelimit::guard("profile", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 30, 3600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        if(moderation::isMuted($currentuser)){
            return apiError(moderation::muteMessage($currentuser), 403);
        }

        $blurb = trim($_POST["blurb"] ?? "");

        if(mb_strlen($blurb) > 255){
            return apiError("Blurbs can be 255 characters at most.");
        }

        $users = new users();
        $users->update($currentuser->id, ["blurb"=>$blurb]);

        return ["status"=>"okay", "message"=>"Blurb saved."];
    });

    $router->post("/avatar", function(){
        \watrlabs\watrkit\ratelimit::guard("profile", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 30, 3600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        if(!uploads::sent("avatar")){
            return apiError(empty($_FILES) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0 ? "Pictures have to be under 5MB." : "Pick a picture first.");
        }

        try {
            $path = uploads::storeAvatar("avatar");
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        uploads::delete($currentuser->avatar ?? null);
        (new users())->update($currentuser->id, ["avatar"=>$path]);

        return ["status"=>"okay", "message"=>"Profile picture saved.", "avatar"=>$path];
    });

    $router->post("/avatar/remove", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        uploads::delete($currentuser->avatar ?? null);
        (new users())->update($currentuser->id, ["avatar"=>null]);

        return ["status"=>"okay", "message"=>"Profile picture removed."];
    });

    $router->post("/password", function(){
        \watrlabs\watrkit\ratelimit::guard("confirm", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 10, 900, "Too many tries. Wait a few minutes.");
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
