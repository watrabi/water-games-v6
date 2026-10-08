<?php

namespace watrlabs\ai\providers;

use watrlabs\ai\config;

// ollama's own api, POST {url}/api/chat, streams one json object per line
class ollama extends provider {

    private string $text = "";
    private string $thinking = "";
    private bool $thinkingOpen = false;
    private array $calls = [];
    private ?string $doneReason = null;

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

        // only sent to models that can think, ollama errors on the rest
        if(($this->model["think"] ?? "auto") !== "auto" && config::canThink($this->model)){
            $body["think"] = $this->model["think"] === "yes";
        }

        if($keepAlive = config::option($this->model, "keep_alive")){
            $body["keep_alive"] = $keepAlive;
        }

        $headers = [];
        if($key = $this->model["key"]){
            $headers[] = "Authorization: Bearer " . $key;
        }

        return [$this->model["url"] . "/api/chat", $headers, $body];
    }

    private function convertMessages(array $messages){
        $out = [];

        foreach($messages as $message){
            if($message["role"] === "assistant"){
                $entry = ["role"=>"assistant", "content"=>self::textOf($message["content"])];

                foreach($message["content"] as $block){
                    if($block["type"] === "tool_use"){
                        $entry["tool_calls"][] = ["function"=>["name"=>$block["name"], "arguments"=>(object) $block["input"]]];
                    }
                }

                if($entry["content"] !== "" || isset($entry["tool_calls"])){
                    $out[] = $entry;
                }
                continue;
            }

            $images = [];
            foreach($message["content"] as $block){
                if($block["type"] === "tool_result"){
                    $out[] = ["role"=>"tool", "content"=>$block["content"], "tool_name"=>$block["name"] ?? ""];
                } elseif($block["type"] === "image"){
                    $images[] = $block["data"];
                }
            }

            $text = self::textOf($message["content"]);
            if($text !== "" || $images){
                $entry = ["role"=>"user", "content"=>$text];
                if($images){
                    $entry["images"] = $images;
                }
                $out[] = $entry;
            }
        }

        return $out;
    }

    protected function handleLine(string $line): void {
        $data = json_decode($line, true);
        if(!is_array($data)){
            return;
        }

        if(isset($data["error"])){
            $this->error = "The model returned an error: " . (is_string($data["error"]) ? $data["error"] : json_encode($data["error"]));
            $this->stopReason = "error";
            return;
        }

        $message = $data["message"] ?? [];

        if(!empty($message["thinking"])){
            if(!$this->thinkingOpen){
                $this->thinkingOpen = true;
                $this->emit(["type"=>"thinking_start"]);
            }
            $this->thinking .= $message["thinking"];
            $this->emit(["type"=>"thinking", "text"=>$message["thinking"]]);
        }

        if(isset($message["content"]) && $message["content"] !== ""){
            $this->closeThinking();
            $this->text .= $message["content"];
            $this->emit(["type"=>"text", "text"=>$message["content"]]);
        }

        // ollama sends whole tool calls, no ids, so make some up
        foreach($message["tool_calls"] ?? [] as $call){
            $this->closeThinking();
            $id = self::newToolId();
            $arguments = $call["function"]["arguments"] ?? [];

            if(is_string($arguments)){
                $arguments = json_decode($arguments, true);
            }

            $this->calls[] = [
                "type"=>"tool_use",
                "id"=>$id,
                "name"=>$call["function"]["name"] ?? "",
                "input"=>is_array($arguments) ? $arguments : [],
            ];

            $this->emit(["type"=>"tool_start", "id"=>$id, "name"=>$call["function"]["name"] ?? ""]);
            $this->emit(["type"=>"tool_input", "id"=>$id, "input"=>is_array($arguments) ? $arguments : []]);
        }

        if(!empty($data["done"])){
            $this->doneReason = $data["done_reason"] ?? "stop";
        }
    }

    private function closeThinking(){
        if($this->thinkingOpen){
            $this->thinkingOpen = false;
            $this->emit(["type"=>"thinking_end"]);
        }
    }

    protected function finish(): void {
        $this->closeThinking();

        $blocks = [];
        if($this->thinking !== ""){
            $blocks[] = ["type"=>"reasoning", "text"=>$this->thinking];
        }
        if($this->text !== ""){
            $blocks[] = ["type"=>"text", "text"=>$this->text];
        }

        $stopped = in_array($this->stopReason, ["stopped", "error"], true);

        if(!$stopped){
            $blocks = array_merge($blocks, $this->calls);

            if($this->calls){
                $this->stopReason = "tool_use";
            } else {
                $this->stopReason = $this->doneReason === "length" ? "max_tokens" : "end_turn";
            }
        }

        $this->blocks = $blocks;
    }
}
