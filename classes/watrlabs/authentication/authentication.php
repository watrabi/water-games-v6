<?php

namespace watrlabs\authentication;

class authentication {

    private $db = null;
    private $sessionName = null;
    private $currentSession = null;

    // inits the class
    function __construct() {
        global $db;

        $this->db = $db;
        $this->sessionName = $_ENV["COOKIE_NAME"];

        if(isset($_COOKIE[$this->sessionName])){
            $this->currentSession = $this->db->table("sessions")->where("session", $_COOKIE[$this->sessionName])->first();
        }
    }

    // checks if the user is currently authenticated and has a session
    public function hasAccount() {
        return (bool) $this->currentSession;
    }

}
