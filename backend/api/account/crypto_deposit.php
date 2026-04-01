<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$input  = json_decode(file_get_contents('php://input'), true);
$amount = round((float)($input['amount'] ?? 0), 2);

require_once __DIR__ . '/../../includes/invoice_service.php';
require_once __DIR__ . '/../../includes/nowpayments.php';

$npConfig = require __DIR__ . '/../../config/nowpayments.php';
$client   = new NowPaymentsClient($npConfig);
$service  = new InvoiceService($pdo, $client, $npConfig);

try {
    $result = $service->createInvoice($_SESSION['user_id'], $amount);
    echo json_encode([
        'invoice_id'  => $result['invoice_id'],
        'invoice_url' => $result['invoice_url'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
} catch (NowPaymentsException $e) {
    http_response_code(502);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again.']);
}
