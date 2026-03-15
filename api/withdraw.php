<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$amount = round((float)($input['amount'] ?? 0), 2);
$bank   = trim($input['bank_details'] ?? '');

if ($amount <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid amount.']);
    exit;
}
if (empty($bank)) {
    echo json_encode(['ok' => false, 'message' => 'Bank details are required.']);
    exit;
}

$userId = $_SESSION['user_id'];

// Check balance
$balance = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
$pdo->beginTransaction();
$balance->execute([$userId]);
$balance = (float)$balance->fetchColumn();

if ($amount > $balance) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Insufficient balance.']);
    exit;
}

try {
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status) VALUES (?, "withdrawal", ?, "pending")')
        ->execute([$userId, $amount]);
    $txId = $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO withdrawal_requests (user_id, transaction_id, amount, bank_details) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $txId, $amount, $bank]);

    // Reserve funds (deduct immediately, refund if rejected)
    $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
        ->execute([$amount, $userId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'Withdrawal request submitted. Pending approval.']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Request failed.']);
}
