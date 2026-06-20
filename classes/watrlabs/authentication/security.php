<?php

namespace watrlabs\authentication;

use watrlabs\encryption;

class security {

    // function to detect alts based on a single username
    public function detectAlts(string $username) {
        global $db;

        $encryption = new encryption();

        $registerAlts = $db->table("users")->where("RegisterIP", $encryption->encrypt($ip))->get();
        $loginAlts = $db->table("users")->where("LastIP", $encryption->encrypt($ip))->get();

        $combinedAlts = array_merge($registerAlts, $loginAlts );
        $combinedAlts = array_unique($combinedAlts);

        return $combinedAlts;

    }

}