<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireLogin();

$stmt = $pdo->prepare(
    'SELECT id, email, balance, bank_details, ref_code, referral_earnings,
            nickname, nickname_changed_at, default_wallet_address, default_crypto_currency
     FROM users WHERE id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
$countStmt->execute([$_SESSION['user_id']]);
$referredCount = (int)$countStmt->fetchColumn();

// Can the user change their nickname today?
$canChangeNickname = true;
if ($user['nickname_changed_at']) {
    $lastChange = strtotime($user['nickname_changed_at']);
    $canChangeNickname = (time() - $lastChange) >= 86400; // 24 hours
}

echo json_encode(['user' => [
    'id'                  => (int)$user['id'],
    'email'               => $user['email'],
    'balance'             => (float)$user['balance'],
    'bank_details'        => $user['bank_details'],
    'ref_code'            => $user['ref_code'],
    'referral_earnings'   => (float)$user['referral_earnings'],
    'referred_count'      => $referredCount,
    'nickname'            => $user['nickname'],
    'nickname_changed_at' => $user['nickname_changed_at'],
    'can_change_nickname'      => $canChangeNickname,
    'default_wallet_address'   => $user['default_wallet_address'],
    'default_crypto_currency'  => $user['default_crypto_currency'],
]]);
