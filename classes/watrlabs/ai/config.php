<?php

namespace watrlabs\ai;

use watrlabs\encryption;
use watrlabs\watrkit\settings;

// where the AI's providers, models and limits come from.
//
// providers can be set in .env (OLLAMA_* / ANTHROPIC_* / OPENAI_*) or added in the admin panel.
// admin panel settings win over .env for the general stuff (on/off, limits, tools).
//
// model ids handed to the browser:
//   "anthropic:claude-opus-5-5"   a model from .env
//   "m12"                         a model added in the admin panel
class config {

    const TYPES = [
        "ollama"=>"Ollama",
        "anthropic"=>"Anthropic compatible",
        "openai"=>"OpenAI compatible",
    ];

    const DEFAULT_URLS = [
        "ollama"=>"http://127.0.0.1:11434",
        "anthropic"=>"https://api.anthropic.com",
        "openai"=>"https://api.openai.com/v1",
    ];

    const TOOL_NAMES = [
        "time"=>"Current time",
        "calculator"=>"Calculator",
        "site_search"=>"Search the site",
        "weather"=>"Weather",
        "fetch"=>"Read web pages",
        "exa"=>"Search the web (Exa)",
        "memory"=>"Memory (remembers things about each person between chats)",
        "themes"=>"Make site themes (each person's own)",
    ];

    private static ?array $providers = null;

    static function env(string $key, $default = null){
        $value = $_ENV[$key] ?? null;
        return ($value === null || $value === "") ? $default : $value;
    }

    // admin setting if there is one, otherwise .env, otherwise the default
    static function setting(string $name, string $envKey, $default = null){
        $value = settings::get($name);
        return ($value === null || $value === "") ? self::env($envKey, $default) : $value;
    }

    static function enabled(){
        return filter_var(self::setting("ai_enabled", "AI_ENABLED", false), FILTER_VALIDATE_BOOLEAN) && count(self::models()) > 0;
    }

    static function dailyLimit(){
        return max(0, (int) self::setting("ai_daily_limit", "AI_DAILY_LIMIT", 100));
    }

    static function dailyUploads(){
        return max(0, (int) self::setting("ai_daily_uploads", "AI_DAILY_UPLOADS", 50));
    }

    // the assistant's name, shown on the ai page and used in its instructions
    static function name(){
        $name = trim((string) self::setting("ai_name", "AI_NAME", "Bloop"));
        return $name === "" ? "Bloop" : mb_substr($name, 0, 40);
    }

    static function systemExtra(){
        return (string) self::setting("ai_system_prompt", "AI_SYSTEM_PROMPT", "");
    }

    // which built in tools are turned on (web search only counts once there's an exa key to use)
    static function tools(){
        $list = self::setting("ai_tools", "AI_TOOLS", "time,calculator,site_search,weather,memory,themes");
        $tools = array_values(array_filter(array_map("trim", explode(",", strtolower($list)))));
        return self::exaKey() ? $tools : array_values(array_diff($tools, ["exa"]));
    }

    // what the admin panel shows ticked, including web search when it's on but missing a key
    static function toolsPicked(){
        $list = self::setting("ai_tools", "AI_TOOLS", "time,calculator,site_search,weather,memory,themes");
        return array_values(array_filter(array_map("trim", explode(",", strtolower($list)))));
    }

    // exa.ai key for web search. the admin panel one (stored encrypted) wins over EXA_API_KEY
    static function exaKey(){
        $stored = settings::get("ai_exa_key");
        if($stored){
            $key = (new encryption())->decrypt($stored);
            if($key){
                return $key;
            }
        }
        return self::env("EXA_API_KEY");
    }

    // ---------- providers ----------

    static function providers(){
        if(self::$providers !== null){
            return self::$providers;
        }

        return self::$providers = array_merge(self::envProviders(), self::dbProviders());
    }

    static function provider(string $id){
        return self::providers()[$id] ?? null;
    }

