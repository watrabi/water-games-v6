<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserTracksLyricsAvatars extends AbstractMigration
{
    public function change(): void
    {
        // tracks people upload themselves (admin added ones have no uploader)
        // lyrics: LRC text when synced, plain text otherwise. lyricsChecked is when we last asked lrclib,
        // null means "never asked", so the first play looks them up
        $this->table('tracks')
        ->addColumn('uploader_id', 'integer', ['null' => true])
        ->addColumn('created', 'integer', ['null' => true])
        ->addColumn('lyrics', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
        ->addColumn('lyricsSynced', 'boolean', ['default' => false])
        ->addColumn('lyricsChecked', 'integer', ['null' => true])
        ->addIndex(['uploader_id', 'created'])
        ->update();

        $this->table('users')
        ->addColumn('avatar', 'string', ['limit' => 255, 'null' => true])
        ->update();
    }
}
