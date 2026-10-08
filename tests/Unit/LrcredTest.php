<?php

use PHPUnit\Framework\TestCase;
use watrlabs\music\lrcred;
use watrlabs\music\lyricsfile;

final class LrcredTest extends TestCase
{
    // trimmed from a real lrc.red file: split syllables, background vocals, an agent and a section
    private const TTML = <<<'XML'
<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttm="http://www.w3.org/ns/ttml#metadata" xmlns:lrc="http://lrc.red/lyric-ttml-internal" lrc:timing="Word" xml:lang="en"><head><metadata><ttm:agent type="person" xml:id="v1"/></metadata></head><body dur="3:21.570"><div begin="27.395" end="48.621" lrc:songPart="Verse"><p begin="30.189" end="32.529" ttm:agent="v1"><span begin="30.189" end="30.396">I've</span> <span begin="31.839" end="31.996">e</span><span begin="31.996" end="32.529">nough</span></p><p begin="1:27.395" end="1:28.960" ttm:agent="v1"><span begin="1:27.395" end="1:27.549">Know</span> <span ttm:role="x-bg"><span begin="1:28.000" end="1:28.500">(Back</span> <span begin="1:28.500" end="1:28.960">now)</span></span></p></div></body></tt>
XML;

    public function testReadsClockTimes(): void
    {
        $this->assertSame(27395, lrcred::clock("27.395"));
        $this->assertSame(141720, lrcred::clock("2:21.720"));
        $this->assertSame(3723500, lrcred::clock("1:02:03.5"));
        $this->assertSame(12500, lrcred::clock("12.5s"));
        $this->assertNull(lrcred::clock("soon"));
        $this->assertNull(lrcred::clock(""));
    }

    public function testTurnsTtmlIntoLinesAndWords(): void
    {
        $lines = lrcred::parseTtml(self::TTML);

        $this->assertCount(2, $lines);
        $this->assertSame("I've enough", $lines[0]["text"]);
        $this->assertSame(["I've ", "e", "nough"], array_column($lines[0]["words"], "text"), "syllables stay joined");
        $this->assertSame([30189, 32529], [$lines[0]["start"], $lines[0]["end"]]);

        $this->assertSame("Know (Back now)", $lines[1]["text"], "background vocals are part of the line");
        $this->assertSame(["Know ", "(Back ", "now)"], array_column($lines[1]["words"], "text"));
        $this->assertSame(87395, $lines[1]["start"]);
    }

    public function testLineTimedTtmlStillWorks(): void
    {
        $lines = lrcred::parseTtml('<tt xmlns="http://www.w3.org/ns/ttml"><body><div><p begin="1.0" end="2.0">First line</p><p begin="3.0" end="4.0">Second line</p></div></body></tt>');

        $this->assertSame(["First line", "Second line"], array_column($lines, "text"));
        $this->assertSame([], $lines[0]["words"]);
    }

    public function testBrokenOrEmptyTtmlGivesNothing(): void
    {
        $this->assertNull(lrcred::parseTtml("<tt><body>"));
        $this->assertNull(lrcred::parseTtml("not xml at all"));
        $this->assertNull(lrcred::parseTtml('<tt xmlns="http://www.w3.org/ns/ttml"><body><div><p begin="1.0">Only one</p></div></body></tt>'));
    }

    public function testConvertsToALyricsfileAndLrc(): void
    {
        $lines = lrcred::parseTtml(self::TTML);

        $file = lyricsfile::parse(lrcred::toLyricsfile($lines, "Song", "Singer", 201));
        $this->assertNotNull($file, "a valid Lyricsfile");
        $this->assertTrue($file["words"]);
        $this->assertSame(array_column($lines, "text"), array_column($file["lines"], "text"));
        $this->assertSame(array_column($lines[1]["words"], "start"), array_column($file["lines"][1]["words"], "start"));

        $this->assertSame("[00:30.18] I've enough\n[01:27.39] Know (Back now)", lrcred::toLrc($lines));
    }
}
