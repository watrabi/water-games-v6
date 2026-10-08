<?php

namespace watrlabs\social;

use watrlabs\users\moderation;

// a short line next to your name ("grinding slope", "brb dinner"). friends see it in the chat tray,
// everyone sees it on your profile. it can clear itself after a while
class status {

    const MAX_LENGTH = 80;

    // what the picker offers, in seconds. 0 = until you clear it
    const DURATIONS = [3600, 4 * 3600, 86400, 0];

    // the text if it's still set, otherwise null
    static function current($user): ?string {
        if(empty($user->status_text)){
            return null;
        }
        if(!empty($user->status_until) && (int) $user->status_until <= time()){
            return null;
        }
        return $user->status_text;
    }

    // sets it (or clears it with ""), tells friends' trays, returns what's showing now
    static function set($user, string $text, int $duration = 0): ?string {
        global $db;

        $text = trim(preg_replace('/\s+/u', " ", $text));

        if($text !== ""){
            moderation::requireUnmuted($user);
            if(mb_strlen($text) > self::MAX_LENGTH){
                throw new \InvalidArgumentException("Keep it to " . self::MAX_LENGTH . " characters.");
            }
            if(!in_array($duration, self::DURATIONS, true)){
                $duration = 0;
            }
            $text = chat::filter($text);
        }

        $values = $text === ""
            ? ["status_text"=>null, "status_until"=>null]
            : ["status_text"=>$text, "status_until"=>$duration ? time() + $duration : null];
        $db->table("users")->where("id", $user->id)->update($values);

        realtime::publish(array_merge([(int) $user->id], (new friends())->ids((int) $user->id)), ["type"=>"presence"]);

        return $text === "" ? null : $text;
    }
}
