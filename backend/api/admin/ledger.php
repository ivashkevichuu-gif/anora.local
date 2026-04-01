<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if (!empty($_GET['user_id'])) {
    $where[]  = 'le.user_id = ?';
    $params[] = (int)$_GET['user_id'];
}
if (!empty($_GET['email'])) {
    $where[]  = 'u.email LIKE ?';
    $params[] = '%' . $_GET['email'] . '%';
}
if (!empty($_GET['type'])) {
    $where[]  = 'le.type = ?';
    $params[] = $_GET['type'];
}
if (!empty($_GET['date_from'])) {
    $where[]  = 'le.created_at >= ?';
    $params[] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $where[]  = 'le.created_at <= ?';
    $params[] = $_GET['date_to'] . ' 23:59:59';
}
if (!empty($_GET['reference_type'])) {
    $where[]  = 'le.reference_type = ?';
    $params[] = $_GET['reference_type'];
}
if (!empty($_GET['reference_id'])) {
    $where[]  = 'le.reference_id = ?';
    $params[] = $_GET['reference_id'];
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM ledger_entries le JOIN users u ON u.id = le.user_id $whereSQL"
);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

// Fetch page
$dataStmt = $pdo->prepare(
    "SELECT le.id, le.user_id, u.email, le.type, le.amount, le.direction,
            le.balance_after, le.reference_id, le.reference_type, le.created_at
     FROM ledger_entries le
     JOIN users u ON u.id = le.user_id
     $whereSQL
     ORDER BY le.id DESC
     LIMIT $perPage OFFSET $offset"
);
$dataStmt->execute($params);

echo json_encode([
    'entries'     => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
    'page'        => $page,
    'per_page'    => $perPage,
    'total_count' => $totalCount,
    'total_pages' => (int)ceil($totalCount / max(1, $perPage)),
]);
