<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\social\friends;
use watrlabs\social\chat;
use watrlabs\social\groups;
use watrlabs\social\notifications;

final class SocialTest extends DatabaseTestCase
{
    public function testFriendRequestsSendNotificationsAndAchievements(): void
    {
        global $db;
        $sam = $this->user("sam");
        $alex = $this->user("alex");

        (new friends())->act((int) $sam->id, (int) $alex->id, "request");
        $this->assertSame(1, (new notifications())->unread((int) $alex->id));

        (new friends())->act((int) $alex->id, (int) $sam->id, "accept");
        $this->assertSame(1, $db->table("notifications")->where("userid", $sam->id)->where("type", "friend_accept")->count());
        $this->assertNotNull($db->table("user_achievements")->where("userid", $sam->id)->where("code", "first_friend")->first());
    }

    public function testOnlyFriendsCanMessage(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");

        $this->expectException(\InvalidArgumentException::class);
        (new chat())->send((int) $sam->id, (int) $alex->id, "hi", null);
    }

    public function testGroupsNeedFriendsAndAtLeastTwo(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $stranger = $this->user("stranger");
        $this->befriend($sam, $alex);

        try {
            (new groups())->create((int) $sam->id, "", [(int) $alex->id, (int) $stranger->id]);
            $this->fail("a group with only one friend shouldn't be allowed");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("two friends", $e->getMessage());
        }
    }

    public function testGroupLifecycle(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $jo = $this->user("jo");
        $outsider = $this->user("out");
        $this->befriend($sam, $alex);
        $this->befriend($sam, $jo);

        $groups = new groups();
        $id = $groups->create((int) $sam->id, "Squad", [(int) $alex->id, (int) $jo->id]);

        $this->assertCount(3, $groups->memberIds($id));
        global $db;
        $this->assertSame(1, $db->table("notifications")->where("userid", $alex->id)->where("type", "group_added")->count());

        $message = $groups->send((int) $alex->id, $id, "hello all", null);
        $this->assertSame("hello all", $message["body"]);

        $list = $groups->listFor((int) $jo->id);
        $this->assertSame(1, $list[0]["unread"], "the system note doesn't count, alex's message does");

        // people outside can't read or post
        try {
            $groups->history((int) $outsider->id, $id);
            $this->fail("outsiders can't read a group");
        } catch (\InvalidArgumentException $e) {}

        // only the owner removes people
        try {
            $groups->remove((int) $alex->id, $id, (int) $jo->id);
            $this->fail("only the owner can remove");
        } catch (\InvalidArgumentException $e) {}

        // owner leaves, the longest member takes over
        $groups->leave((int) $sam->id, $id);
        global $db;
        $owner = (int) $db->table("chat_groups")->where("id", $id)->first()->owner_id;
        $this->assertContains($owner, [(int) $alex->id, (int) $jo->id]);

        // everyone leaves, the group is gone
        $groups->leave((int) $alex->id, $id);
        $groups->leave((int) $jo->id, $id);
        $this->assertNull($db->table("chat_groups")->where("id", $id)->first());
    }

    public function testGroupReportsOnlyFromMembers(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $jo = $this->user("jo");
        $this->befriend($sam, $alex);
        $this->befriend($sam, $jo);

        $groups = new groups();
        $id = $groups->create((int) $sam->id, "", [(int) $alex->id, (int) $jo->id]);
        $message = $groups->send((int) $sam->id, $id, "something mean", null);

        $groups->report((int) $alex->id, $message["id"], "harassment", "");

        global $db;
        $this->assertSame(1, $db->table("chat_reports")->where("kind", "group")->where("message_id", $message["id"])->count());

        $this->expectException(\InvalidArgumentException::class);
        $groups->report((int) $sam->id, $message["id"], "harassment", ""); // can't report yourself
    }
}
