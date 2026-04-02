<?php
declare(strict_types=1);

require_once __DIR__ . '/structured_logger.php';

/**
 * RedisClient — singleton wrapper over phpredis with graceful degradation.
 *
 * Connects to Redis via environment variables (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD).
 * On Redis unavailability, logs a warning and returns null/false instead of throwing.
 * Integrates with StructuredLogger for connection error logging.
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 3.5
 */
class RedisClient
{
    private static ?self $instance = null;

    private ?\Redis $connection = null;
    private bool $available = false;
    private StructuredLogger $logger;

    private string $host;
    private int $port;
    private ?string $password;

    public function __construct(?string $host = null, ?int $port = null, ?string $password = null)
    {
        $this->host = $host ?? (getenv('REDIS_HOST') ?: '127.0.0.1');
        $this->port = $port ?? (int)(getenv('REDIS_PORT') ?: 6379);
        $pwd = $password ?? (getenv('REDIS_PASSWORD') ?: null);
        $this->password = ($pwd !== null && $pwd !== '' && $pwd !== false) ? $pwd : null;

        $this->logger = StructuredLogger::getInstance();

        $this->connect();
    }

    /**
     * Get or create the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset singleton (useful for testing).
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null && self::$instance->connection !== null) {
            try {
                self::$instance->connection->close();
            } catch (\Throwable $e) {
                // Ignore close errors
            }
        }
        self::$instance = null;
    }

    /**
     * Attempt to connect to Redis.
     */
    private function connect(): void
    {
        if (!extension_loaded('redis')) {
            $this->logger->warning('Redis extension (phpredis) not loaded, Redis unavailable', [], [
                'component' => 'RedisClient',
            ]);
            $this->available = false;
            return;
        }

        try {
            $redis = new \Redis();
            $connected = $redis->connect($this->host, $this->port, 2.0); // 2s timeout

            if (!$connected) {
                $this->logger->warning('Redis connection failed', [], [
                    'component' => 'RedisClient',
                    'host' => $this->host,
                    'port' => $this->port,
                ]);
                $this->available = false;
                return;
            }

            if ($this->password !== null) {
                $redis->auth($this->password);
            }

            $this->connection = $redis;
            $this->available = true;
        } catch (\RedisException $e) {
            $this->logger->warning('Redis connection error', [], [
                'component' => 'RedisClient',
                'host' => $this->host,
                'port' => $this->port,
                'error' => $e->getMessage(),
            ]);
            $this->connection = null;
            $this->available = false;
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error connecting to Redis', [], [
                'component' => 'RedisClient',
                'host' => $this->host,
                'port' => $this->port,
            ], $e);
            $this->connection = null;
            $this->available = false;
        }
    }

    /**
     * Get the underlying Redis connection, or null if unavailable.
     */
    public function getConnection(): ?\Redis
    {
        return $this->connection;
    }

    /**
     * Check if Redis is currently available.
     */
    public function isAvailable(): bool
    {
        return $this->available && $this->connection !== null;
    }

    /**
     * Ping Redis to verify the connection is alive.
     *
     * @return bool True if Redis responds to PING, false otherwise.
     */
    public function ping(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $result = $this->connection->ping();
            // phpredis returns true or "+PONG" depending on version
            return $result === true || $result === '+PONG';
        } catch (\Throwable $e) {
            $this->logger->warning('Redis ping failed', [], [
                'component' => 'RedisClient',
                'error' => $e->getMessage(),
            ]);
            $this->available = false;
            return false;
        }
    }
}
