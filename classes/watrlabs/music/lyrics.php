<?php

namespace watrlabs\music;

// finds lyrics for a track on lrclib.net (free, no key). prefers time synced (LRC) lyrics so the
// player can follow along, falls back to plain text. a match needs the title to line up plus either
// the artist or the length, so a song called "Hello" doesn't get whichever "Hello" comes back first
class lyrics {

    const API = "https://lrclib.net/api/search";

    // how long to wait before asking again about a track that had nothing
    const RETRY_AFTER = 7 * 86400;

    // bits people tack onto titles that the real song name doesn't have
    const TITLE_NOISE = '/[\(\[][^\)\]]*(official|video|audio|lyric|visuali[sz]er|hd|hq|4k|remaster|explicit|clean|mv|m\/v)[^\)\]]*[\)\]]/i';

    // returns ["lyrics"=>string, "synced"=>bool] or null if nothing good turned up.
    // throws RuntimeException when lrclib can't be reached, so the caller knows to try again later
    public static function find(string $title, ?string $artist, ?int $duration){
        $title = trim($title);
        $artist = trim((string) $artist);

        // "Artist - Title" with no artist filled in, a common way files are named
        if($artist === "" && preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title, $parts)){
            $guess = self::best(self::search(["track_name"=>self::cleanTitle($parts[2]), "artist_name"=>$parts[1]]), $parts[2], $parts[1], $duration);
            if($guess){
                return $guess;
            }
        }

        if($title === ""){
            return null;
        }

        $query = ["track_name"=>self::cleanTitle($title)];
        if($artist !== ""){
            $query["artist_name"] = $artist;
        }

        $best = self::best(self::search($query), $title, $artist, $duration);

        // lrclib's field search is strict about the artist, a looser text search catches "Artist, Other Artist"
        if(!$best && $artist !== ""){
            $best = self::best(self::search(["q"=>self::cleanTitle($title) . " " . $artist]), $title, $artist, $duration);
        }

        return $best;
    }

    private static function best(array $candidates, string $title, ?string $artist, ?int $duration){
        $wantTitle = self::norm(self::cleanTitle($title));
        $wantArtists = self::artistWords((string) $artist);

        $best = null;
        $bestScore = -INF;

        foreach($candidates as $candidate){
            if(!empty($candidate["instrumental"])){
                continue;
            }

            $synced = trim((string) ($candidate["syncedLyrics"] ?? ""));
            $plain = trim((string) ($candidate["plainLyrics"] ?? ""));
            $text = $synced !== "" ? $synced : $plain;

            // lrclib has some junk entries ("probe", one line placeholders), real songs have more than this
            if(count(array_filter(array_map("trim", explode("\n", $plain !== "" ? $plain : $synced)))) < 4){
                continue;
            }

            if(self::norm(self::cleanTitle((string) ($candidate["trackName"] ?? ""))) !== $wantTitle){
                continue;
            }

            $artistMatch = false;
            if($wantArtists){
                $theirs = self::norm((string) ($candidate["artistName"] ?? ""));
                foreach($wantArtists as $word){
                    if(str_contains(" $theirs ", " $word ")){
                        $artistMatch = true;
                        break;
                    }
                }
                if(!$artistMatch){
                    continue;
                }
            }

            $diff = ($duration && !empty($candidate["duration"])) ? abs($candidate["duration"] - $duration) : null;

            // a different cut of the song (live, extended) won't line up with the timestamps
            if($diff !== null && $diff > 8){
                continue;
            }

            // with no artist to go on, only trust a title match if the length agrees too
            if(!$wantArtists && ($diff === null || $diff > 3)){
                continue;
            }

            $score = ($synced !== "" ? 100 : 0) - ($diff ?? 4) * 3;
            if($score > $bestScore){
                $bestScore = $score;
                $best = ["lyrics"=>$text, "synced"=>$synced !== ""];
            }
        }

        return $best;
    }

    // one retry, lrclib drops the odd request
    private static function search(array $query){
        for($attempt = 0; $attempt < 2; $attempt++){
            $ch = curl_init(self::API . "?" . http_build_query($query));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>4,
                CURLOPT_TIMEOUT=>8,
                // lrclib asks clients to say who they are
                CURLOPT_USERAGENT=>"WaterGames/1.0 (" . ($_ENV["APP_DOMAIN"] ?? "watrgames") . ")",
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if($body !== false && $status !== 0 && $status !== 429 && $status < 500){
                $results = $status === 200 ? json_decode($body, true) : null;
                return is_array($results) ? array_slice($results, 0, 40) : [];
            }

            usleep(500000);
        }

        throw new \RuntimeException("Couldn't reach the lyrics service.");
    }

    // "Song (Official Video) [HD] ft. Someone" -> "Song"
    public static function cleanTitle(string $title){
        $title = preg_replace(self::TITLE_NOISE, "", $title);
        $title = preg_replace('/\s+[\(\[]?(feat\.?|ft\.?|featuring)\s.*$/iu', "", $title);
        $title = preg_replace('/\.(mp3|ogg|wav|m4a|flac|webm|opus)$/i', "", $title);
        return trim($title);
    }

    // lowercase, no accents or punctuation, so "Don't Stop Me Now" == "dont stop me now"
    private static function norm(string $text){
        $text = mb_strtolower($text);
        if(function_exists("iconv")){
            $ascii = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $text);
            if($ascii !== false && trim($ascii) !== ""){
                $text = $ascii;
            }
        }
        $text = str_replace(["'", "’", "`"], "", $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', " ", $text);
        $text = preg_replace('/^the\s+/', "", trim($text));
        return trim($text);
    }

    // each artist named ("A & B feat. C" -> ["a", "b", "c"])
    private static function artistWords(string $artist){
        $names = preg_split('/\s*(?:,|&|\+|\/|\bx\b|\band\b|\bfeat\.?|\bft\.?|\bfeaturing\b|\bwith\b)\s*/iu', $artist);
        return array_values(array_filter(array_map([self::class, "norm"], $names)));
    }
}
