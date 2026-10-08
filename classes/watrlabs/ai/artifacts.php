<?php

namespace watrlabs\ai;

// artifacts: things the model makes that are worth keeping (a web page, an svg, a document, a chunk of code).
// the model writes them inline in its answer as
//   <artifact id="snake-game" type="html" title="Snake">...the whole file...</artifact>
// so they stream in like any other text and work with models that can't call tools.
// once an answer is done we save each one, and writing the same id again makes a new version.
// a change to one can be sent as just the edits, which saves rewriting (and paying for) the whole thing:
//   <artifact id="snake-game" mode="edit">
//   <<<<<<< SEARCH
//   lines copied from the current version
//   =======
//   what they become
//   >>>>>>> REPLACE
//   </artifact>
// the edits are applied here to the latest version and saved as a new full version. if any of them don't
// line up, none are applied and the model gets told (with the current version) so it can try again
class artifacts {

    const TYPES = ["html", "svg", "markdown", "code"];
    const MAX_BYTES = 400 * 1024;
    const MAX_PER_CHAT = 60;

    const PATTERN = '/<artifact\b([^>]*)>(.*?)<\/artifact>/s';
    const EDIT_PATTERN = '/^<<<<<<< SEARCH[ \t]*\n(.*?)\n?^=======[ \t]*\n(.*?)\n?^>>>>>>> REPLACE[ \t]*$/ms';

    // what an html/svg artifact may load. anyone can make the model write one that reports to their own server
    // (a webhook), which would hand them the viewer's ip address and browser. so nothing leaves the page except
    // libraries and fonts from the big CDNs, which don't show their logs to whoever wrote the code. images have
    // to be inline (data:/blob:), and fetch, forms, beacons, frames and webrtc are all off. sent as a header on
    // /ai/artifacts/..., and put in the preview's srcdoc by ai.js as a <meta> (it gets it from aiData)
    const CDNS = "https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com";
    const CSP = "default-src 'none'; "
        . "script-src 'unsafe-inline' 'unsafe-eval' blob: " . self::CDNS . "; "
        . "style-src 'unsafe-inline' https://fonts.googleapis.com " . self::CDNS . "; "
        . "font-src data: https://fonts.gstatic.com " . self::CDNS . "; "
        . "img-src data: blob:; media-src data: blob:; worker-src blob:; "
        . "connect-src 'none'; form-action 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; manifest-src 'none'; "
        . "webrtc 'block'";

    // the sandbox both kinds of preview run in. no popups: a link out goes through the page, which asks first
    const SANDBOX = "allow-scripts allow-forms allow-modals allow-downloads";

    // the current version goes back to the model when an edit misses, cut down if it's huge
    const FEEDBACK_MAX_CHARS = 60000;

    // edits that didn't apply in the last prepare(): [ref, title, message, version, content, at]
    public array $errors = [];
    private int $at = 0;

