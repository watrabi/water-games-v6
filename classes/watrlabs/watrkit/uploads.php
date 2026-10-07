<?php

namespace watrlabs\watrkit;

// files the admin panel uploads (game icons, track covers, music), saved under public/uploads
class uploads {

    const IMAGES = ["image/jpeg"=>"jpg", "image/png"=>"png", "image/gif"=>"gif", "image/webp"=>"webp"];

    // what finfo calls each kind of audio file, and the extension it gets saved with
    const AUDIO = [
        "audio/mpeg"=>"mp3", "audio/mp3"=>"mp3",
        "audio/ogg"=>"ogg", "application/ogg"=>"ogg", "audio/opus"=>"opus",
        "audio/wav"=>"wav", "audio/x-wav"=>"wav", "audio/wave"=>"wav", "audio/vnd.wave"=>"wav",
        "audio/mp4"=>"m4a", "audio/x-m4a"=>"m4a", "video/mp4"=>"m4a",
        "audio/flac"=>"flac", "audio/x-flac"=>"flac",
        "audio/webm"=>"webm", "video/webm"=>"webm",
    ];

    // true if the form actually had a file in this field
    static function sent(string $field){
        return isset($_FILES[$field]) && ($_FILES[$field]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    // $kind: "icons" | "covers" for images, "music" for audio. returns the public path
    static function store(string $field, string $kind): string {
        $file = $_FILES[$field];

        if($file["error"] === UPLOAD_ERR_INI_SIZE || $file["error"] === UPLOAD_ERR_FORM_SIZE){
            throw new \InvalidArgumentException("That file is bigger than the server allows (upload_max_filesize in php.ini).");
        }

        if($file["error"] !== UPLOAD_ERR_OK){
            throw new \InvalidArgumentException("The upload didn't go through.");
        }

        if($kind === "music"){
            if($file["size"] > 100 * 1024 * 1024){
                throw new \InvalidArgumentException("Audio files have to be under 100MB.");
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
            $ext = self::AUDIO[$mime] ?? null;

            if(!$ext){
                throw new \InvalidArgumentException("That isn't an audio file this site can play (mp3, ogg, wav, m4a, flac or webm). It looked like $mime.");
            }
        } else {
            if($file["size"] > 5 * 1024 * 1024){
                throw new \InvalidArgumentException("Images have to be under 5MB.");
            }

            $info = self::imageInfo($file["tmp_name"]);
            $ext = $info ? (self::IMAGES[$info["mime"]] ?? null) : null;

            if(!$ext){
                throw new \InvalidArgumentException("Images have to be JPEG, PNG, GIF or WebP.");
            }
        }

        $dir = __DIR__ . "/../../../public/uploads/" . $kind;
        if(!is_dir($dir)){
            mkdir($dir, 0755, true);
        }

        $name = bin2hex(random_bytes(12)) . "." . $ext;

        if(!move_uploaded_file($file["tmp_name"], "$dir/$name")){
            throw new \RuntimeException("Couldn't save the file. Is public/uploads writable?");
        }

        return "/uploads/$kind/$name";
    }

    // getimagesize warns on files that aren't images, and the site's error handler turns warnings into
    // exceptions, so catch it and treat it as "not an image"
    static function imageInfo(string $path){
        try {
            return getimagesize($path) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // removes a file we uploaded earlier (anything outside /uploads/ is left alone)
    static function delete(?string $path){
        if(!$path || !preg_match('#^/uploads/(icons|covers|music)/[a-f0-9]+\.[a-z0-9]+$#', $path)){
            return;
        }

        $file = __DIR__ . "/../../../public" . $path;
        if(is_file($file)){
            unlink($file);
        }
    }
}
