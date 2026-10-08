<?php

use PHPUnit\Framework\TestCase;
use watrlabs\authentication\security;

final class RequestIpTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER["HTTP_CF_CONNECTING_IP"], $_SERVER["REMOTE_ADDR"]);
    }

    public function testIgnoresTheCloudflareHeaderFromAnyoneElse(): void
    {
        $_SERVER["REMOTE_ADDR"] = "203.0.113.9";
        $_SERVER["HTTP_CF_CONNECTING_IP"] = "1.2.3.4";

        $this->assertSame("203.0.113.9", security::getRequestIp());
    }

    public function testTrustsItFromCloudflare(): void
    {
        $_SERVER["REMOTE_ADDR"] = "172.70.1.2"; // 172.64.0.0/13
        $_SERVER["HTTP_CF_CONNECTING_IP"] = "198.51.100.7";
        $this->assertSame("198.51.100.7", security::getRequestIp());

        $_SERVER["REMOTE_ADDR"] = "2606:4700:10::6816:1";
        $_SERVER["HTTP_CF_CONNECTING_IP"] = "2001:db8::1";
        $this->assertSame("2001:db8::1", security::getRequestIp());
    }

    public function testJunkInTheHeaderIsIgnored(): void
    {
        $_SERVER["REMOTE_ADDR"] = "172.70.1.2";
        $_SERVER["HTTP_CF_CONNECTING_IP"] = "not an ip";
        $this->assertSame("172.70.1.2", security::getRequestIp());
    }

    public function testRanges(): void
    {
        $this->assertTrue(security::inRange("104.16.0.1", "104.16.0.0/13"));
        $this->assertTrue(security::inRange("104.23.255.255", "104.16.0.0/13"));
        $this->assertFalse(security::inRange("104.24.0.0", "104.16.0.0/13"));
        $this->assertTrue(security::inRange("2a06:98c7::1", "2a06:98c0::/29"));
        $this->assertFalse(security::inRange("2a06:98c8::1", "2a06:98c0::/29"));
        $this->assertFalse(security::inRange("104.16.0.1", "2400:cb00::/32"), "v4 never matches a v6 range");
    }
}
