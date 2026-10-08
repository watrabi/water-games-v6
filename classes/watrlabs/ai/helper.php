<?php

namespace watrlabs\ai;

use watrlabs\ai\providers\provider;

// little jobs for the small model: naming chats and coming up with the starter prompts on the ai page
class helper {

    const SUGGESTION_COUNT = 3;
    const SUGGESTION_TTL = 300;

    const FALLBACK_SUGGESTIONS = [
        "Find me a good racing game on here",
        "What's the weather in Tokyo right now?",
        "Make me a snake game I can play in the browser",
    ];

    // one quick, tool free call. returns the answer's text, or null if it didn't work out
    static function ask(string $system, string $prompt, int $timeout = 30): ?string {
        $model = config::smallModel();
        if(!$model){
            return null;
        }

        // no thinking and a short answer, whatever the model is set up with for chatting
        $model["options"] = array_merge($model["options"], ["effort"=>null, "thinking"=>null, "fallbacks"=>"false"]);
        if($model["provider"] === "anthropic"){
            $model["options"]["max_tokens"] = 1024;
        }

        try {
            $provider = provider::for($model, function(){});
            $provider->quiet = true;
            $provider->timeout = $timeout;
            $result = $provider->run($system, [["role"=>"user", "content"=>[["type"=>"text", "text"=>$prompt]]]], []);
        } catch (\Throwable $e) {
            return null;
        }

        if($result["stop"] === "error" || $result["stop"] === "refusal"){
            return null;
        }

        $text = "";
        foreach($result["blocks"] as $block){
            if($block["type"] === "text"){
                $text .= $block["text"];
            }
        }

        // some local models think out loud in the answer itself
        $text = trim(preg_replace('#<think>.*?</think>#s', "", $text));
        return $text === "" ? null : $text;
    }

    // what a new chat is called until the small model comes up with something better
    static function draftTitle(string $text){
        $title = preg_replace('/\s+/', ' ', trim($text));
        if($title === ""){
            return "Image";
        }
        if(mb_strlen($title) > 60){
            $title = rtrim(mb_substr($title, 0, 57)) . "...";
        }
        return $title;
    }

    // a short name for a chat from how it started
    static function titleFor(string $prompt, string $answer): ?string {
        $prompt = mb_substr($prompt, 0, 2000);
        $answer = mb_substr($answer, 0, 2000);

        $text = self::ask(
            "You name chats. Reply with only a short title for the conversation: 2 to 6 words, plain text, "
            . "no quotes, no ending punctuation, no emoji. Write it in the same language the person used.",
            "The person wrote:\n$prompt\n\nThe assistant answered:\n" . ($answer !== "" ? $answer : "(no text)")
        );

        if($text === null){
            return null;
        }

        $title = trim(strtok($text, "\n"));
        $title = preg_replace('/^(title|chat title)\s*:\s*/i', "", $title);
        $title = trim($title, " \t\"'`*#.");

        if($title === "" || mb_strlen($title) > 80){
            return null;
        }

        return $title;
    }

    // the starter prompts, shared by everyone and made fresh about every five minutes
    static function suggestions(): array {
        $file = __DIR__ . "/../../../storage/cache/ai-suggestions.json";
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $fresh = is_array($cached) && ($cached["time"] ?? 0) > time() - self::SUGGESTION_TTL;

        if($fresh){
            return $cached["prompts"];
        }

        $stale = is_array($cached["prompts"] ?? null) ? $cached["prompts"] : self::FALLBACK_SUGGESTIONS;

        // only one request makes new ones, everyone else gets the last batch meanwhile
        $lock = @fopen($file . ".lock", "c");
        if(!$lock || !flock($lock, LOCK_EX | LOCK_NB)){
            return $stale;
        }

        try {
            $prompts = self::makeSuggestions($stale);

            // a failed try still waits its turn, so a broken provider isn't asked on every page load
            @file_put_contents($file, json_encode(["time"=>time(), "prompts"=>$prompts ?? $stale], JSON_UNESCAPED_UNICODE), LOCK_EX);

            return $prompts ?? $stale;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function makeSuggestions(array $previous): ?array {
        $name = config::name();
        $site = $_ENV["APP_NAME"] ?? "Water Games";
        $can = ["find games on the site", "make little web pages, browser games and drawings"];
        $tools = config::tools();
        if(in_array("weather", $tools, true)){
            $can[] = "check the weather anywhere";
        }
        if(in_array("exa", $tools, true) || in_array("fetch", $tools, true)){
            $can[] = "look things up on the web";
        }
        if(in_array("themes", $tools, true)){
            $can[] = "make custom color themes for the site";
        }
        $can[] = "look at pictures people attach";
        $can[] = "chat, explain things and help with homework";

        $text = self::ask(
            "You write the example prompts shown on an empty chat screen. The assistant is $name, a friendly water blob on $site, "
            . "a site with free browser games and music. It can " . implode(", ", $can) . ".",
            "Write " . self::SUGGESTION_COUNT . " different things someone might type to $name, each under 60 characters, written as the person "
            . "would type them. Mix it up: games, building something, and everyday questions. Be specific and fun, not generic. "
            . "Don't repeat these: " . implode(" | ", $previous) . "\n\nReply with only a JSON array of strings.",
            20
        );

        if($text === null || !preg_match('/\[.*\]/s', $text, $match)){
            return null;
        }

        $list = json_decode($match[0], true);
        if(!is_array($list)){
            return null;
        }

        $prompts = [];
        foreach($list as $prompt){
            if(!is_string($prompt)){
                continue;
            }
            $prompt = trim(preg_replace('/\s+/', ' ', $prompt));
            if($prompt !== "" && mb_strlen($prompt) <= 100){
                $prompts[] = $prompt;
            }
        }

        $prompts = array_slice(array_values(array_unique($prompts)), 0, self::SUGGESTION_COUNT);
        return count($prompts) === self::SUGGESTION_COUNT ? $prompts : null;
    }
}
