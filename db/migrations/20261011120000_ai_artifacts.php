<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AiArtifacts extends AbstractMigration
{
    public function change(): void
    {
        // things the ai made (pages, svgs, docs, code). ref is the id the model gave it, unique per chat
        $this->table('ai_artifacts')
        ->addColumn('userid', 'integer')
        ->addColumn('chatid', 'integer')
        ->addColumn('ref', 'string', ['limit' => 64])
        ->addColumn('title', 'string', ['limit' => 120])
        ->addColumn('type', 'string', ['limit' => 12]) // html | svg | markdown | code
        ->addColumn('language', 'string', ['limit' => 30, 'null' => true])
        ->addColumn('latest', 'integer', ['default' => 1])
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['chatid', 'ref'], ['unique' => true])
        ->addIndex(['userid', 'updated'])
        ->create();

        // every time the model writes it again with the same ref is a new version
        $this->table('ai_artifact_versions')
        ->addColumn('artifactid', 'integer')
        ->addColumn('version', 'integer')
        ->addColumn('content', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
        ->addColumn('messageid', 'integer', ['null' => true])
        ->addColumn('created', 'integer')
        ->addIndex(['artifactid', 'version'], ['unique' => true])
        ->addIndex(['messageid'])
        ->create();
    }
}
