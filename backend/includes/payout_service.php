<?php

require_once __DIR__ . '/nowpayments.php';
require_once __DIR__ . '/ledger_service.php';

class PayoutService
{
    private PDO $pdo;
    private NowPaymentsClient $client;
    private array $config;

    public function __construct(PDO $pdo, NowPaymentsClient $client, array $config)
    {
        $this->pdo    = $pdo;
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * Create a crypto withdrawal payout.
     *
     * Validates: amount >= $5, daily cap <= $10k, rate <= 3/day, balance sufficient.
     * Atomic: deducts balance (FOR UPDATE), inserts crypto_payouts + user_transactions.
     * If amount > manual_approval_threshold: status='awaiting_approval', skip API call.
     * Otherwise: calls NOWPayments API, updates status='processing'.
     * On API failure: immediate refund within transaction.
     *
     * @return array ['payout_id' => int, 'status' => string, 'message' => string]
     */
    public function createPayout(int $userId, float $amountUsd, string $walletAddress, string $currency): array
    {
        // Block bot users from withdrawing
        $botCheck = $this->pdo->prepare("SELECT is_bot FROM users WHERE id = ?");
        $botCheck->execute([$userId]);
        if ((int)$botCheck->fetchColumn() === 1) {
            throw new InvalidArgumentException('Bot users cannot withdraw');
        }

        // Validate minimum amount
        if ($amountUsd < 5.00) {
            throw new InvalidArgumentException('Minimum withdrawal is $5.00 USD');
        }

        // Check daily cap: sum of non-failed payouts today
        $capStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_usd), 0) FROM crypto_payouts
             WHERE user_id = ? AND DATE(created_at) = CURDATE() AND status NOT IN ('failed', 'rejected')"
        );
        $capStmt->execute([$userId]);
        $dailyTotal = (float) $capStmt->fetchColumn();

        if (($dailyTotal + $amountUsd) > 10000.00) {
            throw new InvalidArgumentException('Daily withdrawal limit of $10,000 exceeded');
        }

        // Check rate limit: max 3 withdrawal requests per day
        $rateStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM crypto_payouts
             WHERE user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $rateStmt->execute([$userId]);
        $dailyCount = (int) $rateStmt->fetchColumn();

        if ($dailyCount >= 3) {
            throw new RuntimeException('Maximum 3 withdrawal requests per day');
        }

        $manualThreshold = (float) ($this->config['manual_approval_threshold'] ?? 500.00);

        // Begin atomic transaction: deduct balance via ledger, insert payout + user_transaction
        $this->pdo->beginTransaction();
        try {
            // Use LedgerService to check balance (acquires FOR UPDATE lock on user_balances)
            $ledger = new LedgerService($this->pdo);
            $balance = $ledger->getBalanceForUpdate($userId);

            if ($balance < $amountUsd) {
                $this->pdo->rollBack();
                throw new InvalidArgumentException('Insufficient balance');
            }

            // Determine initial status
            $status = ($amountUsd > $manualThreshold) ? 'awaiting_approval' : 'pending';

            // Insert crypto_payouts first to get payoutId
            $this->pdo->prepare(
                "INSERT INTO crypto_payouts (user_id, amount_usd, wallet_address, currency, status)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$userId, $amountUsd, $walletAddress, $currency, $status]);

            $payoutId = (int) $this->pdo->lastInsertId();

            // Deduct balance via LedgerService
            $ledger->addEntry($userId, 'crypto_withdrawal', $amountUsd, 'debit', 'crypto_payout:' . $payoutId, 'crypto_payout', ['source' => 'deposit']);

            // Insert user_transactions for audit trail (backward compatibility)
            $this->pdo->prepare(
                "INSERT INTO user_transactions (user_id, payout_id, type, amount, game_id, note)
                 VALUES (?, NULL, 'crypto_withdrawal', ?, NULL, ?)"
            )->execute([$userId, $amountUsd, "payout_id:$payoutId"]);

            // Update user's default crypto preferences
            $this->pdo->prepare(
                "UPDATE users SET default_wallet_address = ?, default_crypto_currency = ? WHERE id = ?"
            )->execute([$walletAddress, $currency, $userId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // If awaiting manual approval, skip API call
        if ($status === 'awaiting_approval') {
            return [
                'payout_id' => $payoutId,
                'status'    => 'awaiting_approval',
                'message'   => 'Withdrawal request submitted for manual approval.',
            ];
        }

        // Call NOWPayments API for normal flow
        try {
            $apiResponse = $this->client->createPayout($amountUsd, $walletAddress, $currency);

            $nowpaymentPayoutId = $apiResponse['id'] ?? null;

            $this->pdo->prepare(
                "UPDATE crypto_payouts SET nowpayments_payout_id = ?, status = 'processing' WHERE id = ?"
            )->execute([$nowpaymentPayoutId, $payoutId]);

            // Update the note in user_transactions to include the nowpayments payout id
            if ($nowpaymentPayoutId) {
                $this->pdo->prepare(
                    "UPDATE user_transactions SET note = ? WHERE user_id = ? AND type = 'crypto_withdrawal' AND note = ?"
                )->execute(["nowpayments_payout_id:$nowpaymentPayoutId", $userId, "payout_id:$payoutId"]);
            }

            return [
                'payout_id' => $payoutId,
                'status'    => 'processing',
                'message'   => 'Withdrawal request submitted.',
            ];
        } catch (NowPaymentsException $e) {
            // API failure: immediate refund
            error_log("[Payout] API failure for payout_id=$payoutId: " . $e->getMessage());
            $this->refundPayout($payoutId);

            return [
                'payout_id' => $payoutId,
                'status'    => 'failed',
                'message'   => 'Withdrawal submitted but processing failed. Your balance has been refunded.',
            ];
        }
    }

    /**
     * Admin approves a payout — calls NOWPayments API.
     *
     * @return array ['payout_id' => int, 'status' => string, 'message' => string]
     */
    public function approvePayout(int $payoutId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM crypto_payouts WHERE id = ?"
        );
        $stmt->execute([$payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout) {
            throw new InvalidArgumentException('Payout not found');
        }

        if ($payout['status'] !== 'awaiting_approval') {
            throw new InvalidArgumentException('Payout is not awaiting approval');
        }

        try {
            $apiResponse = $this->client->createPayout(
                (float) $payout['amount_usd'],
                $payout['wallet_address'],
                $payout['currency']
            );

            $nowpaymentPayoutId = $apiResponse['id'] ?? null;

            $this->pdo->prepare(
                "UPDATE crypto_payouts SET nowpayments_payout_id = ?, status = 'processing' WHERE id = ?"
            )->execute([$nowpaymentPayoutId, $payoutId]);

            // Update the note in user_transactions
            if ($nowpaymentPayoutId) {
                $this->pdo->prepare(
                    "UPDATE user_transactions SET note = ? WHERE user_id = ? AND type = 'crypto_withdrawal' AND note = ?"
                )->execute([
                    "nowpayments_payout_id:$nowpaymentPayoutId",
                    $payout['user_id'],
                    "payout_id:$payoutId",
                ]);
            }

            return [
                'payout_id' => $payoutId,
                'status'    => 'processing',
                'message'   => 'Payout approved and submitted to NOWPayments.',
            ];
        } catch (NowPaymentsException $e) {
            error_log("[Payout] API failure on approve for payout_id=$payoutId: " . $e->getMessage());
            $this->refundPayout($payoutId);

            return [
                'payout_id' => $payoutId,
                'status'    => 'failed',
                'message'   => 'Payout approval failed. User balance has been refunded.',
            ];
        }
    }

    /**
     * Admin rejects a payout — refunds user balance atomically.
     *
     * @return array ['payout_id' => int, 'status' => string, 'message' => string]
     */
    public function rejectPayout(int $payoutId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM crypto_payouts WHERE id = ?"
        );
        $stmt->execute([$payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout) {
            throw new InvalidArgumentException('Payout not found');
        }

        if ($payout['status'] !== 'awaiting_approval') {
            throw new InvalidArgumentException('Payout is not awaiting approval');
        }

        $this->pdo->beginTransaction();
        try {
            // Lock payout row
            $lockStmt = $this->pdo->prepare(
                "SELECT * FROM crypto_payouts WHERE id = ? FOR UPDATE"
            );
            $lockStmt->execute([$payoutId]);
            $lockedPayout = $lockStmt->fetch(PDO::FETCH_ASSOC);

            // Double-check status under lock
            if ($lockedPayout['status'] !== 'awaiting_approval') {
                $this->pdo->rollBack();
                throw new InvalidArgumentException('Payout is not awaiting approval');
            }

            // Update payout status to rejected
            $this->pdo->prepare(
                "UPDATE crypto_payouts SET status = 'rejected' WHERE id = ?"
            )->execute([$payoutId]);

            // Refund user balance via LedgerService
            $ledger = new LedgerService($this->pdo);
            $ledger->addEntry($lockedPayout['user_id'], 'crypto_withdrawal_refund', $lockedPayout['amount_usd'], 'credit', 'crypto_payout:' . $payoutId, 'crypto_payout_refund', ['source' => 'deposit']);

            // Insert refund user_transaction (backward compatibility)
            $this->pdo->prepare(
                "INSERT INTO user_transactions (user_id, payout_id, type, amount, game_id, note)
                 VALUES (?, NULL, 'crypto_withdrawal_refund', ?, NULL, ?)"
            )->execute([
                $lockedPayout['user_id'],
                $lockedPayout['amount_usd'],
                "payout_id:$payoutId",
            ]);

            $this->pdo->commit();

            error_log("[Payout] Admin rejected payout_id=$payoutId");

            return [
                'payout_id' => $payoutId,
                'status'    => 'rejected',
                'message'   => 'Payout rejected. User balance has been refunded.',
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Refund a failed payout. Used by webhook handler and API failure path.
     * Atomic: updates status to 'failed', credits balance, inserts refund transaction.
     */
    public function refundPayout(int $payoutId): void
    {
        $this->pdo->beginTransaction();
        try {
            // Lock payout row
            $stmt = $this->pdo->prepare(
                "SELECT * FROM crypto_payouts WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$payoutId]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payout) {
                $this->pdo->rollBack();
                throw new InvalidArgumentException('Payout not found');
            }

            // Skip if already in a terminal state
            if (in_array($payout['status'], ['completed', 'failed', 'rejected'], true)) {
                $this->pdo->rollBack();
                return;
            }

            // Update payout status to failed
            $this->pdo->prepare(
                "UPDATE crypto_payouts SET status = 'failed' WHERE id = ?"
            )->execute([$payoutId]);

            // Credit user balance via LedgerService
            $ledger = new LedgerService($this->pdo);
            $ledger->addEntry($payout['user_id'], 'crypto_withdrawal_refund', $payout['amount_usd'], 'credit', 'crypto_payout:' . $payoutId, 'crypto_payout_refund', ['source' => 'deposit']);

            // Insert refund user_transaction (backward compatibility)
            $noteValue = $payout['nowpayments_payout_id']
                ? "nowpayments_payout_id:{$payout['nowpayments_payout_id']}"
                : "payout_id:$payoutId";

            $this->pdo->prepare(
                "INSERT INTO user_transactions (user_id, payout_id, type, amount, game_id, note)
                 VALUES (?, NULL, 'crypto_withdrawal_refund', ?, NULL, ?)"
            )->execute([
                $payout['user_id'],
                $payout['amount_usd'],
                $noteValue,
            ]);

            $this->pdo->commit();

            error_log("[Payout] Refunded payout_id=$payoutId, user_id={$payout['user_id']}, amount={$payout['amount_usd']}");
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
