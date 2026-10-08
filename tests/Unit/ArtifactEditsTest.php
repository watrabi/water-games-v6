<?php

use PHPUnit\Framework\TestCase;
use watrlabs\ai\artifacts;

final class ArtifactEditsTest extends TestCase
{
    private const PAGE = "<!doctype html>\n<html>\n<body>\n    <h1>Snake</h1>\n    <canvas id=\"c\"></canvas>\n    <script>\n        let speed = 5;\n    </script>\n</body>\n</html>";

    private static function edit(string ...$pairs): string
    {
        $body = "";
        for($i = 0; $i < count($pairs); $i += 2){
            $body .= "<<<<<<< SEARCH\n" . $pairs[$i] . "\n=======\n" . $pairs[$i + 1] . "\n>>>>>>> REPLACE\n";
        }
        return $body;
    }

    public function testParsesSeveralBlocksIncludingAnEmptyReplace(): void
    {
        $edits = artifacts::parseEdits(self::edit("let speed = 5;", "let speed = 8;", "<h1>Snake</h1>", "") . "trailing words");

        $this->assertSame([["let speed = 5;", "let speed = 8;"], ["<h1>Snake</h1>", ""]], $edits);
    }

    public function testParsesWindowsLineEndings(): void
    {
        $edits = artifacts::parseEdits(str_replace("\n", "\r\n", self::edit("a\nb", "c")));

        $this->assertSame([["a\nb", "c"]], $edits);
    }

    public function testAppliesEditsInOrder(): void
    {
        [$content, $error] = artifacts::applyEdits(self::PAGE, artifacts::parseEdits(self::edit(
            "        let speed = 5;", "        let speed = 8;\n        let score = 0;",
            "let score = 0;", "let score = 10;"
        )));

        $this->assertNull($error);
        $this->assertStringContainsString("let speed = 8;\n        let score = 10;\n    </script>", $content);
    }

    public function testMatchesWhenOnlyIndentationIsDifferent(): void
    {
        [$content, $error] = artifacts::applyEdits(self::PAGE, artifacts::parseEdits(self::edit(
            "<h1>Snake</h1>\n<canvas id=\"c\"></canvas>", "    <h1>Snake!</h1>\n    <canvas id=\"c\"></canvas>"
        )));

        $this->assertNull($error);
        $this->assertStringContainsString("<body>\n    <h1>Snake!</h1>\n    <canvas", $content);
    }

    public function testEmptyReplaceDeletesTheLines(): void
    {
        [$content, $error] = artifacts::applyEdits("one\ntwo\nthree", [["two\n", ""]]);

        $this->assertNull($error);
        $this->assertSame("one\nthree", $content);
    }

    public function testNothingChangesWhenOneEditMisses(): void
    {
        [$content, $error] = artifacts::applyEdits(self::PAGE, artifacts::parseEdits(self::edit(
            "let speed = 5;", "let speed = 8;",
            "let lives = 3;", "let lives = 5;"
        )));

        $this->assertNull($content);
        $this->assertStringContainsString("edit 2", $error);
        $this->assertStringContainsString("wasn't found", $error);
    }

    public function testAmbiguousSearchIsRefused(): void
    {
        [$content, $error] = artifacts::applyEdits("x = 1\ny = 2\nx = 1", [["x = 1", "x = 3"]]);

        $this->assertNull($content);
        $this->assertStringContainsString("matches 2 places", $error);
    }

    public function testEmptySearchAndNoBlocksAreRefused(): void
    {
        $this->assertStringContainsString("empty SEARCH", artifacts::applyEdits("a", [["", "b"]])[1]);
        $this->assertStringContainsString("no SEARCH/REPLACE blocks", artifacts::applyEdits("a", [])[1]);
    }

    public function testFeedbackCarriesTheCurrentVersion(): void
    {
        $text = artifacts::feedback([["ref"=>"snake", "title"=>"Snake", "message"=>"edit 1's SEARCH wasn't found.", "version"=>3, "content"=>"the page"]]);

        $this->assertStringContainsString("\"snake\": edit 1's SEARCH wasn't found.", $text);
        $this->assertStringContainsString("<current_version id=\"snake\">\nthe page\n</current_version>", $text);
        $this->assertStringContainsString("(v3)", $text);
    }
}
