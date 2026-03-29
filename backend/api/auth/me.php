<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$stmt = $pdo->prepare('SELECT id, email, balance, bank_details FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

echo json_encode(['user' => [
    'id'           => (int)$user['id'],
    'email'        => $user['email'],
    'balance'      => (float)$user['balance'],
    'bank_details' => $user['bank_details'],
]]);
