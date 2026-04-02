<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';
require_once __DIR__ . '/structured_logger.php';

/**
 * QueueService — Redis Streams based task queue with consumer groups.
 *
 * Provides methods for adding tasks (XADD), reading tasks (XREADGROUP),
 * acknowledging tasks (XACK), and claiming pending tasks from dead workers (XCLAIM).
 * Consumer groups are created automatically on first call (XGROUP CREATE ... MKSTREAM).
 * Consumer name format: {hostname}-{pid}.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 2.1, 2.3, 2.4
 */
class QueueService
{
    private RedisClient $redisClient;
    private StructuredLogger $logger;
    private string $consumerName;

    /** @var array<string, bool> Tracks which groups have been ensured */
    private array $groupsEnsured = [];

    public function __construct(?RedisClient $redisClient = null, ?string $consumerName = null)
    {
        $this->redisClient = $redisClient ?? RedisClient::getInstance();
        $this->logger = StructuredLogger::getInstance();
        $this->consumerName = $consumerName ?? (gethostname() . '-' . getmypid());
    }

    /**
     * Get the consumer name for this instance.
     */
    public function getConsumerName(): string
    {
        return $this->consumerName;
    }

    /**
     * Add a task to a Redis Stream.
     *
     * @param string $stream  Stream name (e.g. "game:rounds")
     * @param array  $payload Associative array of field => value pairs
     * @return string|false   The message ID on success, false on failure
     */
    public function addTask(string $stream, array $payload): string|false
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('QueueService::addTask — Redis unavailable, task not queued', [], [
                'component' => 'QueueService',
                'stream' => $stream,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            $messageId = $redis->xAdd($stream, '*', $payload);

            $this->logger->debug('Task added to stream', [
                'stream' => $stream,
                'message_id' => $messageId,
            ], [
                'component' => 'QueueService',
            ]);

            return $messageId;
        } catch (\Throwable $e) {
            $this->logger->error('QueueService::addTask failed', [], [
                'component' => 'QueueService',
                'stream' => $stream,
            ], $e);
            return false;
        }
    }

    /**
     * Read tasks from a Redis Stream using consumer groups (XREADGROUP).
     *
     * Creates the consumer group automatically on first call if it doesn't exist.
     *
     * @param string $stream   Stream name
     * @param string $group    Consumer group name
     * @param string $consumer Consumer name (defaults to this instance's name)
     * @param int    $count    Max number of messages to read
     * @param int    $blockMs  Block timeout in milliseconds (0 = no block)
     * @return array           Array of messages [{id => {field => value}}, ...] or empty array
     */
    public function readTasks(
        string $stream,
        string $group,
        ?string $consumer = null,
        int $count = 1,
        int $blockMs = 5000
    ): array {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('QueueService::readTasks — Redis unavailable', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
            ]);
            return [];
        }

        $consumer = $consumer ?? $this->consumerName;

        $this->ensureGroup($stream, $group);

        try {
            $redis = $this->redisClient->getConnection();

            // XREADGROUP GROUP group consumer COUNT count BLOCK blockMs STREAMS stream >
            $result = $redis->xReadGroup(
                $group,
                $consumer,
                [$stream => '>'],
                $count,
                $blockMs
            );

            if ($result === false || !is_array($result)) {
                return [];
            }

            // phpredis returns [stream => [id => [field => value], ...]]
            return $result[$stream] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('QueueService::readTasks failed', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
                'consumer' => $consumer,
            ], $e);
            return [];
        }
    }

    /**
     * Acknowledge a message as processed (XACK).
     *
     * @param string $stream    Stream name
     * @param string $group     Consumer group name
     * @param string $messageId Message ID to acknowledge
     * @return bool             True on success, false on failure
     */
    public function ack(string $stream, string $group, string $messageId): bool
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('QueueService::ack — Redis unavailable', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
                'message_id' => $messageId,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            $result = $redis->xAck($stream, $group, [$messageId]);
            return $result >= 1;
        } catch (\Throwable $e) {
            $this->logger->error('QueueService::ack failed', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
                'message_id' => $messageId,
            ], $e);
            return false;
        }
    }

    /**
     * Claim pending messages from a dead/idle consumer (XCLAIM).
     *
     * @param string $stream      Stream name
     * @param string $group       Consumer group name
     * @param string $consumer    Consumer name to claim messages for
     * @param int    $minIdleMs   Minimum idle time in milliseconds
     * @return array              Array of claimed messages [{id => {field => value}}, ...]
     */
    public function claimPending(
        string $stream,
        string $group,
        ?string $consumer = null,
        int $minIdleMs = 60000
    ): array {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('QueueService::claimPending — Redis unavailable', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
            ]);
            return [];
        }

        $consumer = $consumer ?? $this->consumerName;

        try {
            $redis = $this->redisClient->getConnection();

            // First, get pending messages
            $pending = $redis->xPending($stream, $group, '-', '+', 100);

            if (empty($pending)) {
                return [];
            }

            // Collect message IDs that have been idle long enough
            $messageIds = [];
            foreach ($pending as $entry) {
                // xPending returns [messageId, consumer, idleTime, deliveryCount]
                $idleTime = $entry[2] ?? 0;
                if ($idleTime >= $minIdleMs) {
                    $messageIds[] = $entry[0];
                }
            }

            if (empty($messageIds)) {
                return [];
            }

            // XCLAIM stream group consumer min-idle-time id [id ...]
            $claimed = $redis->xClaim($stream, $group, $consumer, $minIdleMs, $messageIds);

            if (!is_array($claimed)) {
                return [];
            }

            $this->logger->info('Claimed pending messages', [
                'stream' => $stream,
                'group' => $group,
                'consumer' => $consumer,
                'count' => count($claimed),
            ], [
                'component' => 'QueueService',
            ]);

            return $claimed;
        } catch (\Throwable $e) {
            $this->logger->error('QueueService::claimPending failed', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
                'consumer' => $consumer,
            ], $e);
            return [];
        }
    }

    /**
     * Ensure a consumer group exists for the given stream.
     * Creates it with MKSTREAM if it doesn't exist.
     */
    private function ensureGroup(string $stream, string $group): void
    {
        $key = $stream . ':' . $group;
        if (isset($this->groupsEnsured[$key])) {
            return;
        }

        try {
            $redis = $this->redisClient->getConnection();
            // XGROUP CREATE stream group $ MKSTREAM
            // '$' means only new messages; '0' means all messages from beginning
            $redis->xGroup('CREATE', $stream, $group, '0', true); // true = MKSTREAM
            $this->groupsEnsured[$key] = true;
        } catch (\RedisException $e) {
            // "BUSYGROUP Consumer Group name already exists" is expected
            if (str_contains($e->getMessage(), 'BUSYGROUP')) {
                $this->groupsEnsured[$key] = true;
                return;
            }
            $this->logger->error('QueueService::ensureGroup failed', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
            ], $e);
        } catch (\Throwable $e) {
            $this->logger->error('QueueService::ensureGroup unexpected error', [], [
                'component' => 'QueueService',
                'stream' => $stream,
                'group' => $group,
            ], $e);
        }
    }
}
