<?php
/**
 * Balance Migration Script
 *
 * Migrates existing users.balance values into the ledger_entries and user_balances tables.
 * Idempotent — safe to run multiple times.
 *
 * Usage: php backend/migrations/migrate_balances.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

define('SYSTEM_USER_ID', 1);

echo "=== Balance Migration Start ===\n\n";

$migrated = 0;
$skippedZero = 0;
$skippedExists = 0;

// ── Step 1: Migrate ledger entries for users with balance > 0 ────────────────

$users = $pdo->query("SELECT id, balance FROM users WHERE balance > 0 ORDER BY id ASC");

while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
    $userId  = (int) $user['id'];
    $balance = (float) $user['balance'];

    // Idempotency check: skip if migration entry already exists
    $check = $pdo->prepare(
        "SELECT id FROM ledger_entries WHERE user_id = ? AND reference_type = 'migration' AND reference_id = 'initial_migration' LIMIT 1"
    );
    $check->execute([$userId]);

    if ($check->fetch()) {
        $skippedExists++;
        echo "  [SKIP] User #{$userId} — migration entry already exists\n";
        continue;
    }

    // Insert ledger entry
    $stmt = $pdo->prepare(
        "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_type, reference_id, metadata)
         VALUES (?, 'deposit', ?, 'credit', ?, 'migration', 'initial_migration', ?)"
    );
    $stmt->execute([
        $userId,
        $balance,
        $balance,
        json_encode(['source' => 'migration']),
    ]);

    $migrated++;
    echo "  [OK]   User #{$userId} — migrated balance \${$balance}\n";
}

// Count users with balance <= 0 that were not processed
$zeroStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE balance <= 0");
$skippedZero = (int) $zeroStmt->fetch(PDO::FETCH_ASSOC)['cnt'];


// ── Step 2: Populate user_balances for ALL users ─────────────────────────────

echo "\nPopulating user_balances for all users...\n";

$inserted = $pdo->exec(
    "INSERT IGNORE INTO user_balances (user_id, balance) SELECT id, balance FROM users"
);
echo "  user_balances rows inserted/already present: {$inserted} new\n";

// ── Step 3: System Account (SYSTEM_USER_ID = 0) ─────────────────────────────

echo "\nSetting up System Account (user_id = " . SYSTEM_USER_ID . ")...\n";

$sysStmt = $pdo->query("SELECT balance FROM system_balance WHERE id = 1 LIMIT 1");
$sysRow  = $sysStmt->fetch(PDO::FETCH_ASSOC);
$systemBalance = $sysRow ? (float) $sysRow['balance'] : 0.00;

// Create user_balances row for System Account
$pdo->prepare("INSERT IGNORE INTO user_balances (user_id, balance) VALUES (?, ?)")
    ->execute([SYSTEM_USER_ID, $systemBalance]);

echo "  System Account balance: \${$systemBalance}\n";

// If system balance > 0, create a ledger entry for the system account
if ($systemBalance > 0) {
    $checkSys = $pdo->prepare(
        "SELECT id FROM ledger_entries WHERE user_id = ? AND reference_type = 'migration' AND reference_id = 'initial_migration' LIMIT 1"
    );
    $checkSys->execute([SYSTEM_USER_ID]);

    if (!$checkSys->fetch()) {
        $pdo->prepare(
            "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_type, reference_id, metadata)
             VALUES (?, 'deposit', ?, 'credit', ?, 'migration', 'initial_migration', ?)"
        )->execute([
            SYSTEM_USER_ID,
            $systemBalance,
            $systemBalance,
            json_encode(['source' => 'migration']),
        ]);
        echo "  [OK]   System Account ledger entry created\n";
    } else {
        echo "  [SKIP] System Account ledger entry already exists\n";
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────

echo "\n=== Migration Summary ===\n";
echo "  Migrated:              {$migrated}\n";
echo "  Skipped (balance <= 0): {$skippedZero}\n";
echo "  Skipped (already done): {$skippedExists}\n";
echo "  System Account balance: \${$systemBalance}\n";
echo "=== Migration Complete ===\n";
