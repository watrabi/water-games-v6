<?php
// local dev only: php -S localhost:8000 -t public dev-router.php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path !== "/" && is_file(__DIR__ . "/public" . $path)) {
    return false;
}
chdir(__DIR__ . "/public");
require __DIR__ . "/public/index.php";
