<?php

namespace watrlabs\games;

use watrlabs\social\notifications;
use watrlabs\social\achievements;
use watrlabs\social\friends;
use watrlabs\watrkit\ratelimit;

// leaderboards. games send scores through /assets/js/watr-sdk.js -> play.js -> POST /api/v1/play/{id}/score.
//
// a score is only as honest as the browser that sent it, anyone with devtools can send a big number. what this
// does about it: scores only count while the game has been open for you lately (playtime last_played), one per
// 5 seconds per game, never above the game's score_max, and admins can wipe someone's scores
class scores {

    const BOARD_SIZE = 25;

    // "2026-41", the ISO week, which starts on monday
    static function week(?int $time = null): string {
        return date("o-W", $time ?? time());
    }

    static function enabled($game): bool {
        return $game && in_array($game->scores ?? "off", ["high", "low"], true);
    }

    private static function lowWins($game): bool {
        return ($game->scores ?? "high") === "low";
    }

    // 1234 -> "1,234 points". times are milliseconds -> "1:23.456" (or "1:02:03.4" past an hour)
    static function format(int $score, $game = null): string {
        if($game && ($game->score_format ?? "number") === "time"){
            $ms = max(0, $score);
            $hours = intdiv($ms, 3600000);
            $minutes = intdiv($ms % 3600000, 60000);
            $seconds = intdiv($ms % 60000, 1000);
            $rest = $ms % 1000;
            if($hours){
                return sprintf("%d:%02d:%02d.%d", $hours, $minutes, $seconds, intdiv($rest, 100));
            }
            return sprintf("%d:%02d.%03d", $minutes, $seconds, $rest);
        }

        $text = number_format($score);
        $label = trim((string) ($game->score_label ?? ""));
        return $label !== "" ? "$text $label" : $text;
    }

    // checks a score before it's saved. the message is shown to the player
    static function validate($game, $value): int {
        if(!self::enabled($game)){
            throw new \InvalidArgumentException("This game doesn't have a leaderboard.");
        }
        // 1234, 1234.0 and "1234" are fine, 12.5 and "12abc" aren't
        $whole = is_int($value)
            || (is_float($value) && floor($value) === $value && abs($value) < 1e15)
            || (is_string($value) && preg_match('/^-?\d{1,15}$/', $value));
        if(!$whole){
            throw new \InvalidArgumentException("Scores have to be whole numbers.");
        }

        $score = (int) $value;
        if($score < 0){
            throw new \InvalidArgumentException("Scores can't be negative.");
        }
        if($game->score_format === "time" && $score === 0){
            throw new \InvalidArgumentException("A time of zero doesn't count.");
        }
        if($game->score_max !== null && $score > (int) $game->score_max){
            throw new \InvalidArgumentException("That score is higher than this game allows.");
        }

        return $score;
    }

    // saves it if it's your best (all time and this week) and says how it went
    public function submit($user, $game, $value): array {
        global $db;

        $score = self::validate($game, $value);
        $userId = (int) $user->id;
        $gameId = (int) $game->id;

        // opening the game page (playtime::start) and every heartbeat touch last_played. playing_game_id only
        // gets set on the first heartbeat, ~15s in, which is too late for a quick game over
        $played = $db->table("playtime")->select(["last_played"])->where("userid", $userId)->where("gameid", $gameId)->first();
        if(!$played || (int) $played->last_played < time() - 3 * 3600){
            throw new \InvalidArgumentException("Scores only count while the game is open.");
        }

        if(!ratelimit::hit("score:" . $gameId, "u" . $userId, 1, 5)){
            throw new \InvalidArgumentException("Scores are coming in too fast.");
        }

        $previous = $this->best($userId, $gameId);
        $better = self::lowWins($game) ? "<" : ">";
        $now = time();

        foreach(["all", self::week($now)] as $period){
            // created goes first: mysql applies these left to right, so score has to change after it's compared
            $db->query(
                "INSERT INTO game_scores (gameid, userid, period, score, created) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE created = IF(VALUES(score) $better score, VALUES(created), created),
                                         score = IF(VALUES(score) $better score, VALUES(score), score)",
                [$gameId, $userId, $period, $score, $now]
            );
        }

        $best = $this->best($userId, $gameId);
        $personalBest = $previous === null || $best !== $previous;
        $rank = $this->rankOf($game, $userId, "all");

        if($personalBest){
            $this->checkChallenges($user, $game, $best);
            if($rank === 1 && $this->players($gameId) >= 3){
                achievements::award($userId, "top_score");
            }
        }

        return [
            "score"=>$score,
            "best"=>$best,
            "personalBest"=>$personalBest,
            "rank"=>$rank,
            "weekRank"=>$this->rankOf($game, $userId, self::week($now)),
            "text"=>self::format($score, $game),
            "bestText"=>self::format((int) $best, $game),
        ];
    }

    public function best(int $userId, int $gameId, string $period = "all"): ?int {
        global $db;

        $row = $db->table("game_scores")->select(["score"])->where("gameid", $gameId)->where("userid", $userId)->where("period", $period)->first();
        return $row ? (int) $row->score : null;
    }

    public function players(int $gameId, string $period = "all"): int {
        global $db;

        return (int) $db->query(
            "SELECT COUNT(*) AS n FROM game_scores s INNER JOIN users u ON u.id = s.userid WHERE s.gameid = ? AND s.period = ? AND u.banned = 0",
            [$gameId, $period]
        )->first()->n;
    }

