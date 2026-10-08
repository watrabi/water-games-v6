<?php
// the bits of init.php that command line scripts need: classes, .env and the database. no session, no twig
if(PHP_SAPI !== "cli"){
    exit;
}

// the libraries in vendor/ are older than PHP 8.5 and print deprecation notices that would fill the cron log
error_reporting(E_ALL & ~E_DEPRECATED);

spl_autoload_register(function ($class_name) {
    $file = dirname(__DIR__) . '/classes/' . str_replace('\\', DIRECTORY_SEPARATOR, $class_name) . '.php';
    if(file_exists($file)){
        require_once $file;
    }
});

require_once dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();

global $db;
global $currentuser;
$currentuser = null;

$db = (new Pixie\Connection('mysql', [
    'driver'    => 'mysql',
    'host'      => $_ENV["DB_HOST"],
    'database'  => $_ENV["DB_NAME"],
    'username'  => $_ENV["DB_USER"],
    'password'  => $_ENV["DB_PASS"],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'options'   => [
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]
]))->getQueryBuilder();
