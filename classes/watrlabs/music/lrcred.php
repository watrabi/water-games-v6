<?php

namespace watrlabs\music;

use Symfony\Component\Yaml\Yaml;

// lyrics from lrc.red, which has word by word timing for most songs (in Apple Music's TTML format). tried before
// lrclib. a match needs the same title as lyrics.php does, plus the artist or (with no artist) a length within 3
// seconds, and never more than 8 seconds off, so a live or extended cut doesn't get studio timing.
//
// the TTML is turned into a Lyricsfile (lyricsfile.php), so everything after this treats it like lrclib's
class lrcred {

    const BASE = "https://lrc.red";
    const MAX_BYTES = 1024 * 1024;

    // ["lyrics"=>LRC text, "synced"=>true, "file"=>Lyricsfile yaml, "source"=>"lrc.red"], or null if it has nothing
    // good. throws RuntimeException when lrc.red can't be reached
    public static function find(string $title, ?string $artist, ?int $duration){
        $title = trim($title);
        $artist = trim((string) $artist);

        // "Artist - Title" with no artist filled in, like lyrics.php
        if($artist === "" && preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title, $parts)){
            [$artist, $title] = [$parts[1], $parts[2]];
        }
        if($title === ""){
            return null;
        }

        $clean = lyrics::cleanTitle($title);
        $hits = [];
        foreach([
            "/match.json?" . http_build_query(["title"=>$clean] + ($artist !== "" ? ["artist"=>$artist] : [])),
            "/search.json?" . http_build_query(["q"=>trim($clean . " " . $artist)]),
        ] as $path){
            $body = self::get($path);
            $data = $body !== null ? json_decode($body, true) : null;
            foreach(is_array($data["hits"] ?? null) ? array_slice($data["hits"], 0, 20) : [] as $hit){
                if(is_array($hit) && is_string($hit["isrc"] ?? null)){
                    $hits[$hit["isrc"]] = $hit;
                }
            }
        }

        $isrc = self::pick(array_values($hits), $clean, $artist, $duration);
        if(!$isrc){
            return null;
        }

        $ttml = self::get("/s/" . rawurlencode($isrc) . ".ttml");
        $lines = $ttml !== null ? self::parseTtml($ttml) : null;
        if(!$lines){
            return null;
        }

