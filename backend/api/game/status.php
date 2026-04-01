<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
require_once __DIR__ . '/../../includes/game_engine.php';

$room = (int)($_GET['room'] ?? 1);
if (!in_array($room, [1, 10, 100], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid room']); exit;
}

$userId = $_SESSION['user_id'] ?? null;
$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

$state = $engine->getGameState($room, $userId);

// Add user balance from cache
$balance = null;
if ($userId) {
    $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $balStmt->execute([$userId]);
    $balance = (float)$balStmt->fetchColumn();
}

echo json_encode([
    'game'     => $state['round'],
    'bets'     => $state['bets'],
    'stats'    => [
        'unique_players' => $state['unique_players'],
        'total_bets'     => $state['total_bets'],
    ],
    'my_stats' => $state['my_stats'],
    'previous' => $state['previous'],
    'balance'  => $balance,
]);
