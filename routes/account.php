<?php
use watrlabs\authentication\sessions;
use watrlabs\authentication\totp;
use watrlabs\authentication\passwordreset;
use watrlabs\users\users;
use watrlabs\users\accountdata;
use watrlabs\social\achievements;

global $router; // IMPORTANT: KEEP THIS HERE!

function accountUser(){
    global $currentuser;

    if(!$currentuser){
        http_response_code(401);
        header("Content-Type: application/json");
        exit(json_encode(["status"=>"error", "message"=>"You need to be signed in."]));
    }

    return $currentuser;
}

// for the dangerous stuff: your password, and your 2FA code if it's on
function confirmIdentity($user, array $input){
    if(!password_verify((string) ($input["password"] ?? ""), $user->password)){
        throw new \InvalidArgumentException("Your password is wrong.");
    }

    if(!empty($user->totp_enabled)){
        global $db;
        $secret = totp::open($user->totp_secret);
        $step = $secret ? totp::verify($secret, (string) ($input["code"] ?? ""), $user->totp_last_step !== null ? (int) $user->totp_last_step : null) : null;
        if($step === null){
            throw new \InvalidArgumentException("That 2FA code isn't right.");
        }
        $db->table("users")->where("id", $user->id)->update(["totp_last_step"=>$step]);
    }
}

