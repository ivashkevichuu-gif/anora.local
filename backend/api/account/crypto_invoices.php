<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$userId  = (int)$_SESSION['user_id'];
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM crypto_invoices WHERE user_id = ?');
$cntStmt->execute([$userId]);
$totalCount = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $pdo->prepare(
    'SELECT id, nowpayments_invoice_id, amount_usd, credited_usd, amount_crypto,
            currency, status, invoice_url, created_at, updated_at
     FROM crypto_invoices WHERE user_id = ?
     ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $userId,  PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset,  PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
    'invoices'    => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
]);
