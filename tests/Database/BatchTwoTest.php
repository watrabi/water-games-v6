<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\watrkit\ratelimit;
use watrlabs\users\moderation;
use watrlabs\users\progress;
use watrlabs\users\recap;
use watrlabs\games\scores;
use watrlabs\games\collections;
use watrlabs\games\featured;
use watrlabs\games\playtime;
use watrlabs\games\comments;
use watrlabs\social\chat;
use watrlabs\social\reactions;

final class BatchTwoTest extends DatabaseTestCase
{
    private function scoredGame(string $order = "high", ?int $max = null){
        global $db;
        $id = $this->game();
        $db->table("games")->where("id", $id)->update(["scores"=>$order, "score_max"=>$max]);
        return (new \watrlabs\games\games())->get($id);
    }

    // the game page was opened, which is what lets scores count
    private function opened($user, $game){
        (new playtime())->start($user, (int) $game->id);
    }

    // ---------- rate limits ----------

    public function testRateLimitsCountWithinAWindowThenReset(): void
    {
        $who = "test" . bin2hex(random_bytes(4));
        $now = time();

        $this->assertTrue(ratelimit::hit("t", $who, 2, 60, $now));
        $this->assertTrue(ratelimit::hit("t", $who, 2, 60, $now));
        $this->assertFalse(ratelimit::hit("t", $who, 2, 60, $now));
        $this->assertSame(3, ratelimit::count("t", $who, $now));

        // a minute later the window starts over
        $this->assertTrue(ratelimit::hit("t", $who, 2, 60, $now + 61));
        $this->assertSame(1, ratelimit::count("t", $who, $now + 61));
    }

    // ---------- bans and mutes ----------

    public function testATimedBanLiftsItselfWhenTimeIsUp(): void
    {
        global $db;
        $sam = $this->user("sam");

        moderation::ban((int) $sam->id, 3600, "spam", null);
        $user = $db->table("users")->where("id", $sam->id)->first();
        $this->assertTrue(moderation::isBanned($user));
        $this->assertStringContainsString("spam", moderation::banMessage($user));

        // pretend the hour went by
        $this->assertFalse(moderation::isBanned($user, time() + 3601));
        $this->assertSame(0, (int) $db->table("users")->where("id", $sam->id)->first()->banned);
        $this->assertSame(["ban", "unban"], array_map(fn($r) => $r->action, array_reverse(moderation::history((int) $sam->id))));
    }

    public function testForeverBansStay(): void
    {
        global $db;
        $sam = $this->user("sam");

        moderation::ban((int) $sam->id, 0, null, null);
        $this->assertTrue(moderation::isBanned($db->table("users")->where("id", $sam->id)->first(), time() + 10 * 365 * 86400));
    }

