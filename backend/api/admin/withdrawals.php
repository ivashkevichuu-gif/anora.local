<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$reqs = $pdo->query(
    'SELECT wr.id, wr.amount, wr.bank_details, wr.status, wr.created_at, wr.transaction_id, u.email
     FROM withdrawal_requests wr JOIN users u ON u.id = wr.user_id
     ORDER BY wr.created_at DESC'
)->fetchAll();

echo json_encode(['requests' => $reqs]);
