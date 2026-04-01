<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$status  = trim($_GET['status'] ?? '');

$where  = '';
$params = [];

if ($status !== '') {
    $where  = 'WHERE ci.status = ?';
    $params[] = $status;
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM crypto_invoices ci $where");
$cntStmt->execute($params);
$totalCount = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$sql = "SELECT ci.id, u.email, ci.amount_usd, ci.credited_usd, ci.currency,
               ci.status, ci.nowpayments_invoice_id, ci.created_at
        FROM crypto_invoices ci
        JOIN users u ON u.id = ci.user_id
        $where
        ORDER BY ci.created_at DESC LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i,   $offset,  PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
    'invoices'    => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
]);
