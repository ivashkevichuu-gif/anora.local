<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ledger_service.php';
require_once __DIR__ . '/../../includes/game_engine.php';
require_once __DIR__ . '/../../includes/redis_client.php';
require_once __DIR__ . '/../../includes/cache_service.php';
require_once __DIR__ . '/../../includes/queue_service.php';
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$room = (int)($input['room'] ?? 1);
$clientSeed = trim($input['client_seed'] ?? '');

$userId = $_SESSION['user_id'];
$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

try {
    // Check round status before bet to detect spinning transition
    $preStmt = $pdo->prepare(
        "SELECT id, status FROM game_rounds WHERE room = ? AND status IN ('waiting','active','spinning') ORDER BY id DESC LIMIT 1"
    );
    $preStmt->execute([$room]);
    $preBetRound = $preStmt->fetch(PDO::FETCH_ASSOC);

    $state = $engine->placeBet($userId, $room, $clientSeed);

    // Get round info after bet
    $roundId = $state['round']['round_id'] ?? 0;
    $roundStatus = $state['round']['status'] ?? '';

    // PUBLISH bet:placed event via Redis Pub/Sub
    $redisClient = RedisClient::getInstance();
    if ($redisClient->isAvailable()) {
        $redis = $redisClient->getConnection();

        // Publish bet:placed event
        try {
            $betEvent = json_encode([
                'round_id' => $roundId,
                'user_id'  => $userId,
                'amount'   => (float)$room,
                'room'     => $room,
            ]);
            $redis->publish('bet:placed', $betEvent);
        } catch (\Throwable $e) {
            error_log('[bet.php] Failed to PUBLISH bet:placed: ' . $e->getMessage());
        }

        // If round transitioned to 'spinning', XADD to Redis Stream for game worker
        if ($roundStatus === 'spinning' || ($preBetRound && $preBetRound['status'] !== 'spinning' && $roundStatus === 'spinning')) {
            try {
                $queueService = new QueueService($redisClient);
                $queueService->addTask('game:rounds', [
                    'round_id'  => (string)$roundId,
                    'room'      => (string)$room,
                    'timestamp' => (string)time(),
                ]);
            } catch (\Throwable $e) {
                error_log('[bet.php] Failed to XADD game:rounds: ' . $e->getMessage());
            }
        }

        // Invalidate game:state:{room} cache
        try {
            $cache = new CacheService($redisClient);
            $cache->invalidateGameState($room);
        } catch (\Throwable $e) {
            error_log('[bet.php] Failed to invalidate cache: ' . $e->getMessage());
        }
    }

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