    // 1 = the best. ties share a place
    public function rankOf($game, int $userId, string $period = "all"): ?int {
        global $db;

        $mine = $this->best($userId, (int) $game->id, $period);
        if($mine === null){
            return null;
        }

        $better = self::lowWins($game) ? "<" : ">";
        return 1 + (int) $db->query(
            "SELECT COUNT(*) AS n FROM game_scores s INNER JOIN users u ON u.id = s.userid
             WHERE s.gameid = ? AND s.period = ? AND u.banned = 0 AND s.score $better ?",
            [(int) $game->id, $period, $mine]
        )->first()->n;
    }

    // period: "all", "week", or "friends" (all time, you and your friends)
    public function board($game, string $period, $viewer = null, int $limit = self::BOARD_SIZE): array {
        global $db;

        $order = self::lowWins($game) ? "ASC" : "DESC";
        $sql = "SELECT s.userid, s.score, s.created, u.username, u.avatar, u.level FROM game_scores s
                INNER JOIN users u ON u.id = s.userid
                WHERE s.gameid = ? AND s.period = ? AND u.banned = 0";
        $params = [(int) $game->id, $period === "week" ? self::week() : "all"];

        if($period === "friends"){
            if(!$viewer){
                return ["rows"=>[], "me"=>null];
            }
            $ids = array_map(fn($f) => (int) $f["id"], (new friends())->list((int) $viewer->id));
            $ids[] = (int) $viewer->id;
            $sql .= " AND s.userid IN (" . implode(",", $ids) . ")";
        }

        $rows = $db->query($sql . " ORDER BY s.score $order, s.created ASC LIMIT " . max(1, $limit), $params)->get();

        // places, with ties sharing one
        $out = [];
        $place = 0;
        $last = null;
        foreach($rows as $i => $row){
            if($row->score !== $last){
                $place = $i + 1;
                $last = $row->score;
            }
            $out[] = [
                "rank"=>$place,
                "userid"=>(int) $row->userid,
                "username"=>$row->username,
                "avatar"=>$row->avatar,
                "level"=>(int) $row->level,
                "score"=>(int) $row->score,
                "text"=>self::format((int) $row->score, $game),
                "when"=>(int) $row->created,
                "me"=>$viewer && (int) $viewer->id === (int) $row->userid,
            ];
        }

        // you, even when you're not in the top 25
        $me = null;
        if($viewer && $period !== "friends"){
            $p = $period === "week" ? self::week() : "all";
            $best = $this->best((int) $viewer->id, (int) $game->id, $p);
            if($best !== null){
                $me = ["rank"=>$this->rankOf($game, (int) $viewer->id, $p), "score"=>$best, "text"=>self::format($best, $game)];
            }
        }

        return ["rows"=>$out, "me"=>$me];
    }

    // ---------- challenges ----------

    // "beat my score": your all time best, sent to a friend
    public function challenge($user, $game, int $friendId){
        global $db;

        \watrlabs\users\moderation::requireUnmuted($user);

        if(!self::enabled($game)){
            throw new \InvalidArgumentException("This game doesn't have a leaderboard.");
        }
        if((new friends())->relation((int) $user->id, $friendId) !== "friends"){
            throw new \InvalidArgumentException("You can only challenge friends.");
        }

        $best = $this->best((int) $user->id, (int) $game->id);
        if($best === null){
            throw new \InvalidArgumentException("Get a score first, then challenge someone to beat it.");
        }

        if($db->table("challenges")->where("from_id", $user->id)->where("created", ">", time() - 3600)->count() >= 20){
            throw new \InvalidArgumentException("That's a lot of challenges. Try again in a bit.");
        }

        // one open challenge per friend per game, a new one replaces it
        $db->table("challenges")->where("from_id", $user->id)->where("to_id", $friendId)->where("gameid", $game->id)->whereNull("beaten_at")->delete();
        $db->table("challenges")->insert([
            "from_id"=>(int) $user->id,
            "to_id"=>$friendId,
            "gameid"=>(int) $game->id,
            "score"=>$best,
            "created"=>time(),
        ]);

        notifications::send($friendId, "challenge", (int) $user->id, [
            "game"=>$game->name, "gameid"=>(int) $game->id, "type"=>$game->type, "score"=>self::format($best, $game),
        ]);
    }

    // open challenges for this player on this game that their new best beats
    private function checkChallenges($user, $game, int $best){
        global $db;

        $better = self::lowWins($game) ? ">" : "<";
        $open = $db->query(
            "SELECT * FROM challenges WHERE to_id = ? AND gameid = ? AND beaten_at IS NULL AND score $better ?",
            [(int) $user->id, (int) $game->id, $best]
        )->get();

        foreach($open as $challenge){
            $db->table("challenges")->where("id", $challenge->id)->update(["beaten_at"=>time()]);
            notifications::send((int) $challenge->from_id, "challenge_beaten", (int) $user->id, [
                "game"=>$game->name, "gameid"=>(int) $game->id, "type"=>$game->type,
                "score"=>self::format((int) $challenge->score, $game), "theirs"=>self::format($best, $game),
            ]);
        }
    }

    // challenges waiting for you on this game, for the play page
    public function openChallenges(int $userId, $game): array {
        global $db;

        $rows = $db->query(
            "SELECT c.score, c.created, u.username FROM challenges c INNER JOIN users u ON u.id = c.from_id
             WHERE c.to_id = ? AND c.gameid = ? AND c.beaten_at IS NULL AND u.banned = 0 ORDER BY c.id DESC LIMIT 5",
            [$userId, (int) $game->id]
        )->get();

        return array_map(fn($r) => ["username"=>$r->username, "text"=>self::format((int) $r->score, $game), "created"=>(int) $r->created], $rows);
    }

    // admin: every score this person has
    static function wipeUser(int $userId){
        global $db;

        $db->table("game_scores")->where("userid", $userId)->delete();
        $db->table("challenges")->where("from_id", $userId)->delete();
    }
}
