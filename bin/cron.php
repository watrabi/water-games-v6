<?php
// everything the site does on a timer. run it every 5 minutes:
//
//   */5 * * * * php /www/wwwroot/games.watr.lol/bin/cron.php >> /www/wwwroot/games.watr.lol/storage/logs/cron.log 2>&1
//
//   php bin/cron.php           everything below
//   php bin/cron.php health    just print the health checks
//
// 1. health checks, with an alert (ALERT_WEBHOOK / ALERT_EMAIL) when one starts or stops failing
// 2. bans and mutes whose time is up get lifted (they also lift on their own when the person shows up)
// 3. weekly recaps: the notification, plus the email for people who turned it on. a couple hundred per run
// 4. old rate limit windows get cleaned up

require __DIR__ . "/bootstrap.php";

use watrlabs\watrkit\health;

function out(string $line){
    echo date("c") . " " . $line . "\n";
}

if(($argv[1] ?? "") === "health"){
    foreach(health::checks() as $name => $check){
        echo str_pad($check["ok"] === null ? "--" : ($check["ok"] ? "ok" : "FAIL"), 6) . str_pad($name, 16) . $check["detail"] . "\n";
    }
    echo health::alertsConfigured() ? "alerts go to ALERT_WEBHOOK / ALERT_EMAIL\n" : "no ALERT_WEBHOOK or ALERT_EMAIL set, so nobody hears about failures\n";
    exit;
}

// only one at a time, a slow run shouldn't pile up behind itself
$lock = fopen(sys_get_temp_dir() . "/watr-cron-" . md5(__DIR__) . ".lock", "c");
if(!flock($lock, LOCK_EX | LOCK_NB)){
    out("still running from last time, skipping");
    exit;
}

$changes = health::watch();
foreach($changes["broke"] as $line){
    out("FAIL $line");
}
foreach($changes["fixed"] as $line){
    out("fixed $line");
}

$lifted = \watrlabs\users\moderation::liftExpired();
if($lifted){
    out("lifted $lifted ban(s)/mute(s) that ran out");
}

$sent = 0;
$week = \watrlabs\users\recap::lastWeek();
$due = $db->query(
    "SELECT u.* FROM users u WHERE u.banned = 0 AND (u.recap_week IS NULL OR u.recap_week <> ?)
     AND EXISTS (SELECT 1 FROM user_game_daily d WHERE d.userid = u.id AND d.day BETWEEN ? AND ?) LIMIT 200",
    [$week["id"], $week["start"], $week["end"]]
)->get();
foreach($due as $user){
    if(\watrlabs\users\recap::deliver($user, true)){
        $sent++;
    }
}
if($sent){
    out("sent $sent weekly recap(s) for " . $week["id"]);
}

\watrlabs\watrkit\ratelimit::prune();
