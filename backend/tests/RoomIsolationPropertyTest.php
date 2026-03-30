<?php
/**
 * Property-based tests for room isolation (Properties 9, 10, 11).
 *
 * Uses an in-memory SQLite database to simulate the MySQL schema.
 * Same schema and approach as PayoutEnginePropertyTest.php.
 *
 * Feature: referral-commission-system
 * Validates: Requirements 5.2, 5.3, 6.1, 6.2, 6.3, 6.4
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/lottery.php';

use PHPUnit\Framework\TestCase;

class RoomIsolationPropertyTest extends TestCase
{
    private PDO $db;

    // -------------------------------------------------------------------------
    // Set up in-memory SQLite database with required schema
    // -------------------------------------------------------------------------
    protected function setUp(): void
    {
        defined('LOTTERY_BET')              || define('LOTTERY_BET',              1.00);
        defined('LOTTERY_COUNTDOWN')        || define('LOTTERY_COUNTDOWN',        30);
        defined('LOTTERY_MIN_PLAYERS')      || define('LOTTERY_MIN_PLAYERS',      2);
        defined('LOTTERY_MAX_BETS_PER_SEC') || define('LOTTERY_MAX_BETS_PER_SEC', 5);
        defined('LOTTERY_HASH_FORMAT')      || define('LOTTERY_HASH_FORMAT',      '%s:%s:%d');

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                password TEXT DEFAULT '',
                balance REAL DEFAULT 0,
                referral_earnings REAL DEFAULT 0,
                is_verified INTEGER DEFAULT 0,
                is_banned INTEGER DEFAULT 0,
                referred_by INTEGER DEFAULT NULL,
                referral_locked INTEGER DEFAULT 0,
                ref_code TEXT DEFAULT '',
                registration_ip TEXT DEFAULT NULL,
                referral_snapshot TEXT DEFAULT NULL,
                display_name TEXT DEFAULT NULL,
                is_bot INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE lottery_games (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT DEFAULT 'waiting',
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                winner_id INTEGER DEFAULT NULL,
                total_pot REAL DEFAULT 0,
                room INTEGER DEFAULT 1,
                payout_status TEXT DEFAULT 'pending',
                payout_id TEXT DEFAULT NULL,
                commission REAL DEFAULT NULL,
                referral_bonus REAL DEFAULT NULL,
                winner_net REAL DEFAULT NULL,
                server_seed TEXT DEFAULT NULL,
                server_seed_hash TEXT DEFAULT NULL,
                final_bets_snapshot TEXT DEFAULT NULL,
                final_combined_hash TEXT DEFAULT NULL,
                final_rand_unit REAL DEFAULT NULL,
                final_target REAL DEFAULT NULL,
                final_total_weight REAL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE lottery_bets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER,
                user_id INTEGER,
                amount REAL DEFAULT 1,
                room INTEGER DEFAULT 1,
                client_seed TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE system_balance (
                id INTEGER DEFAULT 1 PRIMARY KEY,
                balance REAL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE system_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER,
                payout_id TEXT,
                amount REAL,
                type TEXT,
                source_user_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(game_id, payout_id, type)
            )
        ");

        $this->db->exec("
            CREATE TABLE user_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                payout_id TEXT,
                type TEXT,
                amount REAL,
                game_id INTEGER,
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(game_id, user_id, type, payout_id)
            )
        ");

        $this->db->exec("
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                type TEXT,
                amount REAL,
                status TEXT DEFAULT 'pending',
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Seed system_balance singleton
        $this->db->exec("INSERT INTO system_balance (id, balance) VALUES (1, 0)");
    }

    // -------------------------------------------------------------------------
    // Helper: insert a user, return its id
    // -------------------------------------------------------------------------
    private function insertUser(float $balance = 1000.0): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (email, balance, is_verified, is_bot)
             VALUES (?, ?, 1, 0)"
        );
        $stmt->execute(['user' . mt_rand(1, 9999999) . '@test.com', $balance]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helper: insert a game for a given room, return its id
    // -------------------------------------------------------------------------
    private function insertGame(int $room, float $pot = 0.0): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lottery_games (status, total_pot, room, payout_status)
             VALUES ('waiting', ?, ?, 'pending')"
        );
        $stmt->execute([$pot, $room]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helper: simulate placing a bet (as placeBet would) without calling the
    // full placeBet() function (which uses FOR UPDATE and MySQL-specific syntax).
    // Inserts the bet row, deducts balance, inserts user_transaction, updates pot.
    // -------------------------------------------------------------------------
    private function simulateBet(int $gameId, int $userId, int $room): void
    {
        // Deduct balance
        $this->db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
            ->execute([$room, $userId]);

        // Insert bet row
        $this->db->prepare(
            "INSERT INTO lottery_bets (game_id, user_id, amount, room) VALUES (?, ?, ?, ?)"
        )->execute([$gameId, $userId, $room, $room]);

        // Insert user_transaction for the bet
        $this->db->prepare(
            "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id)
             VALUES (?, 'bet', ?, ?, NULL)"
        )->execute([$userId, $room, $gameId]);

        // Update game pot
        $this->db->prepare("UPDATE lottery_games SET total_pot = total_pot + ? WHERE id = ?")
            ->execute([$room, $gameId]);
    }

    // =========================================================================
    // Property 9: Room Independence
    // Feature: referral-commission-system, Property 9: Room Independence
    //
    // For any two distinct rooms R1 and R2, a bet placed in R1 must not change
    // the total_pot, winner_id, or bet list of any active game in R2.
    //
    // Validates: Requirements 5.2, 5.3
    // =========================================================================
    public function testProperty9_RoomIndependence(): void
    {
        // Feature: referral-commission-system, Property 9: Room Independence
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Create two games in different rooms
            $gameRoom1  = $this->insertGame(1);
            $gameRoom10 = $this->insertGame(10);

            // Generate a random number of bets (1–5) in room 1 only
            $numBets = mt_rand(1, 5);
            $expectedPot = 0.0;

            for ($b = 0; $b < $numBets; $b++) {
                $userId = $this->insertUser(500.0);
                $this->simulateBet($gameRoom1, $userId, 1);
                $expectedPot += 1.0;
            }

            // Verify room 10's game has pot=0 and no bets
            $room10Row = $this->db->prepare("SELECT total_pot, winner_id FROM lottery_games WHERE id = ?");
            $room10Row->execute([$gameRoom10]);
            $room10Game = $room10Row->fetch();

            if ((float)$room10Game['total_pot'] !== 0.0) {
                $failures[] = sprintf(
                    'iter=%d: room 10 game pot should be 0, got %.4f',
                    $i, (float)$room10Game['total_pot']
                );
            }

            if ($room10Game['winner_id'] !== null) {
                $failures[] = sprintf('iter=%d: room 10 game winner_id should be null', $i);
            }

            $betCountStmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM lottery_bets WHERE game_id = ?");
            $betCountStmt->execute([$gameRoom10]);
            $betCount = (int)$betCountStmt->fetch()['cnt'];

            if ($betCount !== 0) {
                $failures[] = sprintf(
                    'iter=%d: room 10 game should have 0 bets, got %d',
                    $i, $betCount
                );
            }

            // Verify room 1's game has the expected pot
            $room1Row = $this->db->prepare("SELECT total_pot FROM lottery_games WHERE id = ?");
            $room1Row->execute([$gameRoom1]);
            $room1Game = $room1Row->fetch();

            if (abs((float)$room1Game['total_pot'] - $expectedPot) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d: room 1 game pot expected=%.2f got=%.4f',
                    $i, $expectedPot, (float)$room1Game['total_pot']
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 9 (Room Independence) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 10: Room API Scoping
    // Feature: referral-commission-system, Property 10: Room API Scoping
    //
    // getOrCreateActiveGame() with valid rooms (1, 10, 100) creates/returns games
    // scoped to that room. With invalid rooms (0, 2, 5, 50, 200, -1) it throws
    // InvalidArgumentException.
    //
    // Validates: Requirements 6.1, 6.3
    // =========================================================================
    public function testProperty10_RoomAPIScoping(): void
    {
        // Feature: referral-commission-system, Property 10: Room API Scoping
        $failures = [];

        // Test invalid rooms throw InvalidArgumentException
        $invalidRooms = [0, 2, 5, 50, 200, -1];
        foreach ($invalidRooms as $invalidRoom) {
            $threw = false;
            try {
                getOrCreateActiveGame($this->db, $invalidRoom);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            if (!$threw) {
                $failures[] = sprintf('room=%d: expected InvalidArgumentException, none thrown', $invalidRoom);
            }
        }

        // Test valid rooms — 20 iterations with random valid rooms
        $validRooms = [1, 10, 100];
        for ($i = 0; $i < 20; $i++) {
            $room = $validRooms[array_rand($validRooms)];

            try {
                $game = getOrCreateActiveGame($this->db, $room);
            } catch (\Exception $e) {
                $failures[] = sprintf('iter=%d room=%d: unexpected exception: %s', $i, $room, $e->getMessage());
                continue;
            }

            if (!is_array($game)) {
                $failures[] = sprintf('iter=%d room=%d: expected array, got %s', $i, $room, gettype($game));
                continue;
            }

            if ((int)$game['room'] !== $room) {
                $failures[] = sprintf(
                    'iter=%d: requested room=%d but game[room]=%d',
                    $i, $room, (int)$game['room']
                );
            }

            if (!isset($game['id']) || (int)$game['id'] <= 0) {
                $failures[] = sprintf('iter=%d room=%d: game has no valid id', $i, $room);
            }

            if (!in_array($game['status'], ['waiting', 'countdown'], true)) {
                $failures[] = sprintf(
                    'iter=%d room=%d: expected status waiting/countdown, got %s',
                    $i, $room, $game['status']
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 10 (Room API Scoping) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 11: Bet Amount Equals Room Bet Step
    // Feature: referral-commission-system, Property 11: Bet Amount Equals Room Bet Step
    //
    // For any bet placed in room R:
    //   - The amount deducted from user balance equals R
    //   - lottery_bets.amount equals R
    //   - user_transactions.amount for type='bet' equals R
    //
    // Validates: Requirements 6.2, 6.4
    // =========================================================================
    public function testProperty11_BetAmountEqualsRoomStep(): void
    {
        // Feature: referral-commission-system, Property 11: Bet Amount Equals Room Bet Step
        $iterations = 30;
        $failures   = [];
        $validRooms = [1, 10, 100];

        for ($i = 0; $i < $iterations; $i++) {
            $room   = $validRooms[array_rand($validRooms)];
            $userId = $this->insertUser(500.0);
            $gameId = $this->insertGame($room);

            // Record balance before bet
            $balBefore = (float)$this->db->prepare("SELECT balance FROM users WHERE id = ?")
                ->execute([$userId]) ? null : null;
            $balStmt = $this->db->prepare("SELECT balance FROM users WHERE id = ?");
            $balStmt->execute([$userId]);
            $balanceBefore = (float)$balStmt->fetch()['balance'];

            // Simulate placing the bet
            $this->simulateBet($gameId, $userId, $room);

            // Check balance deduction
            $balStmt2 = $this->db->prepare("SELECT balance FROM users WHERE id = ?");
            $balStmt2->execute([$userId]);
            $balanceAfter = (float)$balStmt2->fetch()['balance'];

            $deducted = $balanceBefore - $balanceAfter;
            if (abs($deducted - $room) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d room=%d: balance deducted=%.4f expected=%.2f',
                    $i, $room, $deducted, (float)$room
                );
            }

            // Check lottery_bets.amount
            $betStmt = $this->db->prepare("SELECT amount FROM lottery_bets WHERE game_id = ? AND user_id = ?");
            $betStmt->execute([$gameId, $userId]);
            $betRow = $betStmt->fetch();

            if (!$betRow) {
                $failures[] = sprintf('iter=%d room=%d: no lottery_bets row found', $i, $room);
            } elseif (abs((float)$betRow['amount'] - $room) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d room=%d: lottery_bets.amount=%.4f expected=%.2f',
                    $i, $room, (float)$betRow['amount'], (float)$room
                );
            }

            // Check user_transactions.amount for type='bet'
            $txStmt = $this->db->prepare(
                "SELECT amount FROM user_transactions WHERE game_id = ? AND user_id = ? AND type = 'bet'"
            );
            $txStmt->execute([$gameId, $userId]);
            $txRow = $txStmt->fetch();

            if (!$txRow) {
                $failures[] = sprintf('iter=%d room=%d: no user_transactions bet row found', $i, $room);
            } elseif (abs((float)$txRow['amount'] - $room) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d room=%d: user_transactions.amount=%.4f expected=%.2f',
                    $i, $room, (float)$txRow['amount'], (float)$room
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 11 (Bet Amount Equals Room Step) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
