<?php

class NowPaymentsException extends \RuntimeException
{
    private int $httpStatus;
    private string $responseBody;

    public function __construct(string $message, int $httpStatus = 0, string $responseBody = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}

class NowPaymentsClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout = 30;

    public function __construct(array $config)
    {
        $this->apiKey  = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['api_base_url'] ?? 'https://api.nowpayments.io/v1', '/');
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    /**
     * POST /v1/invoice — create a crypto deposit invoice.
     *
     * @return array Decoded JSON response from NOWPayments.
     */
    public function createInvoice(float $priceAmount, string $priceCurrency = 'usd'): array
    {
        return $this->request('POST', '/v1/invoice', [
            'price_amount'   => $priceAmount,
            'price_currency' => $priceCurrency,
        ]);
    }

    /**
     * POST /v1/payout — create a crypto withdrawal payout.
     *
     * @return array Decoded JSON response from NOWPayments.
     */
    public function createPayout(float $amount, string $address, string $currency): array
    {
        return $this->request('POST', '/v1/payout', [
            'amount'  => $amount,
            'address' => $address,
            'currency' => $currency,
        ]);
    }

    /**
     * GET /v1/payment/{id} — retrieve payment status.
     *
     * @return array Decoded JSON response from NOWPayments.
     */
    public function getPaymentStatus(string $paymentId): array
    {
        return $this->request('GET', '/v1/payment/' . urlencode($paymentId));
    }

    /**
     * Internal HTTP request via cURL.
     * Sets x-api-key header and 30s timeout.
     * Throws NowPaymentsException on non-2xx or network error.
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $responseBody = curl_exec($ch);
        $curlError    = curl_error($ch);
        $curlErrno    = curl_errno($ch);
        $httpStatus   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Network / cURL error
        if ($curlErrno !== 0) {
            error_log("[NowPayments] cURL error #{$curlErrno}: {$curlError} — {$method} {$endpoint}");
            throw new NowPaymentsException(
                "Network error: {$curlError}",
                0,
                ''
            );
        }

        // Non-2xx HTTP status
        if ($httpStatus < 200 || $httpStatus >= 300) {
            error_log("[NowPayments] HTTP {$httpStatus} — {$method} {$endpoint}: {$responseBody}");
            throw new NowPaymentsException(
                "HTTP error {$httpStatus}",
                $httpStatus,
                (string) $responseBody
            );
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            error_log("[NowPayments] Invalid JSON response — {$method} {$endpoint}: {$responseBody}");
            throw new NowPaymentsException(
                'Invalid JSON response from NOWPayments',
                $httpStatus,
                (string) $responseBody
            );
        }

        return $decoded;
    }
}
