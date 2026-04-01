<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
requireAdmin();

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$id     = (int)($input['id'] ?? 0);

if (!$id || !in_array($action, ['approve', 'reject', 'ban', 'clear_fraud_flag'])) {
    echo json_encode(['error' => 'Invalid request.']); exit;
}

// Handle ban action
if ($action === 'ban') {
    $user = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $user->execute([$id]);
    if (!$user->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }
    $pdo->prepare('UPDATE users SET is_banned = 1 WHERE id = ?')->execute([$id]);
    echo json_encode(['message' => 'User banned.']);
    exit;
}

// Handle clear_fraud_flag action
if ($action === 'clear_fraud_flag') {
    $user = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $user->execute([$id]);
    if (!$user->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }
    $pdo->prepare('UPDATE users SET fraud_flagged = 0 WHERE id = ?')->execute([$id]);
    echo json_encode(['message' => 'Fraud flag cleared.']);
    exit;
}

$req = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND status = "pending"');
$req->execute([$id]);
$req = $req->fetch();

if (!$req) {
    echo json_encode(['error' => 'Not found or already processed.']); exit;
}

$pdo->beginTransaction();
try {
    if ($action === 'approve') {
        $pdo->prepare('UPDATE withdrawal_requests SET status = "approved" WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE transactions SET status = "approved" WHERE id = ?')->execute([$req['transaction_id']]);
    } else {
        // Use LedgerService for balance refund instead of direct SQL
        $ledger = new LedgerService($pdo);
        $ledger->addEntry(
            (int)$req['user_id'],
            'withdrawal_refund',
            (float)$req['amount'],
            'credit',
            'withdrawal_reject:' . $id,
            'admin_action',
            ['source' => 'admin']
        );

        $pdo->prepare('UPDATE withdrawal_requests SET status = "rejected" WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE transactions SET status = "rejected" WHERE id = ?')->execute([$req['transaction_id']]);
    }
    $pdo->commit();
    echo json_encode(['message' => 'Done.']);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Action failed: ' . $e->getMessage()]);
}
