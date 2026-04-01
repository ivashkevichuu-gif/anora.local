<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$input          = json_decode(file_get_contents('php://input'), true);
$amount         = round((float)($input['amount'] ?? 0), 2);
$walletAddress  = trim($input['wallet_address'] ?? '');
$currency       = strtolower(trim($input['currency'] ?? ''));

if (empty($walletAddress)) {
    http_response_code(400);
    echo json_encode(['error' => 'Wallet address is required.']);
    exit;
}
if (empty($currency)) {
    http_response_code(400);
    echo json_encode(['error' => 'Currency is required.']);
    exit;
}

require_once __DIR__ . '/../../includes/payout_service.php';

$npConfig = require __DIR__ . '/../../config/nowpayments.php';
$client   = new NowPaymentsClient($npConfig);
$service  = new PayoutService($pdo, $client, $npConfig);

try {
    $result = $service->createPayout($_SESSION['user_id'], $amount, $walletAddress, $currency);
    echo json_encode([
        'payout_id' => $result['payout_id'],
        'status'    => $result['status'],
        'message'   => $result['message'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
}
