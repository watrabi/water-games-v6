<?php

use PHPUnit\Framework\TestCase;
use watrlabs\users\progress;
use watrlabs\users\recap;
use watrlabs\games\scores;

final class ProgressTest extends TestCase
{
    public function testLevelsFollowTheCurve(): void
    {
        $this->assertSame(1, progress::levelFor(0));
        $this->assertSame(1, progress::levelFor(24));
        $this->assertSame(2, progress::levelFor(25));
        $this->assertSame(5, progress::levelFor(400));
        $this->assertSame(10, progress::levelFor(2025));
        $this->assertSame(1, progress::levelFor(-50));

        // where each level starts lines up with levelFor
        foreach([1, 2, 7, 30] as $level){
            $this->assertSame($level, progress::levelFor(progress::xpForLevel($level)));
            $this->assertSame($level - 1 ?: 1, progress::levelFor(max(0, progress::xpForLevel($level) - 1)));
        }

        $this->assertSame(0, progress::levelProgress(25));
        $this->assertSame(33, progress::levelProgress(50)); // level 2 runs 25..100, 50 is a third of the way
    }

    public function testStreaksCountDaysInARow(): void
    {
        $this->assertSame(1, progress::nextStreak(null, 0, "2026-10-07"));
        $this->assertSame(4, progress::nextStreak("2026-10-06", 3, "2026-10-07"));
        $this->assertSame(3, progress::nextStreak("2026-10-07", 3, "2026-10-07"), "same day doesn't count twice");
        $this->assertSame(1, progress::nextStreak("2026-10-05", 9, "2026-10-07"), "a missed day starts over");
        $this->assertSame(2, progress::nextStreak("2026-12-31", 1, "2027-01-01"), "across a year");
    }

    public function testAStreakEndsAfterAMissedDay(): void
    {
        $user = (object) ["streak"=>5, "streak_day"=>"2026-10-06"];
        $this->assertSame(5, progress::currentStreak($user, "2026-10-06"));
        $this->assertSame(5, progress::currentStreak($user, "2026-10-07"), "still alive until today's over");
        $this->assertSame(0, progress::currentStreak($user, "2026-10-08"));
        $this->assertSame(0, progress::currentStreak((object) ["streak"=>0, "streak_day"=>null]));
    }

    public function testRecapWeeksAreMondayToSunday(): void
    {
        // a wednesday: last week is the one before this one
        $week = recap::lastWeek(strtotime("2026-10-07 12:00"));
        $this->assertSame(["id"=>"2026-40", "start"=>"2026-09-28", "end"=>"2026-10-04"], $week);

        // a monday: the week that just ended
        $this->assertSame("2026-10-05", recap::lastWeek(strtotime("2026-10-12 00:30"))["start"]);

        $this->assertSame(["id"=>"2026-41", "start"=>"2026-10-05", "end"=>"2026-10-11"], recap::week("2026-41"));
        $this->assertSame("2020-12-28", recap::week("2020-53")["start"], "years with a week 53");
        $this->assertNull(recap::week("2026-60"));
        $this->assertNull(recap::week("nope"));
    }

    public function testScoresFormatAsNumbersOrTimes(): void
    {
        $points = (object) ["score_format"=>"number", "score_label"=>"points"];
        $plain = (object) ["score_format"=>"number", "score_label"=>null];
        $time = (object) ["score_format"=>"time", "score_label"=>null];

        $this->assertSame("1,234 points", scores::format(1234, $points));
        $this->assertSame("1,234", scores::format(1234, $plain));
        $this->assertSame("1:23.456", scores::format(83456, $time));
        $this->assertSame("0:05.007", scores::format(5007, $time));
        $this->assertSame("1:02:03.4", scores::format(3723400, $time));
    }

    public function testOnlyWholeBelievableScoresGetThrough(): void
    {
        $game = (object) ["scores"=>"high", "score_format"=>"number", "score_max"=>1000];

        $this->assertSame(500, scores::validate($game, 500));
        $this->assertSame(500, scores::validate($game, "500"));
        $this->assertSame(500, scores::validate($game, 500.0));

        foreach([12.5, "12abc", -1, 1001, null, [], "1e5"] as $bad){
            try {
                scores::validate($game, $bad);
                $this->fail("let " . json_encode($bad) . " through");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        scores::validate((object) ["scores"=>"off", "score_format"=>"number", "score_max"=>null], 5);
    }
}
