<?php

namespace watrlabs\watrkit;

// is everything the site leans on still working? used by the admin dashboard's System panel and by bin/cron.php,
// which sends an alert when a check starts or stops failing (ALERT_WEBHOOK and/or ALERT_EMAIL in .env)
class health {

    const BACKUP_MAX_AGE = 36 * 3600;

    private static function root(): string {
        return dirname(__DIR__, 3);
    }

    // name => ["ok"=>bool|null, "detail"=>string]. ok null = not set up, so nothing to judge
    static function checks(): array {
        $checks = [];

        // database
        try {
            global $db;
            $started = microtime(true);
            $db->query("SELECT 1")->get();
            $checks["Database"] = ["ok"=>true, "detail"=>"Answered in " . round((microtime(true) - $started) * 1000) . "ms"];
        } catch (\Throwable $e) {
            $checks["Database"] = ["ok"=>false, "detail"=>"Not answering: " . $e->getMessage()];
        }

        // realtime chat server
        if(\watrlabs\social\realtime::enabled()){
            $url = rtrim($_ENV["REALTIME_INTERNAL_URL"] ?? "http://127.0.0.1:3001", "/") . "/health";
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>1000, CURLOPT_TIMEOUT_MS=>2000]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = $body ? json_decode($body, true) : null;
            $checks["Realtime chat"] = $code === 200 && !empty($data["ok"])
                ? ["ok"=>true, "detail"=>(int) ($data["users"] ?? 0) . " people connected"]
                : ["ok"=>false, "detail"=>"Not answering at $url (chat falls back to polling)"];
        } else {
            $checks["Realtime chat"] = ["ok"=>null, "detail"=>"Not set up, chat uses polling"];
        }

        // disk
        $free = @disk_free_space(self::root());
        $total = @disk_total_space(self::root());
        if($free !== false && $total){
            $percent = $free / $total * 100;
            $checks["Disk space"] = [
                "ok"=>$free > 2 * 1024 ** 3 && $percent > 5,
                "detail"=>round($free / 1024 ** 3, 1) . " GB free (" . round($percent) . "%)",
            ];
        }

        // backups from bin/backup.sh
        $backups = glob(self::root() . "/storage/backups/*.sql.gz") ?: [];
        if(!$backups){
            $checks["Backups"] = ["ok"=>false, "detail"=>"No backups in storage/backups. Is the bin/backup.sh cron set up?"];
        } else {
            $newest = max(array_map("filemtime", $backups));
            $age = time() - $newest;
            $checks["Backups"] = [
                "ok"=>$age < self::BACKUP_MAX_AGE,
                "detail"=>"Newest is " . ($age < 3600 ? round($age / 60) . " minutes" : round($age / 3600) . " hours") . " old, " . count($backups) . " kept",
            ];
        }

        // places the site writes to
        $unwritable = [];
        foreach(["storage/cache", "storage/logs", "storage/private", "public/uploads"] as $dir){
            $path = self::root() . "/" . $dir;
            if(is_dir($path) && !is_writable($path)){
                $unwritable[] = $dir;
            }
        }
        $checks["File storage"] = $unwritable
            ? ["ok"=>false, "detail"=>"Can't write to " . implode(", ", $unwritable)]
            : ["ok"=>true, "detail"=>"Uploads and caches are writable"];

        $checks["Email"] = mail::configured()
            ? ["ok"=>true, "detail"=>"SMTP is set up (" . ($_ENV["MAIL_HOST"] ?? "") . ")"]
            : ["ok"=>null, "detail"=>"Not set up, so password resets and recap emails can't send"];

        return $checks;
    }

    // a webhook (Discord or Slack style) and/or an email. returns whether anything was sent
    static function alert(string $text): bool {
        $sent = false;

        $webhook = trim($_ENV["ALERT_WEBHOOK"] ?? "");
        if($webhook !== ""){
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_POST=>true,
                // discord reads "content", slack reads "text"
                CURLOPT_POSTFIELDS=>json_encode(["content"=>mb_substr($text, 0, 1900), "text"=>$text]),
                CURLOPT_HTTPHEADER=>["Content-Type: application/json"],
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>10,
            ]);
            curl_exec($ch);
            $sent = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
            curl_close($ch);
        }

        $email = trim($_ENV["ALERT_EMAIL"] ?? "");
        if($email !== "" && mail::configured()){
            $sent = mail::send($email, "[" . ($_ENV["APP_NAME"] ?? "Water Games") . "] " . strtok($text, "\n"), $text) || $sent;
        }

        return $sent;
    }

    static function alertsConfigured(): bool {
        return trim($_ENV["ALERT_WEBHOOK"] ?? "") !== "" || (trim($_ENV["ALERT_EMAIL"] ?? "") !== "" && mail::configured());
    }

    // compares with the last run (storage/cron/health.json) and alerts on what changed. returns the changes
    static function watch(): array {
        $file = self::root() . "/storage/cron/health.json";
        if(!is_dir(dirname($file))){
            mkdir(dirname($file), 0750, true);
        }

        $before = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $now = self::checks();

        $broke = [];
        $fixed = [];
        foreach($now as $name => $check){
            $was = $before[$name] ?? null;
            if($check["ok"] === false && $was !== false){
                $broke[] = "$name: " . $check["detail"];
            } elseif($check["ok"] === true && $was === false){
                $fixed[] = "$name: " . $check["detail"];
            }
        }

        $state = array_map(fn($c) => $c["ok"], $now);
        file_put_contents($file, json_encode($state));

        $site = site::url();
        if($broke){
            self::alert("Something's wrong on $site\n- " . implode("\n- ", $broke));
        }
        if($fixed){
            self::alert("Back to normal on $site\n- " . implode("\n- ", $fixed));
        }

        return ["broke"=>$broke, "fixed"=>$fixed];
    }
}