        return [
            "lyrics"=>self::toLrc($lines),
            "synced"=>true,
            "file"=>self::toLyricsfile($lines, $title, $artist, $duration),
            "source"=>"lrc.red",
        ];
    }

    // the closest length among hits with the right title (and artist, when we know it)
    private static function pick(array $hits, string $title, string $artist, ?int $duration): ?string {
        $wantTitle = lyrics::norm($title);
        $wantArtists = lyrics::artistWords($artist);

        $best = null;
        $bestDiff = INF;
        foreach($hits as $hit){
            if(lyrics::norm(lyrics::cleanTitle((string) ($hit["title"] ?? ""))) !== $wantTitle){
                continue;
            }

            if($wantArtists){
                $theirs = lyrics::norm((string) ($hit["artist"] ?? ""));
                $match = false;
                foreach($wantArtists as $word){
                    if(str_contains(" $theirs ", " $word ")){
                        $match = true;
                        break;
                    }
                }
                if(!$match){
                    continue;
                }
            }

            $diff = ($duration && !empty($hit["duration"])) ? abs((float) $hit["duration"] - $duration) : null;
            if($diff !== null && $diff > 8){
                continue;
            }
            if(!$wantArtists && ($diff === null || $diff > 3)){
                continue;
            }

            $diff = $diff ?? 4;
            if($diff < $bestDiff){
                $bestDiff = $diff;
                $best = $hit["isrc"];
            }
        }

        return $best;
    }

    // the body, null for a 404, throws when the site is down
    private static function get(string $path): ?string {
        for($attempt = 0; $attempt < 2; $attempt++){
            $ch = curl_init(self::BASE . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>4,
                CURLOPT_TIMEOUT=>8,
                CURLOPT_USERAGENT=>"WaterGames/1.0 (" . ($_ENV["APP_DOMAIN"] ?? "watrgames") . ")",
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($body !== false && $status !== 0 && $status !== 429 && $status < 500){
                return $status === 200 && strlen($body) <= self::MAX_BYTES ? $body : null;
            }
            usleep(500000);
        }

        throw new \RuntimeException("Couldn't reach lrc.red.");
    }

    // "27.395", "2:21.720", "1:02:03.5" or "12.5s" -> milliseconds
    static function clock(?string $value): ?int {
        $value = trim((string) $value);
        if(preg_match('/^(\d+(?:\.\d+)?)s?$/', $value, $m)){
            return (int) round((float) $m[1] * 1000);
        }
        if(preg_match('/^(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $value, $m)){
            return (int) round(((int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3]) * 1000);
        }
        return null;
    }

    // <p> per line, <span begin end> per word (syllables split a word without a space between them). background
    // vocals are a <span ttm:role="x-bg"> holding their own word spans. returns the same line shape lyricsfile::parse
    // does, or null when there's nothing usable
    static function parseTtml(string $xml): ?array {
        $doc = new \DOMDocument();
        // no network, no entities: it's just markup with times in it
        if(!@$doc->loadXML($xml, LIBXML_NONET)){
            return null;
        }

        $lines = [];
        foreach($doc->getElementsByTagName("p") as $p){
            $start = self::clock($p->getAttribute("begin"));
            $end = self::clock($p->getAttribute("end"));
            if($start === null){
                continue;
            }

            $words = [];
            self::collectWords($p, $words);
            $text = $words ? implode("", array_column($words, "text")) : $p->textContent;
            $text = trim(preg_replace('/\s+/u', " ", $text));

            if($words){
                // the spaces are their own text nodes, drop the one dangling off the end
                $last = count($words) - 1;
                $words[$last]["text"] = rtrim($words[$last]["text"]);
            }

            $lines[] = [
                "text"=>$text,
                "start"=>$start,
                "end"=>$end !== null && $end >= $start ? $end : null,
                "words"=>$words,
            ];
        }

        usort($lines, fn($a, $b) => $a["start"] <=> $b["start"]);
        return count($lines) >= 2 ? $lines : null;
    }

    // walks a line in order: timed spans become words, the spaces between them stick to the word before
    private static function collectWords(\DOMNode $node, array &$words){
        foreach($node->childNodes as $child){
            if($child instanceof \DOMText){
                if($words && trim($child->data) === "" && $child->data !== ""){
                    $words[count($words) - 1]["text"] .= " ";
                } elseif($words && trim($child->data) !== ""){
                    $words[count($words) - 1]["text"] .= $child->data;
                }
                continue;
            }
            if(!$child instanceof \DOMElement){
                continue;
            }

            $start = self::clock($child->getAttribute("begin"));
            $hasTimedChildren = $child->getElementsByTagName("span")->length > 0;

            if($start !== null && !$hasTimedChildren){
                // background vocals start a new phrase, keep a space before them
                if($words && !str_ends_with($words[count($words) - 1]["text"], " ") && $child->parentNode->nodeName === "span"
                    && $child === self::firstSpan($child->parentNode)){
                    $words[count($words) - 1]["text"] .= " ";
                }
                $end = self::clock($child->getAttribute("end"));
                $words[] = ["text"=>$child->textContent, "start"=>$start, "end"=>$end !== null && $end >= $start ? $end : null];
            } else {
                self::collectWords($child, $words);
            }
        }
    }

    private static function firstSpan(\DOMNode $node){
        foreach($node->childNodes as $child){
            if($child instanceof \DOMElement){
                return $child;
            }
        }
        return null;
    }

    // the plain LRC version, kept as the fallback the player already understands
    static function toLrc(array $lines): string {
        return implode("\n", array_map(function($line){
            $ms = $line["start"];
            return sprintf("[%02d:%02d.%02d] %s", intdiv($ms, 60000), intdiv($ms % 60000, 1000), intdiv($ms % 1000, 10), $line["text"]);
        }, $lines));
    }

    static function toLyricsfile(array $lines, string $title, string $artist, ?int $duration): string {
        $doc = [
            "version"=>"1.0",
            "metadata"=>array_filter([
                "title"=>$title,
                "artist"=>$artist !== "" ? $artist : "Unknown",
                "duration_ms"=>$duration ? $duration * 1000 : null,
            ], fn($v) => $v !== null),
            "lines"=>array_map(fn($line) => array_filter([
                "text"=>$line["text"],
                "start_ms"=>$line["start"],
                "end_ms"=>$line["end"],
                "words"=>array_map(fn($w) => array_filter(["text"=>$w["text"], "start_ms"=>$w["start"], "end_ms"=>$w["end"]], fn($v) => $v !== null), $line["words"]),
            ], fn($v) => $v !== null && $v !== []), $lines),
        ];

        return Yaml::dump($doc, 4, 2);
    }
}
