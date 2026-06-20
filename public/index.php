<?php

use watrlabs\watrkit\routing;
use watrlabs\authentication\authentication;
use watrlabs\watrkit\maintenance;

require_once '../init.php';

global $db;
global $router;
global $twig;

if(isset($_ENV["APP_DEBUG"])){
    if($_ENV["APP_DEBUG"] !== "true"){
        error_reporting(0);
    }
}

$maintenance = new maintenance();
$isSoftMaintenanceEnabled = $maintenance::isSoftMaintenanceEnabled();


$maintenanceCallBack = function() {
    global $twig;

    echo $twig->render('maintenance.twig');
};

$maintenance->setMaintenancePage($maintenanceCallBack);

if($isSoftMaintenanceEnabled){
    if($maintenance->shouldShowMaintenance()){
        $maintenance->showMaintenance();
    }
}

ob_start();

$auth = new authentication();
$router = new routing();

$routers = [
    "web",
];

foreach ($routers as $r) {
    $router->addrouter($r);
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = strtolower($uri);
$method = $_SERVER['REQUEST_METHOD'];
$response = $router->dispatch($uri, $method);

ob_end_flush();