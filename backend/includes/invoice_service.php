<?php

require_once __DIR__ . '/nowpayments.php';

class InvoiceService
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
     * Create a crypto deposit invoice.
     *
     * Validates amount >= $1.00, enforces rate limit (5/hour).
     * Calls NOWPayments API, inserts crypto_invoices row with status='pending'.
     *
     * @return array ['invoice_id' => int, 'invoice_url' => string]
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on rate limit
     * @throws NowPaymentsException on API failure
     */
    public function createInvoice(int $userId, float $amountUsd): array
    {
        // Validate minimum amount
        if ($amountUsd < 15.00) {
            throw new InvalidArgumentException('Minimum deposit is $15.00 USD');
        }

        // Check rate limit: max 5 invoices per user per 60 minutes
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM crypto_invoices
             WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR"
        );
        $stmt->execute([$userId]);
        $recentCount = (int) $stmt->fetchColumn();

        if ($recentCount >= 5) {
            throw new RuntimeException('Too many deposit requests. Try again later.');
        }

        // Call NOWPayments API
        $apiResponse = $this->client->createInvoice($amountUsd, 'usd');

        $nowpaymentsInvoiceId = $apiResponse['id'] ?? null;
        $invoiceUrl           = $apiResponse['invoice_url'] ?? null;

        // Insert crypto_invoices row
        $insertStmt = $this->pdo->prepare(
            "INSERT INTO crypto_invoices (user_id, nowpayments_invoice_id, amount_usd, status, invoice_url)
             VALUES (?, ?, ?, 'pending', ?)"
        );
        $insertStmt->execute([
            $userId,
            $nowpaymentsInvoiceId,
            $amountUsd,
            $invoiceUrl,
        ]);

        $invoiceId = (int) $this->pdo->lastInsertId();

        return [
            'invoice_id'  => $invoiceId,
            'invoice_url' => $invoiceUrl,
        ];
    }
}
