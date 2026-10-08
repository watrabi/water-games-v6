<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AiModelThink extends AbstractMigration
{
    public function change(): void
    {
        // whether a model thinks before answering: auto (its own default) | yes | no. ollama only for now
        if(!$this->table('ai_models')->hasColumn('think')){
            $this->table('ai_models')
            ->addColumn('think', 'string', ['limit' => 8, 'default' => 'auto', 'after' => 'tools'])
            ->update();
        }
    }
}
