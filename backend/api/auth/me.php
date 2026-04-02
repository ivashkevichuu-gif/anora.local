<?php
/**
 * GET /api/auth/me
 *
 * Returns the current authenticated user's profile.
 * Uses JWT instead of $_SESSION, with backward compatibility.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 1.1, 1.2
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth();

$userId = getCurrentUserId();

$stmt = $pdo->prepare(
    'SELECT id, email, balance, bank_details, ref_code, referral_earnings,
            nickname, nickname_changed_at, default_wallet_address, default_crypto_currency
     FROM users WHERE id = ?'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
$countStmt->execute([$userId]);
$referredCount = (int)$countStmt->fetchColumn();

$canChangeNickname = true;
if ($user['nickname_changed_at']) {
    $lastChange = strtotime($user['nickname_changed_at']);
    $canChangeNickname = (time() - $lastChange) >= 86400;
}

echo json_encode(['user' => [
    'id'                       => (int)$user['id'],
    'email'                    => $user['email'],
    'balance'                  => (float)$user['balance'],
    'bank_details'             => $user['bank_details'],
    'ref_code'                 => $user['ref_code'],
    'referral_earnings'        => (float)$user['referral_earnings'],
    'referred_count'           => $referredCount,
    'nickname'                 => $user['nickname'],
    'nickname_changed_at'      => $user['nickname_changed_at'],
    'can_change_nickname'      => $canChangeNickname,
    'default_wallet_address'   => $user['default_wallet_address'],
    'default_crypto_currency'  => $user['default_crypto_currency'],
]]);
