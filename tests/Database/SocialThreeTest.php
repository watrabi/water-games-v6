<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\social\friends;
use watrlabs\social\chat;
use watrlabs\social\groups;
use watrlabs\social\status;
use watrlabs\social\activity;
use watrlabs\social\reactions;
use watrlabs\games\playtime;

final class SocialThreeTest extends DatabaseTestCase
{
    private function played($user, int $gameId, int $seconds){
        global $db;

        $db->table("playtime")->insert(["userid"=>$user->id, "gameid"=>$gameId, "seconds"=>$seconds, "sessions"=>1, "first_played"=>time(), "last_played"=>time()]);
    }

    private function fresh($user){
        global $db;

        return $db->table("users")->where("id", $user->id)->first();
    }

    // ---------- people you may know ----------

    public function testSuggestionsAreFriendsOfFriendsRankedByMutuals(): void
    {
        $me = $this->user("me");
        $a = $this->user("a");
        $b = $this->user("b");
        $close = $this->user("close");
        $far = $this->user("far");
        $this->befriend($me, $a);
        $this->befriend($me, $b);
        $this->befriend($a, $close);
        $this->befriend($b, $close);
        $this->befriend($a, $far);

        $people = (new friends())->suggestions((int) $me->id);

        $this->assertSame([(int) $close->id, (int) $far->id], array_column($people, "id"));
        $this->assertSame([2, 1], array_column($people, "mutual"));
    }

    public function testSuggestionsLeaveOutRequestsBlocksAndPrivatePeople(): void
    {
        global $db;
        $me = $this->user("me");
        $a = $this->user("a");
        $asked = $this->user("asked");
        $blocker = $this->user("blocker");
        $private = $this->user("private", ["share_activity"=>0]);
        $this->befriend($me, $a);
        foreach([$asked, $blocker, $private] as $person){
            $this->befriend($a, $person);
        }

        (new friends())->act((int) $me->id, (int) $asked->id, "request");
        (new friends())->act((int) $blocker->id, (int) $me->id, "block");

        $this->assertSame([], (new friends())->suggestions((int) $me->id));
    }

    public function testProfileFriendsPutMutualsFirst(): void
    {
        $me = $this->user("me");
        $them = $this->user("them");
        $shared = $this->user("zz_shared");
        $other = $this->user("aa_other");
        $this->befriend($me, $them);
        $this->befriend($me, $shared);
        $this->befriend($them, $shared);
        $this->befriend($them, $other);

        $result = (new friends())->friendsOf((int) $them->id, (int) $me->id);

        $this->assertSame(1, $result["mutual"]);
        $this->assertSame([(int) $me->id, (int) $shared->id, (int) $other->id], array_column($result["list"], "id"));
        $this->assertTrue($result["list"][0]["isYou"]);
    }

    // ---------- status ----------

    public function testStatusSetsExpiresAndClears(): void
    {
        global $db;
        $me = $this->user("me");

        $this->assertSame("grinding slope", status::set($me, "  grinding   slope ", 3600));
        $this->assertSame("grinding slope", status::current($this->fresh($me)));

        $db->table("users")->where("id", $me->id)->update(["status_until"=>time() - 1]);
        $this->assertNull(status::current($this->fresh($me)));

        status::set($me, "forever", 0);
        $this->assertNull($this->fresh($me)->status_until);

        $this->assertNull(status::set($me, ""));
        $this->assertNull(status::current($this->fresh($me)));
    }

    public function testStatusHasALimitAndMutesBlockIt(): void
    {
        $me = $this->user("me");
        try {
            status::set($me, str_repeat("a", status::MAX_LENGTH + 1));
            $this->fail("a long status got through");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString((string) status::MAX_LENGTH, $e->getMessage());
        }

        $muted = $this->user("muted", ["muted_until"=>time() + 3600]);
        $this->expectException(\InvalidArgumentException::class);
        status::set($muted, "hi");
    }

    public function testFriendsListCarriesStatus(): void
    {
        $me = $this->user("me");
        $friend = $this->user("friend");
        $this->befriend($me, $friend);
        status::set($friend, "brb dinner");

        $list = (new friends())->list((int) $me->id);
        $this->assertSame("brb dinner", $list[0]["status"]);
    }

