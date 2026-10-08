<?php

use PHPUnit\Framework\TestCase;
use watrlabs\games\cloudsaves;
use watrlabs\games\games;
use watrlabs\games\tags;
use watrlabs\watrkit\uploads;
use watrlabs\authentication\sessions;
use watrlabs\authentication\registration;
use watrlabs\social\notifications;

final class SmallHelpersTest extends TestCase
{
    public function testCloudSavesKeepOnlyTheGamesStringKeys(): void
    {
        $clean = cloudsaves::clean([
            "highscore"=>"120",
            "settings"=>'{"music":false}',
            "sidebarClosed"=>"1",      // the site's
            "watrMusic"=>"{}",         // the site's
            "wg_theme"=>"x",           // the site's
            "number"=>12,              // not a string
            ""=>"empty key",
        ]);

        $this->assertSame(["highscore"=>"120", "settings"=>'{"music":false}'], $clean);
        $this->assertSame([], cloudsaves::clean("nope"));
    }

    public function testCloudSavesRefuseHugeSaves(): void
    {
        $this->assertNull(cloudsaves::clean(["big"=>str_repeat("x", cloudsaves::MAX_BYTES)]));

        $many = [];
        for($i = 0; $i <= cloudsaves::MAX_KEYS; $i++){
            $many["k$i"] = "v";
        }
        $this->assertNull(cloudsaves::clean($many));
    }

    public function testOnlyOwnGamesCanBeCloudSaved(): void
    {
        $this->assertTrue(cloudsaves::supported((object) ["gamePath"=>"/game-files/slope/index.html"]));
        $this->assertFalse(cloudsaves::supported((object) ["gamePath"=>"https://example.com/game"]));
    }

    public function testRating(): void
    {
        $this->assertNull(games::rating(0, 0));
        $this->assertSame(100, games::rating(3, 0));
        $this->assertSame(67, games::rating(2, 1));
    }

    public function testSlugify(): void
    {
        $this->assertSame("two-player", tags::slugify("Two Player!"));
        $this->assertSame("racing", tags::slugify("  Racing  "));
        $this->assertSame("", tags::slugify("!!!"));
    }

    public function testAudioSniffing(): void
    {
        $this->assertSame("audio/mpeg", uploads::sniffAudio("ID3\x04\x00"));
        $this->assertSame("audio/mpeg", uploads::sniffAudio("\xFF\xFB\x90\x00"));
        $this->assertSame("audio/ogg", uploads::sniffAudio("OggS\x00"));
        $this->assertSame("audio/flac", uploads::sniffAudio("fLaC"));
        $this->assertSame("audio/x-wav", uploads::sniffAudio("RIFF\x24\x00\x00\x00WAVEfmt "));
        $this->assertSame("audio/mp4", uploads::sniffAudio("\x00\x00\x00\x20ftypM4A "));
        $this->assertSame("audio/webm", uploads::sniffAudio("\x1A\x45\xDF\xA3"));
        $this->assertSame("application/octet-stream", uploads::sniffAudio("<?php echo 1;"));
        $this->assertSame("application/octet-stream", uploads::sniffAudio("RIFF\x24\x00\x00\x00AVI "));
    }

    public function testEverySniffedTypeIsAccepted(): void
    {
        foreach(["audio/mpeg", "audio/ogg", "audio/flac", "audio/x-wav", "audio/mp4", "audio/webm"] as $mime){
            $this->assertArrayHasKey($mime, uploads::AUDIO);
        }
    }

    public function testDeviceNames(): void
    {
        $this->assertSame("Firefox on Windows", sessions::describeAgent("Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:152.0) Gecko/20100101 Firefox/152.0"));
        $this->assertSame("Chrome on ChromeOS", sessions::describeAgent("Mozilla/5.0 (X11; CrOS x86_64 15000.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"));
        $this->assertSame("Safari on iPhone", sessions::describeAgent("Mozilla/5.0 (iPhone; CPU iPhone OS 27_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1"));
        $this->assertSame("Edge on Windows", sessions::describeAgent("Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/149.0 Safari/537.36 Edg/149.0"));
        $this->assertSame("Unknown device", sessions::describeAgent(""));
    }

    public function testPasswordRules(): void
    {
        $this->assertNotNull(registration::validatePassword("short"));
        $this->assertNotNull(registration::validatePassword(str_repeat("a", 73)));
        $this->assertNull(registration::validatePassword("long enough"));
    }

    public function testNotificationText(): void
    {
        [$icon, $text, $link] = notifications::describe("friend_request", [], "Sam");
        $this->assertSame("ph-user-plus", $icon);
        $this->assertSame("Sam sent you a friend request.", $text);
        $this->assertSame("/users/sam", $link);

        [, $text, $link] = notifications::describe("mention", ["game"=>"Slope", "gameid"=>3, "type"=>"game"], "alex");
        $this->assertSame("alex mentioned you in a comment on Slope.", $text);
        $this->assertSame("/item/3#comments", $link);

        [, , $link] = notifications::describe("request_added", ["name"=>"Calc", "gameid"=>9, "type"=>"app"], null);
        $this->assertSame("/apps/9", $link);

        [, $text] = notifications::describe("something_new", [], null);
        $this->assertSame("Something happened.", $text);
    }
}
