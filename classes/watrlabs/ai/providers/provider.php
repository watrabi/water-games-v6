<?php

namespace watrlabs\ai\providers;

use watrlabs\ai\config;

// shared bits for the three api styles.
//
// every provider takes the chat history in one common shape (basically anthropic's content blocks):
//   text        ["type"=>"text", "text"=>...]
//   image       ["type"=>"image", "mime"=>..., "data"=>base64]   (attachments get loaded before this)
//   tool_use    ["type"=>"tool_use", "id"=>..., "name"=>..., "input"=>[...]]
//   tool_result ["type"=>"tool_result", "tool_use_id"=>..., "name"=>..., "content"=>string, "is_error"=>bool]
//   thinking / redacted_thinking   anthropic only, passed back untouched
//
// and streams back blocks in that same shape, calling $emit for anything the browser should see
abstract class provider {

    protected array $model;
    protected $emit;
    protected bool $aborted = false;
    protected int $lastPing = 0;
    // place in line for a local model, see takeTicket()
    protected ?string $ticket = null;
    protected int $ahead = 0;
    protected int $lastQueueCheck = 0;
    protected bool $started = false;
    // background calls (titles, suggestions) answer with plain json, so no keepalive pings
    public bool $quiet = false;
    public ?int $timeout = null;

    // filled in while streaming
    protected array $blocks = [];
    protected ?string $stopReason = null;
    protected ?string $error = null;
    protected ?string $servedBy = null;

    function __construct(array $model, callable $emit){
        $this->model = $model;
        $this->emit = $emit;
    }

    abstract protected function request(string $system, array $messages, array $tools): array;
    abstract protected function handleLine(string $line): void;
    abstract protected function finish(): void;

    static function for(array $model, callable $emit): provider {
        switch($model["provider"]){
            case "anthropic": return new anthropic($model, $emit);
            case "openai": return new openai($model, $emit);
            case "ollama": return new ollama($model, $emit);
        }

        throw new \InvalidArgumentException("Unknown provider " . $model["provider"]);
    }

    // runs one model call. returns:
    //   blocks  the assistant's content blocks
    //   stop    end_turn | tool_use | max_tokens | refusal | stopped | error
    //   error   message when stop is error
    //   model   what actually answered (anthropic can fall back to another model)
    public function run(string $system, array $messages, array $tools): array {
        [$url, $headers, $body] = $this->request($system, $messages, $tools);

        if($this->model["provider"] === "ollama"){
            $this->takeTicket();
        }

        $buffer = "";
        $status = 0;
        $errorBody = "";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER=>array_merge(["Content-Type: application/json"], $headers),
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>$this->timeout ?? (int) config::env("AI_TIMEOUT", 600),
            CURLOPT_HEADERFUNCTION=>function($ch, $header) use (&$status){
                if(preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)){
                    $status = (int) $m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION=>function($ch, $chunk) use (&$buffer, &$status, &$errorBody){
                if($status >= 400){
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }

                $this->started = true;
                $buffer .= $chunk;

                while(($pos = strpos($buffer, "\n")) !== false){
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);

                    if($line !== ""){
                        $this->handleLine($line);
                    }
                }

                return strlen($chunk);
            },
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>function(){
                $this->checkQueue();
                return $this->checkClient() ? 0 : 1;
            },
        ]);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        $this->dropTicket();

        if(trim($buffer) !== "" && $status < 400){
            $this->handleLine(trim($buffer));
        }

        if($this->aborted){
            $this->stopReason = "stopped";
        } elseif($status >= 400){
            $this->error = $this->errorMessage($status, $errorBody);
            $this->stopReason = "error";
        } elseif($ok === false){
            $this->error = "Couldn't reach the model (" . $curlError . ").";
            $this->stopReason = "error";
        }

        $this->finish();

        return [
            "blocks"=>$this->blocks,
            "stop"=>$this->stopReason ?? "end_turn",
            "error"=>$this->error,
            "model"=>$this->servedBy,
        ];
    }

    // ollama answers one request per model at a time and queues the rest without saying so, so every
    // request to it takes a ticket here: a file named by model and time. the older tickets still around
    // are the requests ahead of this one. a ticket from a request that died without cleaning up stops
    // counting once it's older than the longest a request can run
    private static function queueDir(){
        return __DIR__ . "/../../../../storage/cache/ai-queue";
    }

    private function queueKey(){
        return md5($this->model["url"] . "|" . $this->model["name"]);
    }

    protected function takeTicket(){
        $dir = self::queueDir();
        if(!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)){
            return; // no queue display is better than no answer
        }

        $name = $this->queueKey() . "-" . sprintf("%017d", (int) (microtime(true) * 1e6)) . "-" . bin2hex(random_bytes(3));
        if(@touch($dir . "/" . $name)){
            $this->ticket = $name;
            register_shutdown_function(fn() => $this->dropTicket());
        }
    }

    protected function dropTicket(){
        if($this->ticket){
            @unlink(self::queueDir() . "/" . $this->ticket);
            $this->ticket = null;
        }
    }

    // how many requests to the same model are ahead of this one
    protected function countAhead(){
        $dir = self::queueDir();
        $stale = time() - (int) config::env("AI_TIMEOUT", 600) - 60;
        $ahead = 0;

        foreach(glob($dir . "/" . $this->queueKey() . "-*") ?: [] as $file){
            $name = basename($file);
            if($name >= $this->ticket){
                continue;
            }
            if(@filemtime($file) < $stale){
                @unlink($file);
                continue;
            }
            $ahead++;
        }

        return $ahead;
    }

    // tells the browser its place in line until the model starts answering
    protected function checkQueue(){
        if(!$this->ticket || $this->started || $this->quiet || time() - $this->lastQueueCheck < 2){
            return;
        }
        $this->lastQueueCheck = time();

        $ahead = $this->countAhead();
        if($ahead !== $this->ahead){
            $this->ahead = $ahead;
            $this->emit(["type"=>"queue", "ahead"=>$ahead]);
        }
    }

    // keeps the browser connection alive and notices when it's gone
    protected function checkClient(){
        if($this->quiet){
            return true;
        }

        if(time() - $this->lastPing >= 10){
            $this->lastPing = time();
            echo ": ping\n\n";
            flush();
        }

        if(connection_aborted()){
            $this->aborted = true;
            return false;
        }

        return true;
    }

    protected function emit(array $event){
        call_user_func($this->emit, $event);
    }

    protected function errorMessage(int $status, string $body){
        $json = json_decode($body, true);
        $message = $json["error"]["message"] ?? (is_string($json["error"] ?? null) ? $json["error"] : null) ?? $json["message"] ?? null;

        if(!$message){
            $message = trim(substr(strip_tags($body), 0, 200)) ?: "no details";
        }

        return "The model returned an error ($status): $message";
    }

    // the text of a message, for apis that only take strings
    protected static function textOf(array $blocks){
        $parts = [];
        foreach($blocks as $block){
            if($block["type"] === "text" && $block["text"] !== ""){
                $parts[] = $block["text"];
            }
        }
        return implode("\n\n", $parts);
    }

    protected static function newToolId(){
        return "call_" . bin2hex(random_bytes(8));
    }
}