    // the instructions that go in the system prompt
    static function prompt(bool $short = false){
        if($short){
            return "# Artifacts\n"
                . "When the user asks you to make something they'd keep or reuse (a web page, game, app, SVG, document, or 20+ lines of code), "
                . "type it straight into your reply inside artifact tags instead of a code block. It's plain text, not a tool. The page shows a live preview:\n"
                . "<artifact id=\"short-kebab-id\" type=\"html|svg|markdown|code\" title=\"Short title\" language=\"python\">\n"
                . "the complete content, no code fences\n"
                . "</artifact>\n"
                . "type=\"html\" is one self-contained page with inline CSS and JS (libraries from cdnjs.cloudflare.com are fine); localStorage, cookies, fetch and images from other sites don't work in it, so draw pictures with SVG/canvas or data: URLs. "
                . "language is only for type=\"code\". To change one, write the whole thing again with the same id. Keep the text around it short.";
        }

        // models with tools tend to go looking for an "artifact" tool, so say plainly that it's just text
        return "# Artifacts\n"
            . "Artifacts are NOT a tool and there is no artifact tool to call. An artifact is plain markup you type directly into your reply, "
            . "like Markdown. This chat page spots the tags in your reply, saves what's inside and shows the user a live preview.\n\n"
            . "When the user asks you to make something they'd want to keep, open or reuse (a web page, game, app, SVG drawing, document, "
            . "or a substantial piece of code, roughly 20 lines or more), write it inside artifact tags in your reply instead of a code block:\n"
            . "<artifact id=\"short-kebab-id\" type=\"html|svg|markdown|code\" title=\"Short title\" language=\"python\">\n"
            . "the complete content, with no code fences around it\n"
            . "</artifact>\n\n"
            . "Example reply to \"make me a counter button\":\n"
            . "Here's a counter, click the button to count up.\n"
            . "<artifact id=\"counter\" type=\"html\" title=\"Counter\">\n"
            . "<!doctype html>\n<html>\n<body>\n<button id=\"b\">Clicked 0 times</button>\n"
            . "<script>let n = 0; b.onclick = () => b.textContent = \"Clicked \" + (++n) + \" times\";</script>\n"
            . "</body>\n</html>\n"
            . "</artifact>\n\n"
            . "Rules:\n"
            . "- type=\"html\" is one self-contained page: inline CSS and JS. Libraries can come from cdnjs.cloudflare.com, cdn.jsdelivr.net or unpkg.com, fonts from Google Fonts. "
            . "It runs in a sandboxed preview with no access to this site, and localStorage, cookies and top-level navigation don't work there. "
            . "It can't reach the internet either: fetch, websockets, forms and images or files from other sites are blocked, "
            . "so draw pictures with SVG, canvas or data: URLs, and keep any data in the page itself.\n"
            . "- language is only for type=\"code\".\n"
            . "- To change an artifact you already made, send only the changes: the same id with mode=\"edit\", and one or more SEARCH/REPLACE blocks:\n"
            . "<artifact id=\"counter\" mode=\"edit\">\n"
            . "<<<<<<< SEARCH\n<button id=\"b\">Clicked 0 times</button>\n=======\n<button id=\"b\" style=\"font-size: 2em\">Clicked 0 times</button>\n>>>>>>> REPLACE\n"
            . "</artifact>\n"
            . "  Each SEARCH is copied exactly from the latest version (same spaces, indentation and line breaks) and has to match exactly one place, "
            . "so add a neighbouring line if it could match more than one. Keep them small: the lines that change plus just enough to be unique. "
            . "Blocks are applied in order; an empty REPLACE deletes the lines. To add something new, SEARCH for the line it goes after and REPLACE it with that line plus the new lines. "
            . "Edits are much cheaper and faster than rewriting, so use them for any change that leaves most of the artifact as it was. "
            . "Only write the whole artifact again (without mode=\"edit\") when you're changing most of it. If an edit doesn't line up you'll be told and shown the current version, so you can send it again.\n"
            . "- One artifact per thing. Keep the text around it short, the user can see the result in the preview.\n"
            . "- Short snippets and examples still go in normal Markdown code blocks.\n\n"
            . "# Plan before you build\n"
            . "Before you make a new artifact, or make a big change to one (a new feature, a redesign, a rewrite), plan it out first, in the same reply, inside plan tags, "
            . "then build it straight after following that plan:\n"
            . "<plan title=\"Snake game\">\n"
            . "Goal: one line on what it is and who it's for.\n"
            . "- [ ] each piece you'll build, in the order you'll build it (layout, controls, game loop, scoring, game over, ...)\n"
            . "- [ ] how it fits together: the main parts of the code and the data they share\n"
            . "- [ ] the details that are easy to get wrong (edge cases, mobile and touch, saving state, performance)\n"
            . "</plan>\n"
            . "Make the plan complete enough that someone else could build it from the plan alone, but keep each item to a line. "
            . "Then build the whole thing so it matches the plan; don't leave anything in the plan out. "
            . "If the request is too vague to plan without guessing at what they want, ask one or two quick questions instead of planning. "
            . "Skip the plan for small tweaks, quick fixes and anything that isn't an artifact.";
    }

    // the SEARCH/REPLACE pairs in an edit
    static function parseEdits(string $body){
        $body = str_replace("\r\n", "\n", $body);
        preg_match_all(self::EDIT_PATTERN, $body, $found, PREG_SET_ORDER);
        return array_map(fn($m) => [$m[1], $m[2]], $found);
    }