    // ---------- sharing games ----------

    public function testSharingAGameInADmMakesACard(): void
    {
        $me = $this->user("me");
        $friend = $this->user("friend");
        $this->befriend($me, $friend);
        $game = $this->game();

        $message = (new chat())->send((int) $me->id, (int) $friend->id, "", null, $game);

        $this->assertNull($message["body"]);
        $this->assertSame($game, $message["game"]["id"]);
        $this->assertSame("/item/" . $game, $message["game"]["url"]);
    }

    public function testSharingAMissingGameFails(): void
    {
        $me = $this->user("me");
        $friend = $this->user("friend");
        $this->befriend($me, $friend);

        $this->expectException(\InvalidArgumentException::class);
        (new chat())->send((int) $me->id, (int) $friend->id, "", null, 999999999);
    }

    public function testSharingAGameInAGroup(): void
    {
        $me = $this->user("me");
        $a = $this->user("a");
        $b = $this->user("b");
        $this->befriend($me, $a);
        $this->befriend($me, $b);
        $groups = new groups();
        $id = $groups->create((int) $me->id, "Squad", [(int) $a->id, (int) $b->id]);
        $game = $this->game();

        $message = $groups->send((int) $me->id, $id, "join me", null, $game);

        $this->assertSame("join me", $message["body"]);
        $this->assertSame($game, $message["game"]["id"]);
    }

    // ---------- feed reactions ----------

    public function testFriendsCanReactToActivityButStrangersCant(): void
    {
        global $db;
        $me = $this->user("me");
        $friend = $this->user("friend");
        $stranger = $this->user("stranger");
        $this->befriend($me, $friend);

        activity::log((int) $friend->id, "favorite", $this->game());
        $item = $db->table("activity")->where("userid", $friend->id)->where("type", "favorite")->first();

        $summary = (new reactions())->toggle($me, "activity", (int) $item->id, "🔥");
        $this->assertSame(1, $summary[0]["count"]);

        $feed = (new activity())->feedFor((int) $me->id);
        $mine = array_values(array_filter($feed, fn($i) => ($i["id"] ?? null) === (int) $item->id));
        $this->assertSame("🔥", $mine[0]["reactions"][0]["emoji"]);

        $this->expectException(\InvalidArgumentException::class);
        (new reactions())->toggle($stranger, "activity", (int) $item->id, "🔥");
    }

    public function testHiddenActivityCantBeReactedTo(): void
    {
        global $db;
        $me = $this->user("me");
        $friend = $this->user("friend");
        $this->befriend($me, $friend);
        activity::log((int) $friend->id, "favorite", $this->game());
        $item = $db->table("activity")->where("userid", $friend->id)->where("type", "favorite")->first();
        $db->table("users")->where("id", $friend->id)->update(["share_activity"=>0]);

        $this->expectException(\InvalidArgumentException::class);
        (new reactions())->toggle($me, "activity", (int) $item->id, "👍");
    }

    // ---------- playing together ----------

    public function testFriendsWhoPlayedSkipsPeopleWhoDontShare(): void
    {
        $me = $this->user("me");
        $sharer = $this->user("sharer");
        $private = $this->user("private", ["share_activity"=>0]);
        $stranger = $this->user("stranger");
        $this->befriend($me, $sharer);
        $this->befriend($me, $private);
        $game = $this->game();
        $this->played($sharer, $game, 300);
        $this->played($private, $game, 600);
        $this->played($stranger, $game, 900);

        $rows = (new playtime())->friendsOn((int) $me->id, $game);

        $this->assertSame([(int) $sharer->id], array_map(fn($r) => (int) $r->id, $rows));
    }

    public function testGamesInCommon(): void
    {
        $me = $this->user("me");
        $them = $this->user("them");
        $both = $this->game();
        $onlyMine = $this->game();
        $this->played($me, $both, 100);
        $this->played($them, $both, 200);
        $this->played($me, $onlyMine, 500);

        $rows = (new playtime())->inCommon((int) $me->id, (int) $them->id);

        $this->assertCount(1, $rows);
        $this->assertSame($both, (int) $rows[0]->id);
        $this->assertSame(100, (int) $rows[0]->mine);
        $this->assertSame(200, (int) $rows[0]->theirs);
    }
}
