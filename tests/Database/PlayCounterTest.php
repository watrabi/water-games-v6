<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\watrkit\playcounter;

final class PlayCounterTest extends DatabaseTestCase
{
    public function testRefreshingOnlyCountsOncePerWindow(): void
    {
        $game = $this->game();
        $now = 1_800_000_000;

        $this->assertTrue(playcounter::shouldCount("game", $game, "u1", $now));
        $this->assertFalse(playcounter::shouldCount("game", $game, "u1", $now));
        $this->assertFalse(playcounter::shouldCount("game", $game, "u1", $now + 60));
        $this->assertFalse(playcounter::shouldCount("game", $game, "u1", $now + 1799));

        // the window is measured from the last time it counted, not from the last refresh
        $this->assertTrue(playcounter::shouldCount("game", $game, "u1", $now + 1800));
    }

    public function testDifferentPeopleAndGamesCountSeparately(): void
    {
        $one = $this->game();
        $two = $this->game();
        $now = 1_800_000_000;

        $this->assertTrue(playcounter::shouldCount("game", $one, "u1", $now));
        $this->assertTrue(playcounter::shouldCount("game", $one, "u2", $now));
        $this->assertTrue(playcounter::shouldCount("game", $two, "u1", $now));
        $this->assertTrue(playcounter::shouldCount("track", $one, "u1", $now), "a track with the same id is its own thing");
    }

    public function testSongsHaveAShorterWindow(): void
    {
        $now = 1_800_000_000;

        $this->assertTrue(playcounter::shouldCount("track", 77, "u1", $now));
        $this->assertFalse(playcounter::shouldCount("track", 77, "u1", $now + 599));
        $this->assertTrue(playcounter::shouldCount("track", 77, "u1", $now + 600));
    }

    public function testGuestsAreKeyedByAddressWithoutStoringIt(): void
    {
        $_SERVER["REMOTE_ADDR"] = "203.0.113.9";
        unset($_SERVER["HTTP_CF_CONNECTING_IP"]);

        $guest = playcounter::viewer(null);
        $this->assertStringStartsWith("ip", $guest);
        $this->assertStringNotContainsString("203.0.113.9", $guest);
        $this->assertSame($guest, playcounter::viewer(null), "same address, same key");

        $_SERVER["REMOTE_ADDR"] = "203.0.113.10";
        $this->assertNotSame($guest, playcounter::viewer(null));

        $this->assertSame("u5", playcounter::viewer((object) ["id"=>5]));
    }
}
