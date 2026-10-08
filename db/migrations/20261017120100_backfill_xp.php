<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillXp extends AbstractMigration
{
    // people who played before levels existed start with what they'd earned: a point a minute and 50 per
    // achievement (days played can't be counted back, there's no per-day history from before)
    public function up(): void
    {
        $this->execute(
            "UPDATE users u SET u.xp =
                FLOOR(COALESCE((SELECT SUM(p.seconds) FROM playtime p WHERE p.userid = u.id), 0) / 60)
                + 50 * (SELECT COUNT(*) FROM user_achievements a WHERE a.userid = u.id)"
        );
        $this->execute("UPDATE users SET level = FLOOR(SQRT(xp / 25)) + 1");
    }

    public function down(): void
    {
        $this->execute("UPDATE users SET xp = 0, level = 1");
    }
}
