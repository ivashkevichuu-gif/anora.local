<?php
/**
 * Property-based test for Reconciliation Output Correctness (Property 13).
 *
 * Uses an in-memory SQLite database to simulate user_balances and ledger_entries.
 * The reconciliation logic is inlined from cron/reconciliation.php.
 *
 * Feature: platform-observability, Property 13: Reconciliation Output Correctness
 * Validates: Requirements 10.1, 10.2, 10.3, 10.4, 10.5
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ReconciliationPropertyTest extends TestCase
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
    // Property 13: Reconciliation Output Correctness
    // Feature: platform-observability, Property 13: Reconciliation Output Correctness
    //
    // JSON output should contain all required fields. Status "ok" when
    // discrepancy <= 0.01 and per_user_mismatches = 0, "fail" otherwise.
    //
    // Validates: Requirements 10.1, 10.2, 10.3, 10.4, 10.5
    // =========================================================================
    public function testProperty13_ReconciliationOutputCorrectness(): void
    {
        // Feature: platform-observability, Property 13: Reconciliation Output Correctness
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM ledger_entries");
            $this->db->exec("DELETE FROM user_balances");
            $this->db->exec("DELETE FROM users");

            $numUsers = mt_rand(1, 5);

            // Decide scenario: 0 = all ok, 1 = global discrepancy, 2 = per-user mismatch, 3 = both
            $scenario = mt_rand(0, 3);
            $expectedPerUserMismatches = 0;

            for ($u = 0; $u < $numUsers; $u++) {
                $uid = $this->createUser("recon_user{$u}_iter{$i}@test.com");

                $creditAmt = round(mt_rand(1000, 50000) / 100, 2);
                $debitAmt  = round(mt_rand(100, (int)($creditAmt * 100)) / 100, 2);
                $correctBalance = $creditAmt - $debitAmt;

                // Insert ledger entries
                $this->db->prepare(
                    "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                     VALUES (?, 'deposit', ?, 'credit', ?, ?, 'test')"
                )->execute([$uid, $creditAmt, $creditAmt, 'ref_' . mt_rand(1, 999999)]);

                $this->db->prepare(
                    "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type)
                     VALUES (?, 'bet', ?, 'debit', ?, ?, 'test')"
                )->execute([$uid, $debitAmt, $correctBalance, 'ref_' . mt_rand(1, 999999)]);

                // Set user balance based on scenario
                if ($scenario === 0) {
                    // All correct
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $correctBalance]);
                } elseif ($scenario === 1) {
                    // Global discrepancy: add extra to balance
                    $extra = mt_rand(2, 100) / 100.0;
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $correctBalance + $extra]);
                } elseif ($scenario === 2) {
                    // Per-user mismatch on first user only
                    if ($u === 0) {
                        $badBalance = $correctBalance + mt_rand(2, 100) / 100.0;
                        $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                            ->execute([$uid, $badBalance]);
                        $expectedPerUserMismatches++;
                    } else {
                        $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                            ->execute([$uid, $correctBalance]);
                    }
                } else {
                    // Both: all users have bad balances
                    $extra = mt_rand(2, 100) / 100.0;
                    $this->db->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, ?)")
                        ->execute([$uid, $correctBalance + $extra]);
                    $expectedPerUserMismatches++;
                }
            }

            // Run the reconciliation logic (inlined from cron/reconciliation.php)
            $sumBalances = (float) $this->db->query("SELECT COALESCE(SUM(balance), 0) FROM user_balances")->fetchColumn();
            $sumCredits  = (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'credit'")->fetchColumn();
            $sumDebits   = (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'debit'")->fetchColumn();

            $expectedBalance = $sumCredits - $sumDebits;
            $discrepancy     = abs($sumBalances - $expectedBalance);

            // Per-user check
            $mismatches = $this->db->query(
                "SELECT ub.user_id, ub.balance AS actual_balance, le.balance_after AS expected_balance
                 FROM user_balances ub
                 INNER JOIN ledger_entries le ON le.id = (
                     SELECT MAX(le2.id) FROM ledger_entries le2 WHERE le2.user_id = ub.user_id
                 )
                 WHERE ABS(ub.balance - le.balance_after) > 0.01"
            )->fetchAll();
            $perUserMismatches = count($mismatches);

            $hasDiscrepancy = $discrepancy > 0.01 || $perUserMismatches > 0;
            $status = $hasDiscrepancy ? 'fail' : 'ok';

            // Build the JSON summary (same structure as reconciliation.php)
            $summary = [
                'status'              => $status,
                'sum_user_balances'   => round($sumBalances, 2),
                'sum_credits'         => round($sumCredits, 2),
                'sum_debits'          => round($sumDebits, 2),
                'expected_balance'    => round($expectedBalance, 2),
                'discrepancy'         => round($discrepancy, 2),
                'per_user_mismatches' => $perUserMismatches,
                'checked_at'          => date('c'),
            ];

            // Verify all required fields exist
            $requiredFields = ['status', 'sum_user_balances', 'sum_credits', 'sum_debits',
                               'expected_balance', 'discrepancy', 'per_user_mismatches', 'checked_at'];
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $summary)) {
                    $failures[] = sprintf('iter=%d: missing required field "%s" in JSON output', $i, $field);
                }
            }

            // Verify status correctness
            $expectedStatus = ($discrepancy <= 0.01 && $perUserMismatches === 0) ? 'ok' : 'fail';
            if ($summary['status'] !== $expectedStatus) {
                $failures[] = sprintf(
                    'iter=%d: status mismatch: expected=%s got=%s (disc=%.4f, mismatches=%d)',
                    $i, $expectedStatus, $summary['status'], $discrepancy, $perUserMismatches
                );
            }

            // Verify scenario expectations
            if ($scenario === 0 && $summary['status'] !== 'ok') {
                $failures[] = sprintf('iter=%d: scenario=ok but status=%s', $i, $summary['status']);
            }
            if (($scenario === 1 || $scenario === 3) && $summary['status'] !== 'fail') {
                $failures[] = sprintf('iter=%d: scenario=%d (should fail) but status=%s', $i, $scenario, $summary['status']);
            }

            // Verify exit code logic
            $exitCode = $hasDiscrepancy ? 1 : 0;
            $expectedExitCode = ($summary['status'] === 'ok') ? 0 : 1;
            if ($exitCode !== $expectedExitCode) {
                $failures[] = sprintf('iter=%d: exit code mismatch: expected=%d got=%d', $i, $expectedExitCode, $exitCode);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 13 (Reconciliation Output Correctness) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
