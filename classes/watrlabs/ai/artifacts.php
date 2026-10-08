<?php

namespace watrlabs\ai;

// artifacts: things the model makes that are worth keeping (a web page, an svg, a document, a chunk of code).
// the model writes them inline in its answer as
//   <artifact id="snake-game" type="html" title="Snake">...the whole file...</artifact>
// so they stream in like any other text and work with models that can't call tools.
// once an answer is done we save each one, and writing the same id again makes a new version
class artifacts {

    const TYPES = ["html", "svg", "markdown", "code"];
    const MAX_BYTES = 400 * 1024;
    const MAX_PER_CHAT = 60;

    const PATTERN = '/<artifact\b([^>]*)>(.*?)<\/artifact>/s';

    // the instructions that go in the system prompt
    static function prompt(){
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
            . "- type=\"html\" is one self-contained page: inline CSS and JS. Libraries can come from cdnjs.cloudflare.com or cdn.jsdelivr.net. "
            . "It runs in a sandboxed preview with no access to this site, and localStorage, cookies and top-level navigation don't work there.\n"
            . "- language is only for type=\"code\".\n"
            . "- To change an artifact, write it again in full with the same id. Never send only the changed part.\n"
            . "- One artifact per thing. Keep the text around it short, the user can see the result in the preview.\n"
            . "- Short snippets and examples still go in normal Markdown code blocks.";
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
    // that back into the tag. returns [blocks, saves]; the saves get written once the message has an id
    public function prepare(int $chatId, array $blocks){
        global $db;

        $saves = [];
        $next = [];

        foreach($blocks as $i => $block){
            if($block["type"] !== "text" || stripos($block["text"], "<artifact") === false){
                continue;
            }

            $blocks[$i]["text"] = preg_replace_callback(self::PATTERN, function($match) use ($chatId, &$saves, &$next, $db){
                $attrs = self::attributes($match[1]);
                $content = self::cleanContent($match[2]);
                $title = trim($attrs["title"] ?? "") ?: "Untitled";
                $title = mb_substr(preg_replace('/\s+/', ' ', $title), 0, 120);
                $ref = self::slug(($attrs["id"] ?? "") !== "" ? $attrs["id"] : $title);
                $type = self::type($attrs, $content);
                $language = $type === "code" ? mb_substr(strtolower(trim($attrs["language"] ?? "")), 0, 30) : null;

                if(!isset($next[$ref])){
                    $existing = $db->table("ai_artifacts")->where("chatid", $chatId)->where("ref", $ref)->first();
                    $next[$ref] = $existing ? (int) $existing->latest + 1 : 1;
                }
                $version = $next[$ref]++;

                if(strlen($content) <= self::MAX_BYTES){
                    $saves[] = compact("ref", "title", "type", "language", "content", "version");
                }

                $tag = '<artifact id="' . $ref . '" type="' . $type . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
                    . ($language ? ' language="' . htmlspecialchars($language, ENT_QUOTES) . '"' : "")
                    . ' version="' . $version . '">';

                return $tag . "\n" . $content . "\n</artifact>";
            }, $block["text"]);
        }

        return [$blocks, $saves];
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

            $saved[] = ["ref"=>$item["ref"], "version"=>$item["version"], "title"=>$item["title"], "type"=>$item["type"]];
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
