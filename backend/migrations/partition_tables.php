<?php
/**
 * Migration: Partition ledger_entries and game_bets by RANGE COLUMNS(created_at).
 *
 * Steps:
 * 1. Drop foreign key constraints (MySQL doesn't support FK on partitioned tables)
 * 2. Apply PARTITION BY RANGE COLUMNS(created_at) with initial monthly partitions
 * 3. Application-level enforcement replaces FK constraints (already exists in LedgerService and GameEngine)
 *
 * Run once:
 *   php backend/migrations/partition_tables.php
 *
 * Requirements: 7.5, 7.6
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/structured_logger.php';
require_once __DIR__ . '/../config/db.php';

function runPartitionMigration(PDO $pdo): void
{
    $logger = StructuredLogger::getInstance();
    $logger->info("Partition migration started");

    // ── ledger_entries ──────────────────────────────────────────────────
    // ledger_entries has no FK constraints pointing to other tables,
    // but other tables may reference it. Drop any FK on ledger_entries itself.
    try {
        $logger->info("Partitioning ledger_entries");

        // Drop FK constraints if they exist
        $fks = getForeignKeys($pdo, 'ledger_entries');
        foreach ($fks as $fk) {
            $pdo->exec("ALTER TABLE ledger_entries DROP FOREIGN KEY `{$fk}`");
            $logger->info("Dropped FK on ledger_entries", ['fk' => $fk]);
        }

        // Generate partition definitions for the past 12 months + 3 months ahead
        $partitionDefs = generateInitialPartitions();

        $sql = "ALTER TABLE ledger_entries PARTITION BY RANGE COLUMNS(created_at) ({$partitionDefs})";
        $pdo->exec($sql);
        $logger->info("ledger_entries partitioned successfully");
    } catch (\PDOException $e) {
        $logger->error("Failed to partition ledger_entries", [], [], $e);
        throw $e;
    }

    // ── game_bets ───────────────────────────────────────────────────────
    try {
        $logger->info("Partitioning game_bets");

        // Drop FK constraints (round_id → game_rounds, user_id → users)
        $fks = getForeignKeys($pdo, 'game_bets');
        foreach ($fks as $fk) {
            $pdo->exec("ALTER TABLE game_bets DROP FOREIGN KEY `{$fk}`");
            $logger->info("Dropped FK on game_bets", ['fk' => $fk]);
        }

        $sql = "ALTER TABLE game_bets PARTITION BY RANGE COLUMNS(created_at) ({$partitionDefs})";
        $pdo->exec($sql);
        $logger->info("game_bets partitioned successfully");
    } catch (\PDOException $e) {
        $logger->error("Failed to partition game_bets", [], [], $e);
        throw $e;
    }

    $logger->info("Partition migration completed");
}

/**
 * Get foreign key constraint names for a table.
 *
 * @return array<string> Constraint names
 */
function getForeignKeys(PDO $pdo, string $table): array
{
    $schema = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'anora');
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->execute([$schema, $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Generate initial partition definitions covering past 12 months + 3 months ahead.
 *
 * @return string SQL partition definitions
 */
function generateInitialPartitions(): string
{
    $now = new DateTimeImmutable();
    $start = $now->modify('-12 months');
    $end = $now->modify('+3 months');

    $defs = [];
    $dt = new DateTimeImmutable($start->format('Y-m-01'));

    while ($dt <= $end) {
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $name = sprintf('p%04d_%02d', $year, $month);
        $boundary = $dt->modify('+1 month')->format('Y-m-d');

        $defs[] = "PARTITION `{$name}` VALUES LESS THAN ('{$boundary}')";
        $dt = $dt->modify('+1 month');
    }

    // Catch-all for future data
    $defs[] = "PARTITION p_future VALUES LESS THAN MAXVALUE";

    return implode(",\n        ", $defs);
}

// ── CLI execution ───────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    runPartitionMigration($pdo);
}
