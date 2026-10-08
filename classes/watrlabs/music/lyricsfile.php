<?php

namespace watrlabs\music;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// reads a Lyricsfile (https://github.com/tranxuanthang/lyricsfile, the YAML lrclib serves as "lyricsfile"): lines with
// start/end times in milliseconds, and sometimes each word's timing too. it's checked against the 1.0 draft and
// turned into the small shape the player wants. anything off (unknown version, bad times, too big) gives null, and
// the player falls back to the plain LRC lyrics
class lyricsfile {

    const MAX_BYTES = 512 * 1024;
    const MAX_LINES = 2000;
    const MAX_WORDS = 200; // per line

    // [
    //   "lines"=>[["text"=>, "start"=>ms, "end"=>?ms, "words"=>[["text"=>, "start"=>ms, "end"=>?ms], ...]], ...],
    //   "words"=>bool (any line has word timing), "plain"=>?string
    // ] or null
    static function parse(?string $yaml): ?array {
        if($yaml === null || trim($yaml) === "" || strlen($yaml) > self::MAX_BYTES){
            return null;
        }

        try {
            // safe loading: no php objects, no custom tags, and duplicate keys are an error (the default since symfony 4)
            $doc = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            return null;
        }

        if(!is_array($doc) || ($doc["version"] ?? null) !== "1.0" || !is_array($doc["metadata"] ?? null)){
            return null;
        }
        if(!empty($doc["metadata"]["instrumental"])){
            return null;
        }

        $lines = [];
        $hasWords = false;
        foreach(array_slice(is_array($doc["lines"] ?? null) ? $doc["lines"] : [], 0, self::MAX_LINES) as $raw){
            $line = self::timed($raw);
            if(!$line){
                return null; // one broken line means the file isn't trustworthy
            }

            $words = [];
            if(isset($raw["words"]) && is_array($raw["words"])){
                foreach(array_slice($raw["words"], 0, self::MAX_WORDS) as $rawWord){
                    $word = self::timed($rawWord);
                    if(!$word){
                        return null;
                    }
                    $words[] = $word;
                }
                usort($words, fn($a, $b) => $a["start"] <=> $b["start"]);
            }

            $line["words"] = $words;
            $hasWords = $hasWords || (bool) $words;
            $lines[] = $line;
        }

        usort($lines, fn($a, $b) => $a["start"] <=> $b["start"]);

        $plain = is_string($doc["plain"] ?? null) && trim($doc["plain"]) !== "" ? $doc["plain"] : null;
        if(!$lines && $plain === null){
            return null;
        }

        return ["lines"=>$lines, "words"=>$hasWords, "plain"=>$plain];
    }

    // {text, start_ms, end_ms?} with the spec's rules: text is a string, times are whole, not negative, end >= start
    private static function timed($raw): ?array {
        if(!is_array($raw) || !is_string($raw["text"] ?? null) || !is_int($raw["start_ms"] ?? null) || $raw["start_ms"] < 0){
            return null;
        }

        $end = $raw["end_ms"] ?? null;
        if($end !== null && (!is_int($end) || $end < $raw["start_ms"])){
            return null;
        }

        return ["text"=>$raw["text"], "start"=>$raw["start_ms"], "end"=>$end];
    }
}
