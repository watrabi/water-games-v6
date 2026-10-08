<?php

namespace watrlabs\watrkit;

// themes people make for themselves (the ai makes them on request). each one is the full set of css variables the
// built in themes use, worked out from a few colors, with every text color checked against its backgrounds for
// 4.5:1 contrast and nudged until it passes. only the person who made one can use it
class userthemes {

    const MAX_PER_USER = 12;
    const PREFIX = "custom-";
    const CONTRAST = 4.5;

    // the colors someone can set; anything left out is worked out from bg and accent
    const INPUTS = ["bg", "surface", "accent", "link", "text", "muted"];

    static function list(int $userId){
        global $db;
        return $db->table("user_themes")->where("userid", $userId)->orderBy("updated", "DESC")->orderBy("id", "DESC")->get();
    }

    static function get(int $userId, int $id){
        global $db;
        return $db->table("user_themes")->where("id", $id)->where("userid", $userId)->first();
    }

    static function count(int $userId){
        global $db;
        return $db->table("user_themes")->where("userid", $userId)->count();
    }

    // "custom-12" -> 12
    static function idFrom($pref): ?int {
        return is_string($pref) && preg_match('/^' . self::PREFIX . '(\d{1,10})$/', $pref, $m) ? (int) $m[1] : null;
    }

    // [row, notes about colors that got changed]
    static function create(int $userId, string $name, array $colors): array {
        global $db;

        if(self::count($userId) >= self::MAX_PER_USER){
            throw new \LengthException("You can have " . self::MAX_PER_USER . " themes. Delete one in Settings → Appearance first.");
        }

        $name = self::cleanName($name);
        $input = self::pick($colors);
        [$vars, $notes] = self::build($input);

        $now = time();
        $id = (int) $db->table("user_themes")->insert([
            "userid"=>$userId,
            "name"=>$name,
            "input"=>json_encode($input),
            "vars"=>json_encode($vars),
            "created"=>$now,
            "updated"=>$now,
        ]);

        return [self::get($userId, $id), $notes];
    }

    // changes some colors (or the name) of an existing theme. null when it isn't theirs
    static function edit(int $userId, int $id, ?string $name, array $colors): ?array {
        global $db;

        $row = self::get($userId, $id);
        if(!$row){
            return null;
        }

        $input = array_merge(json_decode($row->input, true) ?: [], self::pick($colors));
        [$vars, $notes] = self::build($input);

        $db->table("user_themes")->where("id", $id)->where("userid", $userId)->update([
            "name"=>$name !== null && trim($name) !== "" ? self::cleanName($name) : $row->name,
            "input"=>json_encode($input),
            "vars"=>json_encode($vars),
            "updated"=>time(),
        ]);

        return [self::get($userId, $id), $notes];
    }

    static function delete(int $userId, int $id): bool {
        global $db;

        if(!self::get($userId, $id)){
            return false;
        }

        $db->table("user_themes")->where("id", $id)->where("userid", $userId)->delete();
        // anyone using it goes back to the site default
        $db->table("users")->where("id", $userId)->where("theme", self::PREFIX . $id)->update(["theme"=>null]);
        return true;
    }

    // shaped like the built in themes, for pickers and base.twig
    static function asTheme($row): array {
        $vars = json_decode($row->vars, true) ?: [];
        $id = self::PREFIX . $row->id;

        return [
            "id"=>$id,
            "dbid"=>(int) $row->id,
            "name"=>$row->name,
            "kind"=>"custom",
            "description"=>"Your own theme",
            "color"=>$vars["--bg"] ?? "#002131",
            "swatch"=>[$vars["--bg"] ?? "#002131", $vars["--accent"] ?? "#01537a", $vars["--link"] ?? "#7cc4e4", $vars["--muted"] ?? "#A99985"],
            "emoji"=>null,
            "greeting"=>null,
            "effect"=>null,
            "css"=>self::css($id, $vars),
            "vars"=>$vars,
        ];
    }

    // every value in here was made by build(), so it's only ever hex colors, rgba() and a color-scheme keyword
    static function css(string $id, array $vars): string {
        $lines = [];
        foreach($vars as $name => $value){
            $lines[] = "  $name: $value;";
        }
        return "[data-theme=\"$id\"] {\n" . implode("\n", $lines) . "\n}";
    }

    private static function cleanName(string $name){
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
        if($name === ""){
            $name = "My theme";
        }
        return mb_substr($name, 0, 40);
    }

    // only the known colors, as #rrggbb. anything that isn't a hex color is an error, so the model can fix it
    private static function pick(array $colors): array {
        $out = [];

        foreach(self::INPUTS as $key){
            if(!isset($colors[$key]) || $colors[$key] === ""){
                continue;
            }

            $rgb = self::parse($colors[$key]);
            if(!$rgb){
                throw new \InvalidArgumentException("'$key' needs to be a hex color like #1a2b3c.");
            }
            $out[$key] = self::hex($rgb);
        }

        return $out;
    }

