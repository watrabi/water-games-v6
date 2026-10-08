<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PlaySocialAccounts extends AbstractMigration
{
    public function change(): void
    {
        // ---------- accounts ----------

        $this->table('users')
        ->addColumn('totp_secret', 'string', ['limit' => 255, 'null' => true]) // encrypted (iv + tag + secret, base64)
        ->addColumn('totp_enabled', 'boolean', ['default' => false])
        ->addColumn('totp_last_step', 'integer', ['null' => true]) // stops a code being used twice
        ->addColumn('recovery_codes', 'text', ['null' => true]) // json list of hashes
        ->addColumn('playing_game_id', 'integer', ['null' => true]) // "Playing Slope" in the chat tray
        ->addColumn('playing_since', 'integer', ['null' => true])
        ->addColumn('play_beat', 'integer', ['null' => true]) // last playtime heartbeat, across every tab
        ->addColumn('share_activity', 'boolean', ['default' => true]) // friends see what you play and do
        ->update();

        // so people can see and end their own sessions
        $this->table('sessions')
        ->addColumn('created', 'integer', ['null' => true])
        ->addColumn('last_used', 'integer', ['null' => true])
        ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
        ->addIndex(['userid'])
        ->update();

        $this->table('password_resets')
        ->addColumn('userid', 'integer')
        ->addColumn('token_hash', 'string', ['limit' => 64])
        ->addColumn('created', 'integer')
        ->addColumn('expires', 'integer')
        ->addColumn('used', 'boolean', ['default' => false])
        ->addIndex(['token_hash'], ['unique' => true])
        ->addIndex(['userid', 'created'])
        ->create();

        // the step between a right password and a right 2FA code
        $this->table('login_challenges')
        ->addColumn('userid', 'integer')
        ->addColumn('token_hash', 'string', ['limit' => 64])
        ->addColumn('created', 'integer')
        ->addColumn('attempts', 'integer', ['default' => 0])
        ->addIndex(['token_hash'], ['unique' => true])
        ->create();

        // ---------- games ----------

        $this->table('games')
        ->addColumn('created', 'integer', ['null' => true])
        ->update();

        $this->table('tags')
        ->addColumn('slug', 'string', ['limit' => 40])
        ->addColumn('name', 'string', ['limit' => 40])
        ->addColumn('sort', 'integer', ['default' => 0])
        ->addIndex(['slug'], ['unique' => true])
        ->create();

        $this->table('game_tags')
        ->addColumn('gameid', 'integer')
        ->addColumn('tagid', 'integer')
        ->addIndex(['gameid', 'tagid'], ['unique' => true])
        ->addIndex(['tagid'])
        ->create();

        // thumbs up (1) or down (-1)
        $this->table('game_votes')
        ->addColumn('userid', 'integer')
        ->addColumn('gameid', 'integer')
        ->addColumn('vote', 'integer', ['limit' => 1])
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'gameid'], ['unique' => true])
        ->addIndex(['gameid'])
        ->create();

        // focused playtime per person per game
        $this->table('playtime')
        ->addColumn('userid', 'integer')
        ->addColumn('gameid', 'integer')
        ->addColumn('seconds', 'integer', ['default' => 0])
        ->addColumn('sessions', 'integer', ['default' => 0])
        ->addColumn('first_played', 'integer')
        ->addColumn('last_played', 'integer')
        ->addIndex(['userid', 'gameid'], ['unique' => true])
        ->addIndex(['userid', 'last_played'])
        ->addIndex(['gameid'])
        ->create();

        // per game per day, for "trending" and the admin charts
        $this->table('game_daily')
        ->addColumn('gameid', 'integer')
        ->addColumn('day', 'date')
        ->addColumn('plays', 'integer', ['default' => 0])
        ->addColumn('seconds', 'integer', ['default' => 0])
        ->addIndex(['gameid', 'day'], ['unique' => true])
        ->addIndex(['day'])
        ->create();

        // a game's localStorage, kept on the account so it follows you between devices
        $this->table('cloud_saves')
        ->addColumn('userid', 'integer')
        ->addColumn('gameid', 'integer')
        ->addColumn('data', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
        ->addColumn('bytes', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['userid', 'gameid'], ['unique' => true])
        ->create();

        // status: open | added | declined
        $this->table('game_requests')
        ->addColumn('userid', 'integer')
        ->addColumn('name', 'string', ['limit' => 100])
        ->addColumn('url', 'string', ['limit' => 500, 'null' => true])
        ->addColumn('notes', 'text', ['null' => true])
        ->addColumn('status', 'string', ['limit' => 12, 'default' => 'open'])
        ->addColumn('gameid', 'integer', ['null' => true])
        ->addColumn('created', 'integer')
        ->addColumn('handled_by', 'integer', ['null' => true])
        ->addColumn('handled_at', 'integer', ['null' => true])
        ->addIndex(['status', 'created'])
        ->addIndex(['userid', 'created'])
        ->create();

        // "this game is broken"
        $this->table('game_reports')
        ->addColumn('gameid', 'integer')
        ->addColumn('userid', 'integer')
        ->addColumn('reason', 'string', ['limit' => 30])
        ->addColumn('details', 'text', ['null' => true])
        ->addColumn('status', 'string', ['limit' => 12, 'default' => 'open'])
        ->addColumn('created', 'integer')
        ->addColumn('handled_by', 'integer', ['null' => true])
        ->addColumn('handled_at', 'integer', ['null' => true])
        ->addIndex(['status', 'created'])
        ->addIndex(['gameid'])
        ->create();

        // ---------- social ----------

        $this->table('notifications')
        ->addColumn('userid', 'integer')
        ->addColumn('type', 'string', ['limit' => 30])
        ->addColumn('actor_id', 'integer', ['null' => true])
        ->addColumn('data', 'text', ['null' => true]) // json
        ->addColumn('read_at', 'integer', ['null' => true])
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'read_at'])
        ->addIndex(['created'])
        ->create();

        $this->table('user_achievements')
        ->addColumn('userid', 'integer')
        ->addColumn('code', 'string', ['limit' => 40])
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'code'], ['unique' => true])
        ->create();

        // what friends see on their home page
        $this->table('activity')
        ->addColumn('userid', 'integer')
        ->addColumn('type', 'string', ['limit' => 30])
        ->addColumn('gameid', 'integer', ['null' => true])
        ->addColumn('data', 'text', ['null' => true])
        ->addColumn('created', 'integer')
        ->addIndex(['userid', 'created'])
        ->addIndex(['created'])
        ->create();

        $this->table('chat_groups')
        ->addColumn('name', 'string', ['limit' => 50])
        ->addColumn('owner_id', 'integer')
        ->addColumn('created', 'integer')
        ->create();

        $this->table('chat_group_members')
        ->addColumn('groupid', 'integer')
        ->addColumn('userid', 'integer')
        ->addColumn('joined', 'integer')
        ->addColumn('last_read', 'integer', ['default' => 0]) // id of the last message they've seen
        ->addIndex(['groupid', 'userid'], ['unique' => true])
        ->addIndex(['userid'])
        ->create();

        $this->table('chat_group_messages')
        ->addColumn('groupid', 'integer')
        ->addColumn('sender_id', 'integer') // 0 for "x joined" style notes
        ->addColumn('body', 'text', ['null' => true])
        ->addColumn('image_id', 'integer', ['null' => true])
        ->addColumn('created', 'integer')
        ->addColumn('deleted', 'boolean', ['default' => false])
        ->addIndex(['groupid', 'id'])
        ->create();

        // reports can be about a group message now
        $this->table('chat_reports')
        ->addColumn('kind', 'string', ['limit' => 8, 'default' => 'dm'])
        ->update();

        // ---------- music ----------

        $this->table('playlists')
        ->addColumn('userid', 'integer')
        ->addColumn('name', 'string', ['limit' => 60])
        ->addColumn('created', 'integer')
        ->addColumn('updated', 'integer')
        ->addIndex(['userid'])
        ->create();

        $this->table('playlist_tracks')
        ->addColumn('playlistid', 'integer')
        ->addColumn('trackid', 'integer')
        ->addColumn('position', 'integer')
        ->addColumn('added', 'integer')
        ->addIndex(['playlistid', 'position'])
        ->addIndex(['trackid'])
        ->create();

        // ---------- admin ----------

        $this->table('admin_log')
        ->addColumn('admin_id', 'integer')
        ->addColumn('action', 'string', ['limit' => 40])
        ->addColumn('target_type', 'string', ['limit' => 20, 'null' => true])
        ->addColumn('target_id', 'integer', ['null' => true])
        ->addColumn('details', 'string', ['limit' => 500, 'null' => true])
        ->addColumn('created', 'integer')
        ->addIndex(['created'])
        ->addIndex(['admin_id'])
        ->create();

        if($this->isMigratingUp()){
            // a starting set of categories, admins can rename or add more
            $tags = ["action"=>"Action", "puzzle"=>"Puzzle", "racing"=>"Racing", "sports"=>"Sports", "shooter"=>"Shooter",
                "platformer"=>"Platformer", "strategy"=>"Strategy", "idle"=>"Idle", "two-player"=>"2 player", "classic"=>"Classic"];
            $rows = [];
            $sort = 0;
            foreach($tags as $slug => $name){
                $rows[] = ["slug"=>$slug, "name"=>$name, "sort"=>$sort++];
            }
            $this->table('tags')->insert($rows)->saveData();
        }
    }
}