    private static function envProviders(){
        $providers = [];

        foreach(array_keys(self::TYPES) as $type){
            $prefix = strtoupper($type);
            $list = self::env($prefix . "_MODELS", "");
            if(trim($list) === ""){
                continue;
            }

            $options = match($type){
                "anthropic"=>[
                    "max_tokens"=>self::env("ANTHROPIC_MAX_TOKENS"),
                    "effort"=>self::env("ANTHROPIC_EFFORT"),
                    "thinking"=>self::env("ANTHROPIC_THINKING"),
                    "fallbacks"=>self::env("ANTHROPIC_FALLBACKS", "true"),
                ],
                "openai"=>["max_tokens"=>self::env("OPENAI_MAX_TOKENS")],
                "ollama"=>["keep_alive"=>self::env("OLLAMA_KEEP_ALIVE")],
            };

            $id = "env-" . $type;
            $provider = [
                "id"=>$id,
                "source"=>"env",
                "type"=>$type,
                "name"=>self::TYPES[$type] . " (.env)",
                "url"=>rtrim(self::env($prefix . "_URL", self::DEFAULT_URLS[$type]), "/"),
                "key"=>self::env($prefix . "_KEY", ""),
                "options"=>$options,
                "enabled"=>true,
                "models"=>[],
            ];

            // "model-name" or "model-name=Display Name", comma separated
            foreach(explode(",", $list) as $entry){
                $entry = trim($entry);
                if($entry === ""){
                    continue;
                }

                $parts = explode("=", $entry, 2);
                $name = trim($parts[0]);

                $provider["models"][] = self::model_($provider, $type . ":" . $name, $name, isset($parts[1]) ? trim($parts[1]) : $name, "auto", "auto", "auto", "auto");
            }

            $providers[$id] = $provider;
        }

        return $providers;
    }

    private static function dbProviders(){
        global $db;
        $providers = [];

        try {
            $rows = $db->table("ai_providers")->orderBy("id", "ASC")->get();
            $modelRows = $db->table("ai_models")->orderBy("sort", "ASC")->orderBy("id", "ASC")->get();
        } catch (\Throwable $e) {
            return []; // tables show up once migrations run
        }

        $encryption = new encryption();

        foreach($rows as $row){
            if(!isset(self::TYPES[$row->type])){
                continue;
            }

            $id = "db-" . $row->id;
            $providers[$id] = [
                "id"=>$id,
                "dbId"=>(int) $row->id,
                "source"=>"db",
                "type"=>$row->type,
                "name"=>$row->name,
                "url"=>rtrim($row->base_url ?: self::DEFAULT_URLS[$row->type], "/"),
                "key"=>$row->api_key ? (string) $encryption->decrypt($row->api_key) : "",
                "hasKey"=>(bool) $row->api_key,
                "options"=>json_decode($row->options ?? "", true) ?: [],
                "enabled"=>(bool) $row->enabled,
                "models"=>[],
            ];
        }

        foreach($modelRows as $row){
            $providerId = "db-" . $row->provider_id;
            if(!isset($providers[$providerId])){
                continue;
            }

            $model = self::model_($providers[$providerId], "m" . $row->id, $row->name, $row->label ?: $row->name, $row->vision, $row->tools, $row->think ?? "auto", $row->prompt ?? "auto");
            $model["dbId"] = (int) $row->id;
            $model["enabled"] = (bool) $row->enabled;
            $model["sort"] = (int) $row->sort;
            $providers[$providerId]["models"][] = $model;
        }

        return $providers;
    }

    private static function model_(array $provider, string $id, string $name, string $label, $vision, $tools, $think, $prompt){
        return [
            "id"=>$id,
            "provider"=>$provider["type"],
            "providerId"=>$provider["id"],
            "providerName"=>$provider["name"],
            "name"=>$name,
            "label"=>$label,
            "url"=>$provider["url"],
            "key"=>$provider["key"],
            "options"=>$provider["options"],
            "vision"=>in_array($vision, ["yes", "no"], true) ? $vision : "auto",
            "tools"=>in_array($tools, ["yes", "no"], true) ? $tools : "auto",
            // thinking before answering: auto leaves it to the model. small local models can think for minutes
            "think"=>in_array($think, ["yes", "no"], true) ? $think : "auto",
            "prompt"=>in_array($prompt, ["full", "short"], true) ? $prompt : "auto",
            "enabled"=>true,
        ];
    }

    // ---------- models ----------

    // every model people can actually pick (enabled, on an enabled provider)
    static function models(){
        $models = [];

        foreach(self::providers() as $provider){
            if(!$provider["enabled"]){
                continue;
            }
            foreach($provider["models"] as $model){
                if($model["enabled"]){
                    $models[$model["id"]] = $model;
                }
            }
        }

        return $models;
    }

    static function model(?string $id){
        return self::models()[$id] ?? null;
    }

    static function defaultModel(){
        $models = self::models();
        $default = self::setting("ai_default_model", "AI_DEFAULT_MODEL");

        if($default && isset($models[$default])){
            return $models[$default];
        }

        return reset($models) ?: null;
    }

    // the model for little background jobs (chat titles, the starter prompts). picked in the admin panel,
    // otherwise the first one whose name says it's small, otherwise the default
    static function smallModel(){
        $models = self::models();
        $picked = self::setting("ai_small_model", "AI_SMALL_MODEL");

        if($picked && isset($models[$picked])){
            return $models[$picked];
        }

        foreach($models as $model){
            if(preg_match('/haiku|mini|nano|flash|lite|small|tiny/i', $model["name"])){
                return $model;
            }
        }

        return self::defaultModel();
    }