    // the whole set of theme variables from what was given. returns [vars, notes]
    static function build(array $input): array {
        if(empty($input["bg"]) || empty($input["accent"])){
            throw new \InvalidArgumentException("A theme needs at least 'bg' and 'accent'.");
        }

        $notes = [];
        $white = [255, 255, 255];
        $black = [0, 0, 0];

        $bg = self::parse($input["bg"]);
        $accent = self::parse($input["accent"]);
        if(!$bg || !$accent){
            throw new \InvalidArgumentException("'bg' and 'accent' need to be hex colors like #1a2b3c.");
        }
        $light = self::luminance($bg) > 0.4;

        $surface = isset($input["surface"]) ? self::parse($input["surface"]) : ($light ? self::mix($bg, $white, 0.6) : self::mix($bg, $white, 0.05));
        $raised = $light ? self::mix($bg, $black, 0.05) : self::mix($bg, $white, 0.10);
        $raisedHover = $light ? self::mix($bg, $black, 0.09) : self::mix($bg, $white, 0.15);
        $panels = [$bg, $surface, $raised];

        // main text has to be properly readable, so a text color that isn't gets swapped for the default instead of nudged
        $defaultText = $light ? [13, 36, 51] : [245, 241, 237];
        $text = isset($input["text"]) ? self::parse($input["text"]) : $defaultText;
        if(min(array_map(fn($b) => self::contrast($text, $b), $panels)) < self::CONTRAST){
            $notes[] = "text was too close to the background, used " . self::hex($defaultText) . " instead";
            $text = $defaultText;
        }
        $text = self::readable("text", $text, $panels, $light, $notes);

        $muted = isset($input["muted"]) ? self::parse($input["muted"]) : self::mix($text, $bg, 0.4);
        $muted = self::readable("muted", $muted, [$bg, $surface], $light, $notes);

        $link = isset($input["link"]) ? self::parse($input["link"]) : ($light ? self::mix($accent, $black, 0.25) : self::mix($accent, $white, 0.45));
        $link = self::readable("link", $link, [$bg, $surface], $light, $notes);

        $danger = self::readable("danger", $light ? [179, 38, 30] : [240, 138, 122], [$bg, $surface], $light, $notes, false);

        // button text is white unless dark text is clearly better on this accent. if it isn't readable,
        // the accent gets darker (for white text) or lighter (for dark text) until it is
        $onWhite = [255, 255, 255];
        $onDark = [15, 20, 25];
        $onAccent = self::contrast($onWhite, $accent) + 1.5 >= self::contrast($onDark, $accent) ? $onWhite : $onDark;
        $steps = 0;
        while(self::contrast($onAccent, $accent) < self::CONTRAST && $steps < 40){
            $accent = self::mix($accent, $onAccent === $onWhite ? $black : $white, 0.04);
            $steps++;
        }
        if($steps){
            $notes[] = "accent was changed to " . self::hex($accent) . " so button text is readable on it";
        }

        $scrimBase = $light ? [20, 24, 40] : self::mix($bg, $black, 0.5);

        $vars = [
            "--bg"=>self::hex($bg),
            "--surface"=>self::hex($surface),
            "--raised"=>self::hex($raised),
            "--raised-hover"=>self::hex($raisedHover),
            "--accent"=>self::hex($accent),
            // same direction as the built in themes: hover a little lighter, pressed a little darker
            "--accent-hover"=>self::hex(self::mix($accent, $white, 0.1)),
            "--accent-active"=>self::hex(self::mix($accent, $black, 0.12)),
            "--on-accent"=>self::hex($onAccent),
            "--link"=>self::hex($link),
            "--text"=>self::hex($text),
            "--muted"=>self::hex($muted),
            "--danger"=>self::hex($danger),
            "--scrim"=>self::rgba($scrimBase, $light ? 0.35 : 0.6),
            "--scrim-strong"=>self::rgba($scrimBase, $light ? 0.7 : 0.85),
            "--code-bg"=>$light ? "#0b1620" : self::hex(self::mix($bg, $black, 0.5)),
            "color-scheme"=>$light ? "light" : "dark",
        ];

        return [$vars, $notes];
    }

    // moves a color away from its backgrounds until it has enough contrast with all of them
    private static function readable(string $name, array $color, array $backgrounds, bool $light, array &$notes, bool $mention = true){
        $start = $color;
        $toward = $light ? [0, 0, 0] : [255, 255, 255];

        for($i = 0; $i < 40 && min(array_map(fn($b) => self::contrast($color, $b), $backgrounds)) < self::CONTRAST; $i++){
            $color = self::mix($color, $toward, 0.08);
        }

        if($mention && $color !== $start){
            $notes[] = "$name was too close to the background, changed to " . self::hex($color);
        }

        return $color;
    }

    // ---------- color math ----------

    static function parse($value): ?array {
        if(!is_string($value)){
            return null;
        }

        $value = ltrim(trim($value), "#");
        if(preg_match('/^[0-9a-f]{3}$/i', $value)){
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }
        if(!preg_match('/^[0-9a-f]{6}$/i', $value)){
            return null;
        }

        return [hexdec(substr($value, 0, 2)), hexdec(substr($value, 2, 2)), hexdec(substr($value, 4, 2))];
    }

    static function hex(array $rgb): string {
        return sprintf("#%02x%02x%02x", ...array_map(fn($c) => max(0, min(255, (int) round($c))), $rgb));
    }

    private static function rgba(array $rgb, float $alpha){
        return sprintf("rgba(%d, %d, %d, %s)", ...array_merge(array_map(fn($c) => (int) round($c), $rgb), [$alpha]));
    }

    // amount 0 = a, 1 = b
    static function mix(array $a, array $b, float $amount): array {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $amount),
            (int) round($a[1] + ($b[1] - $a[1]) * $amount),
            (int) round($a[2] + ($b[2] - $a[2]) * $amount),
        ];
    }

    // wcag relative luminance
    static function luminance(array $rgb): float {
        $channel = function($c){
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    static function contrast(array $a, array $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
