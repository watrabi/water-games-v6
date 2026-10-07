<?php

namespace watrlabs\ai;

use watrlabs\ai\providers\provider;

// runs one turn of a chat: saves the prompt, calls the model, runs tools, saves what comes back,
// and streams events to the browser along the way through $emit
class assistant {

    const MAX_TOOL_ROUNDS = 8;
    const MAX_PROMPT_CHARS = 20000;
    const MAX_TOOL_RESULT_CHARS = 30000;

    private chats $chats;
    private $emit;
    private array $imageCache = [];
    private bool $regenerate = false;

    function __construct(callable $emit){
        $this->chats = new chats();
        $this->emit = $emit;
    }

    private function emit(array $event){
        call_user_func($this->emit, $event);
    }

    // $request: chatId, model, text, attachments (ids), regenerate
    public function run($user, array $request){
        $this->regenerate = !empty($request["regenerate"]);

        $model = config::model($request["model"] ?? null);
        if(!$model){
            return $this->fail("That model isn't available.");
        }

        $caps = config::capabilities($model);
        $text = trim((string) ($request["text"] ?? ""));
        $attachmentIds = array_values(array_unique(array_map("intval", (array) ($request["attachments"] ?? []))));

        $limit = config::dailyLimit();
        if($limit > 0 && $this->chats->promptsToday($user->id) >= $limit){
            return $this->fail("You've hit today's limit of $limit messages. Try again tomorrow.");
        }

        // everything gets checked before a chat is created or anything is saved
        $attachments = [];

        if(!$this->regenerate){
            if($text === "" && !$attachmentIds){
                return $this->fail("Type something first.");
            }

            if(mb_strlen($text) > self::MAX_PROMPT_CHARS){
                return $this->fail("That message is too long (" . self::MAX_PROMPT_CHARS . " characters max).");
            }

            if(count($attachmentIds) > chats::MAX_IMAGES_PER_MESSAGE){
                return $this->fail("You can attach up to " . chats::MAX_IMAGES_PER_MESSAGE . " images at a time.");
            }

            if($attachmentIds && !$caps["vision"]){
                return $this->fail($model["label"] . " can't look at images. Pick a different model or remove the image.");
            }

            foreach($attachmentIds as $id){
                $attachment = $this->chats->attachment($id, $user->id);
                if(!$attachment){
                    return $this->fail("One of the images couldn't be found, try attaching it again.");
                }
                $attachments[] = $attachment;
            }
        }

        // find or start the chat
        $chatId = isset($request["chatId"]) ? (int) $request["chatId"] : 0;

        if($chatId){
            $chat = $this->chats->get($chatId, $user->id);
            if(!$chat){
                return $this->fail("That chat doesn't exist.");
            }
        } elseif($this->regenerate){
            return $this->fail("Nothing to regenerate.");
        } else {
            $title = $text !== "" ? $text : "Image";
            $title = preg_replace('/\s+/', ' ', $title);
            if(mb_strlen($title) > 60){
                $title = rtrim(mb_substr($title, 0, 57)) . "...";
            }

            $chatId = (int) $this->chats->create($user->id, $title, $model["id"]);
            $this->emit(["type"=>"chat", "id"=>$chatId, "title"=>$title]);
        }

        if($this->regenerate){
            if(!$this->chats->trimAfterLastPrompt($chatId)){
                return $this->fail("Nothing to regenerate.");
            }
        } else {
            $content = [];
            foreach($attachments as $attachment){
                $content[] = ["type"=>"image", "attachment"=>(int) $attachment->id, "mime"=>$attachment->mime];
            }
            if($text !== ""){
                $content[] = ["type"=>"text", "text"=>$text];
            }

            $this->chats->addMessage($chatId, "user", $content);
        }

        $history = $this->history($chatId, $model, $caps, $user->id);
        $tools = $caps["tools"] ? tools::definitions() : [];
        $system = $this->systemPrompt($user);

        for($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++){
            $provider = provider::for($model, $this->emit);
            $result = $provider->run($system, $history, $tools);
            $blocks = $result["blocks"];
            $stop = $result["stop"];

            if($stop === "refusal"){
                // partial output from a declined answer shouldn't be kept or reused
                $this->chats->addMessage($chatId, "assistant", [["type"=>"notice", "kind"=>"refused", "text"=>"The model declined to answer this."]], $model["id"]);
                $this->emit(["type"=>"refused", "text"=>"The model declined to answer this."]);
                break;
            }

            if($stop === "error" || $stop === "stopped"){
                $kept = array_values(array_filter($blocks, fn($b) => in_array($b["type"], ["text", "reasoning"], true)));
                $notice = $stop === "error"
                    ? ["type"=>"notice", "kind"=>"error", "text"=>$result["error"] ?? "Something went wrong."]
                    : ["type"=>"notice", "kind"=>"stopped", "text"=>"Stopped."];
                $kept[] = $notice;

                $this->chats->addMessage($chatId, "assistant", $kept, $model["id"]);

                if($stop === "error"){
                    $this->emit(["type"=>"error", "message"=>$notice["text"], "retry"=>true]);
                }
                break;
            }

            if($stop === "max_tokens"){
                $blocks = array_values(array_filter($blocks, fn($b) => $b["type"] !== "tool_use"));
                $blocks[] = ["type"=>"notice", "kind"=>"max_tokens", "text"=>"The answer hit the length limit and got cut off."];
                $this->emit(["type"=>"notice", "text"=>"The answer hit the length limit and got cut off."]);
            }

            $this->chats->addMessage($chatId, "assistant", $blocks, $model["id"]);
            $history[] = ["role"=>"assistant", "content"=>$this->forModel($blocks, $model)];

            if($stop !== "tool_use"){
                break;
            }

            // run every tool call from this answer, then send all the results back in one message
            $results = [];
            foreach($blocks as $block){
                if($block["type"] !== "tool_use"){
                    continue;
                }

                [$output, $isError] = tools::run($block);

                if(mb_strlen($output) > self::MAX_TOOL_RESULT_CHARS){
                    $output = mb_substr($output, 0, self::MAX_TOOL_RESULT_CHARS) . "\n[cut off]";
                }

                $results[] = [
                    "type"=>"tool_result",
                    "tool_use_id"=>$block["id"],
                    "name"=>$block["name"],
                    "content"=>$output,
                    "is_error"=>$isError,
                ];

                $this->emit(["type"=>"tool_result", "id"=>$block["id"], "content"=>mb_substr($output, 0, 2000), "is_error"=>$isError]);
            }

            $this->chats->addMessage($chatId, "user", $results, null, false);
            $history[] = ["role"=>"user", "content"=>$results];

            if(connection_aborted()){
                break;
            }

            if($round === self::MAX_TOOL_ROUNDS - 1){
                $this->chats->addMessage($chatId, "assistant", [["type"=>"notice", "kind"=>"error", "text"=>"Stopped after too many tool calls in a row."]], $model["id"]);
                $this->emit(["type"=>"notice", "text"=>"Stopped after too many tool calls in a row."]);
            }
        }

        $this->chats->touch($chatId, $model["id"]);
        $this->emit(["type"=>"done", "chatId"=>$chatId]);
    }

