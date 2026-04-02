<?php
/**
 * Property-based test for game worker idempotent processing (Property P6).
 *
 * Double call to finishRound() → exactly one set of ledger entries.
 * Uses in-memory SQLite with a PDO wrapper that strips MySQL-specific syntax.
 * 100 iterations with mt_rand().
 *
 * Feature: production-architecture-overhaul, Property 6
 * Validates: Requirements 2.5
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/ledger_service.php';
require_once __DIR__ . '/../includes/game_engine.php';

/**
 * PDO wrapper that makes SQLite compatible with MySQL-specific queries.
 * Strips FOR UPDATE, replaces NOW() with datetime('now'), UUID() with a random string.
 */
class SqliteCompatPDO extends PDO
{
    private PDO $inner;

    public function __construct(PDO $inner)
    {
        $this->inner = $inner;
    }

    private function fixSql(string $sql): string
    {
        // Strip FOR UPDATE
        $sql = preg_replace('/\s+FOR\s+UPDATE/i', '', $sql);
        // Replace NOW() with datetime('now')
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql);
        // Replace UUID() with a hex string
        $sql = preg_replace('/\bSELECT\s+UUID\(\)/i', "SELECT '" . bin2hex(random_bytes(16)) . "'", $sql);
        // Replace NOW() - INTERVAL N SECOND/HOUR/MINUTE
        $sql = preg_replace('/datetime\(\'now\'\)\s*-\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY)/i',
            "datetime('now', '-$1 $2')", $sql);
        // Replace TIMESTAMPDIFF
        $sql = preg_replace('/TIMESTAMPDIFF\(SECOND,\s*(\w+),\s*datetime\(\'now\'\)\)/i',
            "(strftime('%s', 'now') - strftime('%s', $1))", $sql);
        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->inner->prepare($this->fixSql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->inner->query($this->fixSql($query));
    }

