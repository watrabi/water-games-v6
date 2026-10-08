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
    // absolute, because php changes the working directory while shutting down (when the error page renders)
    $directory = __DIR__ . '/classes/';
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
        'charset'   => 'utf8mb4', // plain utf8 in mysql is 3 bytes, emoji need 4
        'collation' => 'utf8mb4_unicode_ci',
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
    return $_ENV[$key] ?? null;
}));


// adds localization & eotd stuff
$twig->addExtension(new app\twig\twigLocalization());
$twig->addExtension(new app\twig\eotdHelper());
$twig->addExtension(new app\twig\siteHelper());

// this defines all the current info for the user
$twig->addGlobal('currentuser', $currentuser);

// used by the sidebar to highlight where you are
$twig->addGlobal('path', strtolower(rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/')) ?: '/');

$twig->addGlobal('aiEnabled', \watrlabs\ai\config::enabled());
$twig->addGlobal('aiName', \watrlabs\ai\config::name());
$twig->addGlobal('aiMemory', in_array('memory', \watrlabs\ai\config::tools(), true));

// red badge on an admin's avatar while there are chat reports to look at
$twig->addGlobal('openReports', $currentuser && !empty($currentuser->admin) ? \watrlabs\social\chat::openReports() : 0);

// the bell in the top bar
$unreadNotifications = 0;
if($currentuser){
    try {
        $unreadNotifications = (new \watrlabs\social\notifications())->unread((int) $currentuser->id);
    } catch (\Throwable $e) {} // before migrations
}
$twig->addGlobal('unreadNotifications', $unreadNotifications);

// themes: which one to draw with, what the person picked, and the list for pickers
$themeState = \watrlabs\watrkit\themes::resolve($currentuser);
$twig->addGlobal('theme', $themeState["theme"]);
$twig->addGlobal('themePref', $themeState["pref"]);
$twig->addGlobal('themes', \watrlabs\watrkit\themes::all());
$twig->addGlobal('customThemes', $currentuser ? array_map([\watrlabs\watrkit\userthemes::class, 'asTheme'], \watrlabs\watrkit\userthemes::list((int) $currentuser->id)) : []);
$twig->addGlobal('effectsOn', ($_COOKIE["wg_effects"] ?? "on") !== "off");

// the strip under the navbar: an admin announcement, or a seasonal greeting
$announcement = trim((string) \watrlabs\watrkit\settings::get("announcement", ""));
$banner = null;
if($announcement !== ""){
    $banner = ["id"=>"a" . substr(md5($announcement), 0, 10), "text"=>$announcement, "emoji"=>null];
} elseif($themeState["theme"]["kind"] === "seasonal" && \watrlabs\watrkit\settings::bool("seasonal_greeting", true)){
    $banner = ["id"=>$themeState["theme"]["id"] . date("Y"), "text"=>$themeState["theme"]["greeting"], "emoji"=>$themeState["theme"]["emoji"]];
}
if($banner && ($_COOKIE["wg_dismissed"] ?? "") === $banner["id"]){
    $banner = null;
}
$twig->addGlobal('banner', $banner);
$twig->addGlobal('csrf', \watrlabs\watrkit\csrf::token());
$twig->addGlobal('siteUrl', \watrlabs\watrkit\site::url());

$twig->addGlobal('captcha', [
    "enabled"=>\watrlabs\authentication\security::captchaActive(),
    "siteKey"=>$_ENV["TurnstileSiteKey"] ?? "",
]);
