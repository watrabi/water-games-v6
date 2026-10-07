<?php

namespace watrlabs\ai;

// reads the AI_* / OLLAMA_* / ANTHROPIC_* / OPENAI_* settings from .env
// model ids handed to the browser look like "anthropic:claude-opus-5-5"
class config {

    const PROVIDERS = ["ollama", "anthropic", "openai"];

    private static ?array $models = null;

    static function env(string $key, $default = null){
        $value = $_ENV[$key] ?? null;
        return ($value === null || $value === "") ? $default : $value;
    }

    static function enabled(){
        return filter_var(self::env("AI_ENABLED", false), FILTER_VALIDATE_BOOLEAN) && count(self::models()) > 0;
    }

    static function dailyLimit(){
        return max(0, (int) self::env("AI_DAILY_LIMIT", 100));
    }

    // which built in tools are turned on
    static function tools(){
        $list = self::env("AI_TOOLS", "time,calculator,site_search,weather");
        return array_values(array_filter(array_map("trim", explode(",", strtolower($list)))));
    }

    // every model from every configured provider
    // list format: "model-name" or "model-name=Display Name", comma separated
    static function models(){
        if(self::$models !== null){
            return self::$models;
        }

        $models = [];

        foreach(self::PROVIDERS as $provider){
            $list = self::env(strtoupper($provider) . "_MODELS", "");

            foreach(explode(",", $list) as $entry){
                $entry = trim($entry);
                if($entry === ""){
                    continue;
                }

                $parts = explode("=", $entry, 2);
                $name = trim($parts[0]);
                $label = isset($parts[1]) ? trim($parts[1]) : $name;

                $models[$provider . ":" . $name] = [
                    "id"=>$provider . ":" . $name,
                    "provider"=>$provider,
                    "name"=>$name,
                    "label"=>$label,
                ];
            }
        }

        return self::$models = $models;
    }

    static function model(?string $id){
        $models = self::models();
        return $models[$id] ?? null;
    }

    static function defaultModel(){
        $models = self::models();
        $default = self::env("AI_DEFAULT_MODEL");

        if($default && isset($models[$default])){
            return $models[$default];
        }

        return reset($models) ?: null;
    }

    static function providerUrl(string $provider){
        $defaults = [
            "ollama"=>"http://127.0.0.1:11434",
            "anthropic"=>"https://api.anthropic.com",
            "openai"=>"https://api.openai.com/v1",
        ];

        return rtrim(self::env(strtoupper($provider) . "_URL", $defaults[$provider]), "/");
    }

    static function providerKey(string $provider){
        return self::env(strtoupper($provider) . "_KEY", "");
    }

    // anthropic's own api (not a compatible proxy) gets the extras that only it understands
    static function isFirstPartyAnthropic(){
        return parse_url(self::providerUrl("anthropic"), PHP_URL_HOST) === "api.anthropic.com";
    }

    // what a model can do. anthropic + openai style models are assumed to handle images and tools,
    // ollama gets asked (and the answer is cached for a bit)
    static function capabilities(array $model){
        if($model["provider"] !== "ollama"){
            return ["vision"=>true, "tools"=>true];
        }

        $cacheFile = __DIR__ . "/../../../storage/cache/ai-ollama-" . md5($model["name"]) . ".json";

        if(is_file($cacheFile) && filemtime($cacheFile) > time() - 600){
            $cached = json_decode(file_get_contents($cacheFile), true);
            if(is_array($cached)){
                return $cached;
            }
        }

        $caps = ["vision"=>false, "tools"=>false];

        $ch = curl_init(self::providerUrl("ollama") . "/api/show");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(["model"=>$model["name"]]),
            CURLOPT_HTTPHEADER=>["Content-Type: application/json"],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>5,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($response !== false && $status === 200){
            $info = json_decode($response, true);
            $list = $info["capabilities"] ?? [];
            $caps = [
                "vision"=>in_array("vision", $list, true),
                "tools"=>in_array("tools", $list, true),
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
        return array_values(array_map(function($model){
            return ["id"=>$model["id"], "label"=>$model["label"], "provider"=>$model["provider"]];
        }, self::models()));
    }
}
