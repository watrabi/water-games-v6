<?php
// loads the classes and .env the same way init.php does, minus the request/session/twig parts.
// $db is only set up when the database in .env is reachable; the database tests skip otherwise

require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../classes/' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

// tests must never reach a real realtime server or send real mail
$_ENV["REALTIME_URL"] = "";
$_ENV["MAIL_HOST"] = "";
$_ENV["APP_DEBUG"] = "false";
$_ENV["encryptionKey"] = $_ENV["encryptionKey"] ?? "test-key";

global $db;
$db = null;

try {
    $connection = new Pixie\Connection('mysql', [
        'driver'=>'mysql',
        'host'=>$_ENV["DB_HOST"] ?? '127.0.0.1',
        'database'=>$_ENV["DB_NAME"] ?? '',
        'username'=>$_ENV["DB_USER"] ?? '',
        'password'=>$_ENV["DB_PASS"] ?? '',
        'charset'=>'utf8mb4',
        'collation'=>'utf8mb4_unicode_ci',
        'prefix'=>'',
        'options'=>[PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION],
    ]);
    $db = $connection->getQueryBuilder();
} catch (\Throwable $e) {
    $db = null;
}
