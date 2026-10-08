<?php

namespace watrlabs\watrkit;

// the site's color themes. colors live in base.css (deep) and themes.css (the rest),
// this is the list of them plus the dates the seasonal ones show up on their own
class themes {

    const BASE = [
        "deep"=>["name"=>"Deep", "description"=>"The default. Navy, cream and sand.", "color"=>"#002131", "swatch"=>["#002131", "#01537a", "#7cc4e4", "#A99985"]],
        "abyss"=>["name"=>"Abyss", "description"=>"Close to black, easy on OLED screens.", "color"=>"#05080c", "swatch"=>["#05080c", "#1d5f86", "#7cc4e4", "#8f9aa5"]],
        "reef"=>["name"=>"Reef", "description"=>"Warm browns with a coral accent.", "color"=>"#1c1311", "swatch"=>["#1c1311", "#a8452f", "#f2a58e", "#b8a296"]],
        "foam"=>["name"=>"Foam", "description"=>"Light mode.", "color"=>"#f6f2ec", "swatch"=>["#f6f2ec", "#01537a", "#0a5f8c", "#5f574d"]],
    ];

    // start / end are month-day, inclusive. easter is worked out each year
    const SEASONAL = [
        "newyear"=>["name"=>"New Year", "emoji"=>"🎆", "greeting"=>"Happy New Year!", "start"=>"12-31", "end"=>"01-02", "effect"=>null, "color"=>"#0c0c16", "swatch"=>["#0c0c16", "#c9962a", "#f0d27a", "#a9a6b8"]],
        "valentines"=>["name"=>"Valentine's Day", "emoji"=>"💘", "greeting"=>"Happy Valentine's Day!", "start"=>"02-07", "end"=>"02-14", "effect"=>null, "color"=>"#200f17", "swatch"=>["#200f17", "#b8235a", "#ff9cbf", "#c7a3b1"]],
        "stpatricks"=>["name"=>"St. Patrick's Day", "emoji"=>"☘️", "greeting"=>"Happy St. Patrick's Day!", "start"=>"03-14", "end"=>"03-17", "effect"=>null, "color"=>"#0b1d13", "swatch"=>["#0b1d13", "#1f7a3a", "#86d9a0", "#a3bba8"]],
        "easter"=>["name"=>"Easter", "emoji"=>"🐣", "greeting"=>"Happy Easter!", "start"=>"easter-6", "end"=>"easter+1", "effect"=>null, "color"=>"#fbf8f1", "swatch"=>["#fbf8f1", "#6f46a8", "#5c3a96", "#665d78"]],
        "halloween"=>["name"=>"Halloween", "emoji"=>"🎃", "greeting"=>"Happy Halloween!", "start"=>"10-01", "end"=>"10-31", "effect"=>null, "color"=>"#17101c", "swatch"=>["#17101c", "#b8520f", "#f5a04a", "#b6a2bf"]],
        "christmas"=>["name"=>"Christmas", "emoji"=>"🎄", "greeting"=>"Merry Christmas!", "start"=>"12-01", "end"=>"12-26", "effect"=>"snow", "color"=>"#0d1d16", "swatch"=>["#0d1d16", "#b3202f", "#f2c35a", "#a9bfae"]],
    ];

    static function all(){
        $all = [];
        foreach(self::BASE as $id => $theme){
            $all[$id] = array_merge($theme, ["id"=>$id, "kind"=>"base", "emoji"=>null, "greeting"=>null, "effect"=>null]);
        }
        foreach(self::SEASONAL as $id => $theme){
            $all[$id] = array_merge($theme, ["id"=>$id, "kind"=>"seasonal", "description"=>self::describeDates($id)]);
        }
        return $all;
    }

    static function exists(?string $id){
        return $id !== null && isset(self::all()[$id]);
    }

    static function get(string $id){
        return self::all()[$id] ?? self::all()["deep"];
    }

