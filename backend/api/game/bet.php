<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
require_once __DIR__ . '/../../includes/game_engine.php';
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$room = (int)($input['room'] ?? 1);
$clientSeed = trim($input['client_seed'] ?? '');

$userId = $_SESSION['user_id'];
$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

try {
    $state = $engine->placeBet($userId, $room, $clientSeed);
    $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $balStmt->execute([$userId]);
    $balance = (float)$balStmt->fetchColumn();

    echo json_encode([
        'ok'      => true,
        'state'   => $state,
        'balance' => $balance,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
