<?php

use PHPUnit\Framework\TestCase;

// every test runs in a transaction that's rolled back, so the database in .env is left as it was
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        global $db;

        if(!$db){
            $this->markTestSkipped("No database (check .env)");
        }

        try {
            $db->table("playtime")->limit(1)->get();
        } catch (\Throwable $e) {
            $this->markTestSkipped("Run the migrations first");
        }

        $db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        global $db;

        if($db && $db->pdo()->inTransaction()){
            $db->pdo()->rollBack();
        }
    }

    protected function user(string $name, array $extra = []){
        global $db;

        $id = $db->table("users")->insert($extra + [
            "username"=>$name . bin2hex(random_bytes(3)),
            "password"=>password_hash("password123", PASSWORD_BCRYPT, ["cost"=>4]),
            "registered"=>time(),
            "share_activity"=>1,
        ]);

        return $db->table("users")->where("id", $id)->first();
    }

    protected function befriend($a, $b){
        $friends = new \watrlabs\social\friends();
        $friends->act((int) $a->id, (int) $b->id, "request");
        $friends->act((int) $b->id, (int) $a->id, "accept");
    }

    protected function game(string $path = "/game-files/test/index.html"){
        global $db;

        return (int) $db->table("games")->insert(["name"=>"Test game " . bin2hex(random_bytes(3)), "type"=>"game", "gamePath"=>$path, "plays"=>0, "created"=>time()]);
    }
}
