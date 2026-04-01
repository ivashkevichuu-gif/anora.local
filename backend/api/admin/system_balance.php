<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireAdmin();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// System Account balance from user_balances (SYSTEM_USER_ID = 1)
$balance = 0.00;
try {
    $ubRow = $pdo->prepare('SELECT balance FROM user_balances WHERE user_id = ?');
    $ubRow->execute([SYSTEM_USER_ID]);
    $row = $ubRow->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $balance = (float)$row['balance'];
    }
} catch (PDOException $e) {
    // Fall back to system_balance singleton
    $balanceRow = $pdo->query('SELECT balance FROM system_balance WHERE id = 1')->fetch();
    $balance = $balanceRow ? (float)$balanceRow['balance'] : 0.00;
}

// Aggregates from ledger_entries for system user
$totals = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN type = 'system_fee' THEN amount ELSE 0 END) AS total_commission,
        SUM(CASE WHEN type = 'referral_bonus' THEN amount ELSE 0 END) AS total_referral_unclaimed
     FROM ledger_entries WHERE user_id = ?"
);
$totals->execute([SYSTEM_USER_ID]);
$totalsRow = $totals->fetch(PDO::FETCH_ASSOC);

// Total count for pagination
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE user_id = ?');
$countStmt->execute([SYSTEM_USER_ID]);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Paginated ledger entries for system user
$stmt = $pdo->prepare(
    'SELECT le.id, le.amount, le.type, le.direction, le.balance_after,
            le.reference_id, le.reference_type, le.created_at
     FROM ledger_entries le
     WHERE le.user_id = :uid
     ORDER BY le.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':uid', SYSTEM_USER_ID, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'system_balance' => [
        'balance'                  => $balance,
        'total_commission'         => (float)($totalsRow['total_commission'] ?? 0),
        'total_referral_unclaimed' => (float)($totalsRow['total_referral_unclaimed'] ?? 0),
    ],
    'transactions' => $transactions,
    'total_count'  => $totalCount,
    'total_pages'  => $totalPages,
    'page'         => $page,
]);
