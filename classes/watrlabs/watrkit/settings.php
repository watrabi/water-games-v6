<?php

namespace watrlabs\watrkit;

// site settings the admin panel can change, stored in the `settings` table
class settings {

    private static ?array $cache = null;

    private static function load(){
        if(self::$cache !== null){
            return self::$cache;
        }

        global $db;
        self::$cache = [];

        try {
            foreach($db->table("settings")->get() as $row){
                self::$cache[$row->name] = $row->value;
            }
        } catch (\Throwable $e) {
            // table isn't there until migrations run, everything falls back to defaults
        }

        return self::$cache;
    }

    static function get(string $name, $default = null){
        $all = self::load();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    static function bool(string $name, bool $default = false){
        $value = self::get($name);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    static function set(string $name, $value){
        global $db;

        $value = is_bool($value) ? ($value ? "1" : "0") : (string) $value;

        if($db->table("settings")->where("name", $name)->first()){
            $db->table("settings")->where("name", $name)->update(["value"=>$value]);
        } else {
            $db->table("settings")->insert(["name"=>$name, "value"=>$value]);
        }

        self::load();
        self::$cache[$name] = $value;
    }
}
