<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true);
$amount = round((float)($input['amount'] ?? 0), 2);
$bank   = trim($input['bank_details'] ?? '');

if ($amount <= 0) { echo json_encode(['error' => 'Invalid amount.']); exit; }
if (empty($bank)) { echo json_encode(['error' => 'Bank details are required.']); exit; }

$userId = $_SESSION['user_id'];
$pdo->beginTransaction();

try {
    // Keep existing transactions insert for backward compatibility
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status) VALUES (?, "withdrawal", ?, "pending")')
        ->execute([$userId, $amount]);
    $txId = $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO withdrawal_requests (user_id, transaction_id, amount, bank_details) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $txId, $amount, $bank]);

    // Use LedgerService instead of direct balance update
    $ledger = new LedgerService($pdo);
    $ledger->addEntry($userId, 'withdrawal', $amount, 'debit', (string)$txId, 'fiat_withdrawal', ['source' => 'deposit']);

    $pdo->commit();
    echo json_encode(['message' => 'Withdrawal request submitted. Pending approval.']);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    if ($e->getMessage() === 'Insufficient balance') {
        echo json_encode(['error' => 'Insufficient balance.']); exit;
    }
    http_response_code(500);
    echo json_encode(['error' => 'Request failed: ' . $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Request failed: ' . $e->getMessage()]);
}
