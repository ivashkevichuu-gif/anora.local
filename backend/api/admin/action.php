<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$id     = (int)($input['id'] ?? 0);

if (!$id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['error' => 'Invalid request.']); exit;
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
        $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$req['amount'], $req['user_id']]);
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
