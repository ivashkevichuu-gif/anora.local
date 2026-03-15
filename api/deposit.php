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

if ($amount <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid amount.']);
    exit;
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
    $balance = $balance->fetchColumn();

    echo json_encode(['ok' => true, 'message' => 'Deposit successful.', 'balance' => $balance]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Transaction failed.']);
}
