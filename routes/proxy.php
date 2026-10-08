<?php
use watrlabs\proxy\proxy;

global $router; // IMPORTANT: KEEP THIS HERE!

// the web proxy. ?url= opens a site straight away (a link to /network?url=wikipedia.org works)
$router->get("/network", function(){
    global $twig;
    global $currentuser;

    if(!proxy::enabled()){
        http_response_code(404);
        echo $twig->render("statusCodes/404.twig");
        return;
    }

    requireAccount();

    $start = trim((string) ($_GET["url"] ?? ""));

    echo $twig->render("network.twig", [
        "networkData"=>json_encode(proxy::clientConfig((int) $currentuser->id) + ["start"=>mb_substr($start, 0, 2000)], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
        "networkVersion"=>proxy::version(),
    ]);
});

// the old address, for bookmarks
$router->get("/proxy", fn() => movedTo("/network"));
