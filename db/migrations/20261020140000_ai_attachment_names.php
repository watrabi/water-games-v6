<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AiAttachmentNames extends AbstractMigration
{
    public function change(): void
    {
        // files the AI shared from its sandbox keep their name; uploads leave it empty
        if(!$this->table('ai_attachments')->hasColumn('name')){
            $this->table('ai_attachments')
            ->addColumn('name', 'string', ['limit' => 160, 'null' => true, 'after' => 'mime'])
            ->update();
        }
    }
}
