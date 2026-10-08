<?php

namespace watrlabs\watrkit;

use watrlabs\authentication\security;

// decides whether a play should count. each person (or, for guests, each IP address) counts once per game
// every 30 minutes and once per song every 10, no matter how often they refresh. it's checked in the
// database, so clearing cookies or opening a private window doesn't get around it
class playcounter {

    const WINDOWS = [
        "game"=>1800,
        "track"=>600,
    ];

    // "u12" for an account, "ip" + a keyed hash for guests (the address itself is never stored)
    static function viewer($user = null): string {
        if($user && !empty($user->id)){
            return "u" . (int) $user->id;
        }

        $ip = security::getRequestIp() ?: "unknown";
        return "ip" . substr(hash_hmac("sha256", $ip, ($_ENV["encryptionKey"] ?? "") . "|plays"), 0, 40);
    }

    // true the first time in the window (and records it), false for repeats. atomic, so two refreshes at
    // the same moment can't both count
    static function shouldCount(string $kind, int $itemId, string $viewer, ?int $now = null): bool {
        global $db;

        $window = self::WINDOWS[$kind] ?? 1800;
        $now = $now ?? time();

        // mysql reports 1 for an insert, 2 for an update that changed the row, 0 when nothing changed
        [$statement] = $db->statement(
            "INSERT INTO play_views (kind, item_id, viewer, last) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last = IF(last <= ?, VALUES(last), last)",
            [$kind, $itemId, $viewer, $now, $now - $window]
        );

        if(random_int(1, 200) === 1){
            $db->table("play_views")->where("last", "<", $now - max(self::WINDOWS))->delete();
        }

        return $statement->rowCount() > 0;
    }
}
