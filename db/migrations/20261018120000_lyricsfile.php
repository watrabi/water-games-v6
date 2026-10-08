<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Lyricsfile extends AbstractMigration
{
    public function up(): void
    {
        // the Lyricsfile (yaml) lrclib had for the track, with line end times and sometimes word timing
        if(!$this->table('tracks')->hasColumn('lyricsFile')){
            $this->table('tracks')
            ->addColumn('lyricsFile', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
            ->update();
        }

        // ask lrclib again on each track's next play, to pick up Lyricsfiles for songs looked up before this.
        // the lyrics already saved stay until the new answer comes back
        $this->execute("UPDATE tracks SET lyricsChecked = NULL");
    }

    public function down(): void
    {
        $this->table('tracks')->removeColumn('lyricsFile')->update();
    }
}
