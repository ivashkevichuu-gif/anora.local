<?php
declare(strict_types=1);

define('SYSTEM_USER_ID', 0);

class LedgerService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Add a ledger entry with idempotency, scalable locking, and audit metadata.
     *
     * This method runs WITHIN the caller's transaction.
     * Caller is responsible for BEGIN/COMMIT/ROLLBACK.
     */
    public function addEntry(
        int $userId,
        string $type,
        float $amount,
        string $direction,
        ?string $referenceId,
        ?string $referenceType,
        array $metadata = []
    ): array {
        // Validate reference_id and reference_type are provided
        if (empty($referenceId) || empty($referenceType)) {
            throw new InvalidArgumentException('reference_id and reference_type are required');
        }

        // Auto-populate metadata fields from request context
        $metadata['ip'] = $metadata['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $metadata['user_agent'] = $metadata['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        // 'source' should be provided by caller

        // 1. Idempotency check — return existing entry if duplicate
        $existing = $this->pdo->prepare(
            "SELECT * FROM ledger_entries WHERE reference_type = ? AND reference_id = ? AND user_id = ? AND type = ? LIMIT 1"
        );
        $existing->execute([$referenceType, $referenceId, $userId, $type]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // 2. Acquire row-level lock on user_balances
        $stmt = $this->pdo->prepare("SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $balanceRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($balanceRow === false) {
            // Insert a new user_balances row with 0.00 if missing
            $insert = $this->pdo->prepare("INSERT INTO user_balances (user_id, balance) VALUES (?, 0.00)");
            $insert->execute([$userId]);
            $currentBalance = 0.00;
        } else {
            $currentBalance = (float) $balanceRow['balance'];
        }

        // 3. Compute balance_after
        if ($direction === 'credit') {
            $balanceAfter = $currentBalance + $amount;
        } else {
            $balanceAfter = $currentBalance - $amount;
        }

        // 4. Reject debit if balance would go negative
        if ($direction === 'debit' && $balanceAfter < 0) {
            throw new RuntimeException('Insufficient balance');
        }

        // 5. INSERT into ledger_entries
        $insertEntry = $this->pdo->prepare(
            "INSERT INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertEntry->execute([
            $userId,
            $type,
            $amount,
            $direction,
            $balanceAfter,
            $referenceId,
            $referenceType,
            json_encode($metadata),
        ]);

        $entryId = (int) $this->pdo->lastInsertId();

        // 6. UPDATE user_balances
        $updateBalance = $this->pdo->prepare("UPDATE user_balances SET balance = ? WHERE user_id = ?");
        $updateBalance->execute([$balanceAfter, $userId]);

        // 7. UPDATE users.balance (denormalized cache, 2 decimal places)
        $updateUser = $this->pdo->prepare("UPDATE users SET balance = ROUND(?, 2) WHERE id = ?");
        $updateUser->execute([$balanceAfter, $userId]);

        // 8. Return inserted row
        $fetch = $this->pdo->prepare("SELECT * FROM ledger_entries WHERE id = ?");
        $fetch->execute([$entryId]);
        return $fetch->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get user balance with FOR UPDATE lock on user_balances.
     * Returns 0.00 if no row exists.
     */
    public function getBalanceForUpdate(int $userId): float
    {
        $stmt = $this->pdo->prepare("SELECT balance FROM user_balances WHERE user_id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return 0.00;
        }

        return (float) $row['balance'];
    }
}
