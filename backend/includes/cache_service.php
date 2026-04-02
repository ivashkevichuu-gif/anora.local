<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';
require_once __DIR__ . '/structured_logger.php';

/**
 * CacheService — Redis-based caching and rate limiting with graceful degradation.
 *
 * Provides generic cache operations (get/set/delete/exists/increment) and
 * domain-specific helpers for game state cache, admin dashboard cache,
 * rate limiting, and user blacklist management.
 *
 * When Redis is unavailable, all operations degrade gracefully:
 * reads return null/false, writes are silently skipped with a warning log.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5
 */
class CacheService
{
    /** TTL constants (seconds) */
    private const TTL_GAME_STATE       = 5;
    private const TTL_ADMIN_DASHBOARD  = 30;
    private const TTL_RATE_BET         = 60;
    private const TTL_RATE_BET_SEC     = 1;
    private const TTL_RATE_DEPOSIT     = 3600;
    private const TTL_RATE_WITHDRAW    = 3600;

    private RedisClient $redisClient;
    private StructuredLogger $logger;

    public function __construct(?RedisClient $redisClient = null)
    {
        $this->redisClient = $redisClient ?? RedisClient::getInstance();
        $this->logger = StructuredLogger::getInstance();
    }

    // ── Generic cache operations ────────────────────────────────────────

