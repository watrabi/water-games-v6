<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BatchTwo extends AbstractMigration
{
    // every step checks first, so a run that stopped halfway (mysql can't roll back table changes) can pick up again
    public function change(): void
    {
        // streaks, levels, the weekly recap, timed bans and mutes
        if(!$this->table('users')->hasColumn('streak')){
            $this->table('users')
            ->addColumn('streak', 'integer', ['default' => 0])
            ->addColumn('streak_best', 'integer', ['default' => 0])
            ->addColumn('streak_day', 'date', ['null' => true]) // the last day that counted
            ->addColumn('xp', 'integer', ['default' => 0])
            ->addColumn('level', 'integer', ['default' => 1])
            ->addColumn('recap_week', 'string', ['limit' => 8, 'null' => true]) // "2026-41", the last recap delivered
            ->addColumn('recap_email', 'boolean', ['default' => false])
            ->addColumn('banned_until', 'integer', ['null' => true]) // null with banned = 1 means forever
            ->addColumn('ban_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('muted_until', 'integer', ['null' => true])
            ->addColumn('mute_reason', 'string', ['limit' => 255, 'null' => true])
            ->update();
        }

        // scores (off | high | low is better), controls and screenshots
        if(!$this->table('games')->hasColumn('scores')){
            $this->table('games')
            ->addColumn('scores', 'string', ['limit' => 4, 'default' => 'off'])
            ->addColumn('score_label', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('score_format', 'string', ['limit' => 8, 'default' => 'number']) // number | time (ms)
            ->addColumn('score_max', 'biginteger', ['null' => true])
            ->addColumn('controls', 'text', ['null' => true])
            ->addColumn('screenshots', 'text', ['null' => true]) // json list of upload paths
            ->update();
        }

        // best score per game, person and period ("all" or an ISO week like "2026-41")
        if(!$this->hasTable('game_scores')){
            $this->table('game_scores')
            ->addColumn('gameid', 'integer')
            ->addColumn('userid', 'integer')
            ->addColumn('period', 'string', ['limit' => 8])
            ->addColumn('score', 'biginteger')
            ->addColumn('created', 'integer') // when this best was set
            ->addIndex(['gameid', 'userid', 'period'], ['unique' => true])
            ->addIndex(['gameid', 'period', 'score'])
            ->create();
        }

        if(!$this->hasTable('challenges')){
            $this->table('challenges')
            ->addColumn('from_id', 'integer')
            ->addColumn('to_id', 'integer')
            ->addColumn('gameid', 'integer')
            ->addColumn('score', 'biginteger')
            ->addColumn('created', 'integer')
            ->addColumn('beaten_at', 'integer', ['null' => true])
            ->addIndex(['to_id', 'gameid', 'beaten_at'])
            ->addIndex(['from_id', 'created'])
            ->create();
        }

        // playtime per person per game per day: streaks, the weekly recap
        if(!$this->hasTable('user_game_daily')){
            $this->table('user_game_daily')
            ->addColumn('userid', 'integer')
            ->addColumn('gameid', 'integer')
            ->addColumn('day', 'date')
            ->addColumn('seconds', 'integer', ['default' => 0])
            ->addIndex(['userid', 'gameid', 'day'], ['unique' => true])
            ->addIndex(['userid', 'day'])
            ->create();
        }

        if(!$this->hasTable('collections')){
            $this->table('collections')
            ->addColumn('userid', 'integer')
            ->addColumn('name', 'string', ['limit' => 60])
            ->addColumn('description', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('public', 'boolean', ['default' => true])
            ->addColumn('staff', 'boolean', ['default' => false]) // "Staff picks", only admins can set it
            ->addColumn('created', 'integer')
            ->addColumn('updated', 'integer')
            ->addIndex(['userid'])
            ->addIndex(['staff', 'public'])
            ->create();
        }

        if(!$this->hasTable('collection_games')){
            $this->table('collection_games')
            ->addColumn('collectionid', 'integer')
            ->addColumn('gameid', 'integer')
            ->addColumn('position', 'integer', ['default' => 0])
            ->addColumn('added', 'integer')
            ->addIndex(['collectionid', 'gameid'], ['unique' => true])
            ->addIndex(['gameid'])
            ->create();
        }

        // emoji reactions. kind: dm | group | comment
        if(!$this->hasTable('reactions')){
            $this->table('reactions')
            ->addColumn('kind', 'string', ['limit' => 8])
            ->addColumn('item_id', 'integer')
            ->addColumn('userid', 'integer')
            ->addColumn('emoji', 'string', ['limit' => 16])
            ->addColumn('created', 'integer')
            ->addIndex(['kind', 'item_id', 'userid', 'emoji'], ['unique' => true])
            ->create();
        }

        // edits: when, plus what it said before (so a reported message can't be edited away)
        foreach(['chat_messages', 'chat_group_messages', 'game_comments'] as $table){
            if(!$this->table($table)->hasColumn('edited')){
                $this->table($table)->addColumn('edited', 'integer', ['null' => true])->update();
            }
        }

        if(!$this->hasTable('message_edits')){
            $this->table('message_edits')
            ->addColumn('kind', 'string', ['limit' => 8])
            ->addColumn('item_id', 'integer')
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('created', 'integer')
            ->addIndex(['kind', 'item_id'])
            ->create();
        }

        // fixed window counters, bucket is "action:ip..." or "action:u12"
        if(!$this->hasTable('rate_limits')){
            $this->table('rate_limits', ['id' => false, 'primary_key' => ['bucket']])
            ->addColumn('bucket', 'string', ['limit' => 120, 'null' => false]) // mysql 8 wants primary key parts spelled out as not null
            ->addColumn('hits', 'integer', ['default' => 0])
            ->addColumn('reset', 'integer')
            ->addIndex(['reset'])
            ->create();
        }

        // bans, mutes and lifting them, with who and why
        if(!$this->hasTable('moderation_log')){
            $this->table('moderation_log')
            ->addColumn('userid', 'integer')
            ->addColumn('action', 'string', ['limit' => 12]) // ban | unban | mute | unmute
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('until', 'integer', ['null' => true])
            ->addColumn('by_id', 'integer', ['null' => true])
            ->addColumn('created', 'integer')
            ->addIndex(['userid', 'created'])
            ->create();
        }
    }
}
