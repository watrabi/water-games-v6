<?php

namespace watrlabs\watrkit;

use \ErrorException;

class errors {

    function __construct(){

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            // deprecations (pixie has a bunch on php 8.2+) shouldn't take the whole site down,
            // returning false hands them to php's normal logging instead
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                return false;
            }

            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

            // error_get_last also has warnings/deprecations that got logged, only fatals matter here
            if ($error !== null && in_array($error["type"], $fatal, true)) {
                try {
                    $this->showFriendlyError($error["message"]);
                } catch (ErrorException $e){
                    $error = error_get_last();

                    header("content-type: text/plain; charset=utf-8");
                    if(isset($_ENV)){
                        if($_ENV["APP_DEBUG"] == "true"){
                            $this->displayTextError($_ENV["APP_NAME"] . " encountered an error.\n\n" . $error["message"]);
                        }
                    } else {
                        header("content-type: text/plain; charset=utf-8");
                        http_response_code(500);

                        if(isset($_ENV)){
                            if($_ENV["APP_NAME"]){
                                $this->displayTextError($_ENV["APP_NAME"] . " had an error processing your request.");
                            }
                        }

                        
                        $this->displayTextError("An error occured. Please check back later.");
                    }

                }
        }
        });

    }

    // this is for working on the site. i wanna do something fancy like laravel but I have no idea how they do it
    // just stops execution and shows the raw error
    static function displayTextError($e){
        // I'll probably do something better than this
        header("Content-type: text/plain");
        die($e);
    }

    // shows 500 page and if it can't just breaks it to the user we're prob down
    static function showFriendlyError($e){
        global $twig;
        
        //ob_clean();
        http_response_code(500);

        
        header("Content-type: text/html");
        echo $twig->render('statusCodes/500.twig');
        die();
    }
}