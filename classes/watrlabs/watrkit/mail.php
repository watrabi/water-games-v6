<?php

namespace watrlabs\watrkit;

use PHPMailer\PHPMailer\PHPMailer;

// sends email over SMTP. .env:
//   MAIL_HOST, MAIL_PORT (587), MAIL_SECURE (tls | ssl | none), MAIL_USER, MAIL_PASS, MAIL_FROM
// with no MAIL_HOST and APP_DEBUG=true, mail goes to storage/logs/mail.log instead so you can still test
class mail {

    static function configured(){
        return !empty($_ENV["MAIL_HOST"]);
    }

    // can people get mail from us at all (real smtp, or the debug log)
    static function available(){
        return self::configured() || ($_ENV["APP_DEBUG"] ?? "false") === "true";
    }

    static function send(string $to, string $subject, string $text): bool {
        if(!self::configured()){
            if(($_ENV["APP_DEBUG"] ?? "false") !== "true"){
                return false;
            }
            $dir = __DIR__ . "/../../../storage/logs";
            if(!is_dir($dir)){
                mkdir($dir, 0750, true);
            }
            file_put_contents("$dir/mail.log", "---- " . date("c") . "\nTo: $to\nSubject: $subject\n\n$text\n\n", FILE_APPEND);
            return true;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $_ENV["MAIL_HOST"];
            $mail->Port = (int) ($_ENV["MAIL_PORT"] ?? 587);
            $secure = strtolower($_ENV["MAIL_SECURE"] ?? "tls");
            if($secure === "ssl"){
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif($secure === "tls"){
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = "";
                $mail->SMTPAutoTLS = false;
            }
            if(!empty($_ENV["MAIL_USER"])){
                $mail->SMTPAuth = true;
                $mail->Username = $_ENV["MAIL_USER"];
                $mail->Password = $_ENV["MAIL_PASS"] ?? "";
            }
            $mail->Timeout = 10;
            $mail->CharSet = "UTF-8";
            $mail->setFrom($_ENV["MAIL_FROM"] ?? $_ENV["MAIL_USER"], $_ENV["APP_NAME"] ?? "Water Games");
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $text;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log("mail to $to failed: " . $mail->ErrorInfo);
            return false;
        }
    }

    // https://example.com, from the request (works behind cloudflare / a proxy)
    static function siteUrl(){
        $https = !empty($_SERVER["HTTPS"]) || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https";
        $host = $_ENV["APP_DOMAIN"] ?? ($_SERVER["HTTP_HOST"] ?? "localhost");
        return ($https ? "https" : "http") . "://" . $host;
    }
}
