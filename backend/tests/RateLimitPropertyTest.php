<?php
/**
 * Property-based tests for rate limit counter accuracy (Property P9).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests that for N increment operations on a rate limit key, the counter value
 * equals N, and after TTL expiry the key does not exist.
 * Uses mock Redis objects to simulate counter operations without a live Redis server.
 *
 * Feature: production-architecture-overhaul, Property 9: Rate limit counter accuracy
 * Validates: Requirements 3.4
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../includes/redis_client.php';
require_once __DIR__ . '/../includes/cache_service.php';

class RateLimitPropertyTest extends TestCase
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
     * Build a mock RedisClient + mock \Redis backed by an in-memory store.
     *
     * The store tracks values and TTLs. The "expireSimulated" flag can be
     * toggled to simulate TTL expiry.
     *
     * @return array{client: RedisClient, store: array, expiredKeys: array}
     */
    private function createMockRedisWithStore(): array
    {
        $store = [];
        $ttls = [];
        $expiredKeys = [];

        $mockRedis = $this->createMock(\Redis::class);

        // incr: increment counter
        $mockRedis->method('incr')->willReturnCallback(
            function (string $key) use (&$store): int {
                if (!isset($store[$key])) {
                    $store[$key] = 0;
                }
                $store[$key]++;
                return $store[$key];
            }
        );

        // expire: set TTL
        $mockRedis->method('expire')->willReturnCallback(
            function (string $key, int $ttl) use (&$ttls): bool {
                $ttls[$key] = $ttl;
                return true;
            }
        );

        // exists: check key
        $mockRedis->method('exists')->willReturnCallback(
            function (string $key) use (&$store, &$expiredKeys): int {
                if (in_array($key, $expiredKeys, true)) {
                    return 0;
                }
                return isset($store[$key]) ? 1 : 0;
            }
        );

        // get: retrieve value
        $mockRedis->method('get')->willReturnCallback(
            function (string $key) use (&$store, &$expiredKeys): string|false {
                if (in_array($key, $expiredKeys, true)) {
                    return false;
                }
                return isset($store[$key]) ? (string) $store[$key] : false;
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

        $mockClient = $this->createMock(RedisClient::class);
        $mockClient->method('isAvailable')->willReturn(true);
        $mockClient->method('getConnection')->willReturn($mockRedis);

        return [
            'client' => $mockClient,
            'store' => &$store,
            'ttls' => &$ttls,
            'expiredKeys' => &$expiredKeys,
        ];
    }

    // =========================================================================
    // Property 9: Rate limit counter accuracy
    // Feature: production-architecture-overhaul, Property 9
    //
    // For any sequence of N increment operations on a rate limit key
    // ratelimit:{type}:{user_id}, the counter value should equal N.
    // After the TTL expires, the counter should reset to 0 (key does not exist).
    //
    // **Validates: Requirements 3.4**
    // =========================================================================
    public function testProperty9_RateLimitCounterAccuracy(): void
    {
        $iterations = 100;
        $failures = [];

        $rateLimitTypes = [
            'bet'      => CacheService::getTtlRateBet(),
            'bet_sec'  => CacheService::getTtlRateBetSec(),
            'deposit'  => CacheService::getTtlRateDeposit(),
            'withdraw' => CacheService::getTtlRateWithdraw(),
        ];

        $typeKeys = array_keys($rateLimitTypes);

        for ($i = 0; $i < $iterations; $i++) {
            $mock = $this->createMockRedisWithStore();
            $cache = new CacheService($mock['client']);

            $userId = mt_rand(1, 100000);
            $type = $typeKeys[mt_rand(0, count($typeKeys) - 1)];
            $expectedTtl = $rateLimitTypes[$type];
            $n = mt_rand(1, 20);

            $key = "ratelimit:{$type}:{$userId}";

            // Perform N increments using the domain-specific helper
            $lastValue = 0;
            for ($j = 0; $j < $n; $j++) {
                $result = match ($type) {
                    'bet'      => $cache->incrementBetRate($userId),
                    'bet_sec'  => $cache->incrementBetSecRate($userId),
                    'deposit'  => $cache->incrementDepositRate($userId),
                    'withdraw' => $cache->incrementWithdrawRate($userId),
                };

                if ($result === false) {
                    $failures[] = sprintf(
                        'iter=%d type=%s userId=%d: increment #%d returned false',
                        $i, $type, $userId, $j + 1
                    );
                    break;
                }
                $lastValue = $result;
            }

            // Property: counter value should equal N
            if ($lastValue !== $n) {
                $failures[] = sprintf(
                    'iter=%d type=%s userId=%d n=%d: counter=%d (expected %d)',
                    $i, $type, $userId, $n, $lastValue, $n
                );
            }

            // Property: TTL was set on first increment
            if (!isset($mock['ttls'][$key])) {
                $failures[] = sprintf(
                    'iter=%d type=%s userId=%d: TTL not set for key %s',
                    $i, $type, $userId, $key
                );
            } elseif ($mock['ttls'][$key] !== $expectedTtl) {
                $failures[] = sprintf(
                    'iter=%d type=%s userId=%d: TTL=%d (expected %d)',
                    $i, $type, $userId, $mock['ttls'][$key], $expectedTtl
                );
            }

            // Property: key exists before TTL expiry
            $existsBefore = $cache->exists($key);
            if (!$existsBefore) {
                $failures[] = sprintf(
                    'iter=%d type=%s userId=%d: key does not exist before TTL expiry',
                    $i, $type, $userId
                );
            }

            // Simulate TTL expiry
            $mock['expiredKeys'][] = $key;

            // Property: after TTL, key does not exist
            $existsAfter = $cache->exists($key);
            if ($existsAfter) {
                $failures[] = sprintf(
                    'iter=%d type=%s userId=%d: key still exists after TTL expiry',
                    $i, $type, $userId
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 9 (Rate limit counter accuracy) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
