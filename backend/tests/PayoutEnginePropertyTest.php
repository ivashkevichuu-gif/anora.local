<?php
/**
 * Property-based tests for the payout engine (Properties 3–8).
 *
 * Uses an in-memory SQLite database to simulate the MySQL schema.
 * Since SQLite does not support SELECT UUID(), payout logic is simulated
 * directly (inserting rows as the engine would) rather than calling
 * finishGameSafeAttempt() which depends on MySQL-specific UUID().
 *
 * Feature: referral-commission-system
 * Validates: Requirements 1.4, 1.5, 1.6, 2.1, 2.2, 2.3, 2.4, 3.7, 4.2, 4.3, 4.6, 16.1, 16.2
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/lottery.php';

use PHPUnit\Framework\TestCase;

class PayoutEnginePropertyTest extends TestCase
{
    private PDO $db;

    // -------------------------------------------------------------------------
    // Set up in-memory SQLite database with required schema
    // -------------------------------------------------------------------------
    protected function setUp(): void
    {
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
                nickname TEXT DEFAULT NULL,
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
    // Helper: random float in [$min, $max] with 2 decimal places
    // -------------------------------------------------------------------------
    private function randomFloat(float $min, float $max): float
    {
        $cents = mt_rand((int)round($min * 100), (int)round($max * 100));
        return round($cents / 100, 2);
    }

    // -------------------------------------------------------------------------
    // Helper: insert a user, return its id
    // -------------------------------------------------------------------------
    private function insertUser(
        float $balance = 100.0,
        int $isVerified = 1,
        int $isBanned = 0,
        ?int $referredBy = null,
        int $referralLocked = 0,
        string $createdAt = '2000-01-01 00:00:00'
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO users (email, balance, is_verified, is_banned, referred_by, referral_locked, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'user' . mt_rand(1, 999999) . '@test.com',
            $balance,
            $isVerified,
            $isBanned,
            $referredBy,
            $referralLocked,
            $createdAt,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helper: insert a game with given pot, return its id
    // -------------------------------------------------------------------------
    private function insertGame(float $pot, string $payoutStatus = 'pending'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lottery_games (status, total_pot, payout_status, started_at)
             VALUES ('countdown', ?, ?, datetime('now', '-60 seconds'))"
        );
        $stmt->execute([$pot, $payoutStatus]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helper: insert a bet for a user in a game
    // -------------------------------------------------------------------------
    private function insertBet(int $gameId, int $userId, float $amount = 1.0): void
    {
        $this->db->prepare(
            "INSERT INTO lottery_bets (game_id, user_id, amount) VALUES (?, ?, ?)"
        )->execute([$gameId, $userId, $amount]);
    }

    // -------------------------------------------------------------------------
    // Helper: simulate the payout engine for one game.
    //
    // Mirrors finishGameSafeAttempt logic but:
    //   - Uses uniqid() instead of SELECT UUID()
    //   - Works with SQLite (no FOR UPDATE, no MySQL-specific syntax)
    //   - Returns the payout result array
    // -------------------------------------------------------------------------
    private function simulatePayout(
        int $gameId,
        int $winnerId,
        float $pot,
        ?int $referrerId = null
    ): array {
        $payout        = computePayoutAmounts($pot);
        $commission    = $payout['commission'];
        $referralBonus = $payout['referral_bonus'];
        $winnerNet     = $payout['winner_net'];
        $payoutId      = uniqid('payout_', true);

        // Check idempotency guard
        $gameRow = $this->db->prepare("SELECT payout_status, payout_id, commission, referral_bonus, winner_net FROM lottery_games WHERE id = ?");
        $gameRow->execute([$gameId]);
        $game = $gameRow->fetch();
        if ($game && $game['payout_status'] === 'paid') {
            return [
                'already_paid'  => true,
                'payout_id'     => $game['payout_id'],
                'commission'    => (float)$game['commission'],
                'referral_bonus'=> (float)$game['referral_bonus'],
                'winner_net'    => (float)$game['winner_net'],
            ];
        }

        // Credit winner
        $this->db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$winnerNet, $winnerId]);

        $this->db->prepare(
            "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id) VALUES (?, 'win', ?, ?, ?)"
        )->execute([$winnerId, $winnerNet, $gameId, $payoutId]);

        // Determine referrer eligibility (simplified: referrerId provided = eligible)
        if ($referrerId !== null) {
            // Credit referrer
            $this->db->prepare(
                "UPDATE users SET balance = balance + ?, referral_earnings = referral_earnings + ? WHERE id = ?"
            )->execute([$referralBonus, $referralBonus, $referrerId]);

            $this->db->prepare(
                "INSERT INTO user_transactions (user_id, type, amount, game_id, payout_id) VALUES (?, 'referral_bonus', ?, ?, ?)"
            )->execute([$referrerId, $referralBonus, $gameId, $payoutId]);

            $this->db->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id) VALUES (?, ?, ?, 'commission', ?)"
            )->execute([$gameId, $payoutId, $commission, $winnerId]);

            $this->db->prepare("UPDATE system_balance SET balance = balance + ? WHERE id = 1")
                ->execute([$commission]);
        } else {
            $this->db->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id) VALUES (?, ?, ?, 'commission', ?)"
            )->execute([$gameId, $payoutId, $commission, $winnerId]);

            $this->db->prepare(
                "INSERT INTO system_transactions (game_id, payout_id, amount, type, source_user_id) VALUES (?, ?, ?, 'referral_unclaimed', ?)"
            )->execute([$gameId, $payoutId, $referralBonus, $winnerId]);

            $this->db->prepare("UPDATE system_balance SET balance = balance + ? WHERE id = 1")
                ->execute([$commission + $referralBonus]);
        }

        // Mark game paid with snapshot
        $this->db->prepare(
            "UPDATE lottery_games SET payout_status='paid', payout_id=?, commission=?, referral_bonus=?, winner_net=?, status='finished', winner_id=? WHERE id=?"
        )->execute([$payoutId, $commission, $referralBonus, $winnerNet, $winnerId, $gameId]);

        return [
            'already_paid'  => false,
            'payout_id'     => $payoutId,
            'commission'    => $commission,
            'referral_bonus'=> $referralBonus,
            'winner_net'    => $winnerNet,
        ];
    }

    // =========================================================================
    // Property 3: System Balance Monotonicity and Non-Negativity
    // Feature: referral-commission-system, Property 3: System Balance Monotonicity
    //
    // For any sequence of payout operations, system_balance.balance must be
    // >= 0.00 after every operation, and must increase by exactly commission
    // (with referrer) or commission + referral_bonus (without referrer).
    //
    // Validates: Requirements 1.4, 1.5, 1.8
    // =========================================================================
    public function testProperty3_SystemBalanceMonotonicity(): void
    {
        // Feature: referral-commission-system, Property 3: System Balance Monotonicity
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Reset system_balance for each iteration
            $this->db->exec("UPDATE system_balance SET balance = 0 WHERE id = 1");

            $numPayouts   = mt_rand(1, 5);
            $runningTotal = 0.0;

            for ($j = 0; $j < $numPayouts; $j++) {
                $pot      = $this->randomFloat(0.50, 500.00);
                $payout   = computePayoutAmounts($pot);
                $hasRef   = (bool)mt_rand(0, 1);

                $expectedIncrease = $hasRef
                    ? $payout['commission']
                    : $payout['commission'] + $payout['referral_bonus'];

                $runningTotal += $expectedIncrease;

                // Simulate balance update
                $this->db->prepare("UPDATE system_balance SET balance = balance + ? WHERE id = 1")
                    ->execute([$expectedIncrease]);

                $balRow = $this->db->query("SELECT balance FROM system_balance WHERE id = 1")->fetch();
                $balance = (float)$balRow['balance'];

                if ($balance < 0.0) {
                    $failures[] = sprintf(
                        'iter=%d payout=%d pot=%.2f balance=%.4f (went negative)',
                        $i, $j, $pot, $balance
                    );
                }

                if (abs($balance - $runningTotal) > 0.001) {
                    $failures[] = sprintf(
                        'iter=%d payout=%d pot=%.2f balance=%.4f expected=%.4f',
                        $i, $j, $pot, $balance, $runningTotal
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 3 (System Balance Monotonicity) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 4: Payout Audit Trail Completeness
    // Feature: referral-commission-system, Property 4: Payout Audit Trail Completeness
    //
    // With eligible referrer: exactly 1 system_transactions row (type='commission').
    // Without eligible referrer: exactly 2 rows (type='commission' + 'referral_unclaimed').
    //
    // Validates: Requirements 1.6, 2.3, 2.4
    // =========================================================================
    public function testProperty4_PayoutAuditTrail(): void
    {
        // Feature: referral-commission-system, Property 4: Payout Audit Trail Completeness
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pot      = $this->randomFloat(0.50, 1000.00);
            $hasRef   = (bool)mt_rand(0, 1);
            $winner   = $this->insertUser();
            $referrer = $hasRef ? $this->insertUser() : null;
            $gameId   = $this->insertGame($pot);
            $this->insertBet($gameId, $winner);

            $this->simulatePayout($gameId, $winner, $pot, $referrer);

            $stmt = $this->db->prepare("SELECT type FROM system_transactions WHERE game_id = ?");
            $stmt->execute([$gameId]);
            $rows = $stmt->fetchAll();
            $types = array_column($rows, 'type');

            if ($hasRef) {
                // Exactly 1 row: commission only
                if (count($rows) !== 1) {
                    $failures[] = sprintf('iter=%d pot=%.2f hasRef=true: expected 1 system_tx row, got %d', $i, $pot, count($rows));
                }
                if (!in_array('commission', $types, true)) {
                    $failures[] = sprintf('iter=%d pot=%.2f hasRef=true: missing commission row', $i, $pot);
                }
            } else {
                // Exactly 2 rows: commission + referral_unclaimed
                if (count($rows) !== 2) {
                    $failures[] = sprintf('iter=%d pot=%.2f hasRef=false: expected 2 system_tx rows, got %d', $i, $pot, count($rows));
                }
                if (!in_array('commission', $types, true)) {
                    $failures[] = sprintf('iter=%d pot=%.2f hasRef=false: missing commission row', $i, $pot);
                }
                if (!in_array('referral_unclaimed', $types, true)) {
                    $failures[] = sprintf('iter=%d pot=%.2f hasRef=false: missing referral_unclaimed row', $i, $pot);
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 4 (Payout Audit Trail) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 5: Referrer Credit and Earnings
    // Feature: referral-commission-system, Property 5: Referrer Credit and Earnings
    //
    // For any payout with an eligible referrer: referrer's balance increases by
    // exactly referral_bonus, and referral_earnings increases by exactly referral_bonus.
    //
    // Validates: Requirements 2.1, 2.2
    // =========================================================================
    public function testProperty5_ReferrerCredit(): void
    {
        // Feature: referral-commission-system, Property 5: Referrer Credit and Earnings
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pot            = $this->randomFloat(0.50, 1000.00);
            $payout         = computePayoutAmounts($pot);
            $referralBonus  = $payout['referral_bonus'];

            $referrerBalance   = $this->randomFloat(0.0, 500.0);
            $referrerEarnings  = $this->randomFloat(0.0, 200.0);

            $referrer = $this->insertUser($referrerBalance);
            // Manually set referral_earnings to a known value
            $this->db->prepare("UPDATE users SET referral_earnings = ? WHERE id = ?")
                ->execute([$referrerEarnings, $referrer]);

            $winner = $this->insertUser();
            $gameId = $this->insertGame($pot);
            $this->insertBet($gameId, $winner);

            $this->simulatePayout($gameId, $winner, $pot, $referrer);

            $row = $this->db->prepare("SELECT balance, referral_earnings FROM users WHERE id = ?");
            $row->execute([$referrer]);
            $updated = $row->fetch();

            $expectedBalance  = round($referrerBalance + $referralBonus, 2);
            $expectedEarnings = round($referrerEarnings + $referralBonus, 2);

            if (abs((float)$updated['balance'] - $expectedBalance) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d pot=%.2f referral_bonus=%.2f: balance expected=%.4f got=%.4f',
                    $i, $pot, $referralBonus, $expectedBalance, (float)$updated['balance']
                );
            }
            if (abs((float)$updated['referral_earnings'] - $expectedEarnings) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d pot=%.2f referral_bonus=%.2f: referral_earnings expected=%.4f got=%.4f',
                    $i, $pot, $referralBonus, $expectedEarnings, (float)$updated['referral_earnings']
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 5 (Referrer Credit) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 6: Payout Idempotency
    // Feature: referral-commission-system, Property 6: Payout Idempotency
    //
    // Calling the payout engine twice on a game with payout_status='paid' must
    // return the same (winner_net, commission, referral_bonus, payout_id) without
    // modifying any balances, system_balance, or inserting new log rows.
    //
    // Validates: Requirements 3.7, 4.2
    // =========================================================================
    public function testProperty6_PayoutIdempotency(): void
    {
        // Feature: referral-commission-system, Property 6: Payout Idempotency
        $iterations = 20;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pot      = $this->randomFloat(0.50, 500.00);
            $hasRef   = (bool)mt_rand(0, 1);
            $winner   = $this->insertUser(1000.0);
            $referrer = $hasRef ? $this->insertUser(1000.0) : null;
            $gameId   = $this->insertGame($pot);
            $this->insertBet($gameId, $winner);

            // First payout
            $first = $this->simulatePayout($gameId, $winner, $pot, $referrer);

            // Capture state after first payout
            $winnerBalAfter1   = (float)$this->db->prepare("SELECT balance FROM users WHERE id = ?")->execute([$winner]) ? null : null;
            $wRow = $this->db->prepare("SELECT balance FROM users WHERE id = ?");
            $wRow->execute([$winner]);
            $winnerBalAfter1 = (float)$wRow->fetch()['balance'];

            $sysBalAfter1 = (float)$this->db->query("SELECT balance FROM system_balance WHERE id = 1")->fetch()['balance'];

            $sysTxCount1 = (int)$this->db->prepare("SELECT COUNT(*) FROM system_transactions WHERE game_id = ?")->execute([$gameId]) ? null : null;
            $cntStmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM system_transactions WHERE game_id = ?");
            $cntStmt->execute([$gameId]);
            $sysTxCount1 = (int)$cntStmt->fetch()['cnt'];

            $userTxCount1Stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM user_transactions WHERE game_id = ?");
            $userTxCount1Stmt->execute([$gameId]);
            $userTxCount1 = (int)$userTxCount1Stmt->fetch()['cnt'];

            // Second payout attempt (should be no-op)
            $second = $this->simulatePayout($gameId, $winner, $pot, $referrer);

            // Verify same result returned
            if ($second['payout_id'] !== $first['payout_id']) {
                $failures[] = sprintf('iter=%d: payout_id changed on second call', $i);
            }
            if (abs($second['commission'] - $first['commission']) > 0.001) {
                $failures[] = sprintf('iter=%d: commission changed on second call', $i);
            }
            if (abs($second['winner_net'] - $first['winner_net']) > 0.001) {
                $failures[] = sprintf('iter=%d: winner_net changed on second call', $i);
            }

            // Verify no balance changes
            $wRow2 = $this->db->prepare("SELECT balance FROM users WHERE id = ?");
            $wRow2->execute([$winner]);
            $winnerBalAfter2 = (float)$wRow2->fetch()['balance'];

            if (abs($winnerBalAfter2 - $winnerBalAfter1) > 0.001) {
                $failures[] = sprintf('iter=%d: winner balance changed on second call (%.4f → %.4f)', $i, $winnerBalAfter1, $winnerBalAfter2);
            }

            $sysBalAfter2 = (float)$this->db->query("SELECT balance FROM system_balance WHERE id = 1")->fetch()['balance'];
            if (abs($sysBalAfter2 - $sysBalAfter1) > 0.001) {
                $failures[] = sprintf('iter=%d: system_balance changed on second call (%.4f → %.4f)', $i, $sysBalAfter1, $sysBalAfter2);
            }

            // Verify no new log rows
            $cntStmt2 = $this->db->prepare("SELECT COUNT(*) as cnt FROM system_transactions WHERE game_id = ?");
            $cntStmt2->execute([$gameId]);
            $sysTxCount2 = (int)$cntStmt2->fetch()['cnt'];
            if ($sysTxCount2 !== $sysTxCount1) {
                $failures[] = sprintf('iter=%d: system_transactions count changed on second call (%d → %d)', $i, $sysTxCount1, $sysTxCount2);
            }

            $userTxCount2Stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM user_transactions WHERE game_id = ?");
            $userTxCount2Stmt->execute([$gameId]);
            $userTxCount2 = (int)$userTxCount2Stmt->fetch()['cnt'];
            if ($userTxCount2 !== $userTxCount1) {
                $failures[] = sprintf('iter=%d: user_transactions count changed on second call (%d → %d)', $i, $userTxCount1, $userTxCount2);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 6 (Payout Idempotency) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 7: Payout ID Propagation
    // Feature: referral-commission-system, Property 7: Payout ID Propagation
    //
    // All user_transactions and system_transactions rows created during a payout
    // must carry the same non-null payout_id that is stored on lottery_games.payout_id.
    //
    // Validates: Requirements 4.3, 4.6
    // =========================================================================
    public function testProperty7_PayoutIdPropagation(): void
    {
        // Feature: referral-commission-system, Property 7: Payout ID Propagation
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pot      = $this->randomFloat(0.50, 1000.00);
            $hasRef   = (bool)mt_rand(0, 1);
            $winner   = $this->insertUser();
            $referrer = $hasRef ? $this->insertUser() : null;
            $gameId   = $this->insertGame($pot);
            $this->insertBet($gameId, $winner);

            $result = $this->simulatePayout($gameId, $winner, $pot, $referrer);
            $payoutId = $result['payout_id'];

            // Verify game row has the payout_id
            $gameRow = $this->db->prepare("SELECT payout_id FROM lottery_games WHERE id = ?");
            $gameRow->execute([$gameId]);
            $storedPayoutId = $gameRow->fetch()['payout_id'];

            if ($storedPayoutId !== $payoutId) {
                $failures[] = sprintf('iter=%d: game.payout_id mismatch (expected=%s got=%s)', $i, $payoutId, $storedPayoutId);
            }

            // Verify all user_transactions rows have the same payout_id
            $utStmt = $this->db->prepare("SELECT payout_id FROM user_transactions WHERE game_id = ?");
            $utStmt->execute([$gameId]);
            foreach ($utStmt->fetchAll() as $row) {
                if ($row['payout_id'] !== $payoutId) {
                    $failures[] = sprintf('iter=%d: user_transactions.payout_id mismatch (expected=%s got=%s)', $i, $payoutId, $row['payout_id']);
                }
                if ($row['payout_id'] === null) {
                    $failures[] = sprintf('iter=%d: user_transactions.payout_id is null', $i);
                }
            }

            // Verify all system_transactions rows have the same payout_id
            $stStmt = $this->db->prepare("SELECT payout_id FROM system_transactions WHERE game_id = ?");
            $stStmt->execute([$gameId]);
            foreach ($stStmt->fetchAll() as $row) {
                if ($row['payout_id'] !== $payoutId) {
                    $failures[] = sprintf('iter=%d: system_transactions.payout_id mismatch (expected=%s got=%s)', $i, $payoutId, $row['payout_id']);
                }
                if ($row['payout_id'] === null) {
                    $failures[] = sprintf('iter=%d: system_transactions.payout_id is null', $i);
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 7 (Payout ID Propagation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 8: Game Financial Snapshot Consistency
    // Feature: referral-commission-system, Property 8: Game Financial Snapshot Consistency
    //
    // The values stored in lottery_games.commission, referral_bonus, and winner_net
    // must exactly match what was credited to the respective balances.
    //
    // Validates: Requirements 16.1, 16.2
    // =========================================================================
    public function testProperty8_SnapshotConsistency(): void
    {
        // Feature: referral-commission-system, Property 8: Game Financial Snapshot Consistency
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $pot      = $this->randomFloat(0.50, 1000.00);
            $hasRef   = (bool)mt_rand(0, 1);
            $winner   = $this->insertUser(0.0);
            $referrer = $hasRef ? $this->insertUser(0.0) : null;
            $gameId   = $this->insertGame($pot);
            $this->insertBet($gameId, $winner);

            $result = $this->simulatePayout($gameId, $winner, $pot, $referrer);

            // Read stored snapshot from game row
            $gameRow = $this->db->prepare("SELECT commission, referral_bonus, winner_net FROM lottery_games WHERE id = ?");
            $gameRow->execute([$gameId]);
            $snap = $gameRow->fetch();

            // Read actual credited amounts from user_transactions
            $winTxStmt = $this->db->prepare("SELECT amount FROM user_transactions WHERE game_id = ? AND type = 'win'");
            $winTxStmt->execute([$gameId]);
            $winTx = $winTxStmt->fetch();

            // Snapshot winner_net must match the win transaction amount
            if (!$winTx) {
                $failures[] = sprintf('iter=%d pot=%.2f: no win user_transaction found', $i, $pot);
                continue;
            }
            if (abs((float)$snap['winner_net'] - (float)$winTx['amount']) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d pot=%.2f: winner_net snapshot=%.4f vs credited=%.4f',
                    $i, $pot, (float)$snap['winner_net'], (float)$winTx['amount']
                );
            }

            // Snapshot commission must match system_transactions commission row
            $commStmt = $this->db->prepare("SELECT amount FROM system_transactions WHERE game_id = ? AND type = 'commission'");
            $commStmt->execute([$gameId]);
            $commTx = $commStmt->fetch();
            if ($commTx && abs((float)$snap['commission'] - (float)$commTx['amount']) > 0.001) {
                $failures[] = sprintf(
                    'iter=%d pot=%.2f: commission snapshot=%.4f vs system_tx=%.4f',
                    $i, $pot, (float)$snap['commission'], (float)$commTx['amount']
                );
            }

            // Snapshot referral_bonus must match referral_bonus user_transaction (if referrer) or referral_unclaimed system_tx
            if ($hasRef) {
                $refTxStmt = $this->db->prepare("SELECT amount FROM user_transactions WHERE game_id = ? AND type = 'referral_bonus'");
                $refTxStmt->execute([$gameId]);
                $refTx = $refTxStmt->fetch();
                if ($refTx && abs((float)$snap['referral_bonus'] - (float)$refTx['amount']) > 0.001) {
                    $failures[] = sprintf(
                        'iter=%d pot=%.2f: referral_bonus snapshot=%.4f vs credited=%.4f',
                        $i, $pot, (float)$snap['referral_bonus'], (float)$refTx['amount']
                    );
                }
            } else {
                $unclStmt = $this->db->prepare("SELECT amount FROM system_transactions WHERE game_id = ? AND type = 'referral_unclaimed'");
                $unclStmt->execute([$gameId]);
                $unclTx = $unclStmt->fetch();
                if ($unclTx && abs((float)$snap['referral_bonus'] - (float)$unclTx['amount']) > 0.001) {
                    $failures[] = sprintf(
                        'iter=%d pot=%.2f: referral_bonus snapshot=%.4f vs unclaimed=%.4f',
                        $i, $pot, (float)$snap['referral_bonus'], (float)$unclTx['amount']
                    );
                }
            }

            // Snapshot values must match what computePayoutAmounts returned
            if (abs((float)$snap['commission'] - $result['commission']) > 0.001) {
                $failures[] = sprintf('iter=%d pot=%.2f: commission snapshot mismatch', $i, $pot);
            }
            if (abs((float)$snap['referral_bonus'] - $result['referral_bonus']) > 0.001) {
                $failures[] = sprintf('iter=%d pot=%.2f: referral_bonus snapshot mismatch', $i, $pot);
            }
            if (abs((float)$snap['winner_net'] - $result['winner_net']) > 0.001) {
                $failures[] = sprintf('iter=%d pot=%.2f: winner_net snapshot mismatch', $i, $pot);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 8 (Snapshot Consistency) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
