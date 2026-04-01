<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Try reading System_Account balance from user_balances (SYSTEM_USER_ID = 0)
$balance = null;
try {
    $ubRow = $pdo->prepare('SELECT balance FROM user_balances WHERE user_id = 0');
    $ubRow->execute();
    $row = $ubRow->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $balance = (float)$row['balance'];
    }
} catch (PDOException $e) {
    // Table may not exist yet during migration
}

// Fall back to system_balance singleton if user_balances row doesn't exist
if ($balance === null) {
    $balanceRow = $pdo->query('SELECT balance FROM system_balance WHERE id = 1')->fetch();
    $balance = $balanceRow ? (float)$balanceRow['balance'] : 0.00;
}

// Aggregates from system_transactions (legacy)
$totals = $pdo->query(
    "SELECT
        SUM(CASE WHEN type = 'commission' THEN amount ELSE 0 END) AS total_commission,
        SUM(CASE WHEN type = 'referral_unclaimed' THEN amount ELSE 0 END) AS total_referral_unclaimed
     FROM system_transactions"
)->fetch();

// Total count for pagination
$totalCount = (int)$pdo->query('SELECT COUNT(*) FROM system_transactions')->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Paginated transactions with source user email
$stmt = $pdo->prepare(
    'SELECT st.id, st.amount, st.type, st.source_user_id, st.payout_id, st.created_at,
            u.email AS source_email
     FROM system_transactions st
     LEFT JOIN users u ON u.id = st.source_user_id
     ORDER BY st.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

echo json_encode([
    'system_balance' => [
        'balance'                  => $balance,
        'total_commission'         => (float)($totals['total_commission'] ?? 0),
        'total_referral_unclaimed' => (float)($totals['total_referral_unclaimed'] ?? 0),
    ],
    'transactions' => $transactions,
    'total_count'  => $totalCount,
    'total_pages'  => $totalPages,
    'page'         => $page,
]);
