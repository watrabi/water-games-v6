<?php

namespace watrlabs\ai\providers;

use watrlabs\ai\config;

// anthropic messages api (or anything that speaks it), POST {url}/v1/messages with stream: true
class anthropic extends provider {

    private array $open = [];        // index => block being streamed
    private array $partialJson = []; // index => tool input json so far
    private string $event = "";

    protected function request(string $system, array $messages, array $tools): array {
        $firstParty = config::isFirstPartyAnthropic($this->model["url"]);

        $body = [
            "model"=>$this->model["name"],
            "max_tokens"=>(int) config::option($this->model, "max_tokens", 64000),
            "stream"=>true,
            "system"=>$system,
            "messages"=>$this->convertMessages($messages),
        ];

        if($tools){
            $body["tools"] = array_map(function($tool) use ($firstParty){
                $definition = [
                    "name"=>$tool["name"],
                    "description"=>$tool["description"],
                    "input_schema"=>$tool["schema"],
                ];

                // stream tool input as it's written. tools validate their own input, so this is safe
                if($firstParty){
                    $definition["eager_input_streaming"] = true;
                }

                return $definition;
            }, $tools);
        }

        // optional, for models where you want to tune thinking depth / show summarized thinking
        if($effort = config::option($this->model, "effort")){
            $body["output_config"] = ["effort"=>$effort];
        }

        if(config::option($this->model, "thinking") === "summarized"){
            $body["thinking"] = ["type"=>"adaptive", "display"=>"summarized"];
        }

        $headers = [
            "x-api-key: " . $this->model["key"],
            "anthropic-version: 2023-06-01",
        ];

        // things only api.anthropic.com understands, compatible proxies would choke on them
        if($firstParty){
            // cache the conversation prefix so long chats are cheaper
            $body["cache_control"] = ["type"=>"ephemeral"];

            // if the model declines, let anthropic retry on the fallback it recommends
            if(filter_var(config::option($this->model, "fallbacks", true), FILTER_VALIDATE_BOOLEAN)){
                $body["fallbacks"] = "default";
                $headers[] = "anthropic-beta: server-side-fallback-2026-07-01";
            }
        }

        $base = $this->model["url"];
        $url = preg_match('#/v1$#', $base) ? "$base/messages" : "$base/v1/messages";

        return [$url, $headers, $body];
    }

    private function convertMessages(array $messages){
        $out = [];

        foreach($messages as $message){
            $content = [];

            foreach($message["content"] as $block){
                switch($block["type"]){
                    case "text":
                        if($block["text"] !== ""){
                            $content[] = ["type"=>"text", "text"=>$block["text"]];
                        }
                        break;
                    case "image":
                        $content[] = ["type"=>"image", "source"=>["type"=>"base64", "media_type"=>$block["mime"], "data"=>$block["data"]]];
                        break;
                    case "tool_use":
                        $content[] = ["type"=>"tool_use", "id"=>$block["id"], "name"=>$block["name"], "input"=>(object) $block["input"]];
                        break;
                    case "tool_result":
                        $content[] = ["type"=>"tool_result", "tool_use_id"=>$block["tool_use_id"], "content"=>$block["content"], "is_error"=>!empty($block["is_error"])];
                        break;
                    case "thinking":
                    case "redacted_thinking":
                        // history only keeps these when this same model wrote them
                        $raw = $block;
                        unset($raw["model"]);
                        $content[] = $raw;
                        break;
                }
            }

            if($content){
                $out[] = ["role"=>$message["role"], "content"=>$content];
            }
        }

        return $out;
    }

