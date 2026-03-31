<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$stmt = $pdo->prepare('SELECT id, email, balance, bank_details, ref_code, referral_earnings FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
$countStmt->execute([$_SESSION['user_id']]);
$referredCount = (int)$countStmt->fetchColumn();

echo json_encode(['user' => [
    'id'                => (int)$user['id'],
    'email'             => $user['email'],
    'balance'           => (float)$user['balance'],
    'bank_details'      => $user['bank_details'],
    'ref_code'          => $user['ref_code'],
    'referral_earnings' => (float)$user['referral_earnings'],
    'referred_count'    => $referredCount,
]]);
