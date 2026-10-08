<?php

namespace watrlabs\ai;

use watrlabs\games\games;
use watrlabs\music\music;

// tools the model can call. each one: name, description, json schema, and a run function
// that gets the model's input and returns text (or throws to report an error back to the model)
class tools {

    static function definitions(): array {
        $all = [
            "time"=>[
                "name"=>"get_current_time",
                "description"=>"Get the current date and time. Use this whenever the answer depends on today's date or the time somewhere.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "timezone"=>["type"=>"string", "description"=>"IANA timezone like America/New_York or Europe/London. Leave out for UTC."],
                    ],
                ],
            ],
            "calculator"=>[
                "name"=>"calculate",
                "description"=>"Evaluate a math expression exactly. Supports + - * / % (modulo) ^, parentheses, pi, e, sqrt, abs, sin, cos, tan, asin, acos, atan, log (base 10), ln, exp, floor, ceil, round(x, digits), min, max, pow. Use it for any arithmetic beyond the trivial.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "expression"=>["type"=>"string", "description"=>"The expression, e.g. (12.5 * 4) / 3 + sqrt(2)"],
                    ],
                    "required"=>["expression"],
                ],
            ],
            "site_search"=>[
                "name"=>"search_site",
                "description"=>"Search the games, apps and music on this website. Use it when the user asks what to play, whether a game is here, or for music. Results include links to use in your answer.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "query"=>["type"=>"string", "description"=>"Words to look for in names (and artists for music). Use an empty string to list popular items."],
                        "kind"=>["type"=>"string", "enum"=>["all", "games", "apps", "music"], "description"=>"What to search. Defaults to all."],
                    ],
                    "required"=>["query"],
                ],
            ],
            "weather"=>[
                "name"=>"get_weather",
                "description"=>"Get current weather and a 3 day forecast for a place.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "location"=>["type"=>"string", "description"=>"City name, optionally with country, e.g. Paris or Portland, US"],
                        "units"=>["type"=>"string", "enum"=>["celsius", "fahrenheit"], "description"=>"Defaults to celsius"],
                    ],
                    "required"=>["location"],
                ],
            ],
            "exa"=>[
                "name"=>"web_search",
                "description"=>"Search the web and get back the best matching pages with their text. Use it for news, recent events, prices, facts you're not sure of, or anything that may have changed since your training. Cite the pages you use as Markdown links.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "query"=>["type"=>"string", "description"=>"What to search for. A full question or description works better than a few keywords."],
                        "num_results"=>["type"=>"integer", "minimum"=>1, "maximum"=>10, "description"=>"How many pages to get back, 1 to 10. Defaults to 5."],
                        "since"=>["type"=>"string", "description"=>"Only pages published on or after this date (YYYY-MM-DD). Use for news or anything recent."],
                    ],
                    "required"=>["query"],
                ],
            ],
            "fetch"=>[
                "name"=>"fetch_webpage",
                "description"=>"Download a public web page and return its readable text. Use when the user gives you a link or asks about a specific page.",
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "url"=>["type"=>"string", "description"=>"Full http or https URL"],
                    ],
                    "required"=>["url"],
                ],
            ],
        ];

        $enabled = config::tools();

        return array_values(array_filter($all, fn($key) => in_array($key, $enabled, true), ARRAY_FILTER_USE_KEY));
    }

    // runs a tool call, always returns [content, isError]
    static function run(array $call): array {
        if(!empty($call["invalid_input"])){
            return ["The tool input wasn't valid JSON. Try the call again.", true];
        }

        $enabledNames = array_column(self::definitions(), "name");
        if(!in_array($call["name"], $enabledNames, true)){
            return ["There's no tool called " . $call["name"] . ".", true];
        }

        $input = is_array($call["input"]) ? $call["input"] : [];

        try {
            switch($call["name"]){
                case "get_current_time": return [self::time($input), false];
                case "calculate": return [self::calculate($input), false];
                case "search_site": return [self::searchSite($input), false];
                case "get_weather": return [self::weather($input), false];
                case "fetch_webpage": return [self::fetchPage($input), false];
                case "web_search": return [self::webSearch($input), false];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage(), true];
        }

        return ["Unknown tool.", true];
    }

    private static function stringInput(array $input, string $key, bool $required = true): ?string {
        $value = $input[$key] ?? null;

        if($value === null || $value === ""){
            if($required){
                throw new \InvalidArgumentException("Missing '$key'.");
            }
            return null;
        }

        if(!is_string($value)){
            throw new \InvalidArgumentException("'$key' should be a string.");
        }

        return trim($value);
    }

    private static function time(array $input){
        $zone = self::stringInput($input, "timezone", false) ?? "UTC";

        if(!in_array($zone, \DateTimeZone::listIdentifiers(), true)){
            throw new \InvalidArgumentException("Unknown timezone '$zone'. Use an IANA name like Europe/Berlin.");
        }

        $now = new \DateTime("now", new \DateTimeZone($zone));

        return $now->format("l, F j, Y, g:i A") . " ($zone, UTC" . $now->format("P") . ")";
    }

    private static function calculate(array $input){
        $expression = self::stringInput($input, "expression");
        return $expression . " = " . calculator::format(calculator::evaluate($expression));
    }

    private static function searchSite(array $input){
        $query = $input["query"] ?? "";
        $query = is_string($query) ? trim($query) : "";
        $kind = $input["kind"] ?? "all";
        $kind = in_array($kind, ["all", "games", "apps", "music"], true) ? $kind : "all";

        $games = new games();
        $music = new music();
        $lines = [];

        if($kind === "all" || $kind === "games"){
            foreach($games->list("popular", $query, 8, "game") as $game){
                $lines[] = "- Game: {$game->name} ({$game->plays} plays) /play/{$game->id}";
            }
        }

        if($kind === "all" || $kind === "apps"){
            foreach($games->list("popular", $query, 8, "app") as $app){
                $lines[] = "- App: {$app->name} /apps/{$app->id}";
            }
        }

        if($kind === "all" || $kind === "music"){
            foreach($music->list("popular", $query, 8) as $track){
                $artist = $track->artist ? " by {$track->artist}" : "";
                $lines[] = "- Song: {$track->title}{$artist} (play it from /music?q=" . rawurlencode($track->title) . ")";
            }
        }

        if(!$lines){
            return "Nothing on the site matched \"$query\".";
        }

        return "Results (link paths are relative to the site):\n" . implode("\n", $lines);
    }

    private static function webSearch(array $input){
        $query = self::stringInput($input, "query");
        if(mb_strlen($query) > 500){
            throw new \InvalidArgumentException("Keep the search under 500 characters.");
        }

        $count = (int) ($input["num_results"] ?? 5);
        $count = max(1, min(10, $count ?: 5));

        $body = [
            "query"=>$query,
            "type"=>"auto",
            "numResults"=>$count,
            // fewer characters per page when there are more pages, so the whole thing stays a sensible size
            "contents"=>["text"=>["maxCharacters"=>$count > 5 ? 1200 : 2000]],
        ];

        $since = self::stringInput($input, "since", false);
        if($since){
            $date = \DateTime::createFromFormat("!Y-m-d", $since);
            if(!$date){
                throw new \InvalidArgumentException("'since' should look like 2026-01-31.");
            }
            $body["startPublishedDate"] = $date->format("Y-m-d\\TH:i:s.000\\Z");
        }

        $ch = curl_init("https://api.exa.ai/search");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($body),
            CURLOPT_HTTPHEADER=>["Content-Type: application/json", "x-api-key: " . config::exaKey()],
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>25,
            CURLOPT_USERAGENT=>"WaterGames/1.0",
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($response === false || $status === 0){
            throw new \RuntimeException("The search service didn't answer, try again later.");
        }
        if($status === 401 || $status === 403){
            throw new \RuntimeException("Web search isn't set up right (the Exa key was refused). Tell the user search is unavailable.");
        }
        if($status === 429){
            throw new \RuntimeException("Too many searches right now, try again in a bit.");
        }
        if($status !== 200){
            throw new \RuntimeException("The search failed (HTTP $status).");
        }

        $results = json_decode($response, true)["results"] ?? [];
        if(!$results){
            return "No results for \"$query\".";
        }

        $out = "Web results for \"$query\":\n";
        foreach($results as $i => $result){
            $out .= "\n[" . ($i + 1) . "] " . trim($result["title"] ?? "Untitled") . "\n" . ($result["url"] ?? "") . "\n";
            if(!empty($result["publishedDate"])){
                $out .= "Published " . substr($result["publishedDate"], 0, 10) . "\n";
            }
            $text = trim(preg_replace('/\s+/u', " ", (string) ($result["text"] ?? "")));
            if($text !== ""){
                $out .= $text . "\n";
            }
        }

        return $out;
    }

    private static function weather(array $input){
        $location = self::stringInput($input, "location");
        $fahrenheit = ($input["units"] ?? "celsius") === "fahrenheit";

        // open-meteo's geocoder wants just the place name, so drop ", country" and match it ourselves
        $parts = array_map("trim", explode(",", $location));
        $place = $parts[0];
        $country = isset($parts[1]) ? strtolower($parts[1]) : null;

        $geo = self::getJson("https://geocoding-api.open-meteo.com/v1/search?" . http_build_query(["name"=>$place, "count"=>10, "language"=>"en"]));
        $results = $geo["results"] ?? [];

        if($country){
            $filtered = array_values(array_filter($results, fn($r) =>
                strtolower($r["country_code"] ?? "") === $country
                || strtolower($r["country"] ?? "") === $country
                || strtolower($r["admin1"] ?? "") === $country
            ));
            $results = $filtered ?: $results;
        }

        if(!$results){
            throw new \InvalidArgumentException("Couldn't find a place called '$location'.");
        }

        $spot = $results[0];
        $unitQuery = $fahrenheit ? ["temperature_unit"=>"fahrenheit", "wind_speed_unit"=>"mph"] : [];

        $forecast = self::getJson("https://api.open-meteo.com/v1/forecast?" . http_build_query(array_merge([
            "latitude"=>$spot["latitude"],
            "longitude"=>$spot["longitude"],
            "current"=>"temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m",
            "daily"=>"weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max",
            "timezone"=>"auto",
            "forecast_days"=>3,
        ], $unitQuery)));

        $deg = $fahrenheit ? "°F" : "°C";
        $wind = $fahrenheit ? "mph" : "km/h";
        $now = $forecast["current"] ?? [];

        $name = implode(", ", array_filter([$spot["name"] ?? null, $spot["admin1"] ?? null, $spot["country"] ?? null]));
        $out = "Weather for $name\n";
        $out .= "Now: " . self::weatherCode($now["weather_code"] ?? -1) . ", " . round($now["temperature_2m"] ?? 0) . $deg
            . " (feels like " . round($now["apparent_temperature"] ?? 0) . $deg . "), humidity " . ($now["relative_humidity_2m"] ?? "?") . "%, wind " . round($now["wind_speed_10m"] ?? 0) . " $wind\n";

        $daily = $forecast["daily"] ?? [];
        foreach(($daily["time"] ?? []) as $i => $day){
            $out .= date("l M j", strtotime($day)) . ": " . self::weatherCode($daily["weather_code"][$i] ?? -1)
                . ", " . round($daily["temperature_2m_min"][$i] ?? 0) . "–" . round($daily["temperature_2m_max"][$i] ?? 0) . $deg
                . ", " . ($daily["precipitation_probability_max"][$i] ?? 0) . "% chance of rain\n";
        }

        return trim($out);
    }

    private static function weatherCode(int $code){
        $codes = [
            0=>"clear sky", 1=>"mainly clear", 2=>"partly cloudy", 3=>"overcast", 45=>"fog", 48=>"freezing fog",
            51=>"light drizzle", 53=>"drizzle", 55=>"heavy drizzle", 56=>"freezing drizzle", 57=>"heavy freezing drizzle",
            61=>"light rain", 63=>"rain", 65=>"heavy rain", 66=>"freezing rain", 67=>"heavy freezing rain",
            71=>"light snow", 73=>"snow", 75=>"heavy snow", 77=>"snow grains",
            80=>"light showers", 81=>"showers", 82=>"heavy showers", 85=>"snow showers", 86=>"heavy snow showers",
            95=>"thunderstorm", 96=>"thunderstorm with hail", 99=>"thunderstorm with heavy hail",
        ];
        return $codes[$code] ?? "unknown conditions";
    }

    // GET json, one retry on rate limits / server errors / dropped connections
    private static function getJson(string $url){
        for($attempt = 0; $attempt < 2; $attempt++){
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>10,
                CURLOPT_USERAGENT=>"WaterGames/1.0",
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if($body !== false && $status === 200){
                return json_decode($body, true) ?? [];
            }

            if($status !== 0 && $status !== 429 && $status < 500){
                break;
            }

            usleep(400000);
        }

        $why = $status ? "HTTP $status" : ($error ?: "no response");
        throw new \RuntimeException("The weather service didn't answer ($why), try again later.");
    }

    // ---------- fetch_webpage (off unless AI_TOOLS includes "fetch") ----------

    // only public addresses, so the model can't be used to poke at the server's own network
    private static function publicIps(string $host): array {
        if(filter_var($host, FILTER_VALIDATE_IP)){
            $ips = [$host];
        } else {
            $ips = [];
            $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach($records as $record){
                if(isset($record["ip"])){
                    $ips[] = $record["ip"];
                }
                if(isset($record["ipv6"])){
                    $ips[] = $record["ipv6"];
                }
            }
        }

        if(!$ips){
            throw new \InvalidArgumentException("Couldn't look up $host.");
        }

        foreach($ips as $ip){
            if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)){
                throw new \InvalidArgumentException("That address isn't allowed.");
            }
        }

        return $ips;
    }

    private static function fetchPage(array $input){
        $url = self::stringInput($input, "url");

        for($redirects = 0; $redirects <= 3; $redirects++){
            $parts = parse_url($url);
            $scheme = strtolower($parts["scheme"] ?? "");
            $host = strtolower(trim($parts["host"] ?? "", "[]"));

            if(!in_array($scheme, ["http", "https"], true) || $host === "" || isset($parts["user"])){
                throw new \InvalidArgumentException("Only plain http and https links work.");
            }

            $port = $parts["port"] ?? ($scheme === "https" ? 443 : 80);
            if(!in_array($port, [80, 443, 8080, 8443], true)){
                throw new \InvalidArgumentException("That port isn't allowed.");
            }

            $ips = self::publicIps($host);
            $pinned = str_contains($ips[0], ":") ? "[" . $ips[0] . "]" : $ips[0];

            $body = "";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE=>["$host:$port:$pinned"], // use the address we checked, not a second lookup
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>15,
                CURLOPT_USERAGENT=>"Mozilla/5.0 (compatible; WaterGamesBot/1.0)",
                CURLOPT_WRITEFUNCTION=>function($ch, $chunk) use (&$body){
                    $body .= $chunk;
                    return strlen($body) > 1500000 ? 0 : strlen($chunk); // stop after ~1.5MB
                },
            ]);
            curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if($status >= 300 && $status < 400 && $location){
                $url = $location;
                continue;
            }

            if($status === 0){
                throw new \RuntimeException("Couldn't load the page.");
            }

            if($status >= 400){
                throw new \RuntimeException("The page returned HTTP $status.");
            }

            if(!preg_match('#text/|json|xml#i', $type)){
                throw new \RuntimeException("That link isn't a web page ($type).");
            }

            return self::pageText($body, $url);
        }

        throw new \RuntimeException("Too many redirects.");
    }

    private static function pageText(string $html, string $url){
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode($m[1])) : "";

        $html = preg_replace('#<(script|style|noscript|svg|nav|footer|iframe)\b.*?</\1>#is', " ", $html);
        $html = preg_replace('#<(br|p|div|li|h[1-6]|tr|section|article)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, "UTF-8");
        $text = preg_replace("/[ \t]+/", " ", $text);
        $text = preg_replace("/\s*\n\s*/", "\n", $text);
        $text = trim($text);

        if(mb_strlen($text) > 20000){
            $text = mb_substr($text, 0, 20000) . "\n[cut off]";
        }

        return "URL: $url\n" . ($title ? "Title: $title\n" : "") . "\n" . $text;
    }
}
