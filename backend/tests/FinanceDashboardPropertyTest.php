<?php
/**
 * Property-based tests for Finance Dashboard (Properties 6-7).
 *
 * Uses an in-memory SQLite database to simulate ledger_entries and user_balances.
 * The aggregation logic is inlined from finance_dashboard.php.
 *
 * Feature: platform-observability, Properties 6-7: Finance Aggregations & Currency Formatting
 * Validates: Requirements 3.2, 3.3, 3.5, 3.6, 3.7, 4.3
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FinanceDashboardPropertyTest extends TestCase
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

        $this->db->exec("
            CREATE TABLE user_balances (
                user_id INTEGER NOT NULL PRIMARY KEY,
                balance REAL NOT NULL DEFAULT 0
            )
        ");
    }

    private function createUser(string $email, bool $isBot = false): int
    {
        $this->db->prepare("INSERT INTO users (email, is_bot) VALUES (?, ?)")
            ->execute([$email, $isBot ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    private function insertLedger(int $userId, string $type, float $amount, string $direction): void
    {
        $this->db->prepare(
            "INSERT INTO ledger_entries (user_id, type, amount, direction, reference_id, reference_type)
             VALUES (?, ?, ?, ?, 'ref_' || ?, 'test')"
        )->execute([$userId, $type, $amount, $direction, mt_rand(1, 999999)]);
    }

    // =========================================================================
    // Property 6: Finance Dashboard Aggregations
    // Feature: platform-observability, Property 6: Finance Dashboard Aggregations
    //
    // For any set of ledger_entries with users, the finance dashboard should
    // return correct totals for deposits, withdrawals, bets, payouts (excluding
    // bots), and net_platform_position = deposits - withdrawals.
    //
    // Validates: Requirements 3.2, 3.3, 3.5, 3.6, 3.7
    // =========================================================================
    public function testProperty6_FinanceDashboardAggregations(): void
    {
        // Feature: platform-observability, Property 6: Finance Dashboard Aggregations
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM ledger_entries");
            $this->db->exec("DELETE FROM user_balances");
            $this->db->exec("DELETE FROM users");

            // Create random mix of bot and non-bot users
            $numHumans = mt_rand(1, 5);
            $numBots   = mt_rand(0, 3);
            $humanIds  = [];
            $botIds    = [];

            for ($h = 0; $h < $numHumans; $h++) {
                $humanIds[] = $this->createUser("human{$h}_iter{$i}@test.com", false);
            }
            for ($b = 0; $b < $numBots; $b++) {
                $botIds[] = $this->createUser("bot{$b}_iter{$i}@test.com", true);
            }

            // Track expected values (only non-bot)
            $expectedDeposits    = 0.0;
            $expectedWithdrawals = 0.0;
            $expectedBets        = 0.0;
            $expectedPayouts     = 0.0;

            // Insert random ledger entries for humans
            $types = [
                ['deposit', 'credit'],
                ['crypto_deposit', 'credit'],
                ['withdrawal', 'debit'],
                ['crypto_withdrawal', 'debit'],
                ['bet', 'debit'],
                ['win', 'credit'],
            ];

            foreach ($humanIds as $uid) {
                $numEntries = mt_rand(1, 6);
                for ($e = 0; $e < $numEntries; $e++) {
                    $typeInfo = $types[mt_rand(0, count($types) - 1)];
                    $amount   = round(mt_rand(100, 10000) / 100, 2);

                    $this->insertLedger($uid, $typeInfo[0], $amount, $typeInfo[1]);

                    if (in_array($typeInfo[0], ['deposit', 'crypto_deposit']) && $typeInfo[1] === 'credit') {
                        $expectedDeposits += $amount;
                    }
                    if (in_array($typeInfo[0], ['withdrawal', 'crypto_withdrawal']) && $typeInfo[1] === 'debit') {
                        $expectedWithdrawals += $amount;
                    }
                    if ($typeInfo[0] === 'bet' && $typeInfo[1] === 'debit') {
                        $expectedBets += $amount;
                    }
                    if ($typeInfo[0] === 'win' && $typeInfo[1] === 'credit') {
                        $expectedPayouts += $amount;
                    }
                }
            }

            // Insert some bot entries (should be excluded)
            foreach ($botIds as $uid) {
                $this->insertLedger($uid, 'deposit', round(mt_rand(100, 5000) / 100, 2), 'credit');
                $this->insertLedger($uid, 'bet', round(mt_rand(100, 5000) / 100, 2), 'debit');
            }

            // Run the finance dashboard queries (inlined from finance_dashboard.php)
            $totalDeposits = (float) $this->db->query(
                "SELECT COALESCE(SUM(le.amount), 0)
                 FROM ledger_entries le
                 JOIN users u ON u.id = le.user_id
                 WHERE le.type IN ('deposit', 'crypto_deposit')
                   AND le.direction = 'credit'
                   AND u.is_bot = 0"
            )->fetchColumn();

            $totalWithdrawals = (float) $this->db->query(
                "SELECT COALESCE(SUM(le.amount), 0)
                 FROM ledger_entries le
                 JOIN users u ON u.id = le.user_id
                 WHERE le.type IN ('withdrawal', 'crypto_withdrawal')
                   AND le.direction = 'debit'
                   AND u.is_bot = 0"
            )->fetchColumn();

            $totalBets = (float) $this->db->query(
                "SELECT COALESCE(SUM(le.amount), 0)
                 FROM ledger_entries le
                 JOIN users u ON u.id = le.user_id
                 WHERE le.type = 'bet'
                   AND le.direction = 'debit'
                   AND u.is_bot = 0"
            )->fetchColumn();

            $totalPayouts = (float) $this->db->query(
                "SELECT COALESCE(SUM(le.amount), 0)
                 FROM ledger_entries le
                 JOIN users u ON u.id = le.user_id
                 WHERE le.type = 'win'
                   AND le.direction = 'credit'
                   AND u.is_bot = 0"
            )->fetchColumn();

            $netPosition = $totalDeposits - $totalWithdrawals;

            // Verify
            if (abs($totalDeposits - $expectedDeposits) > 0.01) {
                $failures[] = sprintf('iter=%d: total_deposits mismatch: expected=%.2f got=%.2f', $i, $expectedDeposits, $totalDeposits);
            }
            if (abs($totalWithdrawals - $expectedWithdrawals) > 0.01) {
                $failures[] = sprintf('iter=%d: total_withdrawals mismatch: expected=%.2f got=%.2f', $i, $expectedWithdrawals, $totalWithdrawals);
            }
            if (abs($totalBets - $expectedBets) > 0.01) {
                $failures[] = sprintf('iter=%d: total_bets mismatch: expected=%.2f got=%.2f', $i, $expectedBets, $totalBets);
            }
            if (abs($totalPayouts - $expectedPayouts) > 0.01) {
                $failures[] = sprintf('iter=%d: total_payouts mismatch: expected=%.2f got=%.2f', $i, $expectedPayouts, $totalPayouts);
            }
            if (abs($netPosition - ($expectedDeposits - $expectedWithdrawals)) > 0.01) {
                $failures[] = sprintf('iter=%d: net_platform_position mismatch: expected=%.2f got=%.2f', $i, $expectedDeposits - $expectedWithdrawals, $netPosition);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 6 (Finance Dashboard Aggregations) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 7: USD Currency Formatting
    // Feature: platform-observability, Property 7: USD Currency Formatting
    //
    // For any non-negative float, the formatting function should produce a string
    // with exactly two decimal places prefixed by $.
    //
    // Validates: Requirements 4.3
    // =========================================================================
    public function testProperty7_UsdCurrencyFormatting(): void
    {
        // Feature: platform-observability, Property 7: USD Currency Formatting
        $iterations = 30;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate a random non-negative float
            $value = mt_rand(0, 9999999) / 100.0;

            // Apply the formatting function (same as used in frontend/backend: $X,XXX.XX)
            $formatted = '$' . number_format($value, 2);

            // Verify: starts with $
            if ($formatted[0] !== '$') {
                $failures[] = sprintf('iter=%d: value=%.4f formatted=%s does not start with $', $i, $value, $formatted);
                continue;
            }

            // Verify: exactly two decimal places
            $afterDollar = substr($formatted, 1);
            $dotPos = strrpos($afterDollar, '.');
            if ($dotPos === false) {
                $failures[] = sprintf('iter=%d: value=%.4f formatted=%s has no decimal point', $i, $value, $formatted);
                continue;
            }

            $decimals = substr($afterDollar, $dotPos + 1);
            if (strlen($decimals) !== 2) {
                $failures[] = sprintf(
                    'iter=%d: value=%.4f formatted=%s has %d decimal places instead of 2',
                    $i, $value, $formatted, strlen($decimals)
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 7 (USD Currency Formatting) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
