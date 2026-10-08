<?php

require_once __DIR__ . '/DatabaseTestCase.php';

use watrlabs\ai\artifacts;
use watrlabs\ai\chats;

final class AiArtifactsTest extends DatabaseTestCase
{
    // one answer through prepare() and save(), the way the assistant does it
    private function answer(int $userId, int $chatId, string $text){
        $artifacts = new artifacts();
        [$blocks, $saves] = $artifacts->prepare($chatId, [["type"=>"text", "text"=>$text]]);
        $messageId = (int) (new chats())->addMessage($chatId, "assistant", $blocks, "test");
        $saved = $artifacts->save($userId, $chatId, $messageId, $saves);
        return [$blocks[0]["text"], $saved, $artifacts->errors];
    }

    public function testEditsMakeANewFullVersionAndMissesChangeNothing(): void
    {
        $user = $this->user("artifacts");
        $chatId = (int) (new chats())->create((int) $user->id, "Snake", "test");

        $this->answer((int) $user->id, $chatId, "Here you go\n<artifact id=\"snake\" type=\"html\" title=\"Snake\">\n<h1>Snake</h1>\n<p>speed 5</p>\n</artifact>");

        // an edit, then a second one later in the same answer that builds on it
        [$text, $saved, $errors] = $this->answer((int) $user->id, $chatId,
            "Faster:\n<artifact id=\"snake\" mode=\"edit\">\n<<<<<<< SEARCH\n<p>speed 5</p>\n=======\n<p>speed 8</p>\n>>>>>>> REPLACE\n</artifact>\n"
            . "<artifact id=\"snake\" mode=\"edit\">\n<<<<<<< SEARCH\n<h1>Snake</h1>\n=======\n<h1>Fast snake</h1>\n>>>>>>> REPLACE\n</artifact>");

        $this->assertSame([], $errors);
        $this->assertSame([2, 3], array_column($saved, "version"));
        $this->assertSame("<h1>Fast snake</h1>\n<p>speed 8</p>", $saved[1]["content"]);
        $this->assertStringContainsString('mode="edit" type="html" title="Snake" version="2" edits="1">', $text);

        $latest = (new artifacts())->find((int) $user->id, $chatId, "snake");
        $this->assertSame(3, $latest->version);
        $this->assertSame("<h1>Fast snake</h1>\n<p>speed 8</p>", $latest->content);

        // shown in the browser as the full version, while the model's copy keeps just the edit
        $expanded = (new artifacts())->expandEdits($chatId, $text);
        $this->assertStringContainsString("version=\"2\" edits=\"1\">\n<h1>Snake</h1>\n<p>speed 8</p>\n</artifact>", $expanded);
        $this->assertStringNotContainsString("SEARCH", $expanded);

        // a miss saves nothing and reports the current version
        [$text, $saved, $errors] = $this->answer((int) $user->id, $chatId,
            "<artifact id=\"snake\" mode=\"edit\">\n<<<<<<< SEARCH\n<p>speed 99</p>\n=======\n<p>x</p>\n>>>>>>> REPLACE\n</artifact>");

        $this->assertSame([], $saved);
        $this->assertCount(1, $errors);
        $this->assertSame(3, $errors[0]["version"]);
        $this->assertSame("<h1>Fast snake</h1>\n<p>speed 8</p>", $errors[0]["content"]);
        $this->assertStringContainsString('failed="1"', $text);
        $this->assertSame(3, (new artifacts())->find((int) $user->id, $chatId, "snake")->version);

        // and editing something that was never made says so
        [, , $errors] = $this->answer((int) $user->id, $chatId,
            "<artifact id=\"tetris\" mode=\"edit\">\n<<<<<<< SEARCH\na\n=======\nb\n>>>>>>> REPLACE\n</artifact>");
        $this->assertStringContainsString("no artifact with that id", $errors[0]["message"]);
        $this->assertNull($errors[0]["content"]);
    }
}
