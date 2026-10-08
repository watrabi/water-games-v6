<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AiMemories extends AbstractMigration
{
    public function change(): void
    {
        // short notes the ai keeps about each person between chats
        $this->table('ai_memories')
        ->addColumn('userid', 'integer')
        ->addColumn('content', 'string', ['limit' => 300])
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['userid', 'updated'])
        ->create();

        // people can switch memory off for themselves
        $this->table('users')
        ->addColumn('ai_memory', 'boolean', ['default' => true])
        ->update();
    }
}
