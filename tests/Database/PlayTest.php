<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\games\playtime;
use watrlabs\games\cloudsaves;
use watrlabs\games\games;

final class PlayTest extends DatabaseTestCase
{
    public function testHeartbeatsAddUpAndSetPresence(): void
    {
        global $db;
        $me = $this->user("player");
        $game = $this->game();
        $playtime = new playtime();

        $playtime->start($me, $game);
        $total = $playtime->beat($me, $game, 15);
        $this->assertSame(15, $total);

        $row = $db->table("users")->where("id", $me->id)->first();
        $this->assertSame($game, (int) $row->playing_game_id);

        // a second beat in the same second only gets the slack
        $this->assertSame(17, $playtime->beat($me, $game, 15));

        $daily = $db->table("game_daily")->where("gameid", $game)->first();
        $this->assertSame(17, (int) $daily->seconds);

        $playtime->stop($me, $game);
        $this->assertNull($db->table("users")->where("id", $me->id)->first()->playing_game_id);
    }

    public function testTwoGamesAtOnceDontDoubleCount(): void
    {
        $me = $this->user("player");
        $one = $this->game();
        $two = $this->game();
        $playtime = new playtime();

        $playtime->beat($me, $one, 15);
        $playtime->beat($me, $two, 15);

        $this->assertSame(15, $playtime->secondsFor((int) $me->id, $one));
        $this->assertLessThanOrEqual(2, $playtime->secondsFor((int) $me->id, $two));
    }

    public function testFirstMinuteEarnsAnAchievement(): void
    {
        global $db;
        $me = $this->user("player");
        $game = $this->game();

        (new playtime())->beat($me, $game, 60);
        $this->assertNotNull($db->table("user_achievements")->where("userid", $me->id)->where("code", "first_play")->first());
    }

    public function testCloudSavesMergeAcrossDevices(): void
    {
        $me = $this->user("player");
        $game = $this->game();
        $saves = new cloudsaves();

        $saves->put((int) $me->id, $game, ["level"=>"3", "coins"=>"10"]);
        $saves->put((int) $me->id, $game, ["coins"=>"25", "sidebarClosed"=>"1"]);

        $this->assertSame(["level"=>"3", "coins"=>"25"], $saves->get((int) $me->id, $game)["data"]);
    }

    public function testVotesFlipAndTakeBack(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $game = $this->game();
        $games = new games();

        $games->vote((int) $sam->id, $game, 1);
        $counts = $games->vote((int) $alex->id, $game, -1);
        $this->assertSame(["likes"=>1, "dislikes"=>1], $counts);

        $counts = $games->vote((int) $alex->id, $game, 1);
        $this->assertSame(["likes"=>2, "dislikes"=>0], $counts);

        $counts = $games->vote((int) $sam->id, $game, 0);
        $this->assertSame(["likes"=>1, "dislikes"=>0], $counts);
    }
}
