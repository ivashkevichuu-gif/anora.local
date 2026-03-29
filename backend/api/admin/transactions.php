<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$txs = $pdo->query(
    'SELECT t.id, t.type, t.amount, t.status, t.note, t.created_at, u.email
     FROM transactions t JOIN users u ON u.id = t.user_id
     ORDER BY t.created_at DESC'
)->fetchAll();

echo json_encode(['transactions' => $txs]);
