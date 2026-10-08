<?php

use PHPUnit\Framework\TestCase;
use watrlabs\watrkit\csrf;
use watrlabs\ai\tools;

final class RequestGuardTest extends TestCase
{
    public function testOnlyOurOwnPagesCanPost(): void
    {
        $host = ["HTTP_HOST"=>"games.watr.lol"];

        $this->assertTrue(csrf::sameOrigin("POST", $host + ["HTTP_SEC_FETCH_SITE"=>"same-origin"]));
        $this->assertFalse(csrf::sameOrigin("POST", $host + ["HTTP_SEC_FETCH_SITE"=>"same-site"]), "a sibling subdomain");
        $this->assertFalse(csrf::sameOrigin("POST", $host + ["HTTP_SEC_FETCH_SITE"=>"cross-site", "HTTP_ORIGIN"=>"null"]), "a sandboxed artifact");

        // older browsers only send Origin
        $this->assertTrue(csrf::sameOrigin("POST", $host + ["HTTP_ORIGIN"=>"https://games.watr.lol"]));
        $this->assertFalse(csrf::sameOrigin("POST", $host + ["HTTP_ORIGIN"=>"https://evil.example"]));
        $this->assertFalse(csrf::sameOrigin("POST", $host + ["HTTP_ORIGIN"=>"null"]));
        $this->assertTrue(csrf::sameOrigin("POST", ["HTTP_HOST"=>"localhost:8000", "HTTP_ORIGIN"=>"http://localhost:8000"]));

        // not a browser, and reading is always fine
        $this->assertTrue(csrf::sameOrigin("POST", $host));
        $this->assertTrue(csrf::sameOrigin("GET", $host + ["HTTP_SEC_FETCH_SITE"=>"cross-site"]));
    }

    public function testTheFetchToolStaysOffTheServersNetwork(): void
    {
        foreach(["127.0.0.1", "192.168.1.141", "::1", "::ffff:127.0.0.1", "::ffff:192.168.1.141", "64:ff9b::7f00:1", "100.100.100.100", "169.254.169.254", "fd00::1", "2002:7f00:1::"] as $ip){
            $this->assertFalse(tools::isPublicIp($ip), $ip);
        }
        foreach(["1.1.1.1", "2606:4700:4700::1111"] as $ip){
            $this->assertTrue(tools::isPublicIp($ip), $ip);
        }
    }
}
