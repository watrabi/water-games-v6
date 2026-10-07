<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AiTables extends AbstractMigration
{
    public function change(): void
    {
        $chats = $this->table('ai_chats');
        $chats->addColumn('userid', 'integer')
        ->addColumn('title', 'string', ['limit' => 120])
        ->addColumn('model', 'string', ['limit' => 160])
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['userid', 'updated'])
        ->create();

        // content is a json array of blocks (text, image, tool_use, tool_result...)
        // visible = 0 for the rows that only carry tool results back to the model
        $messages = $this->table('ai_messages');
        $messages->addColumn('chatid', 'integer')
        ->addColumn('role', 'string', ['limit' => 16])
        ->addColumn('content', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
        ->addColumn('model', 'string', ['limit' => 160, 'null' => true])
        ->addColumn('visible', 'boolean', ['default' => true])
        ->addColumn('created', 'integer')
        ->addIndex(['chatid'])
        ->addIndex(['created'])
        ->create();

        $attachments = $this->table('ai_attachments');
        $attachments->addColumn('userid', 'integer')
        ->addColumn('path', 'string', ['limit' => 255])
        ->addColumn('mime', 'string', ['limit' => 40])
        ->addColumn('size', 'integer')
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'created'])
        ->create();
    }
}
