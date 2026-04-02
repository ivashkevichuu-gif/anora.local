<?php
/**
 * Property-based tests for Activity Monitor anti-fraud flag detection (Properties 2-5).
 *
 * Uses an in-memory SQLite database to simulate the required tables.
 * The query logic is inlined from activity_monitor.php, adapted for SQLite syntax.
 *
 * Feature: platform-observability, Properties 2-5: Anti-Fraud Flag Detection
 * Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ActivityMonitorFlagsPropertyTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                nickname TEXT DEFAULT NULL,
                is_bot INTEGER NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE device_fingerprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_id TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                canvas_hash TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE game_rounds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'waiting',
                winner_id INTEGER DEFAULT NULL,
                total_pot REAL NOT NULL DEFAULT 0.00,
                winner_net REAL DEFAULT NULL,
                commission REAL DEFAULT NULL,
                referral_bonus REAL DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (winner_id) REFERENCES users(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE game_bets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                round_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                client_seed TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (round_id) REFERENCES game_rounds(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
    }

    private function createUser(string $email, bool $isBot = false): int
    {
        $this->db->prepare("INSERT INTO users (email, is_bot) VALUES (?, ?)")
            ->execute([$email, $isBot ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // Property 2: Multi-Account IP Flag Detection
    // Feature: platform-observability, Property 2: Multi-Account IP Flag Detection
    //
    // For any set of device_fingerprints rows where 3+ distinct non-bot user IDs
    // share the same ip_address within last 7 days, the flag output should contain
    // at least one multi_account_ip flag.
    //
    // Validates: Requirements 2.1, 2.5
    // =========================================================================
    public function testProperty2_MultiAccountIpFlagDetection(): void
    {
        // Feature: platform-observability, Property 2: Multi-Account IP Flag Detection
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Reset tables
            $this->db->exec("DELETE FROM device_fingerprints");
            $this->db->exec("DELETE FROM users");

            // Create 3-6 non-bot users sharing the same IP
            $sharedIp = mt_rand(10, 200) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
            $numUsers = mt_rand(3, 6);
            $userIds  = [];

            for ($j = 0; $j < $numUsers; $j++) {
                $uid = $this->createUser("user{$j}_iter{$i}@test.com", false);
                $userIds[] = $uid;

                // Insert fingerprint within last 7 days
                $hoursAgo  = mt_rand(0, 6 * 24);
                $createdAt = date('Y-m-d H:i:s', strtotime("-{$hoursAgo} hours"));
                $this->db->prepare(
                    "INSERT INTO device_fingerprints (user_id, session_id, ip_address, user_agent, created_at)
                     VALUES (?, ?, ?, 'TestAgent', ?)"
                )->execute([$uid, bin2hex(random_bytes(8)), $sharedIp, $createdAt]);
            }

            // Also add a bot user on the same IP (should be excluded)
            $botId = $this->createUser("bot_iter{$i}@test.com", true);
            $this->db->prepare(
                "INSERT INTO device_fingerprints (user_id, session_id, ip_address, user_agent)
                 VALUES (?, ?, ?, 'BotAgent')"
            )->execute([$botId, bin2hex(random_bytes(8)), $sharedIp]);

            // Run the multi_account_ip detection query (adapted from activity_monitor.php for SQLite)
            $flags = $this->db->query(
                "SELECT df.ip_address, GROUP_CONCAT(DISTINCT df.user_id) AS user_ids,
                        GROUP_CONCAT(DISTINCT u.email) AS emails,
                        COUNT(DISTINCT df.user_id) AS user_count,
                        MAX(df.created_at) AS timestamp
                 FROM device_fingerprints df
                 JOIN users u ON u.id = df.user_id
                 WHERE df.created_at >= datetime('now', '-7 days')
                   AND u.is_bot = 0
                 GROUP BY df.ip_address
                 HAVING COUNT(DISTINCT df.user_id) >= 3"
            )->fetchAll();

            $foundFlag = false;
            foreach ($flags as $flag) {
                if ($flag['ip_address'] === $sharedIp) {
                    $foundFlag = true;
                    // Verify flag has required fields
                    if (empty($flag['user_ids']) || empty($flag['emails']) || empty($flag['timestamp'])) {
                        $failures[] = sprintf('iter=%d: multi_account_ip flag missing required fields', $i);
                    }
                    break;
                }
            }

            if (!$foundFlag) {
                $failures[] = sprintf(
                    'iter=%d: expected multi_account_ip flag for IP %s with %d users, but none found',
                    $i, $sharedIp, $numUsers
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 2 (Multi-Account IP Flag Detection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 3: Canvas Correlation Flag Detection
    // Feature: platform-observability, Property 3: Canvas Correlation Flag Detection
    //
    // For any set of device_fingerprints rows where 2+ distinct non-bot user IDs
    // share the same non-null canvas_hash, the flag output should contain at least
    // one canvas_correlation flag.
    //
    // Validates: Requirements 2.2, 2.5
    // =========================================================================
    public function testProperty3_CanvasCorrelationFlagDetection(): void
    {
        // Feature: platform-observability, Property 3: Canvas Correlation Flag Detection
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM device_fingerprints");
            $this->db->exec("DELETE FROM users");

            // Create 2-5 non-bot users sharing the same canvas_hash
            $sharedHash = md5('canvas_' . mt_rand(1, 99999) . '_' . $i);
            $numUsers   = mt_rand(2, 5);
            $userIds    = [];

            for ($j = 0; $j < $numUsers; $j++) {
                $uid = $this->createUser("canvas_user{$j}_iter{$i}@test.com", false);
                $userIds[] = $uid;

                $ip = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
                $this->db->prepare(
                    "INSERT INTO device_fingerprints (user_id, session_id, ip_address, user_agent, canvas_hash)
                     VALUES (?, ?, ?, 'TestAgent', ?)"
                )->execute([$uid, bin2hex(random_bytes(8)), $ip, $sharedHash]);
            }

            // Run the canvas_correlation detection query
            $flags = $this->db->query(
                "SELECT df.canvas_hash, GROUP_CONCAT(DISTINCT df.user_id) AS user_ids,
                        GROUP_CONCAT(DISTINCT u.email) AS emails,
                        COUNT(DISTINCT df.user_id) AS user_count,
                        MAX(df.created_at) AS timestamp
                 FROM device_fingerprints df
                 JOIN users u ON u.id = df.user_id
                 WHERE df.canvas_hash IS NOT NULL
                   AND u.is_bot = 0
                 GROUP BY df.canvas_hash
                 HAVING COUNT(DISTINCT df.user_id) >= 2"
            )->fetchAll();

            $foundFlag = false;
            foreach ($flags as $flag) {
                if ($flag['canvas_hash'] === $sharedHash) {
                    $foundFlag = true;
                    if (empty($flag['user_ids']) || empty($flag['emails']) || empty($flag['timestamp'])) {
                        $failures[] = sprintf('iter=%d: canvas_correlation flag missing required fields', $i);
                    }
                    break;
                }
            }

            if (!$foundFlag) {
                $failures[] = sprintf(
                    'iter=%d: expected canvas_correlation flag for hash %s with %d users, but none found',
                    $i, $sharedHash, $numUsers
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 3 (Canvas Correlation Flag Detection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 4: Anomalous Win Rate Flag Detection
    // Feature: platform-observability, Property 4: Anomalous Win Rate Flag Detection
    //
    // For any non-bot user whose win_count/rounds_participated > 40% over last
    // 100 rounds, the flag output should contain an anomalous_win_rate flag.
    //
    // Validates: Requirements 2.3, 2.5
    // =========================================================================
    public function testProperty4_AnomalousWinRateFlagDetection(): void
    {
        // Feature: platform-observability, Property 4: Anomalous Win Rate Flag Detection
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM game_bets");
            $this->db->exec("DELETE FROM game_rounds");
            $this->db->exec("DELETE FROM device_fingerprints");
            $this->db->exec("DELETE FROM users");

            // Create a non-bot user with a high win rate
            $targetUser = $this->createUser("highwin_iter{$i}@test.com", false);

            // Create some rounds (between 10 and 100)
            $totalRounds = mt_rand(10, 100);
            // Ensure win rate > 40%: wins must be > 40% of rounds participated
            $winCount = (int) ceil($totalRounds * 0.41) + mt_rand(0, 5);
            $winCount = min($winCount, $totalRounds);

            $roundIds = [];
            for ($r = 0; $r < $totalRounds; $r++) {
                $winnerId = ($r < $winCount) ? $targetUser : null;
                $this->db->prepare(
                    "INSERT INTO game_rounds (room, status, winner_id, total_pot, finished_at)
                     VALUES (1, 'finished', ?, 10.00, datetime('now', '-' || ? || ' hours'))"
                )->execute([$winnerId, $r]);
                $roundIds[] = (int) $this->db->lastInsertId();
            }

            // Place bets for the target user in all rounds
            foreach ($roundIds as $rid) {
                $this->db->prepare(
                    "INSERT INTO game_bets (round_id, user_id, amount) VALUES (?, ?, 1.00)"
                )->execute([$rid, $targetUser]);
            }

            // Run the anomalous_win_rate detection query (adapted for SQLite)
            // Uses the last 100 finished rounds approach
            $flags = $this->db->query(
                "SELECT sub.user_id, u.email, sub.win_count, sub.rounds_participated,
                        ROUND(CAST(sub.win_count AS REAL) / sub.rounds_participated * 100, 2) AS win_rate
                 FROM (
                     SELECT gb_inner.user_id,
                            COUNT(DISTINCT gb_inner.round_id) AS rounds_participated,
                            SUM(CASE WHEN gr_inner.winner_id = gb_inner.user_id THEN 1 ELSE 0 END) AS win_count
                     FROM game_bets gb_inner
                     JOIN game_rounds gr_inner ON gr_inner.id = gb_inner.round_id
                     WHERE gr_inner.status = 'finished'
                       AND gr_inner.id >= (SELECT COALESCE(MAX(id), 0) - 99 FROM game_rounds WHERE status = 'finished')
                     GROUP BY gb_inner.user_id
                 ) sub
                 JOIN users u ON u.id = sub.user_id
                 WHERE u.is_bot = 0
                   AND sub.rounds_participated > 0
                   AND (CAST(sub.win_count AS REAL) / sub.rounds_participated) > 0.40"
            )->fetchAll();

            $foundFlag = false;
            foreach ($flags as $flag) {
                if ((int) $flag['user_id'] === $targetUser) {
                    $foundFlag = true;
                    if (empty($flag['email'])) {
                        $failures[] = sprintf('iter=%d: anomalous_win_rate flag missing email', $i);
                    }
                    break;
                }
            }

            if (!$foundFlag) {
                $failures[] = sprintf(
                    'iter=%d: expected anomalous_win_rate flag for user_id=%d (wins=%d, rounds=%d, rate=%.1f%%)',
                    $i, $targetUser, $winCount, $totalRounds,
                    ($totalRounds > 0 ? $winCount / $totalRounds * 100 : 0)
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 4 (Anomalous Win Rate Flag Detection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 5: Rapid Bet Speed Flag Detection
    // Feature: platform-observability, Property 5: Rapid Bet Speed Flag Detection
    //
    // For any non-bot user with 10+ game_bets within any 10-second window,
    // the flag output should contain a rapid_bet_speed flag.
    //
    // Validates: Requirements 2.4, 2.5
    // =========================================================================
    public function testProperty5_RapidBetSpeedFlagDetection(): void
    {
        // Feature: platform-observability, Property 5: Rapid Bet Speed Flag Detection
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM game_bets");
            $this->db->exec("DELETE FROM game_rounds");
            $this->db->exec("DELETE FROM device_fingerprints");
            $this->db->exec("DELETE FROM users");

            // Create a non-bot user
            $targetUser = $this->createUser("rapid_iter{$i}@test.com", false);

            // Create a round for the bets
            $this->db->prepare(
                "INSERT INTO game_rounds (room, status, total_pot) VALUES (1, 'active', 100.00)"
            )->execute();
            $roundId = (int) $this->db->lastInsertId();

            // Place 10-15 bets within a 10-second window
            $numBets   = mt_rand(10, 15);
            $baseTime  = time() - mt_rand(60, 300); // some time in the recent past

            for ($b = 0; $b < $numBets; $b++) {
                // All bets within 0-9 seconds of base time
                $betTime = date('Y-m-d H:i:s', $baseTime + mt_rand(0, 9));
                $this->db->prepare(
                    "INSERT INTO game_bets (round_id, user_id, amount, created_at) VALUES (?, ?, 1.00, ?)"
                )->execute([$roundId, $targetUser, $betTime]);
            }

            // Run the rapid_bet_speed detection query (adapted for SQLite)
            // SQLite doesn't have DATE_ADD, so we use datetime() with seconds
            $flags = $this->db->query(
                "SELECT gb1.user_id, u.email,
                        COUNT(*) AS bet_count,
                        gb1.created_at AS window_start
                 FROM game_bets gb1
                 JOIN game_bets gb2 ON gb2.user_id = gb1.user_id
                   AND gb2.created_at >= gb1.created_at
                   AND gb2.created_at <= datetime(gb1.created_at, '+10 seconds')
                 JOIN users u ON u.id = gb1.user_id
                 WHERE u.is_bot = 0
                 GROUP BY gb1.user_id, u.email, gb1.created_at
                 HAVING COUNT(*) >= 10
                 ORDER BY gb1.user_id, gb1.created_at"
            )->fetchAll();

            $foundFlag = false;
            foreach ($flags as $flag) {
                if ((int) $flag['user_id'] === $targetUser) {
                    $foundFlag = true;
                    if (empty($flag['email'])) {
                        $failures[] = sprintf('iter=%d: rapid_bet_speed flag missing email', $i);
                    }
                    break;
                }
            }

            if (!$foundFlag) {
                $failures[] = sprintf(
                    'iter=%d: expected rapid_bet_speed flag for user_id=%d with %d bets in 10s window',
                    $i, $targetUser, $numBets
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 5 (Rapid Bet Speed Flag Detection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
