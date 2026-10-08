<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SocialThree extends AbstractMigration
{
    // every step checks first, so a run that stopped halfway can pick up again
    public function change(): void
    {
        // "grinding slope" next to your name in friends' lists
        if(!$this->table('users')->hasColumn('status_text')){
            $this->table('users')
            ->addColumn('status_text', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('status_until', 'integer', ['null' => true]) // null = until you clear it
            ->update();
        }

        // a game shared into a chat shows as a card
        if(!$this->table('chat_messages')->hasColumn('game_id')){
            $this->table('chat_messages')
            ->addColumn('game_id', 'integer', ['null' => true])
            ->update();
        }

        if(!$this->table('chat_group_messages')->hasColumn('game_id')){
            $this->table('chat_group_messages')
            ->addColumn('game_id', 'integer', ['null' => true])
            ->update();
        }

        // "friends who played this" looks playtime up by game
        if(!$this->table('playtime')->hasIndex(['gameid', 'userid'])){
            $this->table('playtime')
            ->addIndex(['gameid', 'userid'])
            ->update();
        }
    }
}
