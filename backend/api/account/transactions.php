<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$stmt = $pdo->prepare(
    'SELECT id, type, amount, status, note, created_at FROM transactions
     WHERE user_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$_SESSION['user_id']]);
echo json_encode(['transactions' => $stmt->fetchAll()]);
