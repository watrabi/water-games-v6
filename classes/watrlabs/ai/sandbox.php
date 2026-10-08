<?php

namespace watrlabs\ai;

use watrlabs\watrkit\ratelimit;

// run_code: python or shell in a throwaway docker container (sandbox/runner.py does the running).
// each chat has its own /work folder that lasts between runs, so the model can build on earlier work
class sandbox {

    const TOOL_NAME = "run_code";
    const PER_MINUTE = 6;
    const PER_DAY = 100;

    static function enabled(): bool {
        return in_array("sandbox", config::tools(), true) && config::env("SANDBOX_TOKEN");
    }

    static function handles(string $name): bool {
        return $name === self::TOOL_NAME;
    }

    static function definitions(): array {
        return [[
            "name"=>self::TOOL_NAME,
            "description"=>"Run Python 3 or a bash script in a Linux sandbox and get back its output. Use it to do maths and data work exactly, "
                . "test code before you give it to the user, convert or make files, or fetch data from the internet. "
                . "numpy, pandas, matplotlib, pillow, requests, beautifulsoup4, sympy, scipy, openpyxl, curl, git, jq, ffmpeg and imagemagick are installed; "
                . "pip install --user works. Files in /work stay for the rest of this chat. Print what you need to see, each run is limited to "
                . "60 seconds and 512 MB, and the user can't see the sandbox's files, only what you tell them.",
            "schema"=>[
                "type"=>"object",
                "properties"=>[
                    "language"=>["type"=>"string", "enum"=>["python", "bash"], "description"=>"Defaults to python."],
                    "code"=>["type"=>"string", "description"=>"The whole program or script to run."],
                ],
                "required"=>["code"],
            ],
        ]];
    }

    // [content, isError]
    static function run(array $call, $user, int $chatId): array {
        $input = is_array($call["input"] ?? null) ? $call["input"] : [];
        $code = (string) ($input["code"] ?? "");
        $language = ($input["language"] ?? "python") === "bash" ? "bash" : "python";

        if(trim($code) === ""){
            return ["There's no code to run.", true];
        }

        $who = "u" . (int) $user->id;
        if(!ratelimit::hit("ai_sandbox_min", $who, self::PER_MINUTE, 60)){
            return ["Too many runs in the last minute. Wait a moment, or do more in each run.", true];
        }
        if(!ratelimit::hit("ai_sandbox_day", $who, self::PER_DAY, 86400)){
            return ["This user has used today's " . self::PER_DAY . " sandbox runs. Answer without running code.", true];
        }

        $ch = curl_init(rtrim(config::env("SANDBOX_URL", "http://127.0.0.1:3003"), "/") . "/run");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(["workspace"=>"c" . $chatId, "language"=>$language, "code"=>$code]),
            CURLOPT_HTTPHEADER=>["Content-Type: application/json", "Authorization: Bearer " . config::env("SANDBOX_TOKEN")],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>150, // a minute waiting for a free slot plus a minute running
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $result = $body === false ? null : json_decode($body, true);
        if(!is_array($result)){
            return ["The sandbox isn't available right now.", true];
        }
        if(empty($result["ok"])){
            return [$result["error"] ?? "The sandbox failed.", true];
        }

        $parts = [];
        if($result["timed_out"]){
            $parts[] = "Stopped after 60 seconds (time limit).";
        }
        $parts[] = "Exit code " . $result["exit_code"] . " (" . $result["seconds"] . "s)";
        if($result["stdout"] !== ""){
            $parts[] = "stdout:\n" . $result["stdout"];
        }
        if($result["stderr"] !== ""){
            $parts[] = "stderr:\n" . $result["stderr"];
        }
        if($result["stdout"] === "" && $result["stderr"] === ""){
            $parts[] = "(no output, print what you want to see)";
        }
        $parts[] = "Files in /work: " . ($result["files"] ? implode(", ", $result["files"]) : "none");

        return [implode("\n\n", $parts), $result["exit_code"] !== 0 || $result["timed_out"]];
    }
}
