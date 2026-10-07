<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminThemesAiProviders extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('users');
        $users->addColumn('admin', 'boolean', ['default' => false])
        ->addColumn('banned', 'boolean', ['default' => false])
        ->addColumn('theme', 'string', ['limit' => 30, 'null' => true])
        ->update();

        $settings = $this->table('settings');
        $settings->addColumn('name', 'string', ['limit' => 64])
        ->addColumn('value', 'text', ['null' => true])
        ->addIndex(['name'], ['unique' => true])
        ->create();

        // ai providers + models added from the admin panel (the .env ones still work too)
        $providers = $this->table('ai_providers');
        $providers->addColumn('name', 'string', ['limit' => 80])
        ->addColumn('type', 'string', ['limit' => 20]) // ollama | anthropic | openai
        ->addColumn('base_url', 'string', ['limit' => 255])
        ->addColumn('api_key', 'text', ['null' => true]) // encrypted
        ->addColumn('options', 'text', ['null' => true]) // json
        ->addColumn('enabled', 'boolean', ['default' => true])
        ->addColumn('created', 'integer')
        ->create();

        $models = $this->table('ai_models');
        $models->addColumn('provider_id', 'integer')
        ->addColumn('name', 'string', ['limit' => 160])
        ->addColumn('label', 'string', ['limit' => 120])
        ->addColumn('vision', 'string', ['limit' => 8, 'default' => 'auto']) // auto | yes | no
        ->addColumn('tools', 'string', ['limit' => 8, 'default' => 'auto'])
        ->addColumn('enabled', 'boolean', ['default' => true])
        ->addColumn('sort', 'integer', ['default' => 0])
        ->addIndex(['provider_id'])
        ->create();
    }
}
