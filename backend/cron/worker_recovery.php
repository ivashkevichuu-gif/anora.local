<?php
/**
 * Worker Recovery Cron — dead worker detection and task reclaim.
 *
 * Checks heartbeat keys worker:*:heartbeat.
 * If heartbeat absent > 30s → XCLAIM pending tasks from dead worker.
 * Logs recovery via StructuredLogger.
 *
 * Run via: php backend/cron/worker_recovery.php
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 2.4, 9.5
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/redis_client.php';
require_once __DIR__ . '/../includes/queue_service.php';
require_once __DIR__ . '/../includes/structured_logger.php';

const STREAM_NAME = 'game:rounds';
const GROUP_NAME = 'workers';
const MIN_IDLE_MS = 60000; // 60 seconds idle before claiming

$logger = StructuredLogger::getInstance();
$redisClient = RedisClient::getInstance();

if (!$redisClient->isAvailable()) {
    $logger->warning('Redis unavailable, cannot perform worker recovery', [], [
        'component' => 'WorkerRecovery',
    ]);
    echo "[WorkerRecovery] Redis unavailable, exiting.\n";
    exit(1);
}

$redis = $redisClient->getConnection();
$queueService = new QueueService($redisClient);
$currentConsumer = $queueService->getConsumerName();

$logger->info('Worker recovery started', [
    'consumer' => $currentConsumer,
], ['component' => 'WorkerRecovery']);

try {
    // Get pending entries summary for the consumer group
    $pending = $redis->xPending(STREAM_NAME, GROUP_NAME);

    if (empty($pending) || $pending[0] === 0) {
        $logger->info('No pending messages found', [], ['component' => 'WorkerRecovery']);
        echo "[WorkerRecovery] No pending messages.\n";
        exit(0);
    }

    $totalPending = $pending[0];
    // $pending[3] contains [consumer_name, pending_count] pairs
    $consumers = $pending[3] ?? [];

    $claimedTotal = 0;

    foreach ($consumers as $consumerInfo) {
        $consumerName = $consumerInfo[0];
        $pendingCount = (int)$consumerInfo[1];

        if ($pendingCount === 0) {
            continue;
        }

        // Check if this consumer's heartbeat is still alive
        $heartbeatKey = "worker:{$consumerName}:heartbeat";
        $heartbeatAlive = $redis->exists($heartbeatKey);

        if ($heartbeatAlive) {
            // Worker is alive, skip
            continue;
        }

        // Worker is dead — XCLAIM its pending tasks
        $logger->warning('Dead worker detected, claiming pending tasks', [
            'dead_worker' => $consumerName,
            'pending_count' => $pendingCount,
        ], ['component' => 'WorkerRecovery']);

        $claimed = $queueService->claimPending(
            STREAM_NAME,
            GROUP_NAME,
            $currentConsumer,
            MIN_IDLE_MS
        );

        $claimedCount = count($claimed);
        $claimedTotal += $claimedCount;

        $logger->info('Claimed tasks from dead worker', [
            'dead_worker' => $consumerName,
            'claimed_count' => $claimedCount,
        ], ['component' => 'WorkerRecovery']);

        echo "[WorkerRecovery] Claimed {$claimedCount} tasks from dead worker: {$consumerName}\n";
    }

    $logger->info('Worker recovery completed', [
        'total_claimed' => $claimedTotal,
    ], ['component' => 'WorkerRecovery']);

    echo "[WorkerRecovery] Done. Total claimed: {$claimedTotal}\n";

} catch (\Throwable $e) {
    $logger->error('Worker recovery failed', [], [
        'component' => 'WorkerRecovery',
    ], $e);
    echo "[WorkerRecovery] Error: " . $e->getMessage() . "\n";
    exit(1);
}
