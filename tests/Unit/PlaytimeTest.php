<?php

use PHPUnit\Framework\TestCase;
use watrlabs\games\playtime;

final class PlaytimeTest extends TestCase
{
    public function testFirstHeartbeatIsCappedPerBeat(): void
    {
        $this->assertSame(15, playtime::credit(15, null, 1000));
        $this->assertSame(playtime::MAX_PER_BEAT, playtime::credit(9999, null, 1000));
    }

    public function testNeverMoreThanTheTimeSinceTheLastBeat(): void
    {
        // two tabs: the second one claims 15s but only 4 seconds passed
        $this->assertSame(6, playtime::credit(15, 996, 1000), "4 seconds plus 2 of slack");
        $this->assertSame(15, playtime::credit(15, 900, 1000));
        $this->assertSame(2, playtime::credit(15, 1000, 1000), "same second, only the slack");
    }

    public function testNegativeAndZeroClaimsCountForNothing(): void
    {
        $this->assertSame(0, playtime::credit(-50, null, 1000));
        $this->assertSame(0, playtime::credit(0, 990, 1000));
    }

    public function testClockGoingBackwardsDoesntGoNegative(): void
    {
        $this->assertSame(2, playtime::credit(10, 2000, 1000));
    }

    public function testFormat(): void
    {
        $this->assertSame("0s", playtime::format(0));
        $this->assertSame("59s", playtime::format(59));
        $this->assertSame("1m", playtime::format(60));
        $this->assertSame("45m", playtime::format(45 * 60 + 30));
        $this->assertSame("1h", playtime::format(3600));
        $this->assertSame("3h 12m", playtime::format(3 * 3600 + 12 * 60));
        $this->assertSame("120h 5m", playtime::format(120 * 3600 + 5 * 60 + 59));
    }

    public function testNowPlayingRespectsPrivacyAndStaleness(): void
    {
        $row = (object) ["playing_game_id"=>4, "share_activity"=>1, "play_beat"=>time()];
        $this->assertSame(4, playtime::nowPlaying($row));

        $this->assertNull(playtime::nowPlaying((object) ["playing_game_id"=>4, "share_activity"=>0, "play_beat"=>time()]));
        $this->assertNull(playtime::nowPlaying((object) ["playing_game_id"=>4, "share_activity"=>1, "play_beat"=>time() - playtime::PRESENCE_FOR - 1]));
        $this->assertNull(playtime::nowPlaying((object) ["playing_game_id"=>null, "share_activity"=>1, "play_beat"=>time()]));
    }
}
