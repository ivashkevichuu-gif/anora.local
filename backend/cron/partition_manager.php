<?php
/**
 * Partition Manager — Automated DB partitioning for ledger_entries and game_bets.
 *
 * Partitions by RANGE COLUMNS(created_at) with 1-month intervals.
 * - Auto-creates partitions 3 months ahead
 * - Identifies partitions older than 12 months as archival candidates (log only)
 * - Partition names: p{YYYY}_{MM}
 *
 * Run via cron (weekly recommended):
 *   php backend/cron/partition_manager.php
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/structured_logger.php';

/**
 * PartitionManager — static methods for partition name/boundary generation and lifecycle.
 *
 * Pure functions are static for testability without DB.
 */
class PartitionManager
{
    /**
     * Generate partition name for a given date.
     *
     * @return string e.g. "p2024_03"
     */
    public static function generatePartitionName(\DateTimeInterface $date): string
    {
        return sprintf('p%04d_%02d', (int) $date->format('Y'), (int) $date->format('m'));
    }

    /**
     * Generate partition boundary (first day of the NEXT month).
     *
     * @return string e.g. "2024-04-01" for a date in March 2024
     */
    public static function generatePartitionBoundary(\DateTimeInterface $date): string
    {
        $firstOfMonth = new \DateTimeImmutable($date->format('Y-m-01'));
        return $firstOfMonth->modify('+1 month')->format('Y-m-d');
    }

    /**
     * Get the list of partitions to create (current month + monthsAhead months).
     *
     * @param \DateTimeInterface $now         Current date
     * @param int                $monthsAhead Months ahead to create (default 3)
     * @return array<int, array{name: string, boundary: string, year: int, month: int}>
     */
    public static function getPartitionsToCreate(\DateTimeInterface $now, int $monthsAhead = 3): array
    {
        $partitions = [];
        $dt = new \DateTimeImmutable($now->format('Y-m-01'));

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $current = $dt->modify("+{$i} months");

            $partitions[] = [
                'name'     => self::generatePartitionName($current),
                'boundary' => self::generatePartitionBoundary($current),
                'year'     => (int) $current->format('Y'),
                'month'    => (int) $current->format('n'),
            ];
        }

