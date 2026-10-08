<?php

namespace watrlabs\ai;

use watrlabs\watrkit\themes;
use watrlabs\watrkit\userthemes;

// lets the ai make site themes for the person it's chatting with. they only ever apply to that person,
// and the colors go through userthemes, which fixes anything that would be hard to read
class themetools {

    const TOOL_NAMES = ["create_theme", "edit_theme", "use_theme"];

    static function enabled(): bool {
        return in_array("themes", config::tools(), true);
    }

    static function handles(string $name): bool {
        return in_array($name, self::TOOL_NAMES, true);
    }

    private static function colorProperties(): array {
        return [
            "bg"=>["type"=>"string", "description"=>"Page background, hex. Very dark for a dark theme, very light for a light one"],
            "accent"=>["type"=>"string", "description"=>"Buttons, highlights and the selected nav item, hex. The theme's main color"],
            "surface"=>["type"=>"string", "description"=>"Optional. Cards, menus and panels, hex, a small step from bg"],
            "text"=>["type"=>"string", "description"=>"Optional. Main text, hex"],
            "muted"=>["type"=>"string", "description"=>"Optional. Secondary text like dates and hints, hex"],
            "link"=>["type"=>"string", "description"=>"Optional. Links and small highlights, hex, usually a brighter take on the accent"],
        ];
    }

