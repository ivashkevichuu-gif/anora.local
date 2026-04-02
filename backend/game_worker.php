<?php
/**
 * Game Worker — Redis Streams based architecture.
 *
 * Replaces the old while(true) + MySQL polling with XREADGROUP BLOCK 5000.
 * Consumer name: {hostname}-{pid}.
 * Loop: XREADGROUP → finishRound() → XACK → PUBLISH game:finished.
 * Heartbeat: SETEX worker:{name}:heartbeat 30 alive every 10 seconds.
 * Graceful shutdown: pcntl_signal(SIGTERM) → finish current task → XACK → exit.
 * Logs metrics via StructuredLogger.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 2.1, 2.3, 2.5, 2.6, 9.1, 9.2, 9.3, 9.4, 9.6
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ledger_service.php';
require_once __DIR__ . '/includes/game_engine.php';
require_once __DIR__ . '/includes/redis_client.php';
require_once __DIR__ . '/includes/queue_service.php';
require_once __DIR__ . '/includes/cache_service.php';
require_once __DIR__ . '/includes/structured_logger.php';

// ── Configuration ───────────────────────────────────────────────────────────

const STREAM_NAME = 'game:rounds';
const GROUP_NAME = 'workers';
const BLOCK_TIMEOUT_MS = 5000;
const HEARTBEAT_INTERVAL = 10; // seconds
const HEARTBEAT_TTL = 30;      // seconds

// ── Setup ───────────────────────────────────────────────────────────────────

$logger = StructuredLogger::getInstance();
$redisClient = RedisClient::getInstance();
$queueService = new QueueService($redisClient);
$cacheService = new CacheService($redisClient);
$ledger = new LedgerService($pdo);
$engine = new GameEngine($pdo, $ledger);

$consumerName = $queueService->getConsumerName();
$running = true;
$lastHeartbeat = 0;
$processedCount = 0;
$errorCount = 0;
$totalProcessingTime = 0.0;

$logger->info('Game worker started', [
    'consumer' => $consumerName,
], ['component' => 'GameWorker']);

echo "[GameWorker] Started as consumer: {$consumerName}\n";

// ── Graceful shutdown via SIGTERM ───────────────────────────────────────────

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running, $logger, $consumerName) {
        $running = false;
        $logger->info('SIGTERM received, shutting down gracefully', [
            'consumer' => $consumerName,
        ], ['component' => 'GameWorker']);
        echo "[GameWorker] SIGTERM received, finishing current task...\n";
    });

    pcntl_signal(SIGINT, function () use (&$running, $logger, $consumerName) {
        $running = false;
        $logger->info('SIGINT received, shutting down gracefully', [
            'consumer' => $consumerName,
        ], ['component' => 'GameWorker']);
    });
}

// ── Heartbeat function ──────────────────────────────────────────────────────

function sendHeartbeat(RedisClient $redisClient, string $consumerName, StructuredLogger $logger): void
{
    if (!$redisClient->isAvailable()) {
        return;
    }
    try {
        $redis = $redisClient->getConnection();
        $redis->setex("worker:{$consumerName}:heartbeat", HEARTBEAT_TTL, 'alive');
    } catch (\Throwable $e) {
        $logger->warning('Failed to send heartbeat', [], [
            'component' => 'GameWorker',
            'consumer' => $consumerName,
            'error' => $e->getMessage(),
        ]);
    }
}

// ── Main loop ───────────────────────────────────────────────────────────────

while ($running) {
    // Dispatch signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Send heartbeat every HEARTBEAT_INTERVAL seconds
    $now = time();
    if ($now - $lastHeartbeat >= HEARTBEAT_INTERVAL) {
        sendHeartbeat($redisClient, $consumerName, $logger);
        $lastHeartbeat = $now;
    }

    // Check Redis availability
    if (!$redisClient->isAvailable()) {
        $logger->warning('Redis unavailable, falling back to sleep', [], [
            'component' => 'GameWorker',
        ]);
        sleep(5);
        continue;
    }

    // XREADGROUP BLOCK 5000
    try {
        $messages = $queueService->readTasks(
            STREAM_NAME,
            GROUP_NAME,
            $consumerName,
            1,
            BLOCK_TIMEOUT_MS
        );
    } catch (\Throwable $e) {
        $logger->error('XREADGROUP failed', [], [
            'component' => 'GameWorker',
            'consumer' => $consumerName,
        ], $e);
        $errorCount++;
        sleep(1);
        continue;
    }

    if (empty($messages)) {
        continue; // No messages, loop back to XREADGROUP
    }

    foreach ($messages as $messageId => $fields) {
        if (!$running) {
            break; // Stop processing if shutdown requested
        }

        $roundId = isset($fields['round_id']) ? (int)$fields['round_id'] : 0;
        $room = isset($fields['room']) ? (int)$fields['room'] : 0;

        if ($roundId <= 0) {
            $logger->warning('Invalid message: missing round_id', [
                'message_id' => $messageId,
                'fields' => $fields,
            ], ['component' => 'GameWorker']);
            // ACK invalid messages to prevent reprocessing
            $queueService->ack(STREAM_NAME, GROUP_NAME, $messageId);
            continue;
        }

        $startTime = microtime(true);

        $logger->info('Processing round', [
            'round_id' => $roundId,
            'room' => $room,
            'message_id' => $messageId,
        ], ['component' => 'GameWorker']);

        try {
            // finishRound() — existing GameEngine logic (unchanged)
            $result = $engine->finishRound($roundId);

            // XACK — acknowledge successful processing
            $queueService->ack(STREAM_NAME, GROUP_NAME, $messageId);

            $processingTime = microtime(true) - $startTime;
            $processedCount++;
            $totalProcessingTime += $processingTime;

            $logger->info('Round finished successfully', [
                'round_id' => $roundId,
                'room' => $room,
                'processing_time_ms' => round($processingTime * 1000, 2),
                'winner_id' => $result['winner_id'] ?? null,
            ], ['component' => 'GameWorker']);

            // PUBLISH game:finished event
            try {
                $redis = $redisClient->getConnection();
                $eventData = json_encode([
                    'round_id'  => $roundId,
                    'winner_id' => (int)($result['winner_id'] ?? 0),
                    'room'      => $room,
                    'winner_net' => (float)($result['winner_net'] ?? 0),
                ]);
                $redis->publish('game:finished', $eventData);
            } catch (\Throwable $e) {
                $logger->warning('Failed to PUBLISH game:finished', [], [
                    'component' => 'GameWorker',
                    'round_id' => $roundId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Invalidate game:state:{room} cache
            try {
                $cacheService->invalidateGameState($room);
            } catch (\Throwable $e) {
                $logger->warning('Failed to invalidate cache', [], [
                    'component' => 'GameWorker',
                    'room' => $room,
                ]);
            }

        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $startTime;
            $errorCount++;

            $logger->error('Failed to process round', [
                'round_id' => $roundId,
                'room' => $room,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ], ['component' => 'GameWorker'], $e);

            // Do NOT XACK — let Redis redeliver after PEL timeout
        }
    }
}

// ── Shutdown ────────────────────────────────────────────────────────────────

$avgTime = $processedCount > 0 ? round($totalProcessingTime / $processedCount * 1000, 2) : 0;

$logger->info('Game worker stopped', [
    'consumer' => $consumerName,
    'processed_rounds' => $processedCount,
    'errors' => $errorCount,
    'avg_processing_time_ms' => $avgTime,
], ['component' => 'GameWorker']);

echo "[GameWorker] Stopped. Processed: {$processedCount}, Errors: {$errorCount}, Avg time: {$avgTime}ms\n";