    public function testMutedPeopleCantCommentOrChat(): void
    {
        global $db;
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $this->befriend($sam, $alex);

        moderation::mute((int) $sam->id, 86400, "be nice", null);
        $muted = $db->table("users")->where("id", $sam->id)->first();

        try {
            (new comments())->add($muted, $this->game(), "hello");
            $this->fail("commented while muted");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("be nice", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        (new chat())->send((int) $sam->id, (int) $alex->id, "hi", null);
    }

    public function testExpiredMutesAreLiftedByTheCron(): void
    {
        global $db;
        $sam = $this->user("sam", ["muted_until"=>time() - 5, "mute_reason"=>"old"]);

        $this->assertGreaterThanOrEqual(1, moderation::liftExpired());
        $this->assertNull($db->table("users")->where("id", $sam->id)->first()->muted_until);
    }

    // ---------- scores ----------

    public function testScoresKeepYourBestAndRankEveryone(): void
    {
        $game = $this->scoredGame("high");
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $this->opened($sam, $game);
        $this->opened($alex, $game);
        $scores = new scores();

        $first = $scores->submit($sam, $game, 100);
        $this->assertTrue($first["personalBest"]);
        $this->assertSame(1, $first["rank"]);

        ratelimit::clear("score:" . $game->id, "u" . $sam->id);
        $worse = $scores->submit($sam, $game, 50);
        $this->assertFalse($worse["personalBest"]);
        $this->assertSame(100, $worse["best"]);

        $scores->submit($alex, $game, 300);
        $this->assertSame(2, $scores->rankOf($game, (int) $sam->id));

        $board = $scores->board($game, "all", $sam);
        $this->assertSame([300, 100], array_map(fn($r) => $r["score"], $board["rows"]));
        $this->assertSame(2, $board["me"]["rank"]);

        // this week's board has them too
        $this->assertCount(2, $scores->board($game, "week")["rows"]);
    }

    public function testLowerIsBetterForTimedGames(): void
    {
        $game = $this->scoredGame("low");
        $sam = $this->user("sam");
        $this->opened($sam, $game);
        $scores = new scores();

        $scores->submit($sam, $game, 9000);
        ratelimit::clear("score:" . $game->id, "u" . $sam->id);
        $this->assertTrue($scores->submit($sam, $game, 7000)["personalBest"]);
        ratelimit::clear("score:" . $game->id, "u" . $sam->id);
        $this->assertFalse($scores->submit($sam, $game, 8000)["personalBest"]);
        $this->assertSame(7000, $scores->best((int) $sam->id, (int) $game->id));
    }

    public function testScoresNeedTheGameOpenAndAreThrottled(): void
    {
        $game = $this->scoredGame("high");
        $sam = $this->user("sam");
        $scores = new scores();

        try {
            $scores->submit($sam, $game, 10);
            $this->fail("scored without opening the game");
        } catch (\InvalidArgumentException $e) {}

        $this->opened($sam, $game);
        $scores->submit($sam, $game, 10);

        $this->expectException(\InvalidArgumentException::class);
        $scores->submit($sam, $game, 20); // within 5 seconds
    }

    public function testBeatingAChallengeTellsWhoSentIt(): void
    {
        global $db;
        $game = $this->scoredGame("high");
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $this->befriend($sam, $alex);
        $this->opened($sam, $game);
        $this->opened($alex, $game);
        $scores = new scores();

        $scores->submit($sam, $game, 500);
        $scores->challenge($sam, $game, (int) $alex->id);
        $this->assertSame(1, $db->table("notifications")->where("userid", $alex->id)->where("type", "challenge")->count());
        $this->assertCount(1, $scores->openChallenges((int) $alex->id, $game));

        $scores->submit($alex, $game, 501);
        $this->assertSame(1, $db->table("notifications")->where("userid", $sam->id)->where("type", "challenge_beaten")->count());
        $this->assertCount(0, $scores->openChallenges((int) $alex->id, $game));
    }

    public function testYouCanOnlyChallengeFriends(): void
    {
        $game = $this->scoredGame("high");
        $sam = $this->user("sam");
        $stranger = $this->user("stranger");
        $this->opened($sam, $game);
        (new scores())->submit($sam, $game, 5);

        $this->expectException(\InvalidArgumentException::class);
        (new scores())->challenge($sam, $game, (int) $stranger->id);
    }

    // ---------- streaks, xp, recap ----------

    public function testPlayingAMinuteADayBuildsAStreakAndXp(): void
    {
        global $db;
        $sam = $this->user("sam");
        $game = $this->game();
        $day = strtotime("2026-10-05 12:00:00");

        // three days in a row, a minute each
        foreach([0, 1, 2] as $i){
            $db->query("INSERT INTO playtime (userid, gameid, seconds, sessions, first_played, last_played) VALUES (?, ?, 60, 1, ?, ?)
                        ON DUPLICATE KEY UPDATE seconds = seconds + 60", [$sam->id, $game, $day, $day]);
            progress::played((int) $sam->id, $game, 60, $day + $i * 86400);
        }

        $user = $db->table("users")->where("id", $sam->id)->first();
        $this->assertSame(3, (int) $user->streak);
        $this->assertSame("2026-10-07", $user->streak_day);
        // 3 minutes + 3 days * 10
        $this->assertSame(33, (int) $user->xp);
        $this->assertSame(progress::levelFor(33), (int) $user->level);
    }

    public function testTheRecapGoesOutOncePerWeek(): void
    {
        global $db;
        $sam = $this->user("sam");
        $game = $this->game();
        $week = recap::lastWeek();
        $db->table("user_game_daily")->insert(["userid"=>$sam->id, "gameid"=>$game, "day"=>$week["start"], "seconds"=>3600]);

        $recap = recap::deliver($db->table("users")->where("id", $sam->id)->first());
        $this->assertNotNull($recap);
        $this->assertSame(3600, $recap["total"]);
        $this->assertSame(1, $recap["daysPlayed"]);
        $this->assertCount(7, $recap["chart"]);

        // a second page view the same week sends nothing
        $this->assertNull(recap::deliver($db->table("users")->where("id", $sam->id)->first()));
        $this->assertSame(1, $db->table("notifications")->where("userid", $sam->id)->where("type", "recap")->count());
    }

    public function testNoRecapForAWeekWithoutPlay(): void
    {
        global $db;
        $sam = $this->user("sam");

        $this->assertNull(recap::deliver($db->table("users")->where("id", $sam->id)->first()));
        $this->assertSame(0, $db->table("notifications")->where("userid", $sam->id)->where("type", "recap")->count());
    }

    // ---------- collections ----------

    public function testPrivateCollectionsStayPrivate(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $collections = new collections();

        $id = $collections->create($sam, "Secret stash", "", false);
        $collections->add($sam, $id, $this->game());

        $this->assertNotNull($collections->visible($id, $sam));
        $this->assertNull($collections->visible($id, $alex));
        $this->assertNull($collections->visible($id, null));
        $this->assertCount(1, $collections->listFor((int) $sam->id));
        $this->assertCount(0, $collections->listFor((int) $sam->id, true));
    }

    public function testOnlyTheOwnerChangesACollection(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $collections = new collections();
        $id = $collections->create($sam, "Mine");

        $this->expectException(\InvalidArgumentException::class);
        $collections->add($alex, $id, $this->game());
    }

    public function testOnlyAdminsMakeStaffPicks(): void
    {
        global $db;
        $sam = $this->user("sam");
        $admin = $this->user("boss", ["admin"=>1]);
        $collections = new collections();

        $mine = $collections->create($sam, "Mine");
        $collections->update($sam, $mine, ["name"=>"Mine", "public"=>1, "staff"=>1]);
        $this->assertSame(0, (int) $db->table("collections")->where("id", $mine)->first()->staff);

        $theirs = $collections->create($admin, "Best puzzles");
        $collections->update($admin, $theirs, ["name"=>"Best puzzles", "public"=>1, "staff"=>1]);
        $this->assertSame(1, (int) $db->table("collections")->where("id", $theirs)->first()->staff);
    }

    // ---------- reactions and editing ----------

    public function testReactionsToggleAndOnlyFromPeopleInTheChat(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $jo = $this->user("jo");
        $this->befriend($sam, $alex);
        $message = (new chat())->send((int) $sam->id, (int) $alex->id, "hi", null);
        $reactions = new reactions();

        $list = $reactions->toggle($alex, "dm", $message["id"], "👍");
        $this->assertSame([["emoji"=>"👍", "count"=>1, "users"=>[(int) $alex->id]]], $list);
        $this->assertSame([], $reactions->toggle($alex, "dm", $message["id"], "👍"), "pressing it again takes it back");

        try {
            $reactions->toggle($alex, "dm", $message["id"], "💩");
            $this->fail("reacted with an emoji that isn't on the list");
        } catch (\InvalidArgumentException $e) {}

        $this->expectException(\InvalidArgumentException::class);
        $reactions->toggle($jo, "dm", $message["id"], "👍");
    }

    public function testEditsKeepTheOldTextAndOnlyLastAnHour(): void
    {
        global $db;
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $this->befriend($sam, $alex);
        $chat = new chat();
        $message = $chat->send((int) $sam->id, (int) $alex->id, "helo", null);

        $edited = $chat->edit((int) $sam->id, $message["id"], "hello");
        $this->assertSame("hello", $edited["body"]);
        $this->assertTrue($edited["edited"]);
        $this->assertSame("helo", $db->table("message_edits")->where("kind", "dm")->where("item_id", $message["id"])->first()->body);

        try {
            $chat->edit((int) $alex->id, $message["id"], "not yours");
            $this->fail("edited someone else's message");
        } catch (\InvalidArgumentException $e) {}

        $deleted = $chat->deleteOwn((int) $sam->id, $message["id"]);
        $this->assertTrue($deleted["deleted"]);
        $this->assertSame("sender", $deleted["removedBy"]);

        // an old one can't be changed
        $old = $chat->send((int) $sam->id, (int) $alex->id, "from before", null);
        $db->table("chat_messages")->where("id", $old["id"])->update(["created"=>time() - 7200]);
        $this->expectException(\InvalidArgumentException::class);
        $chat->edit((int) $sam->id, $old["id"], "changed");
    }

    // ---------- game of the day ----------

    public function testGameOfTheDayIsTheSameAllDayAndCanBePinned(): void
    {
        $this->game();
        $this->game();
        $pinned = $this->game();

        $first = featured::gameOfDayId("2031-01-01");
        $this->assertNotNull($first);
        $this->assertSame($first, featured::gameOfDayId("2031-01-01"));

        featured::pin($pinned);
        $this->assertSame($pinned, featured::gameOfDayId());
        $this->assertSame($pinned, featured::pinnedToday());
    }
}