    // every failure goes out before the prompt is saved, so the browser can hand the message back
    private function fail(string $message){
        $this->emit(["type"=>"error", "message"=>$message, "unsent"=>!$this->regenerate]);
    }

    private function systemPrompt($user){
        $site = $_ENV["APP_NAME"] ?? "Water Games";

        $prompt = "You're the assistant on $site, a website with free browser games, web apps and music. "
            . "You're chatting with {$user->username}. Today is " . gmdate("l, F j, Y") . " (UTC).\n\n"
            . "Be friendly, direct and useful. Keep answers as short as the question allows. "
            . "Format with Markdown when it helps (lists, tables, code blocks with a language tag); don't use headings for short answers.\n\n"
            . "If the user asks about games, apps or music on this site, search for them with the search_site tool and link to them "
            . "with the relative paths it gives you, like [Slope](/games/12). Don't make up links. "
            . "Use the calculator for arithmetic instead of working it out in your head. "
            . "If the user shares an image, look at it carefully before answering.";

        if($extra = config::env("AI_SYSTEM_PROMPT")){
            $prompt .= "\n\n" . $extra;
        }

        return $prompt;
    }

    // the saved chat, turned into what the provider needs
    private function history(int $chatId, array $model, array $caps, int $userId){
        $messages = [];

        foreach($this->chats->messages($chatId) as $row){
            $content = [];

            foreach($row["content"] as $block){
                if($block["type"] === "image"){
                    if(!$caps["vision"]){
                        $content[] = ["type"=>"text", "text"=>"[an image was shared here]"];
                        continue;
                    }

                    $image = $this->loadImage((int) $block["attachment"], $userId);
                    $content[] = $image ?? ["type"=>"text", "text"=>"[an image was shared here but it's no longer available]"];
                    continue;
                }

                $content[] = $block;
            }

            if($row["role"] === "assistant"){
                $content = $this->forModel($content, $model, $row["model"]);
            }

            $messages[] = ["role"=>$row["role"], "content"=>$content];
        }

        return self::pairTools($messages);
    }

