<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

// apps live in the games table too, they're just type = 'app'
final class AddTypeToGames extends AbstractMigration
{
    public function change(): void
    {
        $games = $this->table('games');
        $games->addColumn('type', 'string', ['limit' => 10, 'default' => 'game'])
        ->addIndex(['type'])
        ->update();
    }
}
