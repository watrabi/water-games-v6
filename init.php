<?php
use Pixie\Connection;
use Pixie\QueryBuilder\QueryBuilderHandler;
use watrlabs\authentication\authentication;
use watrlabs\watrkit\errors;
use watrlabs\authentication\sessions;

global $db;
global $twig;
global $currentuser;
global $errors;

spl_autoload_register(function ($class_name) {
    $directory = '../classes/';
    $class_name = str_replace('\\', DIRECTORY_SEPARATOR, $class_name);
    $file = $directory . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
    else {
        throw new ErrorException("Failed to include class $class_name");
    }
});

$errors = new errors();

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    
    $config = [
        'driver'    => 'mysql',
        'host'      => $_ENV["DB_HOST"],
        'database'  => $_ENV["DB_NAME"],
        'username'  => $_ENV["DB_USER"],
        'password'  => $_ENV["DB_PASS"],
        'charset'   => 'utf8',
        'collation' => 'utf8_unicode_ci',
        'prefix'    => '', // if you have a prefix for all your tables.
        'options'   => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    ];

    $connection = new Connection('mysql', $config);
    $db = $connection->getQueryBuilder(); 
    
} catch (PDOException $e){
    $errors->displayTextError("Something went wrong and your request was not processed. Please try again later. (DBERR)");
}

$sessions = new sessions();
$currentuser = $sessions->getUserInfoFromCookie();

$loader = new \Twig\Loader\FilesystemLoader('../views');

$twig = new \Twig\Environment($loader, [
    'cache' => __DIR__ . '/storage/cache',
    'auto_reload' => true // should disable this in production 
]);

// makes it so you can do {{ env('KEY') }} in twig to get env variables
$twig->addFunction(new \Twig\TwigFunction('env', function ($key) {
    return $_ENV[$key];
}));


// adds localization & eotd stuff
$twig->addExtension(new app\twig\twigLocalization());
$twig->addExtension(new app\twig\eotdHelper());

// this defines all the current info for the user
$twig->addGlobal('currentuser', $currentuser);