    static function definitions(): array {
        return [
            [
                "name"=>"create_theme",
                "description"=>"Make a color theme for the whole site that only this user sees, and switch them to it. Give hex colors; bg and accent are required and the rest are worked out from them if you leave them out. Colors that would be hard to read get adjusted automatically.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>["name"=>["type"=>"string", "description"=>"Short theme name, e.g. Neon Night"]] + self::colorProperties(),
                    "required"=>["name", "bg", "accent"],
                ],
            ],
            [
                "name"=>"edit_theme",
                "description"=>"Change some colors or the name of one of this user's own themes (\"make it more purple\"). Only pass what changes. If they're using it, the site updates straight away.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "id"=>["type"=>"integer", "description"=>"The theme's number, e.g. 12 for [t12]"],
                        "name"=>["type"=>"string", "description"=>"Optional new name"],
                    ] + self::colorProperties(),
                    "required"=>["id"],
                ],
            ],
            [
                "name"=>"use_theme",
                "description"=>"Switch this user to a theme: one of their own (custom-12 for [t12]), a built in one by id, or auto for the site default.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "theme"=>["type"=>"string", "description"=>"custom-<number>, a built in theme id, or auto"],
                    ],
                    "required"=>["theme"],
                ],
            ],
        ];
    }

    // [content, isError, event for the browser or null]
    static function run(array $call, $user): array {
        if(!empty($call["invalid_input"])){
            return ["The tool input wasn't valid JSON. Try the call again.", true, null];
        }

        $userId = (int) $user->id;
        $input = is_array($call["input"]) ? $call["input"] : [];

        try {
            switch($call["name"]){
                case "create_theme":
                    [$row, $notes] = userthemes::create($userId, is_string($input["name"] ?? null) ? $input["name"] : "", $input);
                    self::setPreference($userId, userthemes::PREFIX . $row->id);
                    $theme = userthemes::asTheme($row);
                    return [self::describe("Made theme [t{$row->id}] \"{$row->name}\" and switched them to it.", $theme, $notes), false, self::event($theme)];

                case "edit_theme":
                    $id = (int) ($input["id"] ?? 0);
                    $result = userthemes::edit($userId, $id, is_string($input["name"] ?? null) ? $input["name"] : null, $input);
                    if(!$result){
                        return ["There's no theme [t$id] of theirs.", true, null];
                    }
                    [$row, $notes] = $result;
                    $theme = userthemes::asTheme($row);
                    $active = self::preference($userId) === $theme["id"];
                    return [self::describe("Updated [t{$row->id}] \"{$row->name}\"" . ($active ? ", it's showing now." : ". They aren't using it right now, use_theme switches to it."), $theme, $notes), false, $active ? self::event($theme) : null];

                case "use_theme":
                    $id = is_string($input["theme"] ?? null) ? trim($input["theme"]) : "";
                    if(preg_match('/^t(\d+)$/', $id, $m)){
                        $id = userthemes::PREFIX . $m[1];
                    }
                    if(!themes::usable($user, $id)){
                        return ["There's no theme called '$id' they can use.", true, null];
                    }
                    self::setPreference($userId, $id);
                    $theme = themes::custom($user, $id) ?? themes::get($id === "auto" ? themes::siteTheme() : $id);
                    return ["Switched them to " . ($id === "auto" ? "the site default (" . $theme["name"] . ")" : $theme["name"]) . ".", false, self::event($theme)];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage(), true, null];
        }

        return ["Unknown tool.", true, null];
    }

    private static function describe(string $lead, array $theme, array $notes){
        $vars = $theme["vars"];
        $out = $lead . " Colors: bg " . $vars["--bg"] . ", surface " . $vars["--surface"] . ", accent " . $vars["--accent"]
            . ", link " . $vars["--link"] . ", text " . $vars["--text"] . ", muted " . $vars["--muted"] . ".";

        if($notes){
            $out .= " Adjusted so everything stays readable: " . implode("; ", $notes) . ".";
        }

        return $out;
    }

    // tells the page to redraw in the new colors without reloading
    private static function event(array $theme){
        return ["type"=>"theme", "id"=>$theme["id"], "css"=>$theme["css"] ?? "", "color"=>$theme["color"], "effect"=>$theme["effect"] ?? null];
    }

    private static function preference(int $userId){
        global $db;
        $row = $db->table("users")->where("id", $userId)->select(["theme"])->first();
        return $row && $row->theme ? $row->theme : "auto";
    }

    private static function setPreference(int $userId, string $id){
        global $db;
        $db->table("users")->where("id", $userId)->update(["theme"=>$id === "auto" ? null : $id]);
    }

    // the part of the system prompt about themes
    static function prompt($user): string {
        $own = userthemes::list((int) $user->id);
        $current = self::preference((int) $user->id);
        $builtIn = implode(", ", array_map(fn($t) => $t["id"] . " (" . $t["name"] . ")", themes::all()));

        $prompt = "# Site themes\n"
            . "You can make color themes for the whole site for {$user->username} with create_theme. A theme only changes the site for them, nobody else sees it. "
            . "When they ask for one (\"a purple space theme\", \"make the site look like the ocean at sunset\"), pick a palette that really fits the vibe and call create_theme straight away; don't ask which colors they want unless they ask you to. "
            . "Dark themes need a very dark bg and light text, light themes a very light bg and dark text. Keep bg and surface close together and let the accent carry the personality. "
            . "The site checks contrast and nudges colors that would be hard to read, the tool result tells you if it did. "
            . "Afterwards tell them its name and what you went for in a sentence, and that they can switch back from the Theme menu at the bottom of any page or in Settings, Appearance. "
            . "For tweaks (\"more pink\", \"darker\") use edit_theme on the theme they're using instead of making a new one. "
            . "They can have " . userthemes::MAX_PER_USER . " themes, and delete them in Settings, Appearance.\n";

        if($own){
            $prompt .= "Their themes:\n";
            foreach($own as $row){
                $vars = json_decode($row->vars, true) ?: [];
                $id = userthemes::PREFIX . $row->id;
                $prompt .= "[t{$row->id}] {$row->name} (bg " . ($vars["--bg"] ?? "?") . ", accent " . ($vars["--accent"] ?? "?") . ")" . ($current === $id ? " <- using this now" : "") . "\n";
            }
        } else {
            $prompt .= "They haven't made any themes yet.\n";
        }

        return $prompt . "Built in themes for use_theme: $builtIn, or auto for the site default. They're using: $current.";
    }
}