    /**
     * Get a value from cache.
     *
     * @return string|null The cached value, or null if not found / Redis unavailable.
     */
    public function get(string $key): ?string
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('CacheService::get — Redis unavailable', [], [
                'component' => 'CacheService',
                'key' => $key,
            ]);
            return null;
        }

        try {
            $redis = $this->redisClient->getConnection();
            $value = $redis->get($key);
            return $value === false ? null : $value;
        } catch (\Throwable $e) {
            $this->logger->error('CacheService::get failed', [], [
                'component' => 'CacheService',
                'key' => $key,
            ], $e);
            return null;
        }
    }

    /**
     * Set a value in cache with optional TTL.
     *
     * @param string   $key
     * @param string   $value
     * @param int|null $ttl  TTL in seconds, null for no expiry.
     * @return bool
     */
    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('CacheService::set — Redis unavailable', [], [
                'component' => 'CacheService',
                'key' => $key,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            if ($ttl !== null && $ttl > 0) {
                return (bool) $redis->setex($key, $ttl, $value);
            }
            return (bool) $redis->set($key, $value);
        } catch (\Throwable $e) {
            $this->logger->error('CacheService::set failed', [], [
                'component' => 'CacheService',
                'key' => $key,
            ], $e);
            return false;
        }
    }

    /**
     * Delete a key from cache.
     *
     * @return bool True if the key was deleted, false otherwise.
     */
    public function delete(string $key): bool
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('CacheService::delete — Redis unavailable', [], [
                'component' => 'CacheService',
                'key' => $key,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            return $redis->del($key) >= 1;
        } catch (\Throwable $e) {
            $this->logger->error('CacheService::delete failed', [], [
                'component' => 'CacheService',
                'key' => $key,
            ], $e);
            return false;
        }
    }

    /**
     * Check if a key exists in cache.
     *
     * @return bool True if the key exists, false otherwise (including Redis unavailable).
     */
    public function exists(string $key): bool
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('CacheService::exists — Redis unavailable', [], [
                'component' => 'CacheService',
                'key' => $key,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            return (bool) $redis->exists($key);
        } catch (\Throwable $e) {
            $this->logger->error('CacheService::exists failed', [], [
                'component' => 'CacheService',
                'key' => $key,
            ], $e);
            return false;
        }
    }

    /**
     * Increment a counter key. Sets TTL on first increment (when value becomes 1).
     *
     * @param string $key
     * @param int    $ttl TTL in seconds applied on first increment.
     * @return int|false The new counter value, or false on failure.
     */
    public function increment(string $key, int $ttl = 0): int|false
    {
        if (!$this->redisClient->isAvailable()) {
            $this->logger->warning('CacheService::increment — Redis unavailable', [], [
                'component' => 'CacheService',
                'key' => $key,
            ]);
            return false;
        }

        try {
            $redis = $this->redisClient->getConnection();
            $value = $redis->incr($key);

            // Set TTL on first increment (counter just became 1)
            if ($value === 1 && $ttl > 0) {
                $redis->expire($key, $ttl);
            }

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('CacheService::increment failed', [], [
                'component' => 'CacheService',
                'key' => $key,
            ], $e);
            return false;
        }
    }

    // ── Domain-specific helpers ─────────────────────────────────────────

    /**
     * Get cached game state for a room.
     *
     * @param int $room Room identifier (1, 10, 100).
     * @return array|null Decoded game state, or null if not cached.
     */
    public function getGameState(int $room): ?array
    {
        $json = $this->get("game:state:{$room}");
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Cache game state for a room.
     *
     * @param int   $room  Room identifier.
     * @param array $state Game state data.
     * @return bool
     */
    public function setGameState(int $room, array $state): bool
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return $this->set("game:state:{$room}", $json, self::TTL_GAME_STATE);
    }

    /**
     * Invalidate cached game state for a room.
     *
     * @param int $room Room identifier.
     * @return bool
     */
    public function invalidateGameState(int $room): bool
    {
        return $this->delete("game:state:{$room}");
    }

    /**
     * Get cached admin dashboard data.
     *
     * @return array|null Decoded dashboard data, or null if not cached.
     */
    public function getAdminDashboard(): ?array
    {
        $json = $this->get('admin:dashboard');
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Cache admin dashboard data.
     *
     * @param array $data Dashboard data.
     * @return bool
     */
    public function setAdminDashboard(array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return $this->set('admin:dashboard', $json, self::TTL_ADMIN_DASHBOARD);
    }

    /**
     * Invalidate cached admin dashboard data.
     *
     * @return bool
     */
    public function invalidateAdminDashboard(): bool
    {
        return $this->delete('admin:dashboard');
    }

    // ── Rate limiting ───────────────────────────────────────────────────

    /**
     * Increment the per-minute bet rate limit counter.
     *
     * @param int $userId
     * @return int|false Current counter value, or false on failure.
     */
    public function incrementBetRate(int $userId): int|false
    {
        return $this->increment("ratelimit:bet:{$userId}", self::TTL_RATE_BET);
    }

    /**
     * Increment the per-second bet rate limit counter.
     *
     * @param int $userId
     * @return int|false Current counter value, or false on failure.
     */
    public function incrementBetSecRate(int $userId): int|false
    {
        return $this->increment("ratelimit:bet_sec:{$userId}", self::TTL_RATE_BET_SEC);
    }

    /**
     * Increment the per-hour deposit rate limit counter.
     *
     * @param int $userId
     * @return int|false Current counter value, or false on failure.
     */
    public function incrementDepositRate(int $userId): int|false
    {
        return $this->increment("ratelimit:deposit:{$userId}", self::TTL_RATE_DEPOSIT);
    }

    /**
     * Increment the per-hour withdraw rate limit counter.
     *
     * @param int $userId
     * @return int|false Current counter value, or false on failure.
     */
    public function incrementWithdrawRate(int $userId): int|false
    {
        return $this->increment("ratelimit:withdraw:{$userId}", self::TTL_RATE_WITHDRAW);
    }

    // ── Blacklist ───────────────────────────────────────────────────────

    /**
     * Check if a user is blacklisted.
     *
     * @param int $userId
     * @return bool True if blacklisted, false otherwise (including Redis unavailable).
     */
    public function isBlacklisted(int $userId): bool
    {
        return $this->exists("blacklist:user:{$userId}");
    }

    /**
     * Add a user to the blacklist (no TTL — permanent until manual removal).
     *
     * @param int $userId
     * @return bool
     */
    public function blacklistUser(int $userId): bool
    {
        return $this->set("blacklist:user:{$userId}", '1');
    }

    /**
     * Remove a user from the blacklist.
     *
     * @param int $userId
     * @return bool
     */
    public function unblacklistUser(int $userId): bool
    {
        return $this->delete("blacklist:user:{$userId}");
    }

    // ── TTL accessors (for testing) ─────────────────────────────────────

    public static function getTtlGameState(): int
    {
        return self::TTL_GAME_STATE;
    }

    public static function getTtlAdminDashboard(): int
    {
        return self::TTL_ADMIN_DASHBOARD;
    }

    public static function getTtlRateBet(): int
    {
        return self::TTL_RATE_BET;
    }

    public static function getTtlRateBetSec(): int
    {
        return self::TTL_RATE_BET_SEC;
    }

    public static function getTtlRateDeposit(): int
    {
        return self::TTL_RATE_DEPOSIT;
    }

    public static function getTtlRateWithdraw(): int
    {
        return self::TTL_RATE_WITHDRAW;
    }
}
