<?php
/**
 * Reconciliation Cron Job
 *
 * Verifies the global balance invariant and per-user consistency.
 * Run via: php backend/cron/reconciliation.php
 *
 * Exit codes: 0 = all checks pass, 1 = any discrepancy detected
 */
require_once __DIR__ . '/../config/db.php';

$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

$logFile = $logsDir . '/reconciliation.log';
$jsonFile = $logsDir . '/reconciliation_latest.json';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$timestamp = $now->format('Y-m-d H:i:s');
$isoTimestamp = $now->format('c');

$hasDiscrepancy = false;
$perUserMismatches = 0;

try {
    // ── Global invariant check ───────────────────────────────────────────
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) AS total FROM user_balances");
    $sumBalances = (float) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) AS total FROM ledger_entries WHERE direction = 'credit'");
    $sumCredits = (float) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) AS total FROM ledger_entries WHERE direction = 'debit'");
    $sumDebits = (float) $stmt->fetchColumn();

    $expectedBalance = $sumCredits - $sumDebits;
    $discrepancy = abs($sumBalances - $expectedBalance);

    if ($discrepancy > 0.01) {
        $hasDiscrepancy = true;
    }

    // ── Per-user consistency check ───────────────────────────────────────
    // For each user in user_balances, check that balance matches the most
    // recent ledger_entries.balance_after (by id DESC).
    $stmt = $pdo->query("
        SELECT ub.user_id, ub.balance AS actual_balance, le.balance_after AS expected_balance
        FROM user_balances ub
        INNER JOIN ledger_entries le ON le.id = (
            SELECT MAX(le2.id) FROM ledger_entries le2 WHERE le2.user_id = ub.user_id
        )
        WHERE ABS(ub.balance - le.balance_after) > 0.01
    ");
    $mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $perUserMismatches = count($mismatches);

    if ($perUserMismatches > 0) {
        $hasDiscrepancy = true;
    }

    // ── Determine status ─────────────────────────────────────────────────
    $status = $hasDiscrepancy ? 'fail' : 'ok';
    $severity = $hasDiscrepancy ? 'CRITICAL' : 'OK';

    // ── Write human-readable log entry ───────────────────────────────────
    $logLine = sprintf(
        "[%s] %s — sum_balances=%.2f sum_credits=%.2f sum_debits=%.2f expected=%.2f discrepancy=%.2f per_user_mismatches=%d",
        $timestamp,
        $severity,
        $sumBalances,
        $sumCredits,
        $sumDebits,
        $expectedBalance,
        $discrepancy,
        $perUserMismatches
    );

    // Log per-user mismatches
    foreach ($mismatches as $m) {
        $logLine .= sprintf(
            "\n  [%s] MISMATCH user_id=%d actual_balance=%.2f expected_balance_after=%.2f",
            $timestamp,
            $m['user_id'],
            (float) $m['actual_balance'],
            (float) $m['expected_balance']
        );
    }

    file_put_contents($logFile, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);

    // ── Write JSON summary ───────────────────────────────────────────────
    $summary = [
        'status'              => $status,
        'sum_user_balances'   => round($sumBalances, 2),
        'sum_credits'         => round($sumCredits, 2),
        'sum_debits'          => round($sumDebits, 2),
        'expected_balance'    => round($expectedBalance, 2),
        'discrepancy'         => round($discrepancy, 2),
        'per_user_mismatches' => $perUserMismatches,
        'checked_at'          => $isoTimestamp,
    ];

    file_put_contents($jsonFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

    echo $logLine . PHP_EOL;

} catch (Exception $e) {
    // On DB or other errors, write fail status
    $errorMsg = sprintf("[%s] CRITICAL — reconciliation error: %s", $timestamp, $e->getMessage());
    file_put_contents($logFile, $errorMsg . PHP_EOL, FILE_APPEND | LOCK_EX);

    $summary = [
        'status'              => 'fail',
        'sum_user_balances'   => 0,
        'sum_credits'         => 0,
        'sum_debits'          => 0,
        'expected_balance'    => 0,
        'discrepancy'         => 0,
        'per_user_mismatches' => 0,
        'checked_at'          => $isoTimestamp,
    ];
    file_put_contents($jsonFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

    fwrite(STDERR, $errorMsg . PHP_EOL);
    $hasDiscrepancy = true;
}

exit($hasDiscrepancy ? 1 : 0);
