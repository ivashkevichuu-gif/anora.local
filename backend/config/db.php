<?php
/**
 * Database configuration — PDO write/read split for master/replica.
 *
 * Environment variables:
 *   DB_WRITE_HOST — master host (default: localhost)
 *   DB_READ_HOST  — replica host (default: falls back to DB_WRITE_HOST)
 *   DB_USER       — database user
 *   DB_PASS       — database password
 *   DB_NAME       — database name
 *
 * Provides:
 *   $pdo_write — master connection (all writes + critical reads)
 *   $pdo_read  — replica connection (admin/analytics reads)
 *   $pdo       — backward compatibility alias for $pdo_write
 *   DatabaseManager — static class for testable connection management
 *
 * Requirements: 8.1, 8.4, 8.5, 8.6
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/structured_logger.php';

// Legacy constants (backward compatibility)
define('DB_HOST', getenv('DB_WRITE_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'ivash536_anora');
define('DB_PASS', getenv('DB_PASS') ?: 'QjMVmHxVh73cQwnaXUQz');
define('DB_NAME', getenv('DB_NAME') ?: 'ivash536_anora');

$db_write_host = DB_HOST;
$db_read_host  = getenv('DB_READ_HOST') ?: $db_write_host;

$pdo_options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// ── Master (write) connection ───────────────────────────────────────────────
$pdo_write = new PDO(
    'mysql:host=' . $db_write_host . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    $pdo_options
);

// ── Replica (read) connection ───────────────────────────────────────────────
try {
    $pdo_read = new PDO(
        'mysql:host=' . $db_read_host . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $pdo_options
    );
} catch (PDOException $e) {
    // Graceful degradation: fallback to master
    $pdo_read = $pdo_write;
    $logger = StructuredLogger::getInstance();
    $logger->warning('Read replica unavailable, falling back to master', [
        'error' => $e->getMessage(),
    ], ['component' => 'DatabaseManager']);
}

// ── Backward compatibility ──────────────────────────────────────────────────
$pdo = $pdo_write;


// ══════════════════════════════════════════════════════════════════════════════
// DatabaseManager — static class for testable connection management
// ══════════════════════════════════════════════════════════════════════════════

/**
 * DatabaseManager — provides static methods for PDO connection management.
 *
 * Supports write/read split, replication lag detection, and fallback logic.
 * Static methods allow easy mocking/testing without global state.
 */
class DatabaseManager
{
    /** @var PDO|null Override for write connection (testing) */
    private static ?PDO $writeOverride = null;

    /** @var PDO|null Override for read connection (testing) */
    private static ?PDO $readOverride = null;

    /** @var bool Whether read replica failed and we fell back to write */
    private static bool $readFallback = false;

    /**
     * Get the write (master) PDO connection.
     */
    public static function getWriteConnection(): PDO
    {
        if (self::$writeOverride !== null) {
            return self::$writeOverride;
        }
        global $pdo_write;
        return $pdo_write;
    }

    /**
     * Get the read (replica) PDO connection.
     * Falls back to write connection if replica is unavailable.
     */
    public static function getReadConnection(): PDO
    {
        if (self::$readOverride !== null) {
            return self::$readOverride;
        }
        global $pdo_read;
        return $pdo_read;
    }

    /**
     * Check replication lag on the read replica.
     *
     * @return int Seconds behind master (0 if unavailable or not a replica)
     */
    public static function getReplicationLag(): int
    {
        try {
            $pdo = self::getReadConnection();
            $stmt = $pdo->query("SHOW SLAVE STATUS");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['Seconds_Behind_Master'])) {
                return (int) $row['Seconds_Behind_Master'];
            }
            return 0;
        } catch (PDOException $e) {
            $logger = StructuredLogger::getInstance();
            $logger->warning('Failed to check replication lag', [
                'error' => $e->getMessage(),
            ], ['component' => 'DatabaseManager']);
            return 0;
        }
    }

    /**
     * Determine if critical reads should use the write connection due to lag.
     *
     * @param int $lagThreshold Max acceptable lag in seconds (default 5)
     * @return bool True if write connection should be used for critical reads
     */
    public static function shouldUseWriteForCriticalRead(int $lagThreshold = 5): bool
    {
        $lag = self::getReplicationLag();
        return $lag > $lagThreshold;
    }

    /**
     * Set override connections for testing.
     */
    public static function setConnections(?PDO $write, ?PDO $read): void
    {
        self::$writeOverride = $write;
        self::$readOverride = $read;
        self::$readFallback = false;
    }

    /**
     * Reset overrides (call after tests).
     */
    public static function resetConnections(): void
    {
        self::$writeOverride = null;
        self::$readOverride = null;
        self::$readFallback = false;
    }

    /**
     * Execute a read query with automatic fallback to write on failure.
     *
     * @param string $sql    SQL query
     * @param array  $params Bind parameters
     * @return array Query results
     */
    public static function readWithFallback(string $sql, array $params = []): array
    {
        // Try read replica first
        try {
            $pdo = self::getReadConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback to write connection
            $logger = StructuredLogger::getInstance();
            $logger->warning('Read replica query failed, falling back to master', [
                'error' => $e->getMessage(),
                'sql'   => substr($sql, 0, 100),
            ], ['component' => 'DatabaseManager']);

            self::$readFallback = true;

            $pdo = self::getWriteConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /**
     * Check if the last read operation fell back to write.
     */
    public static function didFallbackToWrite(): bool
    {
        return self::$readFallback;
    }
}

// ── Legacy function (backward compatibility) ────────────────────────────────

/**
 * Check replication lag on the read replica.
 *
 * @deprecated Use DatabaseManager::getReplicationLag() instead.
 * @param PDO $pdoRead The read replica PDO connection
 * @return int|null Seconds behind master, or null if unavailable
 */
function checkReplicationLag(PDO $pdoRead): ?int
{
    try {
        $stmt = $pdoRead->query("SHOW SLAVE STATUS");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['Seconds_Behind_Master'])) {
            return (int) $row['Seconds_Behind_Master'];
        }
        return null;
    } catch (PDOException $e) {
        error_log("[DB] Failed to check replication lag: " . $e->getMessage());
        return null;
    }
}
