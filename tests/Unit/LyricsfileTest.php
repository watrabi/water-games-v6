<?php

use PHPUnit\Framework\TestCase;
use watrlabs\music\lyricsfile;

final class LyricsfileTest extends TestCase
{
    private const GOOD = <<<'YAML'
version: "1.0"
metadata:
  title: Test song
  artist: Someone
  duration_ms: 200000
lines:
  - text: Second line
    start_ms: 5000
  - text: Hello there
    start_ms: 1000
    end_ms: 3000
    words:
      - text: "there"
        start_ms: 2000
        end_ms: 3000
      - text: "Hello "
        start_ms: 1000
        end_ms: 2000
plain: |
  Hello there
  Second line
YAML;

    public function testReadsLinesWordsAndPlain(): void
    {
        $file = lyricsfile::parse(self::GOOD);

        $this->assertNotNull($file);
        $this->assertTrue($file["words"]);
        $this->assertSame(["Hello there", "Second line"], array_column($file["lines"], "text"), "sorted by start");
        $this->assertSame(3000, $file["lines"][0]["end"]);
        $this->assertNull($file["lines"][1]["end"], "a missing end stays missing, it isn't made up");
        $this->assertSame(["Hello ", "there"], array_column($file["lines"][0]["words"], "text"), "words sorted too");
        $this->assertSame("Hello there\nSecond line", $file["plain"]);
    }

    public function testLinesWithoutWordsAreFine(): void
    {
        $file = lyricsfile::parse("version: '1.0'\nmetadata:\n  title: A\n  artist: B\nlines:\n- text: Only line\n  start_ms: 150\n  end_ms: 7130\n");
        $this->assertFalse($file["words"]);
        $this->assertSame([], $file["lines"][0]["words"]);
    }

    public function testRejectsWhatTheSpecRulesOut(): void
    {
        $bad = [
            "unknown version" => str_replace('version: "1.0"', 'version: "2.0"', self::GOOD),
            "number version" => str_replace('version: "1.0"', 'version: 1.0', self::GOOD),
            "no metadata" => "version: '1.0'\nlines:\n- text: a\n  start_ms: 1\n",
            "end before start" => str_replace("end_ms: 3000\n    words", "end_ms: 500\n    words", self::GOOD),
            "negative start" => str_replace("start_ms: 5000", "start_ms: -5", self::GOOD),
            "fractional time" => str_replace("start_ms: 5000", "start_ms: 5000.5", self::GOOD),
            "text not a string" => str_replace("- text: Second line", "- text: [1, 2]", self::GOOD),
            "duplicate keys" => "version: '1.0'\nversion: '1.0'\nmetadata:\n  title: A\n  artist: B\nplain: hi\n",
            "custom tag" => "version: '1.0'\nmetadata: !php/object 'O:8:\"stdClass\":0:{}'\nplain: hi\n",
            "broken yaml" => "version: '1.0'\nmetadata: [unclosed\n",
            "nothing to show" => "version: '1.0'\nmetadata:\n  title: A\n  artist: B\n",
            "instrumental" => "version: '1.0'\nmetadata:\n  title: A\n  artist: B\n  instrumental: true\nplain: hi\n",
        ];

        foreach($bad as $why => $yaml){
            $this->assertNull(lyricsfile::parse($yaml), $why);
        }
        $this->assertNull(lyricsfile::parse(null));
        $this->assertNull(lyricsfile::parse(str_repeat("#", 600 * 1024)), "too big");
    }
}