    protected function handleLine(string $line): void {
        if(str_starts_with($line, "event:")){
            $this->event = trim(substr($line, 6));
            return;
        }

        if(!str_starts_with($line, "data:")){
            return;
        }

        $data = json_decode(trim(substr($line, 5)), true);
        if(!is_array($data)){
            return;
        }

        switch($data["type"] ?? $this->event){
            case "message_start":
                $this->servedBy = $data["message"]["model"] ?? null;
                break;

            case "content_block_start":
                $index = $data["index"];
                $block = $data["content_block"];
                $this->open[$index] = $block;

                if($block["type"] === "tool_use"){
                    $this->partialJson[$index] = "";
                    $this->emit(["type"=>"tool_start", "id"=>$block["id"], "name"=>$block["name"]]);
                } elseif($block["type"] === "thinking" || $block["type"] === "redacted_thinking"){
                    $this->emit(["type"=>"thinking_start"]);
                } elseif($block["type"] === "fallback"){
                    $this->emit(["type"=>"notice", "text"=>($block["from"]["model"] ?? "The model") . " passed on this one, " . ($block["to"]["model"] ?? "another model") . " took over."]);
                } elseif($block["type"] === "text" && ($block["text"] ?? "") !== ""){
                    $this->emit(["type"=>"text", "text"=>$block["text"]]);
                }
                break;

            case "content_block_delta":
                $index = $data["index"];
                $delta = $data["delta"];

                switch($delta["type"]){
                    case "text_delta":
                        $this->open[$index]["text"] = ($this->open[$index]["text"] ?? "") . $delta["text"];
                        $this->emit(["type"=>"text", "text"=>$delta["text"]]);
                        break;
                    case "input_json_delta":
                        $this->partialJson[$index] .= $delta["partial_json"];
                        break;
                    case "thinking_delta":
                        $this->open[$index]["thinking"] = ($this->open[$index]["thinking"] ?? "") . $delta["thinking"];
                        if($delta["thinking"] !== ""){
                            $this->emit(["type"=>"thinking", "text"=>$delta["thinking"]]);
                        }
                        break;
                    case "signature_delta":
                        $this->open[$index]["signature"] = ($this->open[$index]["signature"] ?? "") . $delta["signature"];
                        break;
                }
                break;

            case "content_block_stop":
                $this->closeBlock($data["index"]);
                break;

            case "message_delta":
                if(isset($data["delta"]["stop_reason"])){
                    $this->stopReason = $data["delta"]["stop_reason"];
                }
                break;

            case "error":
                $this->error = "The model returned an error: " . ($data["error"]["message"] ?? "unknown");
                $this->stopReason = "error";
                break;
        }
    }

    private function closeBlock($index){
        if(!isset($this->open[$index])){
            return;
        }

        $block = $this->open[$index];
        unset($this->open[$index]);

        if($block["type"] === "tool_use"){
            $json = $this->partialJson[$index] ?? "";
            unset($this->partialJson[$index]);

            $input = $json === "" ? [] : json_decode($json, true);

            // eager streaming means a cut off or broken input is possible, flag it so the tool reports an error
            if(!is_array($input)){
                $block["input"] = [];
                $block["invalid_input"] = true;
            } else {
                $block["input"] = $input;
            }

            $this->emit(["type"=>"tool_input", "id"=>$block["id"], "input"=>$block["input"]]);
        } elseif($block["type"] === "thinking" || $block["type"] === "redacted_thinking"){
            $this->emit(["type"=>"thinking_end"]);
        }

        $this->blocks[$index] = $block;
    }

    protected function finish(): void {
        // anything still open (stopped or errored mid block)
        foreach(array_keys($this->open) as $index){
            if($this->open[$index]["type"] === "text"){
                $this->blocks[$index] = $this->open[$index];
            }
        }
        $this->open = [];

        ksort($this->blocks);
        $blocks = array_values($this->blocks);

        // after a mid-answer fallback, drop the thinking / tool calls the declined model made before the
        // last switch point (only its text is kept as context), and the marker block itself
        $lastFallback = null;
        foreach($blocks as $i => $block){
            if($block["type"] === "fallback"){
                $lastFallback = $i;
            }
        }

        $clean = [];
        foreach($blocks as $i => $block){
            if($block["type"] === "fallback"){
                continue;
            }

            if($lastFallback !== null && $i < $lastFallback && $block["type"] !== "text"){
                continue;
            }

            if(!in_array($block["type"], ["text", "tool_use", "thinking", "redacted_thinking"], true)){
                continue;
            }

            // tag thinking with whoever actually wrote it (a fallback model's thinking can't go back to the original)
            if(in_array($block["type"], ["thinking", "redacted_thinking"], true)){
                $block["model"] = $this->servedBy ? "anthropic:" . $this->servedBy : $this->model["id"];
            }

            $clean[] = $block;
        }

        // a stopped / errored stream can't keep a half written tool call
        if(in_array($this->stopReason, ["stopped", "error"], true)){
            $clean = array_values(array_filter($clean, fn($b) => $b["type"] === "text"));
        }

        $this->blocks = $clean;

        if($this->stopReason === "pause_turn"){
            $this->stopReason = "end_turn";
        }
    }
}
