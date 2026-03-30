<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/lottery.php';

requireLogin();

$userId = (int)$_SESSION['user_id'];

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
// FINAL FIX: trim and pass raw — strict validation happens inside placeBet()
$clientSeed = trim((string)($input['client_seed'] ?? ''));
$room       = (int)($input['room'] ?? 1);

try {
    $state = placeBet($pdo, $userId, $room, $clientSeed);

    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $balance = (float)$stmt->fetchColumn();

    echo json_encode(['ok' => true, 'state' => $state, 'balance' => $balance]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
