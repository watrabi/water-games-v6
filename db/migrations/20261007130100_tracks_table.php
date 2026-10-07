<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TracksTable extends AbstractMigration
{
    public function change(): void
    {
        $tracks = $this->table('tracks');
        $tracks->addColumn('title', 'string', ['limit' => 255])
        ->addColumn('artist', 'string', ['limit' => 255, 'null' => true])
        ->addColumn('filePath', 'text')
        ->addColumn('coverPath', 'text', ['null' => true])
        ->addColumn('duration', 'integer', ['null' => true]) // seconds, optional
        ->addColumn('plays', 'biginteger', ['default' => 0])
        ->create();
    }
}
