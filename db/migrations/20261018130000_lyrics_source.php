<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class LyricsSource extends AbstractMigration
{
    public function up(): void
    {
        // where a track's lyrics came from: "lrc.red" or "lrclib" (null = from before this, which was always lrclib)
        if(!$this->table('tracks')->hasColumn('lyricsSource')){
            $this->table('tracks')
            ->addColumn('lyricsSource', 'string', ['limit' => 16, 'null' => true])
            ->update();
        }

        // look every track up again on its next play, so they get lrc.red's word by word lyrics where it has them.
        // what's saved now stays until the new answer arrives
        $this->execute("UPDATE tracks SET lyricsChecked = NULL");
    }

    public function down(): void
    {
        $this->table('tracks')->removeColumn('lyricsSource')->update();
    }
}