    // anthropic's own api (not a compatible proxy) gets the extras only it understands
    static function isFirstPartyAnthropic(string $url){
        return parse_url($url, PHP_URL_HOST) === "api.anthropic.com";
    }

    static function option(array $model, string $name, $default = null){
        $value = $model["options"][$name] ?? null;
        return ($value === null || $value === "") ? $default : $value;
    }

    // what a model can do. set per model in the admin panel; on "auto" ollama gets asked
    // (and the answer is cached for a bit), anthropic / openai style models are assumed to do both
    static function capabilities(array $model){
        $caps = ["vision"=>true, "tools"=>true];

        if($model["provider"] === "ollama" && ($model["vision"] === "auto" || $model["tools"] === "auto")){
            $caps = self::ollamaCapabilities($model);
        }

        foreach(["vision", "tools"] as $cap){
            if($model[$cap] !== "auto"){
                $caps[$cap] = $model[$cap] === "yes";
            }
        }

        return $caps;
    }

    // whether a model gets the short system prompt: no theme maker and condensed instructions.
    // auto means short for ollama, since a local model on a CPU spends most of a first reply reading the prompt
    static function shortPrompt(array $model){
        $prompt = $model["prompt"] ?? "auto";
        return $prompt === "auto" ? $model["provider"] === "ollama" : $prompt === "short";
    }

    // whether an ollama model can think at all. ollama refuses a think setting on models that can't
    static function canThink(array $model){
        return $model["provider"] === "ollama" && !empty(self::ollamaCapabilities($model)["thinking"]);
    }

    private static function ollamaCapabilities(array $model){
        $cacheFile = __DIR__ . "/../../../storage/cache/ai-ollama-" . md5($model["url"] . "|" . $model["name"]) . ".json";

        if(is_file($cacheFile) && filemtime($cacheFile) > time() - 600){
            $cached = json_decode(file_get_contents($cacheFile), true);
            if(is_array($cached) && isset($cached["thinking"])){
                return $cached;
            }
        }

        $caps = ["vision"=>false, "tools"=>false, "thinking"=>false];
        $headers = ["Content-Type: application/json"];
        if($model["key"]){
            $headers[] = "Authorization: Bearer " . $model["key"];
        }

        $ch = curl_init($model["url"] . "/api/show");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(["model"=>$model["name"]]),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>5,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($response !== false && $status === 200){
            $list = json_decode($response, true)["capabilities"] ?? [];
            $caps = [
                "vision"=>in_array("vision", $list, true),
                "tools"=>in_array("tools", $list, true),
                "thinking"=>in_array("thinking", $list, true),
            ];

            try {
                file_put_contents($cacheFile, json_encode($caps));
            } catch (\Throwable $e) {
                // not being able to cache just means we ask again next time
            }
        }

        return $caps;
    }

    // the bits of the model list the browser needs
    static function publicModels(){
        return array_values(array_map(fn($model) => [
            "id"=>$model["id"],
            "label"=>$model["label"],
            "provider"=>$model["provider"],
        ], self::models()));
    }

    // asks a provider which models it has, for the admin panel's "fetch models" button
    static function remoteModels(array $provider){
        $url = $provider["url"];
        $headers = [];

        switch($provider["type"]){
            case "ollama":
                $endpoint = $url . "/api/tags";
                if($provider["key"]){
                    $headers[] = "Authorization: Bearer " . $provider["key"];
                }
                break;
            case "openai":
                $endpoint = $url . "/models";
                if($provider["key"]){
                    $headers[] = "Authorization: Bearer " . $provider["key"];
                }
                break;
            case "anthropic":
                $endpoint = (preg_match('#/v1$#', $url) ? $url : $url . "/v1") . "/models?limit=100";
                $headers[] = "x-api-key: " . $provider["key"];
                $headers[] = "anthropic-version: 2023-06-01";
                break;
            default:
                throw new \InvalidArgumentException("Unknown provider type.");
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>15,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if($body === false || $status === 0){
            throw new \RuntimeException("Couldn't connect to $endpoint ($error).");
        }

        $json = json_decode($body, true);

        if($status >= 400){
            $message = $json["error"]["message"] ?? (is_string($json["error"] ?? null) ? $json["error"] : null) ?? substr(strip_tags($body), 0, 200);
            throw new \RuntimeException("The provider said HTTP $status: $message");
        }

        $names = [];

        if($provider["type"] === "ollama"){
            foreach($json["models"] ?? [] as $model){
                $names[] = ["name"=>$model["name"], "label"=>$model["name"]];
            }
        } else {
            foreach($json["data"] ?? [] as $model){
                $names[] = ["name"=>$model["id"], "label"=>$model["display_name"] ?? $model["id"]];
            }
        }

        usort($names, fn($a, $b) => strcmp($a["name"], $b["name"]));

        return $names;
    }
}
