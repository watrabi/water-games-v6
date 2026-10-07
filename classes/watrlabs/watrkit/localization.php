<?php

namespace watrlabs\watrkit;

class localization
{
    private static $translations = [];
    private static $locale = 'en_US';

    public static function init(string $locale)
    {
        self::$locale = $locale;

        $file = __DIR__ . "/../../../storage/translations/{$locale}.json";

        // no translation file yet means every key falls back to [key]
        self::$translations = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    }

    private static function resolve(string $key)
    {
        $parts = explode('.', $key);
        $value = self::$translations;

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return "[$key]";
            }
            $value = $value[$part];
        }

        return $value;
    }

    public static function __callStatic($name, $arguments)
    {
        $key = str_replace('_', '.', strtolower($name));

        return self::resolve($key);
    }

    public static function get(string $key)
    {
        return self::resolve($key);
    }
}