        return $partitions;
    }

    /**
     * Get the list of partitions that are candidates for archival (older than retentionMonths).
     *
     * @param \DateTimeInterface $now             Current date
     * @param int                $retentionMonths Months to retain (default 12)
     * @return array<int, array{name: string, year: int, month: int}>
     */
    public static function getPartitionsToArchive(\DateTimeInterface $now, int $retentionMonths = 12): array
    {
        $cutoff = (new \DateTimeImmutable($now->format('Y-m-01')))->modify("-{$retentionMonths} months");
        $cutoffYear = (int) $cutoff->format('Y');
        $cutoffMonth = (int) $cutoff->format('n');

        $candidates = [];

        // Generate candidate names for 24 months before the cutoff (reasonable lookback)
        $start = $cutoff->modify('-24 months');
        $dt = new \DateTimeImmutable($start->format('Y-m-01'));

        while ($dt < $cutoff) {
            $year = (int) $dt->format('Y');
            $month = (int) $dt->format('n');

            // Only include if strictly before the cutoff month
            if ($year < $cutoffYear || ($year === $cutoffYear && $month < $cutoffMonth)) {
                $candidates[] = [
                    'name'  => self::generatePartitionName($dt),
                    'year'  => $year,
                    'month' => $month,
                ];
            }

            $dt = $dt->modify('+1 month');
        }

        return $candidates;
    }

    /**
     * Ensure partitions exist on a table. Creates missing ones via REORGANIZE PARTITION.
     *
     * @param \PDO   $pdo        Database connection
     * @param string $table      Table name
     * @param array  $partitions Partitions from getPartitionsToCreate()
     */
    public static function ensurePartitions(\PDO $pdo, string $table, array $partitions): void
    {
        $logger = StructuredLogger::getInstance();
        $schema = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'anora');
        $existing = self::getExistingPartitions($pdo, $table, $schema);

        foreach ($partitions as $part) {
            if (in_array($part['name'], $existing, true)) {
                $logger->debug("Partition already exists, skipping", [
                    'table'     => $table,
                    'partition' => $part['name'],
                ]);
                continue;
            }

            try {
                $sql = "ALTER TABLE `{$table}` REORGANIZE PARTITION p_future INTO (
                    PARTITION `{$part['name']}` VALUES LESS THAN ('{$part['boundary']}'),
                    PARTITION p_future VALUES LESS THAN MAXVALUE
                )";
                $pdo->exec($sql);
                $logger->info("Partition created", [
                    'table'     => $table,
                    'partition' => $part['name'],
                    'boundary'  => $part['boundary'],
                ]);
            } catch (\PDOException $e) {
                // Skip if partition already exists (duplicate partition name)
                if (strpos($e->getMessage(), 'Duplicate partition') !== false ||
                    strpos($e->getMessage(), 'already exists') !== false) {
                    $logger->info("Partition already exists, skipping", [
                        'table'     => $table,
                        'partition' => $part['name'],
                    ]);
                } else {
                    $logger->error("Failed to create partition", [
                        'table'     => $table,
                        'partition' => $part['name'],
                        'boundary'  => $part['boundary'],
                    ], [], $e);
                }
            }
        }
    }

    /**
     * Get existing partition names for a table.
     *
     * @return array<string> Partition names
     */
    private static function getExistingPartitions(\PDO $pdo, string $table, string $schema): array
    {
        $stmt = $pdo->prepare(
            "SELECT PARTITION_NAME FROM INFORMATION_SCHEMA.PARTITIONS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION"
        );
        $stmt->execute([$schema, $table]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Legacy function wrappers — backward compatibility with existing tests/code
// ══════════════════════════════════════════════════════════════════════════════


/**
 * Generate partition name for a given year and month.
 * @deprecated Use PartitionManager::generatePartitionName() instead.
 */
function generatePartitionName(int $year, int $month): string
{
    $dt = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    return PartitionManager::generatePartitionName($dt);
}

/**
 * Calculate the partition boundary for a given year/month.
 * @deprecated Use PartitionManager::generatePartitionBoundary() instead.
 */
function calculatePartitionBoundary(int $year, int $month): string
{
    $dt = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    return PartitionManager::generatePartitionBoundary($dt);
}

/**
 * Get the list of partitions to create.
 * @deprecated Use PartitionManager::getPartitionsToCreate() instead.
 */
function getPartitionsToCreate(\DateTimeImmutable $now): array
{
    return PartitionManager::getPartitionsToCreate($now);
}

/**
 * Get archival candidates.
 * @deprecated Use PartitionManager::getPartitionsToArchive() instead.
 */
function getArchivalCandidates(\DateTimeImmutable $now): array
{
    return PartitionManager::getPartitionsToArchive($now);
}

/**
 * Run the partition manager: create future partitions and log archival candidates.
 */
function runPartitionManager(\PDO $pdo, string $schema, ?\DateTimeImmutable $now = null): void
{
    $logger = StructuredLogger::getInstance();
    $now = $now ?? new \DateTimeImmutable();
    $tables = ['ledger_entries', 'game_bets'];

    $logger->info("Partition manager started", ['tables' => $tables]);

    // ── Create partitions 3 months ahead ────────────────────────────────
    $toCreate = PartitionManager::getPartitionsToCreate($now);

    foreach ($tables as $table) {
        PartitionManager::ensurePartitions($pdo, $table, $toCreate);
    }

    // ── Identify archival candidates (log only, no auto-delete) ─────────
    $archivalCandidates = PartitionManager::getPartitionsToArchive($now);

    if (!empty($archivalCandidates)) {
        foreach ($tables as $table) {
            $names = array_column($archivalCandidates, 'name');
            $logger->warning("Archival candidates identified (manual action required)", [
                'table'      => $table,
                'partitions' => $names,
                'count'      => count($names),
            ]);
        }
    }

    $logger->info("Partition manager completed");
}

// ── CLI execution ───────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../config/db.php';
    $schema = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'anora');
    runPartitionManager($pdo, $schema);
}