    // applies the edits in order. returns [new content, null] or [null, what went wrong]; all or nothing
    static function applyEdits(string $content, array $edits){
        $content = str_replace("\r\n", "\n", $content);

        if(!$edits){
            return [null, "there were no SEARCH/REPLACE blocks in it (each one needs the <<<<<<< SEARCH, ======= and >>>>>>> REPLACE lines)."];
        }

        foreach($edits as $i => [$search, $replace]){
            $n = $i + 1;

            if(trim($search) === ""){
                return [null, "edit $n has an empty SEARCH. Search for the line the new part goes after instead."];
            }

            $count = substr_count($content, $search);
            if($count === 1){
                $at = strpos($content, $search);
                $content = substr_replace($content, $replace, $at, strlen($search));
                continue;
            }
            if($count > 1){
                return [null, "edit $n's SEARCH matches $count places. Add a neighbouring line or two so it only matches one."];
            }

            // close enough: the same lines apart from trailing spaces, then apart from indentation
            $lines = explode("\n", $content);
            $want = explode("\n", $search);
            $at = null;

            foreach(["rtrim", "trim"] as $clean){
                $starts = self::findLines($lines, $want, $clean);
                if(count($starts) === 1){
                    $at = $starts[0];
                    break;
                }
                if(count($starts) > 1){
                    return [null, "edit $n's SEARCH matches " . count($starts) . " places. Add a neighbouring line or two so it only matches one."];
                }
            }

            if($at === null){
                $first = trim(strtok($search, "\n"));
                return [null, "edit $n's SEARCH wasn't found" . ($first !== "" ? " (it starts with \"" . mb_substr($first, 0, 80) . "\")" : "")
                    . ". It has to be copied exactly from the current version."];
            }

            array_splice($lines, $at, count($want), $replace === "" ? [] : explode("\n", $replace));
            $content = implode("\n", $lines);
        }

        return [$content, null];
    }

    // where $want appears in $lines, comparing each line after $clean
    private static function findLines(array $lines, array $want, string $clean){
        $want = array_map($clean, $want);
        // a blank line at either end of the search doesn't have to match
        while(count($want) > 1 && end($want) === ""){
            array_pop($want);
        }
        while(count($want) > 1 && $want[0] === ""){
            array_shift($want);
        }

        $starts = [];
        $size = count($want);
        for($i = 0; $i + $size <= count($lines); $i++){
            for($j = 0; $j < $size; $j++){
                if($clean($lines[$i + $j]) !== $want[$j]){
                    continue 2;
                }
            }
            $starts[] = $i;
        }
        return $starts;
    }

    // the hidden message that tells the model its edits missed, so it can send them again
    static function feedback(array $errors){
        $text = "[Automatic message from the chat page, not from the user.] Some artifact edits in your last reply didn't apply, "
            . "so those artifacts weren't changed:\n";
        foreach($errors as $error){
            $text .= "- \"" . $error["ref"] . "\": " . $error["message"] . "\n";
        }

        $shown = [];
        foreach($errors as $error){
            if($error["content"] === null || isset($shown[$error["ref"]])){
                continue;
            }
            $shown[$error["ref"]] = true;
            $content = $error["content"];
            $cut = mb_strlen($content) > self::FEEDBACK_MAX_CHARS;
            if($cut){
                $content = mb_substr($content, 0, self::FEEDBACK_MAX_CHARS);
            }
            $text .= "\nHere's the current version of \"" . $error["ref"] . "\" (v" . $error["version"] . ")" . ($cut ? ", cut off because it's long" : "")
                . ". Copy SEARCH text from this exactly:\n<current_version id=\"" . $error["ref"] . "\">\n" . $content . "\n</current_version>\n";
        }

        $text .= "\nSend the edit again with mode=\"edit\" (all of its blocks, since none of them were applied), or write the artifact in full if that's simpler. "
            . "Don't repeat your earlier explanation; at most say in a few words that you're fixing it.";
        return $text;
    }

