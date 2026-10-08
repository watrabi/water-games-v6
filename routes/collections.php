<?php
use watrlabs\games\collections;
use watrlabs\watrkit\ratelimit;

global $router; // IMPORTANT: KEEP THIS HERE!

$router->get("/collections", function(){
    global $twig;
    global $currentuser;

    $collections = new collections();

    echo $twig->render("collections.twig", [
        "staff"=>$collections->staffPicks(12),
        "community"=>$collections->browse(),
        "mine"=>$currentuser ? $collections->listFor((int) $currentuser->id) : [],
    ]);
});

$router->get("/collections/mine", function(){
    global $twig;
    global $currentuser;

    requireAccount();

    echo $twig->render("collections.twig", [
        "mineOnly"=>true,
        "mine"=>(new collections())->listFor((int) $currentuser->id),
    ]);
});

$router->get("/collections/{id}", function($id){
    global $twig;
    global $router;
    global $currentuser;

    $collections = new collections();
    $collection = ctype_digit($id) ? $collections->visible((int) $id, $currentuser) : null;
    if(!$collection){
        return $router->return_status(404);
    }

    echo $twig->render("collection.twig", [
        "collection"=>$collection,
        "games"=>$collections->games((int) $collection->id),
        "mine"=>$currentuser && ((int) $currentuser->id === (int) $collection->userid || !empty($currentuser->admin)),
    ]);
});

$router->group('/api/v1/collections', function($router){

    // yours, for the play page's menu. ?game= says which have that game
    $router->get("/", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }

        return ["status"=>"okay", "collections"=>(new collections())->menuFor((int) $currentuser->id, (int) ($_GET["game"] ?? 0))];
    });

    // name, and optionally a game to put in it straight away
    $router->post("/", function(){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        ratelimit::guard("collections", $currentuser, 20, 3600);

        $collections = new collections();
        try {
            $id = $collections->create($currentuser, (string) ($_POST["name"] ?? ""), (string) ($_POST["description"] ?? ""), ($_POST["public"] ?? "1") !== "0");
            if(ctype_digit((string) ($_POST["game"] ?? ""))){
                $collections->add($currentuser, $id, (int) $_POST["game"]);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay", "id"=>$id];
    });

    // add | remove | update | delete | reorder
    $router->post("/{id}/{action}", function($id, $action){
        global $currentuser;

        if(!$currentuser){
            return apiError("You need to be signed in.", 401);
        }
        ratelimit::guard("collections_edit", $currentuser, 300, 3600);

        $collections = new collections();
        $id = (int) $id;

        try {
            switch($action){
                case "add":
                    $collections->add($currentuser, $id, (int) ($_POST["game"] ?? 0));
                    break;
                case "remove":
                    $collections->remove($currentuser, $id, (int) ($_POST["game"] ?? 0));
                    break;
                case "update":
                    $collections->update($currentuser, $id, $_POST);
                    if(!empty($currentuser->admin)){
                        \watrlabs\watrkit\adminlog::add("collection.update", null, $id, (string) ($_POST["name"] ?? ""));
                    }
                    break;
                case "delete":
                    $collections->delete($currentuser, $id);
                    break;
                case "reorder":
                    $collections->reorder($currentuser, $id, (array) ($_POST["games"] ?? []));
                    break;
                default:
                    return apiError("Unknown action.", 404);
            }
        } catch (\InvalidArgumentException $e) {
            return apiError($e->getMessage());
        }

        return ["status"=>"okay"];
    });

});
