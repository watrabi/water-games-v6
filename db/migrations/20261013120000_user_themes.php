<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserThemes extends AbstractMigration
{
    public function change(): void
    {
        // color themes people made for themselves (the ai makes them on request). only their owner can use them
        $this->table('user_themes')
        ->addColumn('userid', 'integer')
        ->addColumn('name', 'string', ['limit' => 40])
        ->addColumn('input', 'text')   // the colors asked for, json
        ->addColumn('vars', 'text')    // the full set of css variables worked out from them, json
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['userid', 'updated'])
        ->create();
    }
}