$router->group('/api/v1/auth', function($router){

    // second step of signing in, after a right password
    $router->post("/2fa", function(){
        \watrlabs\watrkit\ratelimit::guard("2fa", \watrlabs\watrkit\ratelimit::ip(), 20, 900, "Too many tries. Wait a few minutes.");
        try {
            $userId = totp::answer((string) ($_POST["token"] ?? ""), (string) ($_POST["code"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage(), 401);
        }

        (new sessions())->authenticateUser($userId);
        return ["status"=>"okay"];
    });

    $router->post("/forgot", function(){
        \watrlabs\watrkit\ratelimit::guard("forgot", \watrlabs\watrkit\ratelimit::ip(), 5, 3600, "That's a lot of reset emails. Try again in an hour.");
        if(!\watrlabs\authentication\security::verifyCaptcha($_POST["cf-turnstile-response"] ?? null)){
            return apiError("Captcha failed, try again.");
        }

        (new passwordreset())->start((string) ($_POST["who"] ?? ""));

        // the same answer whether or not the account exists
        return ["status"=>"okay", "message"=>"If that account has an email on it, a reset link is on its way. Check your spam folder too."];
    });

    $router->post("/reset", function(){
        \watrlabs\watrkit\ratelimit::guard("reset", \watrlabs\watrkit\ratelimit::ip(), 10, 3600);
        try {
            $userId = (new passwordreset())->finish((string) ($_POST["token"] ?? ""), (string) ($_POST["password"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        // with 2FA on, they still have to get through that, so don't sign them straight in
        global $db;
        $user = $db->table("users")->where("id", $userId)->first();
        if(empty($user->totp_enabled)){
            (new sessions())->authenticateUser($userId);
            return ["status"=>"okay", "signedIn"=>true];
        }

        return ["status"=>"okay", "signedIn"=>false];
    });

});

$router->group('/api/v1/account', function($router){

    $router->post("/email", function(){
        \watrlabs\watrkit\ratelimit::guard("account", accountUser(), 20, 3600);
        $me = accountUser();

        if(!password_verify((string) ($_POST["password"] ?? ""), $me->password)){
            return apiError("Your password is wrong.");
        }

        $email = trim((string) ($_POST["email"] ?? ""));
        if($email !== "" && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)){
            return apiError("That email doesn't look right.");
        }

        (new users())->update((int) $me->id, ["email"=>$email !== "" ? $email : null]);
        return ["status"=>"okay", "message"=>$email !== "" ? "Email saved." : "Email removed. You won't be able to reset your password by email."];
    });

    $router->post("/privacy", function(){
        $me = accountUser();

        $share = !empty($_POST["share_activity"]) && $_POST["share_activity"] !== "false";
        $update = ["share_activity"=>$share ? 1 : 0];
        if(!$share){
            $update["playing_game_id"] = null;
        }
        (new users())->update((int) $me->id, $update);

        return ["status"=>"okay", "message"=>$share ? "Friends can see what you're playing." : "Your activity is hidden now."];
    });

    $router->post("/recap", function(){
        $me = accountUser();

        $on = !empty($_POST["email"]);
        if($on && empty($me->email)){
            return apiError("Add an email address first.");
        }
        (new users())->update((int) $me->id, ["recap_email"=>$on ? 1 : 0]);

        return ["status"=>"okay", "message"=>$on ? "You'll get it by email every Monday." : "No more recap emails. The notification still comes."];
    });

    // ---------- 2FA ----------

    // step one: a new secret to scan. it's only kept once a code from it works
    $router->post("/2fa/setup", function(){
        \watrlabs\watrkit\ratelimit::guard("account", accountUser(), 20, 3600);
        global $db;
        $me = accountUser();

        if(!empty($me->totp_enabled)){
            return apiError("Two-factor sign in is already on.");
        }

        $secret = totp::newSecret();
        $db->table("users")->where("id", $me->id)->update(["totp_secret"=>totp::seal($secret)]);

        return ["status"=>"okay", "secret"=>$secret, "uri"=>totp::uri($secret, $me->username)];
    });

    $router->post("/2fa/enable", function(){
        \watrlabs\watrkit\ratelimit::guard("2fa_enable", accountUser(), 10, 900, "Too many tries. Wait a few minutes.");
        global $db;
        $me = accountUser();

        if(!empty($me->totp_enabled)){
            return apiError("Two-factor sign in is already on.");
        }

        $secret = totp::open($me->totp_secret);
        $step = $secret ? totp::verify($secret, (string) ($_POST["code"] ?? "")) : null;
        if($step === null){
            return apiError("That code isn't right. Make sure your phone's clock is set automatically and try the newest code.");
        }

        [$codes, $hashes] = totp::newRecoveryCodes();
        $db->table("users")->where("id", $me->id)->update(["totp_enabled"=>1, "totp_last_step"=>$step, "recovery_codes"=>$hashes]);

        // everyone else has to sign in again, with a code this time
        (new sessions())->revokeOthers((int) $me->id);
        achievements::award((int) $me->id, "secure");

        return ["status"=>"okay", "recoveryCodes"=>$codes];
    });

    $router->post("/2fa/disable", function(){
        \watrlabs\watrkit\ratelimit::guard("confirm", accountUser(), 10, 900, "Too many tries. Wait a few minutes.");
        global $db;
        $me = accountUser();

        try {
            confirmIdentity($me, $_POST);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        $db->table("users")->where("id", $me->id)->update(["totp_enabled"=>0, "totp_secret"=>null, "totp_last_step"=>null, "recovery_codes"=>null]);
        return ["status"=>"okay", "message"=>"Two-factor sign in is off."];
    });

    $router->post("/2fa/recovery", function(){
        global $db;
        $me = accountUser();

        if(empty($me->totp_enabled)){
            return apiError("Turn on two-factor sign in first.");
        }

        try {
            confirmIdentity($me, $_POST);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        [$codes, $hashes] = totp::newRecoveryCodes();
        $db->table("users")->where("id", $me->id)->update(["recovery_codes"=>$hashes]);
        return ["status"=>"okay", "recoveryCodes"=>$codes];
    });

    // ---------- sessions ----------

    $router->post("/sessions/{id}/revoke", function($id){
        $me = accountUser();

        if(!ctype_digit($id) || !(new sessions())->revoke((int) $me->id, (int) $id)){
            return apiError("That session is already gone.", 404);
        }
        return ["status"=>"okay"];
    });

    $router->post("/sessions/others", function(){
        $me = accountUser();

        (new sessions())->revokeOthers((int) $me->id);
        return ["status"=>"okay", "message"=>"Signed out everywhere else."];
    });

    // ---------- your data ----------

    $router->get("/export", function(){
        $me = accountUser();

        $data = (new accountdata())->export((int) $me->id);
        header("Content-Type: application/json; charset=utf-8");
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_]/i', '', $me->username) . '-water-games-data.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });

    $router->post("/delete", function(){
        \watrlabs\watrkit\ratelimit::guard("confirm", accountUser(), 10, 900, "Too many tries. Wait a few minutes.");
        $me = accountUser();

        try {
            confirmIdentity($me, $_POST);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        if(strtolower(trim((string) ($_POST["confirm"] ?? ""))) !== strtolower($me->username)){
            return apiError("Type your username to confirm.");
        }

        (new sessions())->destroyAllSessions((int) $me->id);
        (new accountdata())->delete((int) $me->id);

        return ["status"=>"okay"];
    });

});
