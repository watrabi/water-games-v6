<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AiModelPrompt extends AbstractMigration
{
    public function change(): void
    {
        // how much instruction a model gets: auto (short for ollama, full for the rest) | full | short.
        // local models on a CPU spend most of a first reply just reading the full one
        if(!$this->table('ai_models')->hasColumn('prompt')){
            $this->table('ai_models')
            ->addColumn('prompt', 'string', ['limit' => 8, 'default' => 'auto', 'after' => 'think'])
            ->update();
        }
    }
}
