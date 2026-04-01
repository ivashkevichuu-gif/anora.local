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

// Lightweight fallback: if an active round's countdown expired and the worker
// hasn't caught it yet, trigger the transition inline. This prevents the UI
// from freezing at countdown=0 when the worker is slow or not running.
try {
    $staleStmt = $pdo->prepare(
        "SELECT id FROM game_rounds
         WHERE room = ? AND status = 'active'
         AND started_at <= NOW() - INTERVAL 30 SECOND
         LIMIT 1"
    );
    $staleStmt->execute([$room]);
    $staleRound = $staleStmt->fetch(PDO::FETCH_ASSOC);

    if ($staleRound) {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM game_rounds WHERE id = ? AND status = 'active' FOR UPDATE");
        $lock->execute([$staleRound['id']]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        if ($locked && $locked['status'] === 'active') {
            $elapsed = time() - strtotime($locked['started_at']);
            if ($elapsed >= 30) {
                $pdo->prepare("UPDATE game_rounds SET status = 'spinning', spinning_at = NOW() WHERE id = ?")->execute([$locked['id']]);
                // Also finish the round immediately
                $pdo->commit();
                try {
                    $engine->finishRound((int)$locked['id']);
                } catch (\Throwable $e) {
                    error_log("[StatusFallback] finishRound error: " . $e->getMessage());
                }
            } else {
                $pdo->commit();
            }
        } else {
            $pdo->commit();
        }
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("[StatusFallback] Error: " . $e->getMessage());
}

// Also process any spinning rounds that the worker hasn't finished yet
try {
    $spinStmt = $pdo->prepare(
        "SELECT id FROM game_rounds WHERE room = ? AND status = 'spinning' LIMIT 1"
    );
    $spinStmt->execute([$room]);
    $spinRound = $spinStmt->fetch(PDO::FETCH_ASSOC);
    if ($spinRound) {
        $engine->finishRound((int)$spinRound['id']);
    }
} catch (\Throwable $e) {
    error_log("[StatusFallback] Spinning finish error: " . $e->getMessage());
}

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