    // same rules as the browser's copy in ai.js so the two agree on ids
    static function slug(string $text){
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $text), '-'));
        return substr($slug, 0, 64) ?: "artifact";
    }

    static function attributes(string $raw){
        preg_match_all('/([a-zA-Z_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $raw, $found, PREG_SET_ORDER);

        $attrs = [];
        foreach($found as $match){
            $attrs[strtolower($match[1])] = html_entity_decode($match[3] !== "" ? $match[3] : ($match[4] ?? ""), ENT_QUOTES);
        }
        return $attrs;
    }

    // models sometimes wrap the content in a code fence anyway
    static function cleanContent(string $content){
        $content = trim($content, "\r\n");
        if(preg_match('/^\s*```[\w+#-]*\s*\n(.*)\n\s*```\s*$/s', $content, $fenced)){
            $content = $fenced[1];
        }
        return $content;
    }

    // what kind of thing this is, from what the model said or what the content looks like
    static function type(array $attrs, string $content){
        $type = strtolower($attrs["type"] ?? "");
        $language = strtolower($attrs["language"] ?? "");

        if(in_array($type, self::TYPES, true)){
            return $type;
        }
        if(in_array($type, ["text/html", "page", "website"], true) || $language === "html" || preg_match('/^\s*(<!doctype html|<html)/i', $content)){
            return "html";
        }
        if($type === "image/svg+xml" || $language === "svg" || preg_match('/^\s*(<\?xml[^>]*>\s*)?<svg\b/i', $content)){
            return "svg";
        }
        if(in_array($type, ["md", "document", "text/markdown"], true) || in_array($language, ["md", "markdown"], true)){
            return "markdown";
        }
        return "code";
    }

    // finds the finished artifacts in an answer's text blocks, gives each one its version number and writes
    // that back into the tag. edits get applied to the latest version here. returns [blocks, saves]; the saves
    // get written once the message has an id, and edits that didn't apply end up in $this->errors
    public function prepare(int $chatId, array $blocks){
        $saves = [];
        $current = []; // ref -> the latest version so far, including ones earlier in this answer
        $this->errors = [];
        // where each tag is in the answer, so the browser hears about them in order (saves and misses alike)
        $this->at = 0;

        foreach($blocks as $i => $block){
            if($block["type"] !== "text" || stripos($block["text"], "<artifact") === false){
                continue;
            }

            $blocks[$i]["text"] = preg_replace_callback(self::PATTERN, function($match) use ($chatId, &$saves, &$current){
                $attrs = self::attributes($match[1]);
                $this->at++;

                if(strtolower($attrs["mode"] ?? "") === "edit"){
                    return $this->prepareEdit($chatId, $attrs, $match[2], $current, $saves);
                }

                $content = self::cleanContent($match[2]);
                $title = self::title($attrs["title"] ?? "");
                $ref = self::slug(($attrs["id"] ?? "") !== "" ? $attrs["id"] : $title);
                $type = self::type($attrs, $content);
                $language = $type === "code" ? mb_substr(strtolower(trim($attrs["language"] ?? "")), 0, 30) : null;

                $version = $this->latest($chatId, $ref, $current)["version"] + 1;
                $current[$ref] = compact("version", "content", "title", "type", "language");

                if(strlen($content) <= self::MAX_BYTES){
                    $saves[] = compact("ref", "title", "type", "language", "content", "version") + ["at"=>$this->at];
                }

                return self::tag($ref, $type, $title, $language, ["version"=>$version]) . "\n" . $content . "\n</artifact>";
            }, $block["text"]);
        }

        return [$blocks, $saves];
    }

    private function prepareEdit(int $chatId, array $attrs, string $body, array &$current, array &$saves){
        $ref = self::slug($attrs["id"] ?? "");
        $body = trim(str_replace("\r\n", "\n", $body), "\n");
        $base = $this->latest($chatId, $ref, $current);
        $title = trim($attrs["title"] ?? "") !== "" ? self::title($attrs["title"]) : ($base["title"] ?? "Untitled");

        $fail = function(string $message) use ($ref, $title, $base, $body){
            $this->errors[] = ["ref"=>$ref, "title"=>$title, "message"=>$message, "version"=>$base["version"], "content"=>$base["content"], "at"=>$this->at];
            return self::tag($ref, $base["type"] ?? "code", $title, $base["language"] ?? null, ["mode"=>"edit", "failed"=>"1"]) . "\n" . $body . "\n</artifact>";
        };

        if($base["content"] === null){
            return $fail("there's no artifact with that id in this chat, so there's nothing to edit. Write it in full instead.");
        }

        $edits = self::parseEdits($body);
        [$content, $error] = self::applyEdits($base["content"], $edits);
        if($error !== null){
            return $fail($error);
        }
        if(strlen($content) > self::MAX_BYTES){
            return $fail("the result would be over " . (self::MAX_BYTES / 1024) . " KB.");
        }

        $type = $base["type"];
        $language = $base["language"];
        $version = $base["version"] + 1;
        $current[$ref] = compact("version", "content", "title", "type", "language");
        $saves[] = compact("ref", "title", "type", "language", "content", "version") + ["edited"=>true, "at"=>$this->at];

        return self::tag($ref, $type, $title, $language, ["mode"=>"edit", "version"=>$version, "edits"=>count($edits)]) . "\n" . $body . "\n</artifact>";
    }

    // the newest version of an artifact in this chat: what's been written so far in this answer, else the saved one
    private function latest(int $chatId, string $ref, array &$current){
        global $db;

        if(!isset($current[$ref])){
            $current[$ref] = ["version"=>0, "content"=>null, "title"=>null, "type"=>null, "language"=>null];

            $artifact = $db->table("ai_artifacts")->where("chatid", $chatId)->where("ref", $ref)->first();
            if($artifact){
                $row = $db->table("ai_artifact_versions")->where("artifactid", $artifact->id)->where("version", (int) $artifact->latest)->first();
                $current[$ref] = [
                    "version"=>(int) $artifact->latest,
                    "content"=>$row ? $row->content : null,
                    "title"=>$artifact->title,
                    "type"=>$artifact->type,
                    "language"=>$artifact->language,
                ];
            }
        }

        return $current[$ref];
    }

    static function title(string $title){
        $title = trim($title) ?: "Untitled";
        return mb_substr(preg_replace('/\s+/', ' ', $title), 0, 120);
    }

    static function tag(string $ref, string $type, string $title, ?string $language, array $extra = []){
        $tag = '<artifact id="' . $ref . '"';
        if(isset($extra["mode"])){
            $tag .= ' mode="' . $extra["mode"] . '"';
            unset($extra["mode"]);
        }
        $tag .= ' type="' . $type . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ($language ? ' language="' . htmlspecialchars($language, ENT_QUOTES) . '"' : "");
        foreach($extra as $name => $value){
            $tag .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }
        return $tag . ">";
    }

    // the saved text with each applied edit swapped for the full version it made, for showing in the browser
    public function expandEdits(int $chatId, string $text){
        global $db;

        if(stripos($text, 'mode="edit"') === false){
            return $text;
        }

        return preg_replace_callback(self::PATTERN, function($match) use ($chatId, $db){
            $attrs = self::attributes($match[1]);
            if(strtolower($attrs["mode"] ?? "") !== "edit" || !empty($attrs["failed"]) || empty($attrs["version"])){
                return $match[0];
            }

            $row = $db->query(
                "SELECT v.content, a.type, a.language FROM ai_artifacts a INNER JOIN ai_artifact_versions v ON v.artifactid = a.id
                 WHERE a.chatid = ? AND a.ref = ? AND v.version = ? LIMIT 1",
                [$chatId, self::slug($attrs["id"] ?? ""), (int) $attrs["version"]]
            )->first();
            if(!$row){
                return $match[0];
            }

            return self::tag(self::slug($attrs["id"] ?? ""), $attrs["type"] ?? $row->type, $attrs["title"] ?? "Untitled", $attrs["language"] ?? $row->language,
                ["version"=>(int) $attrs["version"], "edits"=>(int) ($attrs["edits"] ?? 0)]) . "\n" . $row->content . "\n</artifact>";
        }, $text);
    }

    public function save(int $userId, int $chatId, int $messageId, array $saves){
        global $db;

        $saved = [];

        foreach($saves as $item){
            $artifact = $db->table("ai_artifacts")->where("chatid", $chatId)->where("ref", $item["ref"])->first();

            if(!$artifact){
                if($db->table("ai_artifacts")->where("chatid", $chatId)->count() >= self::MAX_PER_CHAT){
                    continue;
                }
                $id = $db->table("ai_artifacts")->insert([
                    "userid"=>$userId, "chatid"=>$chatId, "ref"=>$item["ref"], "title"=>$item["title"],
                    "type"=>$item["type"], "language"=>$item["language"], "latest"=>$item["version"],
                    "created"=>time(), "updated"=>time(),
                ]);
            } else {
                $id = $artifact->id;
                $db->table("ai_artifacts")->where("id", $id)->update([
                    "title"=>$item["title"], "type"=>$item["type"], "language"=>$item["language"],
                    "latest"=>max((int) $artifact->latest, $item["version"]), "updated"=>time(),
                ]);
            }

            $db->table("ai_artifact_versions")->insert([
                "artifactid"=>$id, "version"=>$item["version"], "content"=>$item["content"],
                "messageid"=>$messageId, "created"=>time(),
            ]);

            // the browser only has the edits for one of these, so it gets the full result
            $saved[] = ["ref"=>$item["ref"], "version"=>$item["version"], "title"=>$item["title"], "type"=>$item["type"], "at"=>$item["at"] ?? 0]
                + (!empty($item["edited"]) ? ["content"=>$item["content"], "language"=>$item["language"]] : []);
        }

        return $saved;
    }

    // one version (the latest if $version is null), only for the chat's owner
    public function find(int $userId, int $chatId, string $ref, ?int $version = null){
        global $db;

        $artifact = $db->table("ai_artifacts")->where("chatid", $chatId)->where("ref", $ref)->where("userid", $userId)->first();
        if(!$artifact){
            return null;
        }

        $row = $db->table("ai_artifact_versions")->where("artifactid", $artifact->id)
            ->where("version", $version ?? (int) $artifact->latest)->first();

        if(!$row){
            return null;
        }

        $artifact->version = (int) $row->version;
        $artifact->content = $row->content;
        return $artifact;
    }

    public function listFor(int $userId){
        global $db;

        return $db->query(
            "SELECT a.id, a.chatid, a.ref, a.title, a.type, a.language, a.latest, a.updated, c.title AS chatTitle,
                    (SELECT COUNT(*) FROM ai_artifact_versions v WHERE v.artifactid = a.id) AS versions
             FROM ai_artifacts a INNER JOIN ai_chats c ON c.id = a.chatid
             WHERE a.userid = ? ORDER BY a.updated DESC LIMIT 300",
            [$userId]
        )->get();
    }

    // after regenerate trims messages, their versions go too (and artifacts left with none)
    public function forgetMessagesAfter(int $chatId, int $messageId){
        global $db;

        $ids = array_map(fn($a) => (int) $a->id, $db->table("ai_artifacts")->select(["id"])->where("chatid", $chatId)->get());
        if(!$ids){
            return;
        }

        $db->table("ai_artifact_versions")->whereIn("artifactid", $ids)->where("messageid", ">", $messageId)->delete();

        foreach($ids as $id){
            $latest = $db->table("ai_artifact_versions")->select(["version"])->where("artifactid", $id)->orderBy("version", "DESC")->first();
            if(!$latest){
                $db->table("ai_artifacts")->where("id", $id)->delete();
            } else {
                $db->table("ai_artifacts")->where("id", $id)->update(["latest"=>(int) $latest->version]);
            }
        }
    }

    public function deleteChat(int $chatId){
        global $db;

        $ids = array_map(fn($a) => (int) $a->id, $db->table("ai_artifacts")->select(["id"])->where("chatid", $chatId)->get());
        if($ids){
            $db->table("ai_artifact_versions")->whereIn("artifactid", $ids)->delete();
        }
        $db->table("ai_artifacts")->where("chatid", $chatId)->delete();
    }

    public function delete(int $userId, int $id){
        global $db;

        $artifact = $db->table("ai_artifacts")->where("id", $id)->where("userid", $userId)->first();
        if(!$artifact){
            return false;
        }

        $db->table("ai_artifact_versions")->where("artifactid", $id)->delete();
        $db->table("ai_artifacts")->where("id", $id)->delete();
        return true;
    }
}
