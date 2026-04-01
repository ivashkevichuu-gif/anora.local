<?php

require_once __DIR__ . '/ledger_service.php';

class WebhookHandler
{
    private PDO $pdo;
    private string $ipnSecret;

    public function __construct(PDO $pdo, string $ipnSecret)
    {
        $this->pdo       = $pdo;
        $this->ipnSecret = $ipnSecret;
    }

    /**
     * Validate HMAC-SHA512 signature.
     * Decodes JSON from rawBody, recursively sorts payload keys, JSON-encodes sorted payload,
     * computes hash_hmac('sha512', sorted_json, ipnSecret), compares with hash_equals().
     */
    public function validateSignature(string $rawBody, string $signatureHeader): bool
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return false;
        }

        $sorted    = $this->sortPayload($payload);
        $sortedJson = json_encode($sorted);
        $computed  = hash_hmac('sha512', $sortedJson, $this->ipnSecret);

        return hash_equals($computed, $signatureHeader);
    }

    /**
     * Process an incoming IPN webhook.
     * Validates signature, decodes payload, routes to deposit or payout handler.
     *
     * @throws RuntimeException if signature is invalid
     */
    public function handle(string $rawBody, string $signatureHeader): array
    {
        if (!$this->validateSignature($rawBody, $signatureHeader)) {
            throw new RuntimeException('Invalid webhook signature');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON payload');
        }

        // Route: presence of 'payout_id' indicates a payout webhook
        if (isset($payload['payout_id'])) {
            return $this->handlePayout($payload);
        }

        // Otherwise treat as deposit webhook (has 'payment_status' field)
        return $this->handleDeposit($payload);
    }

    /**
     * Process deposit webhook (invoice status update).
     *
     * - 'finished': within transaction, confirm invoice, credit balance, insert user_transaction
     * - 'waiting'/'confirming'/'sending': update status only
     * - 'partially_paid'/'expired'/'failed': update status only
     * - Idempotent: skip if already 'confirmed'
     * - Overpayment: cap credit at original price_amount, log warning
     */
    private function handleDeposit(array $payload): array
    {
        $paymentStatus = $payload['payment_status'] ?? null;
        $paymentId     = $payload['payment_id'] ?? null;

        // Determine the invoice identifier from the payload
        $invoiceId = $payload['order_id'] ?? $payload['invoice_id'] ?? null;

        if (!$invoiceId) {
            error_log("[Webhook] Deposit webhook missing invoice identifier");
            return ['status' => 'ok', 'message' => 'No invoice identifier found'];
        }

        // Look up the crypto_invoices row by nowpayments_invoice_id
        $stmt = $this->pdo->prepare(
            "SELECT * FROM crypto_invoices WHERE nowpayments_invoice_id = ? LIMIT 1"
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            error_log("[Webhook] Unknown invoice_id=$invoiceId, ignoring");
            return ['status' => 'ok', 'message' => 'Unknown invoice, ignored'];
        }

        // Map NOWPayments statuses to our internal statuses
        $statusMap = [
            'waiting'       => 'waiting',
            'confirming'    => 'confirming',
            'sending'       => 'confirming',
            'partially_paid' => 'partially_paid',
            'expired'       => 'expired',
            'failed'        => 'failed',
            'finished'      => 'confirmed',
        ];

        $mappedStatus = $statusMap[$paymentStatus] ?? null;

        if (!$mappedStatus) {
            error_log("[Webhook] Unknown payment_status=$paymentStatus for invoice_id=$invoiceId");
            return ['status' => 'ok', 'message' => 'Unknown status, ignored'];
        }

        // For non-finished statuses: just update the status column
        if ($paymentStatus !== 'finished') {
            $this->pdo->prepare(
                "UPDATE crypto_invoices SET status = ? WHERE id = ?"
            )->execute([$mappedStatus, $invoice['id']]);

            return ['status' => 'ok', 'message' => "Invoice status updated to $mappedStatus"];
        }

        // ── finished status: credit balance within a transaction ──

        // Idempotent: skip if already confirmed
        if ($invoice['status'] === 'confirmed') {
            return ['status' => 'ok', 'message' => 'Already confirmed, skipped'];
        }

        $outcomeAmount = (float) ($payload['outcome_amount'] ?? $payload['price_amount'] ?? $invoice['amount_usd']);
        $priceAmount   = (float) ($payload['price_amount'] ?? $invoice['amount_usd']);
        $amountCrypto  = $payload['actually_paid'] ?? $payload['pay_amount'] ?? null;
        $currency      = $payload['pay_currency'] ?? $payload['currency'] ?? null;

        // Cap credit at original price_amount (overpayment protection)
        $creditAmount = min($outcomeAmount, $priceAmount);

        if ($outcomeAmount > $priceAmount) {
            error_log("[Webhook] Overpayment on invoice_id={$invoice['id']}: outcome=$outcomeAmount requested=$priceAmount");
        }

        $this->pdo->beginTransaction();
        try {
            // Update crypto_invoices to confirmed
            $this->pdo->prepare(
                "UPDATE crypto_invoices
                 SET status = 'confirmed', amount_crypto = ?, currency = ?, credited_usd = ?
                 WHERE id = ?"
            )->execute([$amountCrypto, $currency, $creditAmount, $invoice['id']]);

            // Credit user balance via LedgerService
            $ledger = new LedgerService($this->pdo);
            $ledger->addEntry($invoice['user_id'], 'crypto_deposit', $creditAmount, 'credit', (string)$paymentId, 'crypto_invoice', ['source' => 'webhook']);

            // Insert user_transactions audit row (backward compatibility)
            $this->pdo->prepare(
                "INSERT INTO user_transactions (user_id, payout_id, type, amount, game_id, note)
                 VALUES (?, NULL, 'crypto_deposit', ?, NULL, ?)"
            )->execute([
                $invoice['user_id'],
                $creditAmount,
                "nowpayments_payment_id:$paymentId",
            ]);

            $this->pdo->commit();

            return ['status' => 'ok', 'message' => 'Deposit confirmed and balance credited'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("[Webhook] Transaction failed for invoice_id={$invoice['id']}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process payout webhook (payout status update).
     *
     * - 'finished': update crypto_payouts to 'completed'
     * - 'failed'/'expired': within transaction, update to 'failed', refund balance, insert user_transaction
     * - Idempotent: skip if already 'completed'/'failed'/'rejected'
     */
    private function handlePayout(array $payload): array
    {
        $payoutStatus       = $payload['status'] ?? null;
        $nowpaymentsPayoutId = $payload['payout_id'] ?? $payload['id'] ?? null;

        if (!$nowpaymentsPayoutId) {
            error_log("[Webhook] Payout webhook missing payout identifier");
            return ['status' => 'ok', 'message' => 'No payout identifier found'];
        }

        // Look up the crypto_payouts row
        $stmt = $this->pdo->prepare(
            "SELECT * FROM crypto_payouts WHERE nowpayments_payout_id = ? LIMIT 1"
        );
        $stmt->execute([$nowpaymentsPayoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout) {
            error_log("[Webhook] Unknown payout nowpayments_payout_id=$nowpaymentsPayoutId, ignoring");
            return ['status' => 'ok', 'message' => 'Unknown payout, ignored'];
        }

        // Idempotent: skip if already in terminal state
        if (in_array($payout['status'], ['completed', 'failed', 'rejected'], true)) {
            return ['status' => 'ok', 'message' => 'Payout already in terminal state, skipped'];
        }

        // finished → completed
        if ($payoutStatus === 'finished') {
            $this->pdo->prepare(
                "UPDATE crypto_payouts SET status = 'completed' WHERE id = ?"
            )->execute([$payout['id']]);

            return ['status' => 'ok', 'message' => 'Payout marked as completed'];
        }

        // failed / expired → refund within transaction
        if (in_array($payoutStatus, ['failed', 'expired'], true)) {
            $this->pdo->beginTransaction();
            try {
                // Lock payout row
                $lockStmt = $this->pdo->prepare(
                    "SELECT * FROM crypto_payouts WHERE id = ? FOR UPDATE"
                );
                $lockStmt->execute([$payout['id']]);
                $lockedPayout = $lockStmt->fetch(PDO::FETCH_ASSOC);

                // Double-check terminal state under lock
                if (in_array($lockedPayout['status'], ['completed', 'failed', 'rejected'], true)) {
                    $this->pdo->rollBack();
                    return ['status' => 'ok', 'message' => 'Payout already in terminal state, skipped'];
                }

                // Update payout status to failed
                $this->pdo->prepare(
                    "UPDATE crypto_payouts SET status = 'failed' WHERE id = ?"
                )->execute([$lockedPayout['id']]);

                // Refund user balance via LedgerService
                $ledger = new LedgerService($this->pdo);
                $ledger->addEntry($lockedPayout['user_id'], 'crypto_withdrawal_refund', $lockedPayout['amount_usd'], 'credit', 'nowpayments_payout:' . $nowpaymentsPayoutId, 'crypto_payout_refund', ['source' => 'webhook']);

                // Insert refund user_transaction (backward compatibility)
                $this->pdo->prepare(
                    "INSERT INTO user_transactions (user_id, payout_id, type, amount, game_id, note)
                     VALUES (?, NULL, 'crypto_withdrawal_refund', ?, NULL, ?)"
                )->execute([
                    $lockedPayout['user_id'],
                    $lockedPayout['amount_usd'],
                    "nowpayments_payout_id:$nowpaymentsPayoutId",
                ]);

                $this->pdo->commit();

                error_log("[Payout] Failed payout_id={$lockedPayout['id']}, refunding user_id={$lockedPayout['user_id']}");

                return ['status' => 'ok', 'message' => 'Payout failed, balance refunded'];
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log("[Webhook] Payout refund transaction failed for payout_id={$payout['id']}: " . $e->getMessage());
                throw $e;
            }
        }

        // Unknown payout status — just log and return OK
        error_log("[Webhook] Unknown payout status=$payoutStatus for nowpayments_payout_id=$nowpaymentsPayoutId");
        return ['status' => 'ok', 'message' => 'Unknown payout status, ignored'];
    }

    /**
     * Recursively sort array keys for HMAC computation.
     */
    public function sortPayload(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortPayload($value);
            }
        }
        return $data;
    }
}
