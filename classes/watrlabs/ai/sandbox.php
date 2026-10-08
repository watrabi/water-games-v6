<?php

namespace watrlabs\ai;

use watrlabs\watrkit\ratelimit;

// run_code: python or shell in a throwaway docker container (sandbox/runner.py does the running).
// each chat has its own /work folder that lasts between runs, so the model can build on earlier work
class sandbox {

    const TOOL_NAME = "run_code";
    const SHARE_NAME = "share_file";
    const SHARES_PER_DAY = 40;
    const PER_MINUTE = 6;
    const PER_DAY = 100;

    static function enabled(): bool {
        return in_array("sandbox", config::tools(), true) && config::env("SANDBOX_TOKEN");
    }

    static function handles(string $name): bool {
        return $name === self::TOOL_NAME || $name === self::SHARE_NAME;
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
        ], [
            "name"=>self::SHARE_NAME,
            "description"=>"Show the user a file from the sandbox's /work folder, like a chart, picture, spreadsheet, zip or document you made with run_code. "
                . "Images appear in the chat, other files as a download. Up to 10 MB. Save the file with run_code first, then share it.",
            "schema"=>[
                "type"=>"object",
                "properties"=>[
                    "path"=>["type"=>"string", "description"=>"Path inside /work, e.g. chart.png or /work/out/report.xlsx"],
                ],
                "required"=>["path"],
            ],
        ]];
    }

    // [content, isError, file block for the chat or null]
    static function run(array $call, $user, int $chatId): array {
        $input = is_array($call["input"] ?? null) ? $call["input"] : [];

        if($call["name"] === self::SHARE_NAME){
            return self::share((string) ($input["path"] ?? ""), $user, $chatId);
        }

        $code = (string) ($input["code"] ?? "");
        $language = ($input["language"] ?? "python") === "bash" ? "bash" : "python";

        if(trim($code) === ""){
            return ["There's no code to run.", true, null];
        }

        $who = "u" . (int) $user->id;
        if(!ratelimit::hit("ai_sandbox_min", $who, self::PER_MINUTE, 60)){
            return ["Too many runs in the last minute. Wait a moment, or do more in each run.", true, null];
        }
        if(!ratelimit::hit("ai_sandbox_day", $who, self::PER_DAY, 86400)){
            return ["This user has used today's " . self::PER_DAY . " sandbox runs. Answer without running code.", true, null];
        }

        // a minute waiting for a free slot plus a minute running
        $result = self::call("/run", ["workspace"=>"c" . $chatId, "language"=>$language, "code"=>$code], 150);
        if(empty($result["ok"])){
            return [$result["error"] ?? "The sandbox failed.", true, null];
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

        return [implode("\n\n", $parts), $result["exit_code"] !== 0 || $result["timed_out"], null];
    }

    private static function share(string $path, $user, int $chatId): array {
        if(!ratelimit::hit("ai_sandbox_share", "u" . (int) $user->id, self::SHARES_PER_DAY, 86400)){
            return ["This user has had today's " . self::SHARES_PER_DAY . " shared files. Describe the result instead.", true, null];
        }

        $result = self::call("/file", ["workspace"=>"c" . $chatId, "path"=>$path], 30);
        if(empty($result["ok"])){
            return [$result["error"] ?? "Couldn't get that file.", true, null];
        }

        $bytes = base64_decode($result["data"], true);
        if($bytes === false){
            return ["Couldn't get that file.", true, null];
        }

        $stored = (new chats())->storeFile((int) $user->id, $bytes, $result["name"]);
        $block = ["type"=>"file", "attachment"=>$stored["id"], "name"=>$stored["name"], "mime"=>$stored["mime"], "size"=>$stored["size"]];

        return ["Shared " . $stored["name"] . " (" . $stored["size"] . " bytes) with the user, it's showing in the chat now. Don't link to it.", false, $block];
    }

    private static function call(string $endpoint, array $request, int $timeout): array {
        $ch = curl_init(rtrim(config::env("SANDBOX_URL", "http://127.0.0.1:3003"), "/") . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($request),
            CURLOPT_HTTPHEADER=>["Content-Type: application/json", "Authorization: Bearer " . config::env("SANDBOX_TOKEN")],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>$timeout,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $result = $body === false ? null : json_decode($body, true);
        return is_array($result) ? $result : ["ok"=>false, "error"=>"The sandbox isn't available right now."];
    }
}
