<?php

use PHPUnit\Framework\TestCase;
use watrlabs\authentication\totp;

final class TotpTest extends TestCase
{
    // RFC 6238 appendix B, SHA-1, last 6 digits of the 8 digit values
    public function testMatchesTheRfcVectors(): void
    {
        $secret = totp::base32Encode("12345678901234567890");

        $this->assertSame("287082", totp::code($secret, intdiv(59, 30)));
        $this->assertSame("081804", totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame("050471", totp::code($secret, intdiv(1111111111, 30)));
        $this->assertSame("005924", totp::code($secret, intdiv(1234567890, 30)));
        $this->assertSame("279037", totp::code($secret, intdiv(2000000000, 30)));
    }

    public function testBase32RoundTrips(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, totp::base32Decode(totp::base32Encode($bytes)));
        $this->assertSame("", totp::base32Decode("not base32!"));
    }

    public function testVerifyAllowsOneStepOfClockDrift(): void
    {
        $secret = totp::newSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, 30);

        $this->assertSame($step, totp::verify($secret, totp::code($secret, $step), null, $now));
        $this->assertSame($step - 1, totp::verify($secret, totp::code($secret, $step - 1), null, $now));
        $this->assertSame($step + 1, totp::verify($secret, totp::code($secret, $step + 1), null, $now));
        $this->assertNull(totp::verify($secret, totp::code($secret, $step - 3), null, $now));
    }

    public function testACodeCantBeUsedTwice(): void
    {
        $secret = totp::newSecret();
        $now = 1_700_000_000;
        $code = totp::code($secret, intdiv($now, 30));

        $used = totp::verify($secret, $code, null, $now);
        $this->assertNotNull($used);
        $this->assertNull(totp::verify($secret, $code, $used, $now));
    }

    public function testRejectsJunk(): void
    {
        $secret = totp::newSecret();
        $this->assertNull(totp::verify($secret, "abcdef"));
        $this->assertNull(totp::verify($secret, "12345"));
        $this->assertNull(totp::verify($secret, ""));
    }

    public function testSealedSecretsOpenAndAreNotPlain(): void
    {
        $secret = totp::newSecret();
        $sealed = totp::seal($secret);

        $this->assertStringNotContainsString($secret, $sealed);
        $this->assertNotSame($sealed, totp::seal($secret), "a fresh iv every time");
        $this->assertSame($secret, totp::open($sealed));
        $this->assertNull(totp::open("tampered" . $sealed));
        $this->assertNull(totp::open(null));
    }

    public function testRecoveryCodesWorkOnce(): void
    {
        [$codes, $stored] = totp::newRecoveryCodes();
        $this->assertCount(totp::RECOVERY_CODES, $codes);

        $left = totp::useRecoveryCode($stored, strtoupper(str_replace("-", "", $codes[0])));
        $this->assertNotNull($left, "case and the dash don't matter");
        $this->assertCount(totp::RECOVERY_CODES - 1, json_decode($left, true));
        $this->assertNull(totp::useRecoveryCode($left, $codes[0]));
    }

    public function testUriHasTheSecretAndIssuer(): void
    {
        $_ENV["APP_NAME"] = "Water Games";
        $uri = totp::uri("ABCDEF", "sam");
        $this->assertStringStartsWith("otpauth://totp/Water%20Games%3Asam?", $uri);
        $this->assertStringContainsString("secret=ABCDEF", $uri);
        $this->assertStringContainsString("issuer=Water%20Games", $uri);
    }
}