    // easter sunday (anonymous gregorian algorithm), so we don't need the calendar extension
    static function easter(int $year): \DateTimeImmutable {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf("%04d-%02d-%02d", $year, $month, $day));
    }

    // the [start, end] dates of a season around a given day (handles ranges over new year)
    private static function range(string $id, \DateTimeImmutable $day){
        $season = self::SEASONAL[$id];
        $year = (int) $day->format("Y");

        if(str_starts_with($season["start"], "easter")){
            $easter = self::easter($year);
            return [$easter->modify(substr($season["start"], 6) . " days"), $easter->modify(substr($season["end"], 6) . " days")];
        }

        $start = new \DateTimeImmutable("$year-" . $season["start"]);
        $end = new \DateTimeImmutable("$year-" . $season["end"]);

        if($end < $start){
            // dec 31 -> jan 2: if we're in january the range started last year
            if($day->format("m-d") <= $season["end"]){
                $start = $start->modify("-1 year");
            } else {
                $end = $end->modify("+1 year");
            }
        }

        return [$start, $end];
    }

    static function isActive(string $id, ?\DateTimeImmutable $day = null){
        $day = ($day ?? new \DateTimeImmutable("today"))->setTime(0, 0);
        [$start, $end] = self::range($id, $day);
        return $day >= $start && $day <= $end;
    }

    // which seasonal theme the calendar says it is, if any
    static function currentSeason(?\DateTimeImmutable $day = null){
        foreach(array_keys(self::SEASONAL) as $id){
            if(self::isActive($id, $day)){
                return $id;
            }
        }
        return null;
    }

    static function describeDates(string $id){
        $season = self::SEASONAL[$id];

        if(str_starts_with($season["start"], "easter")){
            $easter = self::easter((int) date("Y"));
            return "The week before Easter (" . $easter->format("F j") . " this year)";
        }

        $start = new \DateTimeImmutable("2000-" . $season["start"]);
        $end = new \DateTimeImmutable("2000-" . $season["end"]);
        return $start->format("M j") . " to " . $end->format("M j");
    }

    static function enabledSeasons(){
        $value = settings::get("seasons_enabled");
        if($value === null){
            return array_keys(self::SEASONAL);
        }
        return array_values(array_filter(explode(",", $value), fn($id) => isset(self::SEASONAL[$id])));
    }

    // what the site uses when someone hasn't picked a theme themselves
    static function siteTheme(){
        $mode = settings::get("seasonal_mode", "auto");

        if(isset(self::SEASONAL[$mode])){
            return $mode;
        }

        if($mode === "auto"){
            $season = self::currentSeason();
            if($season && in_array($season, self::enabledSeasons(), true)){
                return $season;
            }
        }

        $default = settings::get("theme_default", "deep");
        return isset(self::BASE[$default]) ? $default : "deep";
    }

    // order: ?theme= preview, the person's own pick, then the site's choice
    static function resolve($user){
        $preview = $_GET["theme"] ?? null;
        if(is_string($preview) && self::exists($preview)){
            return ["theme"=>self::get($preview), "pref"=>self::preference($user), "auto"=>false];
        }

        $pref = self::preference($user);
        if($pref !== "auto" && ($custom = self::custom($user, $pref))){
            return ["theme"=>$custom, "pref"=>$pref, "auto"=>false];
        }
        if($pref !== "auto" && self::exists($pref)){
            return ["theme"=>self::get($pref), "pref"=>$pref, "auto"=>false];
        }

        return ["theme"=>self::get(self::siteTheme()), "pref"=>"auto", "auto"=>true];
    }

    static function preference($user){
        $pref = ($user && !empty($user->theme)) ? $user->theme : ($_COOKIE["wg_theme"] ?? "auto");
        return self::exists($pref) || self::custom($user, $pref) ? $pref : "auto";
    }

    // one of this person's own themes ("custom-12"), shaped like the others. null if it isn't theirs
    static function custom($user, $pref){
        $id = userthemes::idFrom($pref);
        if(!$user || !$id){
            return null;
        }

        $row = userthemes::get((int) $user->id, $id);
        return $row ? userthemes::asTheme($row) : null;
    }

    // whether this person can switch to this theme id
    static function usable($user, $id){
        return $id === "auto" || self::exists($id) || self::custom($user, $id) !== null;
    }
}
