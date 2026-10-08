<?php

namespace watrlabs\watrkit;

// the site's own address, for links that leave the page (share previews, the sitemap, emails).
// SITE_URL in .env wins; otherwise https://APP_DOMAIN, or plain http for localhost
class site {

    static function url(): string {
        if(!empty($_ENV["SITE_URL"])){
            return rtrim($_ENV["SITE_URL"], "/");
        }

        $host = $_ENV["APP_DOMAIN"] ?? ($_SERVER["HTTP_HOST"] ?? "localhost");
        $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);

        // behind cloudflare the request reaching php is plain http, so only trust "http" for local dev
        return ($local ? "http" : "https") . "://" . $host;
    }

    // "/games/3" -> "https://games.watr.lol/games/3"
    static function absolute(?string $path): ?string {
        if($path === null || $path === ""){
            return null;
        }
        if(preg_match('#^https?://#i', $path)){
            return $path;
        }
        return self::url() . "/" . ltrim($path, "/");
    }
}
