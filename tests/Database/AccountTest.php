<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\music\playlists;
use watrlabs\users\accountdata;
use watrlabs\authentication\passwordreset;

final class AccountTest extends DatabaseTestCase
{
    public function testPlaylistsBelongToTheirOwner(): void
    {
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $playlists = new playlists();

        $id = $playlists->create((int) $sam->id, "Bangers");

        $this->expectException(\InvalidArgumentException::class);
        $playlists->rename((int) $alex->id, $id, "Mine now");
    }

    public function testExportLeavesOutSecrets(): void
    {
        $me = $this->user("sam", ["email"=>"sam@example.com", "totp_secret"=>"SECRETSECRET", "recovery_codes"=>"[]"]);
        $json = json_encode((new accountdata())->export((int) $me->id));

        $this->assertStringContainsString("sam@example.com", $json);
        $this->assertStringNotContainsString("SECRETSECRET", $json);
        $this->assertStringNotContainsString('$2y$', $json, "no password hash");
    }

    public function testDeletingRemovesTheAccountAndItsRows(): void
    {
        global $db;
        $sam = $this->user("sam");
        $alex = $this->user("alex");
        $jo = $this->user("jo");
        $this->befriend($sam, $alex);
        $this->befriend($sam, $jo);
        $this->befriend($alex, $jo);
        $group = (new \watrlabs\social\groups())->create((int) $sam->id, "", [(int) $alex->id, (int) $jo->id]);
        (new playlists())->create((int) $sam->id, "Mine");

        (new accountdata())->delete((int) $sam->id);

        $this->assertNull($db->table("users")->where("id", $sam->id)->first());
        $this->assertSame(0, $db->table("friendships")->where("requester_id", $sam->id)->orWhere("addressee_id", $sam->id)->count());
        $this->assertSame(0, $db->table("playlists")->where("userid", $sam->id)->count());
        $this->assertNotNull($db->table("chat_groups")->where("id", $group)->first(), "the group carries on without them");
    }

    public function testPasswordResetLinksWorkOnce(): void
    {
        global $db;
        $_ENV["APP_DEBUG"] = "false"; // no mail is sent, that's fine, we read the row
        $me = $this->user("sam", ["email"=>"sam@example.com"]);

        // put a known token in, the way start() would
        $token = bin2hex(random_bytes(32));
        $db->table("password_resets")->insert(["userid"=>$me->id, "token_hash"=>hash("sha256", $token), "created"=>time(), "expires"=>time() + 3600]);

        $reset = new passwordreset();
        $this->assertNotNull($reset->find($token));
        $this->assertSame((int) $me->id, $reset->finish($token, "a brand new password"));
        $this->assertTrue(password_verify("a brand new password", $db->table("users")->where("id", $me->id)->first()->password));

        $this->expectException(\InvalidArgumentException::class);
        $reset->finish($token, "another new password");
    }
}