    public function exec(string $statement): int|false
    {
        return $this->inner->exec($this->fixSql($statement));
    }

    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): bool { return $this->inner->rollBack(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
    public function setAttribute(int $attribute, mixed $value): bool { return $this->inner->setAttribute($attribute, $value); }
    public function getAttribute(int $attribute): mixed { return $this->inner->getAttribute($attribute); }
    public function errorCode(): ?string { return $this->inner->errorCode(); }
    public function errorInfo(): array { return $this->inner->errorInfo(); }
}

class GameWorkerIdempotencyPropertyTest extends TestCase
{
    private PDO $realPdo;
    private SqliteCompatPDO $pdo;

    protected function setUp(): void
    {
        $this->realPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo = new SqliteCompatPDO($this->realPdo);
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->realPdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL DEFAULT '',
                balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                is_verified TINYINT NOT NULL DEFAULT 1,
                is_banned TINYINT NOT NULL DEFAULT 0,
                is_bot TINYINT NOT NULL DEFAULT 0,
                fraud_flagged TINYINT NOT NULL DEFAULT 0,
                nickname VARCHAR(64) DEFAULT NULL,
                ref_code VARCHAR(32) NOT NULL DEFAULT '',
                referred_by INTEGER DEFAULT NULL,
                referral_locked TINYINT NOT NULL DEFAULT 0,
                referral_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->realPdo->exec("
            CREATE TABLE user_balances (
                user_id INTEGER NOT NULL PRIMARY KEY,
                balance DECIMAL(20,8) NOT NULL DEFAULT 0.00
            )
        ");
        $this->realPdo->exec("
            CREATE TABLE ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type VARCHAR(32) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                direction VARCHAR(6) NOT NULL,
                balance_after DECIMAL(20,8) NOT NULL,
                reference_id VARCHAR(64) DEFAULT NULL,
                reference_type VARCHAR(32) DEFAULT NULL,
                metadata TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(reference_type, reference_id, user_id, type)
            )
        ");
        $this->realPdo->exec("
            CREATE TABLE game_rounds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room TINYINT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'waiting',
                server_seed VARCHAR(64) DEFAULT NULL,
                server_seed_hash VARCHAR(64) DEFAULT NULL,
                total_pot DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                winner_id INTEGER DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                spinning_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                payout_status VARCHAR(16) NOT NULL DEFAULT 'pending',
                payout_id VARCHAR(36) DEFAULT NULL,
                commission DECIMAL(12,2) DEFAULT NULL,
                referral_bonus DECIMAL(12,2) DEFAULT NULL,
                winner_net DECIMAL(12,2) DEFAULT NULL,
                final_bets_snapshot TEXT DEFAULT NULL,
                final_combined_hash VARCHAR(64) DEFAULT NULL,
                final_rand_unit DECIMAL(20,12) DEFAULT NULL,
                final_target DECIMAL(20,12) DEFAULT NULL,
                final_total_weight DECIMAL(15,2) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->realPdo->exec("
            CREATE TABLE game_bets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                round_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                client_seed VARCHAR(64) DEFAULT NULL,
                ledger_entry_id INTEGER DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->realPdo->exec("
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type VARCHAR(32) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // System account (user_id = 1)
        $this->realPdo->exec("
            INSERT INTO users (id, email, password, balance, is_verified, ref_code)
            VALUES (1, 'system@anora.internal', '', 0.00, 1, 'SYSTEM')
        ");
        $this->realPdo->exec("INSERT INTO user_balances (user_id, balance) VALUES (1, 0.00)");
    }

    private function createUser(int $id, float $balance): void
    {
        $this->realPdo->prepare(
            "INSERT INTO users (id, email, password, balance, is_verified, ref_code, created_at)
             VALUES (?, ?, '', ?, 1, ?, datetime('now', '-2 days'))"
        )->execute([$id, "user{$id}@test.com", $balance, "REF{$id}"]);

        $this->realPdo->prepare(
            "INSERT INTO user_balances (user_id, balance) VALUES (?, ?)"
        )->execute([$id, $balance]);
    }

    private function createSpinningRound(int $room, array $userBets): int
    {
        $serverSeed = bin2hex(random_bytes(32));
        $serverSeedHash = hash('sha256', $serverSeed);
        $totalPot = array_sum($userBets);

        $this->realPdo->prepare(
            "INSERT INTO game_rounds (room, status, server_seed, server_seed_hash, total_pot, started_at, spinning_at)
             VALUES (?, 'spinning', ?, ?, ?, datetime('now', '-35 seconds'), datetime('now'))"
        )->execute([$room, $serverSeed, $serverSeedHash, $totalPot]);

        $roundId = (int)$this->realPdo->lastInsertId();

        foreach ($userBets as $userId => $amount) {
            $clientSeed = mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999);
            $this->realPdo->prepare(
                "INSERT INTO game_bets (round_id, user_id, amount, client_seed) VALUES (?, ?, ?, ?)"
            )->execute([$roundId, $userId, $amount, $clientSeed]);
        }

        return $roundId;
    }

    // =========================================================================
    // Property 6: Game worker idempotent processing
    // Feature: production-architecture-overhaul, Property 6
    //
    // For any game round in 'spinning' status with payout_status='pending',
    // calling finishRound() twice should result in exactly one set of ledger
    // entries. The second call should return the already-finished round without
    // creating duplicate entries.
    //
    // **Validates: Requirements 2.5**
    // =========================================================================
    public function testProperty6_IdempotentProcessing(): void
    {
        $iterations = 100;
        $failures = [];
        $rooms = [1, 10, 100];

        for ($i = 0; $i < $iterations; $i++) {
            // Reset database for each iteration
            $this->realPdo->exec("DELETE FROM ledger_entries");
            $this->realPdo->exec("DELETE FROM game_bets");
            $this->realPdo->exec("DELETE FROM game_rounds");
            $this->realPdo->exec("DELETE FROM users WHERE id > 1");
            $this->realPdo->exec("DELETE FROM user_balances WHERE user_id > 1");
            $this->realPdo->exec("UPDATE user_balances SET balance = 0 WHERE user_id = 1");

            $room = $rooms[mt_rand(0, count($rooms) - 1)];
            $numPlayers = mt_rand(2, 5);
            $userBets = [];

            for ($p = 0; $p < $numPlayers; $p++) {
                $userId = $p + 2;
                $betAmount = (float)$room;
                $this->createUser($userId, $betAmount * 10);
                $userBets[$userId] = $betAmount;
            }

            $roundId = $this->createSpinningRound($room, $userBets);

            $ledger = new LedgerService($this->pdo);
            $engine = new GameEngine($this->pdo, $ledger);

            // First call to finishRound
            try {
                $result1 = $engine->finishRound($roundId);
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: first finishRound threw: %s', $i, $e->getMessage());
                continue;
            }

            // Count ledger entries after first call
            $countStmt = $this->realPdo->prepare("SELECT COUNT(*) FROM ledger_entries WHERE reference_id = ?");
            $countStmt->execute([(string)$roundId]);
            $entriesAfterFirst = (int)$countStmt->fetchColumn();

            if ($entriesAfterFirst === 0) {
                $failures[] = sprintf('iter=%d: no ledger entries after first finishRound', $i);
                continue;
            }

            // Second call to finishRound (should be idempotent)
            try {
                $result2 = $engine->finishRound($roundId);
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: second finishRound threw: %s', $i, $e->getMessage());
                continue;
            }

            // Count ledger entries after second call
            $countStmt->execute([(string)$roundId]);
            $entriesAfterSecond = (int)$countStmt->fetchColumn();

            // Entries should be the same — no duplicates
            if ($entriesAfterFirst !== $entriesAfterSecond) {
                $failures[] = sprintf(
                    'iter=%d room=%d: ledger entries changed: %d → %d',
                    $i, $room, $entriesAfterFirst, $entriesAfterSecond
                );
                continue;
            }

            // Verify round is finished with payout_status='paid'
            $roundStmt = $this->realPdo->prepare("SELECT status, payout_status FROM game_rounds WHERE id = ?");
            $roundStmt->execute([$roundId]);
            $round = $roundStmt->fetch();

            if ($round['status'] !== 'finished') {
                $failures[] = sprintf('iter=%d: round status is %s, expected finished', $i, $round['status']);
            }
            if ($round['payout_status'] !== 'paid') {
                $failures[] = sprintf('iter=%d: payout_status is %s, expected paid', $i, $round['payout_status']);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 6 (Idempotent processing) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
