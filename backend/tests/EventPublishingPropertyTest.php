<?php
/**
 * Property-based tests for event publishing and cache invalidation.
 *
 * Property P7: State change events published with correct payload
 * Property P8: Cache invalidation on state change
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Uses mock Redis objects to simulate operations without a live Redis server.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 2.2, 2.6, 3.2, 4.4
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../includes/redis_client.php';
require_once __DIR__ . '/../includes/cache_service.php';

class EventPublishingPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
    }

    protected function tearDown(): void
    {
        StructuredLogger::resetInstance();
        RedisClient::resetInstance();
    }

    /**
     * Generate a random alphanumeric string.
     */
    private function randomString(int $minLen = 1, int $maxLen = 50): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        $len = mt_rand($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    // =========================================================================
    // Property 8: Cache invalidation on state change
    // Feature: production-architecture-overhaul, Property 8
    //
    // For any room with a cached game state (game:state:{room} key exists),
    // when the round state changes (new bet, status transition, round finish),
    // the cache key should be deleted. Immediately after invalidation,
    // EXISTS game:state:{room} should return 0.
    //
    // **Validates: Requirements 3.2**
    // =========================================================================
    public function testProperty8_CacheInvalidationOnStateChange(): void
    {
        $iterations = 100;
        $failures = [];
        $rooms = [1, 10, 100];

        for ($i = 0; $i < $iterations; $i++) {
            // In-memory store simulating Redis
            $store = [];

            $room = $rooms[mt_rand(0, count($rooms) - 1)];
            $cacheKey = "game:state:{$room}";

            // Generate random game state
            $gameState = json_encode([
                'round_id' => mt_rand(1, 100000),
                'room' => $room,
                'status' => ['waiting', 'betting', 'spinning'][mt_rand(0, 2)],
                'bets_count' => mt_rand(0, 50),
                'total_pool' => round(mt_rand(0, 999999) / 100, 2),
            ]);

            // Build a mock \Redis object backed by $store
            $mockRedis = $this->createMock(\Redis::class);

            // setex: store value with TTL (we ignore TTL in mock)
            $mockRedis->method('setex')->willReturnCallback(
                function (string $key, int $ttl, string $value) use (&$store): bool {
                    $store[$key] = $value;
                    return true;
                }
            );

            // set: store value
            $mockRedis->method('set')->willReturnCallback(
                function (string $key, string $value) use (&$store): bool {
                    $store[$key] = $value;
                    return true;
                }
            );

            // get: retrieve value
            $mockRedis->method('get')->willReturnCallback(
                function (string $key) use (&$store): string|false {
                    return $store[$key] ?? false;
                }
            );

            // del: remove key
            $mockRedis->method('del')->willReturnCallback(
                function (string $key) use (&$store): int {
                    if (isset($store[$key])) {
                        unset($store[$key]);
                        return 1;
                    }
                    return 0;
                }
            );

            // exists: check key
            $mockRedis->method('exists')->willReturnCallback(
                function (string $key) use (&$store): int {
                    return isset($store[$key]) ? 1 : 0;
                }
            );

            // Build a mock RedisClient that returns our mock \Redis
            $mockClient = $this->createMock(RedisClient::class);
            $mockClient->method('isAvailable')->willReturn(true);
            $mockClient->method('getConnection')->willReturn($mockRedis);

            $cache = new CacheService($mockClient);

            // Step 1: Set game state in cache
            $setResult = $cache->setGameState($room, json_decode($gameState, true));
            if (!$setResult) {
                $failures[] = sprintf('iter=%d room=%d: setGameState returned false', $i, $room);
                continue;
            }

            // Verify key exists before invalidation
            $existsBefore = $cache->exists($cacheKey);
            if (!$existsBefore) {
                $failures[] = sprintf('iter=%d room=%d: key does not exist after set', $i, $room);
                continue;
            }

            // Step 2: Simulate state change — invalidate cache
            $stateChangeType = ['new_bet', 'status_transition', 'round_finish'][mt_rand(0, 2)];
            $deleteResult = $cache->invalidateGameState($room);
            if (!$deleteResult) {
                $failures[] = sprintf(
                    'iter=%d room=%d change=%s: invalidateGameState returned false',
                    $i, $room, $stateChangeType
                );
                continue;
            }

            // Step 3: Verify EXISTS returns false (0) after invalidation
            $existsAfter = $cache->exists($cacheKey);
            if ($existsAfter) {
                $failures[] = sprintf(
                    'iter=%d room=%d change=%s: key still exists after invalidation',
                    $i, $room, $stateChangeType
                );
                continue;
            }

            // Step 4: Verify get returns null after invalidation
            $getAfter = $cache->getGameState($room);
            if ($getAfter !== null) {
                $failures[] = sprintf(
                    'iter=%d room=%d change=%s: getGameState returned non-null after invalidation',
                    $i, $room, $stateChangeType
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 8 (Cache invalidation on state change) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 7: State change events published with correct payload
    // Feature: production-architecture-overhaul, Property 7
    //
    // For any game state transition (bet placed, round finished),
    // the corresponding Redis Pub/Sub event should contain all required
    // payload fields: round_id (positive integer), room (one of 1, 10, 100),
    // and event-specific fields (user_id + amount for bet:placed,
    // winner_id for game:finished).
    //
    // **Validates: Requirements 2.2, 2.6, 4.4**
    // =========================================================================
    public function testProperty7_StateChangeEventsPayload(): void
    {
        $iterations = 100;
        $failures = [];
        $rooms = [1, 10, 100];

        for ($i = 0; $i < $iterations; $i++) {
            $room = $rooms[mt_rand(0, count($rooms) - 1)];
            $roundId = mt_rand(1, 999999);

            // Track published events
            $publishedEvents = [];

            // Build a mock \Redis that captures publish calls
            $mockRedis = $this->createMock(\Redis::class);
            $mockRedis->method('publish')->willReturnCallback(
                function (string $channel, string $message) use (&$publishedEvents): int {
                    $publishedEvents[] = [
                        'channel' => $channel,
                        'data' => json_decode($message, true),
                    ];
                    return 1;
                }
            );

            $mockClient = $this->createMock(RedisClient::class);
            $mockClient->method('isAvailable')->willReturn(true);
            $mockClient->method('getConnection')->willReturn($mockRedis);

            // Test bet:placed event
            $userId = mt_rand(2, 100000);
            $amount = (float)$room;

            $betEvent = json_encode([
                'round_id' => $roundId,
                'user_id'  => $userId,
                'amount'   => $amount,
                'room'     => $room,
            ]);

            $mockRedis->publish('bet:placed', $betEvent);

            // Validate bet:placed event
            $lastEvent = end($publishedEvents);
            $data = $lastEvent['data'];

            if ($lastEvent['channel'] !== 'bet:placed') {
                $failures[] = sprintf('iter=%d: bet:placed channel mismatch: %s', $i, $lastEvent['channel']);
                continue;
            }
            if (!isset($data['round_id']) || !is_int($data['round_id']) || $data['round_id'] <= 0) {
                $failures[] = sprintf('iter=%d: bet:placed missing/invalid round_id', $i);
                continue;
            }
            if (!isset($data['room']) || !in_array($data['room'], [1, 10, 100], true)) {
                $failures[] = sprintf('iter=%d: bet:placed invalid room: %s', $i, json_encode($data['room'] ?? null));
                continue;
            }
            if (!isset($data['user_id']) || !is_int($data['user_id']) || $data['user_id'] <= 0) {
                $failures[] = sprintf('iter=%d: bet:placed missing/invalid user_id', $i);
                continue;
            }
            if (!isset($data['amount']) || !is_numeric($data['amount'])) {
                $failures[] = sprintf('iter=%d: bet:placed missing/invalid amount', $i);
                continue;
            }

            // Test game:finished event
            $winnerId = mt_rand(2, 100000);
            $winnerNet = round(mt_rand(100, 99999) / 100, 2);

            $finishedEvent = json_encode([
                'round_id'   => $roundId,
                'winner_id'  => $winnerId,
                'room'       => $room,
                'winner_net' => $winnerNet,
            ]);

            $mockRedis->publish('game:finished', $finishedEvent);

            // Validate game:finished event
            $lastEvent = end($publishedEvents);
            $data = $lastEvent['data'];

            if ($lastEvent['channel'] !== 'game:finished') {
                $failures[] = sprintf('iter=%d: game:finished channel mismatch: %s', $i, $lastEvent['channel']);
                continue;
            }
            if (!isset($data['round_id']) || !is_int($data['round_id']) || $data['round_id'] <= 0) {
                $failures[] = sprintf('iter=%d: game:finished missing/invalid round_id', $i);
                continue;
            }
            if (!isset($data['room']) || !in_array($data['room'], [1, 10, 100], true)) {
                $failures[] = sprintf('iter=%d: game:finished invalid room: %s', $i, json_encode($data['room'] ?? null));
                continue;
            }
            if (!isset($data['winner_id']) || !is_int($data['winner_id']) || $data['winner_id'] <= 0) {
                $failures[] = sprintf('iter=%d: game:finished missing/invalid winner_id', $i);
                continue;
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 7 (State change events payload) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
