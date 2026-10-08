<?php
use watrlabs\ai\config;
use watrlabs\ai\chats;
use watrlabs\ai\assistant;
use watrlabs\ai\artifacts;
use watrlabs\ai\memories;

global $router; // IMPORTANT: KEEP THIS HERE!

// the saved messages, shaped for the browser (no raw image data, no thinking signatures)
function aiDisplayMessages(array $messages){
    $out = [];

    foreach($messages as $message){
        $content = [];

        foreach($message["content"] as $block){
            switch($block["type"]){
                case "text":
                case "notice":
                    $content[] = $block;
                    break;
                case "tool_use":
                    $content[] = ["type"=>"tool_use", "id"=>$block["id"], "name"=>$block["name"], "input"=>$block["input"]];
                    break;
                case "image":
                    $content[] = ["type"=>"image", "url"=>"/ai/attachments/" . (int) $block["attachment"]];
                    break;
                case "reasoning":
                    $content[] = ["type"=>"thinking", "text"=>$block["text"]];
                    break;
                case "thinking":
                    $content[] = ["type"=>"thinking", "text"=>$block["thinking"] ?? ""];
                    break;
                case "redacted_thinking":
                    $content[] = ["type"=>"thinking", "text"=>""];
                    break;
                case "tool_result":
                    $content[] = [
                        "type"=>"tool_result",
                        "tool_use_id"=>$block["tool_use_id"],
                        "content"=>mb_substr($block["content"], 0, 2000),
                        "is_error"=>!empty($block["is_error"]),
                    ];
                    break;
            }
        }

        $out[] = ["role"=>$message["role"], "visible"=>$message["visible"], "content"=>$content];
    }

    return $out;
}

function aiPage(?int $chatId){
    global $twig;
    global $router;
    global $currentuser;

    if(!config::enabled()){
        echo $twig->render('ai.twig', ["state"=>"disabled"]);
        return;
    }

    if(!$currentuser){
        echo $twig->render('ai.twig', ["state"=>"guest"]);
        return;
    }

    $chats = new chats();
    $chat = null;
    $messages = [];

    if($chatId !== null){
        $chat = $chats->get($chatId, $currentuser->id);
        if(!$chat){
            return $router->return_status(404);
        }
        $messages = aiDisplayMessages($chats->messages($chat->id));
    }

    $limit = config::dailyLimit();
    $default = config::defaultModel();

    $data = [
        "chatId"=>$chat ? (int) $chat->id : null,
        "title"=>$chat ? $chat->title : null,
        "model"=>$chat && config::model($chat->model) ? $chat->model : $default["id"],
        "defaultModel"=>$default["id"],
        "models"=>config::publicModels(),
        "messages"=>$messages,
        "remaining"=>$limit > 0 ? max(0, $limit - $chats->promptsToday($currentuser->id)) : null,
    ];

    echo $twig->render('ai.twig', [
        "state"=>"ready",
        "chat"=>$chat,
        "chatList"=>$chats->listFor($currentuser->id),
        "models"=>config::publicModels(),
        // json for the page script, escaped so it can't break out of the <script> tag
        "aiData"=>json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
    ]);
}

$router->get("/ai", function(){
    return aiPage(null);
});

// everything the ai has made for you
$router->get("/ai/artifacts", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render('ai-artifacts.twig', [
        "artifacts"=>(new artifacts())->listFor((int) $currentuser->id),
    ]);
});

// one artifact on its own. this is our domain, so it's served with a CSP sandbox: the page gets an opaque
// origin and can't read cookies, call the api or touch the rest of the site, same as the iframe preview
$router->get("/ai/artifacts/{chat}/{ref}/{version}", function($chat, $ref, $version){
    global $currentuser;
    global $router;

    if(!$currentuser || !ctype_digit($chat) || !ctype_digit($version) || !preg_match('/^[a-z0-9-]{1,64}$/', $ref)){
        return $router->return_status(404);
    }

    $artifact = (new artifacts())->find((int) $currentuser->id, (int) $chat, $ref, (int) $version);
    if(!$artifact){
        return $router->return_status(404);
    }

    $types = [
        "html"=>["text/html; charset=utf-8", "html"],
        "svg"=>["image/svg+xml; charset=utf-8", "svg"],
        "markdown"=>["text/plain; charset=utf-8", "md"],
        "code"=>["text/plain; charset=utf-8", "txt"],
    ];
    [$mime, $ext] = $types[$artifact->type] ?? $types["code"];

    if(!empty($_GET["download"])){
        $codeExt = ["python"=>"py", "javascript"=>"js", "typescript"=>"ts", "php"=>"php", "css"=>"css", "json"=>"json", "java"=>"java",
            "c"=>"c", "cpp"=>"cpp", "csharp"=>"cs", "go"=>"go", "rust"=>"rs", "ruby"=>"rb", "bash"=>"sh", "shell"=>"sh", "sql"=>"sql", "lua"=>"lua"];
        if($artifact->type === "code" && isset($codeExt[$artifact->language])){
            $ext = $codeExt[$artifact->language];
        }
        header('Content-Disposition: attachment; filename="' . $artifact->ref . "." . $ext . '"');
        $mime = $artifact->type === "html" ? "text/html; charset=utf-8" : $mime;
    }

    header("Content-Type: " . $mime);
    header("Content-Security-Policy: sandbox allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer");
    header("Cache-Control: private, no-store");
    echo $artifact->content;
    exit;
});

$router->post("/api/v1/ai/artifacts/{id}/delete", function($id){
    global $currentuser;

    if(!$currentuser){
        return apiError("You need to be signed in.", 401);
    }

    if(!ctype_digit($id) || !(new artifacts())->delete((int) $currentuser->id, (int) $id)){
        return apiError("That artifact doesn't exist.", 404);
    }

    return ["status"=>"okay"];
});

