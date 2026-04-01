<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true);
$amount = round((float)($input['amount'] ?? 0), 2);

if ($amount <= 0) {
    echo json_encode(['error' => 'Invalid amount.']); exit;
}

$userId = $_SESSION['user_id'];
$pdo->beginTransaction();
try {
    // Keep existing transactions insert for backward compatibility
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status) VALUES (?, "deposit", ?, "completed")')
        ->execute([$userId, $amount]);
    $txId = $pdo->lastInsertId();

    // Use LedgerService instead of direct balance update
    $ledger = new LedgerService($pdo);
    $entry = $ledger->addEntry($userId, 'deposit', $amount, 'credit', (string)$txId, 'fiat_deposit', ['source' => 'deposit']);

    $pdo->commit();

    echo json_encode(['message' => 'Deposit successful.', 'balance' => round((float)$entry['balance_after'], 2)]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
}
