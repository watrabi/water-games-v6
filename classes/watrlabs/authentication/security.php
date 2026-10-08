<?php

namespace watrlabs\authentication;

use watrlabs\encryption;

class security {

    // get the last login a user used
    public function getLastLoginIp($username, $encrypted = true){
        global $db;

        $user = $db->table("users")->where("username", $username)->first();

        if(!$user){
            return null;
        }

        if($encrypted){
            return $user->LastIP;
        }

        $encryption = new encryption();
        return $encryption->decrypt($user->LastIP);

    }

    // IPs get stored encrypted, this is what goes in the db
    public function encryptIp(string $ip){
        $encryption = new encryption();
        return $encryption->encrypt($ip);
    }

    // function to detect alts based on a single ip
    public function detectAlts(string $ip) {
        global $db;

        $encryptedIp = $this->encryptIp($ip);

        $alts = $db->table("users")
            ->select(["id", "username"])
            ->where("RegisterIP", $encryptedIp)
            ->orWhere("LastIP", $encryptedIp)
            ->get();

        // dedupe by id since someone can match both columns
        $unique = [];
        foreach($alts as $alt){
            $unique[$alt->id] = $alt;
        }

        return array_values($unique);

    }

    // returns true if more than 5 alts are found
    public function hasTooManyAlts($ip){
        $alts = $this->detectAlts($ip);

        return count($alts) > 5;

    }

    // Source - https://stackoverflow.com/a/55790
    // Posted by Tim Kennedy, modified by community. See post 'Timeline' for change history
    // Retrieved 2026-07-09, License - CC BY-SA 4.0

    // cloudflare's published ranges (https://www.cloudflare.com/ips/). only requests that really come from
    // cloudflare get to tell us the visitor's address, anyone else could put anything in that header.
    //
    // behind a cloudflare tunnel (games.watr.lol), cloudflared connects from 127.0.0.1, and nginx swaps in the
    // real address itself (set_real_ip_from 127.0.0.1 + real_ip_header CF-Connecting-IP), so REMOTE_ADDR is
    // already the visitor by the time php sees it. keep that nginx config if you move servers
    const CLOUDFLARE_RANGES = [
        "173.245.48.0/20", "103.21.244.0/22", "103.22.200.0/22", "103.31.4.0/22", "141.101.64.0/18",
        "108.162.192.0/18", "190.93.240.0/20", "188.114.96.0/20", "197.234.240.0/22", "198.41.128.0/17",
        "162.158.0.0/15", "104.16.0.0/13", "104.24.0.0/14", "172.64.0.0/13", "131.0.72.0/22",
        "2400:cb00::/32", "2606:4700::/32", "2803:f800::/32", "2405:b500::/32", "2405:8100::/32",
        "2a06:98c0::/29", "2c0f:f248::/32",
    ];

    static function getRequestIp(){
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        // php exposes request headers as HTTP_*, so CF-Connecting-IP lands here
        $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) && self::fromCloudflare($remote)) {
            return $forwarded;
        }

        return $remote;
    }

    static function fromCloudflare(string $ip){
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    // works for both ipv4 and ipv6
    static function inRange(string $ip, string $cidr){
        [$subnet, $bits] = explode("/", $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($ipBin, 0, $whole) !== substr($subnetBin, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (substr($ipBin, $whole, 1) & $mask) === (substr($subnetBin, $whole, 1) & $mask);
    }

    // captcha only runs when it's turned on AND both turnstile keys are filled in
    static function captchaActive(){
        return filter_var($_ENV["CONFIG_CaptchaEnabled"] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !empty($_ENV["TurnstileSiteKey"])
            && !empty($_ENV["PrivateTurnstileKey"]);
    }

    // checks a cloudflare turnstile token, skipped if captcha isn't active
    static function verifyCaptcha($token){
        if(!self::captchaActive()){
            return true;
        }

        if(!$token){
            return false;
        }

        $context = stream_context_create([
            "http"=>[
                "method"=>"POST",
                "header"=>"Content-Type: application/x-www-form-urlencoded",
                "content"=>http_build_query([
                    "secret"=>$_ENV["PrivateTurnstileKey"],
                    "response"=>$token,
                    "remoteip"=>self::getRequestIp(),
                ]),
                "timeout"=>5,
            ]
        ]);

        $response = @file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, $context);

        if($response === false){
            return false;
        }

        $result = json_decode($response, true);

        return !empty($result["success"]);
    }


}