// what the ai remembers about you, and the switch to turn it off
$router->get("/ai/memory", function(){
    global $twig;
    global $currentuser;
    global $router;

    requireAccount();

    if(!in_array("memory", config::tools(), true)){
        return $router->return_status(404);
    }

    echo $twig->render('ai-memory.twig', [
        "memories"=>memories::list((int) $currentuser->id),
        "memoryOn"=>memories::isOn((int) $currentuser->id),
        "maxMemories"=>memories::MAX_MEMORIES,
        "maxChars"=>memories::MAX_CHARS,
    ]);
});

$router->group('/api/v1/ai/memories', function($router) {

    $router->post("/add", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        try {
            $id = memories::add((int) $currentuser->id, (string) ($_POST["content"] ?? ""));
        } catch (\InvalidArgumentException | \LengthException $e) {
            return apiError($e->getMessage());
        }

        $memory = memories::get((int) $currentuser->id, $id);
        return ["status"=>"okay", "id"=>$id, "content"=>$memory->content];
    });

    $router->post("/{id}/delete", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        if(!ctype_digit($id) || !memories::delete((int) $currentuser->id, (int) $id)){
            return apiError("That memory doesn't exist.", 404);
        }

        return ["status"=>"okay"];
    });

    $router->post("/clear", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        memories::clear((int) $currentuser->id);
        return ["status"=>"okay"];
    });

    $router->post("/toggle", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $on = !empty($_POST["on"]) && $_POST["on"] !== "0";
        memories::setOn((int) $currentuser->id, $on);
        return ["status"=>"okay", "on"=>$on];
    });

});

$router->get("/ai/{id}", function($id){
    global $router;

    if(!ctype_digit($id)){
        return $router->return_status(404);
    }

    return aiPage((int) $id);
});

// images people attached, only to the person who uploaded them
$router->get("/ai/attachments/{id}", function($id){
    global $currentuser;
    global $router;

    if(!$currentuser || !ctype_digit($id)){
        return $router->return_status(404);
    }

    $chats = new chats();
    $attachment = $chats->attachment((int) $id, $currentuser->id);
    $file = $attachment ? $chats->attachmentFile($attachment) : null;

    if(!$file || !is_file($file)){
        return $router->return_status(404);
    }

    header("Content-Type: " . $attachment->mime);
    header("Content-Length: " . filesize($file));
    header("Cache-Control: private, max-age=86400");
    header("X-Content-Type-Options: nosniff");
    readfile($file);
    exit;
});

$router->group('/api/v1/ai', function($router) {

    $router->post("/upload", function(){
        \watrlabs\watrkit\ratelimit::guard("ai_upload", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 20, 600);
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        if(!config::enabled()){
            return apiError("The AI isn't set up.", 404);
        }

        $chats = new chats();

        if($chats->uploadsToday($currentuser->id) >= config::dailyUploads()){
            return apiError("You've uploaded a lot of images today. Try again tomorrow.", 429);
        }

        if(!isset($_FILES["image"])){
            return apiError("No image was sent.");
        }

        try {
            $stored = $chats->storeUpload($currentuser->id, $_FILES["image"]);
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "id"=>$stored["id"], "url"=>"/ai/attachments/" . $stored["id"]];
    });

    // streams the answer back as server-sent events
    $router->post("/send", function(){
        \watrlabs\watrkit\ratelimit::guard("ai_send", \watrlabs\watrkit\ratelimit::who($GLOBALS["currentuser"]), 10, 60, "You're sending messages really fast. Wait a moment.");
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        if(!config::enabled()){
            return apiError("The AI isn't set up.", 404);
        }

        $request = json_decode(file_get_contents("php://input"), true);
        if(!is_array($request)){
            return apiError("Bad request.");
        }

        // keep going if the tab closes so the answer (or what there is of it) still gets saved
        ignore_user_abort(true);
        set_time_limit(0);

        header("Content-Type: text/event-stream; charset=utf-8");
        header("Cache-Control: no-cache, no-transform");
        header("X-Accel-Buffering: no"); // nginx would hold the stream otherwise

        while(ob_get_level() > 0){
            ob_end_flush();
        }

        $emit = function(array $event){
            echo "data: " . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };

        try {
            (new assistant($emit))->run($currentuser, $request);
        } catch (\Throwable $e) {
            $debug = ($_ENV["APP_DEBUG"] ?? "false") === "true";
            $emit(["type"=>"error", "message"=>$debug ? "Error: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")" : "Something went wrong on our end."]);
        }

        // the router's output buffer is already gone, so stop here
        exit;
    });

    $router->post("/chats/{id}/rename", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $chats = new chats();
        $chat = ctype_digit($id) ? $chats->get((int) $id, $currentuser->id) : null;
        if(!$chat){
            return apiError("That chat doesn't exist.", 404);
        }

        $title = trim(preg_replace('/\s+/', ' ', $_POST["title"] ?? ""));
        if($title === "" || mb_strlen($title) > 120){
            return apiError("Titles have to be 1 to 120 characters.");
        }

        $chats->rename($chat->id, $title);

        return ["status"=>"okay", "title"=>$title];
    });

    $router->post("/chats/{id}/delete", function($id){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        $chats = new chats();
        $chat = ctype_digit($id) ? $chats->get((int) $id, $currentuser->id) : null;
        if(!$chat){
            return apiError("That chat doesn't exist.", 404);
        }

        $chats->delete($chat->id, $currentuser->id);

        return ["status"=>"okay"];
    });

});
