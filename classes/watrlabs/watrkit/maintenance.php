<?php

namespace watrlabs\watrkit;

class maintenance {

    protected array $bypassKeys = [];
    protected $maintenancePage = null;

    // this adds to the bypass array for any useragents that can be allowed to bypass
    // more functionality will be added in the future (TODO)
    public function addBypass(array $bypassKeys) {
        
        if(isset($bypassKeys["bypassFeature"]) && isset($bypassKeys["bypassKey"])){
            if($this->isValidBypassFeature($bypassKeys["bypassFeature"])){
                $this->bypassKeys[] = $bypassKeys;
            } else {
                throw new \ErrorException("Maintenance feature unsupported or invalid");
            }
        } else {
            throw new \ErrorException("Invalid maintenance bypass added!");
        }
    }

    // checks if its a valid bypass feature as to not break 
    private function isValidBypassFeature($feature){
        $isOkay = false;

        // TODO: Expand this
        $okFeatures = [
            "userAgent",
            "Cookie"
        ];

        return in_array($feature, $okFeatures);

    }

    public function setMaintenancePage($maint){
        $this->maintenancePage = $maint;
    }

    public function showMaintenance() {

        call_user_func($this->maintenancePage);
        die();

    }

    // does calculations to decide if the user should be show maintenance or nah
    // cookie is ALWAYS allowed to bypass maintenance
    public function shouldShowMaintenance(){

        foreach($this->bypassKeys as $bypassKey){

            switch($bypassKey["bypassFeature"]) {
                case "userAgent":
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    if(str_contains($userAgent, $bypassKey["bypassKey"])){
                        return false;
                    }
                    break;
                case "Cookie":
                    // right now only checks for the name, should add support for name and value
                    return isset($_COOKIE[$bypassKey["bypassKey"]]);
                default:
                    throw new \ErrorException("Invalid bypass feature encountered");
                    break;
                }
        }
        
        if(isset($_COOKIE[$_ENV["BypassCookieName"]])){
            if($_COOKIE[$_ENV["BypassCookieName"]] == $_ENV["BypassCookieValue"]){
                return false;
            }
        }

        return true;
    }

    // these bottom two are self explanitory
    static function isSoftMaintenanceEnabled() {
        return filter_var($_ENV["MAINTENANCE"], FILTER_VALIDATE_BOOLEAN);
    }

     static function isHardMaintenanceEnabled() {
        return filter_var($_ENV["HARD_MAINTENANCE"], FILTER_VALIDATE_BOOLEAN);

    }


}