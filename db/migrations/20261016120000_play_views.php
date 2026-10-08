<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PlayViews extends AbstractMigration
{
    public function change(): void
    {
        // who's been counted for what lately, so refreshing a game (or replaying a song) over and over only
        // counts once per window. viewer is "u<user id>", or "ip" + an HMAC of the address for guests
        $this->table('play_views')
        ->addColumn('kind', 'string', ['limit' => 8])
        ->addColumn('item_id', 'integer')
        ->addColumn('viewer', 'string', ['limit' => 70])
        ->addColumn('last', 'integer')
        ->addIndex(['kind', 'item_id', 'viewer'], ['unique' => true])
        ->addIndex(['last'])
        ->create();
    }
}
