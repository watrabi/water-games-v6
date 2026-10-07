<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FavoritesTable extends AbstractMigration
{
    public function change(): void
    {
        $favorites = $this->table('favorites');
        $favorites->addColumn('userid', 'integer')
        ->addColumn('gameid', 'integer')
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'gameid'], ['unique' => true])
        ->addIndex(['gameid'])
        ->create();
    }
}
