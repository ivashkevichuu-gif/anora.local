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

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM user_transactions WHERE user_id = ?');
$cntStmt->execute([$userId]);
$totalCount = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Use all positional params — no mixing with named params
$stmt = $pdo->prepare(
    'SELECT id, type, amount, game_id, payout_id, created_at
     FROM user_transactions WHERE user_id = ?
     ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $userId,  PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset,  PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
    'transactions' => $stmt->fetchAll(),
    'total_count'  => $totalCount,
    'total_pages'  => $totalPages,
    'page'         => $page,
]);
