<?php

namespace watrlabs\authentication;

// two-factor sign in with an authenticator app (RFC 6238: 6 digits, 30 seconds, SHA-1, which is what
// every app expects). the secret is stored encrypted with a random iv each time, and the last step that
// was used is remembered so a code can't be replayed
class totp {

    const DIGITS = 6;
    const PERIOD = 30;
    const WINDOW = 1; // accept the code before and after too, for clocks that are a little off
    const RECOVERY_CODES = 8;

    private const ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

    static function newSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    static function base32Encode(string $bytes): string {
        $bits = "";
        foreach(str_split($bytes) as $char){
            $bits .= str_pad(decbin(ord($char)), 8, "0", STR_PAD_LEFT);
        }

        $out = "";
        foreach(str_split($bits, 5) as $chunk){
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, "0", STR_PAD_RIGHT))];
        }
        return $out;
    }

    static function base32Decode(string $text): string {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = "";
        foreach(str_split($text) as $char){
            $value = strpos(self::ALPHABET, $char);
            if($value === false){
                return "";
            }
            $bits .= str_pad(decbin($value), 5, "0", STR_PAD_LEFT);
        }

        $out = "";
        foreach(str_split($bits, 8) as $byte){
            if(strlen($byte) === 8){
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    // the code for one 30 second step
    static function code(string $secret, int $step): string {
        $key = self::base32Decode($secret);
        $hash = hash_hmac("sha1", pack("N*", 0) . pack("N*", $step), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, "0", STR_PAD_LEFT);
    }

    // returns the step the code matched, or null. $lastStep blocks reusing a code (or an older one)
    static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int {
        $code = preg_replace('/\s+/', '', $code);
        if(!preg_match('/^\d{' . self::DIGITS . '}$/', $code)){
            return null;
        }

        $current = intdiv($now ?? time(), self::PERIOD);
        for($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++){
            if($lastStep !== null && $step <= $lastStep){
                continue;
            }
            if(hash_equals(self::code($secret, $step), $code)){
                return $step;
            }
        }
        return null;
    }

    static function uri(string $secret, string $username): string {
        $issuer = $_ENV["APP_NAME"] ?? "Water Games";
        return "otpauth://totp/" . rawurlencode($issuer . ":" . $username) . "?" . http_build_query([
            "secret"=>$secret, "issuer"=>$issuer, "digits"=>self::DIGITS, "period"=>self::PERIOD,
        ], "", "&", PHP_QUERY_RFC3986);
    }

    // ---------- storage ----------

    private static function key(): string {
        return hash("sha256", ($_ENV["encryptionKey"] ?? "") . "|totp", true);
    }

    static function seal(string $secret): string {
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($secret, "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    static function open(?string $sealed): ?string {
        $raw = $sealed ? base64_decode($sealed, true) : false;
        if(!$raw || strlen($raw) < 29){
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    // ---------- recovery codes ----------

    // "abcd-efgh" style, shown once. returns [plain codes, json of hashes to store]
    static function newRecoveryCodes(): array {
        $plain = [];
        $alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
        for($i = 0; $i < self::RECOVERY_CODES; $i++){
            $code = "";
            for($j = 0; $j < 8; $j++){
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($code, 0, 4) . "-" . substr($code, 4);
        }

        return [$plain, json_encode(array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $plain))];
    }

    // uses up a recovery code. returns the remaining hashes as json, or null if it didn't match
    static function useRecoveryCode(?string $stored, string $code): ?string {
        $hashes = $stored ? (json_decode($stored, true) ?: []) : [];
        $code = strtolower(trim($code));
        if(strlen($code) === 8 && !str_contains($code, "-")){
            $code = substr($code, 0, 4) . "-" . substr($code, 4);
        }

        foreach($hashes as $i => $hash){
            if(password_verify($code, $hash)){
                unset($hashes[$i]);
                return json_encode(array_values($hashes));
            }
        }
        return null;
    }

    // ---------- signing in ----------

    // after a right password: a short lived token the browser sends back with the code
    static function challenge(int $userId): string {
        global $db;

        $db->table("login_challenges")->where("created", "<", time() - 600)->delete();

        $token = bin2hex(random_bytes(24));
        $db->table("login_challenges")->insert(["userid"=>$userId, "token_hash"=>hash("sha256", $token), "created"=>time(), "attempts"=>0]);
        return $token;
    }

    // checks a code (or recovery code) against a challenge. returns the user id, or throws with a message
    static function answer(string $token, string $code): int {
        global $db;

        $challenge = $db->table("login_challenges")->where("token_hash", hash("sha256", $token))->first();
        if(!$challenge || $challenge->created < time() - 600){
            throw new \InvalidArgumentException("That took too long. Sign in again.");
        }
        if($challenge->attempts >= 5){
            $db->table("login_challenges")->where("id", $challenge->id)->delete();
            throw new \InvalidArgumentException("Too many wrong codes. Sign in again.");
        }

        $db->table("login_challenges")->where("id", $challenge->id)->update(["attempts"=>$challenge->attempts + 1]);

        $user = $db->table("users")->where("id", $challenge->userid)->first();
        if(!$user || empty($user->totp_enabled) || \watrlabs\users\moderation::isBanned($user)){
            throw new \InvalidArgumentException("Sign in again.");
        }

        $secret = self::open($user->totp_secret);
        $step = $secret ? self::verify($secret, $code, $user->totp_last_step !== null ? (int) $user->totp_last_step : null) : null;

        if($step !== null){
            $db->table("users")->where("id", $user->id)->update(["totp_last_step"=>$step]);
        } else {
            $remaining = self::useRecoveryCode($user->recovery_codes, $code);
            if($remaining === null){
                throw new \InvalidArgumentException("That code isn't right. Check your app and try again.");
            }
            $db->table("users")->where("id", $user->id)->update(["recovery_codes"=>$remaining]);
        }

        $db->table("login_challenges")->where("id", $challenge->id)->delete();
        return (int) $user->id;
    }
}
