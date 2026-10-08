<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class GameComments extends AbstractMigration
{
    public function change(): void
    {
        // comments under games and apps
        $this->table('game_comments')
        ->addColumn('gameid', 'integer')
        ->addColumn('userid', 'integer')
        ->addColumn('body', 'text')
        ->addColumn('created', 'integer')
        ->addColumn('deleted', 'boolean', ['default' => false]) // by its author or a moderator
        ->addIndex(['gameid', 'deleted'])
        ->addIndex(['userid', 'created'])
        ->create();

        // same shape as chat_reports
        $this->table('comment_reports')
        ->addColumn('comment_id', 'integer')
        ->addColumn('reporter_id', 'integer')
        ->addColumn('reason', 'string', ['limit' => 30])
        ->addColumn('details', 'text', ['null' => true])
        ->addColumn('status', 'string', ['limit' => 12, 'default' => 'open'])
        ->addColumn('created', 'integer')
        ->addColumn('handled_by', 'integer', ['null' => true])
        ->addColumn('handled_at', 'integer', ['null' => true])
        ->addColumn('action', 'string', ['limit' => 30, 'null' => true])
        ->addIndex(['status', 'created'])
        ->addIndex(['comment_id'])
        ->create();
    }
}
