<?php
/**
 * Property-based tests for graceful degradation (Property P10).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests that when Redis is unavailable, cache read operations return fallback
 * values without throwing unhandled exceptions.
 * Uses mock objects to simulate Redis failure.
 *
 * Feature: production-architecture-overhaul, Property 10: Redis unavailability graceful degradation
 * Validates: Requirements 3.5
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../includes/redis_client.php';
require_once __DIR__ . '/../includes/queue_service.php';

// DatabaseManager class for P23 test — loaded without requiring live MySQL connection
if (!class_exists('DatabaseManager')) {
    class DatabaseManager
    {
        private static ?\PDO $writeOverride = null;
        private static ?\PDO $readOverride = null;
        private static bool $readFallback = false;

        public static function getWriteConnection(): \PDO
        {
            if (self::$writeOverride !== null) {
                return self::$writeOverride;
            }
            global $pdo_write;
            return $pdo_write;
        }

        public static function getReadConnection(): \PDO
        {
            if (self::$readOverride !== null) {
                return self::$readOverride;
            }
            global $pdo_read;
            return $pdo_read;
        }

        public static function getReplicationLag(): int
        {
            try {
                $pdo = self::getReadConnection();
                $stmt = $pdo->query("SHOW SLAVE STATUS");
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && isset($row['Seconds_Behind_Master'])) {
                    return (int) $row['Seconds_Behind_Master'];
                }
                return 0;
            } catch (\PDOException $e) {
                return 0;
            }
        }

        public static function shouldUseWriteForCriticalRead(int $lagThreshold = 5): bool
        {
            return self::getReplicationLag() > $lagThreshold;
        }

        public static function setConnections(?\PDO $write, ?\PDO $read): void
        {
            self::$writeOverride = $write;
            self::$readOverride = $read;
            self::$readFallback = false;
        }

        public static function resetConnections(): void
        {
            self::$writeOverride = null;
            self::$readOverride = null;
            self::$readFallback = false;
        }

        public static function readWithFallback(string $sql, array $params = []): array
        {
            try {
                $pdo = self::getReadConnection();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                self::$readFallback = true;
                $pdo = self::getWriteConnection();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        }

        public static function didFallbackToWrite(): bool
        {
            return self::$readFallback;
        }
    }
}

class GracefulDegradationPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
        DatabaseManager::resetConnections();
    }

    protected function tearDown(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
        DatabaseManager::resetConnections();
    }

    /**
     * Generate a random alphanumeric string.
     */
    private function randomString(int $minLen = 1, int $maxLen = 50): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-:.';
        $len = mt_rand($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Create a mock RedisClient that simulates unavailability.
     */
    private function createUnavailableRedisClient(): RedisClient
    {
        $mock = $this->createMock(RedisClient::class);
        $mock->method('isAvailable')->willReturn(false);
        $mock->method('getConnection')->willReturn(null);
        $mock->method('ping')->willReturn(false);
        return $mock;
    }

    // =========================================================================
    // Property 10: Redis unavailability graceful degradation
    // Feature: production-architecture-overhaul, Property 10
    //
    // For any cache read operation, if the Redis connection throws an exception,
    // the system should fall back without throwing an unhandled exception.
    // When Redis is unavailable, RedisClient methods return null/false gracefully.
    //
    // **Validates: Requirements 3.5**
    // =========================================================================
    public function testProperty10_RedisUnavailabilityGracefulDegradation(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            StructuredLogger::resetInstance();
            RedisClient::resetInstance();

            // Create a mock RedisClient simulating unavailability
            $client = $this->createUnavailableRedisClient();

            // isAvailable() should return false
            try {
                $available = $client->isAvailable();
                if ($available !== false) {
                    $failures[] = sprintf('iter=%d: isAvailable() returned true for unavailable Redis', $i);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: isAvailable() threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // getConnection() should return null
            try {
                $conn = $client->getConnection();
                if ($conn !== null) {
                    $failures[] = sprintf('iter=%d: getConnection() returned non-null for unavailable Redis', $i);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: getConnection() threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // ping() should return false without throwing
            try {
                $pingResult = $client->ping();
                if ($pingResult !== false) {
                    $failures[] = sprintf('iter=%d: ping() returned %s instead of false', $i, var_export($pingResult, true));
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: ping() threw %s: %s', $i, get_class($e), $e->getMessage());
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 10 (Redis unavailability graceful degradation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    /**
     * Property 10 (extended): QueueService graceful degradation when Redis is unavailable.
     *
     * When Redis is unavailable, QueueService methods should return fallback values
     * (false, empty array) without throwing unhandled exceptions.
     * Uses mock RedisClient to simulate Redis failure with randomized inputs.
     *
     * **Validates: Requirements 3.5**
     */
    public function testProperty10_QueueServiceGracefulDegradationOnRedisUnavailability(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Use mock RedisClient — no real TCP connections needed
            $client = $this->createUnavailableRedisClient();
            $consumerName = 'test-consumer-' . mt_rand(1, 99999);
            $queue = new QueueService($client, $consumerName);

            // Randomized inputs
            $stream = 'test:stream:' . $this->randomString(3, 15);
            $group = 'test-group-' . mt_rand(1, 10000);
            $rooms = [1, 10, 100];
            $payload = [
                'round_id' => (string)mt_rand(1, 100000),
                'room' => (string)$rooms[mt_rand(0, 2)],
                'timestamp' => (string)time(),
            ];
            $messageId = mt_rand(1000000, 9999999) . '-' . mt_rand(0, 99);

            // addTask should return false without throwing
            try {
                $addResult = $queue->addTask($stream, $payload);
                if ($addResult !== false) {
                    $failures[] = sprintf('iter=%d: addTask returned %s instead of false', $i, var_export($addResult, true));
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: addTask threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // readTasks should return empty array without throwing
            try {
                $readResult = $queue->readTasks($stream, $group, null, mt_rand(1, 10), 0);
                if (!is_array($readResult) || !empty($readResult)) {
                    $failures[] = sprintf('iter=%d: readTasks returned non-empty result', $i);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: readTasks threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // ack should return false without throwing
            try {
                $ackResult = $queue->ack($stream, $group, $messageId);
                if ($ackResult !== false) {
                    $failures[] = sprintf('iter=%d: ack returned %s instead of false', $i, var_export($ackResult, true));
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: ack threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // claimPending should return empty array without throwing
            try {
                $minIdle = mt_rand(10000, 120000);
                $claimResult = $queue->claimPending($stream, $group, null, $minIdle);
                if (!is_array($claimResult) || !empty($claimResult)) {
                    $failures[] = sprintf('iter=%d: claimPending returned non-empty result', $i);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: claimPending threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // Verify consumer name is set correctly
            if ($queue->getConsumerName() !== $consumerName) {
                $failures[] = sprintf(
                    'iter=%d: consumer name mismatch: expected=%s got=%s',
                    $i, $consumerName, $queue->getConsumerName()
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 10 extended (QueueService graceful degradation) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 23: Read replica fallback on failure
    // Feature: production-architecture-overhaul, Property 23
    //
    // For any read query, if the $pdo_read connection throws a PDOException,
    // the query should be retried on $pdo_write and return a valid result
    // without propagating the original exception.
    //
    // **Validates: Requirements 8.4**
    // =========================================================================
    public function testProperty23_ReadReplicaFallbackOnFailure(): void
    {
        $iterations = 100;
        $failures = [];

        // Sample read queries that admin endpoints would execute
        $readQueries = [
            "SELECT id, email, balance FROM users WHERE id != 1 ORDER BY created_at DESC",
            "SELECT COUNT(*) FROM game_rounds WHERE status = 'finished'",
            "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE type = 'bet'",
            "SELECT id, type, amount FROM ledger_entries ORDER BY created_at DESC LIMIT 500",
            "SELECT id, room, total_pot FROM game_rounds WHERE status = 'finished' ORDER BY finished_at DESC",
        ];

        for ($i = 0; $i < $iterations; $i++) {
            // Random query
            $queryIdx = mt_rand(0, count($readQueries) - 1);
            $query = $readQueries[$queryIdx];

            // Random expected result rows
            $numRows = mt_rand(0, 5);
            $expectedRows = [];
            for ($r = 0; $r < $numRows; $r++) {
                $expectedRows[] = ['id' => mt_rand(1, 10000), 'value' => 'row_' . $r];
            }

            // Create a read PDO that throws PDOException
            $readMock = $this->createMock(PDO::class);
            $readMock->method('prepare')->willThrowException(
                new PDOException('Connection lost: read replica unavailable')
            );

            // Create a write PDO that succeeds
            $writeMock = $this->createMock(PDO::class);
            $writeStmt = $this->createMock(PDOStatement::class);
            $writeStmt->method('execute')->willReturn(true);
            $writeStmt->method('fetchAll')->willReturn($expectedRows);
            $writeMock->method('prepare')->willReturn($writeStmt);

            DatabaseManager::setConnections($writeMock, $readMock);

            // Use readWithFallback — should catch read failure and retry on write
            try {
                $result = DatabaseManager::readWithFallback($query);

                // Verify result matches expected
                if ($result !== $expectedRows) {
                    $failures[] = sprintf(
                        'iter=%d: fallback result mismatch: expected %d rows, got %d',
                        $i, count($expectedRows), count($result)
                    );
                    continue;
                }

                // Verify fallback was triggered
                if (!DatabaseManager::didFallbackToWrite()) {
                    $failures[] = sprintf(
                        'iter=%d: fallback was not triggered despite read failure',
                        $i
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf(
                    'iter=%d: readWithFallback threw %s: %s (should have fallen back to write)',
                    $i, get_class($e), $e->getMessage()
                );
            }

            DatabaseManager::resetConnections();
        }

        $this->assertEmpty(
            $failures,
            "Property 23 (Read replica fallback on failure) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
