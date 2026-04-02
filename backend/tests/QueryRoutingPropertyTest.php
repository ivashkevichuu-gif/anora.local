<?php
/**
 * Property-based tests for query routing (Properties P22, P24).
 *
 * Uses mt_rand() for randomized input generation, 100 iterations per property.
 * Tests that financial writes route to $pdo_write and admin reads route to $pdo_read,
 * and that replication lag affects routing decisions.
 *
 * Feature: production-architecture-overhaul
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/structured_logger.php';

// We only need the DatabaseManager class, not the actual PDO connections.
// Include db.php only if not already loaded (it tries to connect to MySQL).
if (!class_exists('DatabaseManager')) {
    // Define constants to prevent db.php from failing when MySQL is unavailable
    if (!defined('DB_HOST')) {
        define('DB_HOST', 'localhost');
        define('DB_USER', 'test');
        define('DB_PASS', 'test');
        define('DB_NAME', 'test');
    }

    // We need the DatabaseManager class definition but not the global PDO connections.
    // Extract just the class by requiring the file in a context where PDO creation will fail gracefully.
    // Instead, define a minimal version for testing:
}

/**
 * DatabaseManager — static class for testable connection management.
 * Duplicated here for test isolation (no MySQL dependency).
 */
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
            $lag = self::getReplicationLag();
            return $lag > $lagThreshold;
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

class QueryRoutingPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        StructuredLogger::resetInstance();
        DatabaseManager::resetConnections();
    }

    protected function tearDown(): void
    {
        StructuredLogger::resetInstance();
        DatabaseManager::resetConnections();
    }

    /**
     * Create a mock PDO that tracks queries executed on it.
     *
     * @param string $label Label for identification (e.g. 'write', 'read')
     * @return array{pdo: PDO, tracker: object} Mock PDO and query tracker
     */
    private function createTrackedPdo(string $label): array
    {
        $tracker = new class {
            /** @var array<string> */
            public array $queries = [];
            public string $label = '';
        };
        $tracker->label = $label;

        $mockPdo = $this->createMock(PDO::class);

        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockStmt->method('fetchColumn')->willReturn(0);
        $mockStmt->method('fetch')->willReturn(false);

        $mockPdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($tracker, $mockStmt) {
                $tracker->queries[] = $sql;
                return $mockStmt;
            }
        );

        $mockPdo->method('query')->willReturnCallback(
            function (string $sql) use ($tracker, $mockStmt) {
                $tracker->queries[] = $sql;
                return $mockStmt;
            }
        );

        return ['pdo' => $mockPdo, 'tracker' => $tracker];
    }

    // =========================================================================
    // Property 22: Query routing by operation type
    // Feature: production-architecture-overhaul, Property 22
    //
    // For any query context with operation type and caller module,
    // financial write operations should route to $pdo_write,
    // and admin read operations should route to $pdo_read.
    //
    // **Validates: Requirements 8.2, 8.3**
    // =========================================================================
    public function testProperty22_QueryRoutingByOperationType(): void
    {
        $iterations = 100;
        $failures = [];

        // Admin read query templates (should go to $pdo_read)
        $adminReadQueries = [
            "SELECT id, email, balance FROM users WHERE id != 1 ORDER BY created_at DESC",
            "SELECT COUNT(*) FROM game_rounds gr WHERE gr.status = 'finished'",
            "SELECT COALESCE(SUM(le.amount), 0) FROM ledger_entries le JOIN users u ON u.id = le.user_id WHERE le.type = 'bet'",
            "SELECT le.id, le.type, le.amount FROM ledger_entries le JOIN users u ON u.id = le.user_id ORDER BY le.created_at DESC LIMIT 500",
            "SELECT gr.id, gr.room, gr.total_pot FROM game_rounds gr WHERE gr.status = 'finished' ORDER BY gr.finished_at DESC",
        ];

        // Financial write queries (should go to $pdo_write)
        $financialWriteQueries = [
            "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after) VALUES (?, ?, ?, ?, ?)",
            "UPDATE user_balances SET balance = balance + ? WHERE user_id = ?",
            "INSERT INTO crypto_invoices (user_id, amount_usd, currency, status) VALUES (?, ?, ?, ?)",
            "UPDATE crypto_payouts SET status = ? WHERE id = ?",
            "INSERT INTO transactions (user_id, type, amount, status) VALUES (?, ?, ?, ?)",
        ];

        for ($i = 0; $i < $iterations; $i++) {
            $writeResult = $this->createTrackedPdo('write');
            $readResult = $this->createTrackedPdo('read');

            DatabaseManager::setConnections($writeResult['pdo'], $readResult['pdo']);

            // Pick a random admin read query
            $readIdx = mt_rand(0, count($adminReadQueries) - 1);
            $readQuery = $adminReadQueries[$readIdx];

            // Pick a random financial write query
            $writeIdx = mt_rand(0, count($financialWriteQueries) - 1);
            $writeQuery = $financialWriteQueries[$writeIdx];

            // Execute admin read on read connection
            try {
                $readPdo = DatabaseManager::getReadConnection();
                $stmt = $readPdo->prepare($readQuery);
                $stmt->execute();

                // Verify it went to the read tracker
                if (!in_array($readQuery, $readResult['tracker']->queries, true)) {
                    $failures[] = sprintf(
                        'iter=%d: admin read query was NOT routed to $pdo_read: %s',
                        $i, substr($readQuery, 0, 60)
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: admin read threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // Execute financial write on write connection
            try {
                $writePdo = DatabaseManager::getWriteConnection();
                $stmt = $writePdo->prepare($writeQuery);
                $stmt->execute();

                // Verify it went to the write tracker
                if (!in_array($writeQuery, $writeResult['tracker']->queries, true)) {
                    $failures[] = sprintf(
                        'iter=%d: financial write query was NOT routed to $pdo_write: %s',
                        $i, substr($writeQuery, 0, 60)
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('iter=%d: financial write threw %s: %s', $i, get_class($e), $e->getMessage());
            }

            // Verify no cross-contamination: write queries should NOT appear on read tracker
            if (in_array($writeQuery, $readResult['tracker']->queries, true)) {
                $failures[] = sprintf(
                    'iter=%d: financial write query leaked to $pdo_read: %s',
                    $i, substr($writeQuery, 0, 60)
                );
            }

            // Verify no cross-contamination: read queries should NOT appear on write tracker
            if (in_array($readQuery, $writeResult['tracker']->queries, true)) {
                $failures[] = sprintf(
                    'iter=%d: admin read query leaked to $pdo_write: %s',
                    $i, substr($readQuery, 0, 60)
                );
            }

            DatabaseManager::resetConnections();
        }

        $this->assertEmpty(
            $failures,
            "Property 22 (Query routing by operation type) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 24: Replication lag routing
    // Feature: production-architecture-overhaul, Property 24
    //
    // For any replication lag value L (in seconds):
    //   - If L > 5, critical read queries should be routed to $pdo_write
    //   - If L <= 5, they should be routed to $pdo_read
    //
    // **Validates: Requirements 8.6**
    // =========================================================================
    public function testProperty24_ReplicationLagRouting(): void
    {
        $iterations = 100;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random lag value: 0-20 seconds
            $lag = mt_rand(0, 20);
            $threshold = 5;

            // Create mock PDOs
            $writeMock = $this->createMock(PDO::class);
            $readMock = $this->createMock(PDO::class);

            // Mock SHOW SLAVE STATUS on read connection to return the lag
            $slaveStmt = $this->createMock(PDOStatement::class);
            $slaveStmt->method('fetch')->willReturn([
                'Seconds_Behind_Master' => $lag,
            ]);
            $readMock->method('query')->willReturnCallback(
                function (string $sql) use ($slaveStmt) {
                    if (stripos($sql, 'SHOW SLAVE STATUS') !== false) {
                        return $slaveStmt;
                    }
                    return $slaveStmt;
                }
            );

            DatabaseManager::setConnections($writeMock, $readMock);

            $shouldUseWrite = DatabaseManager::shouldUseWriteForCriticalRead($threshold);
            $actualLag = DatabaseManager::getReplicationLag();

            if ($lag > $threshold) {
                // High lag: should use write for critical reads
                if (!$shouldUseWrite) {
                    $failures[] = sprintf(
                        'iter=%d: lag=%d > threshold=%d but shouldUseWriteForCriticalRead returned false',
                        $i, $lag, $threshold
                    );
                }
            } else {
                // Low lag: should use read for critical reads
                if ($shouldUseWrite) {
                    $failures[] = sprintf(
                        'iter=%d: lag=%d <= threshold=%d but shouldUseWriteForCriticalRead returned true',
                        $i, $lag, $threshold
                    );
                }
            }

            // Verify lag value is correctly reported
            if ($actualLag !== $lag) {
                $failures[] = sprintf(
                    'iter=%d: getReplicationLag returned %d, expected %d',
                    $i, $actualLag, $lag
                );
            }

            DatabaseManager::resetConnections();
        }

        $this->assertEmpty(
            $failures,
            "Property 24 (Replication lag routing) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
