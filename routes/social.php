<?php
use watrlabs\social\friends;
use watrlabs\social\chat;
use watrlabs\social\groups;
use watrlabs\social\notifications;

global $router; // IMPORTANT: KEEP THIS HERE!

// chat images, only to the two people in the conversation (and admins)
$router->get("/chat/images/{id}", function($id){
    global $currentuser;
    global $router;

    $chat = new chat();
    $image = ctype_digit($id) ? $chat->imageFor((int) $id, $currentuser) : null;
    $file = $image ? chat::imageFile($image) : null;

    if(!$file || !is_file($file)){
        return $router->return_status(404);
    }

    header("Content-Type: " . $image->mime);
    header("Content-Length: " . filesize($file));
    header("Cache-Control: private, max-age=86400");
    header("X-Content-Type-Options: nosniff");
    readfile($file);
    exit;
});

function socialUser(){
    global $currentuser;

    if(!$currentuser){
        http_response_code(401);
        header("Content-Type: application/json");
        exit(json_encode(["status"=>"error", "message"=>"You need to be signed in."]));
    }

    return $currentuser;
}

$router->group('/api/v1/social', function($router){

    // everything the tray needs when it first opens
    $router->get("/state", function(){
        $me = socialUser();
        chat::touchPresence($me);

        global $db;
        $friends = new friends();
        $last = $db->query("SELECT MAX(id) AS lastid FROM chat_messages WHERE sender_id = ? OR recipient_id = ?", [$me->id, $me->id])->get();

        return [
            "status"=>"okay",
            "me"=>["id"=>(int) $me->id, "username"=>$me->username],
            "friends"=>$friends->list($me->id),
            "requests"=>$friends->requests($me->id),
            "blocked"=>$friends->blockedList($me->id),
            "lastId"=>(int) ($last[0]->lastid ?? 0),
            "groups"=>(new groups())->listFor($me->id),
            "lastGroupId"=>(new groups())->lastIdFor($me->id),
            "reasons"=>chat::REPORT_REASONS,
            "realtime"=>\watrlabs\social\realtime::clientConfig((int) $me->id),
        ];
    });

    $router->get("/poll", function(){
        $me = socialUser();
        chat::touchPresence($me);

        $chat = new chat();
        return ["status"=>"okay"] + $chat->poll($me->id, max(0, (int) ($_GET["since"] ?? 0)), max(0, (int) ($_GET["groupSince"] ?? 0)));
    });

    $router->get("/search", function(){
        $me = socialUser();
        $friends = new friends();

        return ["status"=>"okay", "results"=>$friends->search($me->id, trim($_GET["q"] ?? ""))];
    });

    $router->post("/friends/{id}/{action}", function($id, $action){
        $me = socialUser();
        $friends = new friends();

        if(!ctype_digit($id)){
            return apiError("That person doesn't exist.", 404);
        }

        try {
            $relation = $friends->act($me->id, (int) $id, $action);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "relation"=>$relation];
    });

    $router->get("/messages/{id}", function($id){
        $me = socialUser();
        $chat = new chat();
        $friends = new friends();

        if(!ctype_digit($id)){
            return apiError("That person doesn't exist.", 404);
        }

        $other = (int) $id;
        $relation = $friends->relation($me->id, $other);

        // old messages stay readable after unfriending, but not across a block
        if(in_array($relation, ["blocked", "blockedby", "self"], true)){
            return ["status"=>"okay", "messages"=>[], "more"=>false, "relation"=>$relation];
        }

        $before = isset($_GET["before"]) ? (int) $_GET["before"] : null;
        $page = $chat->history($me->id, $other, $before);

        if(!$before){
            $chat->markRead($me->id, $other);
        }

        return ["status"=>"okay", "relation"=>$relation] + $page;
    });

    $router->post("/messages/{id}", function($id){
        $me = socialUser();
        $chat = new chat();

        if(!ctype_digit($id)){
            return apiError("That person doesn't exist.", 404);
        }

        try {
            $message = $chat->send($me->id, (int) $id, (string) ($_POST["body"] ?? ""), !empty($_POST["image"]) ? (int) $_POST["image"] : null);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>$message];
    });

    $router->post("/read/{id}", function($id){
        $me = socialUser();
        (new chat())->markRead($me->id, (int) $id);
        return ["status"=>"okay"];
    });

    $router->post("/images", function(){
        $me = socialUser();

        if(!isset($_FILES["image"])){
            return apiError("No image was sent.");
        }

        try {
            return ["status"=>"okay"] + (new chat())->storeImage($me->id, $_FILES["image"]);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }
    });

    // ---------- group chats ----------

    $router->get("/groups", function(){
        $me = socialUser();
        return ["status"=>"okay", "groups"=>(new groups())->listFor($me->id)];
    });

    $router->post("/groups", function(){
        $me = socialUser();

        try {
            $id = (new groups())->create($me->id, (string) ($_POST["name"] ?? ""), (array) ($_POST["members"] ?? []));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "id"=>$id];
    });

    $router->get("/groups/{id}/messages", function($id){
        $me = socialUser();
        $groups = new groups();

        try {
            $before = isset($_GET["before"]) ? (int) $_GET["before"] : null;
            $page = $groups->history($me->id, (int) $id, $before);
            if(!$before){
                $groups->markRead($me->id, (int) $id);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage(), 404);
        }

        return ["status"=>"okay"] + $page;
    });

    $router->post("/groups/{id}/messages", function($id){
        $me = socialUser();

        try {
            $message = (new groups())->send($me->id, (int) $id, (string) ($_POST["body"] ?? ""), !empty($_POST["image"]) ? (int) $_POST["image"] : null);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>$message];
    });

    $router->post("/groups/{id}/read", function($id){
        $me = socialUser();
        (new groups())->markRead($me->id, (int) $id);
        return ["status"=>"okay"];
    });

    // add | remove | leave | rename
    $router->post("/groups/{id}/{action}", function($id, $action){
        $me = socialUser();
        $groups = new groups();

        try {
            switch($action){
                case "add":
                    $groups->add($me->id, (int) $id, (array) ($_POST["members"] ?? []));
                    break;
                case "remove":
                    $groups->remove($me->id, (int) $id, (int) ($_POST["user"] ?? 0));
                    break;
                case "leave":
                    $groups->leave($me->id, (int) $id);
                    break;
                case "rename":
                    $groups->rename($me->id, (int) $id, (string) ($_POST["name"] ?? ""));
                    break;
                default:
                    return apiError("Unknown action.", 404);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay"];
    });

    $router->post("/group-report/{id}", function($id){
        $me = socialUser();

        try {
            (new groups())->report($me->id, (int) $id, (string) ($_POST["reason"] ?? ""), (string) ($_POST["details"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>"Thanks, a moderator will take a look."];
    });

    $router->post("/report/{id}", function($id){
        $me = socialUser();

        try {
            (new chat())->report($me->id, (int) $id, (string) ($_POST["reason"] ?? ""), (string) ($_POST["details"] ?? ""));
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "message"=>"Thanks, a moderator will take a look."];
    });

});

$router->group('/api/v1/notifications', function($router){

    $router->get("/", function(){
        $me = socialUser();
        $notifications = new notifications();
        notifications::prune();

        $before = ctype_digit((string) ($_GET["before"] ?? "")) ? (int) $_GET["before"] : null;
        return ["status"=>"okay", "unread"=>$notifications->unread($me->id)] + $notifications->list($me->id, $before, 15);
    });

    $router->get("/count", function(){
        $me = socialUser();
        return ["status"=>"okay", "unread"=>(new notifications())->unread($me->id)];
    });

    $router->post("/read", function(){
        $me = socialUser();
        $id = ctype_digit((string) ($_POST["id"] ?? "")) ? (int) $_POST["id"] : null;
        (new notifications())->markRead($me->id, $id);
        return ["status"=>"okay"];
    });

});