    // strips the blocks a given model shouldn't see: display-only stuff, and thinking from a different model
    private function forModel(array $blocks, array $model, ?string $writtenBy = null){
        $writtenBy = $writtenBy ?? $model["id"];

        return array_values(array_filter($blocks, function($block) use ($model, $writtenBy){
            switch($block["type"]){
                case "notice":
                case "reasoning":
                    return false;
                case "thinking":
                case "redacted_thinking":
                    return $model["provider"] === "anthropic" && $writtenBy === $model["id"] && ($block["model"] ?? $writtenBy) === $model["id"];
                case "text":
                    return $block["text"] !== "";
            }
            return true;
        }));
    }

    // every tool_use needs its tool_result right after it (and the other way round), or the apis refuse the request
    private static function pairTools(array $messages){
        $count = count($messages);

        for($i = 0; $i < $count; $i++){
            if($messages[$i]["role"] !== "assistant"){
                continue;
            }

            $next = $messages[$i + 1] ?? null;
            $answered = [];

            if($next && $next["role"] === "user"){
                foreach($next["content"] as $block){
                    if($block["type"] === "tool_result"){
                        $answered[] = $block["tool_use_id"];
                    }
                }
            }

            $messages[$i]["content"] = array_values(array_filter($messages[$i]["content"], fn($b) =>
                $b["type"] !== "tool_use" || in_array($b["id"], $answered, true)
            ));
        }

        $asked = [];
        foreach($messages as $i => $message){
            if($message["role"] === "assistant"){
                $asked = array_column(array_filter($message["content"], fn($b) => $b["type"] === "tool_use"), "id");
                continue;
            }

            $messages[$i]["content"] = array_values(array_filter($message["content"], fn($b) =>
                $b["type"] !== "tool_result" || in_array($b["tool_use_id"], $asked, true)
            ));
            $asked = [];
        }

        // an assistant turn that's only thinking (or nothing) isn't worth sending back
        return array_values(array_filter($messages, function($m){
            if($m["role"] === "assistant"){
                return (bool) array_filter($m["content"], fn($b) => in_array($b["type"], ["text", "tool_use"], true));
            }
            return count($m["content"]) > 0;
        }));
    }

    private function loadImage(int $id, int $userId){
        if(isset($this->imageCache[$id])){
            return $this->imageCache[$id];
        }

        $attachment = $this->chats->attachment($id, $userId);
        if(!$attachment){
            return null;
        }

        $file = $this->chats->attachmentFile($attachment);
        if(!is_file($file)){
            return null;
        }

        return $this->imageCache[$id] = [
            "type"=>"image",
            "mime"=>$attachment->mime,
            "data"=>base64_encode(file_get_contents($file)),
        ];
    }
}
