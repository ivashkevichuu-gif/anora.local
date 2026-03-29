<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true);
$amount = round((float)($input['amount'] ?? 0), 2);

if ($amount <= 0) {
    echo json_encode(['error' => 'Invalid amount.']); exit;
}

$userId = $_SESSION['user_id'];
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO transactions (user_id, type, amount, status) VALUES (?, "deposit", ?, "completed")')
        ->execute([$userId, $amount]);
    $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')
        ->execute([$amount, $userId]);
    $pdo->commit();

    $balance = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
    $balance->execute([$userId]);
    echo json_encode(['message' => 'Deposit successful.', 'balance' => (float)$balance->fetchColumn()]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
}
