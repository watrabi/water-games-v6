<?php

namespace watrlabs\ai\providers;

use watrlabs\ai\config;

// openai style chat completions, POST {url}/chat/completions with stream: true
// works for openai itself and the long list of things that copy its api
class openai extends provider {

    private string $text = "";
    private string $reasoning = "";
    private bool $reasoningOpen = false;
    private array $calls = []; // index => [id, name, arguments]
    private ?string $finishReason = null;

    protected function request(string $system, array $messages, array $tools): array {
        $body = [
            "model"=>$this->model["name"],
            "stream"=>true,
            "messages"=>array_merge([["role"=>"system", "content"=>$system]], $this->convertMessages($messages)),
        ];

        if($tools){
            $body["tools"] = array_map(fn($tool) => [
                "type"=>"function",
                "function"=>[
                    "name"=>$tool["name"],
                    "description"=>$tool["description"],
                    "parameters"=>$tool["schema"],
                ],
            ], $tools);
        }

        if($maxTokens = config::option($this->model, "max_tokens")){
            $body["max_tokens"] = (int) $maxTokens;
        }

        $headers = [];
        if($key = $this->model["key"]){
            $headers[] = "Authorization: Bearer " . $key;
        }

        return [$this->model["url"] . "/chat/completions", $headers, $body];
    }

    private function convertMessages(array $messages){
        $out = [];

        foreach($messages as $message){
            if($message["role"] === "assistant"){
                $entry = ["role"=>"assistant", "content"=>self::textOf($message["content"])];
                $calls = [];

                foreach($message["content"] as $block){
                    if($block["type"] === "tool_use"){
                        $call = [
                            "id"=>$block["id"],
                            "type"=>"function",
                            "function"=>["name"=>$block["name"], "arguments"=>json_encode((object) $block["input"])],
                        ];

                        // gemini 3 refuses tool calls sent back without the thought signature it gave us
                        if(!empty($block["extra_content"])){
                            $call["extra_content"] = $block["extra_content"];
                        } elseif($this->isGoogle()){
                            // calls made by another model have none, google documents this value for that case
                            $call["extra_content"] = ["google"=>["thought_signature"=>"skip_thought_signature_validator"]];
                        }

                        $calls[] = $call;
                    }
                }

                if($calls){
                    $entry["tool_calls"] = $calls;
                    if($entry["content"] === ""){
                        $entry["content"] = null;
                    }
                }

                if($entry["content"] !== "" || $calls){
                    $out[] = $entry;
                }
                continue;
            }

            // user side: tool results become their own "tool" messages
            $parts = [];
            foreach($message["content"] as $block){
                if($block["type"] === "tool_result"){
                    $out[] = ["role"=>"tool", "tool_call_id"=>$block["tool_use_id"], "content"=>$block["content"]];
                } elseif($block["type"] === "text" && $block["text"] !== ""){
                    $parts[] = ["type"=>"text", "text"=>$block["text"]];
                } elseif($block["type"] === "image"){
                    $parts[] = ["type"=>"image_url", "image_url"=>["url"=>"data:" . $block["mime"] . ";base64," . $block["data"]]];
                }
            }

            if(!$parts){
                continue;
            }

            $hasImage = (bool) array_filter($parts, fn($p) => $p["type"] === "image_url");
            $out[] = ["role"=>"user", "content"=>$hasImage ? $parts : self::textOf($message["content"])];
        }

        return $out;
    }

    protected function handleLine(string $line): void {
        if(!str_starts_with($line, "data:")){
            return;
        }

        $payload = trim(substr($line, 5));
        if($payload === "[DONE]"){
            return;
        }

        $data = json_decode($payload, true);
        if(!is_array($data)){
            return;
        }

        if(isset($data["error"])){
            $this->error = "The model returned an error: " . ($data["error"]["message"] ?? json_encode($data["error"]));
            $this->stopReason = "error";
            return;
        }

        $choice = $data["choices"][0] ?? null;
        if(!$choice){
            return;
        }

        $delta = $choice["delta"] ?? [];

        // some servers (openrouter, deepseek, lm studio) stream reasoning separately
        $reasoning = $delta["reasoning_content"] ?? $delta["reasoning"] ?? null;
        if(is_string($reasoning) && $reasoning !== ""){
            if(!$this->reasoningOpen){
                $this->reasoningOpen = true;
                $this->emit(["type"=>"thinking_start"]);
            }
            $this->reasoning .= $reasoning;
            $this->emit(["type"=>"thinking", "text"=>$reasoning]);
        }

        if(isset($delta["content"]) && is_string($delta["content"]) && $delta["content"] !== ""){
            $this->closeReasoning();
            $this->text .= $delta["content"];
            $this->emit(["type"=>"text", "text"=>$delta["content"]]);
        }

        foreach($delta["tool_calls"] ?? [] as $call){
            $this->closeReasoning();
            $index = $call["index"] ?? count($this->calls);

            if(!isset($this->calls[$index])){
                $this->calls[$index] = ["id"=>$call["id"] ?? self::newToolId(), "name"=>"", "arguments"=>""];
            }

            if(!empty($call["function"]["name"])){
                $isNew = $this->calls[$index]["name"] === "";
                $this->calls[$index]["name"] .= $call["function"]["name"];
                if($isNew){
                    $this->emit(["type"=>"tool_start", "id"=>$this->calls[$index]["id"], "name"=>$this->calls[$index]["name"]]);
                }
            }

            if(isset($call["function"]["arguments"])){
                $this->calls[$index]["arguments"] .= $call["function"]["arguments"];
            }

            if(!empty($call["extra_content"]) && is_array($call["extra_content"])){
                $this->calls[$index]["extra_content"] = array_replace_recursive($this->calls[$index]["extra_content"] ?? [], $call["extra_content"]);
            }
        }

        if(!empty($choice["finish_reason"])){
            $this->finishReason = $choice["finish_reason"];
        }
    }

    private function isGoogle(){
        return str_contains($this->model["url"], "generativelanguage.googleapis.com");
    }

    private function closeReasoning(){
        if($this->reasoningOpen){
            $this->reasoningOpen = false;
            $this->emit(["type"=>"thinking_end"]);
        }
    }

    protected function finish(): void {
        $this->closeReasoning();

        $blocks = [];

        if($this->reasoning !== ""){
            // shown in the chat, never sent back to the model
            $blocks[] = ["type"=>"reasoning", "text"=>$this->reasoning];
        }

        if($this->text !== ""){
            $blocks[] = ["type"=>"text", "text"=>$this->text];
        }

        $stopped = in_array($this->stopReason, ["stopped", "error"], true);

        if(!$stopped){
            ksort($this->calls);
            foreach($this->calls as $call){
                $input = $call["arguments"] === "" ? [] : json_decode($call["arguments"], true);
                $block = ["type"=>"tool_use", "id"=>$call["id"], "name"=>$call["name"], "input"=>is_array($input) ? $input : []];

                if(!is_array($input)){
                    $block["invalid_input"] = true;
                }

                if(!empty($call["extra_content"])){
                    $block["extra_content"] = $call["extra_content"];
                }

                $blocks[] = $block;
                $this->emit(["type"=>"tool_input", "id"=>$call["id"], "input"=>$block["input"]]);
            }
        }

        $this->blocks = $blocks;

        if(!$stopped){
            if($this->calls){
                $this->stopReason = "tool_use";
            } else {
                $this->stopReason = match($this->finishReason){
                    "length"=>"max_tokens",
                    "content_filter"=>"refusal",
                    default=>"end_turn",
                };
            }
        }
    }
}
