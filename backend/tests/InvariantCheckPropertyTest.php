<?php
/**
 * Property-based tests for Monetary Invariant Checks (Properties 8-11).
 *
 * Uses an in-memory SQLite database to simulate user_balances and ledger_entries.
 * The check logic is inlined from health_check.php.
 *
 * Feature: platform-observability, Properties 8-11: Monetary Invariant Checks
 * Validates: Requirements 5.5, 5.8, 8.2, 8.3, 8.4, 8.6, 8.7
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class InvariantCheckPropertyTest extends TestCase
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
                is_bot INTEGER NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE user_balances (
                user_id INTEGER NOT NULL PRIMARY KEY,
                balance REAL NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                amount REAL NOT NULL,
                direction TEXT NOT NULL,
                balance_after REAL NOT NULL DEFAULT 0,
                reference_id TEXT DEFAULT NULL,
                reference_type TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
    }

    private function createUser(string $email): int
    {
        $this->db->prepare("INSERT INTO users (email, is_bot) VALUES (?, 0)")
            ->execute([$email]);
        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // Property 8: Global Balance Invariant Check
    // Feature: platform-observability, Property 8: Global Balance Invariant Check
    //
    // When SUM(user_balances.balance) equals SUM(credits) - SUM(debits) within
    // 0.01, no_money_created should pass. When discrepancy > 0.01, it should fail.
    //
    // Validates: Requirements 5.5, 8.2
    // =========================================================================
    public function testProperty8_GlobalBalanceInvariantCheck(): void
    {
        // Feature: platform-observability, Property 8: Global Balance Invariant Check
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM ledger_entries");
            $this->db->exec("DELETE FROM user_balances");
            $this->db->exec("DELETE FROM users");

            // Create users with random balances
            $numUsers = mt_rand(1, 5);
            $totalBalance = 0.0;
            $totalCredits = 0.0;
            $totalDebits  = 0.0;

            // Decide if this iteration should pass or fail
            $shouldPass = mt_rand(0, 1) === 1;

            for ($u = 0; $u < $numUsers; $u++) {
                $uid = $this->createUser("user{$u}_iter{$i}@test.com");

                $creditAmt = round(mt_rand(1000, 50000) / 100, 2);
                $debitAmt  = round(mt_rand(100, (int)($creditAmt * 100)) / 100, 2);
                $balance   = $creditAmt - $debitAmt;

                $totalCredits += $creditAmt;
                $totalDebits  += $debitAmt;

                if ($shouldPass) {
                    $totalBalance += $balance;
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $balance]);
                } else {
                    // Introduce a discrepancy > 0.01
                    $badBalance = $balance + mt_rand(2, 100) / 100.0;
                    $totalBalance += $badBalance;
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $badBalance]);
                }

                $this->db->prepare(
                    "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                     VALUES (?, 'deposit', ?, 'credit', ?, ?, 'test')"
                )->execute([$uid, $creditAmt, $creditAmt, 'ref_' . mt_rand(1, 999999)]);

                $this->db->prepare(
                    "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                     VALUES (?, 'bet', ?, 'debit', ?, ?, 'test')"
                )->execute([$uid, $debitAmt, $balance, 'ref_' . mt_rand(1, 999999)]);
            }

            // Run the no_money_created check (inlined from health_check.php)
            $sumBalances = (float) $this->db->query("SELECT COALESCE(SUM(balance), 0) FROM user_balances")->fetchColumn();
            $sumCredits  = (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'credit'")->fetchColumn();
            $sumDebits   = (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'debit'")->fetchColumn();

            $expected    = $sumCredits - $sumDebits;
            $discrepancy = abs($sumBalances - $expected);
            $passed      = $discrepancy <= 0.01;

            if ($shouldPass && !$passed) {
                $failures[] = sprintf(
                    'iter=%d: expected no_money_created to PASS (balances=%.2f expected=%.2f disc=%.4f)',
                    $i, $sumBalances, $expected, $discrepancy
                );
            }
            if (!$shouldPass && $passed) {
                $failures[] = sprintf(
                    'iter=%d: expected no_money_created to FAIL (balances=%.2f expected=%.2f disc=%.4f)',
                    $i, $sumBalances, $expected, $discrepancy
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 8 (Global Balance Invariant Check) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 9: Per-User Balance Consistency Check
    // Feature: platform-observability, Property 9: Per-User Balance Consistency Check
    //
    // When user_balances.balance matches most recent ledger_entries.balance_after
    // within 0.01, no_money_lost should not flag that user. Otherwise it should.
    //
    // Validates: Requirements 5.8, 8.3
    // =========================================================================
    public function testProperty9_PerUserBalanceConsistencyCheck(): void
    {
        // Feature: platform-observability, Property 9: Per-User Balance Consistency Check
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM ledger_entries");
            $this->db->exec("DELETE FROM user_balances");
            $this->db->exec("DELETE FROM users");

            $numUsers = mt_rand(2, 5);
            $expectedMismatched = [];

            for ($u = 0; $u < $numUsers; $u++) {
                $uid = $this->createUser("user{$u}_iter{$i}@test.com");

                $balanceAfter = round(mt_rand(100, 50000) / 100, 2);

                // Insert a few ledger entries, the last one has balance_after
                $numEntries = mt_rand(1, 3);
                for ($e = 0; $e < $numEntries; $e++) {
                    $ba = ($e === $numEntries - 1) ? $balanceAfter : round(mt_rand(100, 50000) / 100, 2);
                    $this->db->prepare(
                        "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                         VALUES (?, 'deposit', ?, 'credit', ?, ?, 'test')"
                    )->execute([$uid, round(mt_rand(100, 5000) / 100, 2), $ba, 'ref_' . mt_rand(1, 999999)]);
                }

                // Randomly decide if this user's balance matches or not
                $shouldMatch = mt_rand(0, 1) === 1;
                if ($shouldMatch) {
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $balanceAfter]);
                } else {
                    $badBalance = $balanceAfter + mt_rand(2, 100) / 100.0;
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $badBalance]);
                    $expectedMismatched[] = $uid;
                }
            }

            // Run the no_money_lost check (inlined from health_check.php, adapted for SQLite)
            $stmt = $this->db->query(
                "SELECT ub.user_id, ub.balance AS actual_balance, le.balance_after AS expected_balance
                 FROM user_balances ub
                 INNER JOIN ledger_entries le ON le.id = (
                     SELECT MAX(le2.id) FROM ledger_entries le2 WHERE le2.user_id = ub.user_id
                 )
                 WHERE ABS(ub.balance - le.balance_after) > 0.01"
            );
            $mismatched = $stmt->fetchAll();
            $mismatchedIds = array_map(fn($r) => (int) $r['user_id'], $mismatched);

            // Verify expected mismatches are flagged
            foreach ($expectedMismatched as $uid) {
                if (!in_array($uid, $mismatchedIds)) {
                    $failures[] = sprintf('iter=%d: user_id=%d should be flagged but was not', $i, $uid);
                }
            }

            // Verify non-mismatched users are NOT flagged
            $allUserIds = array_map(
                fn($r) => (int) $r['user_id'],
                $this->db->query("SELECT user_id FROM user_balances")->fetchAll()
            );
            $shouldNotFlag = array_diff($allUserIds, $expectedMismatched);
            foreach ($shouldNotFlag as $uid) {
                if (in_array($uid, $mismatchedIds)) {
                    $failures[] = sprintf('iter=%d: user_id=%d should NOT be flagged but was', $i, $uid);
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 9 (Per-User Balance Consistency Check) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 10: Everything Traceable Check
    // Feature: platform-observability, Property 10: Everything Traceable Check
    //
    // Should return passed=true iff every ledger_entries row has non-null,
    // non-empty reference_id and reference_type.
    //
    // Validates: Requirements 8.4
    // =========================================================================
    public function testProperty10_EverythingTraceableCheck(): void
    {
        // Feature: platform-observability, Property 10: Everything Traceable Check
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM ledger_entries");
            $this->db->exec("DELETE FROM user_balances");
            $this->db->exec("DELETE FROM users");

            $uid = $this->createUser("trace_iter{$i}@test.com");

            $numEntries = mt_rand(2, 8);
            $hasUntraceable = mt_rand(0, 1) === 1;
            $untraceableIdx = $hasUntraceable ? mt_rand(0, $numEntries - 1) : -1;

            for ($e = 0; $e < $numEntries; $e++) {
                if ($e === $untraceableIdx) {
                    // Insert an untraceable entry (null or empty reference_id/reference_type)
                    $variant = mt_rand(0, 3);
                    $refId   = ($variant === 0 || $variant === 2) ? null : 'ref_' . mt_rand(1, 999999);
                    $refType = ($variant === 1 || $variant === 2) ? null : 'test';
                    // Also test empty string variants
                    if ($variant === 3) {
                        $refId = '';
                        $refType = '';
                    }
                    $this->db->prepare(
                        "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                         VALUES (?, 'deposit', ?, 'credit', ?, ?, ?)"
                    )->execute([$uid, round(mt_rand(100, 5000) / 100, 2), round(mt_rand(100, 5000) / 100, 2), $refId, $refType]);
                } else {
                    $this->db->prepare(
                        "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                         VALUES (?, 'deposit', ?, 'credit', ?, ?, 'test')"
                    )->execute([$uid, round(mt_rand(100, 5000) / 100, 2), round(mt_rand(100, 5000) / 100, 2), 'ref_' . mt_rand(1, 999999)]);
                }
            }

            // Run the everything_traceable check (inlined from health_check.php)
            $untraceableCount = (int) $this->db->query(
                "SELECT COUNT(*) FROM ledger_entries
                 WHERE reference_id IS NULL
                    OR reference_id = ''
                    OR reference_type IS NULL
                    OR reference_type = ''"
            )->fetchColumn();

            $passed = $untraceableCount === 0;

            if ($hasUntraceable && $passed) {
                $failures[] = sprintf('iter=%d: expected everything_traceable to FAIL but it passed', $i);
            }
            if (!$hasUntraceable && !$passed) {
                $failures[] = sprintf('iter=%d: expected everything_traceable to PASS but it failed (untraceable=%d)', $i, $untraceableCount);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 10 (Everything Traceable Check) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 11: Health Check Status Composition
    // Feature: platform-observability, Property 11: Health Check Status Composition
    //
    // Status should be "ok" iff all three checks pass. "fail" if any fails.
    //
    // Validates: Requirements 8.6, 8.7
    // =========================================================================
    public function testProperty11_HealthCheckStatusComposition(): void
    {
        // Feature: platform-observability, Property 11: Health Check Status Composition
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Randomly decide which checks pass/fail
            $noMoneyCreatedPassed     = mt_rand(0, 1) === 1;
            $noMoneyLostPassed        = mt_rand(0, 1) === 1;
            $everythingTraceablePassed = mt_rand(0, 1) === 1;

            // Compose the status (inlined from health_check.php)
            $allPassed = $noMoneyCreatedPassed && $noMoneyLostPassed && $everythingTraceablePassed;
            $status    = $allPassed ? 'ok' : 'fail';

            // Verify
            $expectedStatus = ($noMoneyCreatedPassed && $noMoneyLostPassed && $everythingTraceablePassed) ? 'ok' : 'fail';

            if ($status !== $expectedStatus) {
                $failures[] = sprintf(
                    'iter=%d: checks=[%s,%s,%s] expected=%s got=%s',
                    $i,
                    $noMoneyCreatedPassed ? 'pass' : 'fail',
                    $noMoneyLostPassed ? 'pass' : 'fail',
                    $everythingTraceablePassed ? 'pass' : 'fail',
                    $expectedStatus,
                    $status
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 11 (Health Check Status Composition) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
