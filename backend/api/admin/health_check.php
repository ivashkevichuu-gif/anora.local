<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireAdmin();

// ── Check 1: No Money Created (global balance invariant) ─────────────────────
$stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) AS total FROM user_balances");
$sumBalances = (float) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'credit'");
$sumCredits = (float) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE direction = 'debit'");
$sumDebits = (float) $stmt->fetchColumn();

$expected = $sumCredits - $sumDebits;
$discrepancy = abs($sumBalances - $expected);
$noMoneyCreatedPassed = $discrepancy <= 0.01;

$noMoneyCreated = [
    'passed'  => $noMoneyCreatedPassed,
    'details' => [
        'sum_balances' => round($sumBalances, 2),
        'sum_credits'  => round($sumCredits, 2),
        'sum_debits'   => round($sumDebits, 2),
        'expected'     => round($expected, 2),
        'discrepancy'  => round($discrepancy, 2),
    ],
];

// ── Check 2: No Money Lost (per-user balance_after match) ────────────────────
$stmt = $pdo->query(
    "SELECT ub.user_id,
            ub.balance AS actual_balance,
            le.balance_after AS expected_balance
     FROM user_balances ub
     INNER JOIN (
         SELECT user_id, balance_after
         FROM ledger_entries le1
         WHERE le1.id = (
             SELECT MAX(le2.id) FROM ledger_entries le2 WHERE le2.user_id = le1.user_id
         )
     ) le ON le.user_id = ub.user_id
     WHERE ABS(ub.balance - le.balance_after) > 0.01"
);
$mismatchedUsers = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mismatchedUsers[] = [
        'user_id'          => (int) $row['user_id'],
        'actual_balance'   => round((float) $row['actual_balance'], 2),
        'expected_balance' => round((float) $row['expected_balance'], 2),
    ];
}

$noMoneyLost = [
    'passed'           => empty($mismatchedUsers),
    'mismatched_users' => $mismatchedUsers,
];

// ── Check 3: Everything Traceable (non-null/non-empty reference_id & reference_type) ─
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM ledger_entries
     WHERE reference_id IS NULL
        OR reference_id = ''
        OR reference_type IS NULL
        OR reference_type = ''"
);
$untraceableCount = (int) $stmt->fetchColumn();

$everythingTraceable = [
    'passed'            => $untraceableCount === 0,
    'untraceable_count' => $untraceableCount,
];

// ── Compose response ─────────────────────────────────────────────────────────
$allPassed = $noMoneyCreatedPassed && empty($mismatchedUsers) && $untraceableCount === 0;

echo json_encode([
    'status'     => $allPassed ? 'ok' : 'fail',
    'checks'     => [
        'no_money_created'     => $noMoneyCreated,
        'no_money_lost'        => $noMoneyLost,
        'everything_traceable' => $everythingTraceable,
    ],
    'checked_at' => date('c'),
]);
