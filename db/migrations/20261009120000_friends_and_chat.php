<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FriendsAndChat extends AbstractMigration
{
    public function change(): void
    {
        // for the green "online" dot
        $this->table('users')
        ->addColumn('last_seen', 'integer', ['null' => true])
        ->update();

        // one row per pair. status: pending (requester asked addressee) | accepted
        $this->table('friendships')
        ->addColumn('requester_id', 'integer')
        ->addColumn('addressee_id', 'integer')
        ->addColumn('status', 'string', ['limit' => 10, 'default' => 'pending'])
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['requester_id', 'addressee_id'], ['unique' => true])
        ->addIndex(['addressee_id', 'status'])
        ->create();

        $this->table('blocks')
        ->addColumn('blocker_id', 'integer')
        ->addColumn('blocked_id', 'integer')
        ->addColumn('created', 'integer')
        ->addIndex(['blocker_id', 'blocked_id'], ['unique' => true])
        ->addIndex(['blocked_id'])
        ->create();

        // direct messages. an image is an attachment id, the file lives in storage/private/chat
        $this->table('chat_messages')
        ->addColumn('sender_id', 'integer')
        ->addColumn('recipient_id', 'integer')
        ->addColumn('body', 'text', ['null' => true])
        ->addColumn('image_id', 'integer', ['null' => true])
        ->addColumn('created', 'integer')
        ->addColumn('read_at', 'integer', ['null' => true])
        ->addColumn('deleted', 'boolean', ['default' => false]) // removed by a moderator
        ->addIndex(['sender_id', 'recipient_id', 'id'])
        ->addIndex(['recipient_id', 'read_at'])
        ->create();

        $this->table('chat_images')
        ->addColumn('userid', 'integer')
        ->addColumn('path', 'string', ['limit' => 255])
        ->addColumn('mime', 'string', ['limit' => 40])
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'created'])
        ->create();

        // status: open | dismissed | actioned
        $this->table('chat_reports')
        ->addColumn('message_id', 'integer')
        ->addColumn('reporter_id', 'integer')
        ->addColumn('reason', 'string', ['limit' => 30])
        ->addColumn('details', 'text', ['null' => true])
        ->addColumn('status', 'string', ['limit' => 12, 'default' => 'open'])
        ->addColumn('created', 'integer')
        ->addColumn('handled_by', 'integer', ['null' => true])
        ->addColumn('handled_at', 'integer', ['null' => true])
        ->addColumn('action', 'string', ['limit' => 30, 'null' => true])
        ->addIndex(['status', 'created'])
        ->addIndex(['message_id', 'reporter_id'], ['unique' => true])
        ->create();
    }
}
