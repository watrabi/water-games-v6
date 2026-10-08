<?php

namespace watrlabs\users;

use watrlabs\games\playtime;
use watrlabs\social\notifications;
use watrlabs\watrkit\mail;
use watrlabs\watrkit\site;

// "your week": what you played monday to sunday, from user_game_daily. it's delivered once per week per person
// (users.recap_week): as a notification the first time they open /home after the week ends, or by bin/cron.php,
// which also emails people who turned that on in settings
class recap {

    // the ISO week id ("2026-41") and its monday, for the last full week before $now
    static function lastWeek(?int $now = null): array {
        $monday = strtotime("monday last week", $now ?? time());
        // "monday last week" on a monday is a week ago, which is what we want
        return ["id"=>date("o-W", $monday), "start"=>date("Y-m-d", $monday), "end"=>date("Y-m-d", strtotime("+6 days", $monday))];
    }

    // "2026-41" -> its monday..sunday, or null for nonsense
    static function week(string $id): ?array {
        if(!preg_match('/^(\d{4})-(\d{2})$/', $id, $m)){
            return null;
        }
        $monday = (new \DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1);
        if($monday->format("o-W") !== $id){
            return null;
        }
        return ["id"=>$id, "start"=>$monday->format("Y-m-d"), "end"=>$monday->modify("+6 days")->format("Y-m-d")];
    }

    // everything for one person and one week. null when they didn't play at all
    static function build(int $userId, array $week): ?array {
        global $db;

        $days = $db->query(
            "SELECT day, SUM(seconds) AS seconds FROM user_game_daily WHERE userid = ? AND day BETWEEN ? AND ? GROUP BY day ORDER BY day",
            [$userId, $week["start"], $week["end"]]
        )->get();

        $total = array_sum(array_map(fn($d) => (int) $d->seconds, $days));
        if($total < 60){
            return null;
        }

        $games = $db->query(
            "SELECT g.id, g.type, g.name, g.gameIcon, SUM(d.seconds) AS seconds FROM user_game_daily d INNER JOIN games g ON g.id = d.gameid
             WHERE d.userid = ? AND d.day BETWEEN ? AND ? GROUP BY g.id, g.type, g.name, g.gameIcon ORDER BY seconds DESC",
            [$userId, $week["start"], $week["end"]]
        )->get();

        $from = strtotime($week["start"] . " 00:00:00");
        $to = strtotime($week["end"] . " 23:59:59");

        $newGames = (int) $db->query("SELECT COUNT(*) AS n FROM playtime WHERE userid = ? AND first_played BETWEEN ? AND ?", [$userId, $from, $to])->first()->n;

        $achievements = [];
        foreach($db->table("user_achievements")->where("userid", $userId)->where("created", ">=", $from)->where("created", "<=", $to)->get() as $row){
            if(isset(\watrlabs\social\achievements::ALL[$row->code])){
                $achievements[] = \watrlabs\social\achievements::ALL[$row->code][0];
            }
        }

        $bests = (int) $db->table("game_scores")->where("userid", $userId)->where("period", $week["id"])->count();

        // a bar for each day of the week, even the empty ones
        $chart = [];
        $byDay = [];
        foreach($days as $d){
            $byDay[$d->day] = (int) $d->seconds;
        }
        for($i = 0; $i < 7; $i++){
            $day = date("Y-m-d", strtotime($week["start"] . " +$i days"));
            $chart[] = ["day"=>$day, "label"=>date("D", strtotime($day)), "seconds"=>$byDay[$day] ?? 0];
        }

        $top = $games[0] ?? null;

        return [
            "week"=>$week,
            "total"=>$total,
            "daysPlayed"=>count($days),
            "games"=>array_slice($games, 0, 5),
            "gameCount"=>count($games),
            "top"=>$top,
            "newGames"=>$newGames,
            "achievements"=>$achievements,
            "scores"=>$bests,
            "chart"=>$chart,
            "summary"=>playtime::format($total) . " played" . ($top ? ", mostly " . $top->name : ""),
        ];
    }

    // sends last week's recap if it hasn't gone out yet. $email: also email it (the cron does, page views don't)
    static function deliver($user, bool $email = false, ?int $now = null): ?array {
        global $db;

        $week = self::lastWeek($now);
        if(($user->recap_week ?? null) === $week["id"]){
            return null;
        }

        // claim it first, so two requests at once don't both send it
        [$claimed] = $db->statement(
            "UPDATE users SET recap_week = ? WHERE id = ? AND (recap_week IS NULL OR recap_week <> ?)",
            [$week["id"], (int) $user->id, $week["id"]]
        );
        $user->recap_week = $week["id"];
        if(!$claimed->rowCount()){
            return null;
        }

        $recap = self::build((int) $user->id, $week);
        if(!$recap){
            return null; // nothing played, nothing to say
        }

        notifications::send((int) $user->id, "recap", null, ["week"=>$week["id"], "summary"=>$recap["summary"]]);

        if($email && !empty($user->recap_email) && !empty($user->email) && mail::configured()){
            mail::send($user->email, "Your week on " . ($_ENV["APP_NAME"] ?? "Water Games"), self::emailText($user, $recap));
        }

        return $recap;
    }

    static function emailText($user, array $recap): string {
        $lines = [
            "Hey " . $user->username . ",",
            "",
            "Here's your week (" . date("M j", strtotime($recap["week"]["start"])) . " to " . date("M j", strtotime($recap["week"]["end"])) . "):",
            "",
            "- " . playtime::format($recap["total"]) . " played over " . $recap["daysPlayed"] . " " . ($recap["daysPlayed"] === 1 ? "day" : "days"),
        ];
        if($recap["top"]){
            $lines[] = "- Most played: " . $recap["top"]->name . " (" . playtime::format((int) $recap["top"]->seconds) . ")";
        }
        if($recap["newGames"]){
            $lines[] = "- " . $recap["newGames"] . " new " . ($recap["newGames"] === 1 ? "game" : "games") . " tried";
        }
        foreach($recap["achievements"] as $name){
            $lines[] = "- Earned: " . $name;
        }
        $lines[] = "";
        $lines[] = "The whole thing: " . site::url() . "/recap";
        $lines[] = "";
        $lines[] = "You get this because you turned on weekly emails. Turn them off in Settings: " . site::url() . "/settings";

        return implode("\n", $lines);
    }

    // for /home: delivers the notification if it's due, and shows the card monday to wednesday
    static function forHome($user): ?array {
        try {
            $recap = self::deliver($user);
            if((int) date("N") > 3){
                return null;
            }
            return $recap ?? self::build((int) $user->id, self::lastWeek());
        } catch (\Throwable $e) {
            return null; // before migrations
        }
    }
}
